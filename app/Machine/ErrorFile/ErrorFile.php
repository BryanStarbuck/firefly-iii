<?php

/*
 * ErrorFile.php
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

use Closure;
use FireflyIII\Machine\Http\MachineExceptionHandler;
use FireflyIII\Machine\MachineException;
use Throwable;

/**
 * The static facade of the error file — pm/error_err.mdx §7.1. Kept deliberately thin: it only
 * delegates, so its first compile after an out-of-memory fatal costs little (§4.7).
 *
 * `for()` stores a string and does no other work (R3). Everything else is total.
 */
final class ErrorFile
{
    /** @var array<string, Site> */
    private static array $sites = [];

    /** The per-`where` handle, memoised. $where is a repo-relative literal (R14). */
    public static function for(string $where): Site
    {
        return self::$sites[$where] ??= new Site($where);
    }

    /** Called ONLY from MachineExceptionHandler::report(), before parent::report(). Total. */
    public static function handlerReport(Throwable $e, MachineExceptionHandler $handler): void
    {
        Reporter::handlerReport($e, $handler);
    }

    /** A pre-built record (ingest). Folded and budgeted, NOT deduped. Total. */
    public static function writeRecord(Record $record): void
    {
        Reporter::record($record);
    }

    /** Marks a cause-less batch echo whose op fault the sub-request already admitted (§4.5 step 1). */
    public static function markEcho(MachineException $e): void
    {
        RequestState::markEcho($e);
    }

    public static function isReported(Throwable $e): bool
    {
        return Reporter::isSeen($e);
    }

    public static function path(): ?string
    {
        return Paths::errorFile();
    }

    // ---------------------------------------------- test seams (tests/Machine/** only) ---

    public static function resetForTests(): void
    {
        self::$sites = [];
        Reporter::resetForTests();
        Paths::resetForTests();
        Folder::resetForTests();
        Appender::resetForTests();
    }

    /** The ONLY in-process path under PHPUnit (R13). */
    public static function usePath(?string $path): void
    {
        Paths::usePath($path);
    }

    /** @param null|Closure(): int $nowMs milliseconds */
    public static function useClock(?Closure $nowMs): void
    {
        Folder::useClock($nowMs);
    }

    /** @param null|Closure(string): void $stderr */
    public static function useFallback(?Closure $stderr): void
    {
        Appender::useFallback($stderr);
    }
}
