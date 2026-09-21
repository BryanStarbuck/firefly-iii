<?php

/*
 * CanaryController.php
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

namespace FireflyIII\Machine\ErrorFile\Canary;

use FireflyIII\Machine\MachineException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The PHP web canaries C1–C8 — pm/error_err.mdx §13.4. Each action makes exactly one net fire (or,
 * for C4 and C7, proves that one does NOT), so scripts/error-file-canary.mjs can assert the exact
 * line in a temp error file.
 *
 * The routes are registered by ErrorFileServiceProvider::boot() ONLY when enabled() holds —
 * FIREFLY_ERROR_FILE_CANARY=1 AND a non-empty FIREFLY_ERROR_FILE — so production never has them and a
 * canary can never write the real file. They carry no middleware at all: the surface (`php-web`,
 * `php-api`, `php-machine`) comes from the path prefix alone (§3.3). Every action checks enabled()
 * again and answers a bare 404 when it does not hold.
 *
 * Everything here is synthetic: no ledger value, no key, no private path (§12).
 */
final class CanaryController
{
    /** The URL segment every canary route carries. */
    public const string SEGMENT = '__error-file-canary';

    /** Both variables, or nothing (§4.11, §13.4): the canary switch AND an explicit error-file path. */
    public static function enabled(): bool
    {
        $path = config('errorfile.path');

        return true === (bool) config('errorfile.canary') && is_string($path) && '' !== trim($path);
    }

    /** C1 (`/__error-file-canary/throw`, php-web) and C2 (`/api/v1/__error-file-canary/throw`, php-api). */
    public function throw(): Response
    {
        $this->refuseUnlessEnabled();

        throw new RuntimeException('Synthetic canary failure');
    }

    /** C3: an `internal` MachineException — the record's headline is the PREVIOUS RuntimeException. */
    public function internal(): Response
    {
        $this->refuseUnlessEnabled();

        throw MachineException::internal(previous: new RuntimeException('Synthetic canary cause'));
    }

    /** C4: an answer code (`conflict`) — a refusal is not a fault, so NO line (R7). */
    public function conflict(): Response
    {
        $this->refuseUnlessEnabled();

        throw MachineException::conflict('Synthetic canary conflict');
    }

    /** C5: an HttpException 500 — written although HttpException is on $dontReport (the >= 500 override). */
    public function abort500(): Response
    {
        $this->refuseUnlessEnabled();
        abort(500);
    }

    /** C6: a bare-string Log::error — tier T3, a WARN whose `where` is this line. */
    public function logBare(): Response
    {
        $this->refuseUnlessEnabled();
        Log::error('Synthetic canary bare log line');

        return new Response('', 204);
    }

    /**
     * C7: replays the five upstream records of one expected duplicate transaction
     * (TransactionJournalFactory.php:135–137, TransactionGroupFactory.php:67,
     * TransactionGroupRepository.php:359) — NO line: two are T4 warnings, one is on the
     * expected-prefix list, one is a trace continuation, one is a T4 warning.
     */
    public function logDuplicate(): Response
    {
        $this->refuseUnlessEnabled();
        Log::warning('TransactionJournalFactory::create() caught a duplicate journal in createJournal()');
        Log::error('Duplicate of transaction #1.');
        Log::error('#0 synthetic/canary/trace.php(1): FireflyIII\Machine\ErrorFile\Canary\CanaryController->logDuplicate()');
        Log::warning('GroupFactory::create() caught journalFactory::create() with a duplicate!');
        Log::warning('Group repository caught group factory with a duplicate exception!');

        return new Response('', 204);
    }

    /** C8: an out-of-memory fatal — one FATAL, net=shutdown (via HandleExceptions::handleShutdown()). */
    public function oom(): Response
    {
        $this->refuseUnlessEnabled();
        ini_set('memory_limit', '64M');
        $hog = [];
        while (true) {   // @phpstan-ignore while.alwaysTrue
            $hog[] = str_repeat('x', 1_048_576);
        }
    }

    private function refuseUnlessEnabled(): void
    {
        if (!self::enabled()) {
            abort(404);
        }
    }
}
