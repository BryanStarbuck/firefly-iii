<?php

/*
 * RequestState.php
 * Copyright (c) 2026 The Firefly III machine-plane contributors
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace FireflyIII\Machine\ErrorFile;

use FireflyIII\Machine\MachineException;
use Illuminate\Container\Container;
use Throwable;
use WeakMap;

/**
 * The per-request (scoped) state of the error file — pm/error_err.mdx §4.5, §4.8, §3.3.
 *
 * Bound as a scoped singleton by ErrorFileServiceProvider: one per HTTP request, one per artisan
 * process, fresh for every PHPUnit application. It holds:
 *
 *  - the written-message set: the normalised message of EVERY throwable handlerReport() saw, so
 *    the render duplicate at Handler.php:216 is dropped by the log net (§4.5 step 0, §4.6 step 7);
 *  - the command stack and lastFailedCommand (CommandStarting/CommandFinished, §4.8);
 *  - the job stack (JobProcessing/JobAttempted) and the job-failure WeakMap
 *    (JobExceptionOccurred/JobFailed), so a sync job's fault is still `php-queue` after the frame
 *    is popped (§3.3);
 *  - the correlation id, taken from the OUTER request (§4.8);
 *  - the nested-dispatch depth, the token stack and the covered-dispatch set, and the echo
 *    WeakMap of MachineExceptions markEcho() marked (§4.5 step 1).
 *
 * The static helpers resolve the bound instance and are total: with no container, no binding or
 * any failure they do nothing and answer "no".
 */
final class RequestState
{
    /** At most this many normalised messages are remembered per request. */
    private const int WRITTEN_MAX = 500;

    /** @var array<string, true> */
    public array $written = [];

    /** @var list<string> */
    public array $commands = [];

    public ?string $lastFailedCommand = null;

    /** @var list<array{job: string, during: string}> */
    public array $jobs = [];

    /** @var WeakMap<Throwable, array{job: string, during: string}> */
    public WeakMap $jobFailures;

    public ?string $rid = null;

    public int $nestedDepth = 0;

    /** @var list<int> */
    public array $tokens = [];

    public int $nextToken = 0;

    public ?int $lastLeftToken = null;

    /** @var array<int, true> */
    public array $covered = [];

    /** @var WeakMap<MachineException, true> */
    public WeakMap $echoes;

    public function __construct()
    {
        $this->jobFailures = new WeakMap();
        $this->echoes      = new WeakMap();
    }

    /** The bound instance, or null (no container, no binding). Total. */
    public static function current(): ?self
    {
        try {
            $app = Container::getInstance();
            if (!$app->bound(self::class)) {
                return null;
            }
            $state = $app->make(self::class);

            return $state instanceof self ? $state : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------ written-set ---

    public function addWritten(string $normalised): void
    {
        if (count($this->written) >= self::WRITTEN_MAX) {
            return;
        }
        $this->written[$normalised] = true;
    }

    public function wasWritten(string $normalised): bool
    {
        return isset($this->written[$normalised]);
    }

    // ----------------------------------------------------------- nested dispatch ---

    /**
     * MirrorController::dispatchInternal() calls this before `$router->dispatch($sub)`: bumps the
     * nested depth and hands out a fresh dispatch token. Returns the token (0 when unbound).
     */
    public static function enterNested(): int
    {
        $state = self::current();
        if (null === $state) {
            return 0;
        }
        ++$state->nestedDepth;
        $token           = ++$state->nextToken;
        $state->tokens[] = $token;

        return $token;
    }

    /** … and this in its `finally`. Remembers the token it closed for lastDispatchCovered(). */
    public static function leaveNested(): void
    {
        $state = self::current();
        if (null === $state || $state->nestedDepth <= 0) {
            return;
        }
        --$state->nestedDepth;
        $state->lastLeftToken = array_pop($state->tokens);
    }

    /** True while a nested dispatch is running. */
    public static function inNested(): bool
    {
        $state = self::current();

        return null !== $state && $state->nestedDepth > 0;
    }

    /** handlerReport() admitted a T1 ERROR/FATAL inside the current nested dispatch. */
    public static function coverCurrentDispatch(): void
    {
        $state = self::current();
        if (null === $state || [] === $state->tokens) {
            return;
        }
        $state->covered[(int) end($state->tokens)] = true;
    }

    /** True when the dispatch that most recently finished had its fault admitted (§4.5 step 1). */
    public static function lastDispatchCovered(): bool
    {
        $state = self::current();

        return null !== $state && null !== $state->lastLeftToken && isset($state->covered[$state->lastLeftToken]);
    }

    // ------------------------------------------------------------------ echoes ---

    public static function markEcho(MachineException $e): void
    {
        $state = self::current();
        if (null !== $state) {
            $state->echoes[$e] = true;
        }
    }

    public static function isEcho(Throwable $e): bool
    {
        $state = self::current();

        return null !== $state && $e instanceof MachineException && isset($state->echoes[$e]);
    }

    // -------------------------------------------------------- commands and jobs ---

    public function pushCommand(string $command): void
    {
        $this->commands[] = $command;
    }

    public function popCommand(?string $command, int $exitCode): void
    {
        $popped = array_pop($this->commands);
        $name   = $popped ?? $command;
        if (0 !== $exitCode && null !== $name && '' !== $name) {
            $this->lastFailedCommand = $name;
        }
    }

    public function pushJob(string $job, string $during): void
    {
        $this->jobs[] = ['job' => $job, 'during' => $during];
    }

    public function popJob(): void
    {
        array_pop($this->jobs);
    }

    /** @return null|array{job: string, during: string} */
    public function openJob(): ?array
    {
        return [] === $this->jobs ? null : $this->jobs[count($this->jobs) - 1];
    }

    public function recordJobFailure(Throwable $e, string $job, string $during): void
    {
        if (!isset($this->jobFailures[$e])) {
            $this->jobFailures[$e] = ['job' => $job, 'during' => $during];
        }
    }

    /** @return null|array{job: string, during: string} */
    public function jobFailure(Throwable $e): ?array
    {
        return $this->jobFailures[$e] ?? null;
    }
}
