<?php

/*
 * LineFormat.php
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

use Throwable;

/**
 * Record → line(s) — pm/error_err.mdx §3.2 (LOCKED). The twin of errorfile/src/format.ts; both are
 * asserted byte for byte against errorfile/fixtures/golden-lines.txt (R16).
 *
 *   [ts] [LEVEL] [app] [where] doing — <error> {k=v k2="v with space"} | cause: Name: message (code=…)
 *       at frame
 *
 * A field with no content is omitted together with its separator (`[where]`, ` doing`, ` — error`,
 * ` {data}`; the cause carries its own ` | cause: ` separators). Every field goes through
 * stripControlChars(), so a hostile message can never forge a second header line. The whole record
 * is at most 8,000 UTF-8 bytes including its final "\n", cut only at a stack-frame boundary; a
 * header that alone would pass the cap has its message middle-cut (down to 200 characters), then
 * its cause chain, then the message again, until it fits.
 *
 * Every method is total.
 */
final class LineFormat
{
    public const int MESSAGE_CAP = 2000;
    public const int DATA_CAP    = 1000;
    public const int RECORD_CAP  = 8000;
    public const int MAX_KEYS    = 40;
    public const string SEP      = ' — ';
    public const string INDENT   = '    ';
    public const string MARKER   = ' … ';

    /** A header over the cap keeps at least this many characters of its message before the cause is cut. */
    public const int KEEP_CHARS  = 200;

    /** \x00–\x1f, \x7f, U+2028 and U+2029: anything that could end a line or forge a header. */
    private const string CONTROL = '/[\x00-\x1f\x7f\x{2028}\x{2029}]/u';

    /** Characters that force a data value into double quotes. */
    private const string NEEDS_QUOTES = '/[ ={}"]/';

    /** The header line, no trailing newline. */
    public static function header(Record $record): string
    {
        return self::headerWith($record, $record->error, $record->cause);
    }

    /** The whole record: header plus indented stack lines, capped, newline-terminated. */
    public static function record(Record $record): string
    {
        try {
            $limit  = self::RECORD_CAP - 1;
            $error  = $record->error;
            $cause  = $record->cause;
            $header = self::headerWith($record, $error, $cause);
            // 1. the message, down to a floor of 200 characters; 2. the cause chain, down to
            // nothing; 3. the message, down to nothing; 4. a byte cut that never splits a character.
            foreach ([['error', self::KEEP_CHARS], ['cause', 0], ['error', 0]] as [$which, $floor]) {
                for ($i = 0; $i < 8 && strlen($header) > $limit; ++$i) {
                    $over = strlen($header) - $limit;
                    if ('error' === $which) {
                        $error = self::shrink($error, $over, $floor);
                    } else {
                        $cause = self::shrink($cause, $over, $floor);
                    }
                    $header = self::headerWith($record, $error, $cause);
                }
            }
            if (strlen($header) > $limit) {
                $header = mb_strcut($header, 0, $limit, 'UTF-8');
            }
            $out = $header."\n";
            foreach ($record->stack as $frame) {
                $line = self::INDENT.self::field((string) $frame)."\n";
                if (strlen($out) + strlen($line) > self::RECORD_CAP) {
                    break;
                }
                $out .= $line;
            }

            return $out;
        } catch (Throwable) {
            return sprintf("[%s] [%s] [?] unformattable record\n", self::field($record->ts), $record->level->value);
        }
    }

    /**
     * A folded summary line (§3.4): `×N more in the 60s window from HH:MM:SS: <headline>`, stamped
     * now. PHP writes summaries lazily, so the window is named by its start, never as "previous".
     *
     * @param array<string, null|bool|float|int|string> $data
     */
    public static function summary(
        Level $level,
        string $app,
        string $where,
        string $doing,
        int $count,
        int $firstMs,
        string $headline,
        int $nowMs,
        int $windowS = 60,
        array $data = [],
    ): string {
        return self::record(new Record(
            ts: self::iso($nowMs),
            level: $level,
            app: $app,
            where: $where,
            doing: $doing,
            error: self::summaryText($count, $firstMs, $headline, $windowS),
            data: $data,
        ));
    }

    /** The error text of a summary line. */
    public static function summaryText(int $count, int $firstMs, string $headline, int $windowS = 60): string
    {
        $what = '' === $headline ? '' : ': '.$headline;

        return sprintf('×%d more in the %ds window from %s%s', $count, $windowS, self::clock($firstMs), $what);
    }

    /** HH:MM:SS (UTC, like the timestamps). */
    public static function clock(int $ms): string
    {
        return gmdate('H:i:s', intdiv($ms, 1000));
    }

    /** ISO-8601 UTC to the millisecond: 2026-09-21T18:41:02.118Z. */
    public static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).sprintf('.%03dZ', $ms % 1000);
    }

    /**
     * `{k=v k2="v with space"}`, or '' for no data. Keys in the given order, at most 40, cleaned;
     * a value containing a space, `=`, `{`, `}` or `"` is double-quoted with `"` written `\"`; the
     * inside is capped at 1,000 characters.
     *
     * @param array<array-key, mixed> $data
     */
    public static function dataBlock(array $data): string
    {
        try {
            $pairs = [];
            foreach ($data as $key => $value) {
                if (count($pairs) >= self::MAX_KEYS) {
                    break;
                }
                $k = Redactor::cleanKey((string) $key);
                if ('' === $k) {
                    continue;
                }
                $v = self::field(self::scalar($value));
                if (1 === preg_match(self::NEEDS_QUOTES, $v)) {
                    $v = '"'.str_replace('"', '\"', $v).'"';
                }
                $pairs[] = $k.'='.$v;
            }
            if ([] === $pairs) {
                return '';
            }

            return '{'.self::capMiddle(implode(' ', $pairs), self::DATA_CAP).'}';
        } catch (Throwable) {
            return '';
        }
    }

    /** Every control character (and U+2028/U+2029) becomes a space; invalid UTF-8 is scrubbed first. */
    public static function stripControlChars(string $value): string
    {
        $clean = mb_scrub($value, 'UTF-8');
        $out   = preg_replace(self::CONTROL, ' ', $clean);

        return is_string($out) ? $out : (string) preg_replace('/[\x00-\x1f\x7f]/', ' ', $clean);
    }

    /** Cap at $max characters by cutting out the middle, so the start and the end both survive. */
    public static function capMiddle(string $value, int $max): string
    {
        $length = mb_strlen($value, 'UTF-8');
        if ($length <= $max) {
            return $value;
        }
        $keep = max(0, $max - mb_strlen(self::MARKER, 'UTF-8'));
        $head = (int) ceil($keep / 2);
        $tail = $keep - $head;

        return mb_substr($value, 0, $head, 'UTF-8').self::MARKER.($tail > 0 ? mb_substr($value, -$tail, null, 'UTF-8') : '');
    }

    private static function headerWith(Record $record, string $error, string $cause): string
    {
        $out = sprintf('[%s] [%s] [%s]', self::field($record->ts), $record->level->value, self::field('' === $record->app ? '?' : $record->app));
        if ('' !== $record->where) {
            $out .= ' ['.self::field($record->where).']';
        }
        if ('' !== $record->doing) {
            $out .= ' '.self::field($record->doing);
        }
        if ('' !== $error) {
            $out .= self::SEP.self::field($error);
        }
        $block = self::dataBlock($record->data);
        if ('' !== $block) {
            $out .= ' '.$block;
        }

        return $out.self::field($cause);
    }

    /** Middle-cut $text so it loses at least $overBytes bytes, never below $floor characters. */
    private static function shrink(string $text, int $overBytes, int $floor): string
    {
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= $floor) {
            return $text;
        }
        $target = max($floor, $length - $overBytes - mb_strlen(self::MARKER, 'UTF-8'));

        return $target <= mb_strlen(self::MARKER, 'UTF-8') ? '' : self::capMiddle($text, $target);
    }

    private static function field(string $value): string
    {
        return self::stripControlChars($value);
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            null === $value  => 'null',
            is_bool($value)  => $value ? 'true' : 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => sprintf('[%s not allowed]', is_array($value) ? 'array' : (is_object($value) ? 'object' : gettype($value))),
        };
    }
}
