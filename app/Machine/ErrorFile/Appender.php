<?php

/*
 * Appender.php
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
use Throwable;

/**
 * The ONLY code that opens error.err — pm/error_err.mdx §4.4.
 *
 *  1. umask(0o077) saved and ALWAYS restored, so the file is created 0600 and upstream files created
 *     later in the same process keep their usual modes;
 *  2. fopen('ab'); on the first failure only, Paths::ensureDir() (0700) and retry;
 *  3. flock(LOCK_EX|LOCK_NB), spinning in 5 ms steps for at most `lock_wait_ms` (200 ms);
 *  4. inode check (fstat vs stat): reopen up to three times when another process rotated it;
 *  5. real size after clearstatcache(); at `max_bytes` or more, rotate UNDER the lock
 *     (.backups dropped, .n → .n+1, error.err → .1), reopen;
 *  6. one fwrite, fflush, unlock.
 *
 * A lock timeout writes unlocked with O_APPEND, in writes of at most 8 KiB that each hold whole
 * records, so one stuck writer can never freeze a faulting request. The first failure in a process
 * writes ONE line to php://stderr (or the useFallback() seam), then the appender stays silent (R11).
 *
 * A lock timeout is STICKY per lock file (lockBounded()): after one 200 ms timeout on a file, every
 * later attempt on that file in this process makes one non-blocking try and no spin, until a try
 * succeeds or the back-off (one fold window) lapses. So a held lock costs a request 200 ms ONCE,
 * however many records it writes (AC 6, invariant 7, R11), instead of 200 ms per record.
 */
final class Appender
{
    /** The largest single unlocked write (a record is at most 8,000 bytes, §3.2). */
    public const int CHUNK = 8192;

    private const int SPIN_US = 5000;

    private static bool $failed = false;

    private static ?Closure $fallback = null;

    /** lock file path → hrtime (ms) until which a lock attempt makes one try and does not spin. @var array<string, int> */
    private static array $backOff = [];

    public static function append(string $path, string $bytes): bool
    {
        if ('' === $bytes) {
            return true;
        }
        $old = umask(0o077);

        try {
            return self::appendLocked($path, $bytes);
        } catch (Throwable $e) {
            self::fail($path, $e::class);

            return false;
        } finally {
            umask($old);
        }
    }

    /**
     * Take LOCK_EX on an open handle, non-blocking, spinning in 5 ms steps for at most $waitMs.
     *
     * @param resource $handle
     */
    public static function lock($handle, int $waitMs): bool
    {
        $deadline = hrtime(true) + max(0, $waitMs) * 1_000_000;
        do {
            $wouldBlock = 0;
            if (@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                return true;
            }
            if (hrtime(true) >= $deadline) {
                return false;
            }
            usleep(self::SPIN_US);
        } while (true);
    }

    /**
     * lock() with a sticky timeout for $file (§4.4 step 3, §4.7 step 2). The first timeout on a file
     * costs the full bounded wait; until the back-off lapses, later attempts on it make ONE
     * non-blocking try (zero wait). A success clears the back-off.
     *
     * @param resource $handle
     */
    public static function lockBounded($handle, string $file): bool
    {
        $now  = intdiv(hrtime(true), 1_000_000);
        $wait = isset(self::$backOff[$file]) && $now < self::$backOff[$file] ? 0 : self::lockWaitMs();
        if (self::lock($handle, $wait)) {
            unset(self::$backOff[$file]);

            return true;
        }
        if (count(self::$backOff) >= 64) {
            self::$backOff = [];
        }
        self::$backOff[$file] = intdiv(hrtime(true), 1_000_000) + max(1, Paths::int('errorfile.fold_window_s', 60)) * 1000;

        return false;
    }

    /** The configured bounded lock wait (§4.11 `lock_wait_ms`). */
    public static function lockWaitMs(): int
    {
        return Paths::int('errorfile.lock_wait_ms', 200);
    }

    /**
     * The one-shot stderr fallback (R11): the first call in a process writes one line, every later
     * call is silent. Never throws, never logs.
     */
    public static function stderr(string $line): void
    {
        if (self::$failed) {
            return;
        }
        self::$failed = true;

        try {
            $line = rtrim(LineFormat::stripControlChars($line))."\n";
            if (null !== self::$fallback) {
                (self::$fallback)($line);

                return;
            }
            $handle = @fopen('php://stderr', 'wb');
            if (false !== $handle) {
                @fwrite($handle, $line);
                @fclose($handle);
            }
        } catch (Throwable) {
            // the fallback of the fallback is silence
        }
    }

    /** Test seam: where the one stderr line goes (ErrorFile::useFallback() delegates here). */
    public static function useFallback(?Closure $stderr): void
    {
        self::$fallback = $stderr;
    }

    public static function resetForTests(): void
    {
        self::$failed   = false;
        self::$fallback = null;
        self::$backOff  = [];
    }

    private static function appendLocked(string $path, string $bytes): bool
    {
        $handle = @fopen($path, 'ab');
        if (false === $handle) {
            Paths::ensureDir($path);
            $handle = @fopen($path, 'ab');
        }
        if (false === $handle) {
            self::fail($path, 'open');

            return false;
        }
        $locked = self::lockBounded($handle, $path);

        // 4. inode check: another process may have rotated the file between our open and our lock.
        for ($i = 0; $locked && $i < 3 && !self::sameFile($handle, $path); ++$i) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
            $handle = @fopen($path, 'ab');
            if (false === $handle) {
                self::fail($path, 'reopen');

                return false;
            }
            $locked = self::lockBounded($handle, $path);
        }

        // 5. real size, under the lock; rotate at max_bytes or more.
        if ($locked) {
            clearstatcache(true, $path);
            $stat = @fstat($handle);
            if (is_array($stat) && $stat['size'] >= Paths::int('errorfile.max_bytes', 5_242_880)) {
                self::rotate($path, max(1, Paths::int('errorfile.backups', 5)));
                $fresh = @fopen($path, 'ab');
                if (false !== $fresh) {
                    $freshLocked = self::lockBounded($fresh, $path);
                    @flock($handle, LOCK_UN);
                    @fclose($handle);
                    $handle = $fresh;
                    $locked = $freshLocked;
                }
            }
        }

        // 6. one write under the lock, or ≤ 8 KiB whole-record writes without it.
        $ok = $locked ? self::writeAll($handle, $bytes) : self::writeChunked($handle, $bytes);
        @fflush($handle);
        if ($locked) {
            @flock($handle, LOCK_UN);
        }
        @fclose($handle);
        if (!$ok) {
            self::fail($path, 'write');
        }

        return $ok;
    }

    /** @param resource $handle */
    private static function sameFile($handle, string $path): bool
    {
        clearstatcache(true, $path);
        $onDisk = @stat($path);
        $open   = @fstat($handle);

        return is_array($onDisk) && is_array($open) && $onDisk['ino'] === $open['ino'] && $onDisk['dev'] === $open['dev'];
    }

    /** .N dropped, .n → .n+1, error.err → .1. There is never a .N+1. */
    private static function rotate(string $path, int $backups): void
    {
        @unlink($path.'.'.$backups);
        for ($i = $backups - 1; $i >= 1; --$i) {
            if (file_exists($path.'.'.$i)) {
                @rename($path.'.'.$i, $path.'.'.($i + 1));
            }
        }
        @rename($path, $path.'.1');
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes): bool
    {
        $length  = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $n = @fwrite($handle, 0 === $written ? $bytes : substr($bytes, $written));
            if (false === $n || 0 === $n) {
                return false;
            }
            $written += $n;
        }

        return true;
    }

    /**
     * The unlocked fallback: whole records packed into writes of at most 8 KiB (§4.4).
     *
     * @param resource $handle
     */
    private static function writeChunked($handle, string $bytes): bool
    {
        $records = preg_split('/(?<=\n)(?=\[)/', $bytes);
        if (!is_array($records)) {
            $records = [$bytes];
        }
        $chunk = '';
        $ok    = true;
        foreach ($records as $record) {
            if ('' !== $chunk && strlen($chunk) + strlen($record) > self::CHUNK) {
                $ok    = self::writeAll($handle, $chunk) && $ok;
                $chunk = '';
            }
            $chunk .= $record;
        }
        if ('' !== $chunk) {
            $ok = self::writeAll($handle, $chunk) && $ok;
        }

        return $ok;
    }

    private static function fail(string $path, string $why): void
    {
        self::stderr(sprintf('firefly error file: could not write %s (%s); further failures in this process are silent', $path, $why));
    }
}
