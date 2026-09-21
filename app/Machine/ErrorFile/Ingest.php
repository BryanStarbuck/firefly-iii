<?php

/*
/*
 * Ingest.php
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
use JsonException;

/**
 * The browser's reports, received — pm/error_err.mdx §4.10 guards 6–10 (the gate ran 1–5):
 *
 *  6. parse — one capped read of the body, `json_decode(depth: 8)`; `events` must be a list; the
 *     first 50 are kept and the rest are event-cap drops; `sid` must match `^[a-z0-9]{8}$`, anything
 *     else counts against the fixed bucket `_` (a raw client string never becomes a sidecar key);
 *  7. rate — under ONE FoldState lock: 240 events per 60 s per sid, 1,200 globally; rate and
 *     event-cap drops go to `r.x`, and a closed window with drops gives exactly one lazy WARN. The
 *     slot is saved and the lock released BEFORE step 10 (flock is per open-file description);
 *  8. sanitise and re-redact — the closed `where`/`doing` rules below, the field caps, the level
 *     and ts rules, Redactor::data() and the text scrubs: the server never trusts the browser;
 *  9. stamp — `app = web`, `data.via = php-web`;
 * 10. write — ErrorFile::writeRecord(): folded and budgeted, not deduped by object.
 *
 * `where`: a page path (leading `/`) is re-templated with templatePath(); anything else must EXACTLY
 * equal an entry of MODULES; otherwise `{x}`. `doing`: one of PHRASES, or `calling {METHOD} {path}`
 * with only the path part templated; otherwise `{x}`. A new fork-owned browser module or phrase adds
 * an entry here, in the same commit (IngestRouteTest replays errorfile/fixtures/browser-batch.json).
 *
 * The body is read here and nowhere else, from php://input with a cap of body_cap + 1 bytes (R12).
 */
final class Ingest
{
    /** The fork-owned browser modules that may report with their repo path as `where` (§7.3). */
    public const array MODULES = [
        'resources/assets/v3/js/support/error-file-app.js',
    ];

    /** The closed browser `doing` phrases (§6.4, §6.5) and the glue's own literals. */
    public const array PHRASES = [
        'running page script',
        'settling a promise',
        'evaluating an Alpine expression',
        'bootstrapping the page',
        'sending error reports',
        'attaching the jQuery net',
    ];

    public const array METHODS     = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    public const string UNKNOWN    = '{x}';
    public const string BUCKET     = '_';
    public const int NAME_CAP      = 200;
    public const int TEXT_CAP      = 2000;
    public const int STACK_LINES   = 24;
    public const int FRAME_CAP     = 400;
    public const int DEPTH         = 8;

    private const string SID       = '/^[a-z0-9]{8}\z/';
    private const string STATIC    = '/^[a-z][a-z0-9-]*\z/';
    private const string ISO_Z     = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?Z\z/';
    private const string CAUSE_SEP = ' | cause: ';

    /** Test seam: the raw body instead of php://input (a PHPUnit request has no php://input). */
    private static ?Closure $input = null;

    /**
     * Guards 6–10 for the current request's body. Returns the number of records handed to the
     * writer. A malformed body writes nothing. Called only by ErrorReportController, which catches.
     */
    public static function accept(): int
    {
        $path = Paths::errorFile();
        if (null === $path) {
            return 0;
        }
        $body = self::body();
        if (null === $body) {
            return 0;
        }

        try {
            $doc = json_decode($body, true, self::DEPTH, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException) {
            return 0;   // guard 6: not a report; an answer, not a fault (R7)
        }
        if (!is_array($doc) || !isset($doc['events']) || !is_array($doc['events']) || !array_is_list($doc['events'])) {
            return 0;
        }
        $max      = max(0, Paths::int('errorfile.ingest.events', 50));
        $events   = array_slice($doc['events'], 0, $max);
        $capDrops = count($doc['events']) - count($events);
        $now      = Folder::now();
        $accepted = self::rate($path, self::sid($doc['sid'] ?? null), count($events), $capDrops, $now);
        $written  = 0;
        foreach (array_slice($events, 0, $accepted) as $event) {
            $record = self::record($event, $now);
            if (null === $record) {
                continue;
            }
            ErrorFile::writeRecord($record);
            ++$written;
        }

        return $written;
    }

    /**
     * The URL-path templater of §6.4, identical to support/error-file.js `templatePath()`: at most
     * two leading static words (one after an `api/v1/` prefix, which is always kept) survive, and
     * every other segment becomes `{x}`. No query, no hash.
     */
    public static function templatePath(string $path): string
    {
        $text     = preg_split('/[?#]/', $path, 2)[0] ?? '';
        $segments = array_values(array_filter(explode('/', $text), static fn (string $part): bool => '' !== $part));
        $out      = [];
        $words    = 2;
        $i        = 0;
        if ('api' === ($segments[0] ?? null) && 'v1' === ($segments[1] ?? null)) {
            $out   = ['api', 'v1'];
            $words = 1;
            $i     = 2;
        }
        for ($n = count($segments); $i < $n; ++$i) {
            if ($words > 0 && 1 === preg_match(self::STATIC, $segments[$i])) {
                $out[] = $segments[$i];
                --$words;

                continue;
            }
            $words = 0;
            $out[] = self::UNKNOWN;
        }

        return (str_starts_with($text, '/') ? '/' : '').implode('/', $out);
    }

    /** The closed server-side rule for a browser `where` (§4.10). */
    public static function where(mixed $raw): string
    {
        $where = LineFormat::stripControlChars(self::str($raw));
        if (str_starts_with($where, '/')) {
            return LineFormat::capMiddle(self::templatePath($where), self::NAME_CAP);
        }

        return in_array($where, self::MODULES, true) ? $where : self::UNKNOWN;
    }

    /** The closed server-side rule for a browser `doing` (§4.10). */
    public static function doing(mixed $raw): string
    {
        $doing = LineFormat::stripControlChars(self::str($raw));
        if (in_array($doing, self::PHRASES, true)) {
            return $doing;
        }
        $methods = implode('|', self::METHODS);
        if (1 === preg_match('/^calling ('.$methods.') (.+)\z/s', $doing, $m)) {
            $path = self::templatePath($m[2]);
            if ('' !== $path) {
                return LineFormat::capMiddle(sprintf('calling %s %s', $m[1], $path), self::NAME_CAP);
            }
        }

        return self::UNKNOWN;
    }

    /** @param null|Closure(): (false|string) $read */
    public static function useInput(?Closure $read): void
    {
        self::$input = $read;
    }

    /** Guard 7, under one FoldState lock that is released before any record is written. */
    private static function rate(string $path, string $sid, int $events, int $capDrops, int $now): int
    {
        if (0 === $events && 0 === $capDrops) {
            return 0;
        }
        $state = FoldState::open($path, $now, 'php-web');
        if (null === $state) {
            return $events;   // like the fold (§4.7 step 2): a sidecar problem never loses a record
        }
        $accepted = $state->rate($sid, $events, $capDrops);
        $lines    = $state->drainQueued();
        if ([] !== $lines) {
            Appender::append($path, implode('', $lines));
        }
        $state->saveAndClose();

        return $accepted;
    }

    /** Guards 8 and 9 for one event; null for an event that is dropped (EXPECTED, not an object). */
    private static function record(mixed $event, int $now): ?Record
    {
        if (!is_array($event)) {
            return null;
        }
        $level = self::level($event['level'] ?? null);
        if (null === $level) {
            return null;
        }

        return new Record(
            ts: self::ts($event['ts'] ?? null, $now),
            level: $level,
            app: 'web',
            where: self::where($event['where'] ?? null),
            doing: self::doing($event['doing'] ?? null),
            error: self::text($event['error'] ?? null),
            cause: self::cause($event['cause'] ?? null),
            stack: self::stack($event['stack'] ?? null),
            data: self::data($event['data'] ?? null),
        );
    }

    /** WARN and ERROR pass; FATAL becomes ERROR; EXPECTED is dropped; anything else is an ERROR. */
    private static function level(mixed $raw): ?Level
    {
        return match (strtoupper(self::str($raw))) {
            'WARN'     => Level::Warn,
            'EXPECTED' => null,
            default    => Level::Error,
        };
    }

    /** The client's ts only when it is ISO-8601 with `Z`; otherwise now. */
    private static function ts(mixed $raw, int $now): string
    {
        $ts = self::str($raw);

        return 1 === preg_match(self::ISO_Z, $ts) ? $ts : LineFormat::iso($now);
    }

    private static function text(mixed $raw): string
    {
        return LineFormat::capMiddle(Redactor::text(LineFormat::stripControlChars(self::str($raw))), self::TEXT_CAP);
    }

    private static function cause(mixed $raw): string
    {
        $cause = Redactor::text(LineFormat::stripControlChars(self::str($raw)));
        if ('' === trim($cause)) {
            return '';
        }
        if (!str_starts_with($cause, self::CAUSE_SEP)) {
            $cause = self::CAUSE_SEP.ltrim($cause);
        }

        return LineFormat::capMiddle($cause, self::TEXT_CAP);
    }

    /** @return list<string> at most 24 frames of at most 400 characters, scrubbed */
    private static function stack(mixed $raw): array
    {
        $lines = is_array($raw) ? array_map(self::str(...), array_values($raw)) : explode("\n", self::str($raw));
        $out   = [];
        foreach ($lines as $line) {
            if (count($out) >= self::STACK_LINES) {
                break;
            }
            $frame = trim(LineFormat::stripControlChars($line));
            if ('' === $frame) {
                continue;
            }
            $out[] = LineFormat::capMiddle(Redactor::text($frame), self::FRAME_CAP);
        }

        return $out;
    }

    /** @return array<string, string> the browser's data, redacted, then `via=php-web` */
    private static function data(mixed $raw): array
    {
        $data = Redactor::data($raw);
        unset($data['via']);
        $data        = array_slice($data, 0, LineFormat::MAX_KEYS - 1, true);
        $data['via'] = 'php-web';

        return $data;
    }

    private static function sid(mixed $raw): string
    {
        return is_string($raw) && 1 === preg_match(self::SID, $raw) ? $raw : self::BUCKET;
    }

    private static function str(mixed $raw): string
    {
        return is_string($raw) ? $raw : (is_int($raw) || is_float($raw) ? (string) $raw : '');
    }

    /** One capped read of the body; null when it is empty, unreadable or over the cap. */
    private static function body(): ?string
    {
        $cap = Paths::int('errorfile.ingest.body_cap', 65_536);
        $cap = $cap > 0 ? $cap : 65_536;
        if (null !== self::$input) {
            $raw = (self::$input)();
        } else {
            $handle = @fopen('php://input', 'rb');
            if (false === $handle) {
                return null;
            }
            $raw = '';

            try {
                while (strlen($raw) <= $cap && !feof($handle)) {
                    $chunk = fread($handle, $cap + 1 - strlen($raw));
                    if (false === $chunk || '' === $chunk) {
                        break;
                    }
                    $raw .= $chunk;
                }
            } finally {
                @fclose($handle);
            }
        }
        if (!is_string($raw) || '' === $raw || strlen($raw) > $cap) {
            return null;
        }

        return $raw;
    }
}
