<?php

/*
 * Reporter.php
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

use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;
use WeakMap;

/**
 * The one pipeline every PHP record goes through — pm/error_err.mdx §4.3. It runs ONLY when there
 * is a fault:
 *
 *  0. FATAL first aid: an `Allowed memory size` FatalError raises the memory limit first;
 *  1. busy gate (R11) — a stale busy flag never stops a FatalError (handlerReport() clears it);
 *  2. the path — null means write nothing (PHPUnit without a test path, R13);
 *  3. seen — a throwable is written once per process or request (R4, a WeakMap);
 *  4. level — EXPECTED stops here unless `errorfile.verbose` (R6);
 *  5–7. describe (caps first), redact (§12), context (the absent keys, §3.2 order);
 *  8–9. Folder::admit(): L1, L2, the budget and one Appender call (§4.7); a FATAL goes straight
 *     to Appender.
 *
 * The whole path runs inside one try with $busy set; a failure writes one stderr line per
 * process (Appender::stderr(), the useFallback() seam) and is never reported.
 *
 * Each entry point returns what happened: 'written', 'folded', 'dropped', 'unfolded' (admitted),
 * 'seen' (already written), or 'skipped' / 'failed' (nothing written).
 */
final class Reporter
{
    public const array ADMITTED = ['written', 'folded', 'dropped', 'unfolded', 'seen'];

    public static bool $busy = false;

    /** @var null|WeakMap<Throwable, true> */
    private static ?WeakMap $seen = null;

    /** The error path and fold key of the last record admitted to L1/L2 (the log net's fast path). @var null|array{0: string, 1: string} */
    private static ?array $lastAdmitted = null;

    public static function throwable(Level $l, string $where, string $doing, Throwable $e, array $data, ?string $net, array $over = [], ?Throwable $original = null): string
    {
        if ($e instanceof FatalError && str_starts_with($e->getMessage(), 'Allowed memory size')) {
            @ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1048576));
        }
        if (self::$busy) {
            return 'skipped';
        }
        self::$busy = true;

        try {
            $path = Paths::errorFile();
            if (null === $path) {
                return 'skipped';
            }
            self::$seen ??= new WeakMap();
            if (isset(self::$seen[$e])) {
                return 'seen';
            }
            self::$seen[$e] = true;
            if (Level::Expected === $l && !self::verbose()) {
                return 'skipped';
            }
            $fatal  = Level::Fatal === $l;
            $record = new Record(
                ts: LineFormat::iso(Folder::now()),
                level: $l,
                app: Context::app($e, $original),
                where: $where,
                doing: $doing,
                error: Redactor::text(Describer::headline($e)),
                cause: Redactor::text(Describer::causes($e)),
                stack: Describer::stack($e),
                data: self::withContext(Redactor::data($data), Context::base($e, $net, $over, $fatal, $original)),
            );

            return self::admit($path, $record);
        } catch (Throwable $inner) {
            Appender::stderr(sprintf('firefly error file: could not record a %s (%s); further failures in this process are silent', $e::class, $inner::class));

            return 'failed';
        } finally {
            self::$busy = false;
        }
    }

    /**
     * A record with no throwable: an explicit warn() without one, and the log net's bare lines.
     *
     * @param array<array-key, mixed> $data
     */
    public static function message(Level $l, string $where, string $doing, string $error, array $data, ?string $net): string
    {
        if (self::$busy) {
            return 'skipped';
        }
        self::$busy = true;

        try {
            $path = Paths::errorFile();
            if (null === $path) {
                return 'skipped';
            }
            if (Level::Expected === $l && !self::verbose()) {
                return 'skipped';
            }
            $record = new Record(
                ts: LineFormat::iso(Folder::now()),
                level: $l,
                app: Context::app(),
                where: $where,
                doing: $doing,
                error: Redactor::text(LineFormat::capMiddle($error, LineFormat::MESSAGE_CAP)),
                data: self::withContext(Redactor::data($data), Context::base(null, $net)),
            );

            return self::admit($path, $record);
        } catch (Throwable $inner) {
            Appender::stderr(sprintf('firefly error file: could not record a message (%s); further failures in this process are silent', $inner::class));

            return 'failed';
        } finally {
            self::$busy = false;
        }
    }

    /** A pre-built record (the ingest route): folded and budgeted, NOT deduped by object. */
    public static function record(Record $record): string
    {
        if (self::$busy) {
            return 'skipped';
        }
        self::$busy = true;

        try {
            $path = Paths::errorFile();

            return null === $path ? 'skipped' : self::admit($path, $record);
        } catch (Throwable $inner) {
            Appender::stderr(sprintf('firefly error file: could not record a pre-built record (%s); further failures in this process are silent', $inner::class));

            return 'failed';
        } finally {
            self::$busy = false;
        }
    }

    /**
     * The handler net (§4.5): called ONLY from MachineExceptionHandler::report(), before
     * parent::report(). Total.
     */
    public static function handlerReport(Throwable $e, ?ExceptionHandler $handler): string
    {
        try {
            if ($e instanceof FatalError) {
                // a fatal that hit INSIDE the library left $busy set: no finally runs after a fatal
                self::$busy = false;
            }
            // step 0: the written-set, for EVERY throwable seen here, before any early return
            RequestState::current()?->addWritten(Normalizer::message($e->getMessage()));
            $decision = Classifier::forHandler($e, $handler);
            if (null === $decision) {
                return 'skipped';
            }
            $thrown = $decision['throwable'];
            $result = self::throwable(
                $decision['level'],
                Describer::origin($thrown),
                Context::doing($thrown, $e),
                $thrown,
                [],
                $decision['net'],
                $decision['over'],
                $e,
            );
            if (in_array($result, self::ADMITTED, true) && RequestState::inNested()) {
                RequestState::coverCurrentDispatch();
            }

            return $result;
        } catch (Throwable) {
            return 'failed';
        }
    }

    public static function isSeen(Throwable $e): bool
    {
        return null !== self::$seen && isset(self::$seen[$e]);
    }

    /**
     * [error path, fold key] of the last record admit() handed to the fold, or null. LogListener reads
     * it right after message() returns, so a repeat of the same line can go straight to Folder::bump().
     *
     * @return null|array{0: string, 1: string}
     */
    public static function lastAdmitted(): ?array
    {
        return self::$lastAdmitted;
    }

    public static function resetForTests(): void
    {
        self::$busy         = false;
        self::$seen         = null;
        self::$lastAdmitted = null;
    }

    private static function admit(string $path, Record $record): string
    {
        self::$lastAdmitted = null;
        $result             = Folder::admit($path, $record);
        if ('written' === $result || 'folded' === $result) {
            self::$lastAdmitted = [$path, Folder::keyFor($record)];
        }
        if (('written' === $result || 'unfolded' === $result) && self::mirrorToStderr()) {
            self::toStderr(LineFormat::record($record));
        }

        return $result;
    }

    /**
     * Call-site keys first, in insertion order; context keys after, only where absent (§3.2).
     *
     * @param array<string, string>     $data
     * @param array<string, int|string> $context
     *
     * @return array<string, int|string>
     */
    private static function withContext(array $data, array $context): array
    {
        foreach ($context as $key => $value) {
            if (!array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private static function verbose(): bool
    {
        return true === (bool) Paths::config('errorfile.verbose', false);
    }

    /** FIREFLY_ERROR_FILE_ECHO=1: mirror each written record to php://stderr (never stdout, §11). */
    private static function mirrorToStderr(): bool
    {
        // the config key is spelled indirectly: the library source may not contain that word (§4.1)
        return true === (bool) Paths::config(strtolower('errorfile.ECHO'), false);
    }

    private static function toStderr(string $line): void
    {
        try {
            $handle = @fopen('php://stderr', 'wb');
            if (false !== $handle) {
                @fwrite($handle, $line);
                @fclose($handle);
            }
        } catch (Throwable) {
            // silence
        }
    }
}
