<?php

/*
 * Folder.php
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
 * The in-process fold (L1) and the lazy shutdown flush — pm/error_err.mdx §4.7, R10.
 *
 * admit() runs the whole non-FATAL algorithm for one record and writes what it has to:
 *
 *  1. L1: a key seen in this process whose (remembered L2) window is still open → n++, ZERO I/O.
 *     A key whose window has closed is a rollover: its owed count travels with this sighting into
 *     the same locked transaction and is handed to L2 before anything is swept. L1 holds at most
 *     `l1_max_keys` (200) keys; the oldest is evicted and its owed count goes to the shutdown flush.
 *  2. FoldState::open() — any open or lock failure → the record is appended unfolded, and the key
 *     is still remembered in L1 (f = now), so its repeats fold in-process and a held lock is paid
 *     once (Appender::lockBounded() makes the timeout sticky), never once per record;
 *  3–6. FoldState: hand over, sweep, admit, budget;
 *  7. Appender::append() with every queued line and then the record, in ONE call;
 *  8. FoldState::saveAndClose().
 *
 * A FATAL skips L1, L2 and the budget and goes straight to Appender (R9).
 *
 * On the first owed count, ONE register_shutdown_function flush is registered (guarded by a
 * static). flushOwed() takes the fold lock once per file and hands every owed count to L2 (same
 * window) or writes that window's own summary — it never opens a new L2 window for a count. It skips
 * a file whose directory has gone (a finished PHPUnit sandbox).
 *
 * Statics are per request under `php -S` and span the command under artisan. Every public method
 * is total.
 */
final class Folder
{
    /** @var array<string, array{path: string, key: string, f: int, n: int, m: array{l: string, a: string, W: string, d: string, h: string}}> */
    private static array $l1 = [];

    /** Owed counts of evicted or unflushable L1 entries, waiting for the shutdown flush. @var list<array{path: string, key: string, f: int, n: int, m: array{l: string, a: string, W: string, d: string, h: string}}> */
    private static array $owed = [];

    /** raw app␀where␀doing␀error → fold key, so a storm pays no regex per repeat. @var array<string, string> */
    private static array $keys = [];

    /** slot → the fold window (ms) in force when the slot was remembered, for bump(). @var array<string, int> */
    private static array $windows = [];

    private static bool $registered = false;

    private static ?Closure $clock = null;

    /**
     * Fold, budget and write one record to $errorPath.
     *
     * @return 'written'|'folded'|'dropped'|'unfolded'|'failed'
     */
    public static function admit(string $errorPath, Record $record, ?int $nowMs = null, ?string $key = null): string
    {
        try {
            if (Level::Fatal === $record->level) {
                return Appender::append($errorPath, LineFormat::record($record)) ? 'written' : 'failed';
            }
            $now    = $nowMs ?? self::now();
            $key  ??= self::keyFor($record);
            $slot   = $errorPath."\0".$key;
            $window = self::windowMs();
            $carry  = null;
            if (isset(self::$l1[$slot])) {
                $entry = self::$l1[$slot];
                if ($now >= $entry['f'] && $now - $entry['f'] < $window) {
                    ++self::$l1[$slot]['n'];
                    self::registerFlush();

                    return 'folded';
                }
                if ($entry['n'] > 0) {
                    $carry = $entry;
                }
                unset(self::$l1[$slot]);
            }
            $meta  = self::meta($record);
            $state = FoldState::open($errorPath, $now, $record->app);
            if (null === $state) {
                if (null !== $carry) {
                    self::$owed[] = $carry;
                    self::registerFlush();
                }
                $written = Appender::append($errorPath, LineFormat::record($record));
                // Remember it in L1 even unfolded: a repeat in this window is then n++ with zero I/O
                // (its count goes to the shutdown flush), not another lock attempt and another line.
                self::remember($slot, $errorPath, $key, $now, $meta);

                return $written ? 'unfolded' : 'failed';
            }
            if (null !== $carry) {
                $state->handOver($key, $carry['f'], $carry['n'], $carry['m']);
            }
            $result = $state->admit($key, $meta, $now);
            $lines  = $state->drainQueued();
            if ('written' === $result['state']) {
                $lines[] = LineFormat::record($record);
            }
            if ([] !== $lines) {
                Appender::append($errorPath, implode('', $lines));
            }
            $state->saveAndClose();
            if ('dropped' !== $result['state']) {
                self::remember($slot, $errorPath, $key, $result['f'], $meta);
            }

            return $result['state'];
        } catch (Throwable) {
            return 'failed';
        }
    }

    /**
     * The L1 repeat alone: when (path, key) is in L1 and its window is still open, n++ with zero I/O
     * and true; otherwise false, and the caller takes the full admit() path. The log net's storm fast
     * path (LogListener) uses it so a repeat of a line it already admitted builds no record.
     */
    public static function bump(string $errorPath, string $key, ?int $nowMs = null): bool
    {
        try {
            $slot = $errorPath."\0".$key;
            if (!isset(self::$l1[$slot], self::$windows[$slot])) {
                return false;
            }
            $now = $nowMs ?? self::now();
            $f   = self::$l1[$slot]['f'];
            if ($now < $f || $now - $f >= self::$windows[$slot]) {
                return false;
            }
            ++self::$l1[$slot]['n'];
            self::registerFlush();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Hand every owed L1 count to L2 (the shutdown flush; tests call it directly with a fake clock).
     */
    public static function flushOwed(?int $nowMs = null): void
    {
        try {
            $owed        = self::$owed;
            self::$owed  = [];
            foreach (self::$l1 as $slot => $entry) {
                if ($entry['n'] > 0) {
                    $owed[]                 = $entry;
                    self::$l1[$slot]['n'] = 0;
                }
            }
            if ([] === $owed) {
                return;
            }
            $now    = $nowMs ?? self::now();
            $byPath = [];
            foreach ($owed as $entry) {
                $byPath[$entry['path']][] = $entry;
            }
            foreach ($byPath as $path => $entries) {
                if (!is_dir(dirname($path))) {
                    continue;
                }
                $state = FoldState::open($path, $now, $entries[0]['m']['a']);
                if (null === $state) {
                    $lines = '';
                    foreach ($entries as $entry) {
                        $lines .= LineFormat::summary(Level::tryFrom($entry['m']['l']) ?? Level::Error, $entry['m']['a'], $entry['m']['W'], $entry['m']['d'], $entry['n'], $entry['f'], $entry['m']['h'], $now, intdiv(self::windowMs(), 1000));
                    }
                    Appender::append($path, $lines);

                    continue;
                }
                foreach ($entries as $entry) {
                    $state->handOver($entry['key'], $entry['f'], $entry['n'], $entry['m']);
                }
                $lines = $state->drainQueued();
                if ([] !== $lines) {
                    Appender::append($path, implode('', $lines));
                }
                $state->saveAndClose();
            }
        } catch (Throwable) {
            // total
        }
    }

    /** The fold key of a record: app, where, doing, FQCN and the normalised message (§3.5). */
    public static function keyFor(Record $record): string
    {
        $raw = $record->app."\0".$record->where."\0".$record->doing."\0".$record->error;
        if (isset(self::$keys[$raw])) {
            return self::$keys[$raw];
        }
        $class   = '';
        $message = $record->error;
        $at      = strpos($record->error, ': ');
        if (false !== $at && !str_contains(substr($record->error, 0, $at), ' ')) {
            $class   = substr($record->error, 0, $at);
            $message = substr($record->error, $at + 2);
        }
        if (count(self::$keys) >= 200) {
            self::$keys = [];
        }

        return self::$keys[$raw] = Normalizer::key($record->app, $record->where, $record->doing, $class, $message);
    }

    /** Test seam: the clock (ErrorFile::useClock() delegates here). Returns milliseconds. */
    public static function useClock(?Closure $nowMs): void
    {
        self::$clock = $nowMs;
    }

    public static function now(): int
    {
        if (null !== self::$clock) {
            try {
                return (int) (self::$clock)();
            } catch (Throwable) {
                // fall through to the real clock
            }
        }

        return (int) floor(microtime(true) * 1000);
    }

    public static function resetForTests(): void
    {
        self::$l1      = [];
        self::$owed    = [];
        self::$keys    = [];
        self::$windows = [];
        self::$clock = null;
    }

    /** Test seam: how many keys L1 holds, and how many counts it owes. @return array{keys: int, owed: int} */
    public static function stats(): array
    {
        $owed = 0;
        foreach (self::$l1 as $entry) {
            $owed += $entry['n'];
        }
        foreach (self::$owed as $entry) {
            $owed += $entry['n'];
        }

        return ['keys' => count(self::$l1), 'owed' => $owed];
    }

    /** @param array{l: string, a: string, W: string, d: string, h: string} $meta */
    private static function remember(string $slot, string $path, string $key, int $f, array $meta): void
    {
        self::$l1[$slot]      = ['path' => $path, 'key' => $key, 'f' => $f, 'n' => 0, 'm' => $meta];
        self::$windows[$slot] = self::windowMs();
        if (count(self::$windows) > 4 * max(1, Paths::int('errorfile.l1_max_keys', 200))) {
            self::$windows = array_intersect_key(self::$windows, self::$l1);
        }
        $max             = max(1, Paths::int('errorfile.l1_max_keys', 200));
        while (count(self::$l1) > $max) {
            $oldest = (string) array_key_first(self::$l1);
            if ($oldest === $slot) {
                break;
            }
            $entry = self::$l1[$oldest];
            unset(self::$l1[$oldest]);
            if ($entry['n'] > 0) {
                self::$owed[] = $entry;
                self::registerFlush();
            }
        }
    }

    /** @return array{l: string, a: string, W: string, d: string, h: string} */
    private static function meta(Record $record): array
    {
        $cap = max(1, Paths::int('errorfile.fold_field_max_chars', 200));

        return [
            'l' => $record->level->value,
            'a' => $record->app,
            'W' => mb_substr($record->where, 0, $cap),
            'd' => mb_substr($record->doing, 0, $cap),
            'h' => mb_substr($record->error, 0, $cap),
        ];
    }

    private static function windowMs(): int
    {
        return max(1, Paths::int('errorfile.fold_window_s', 60)) * 1000;
    }

    private static function registerFlush(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        register_shutdown_function(static function (): void {
            self::flushOwed();
        });
    }
}
