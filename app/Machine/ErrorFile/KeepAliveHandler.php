<?php

/*
 * KeepAliveHandler.php
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

use Monolog\Handler\AbstractHandler;
use Monolog\Level as MonologLevel;
use Monolog\LogRecord;

/**
 * Keeps the log net (N4) alive above APP_LOG_LEVEL — pm/error_err.mdx §4.9, N5.
 *
 * Illuminate\Log\Logger::writeLog() drops a record BEFORE MessageLogged fires when no Monolog
 * handler accepts its level, so with APP_LOG_LEVEL=critical the log net would go blind to every
 * Log::error. This handler accepts Error and above, so the record reaches the event; its handle()
 * returns FALSE, so the record bubbles on to upstream's own handlers and their output is unchanged.
 * It writes nothing.
 *
 * Monolog's NullHandler must NOT be used instead: its handle() returns true, which stops bubbling
 * and would silently delete every ERROR line from upstream's daily file. KeepAliveTest asserts the
 * daily output is byte-identical with and without this handler.
 */
final class KeepAliveHandler extends AbstractHandler
{
    public function __construct()
    {
        parent::__construct(MonologLevel::Error, true);
    }

    /** Error and above: exactly the levels the log net reads as faults (§4.6 step 1). */
    public function isHandling(LogRecord $record): bool
    {
        return $record->level->value >= MonologLevel::Error->value;
    }

    public function handle(LogRecord $record): bool
    {
        return false;   // bubble on: upstream's handlers see the record exactly as before
    }
}
