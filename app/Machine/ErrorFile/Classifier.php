<?php

/*
 * Classifier.php
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

use ErrorException;
use FireflyIII\Machine\MachineException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Which records are faults — pm/error_err.mdx §4.5 (the handler net) and §4.6 (the log net).
 *
 * forHandler() decides what MachineExceptionHandler::report() writes (tier T1):
 *   1. a MachineException coded `internal`/`upstream_error` → unwrap() it and write ERROR with
 *      code= and status=, EXCEPT a cause-less one markEcho() put in the echo map;
 *   2. any other MachineException → nothing (R7);
 *   3. `$handler->shouldReport($e)`, or an HttpExceptionInterface with status ≥ 500 and ≠ 503 →
 *      write: a FatalError as FATAL/net=shutdown, anything else as ERROR/net=report.
 *
 * forLogged() sorts one upstream log record into a tier, statelessly — the listener (and the
 * offline replay, scripts/error-file-replay.php) add the stateful checks (busy, already written,
 * the per-request written-set). It reads ONLY the level, the message and context['exception'].
 *
 * Every method is total.
 */
final class Classifier
{
    public const string TIER_IGNORED     = 'ignored';        // below warning, or warning/notice without VERBOSE
    public const string TIER_DEPRECATION = 'deprecation';    // always dropped
    public const string TIER_T2          = 'T2';             // error+ with context['exception']
    public const string TIER_TRACE       = 'trace';          // /^#\d+ / — always dropped
    public const string TIER_MAIL        = 'mail';           // MailError's `Exception is: ` dump — always dropped
    public const string TIER_EXPECTED    = 'expected';       // on the expected-prefix list — dropped (EXPECTED under VERBOSE)
    public const string TIER_T3          = 'T3';             // a bare error+ string → WARN
    public const string TIER_T4          = 'T4';             // warning/notice under VERBOSE → WARN

    /** The levels that make a record a fault candidate. */
    public const array ERROR_LEVELS = ['error', 'critical', 'alert', 'emergency'];

    /** The levels that VERBOSE adds (T4). */
    public const array WARNING_LEVELS = ['warning', 'notice'];

    /** The plane codes that are faults, not answers (R7). */
    public const array FAULT_CODES = ['internal', 'upstream_error'];

    /**
     * The handler-net decision for one reported throwable, or null for "write nothing".
     *
     * @return null|array{level: Level, net: string, throwable: Throwable, over: array<string, int|string>}
     */
    public static function forHandler(Throwable $e, ?ExceptionHandler $handler): ?array
    {
        try {
            if ($e instanceof MachineException) {
                if (!in_array($e->errorCode, self::FAULT_CODES, true)) {
                    return null;
                }
                if (null === $e->getPrevious() && RequestState::isEcho($e)) {
                    return null;
                }

                return ['level' => Level::Error, 'net' => 'report', 'throwable' => self::unwrap($e), 'over' => ['status' => $e->status(), 'code' => $e->errorCode]];
            }
            if ($e instanceof FatalError) {
                return ['level' => Level::Fatal, 'net' => 'shutdown', 'throwable' => $e, 'over' => []];
            }
            $report = false;
            if (null !== $handler) {
                try {
                    $report = (bool) $handler->shouldReport($e);
                } catch (Throwable) {
                    $report = true;
                }
            }
            if (!$report && $e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $report = $status >= 500 && 503 !== $status;
            }

            return $report ? ['level' => Level::Error, 'net' => 'report', 'throwable' => $e, 'over' => []] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Walk getPrevious() through any nested MachineException to the throwable that caused it; the
     * MachineException itself when there is none. Cycle-safe.
     */
    public static function unwrap(MachineException $e): Throwable
    {
        try {
            $seen = [spl_object_id($e) => true];
            $next = $e->getPrevious();
            for ($i = 0; null !== $next && $i < 16; ++$i) {
                if (isset($seen[spl_object_id($next)])) {
                    break;
                }
                if (!$next instanceof MachineException) {
                    return $next;
                }
                $seen[spl_object_id($next)] = true;
                $next                       = $next->getPrevious();
            }
        } catch (Throwable) {
            // total
        }

        return $e;
    }

    /**
     * The tier of one upstream log record (§4.6 steps 1, 3–6 and 8). The stateful steps — busy
     * (2), already written (3), the written-set (7) — are the caller's.
     */
    public static function forLogged(string $level, string $message, mixed $exception = null, bool $verbose = false): string
    {
        try {
            $level = strtolower($level);
            if (self::isDeprecation($level, $message, $exception)) {
                return self::TIER_DEPRECATION;
            }
            if (!in_array($level, self::ERROR_LEVELS, true)) {
                return $verbose && in_array($level, self::WARNING_LEVELS, true) ? self::classifyBare($message, self::TIER_T4) : self::TIER_IGNORED;
            }
            if ($exception instanceof Throwable) {
                return self::TIER_T2;
            }

            return self::classifyBare($message, self::TIER_T3);
        } catch (Throwable) {
            return self::TIER_IGNORED;
        }
    }

    /**
     * A deprecation: an ErrorException of severity E_DEPRECATED or E_USER_DEPRECATED in context, or
     * a warning-level message ending ` on line <n>` (Laravel's deprecations channel).
     */
    public static function isDeprecation(string $level, string $message, mixed $exception = null): bool
    {
        try {
            if ($exception instanceof ErrorException && in_array($exception->getSeverity(), [E_DEPRECATED, E_USER_DEPRECATED], true)) {
                return true;
            }

            return 'warning' === strtolower($level) && 1 === preg_match('/ on line \d+$/', $message);
        } catch (Throwable) {
            return false;
        }
    }

    /** The level a written tier is recorded at: T2 is ERROR, T3/T4 WARN, expected EXPECTED. */
    public static function levelFor(string $tier): ?Level
    {
        return match ($tier) {
            self::TIER_T2       => Level::Error,
            self::TIER_T3, self::TIER_T4 => Level::Warn,
            self::TIER_EXPECTED => Level::Expected,
            default             => null,
        };
    }

    /** Steps 4–6 for a bare message, then $tier. */
    private static function classifyBare(string $message, string $tier): string
    {
        if (1 === preg_match(ExpectedMessages::TRACE, $message)) {
            return self::TIER_TRACE;
        }
        if (str_starts_with($message, ExpectedMessages::MAIL_ERROR)) {
            return self::TIER_MAIL;
        }
        if (ExpectedMessages::matches($message)) {
            return self::TIER_EXPECTED;
        }

        return $tier;
    }
}
