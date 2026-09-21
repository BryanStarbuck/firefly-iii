<?php

/*
 * Redactor.php
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
 * Privacy — pm/error_err.mdx §12 (LOCKED). The library enforces it, not the call site (R12).
 *
 *  - data keys: SECRET_KEY → `[redacted]`; LEDGER_KEY → `[ledger-field refused]`, even for null;
 *  - text values (the error, every cause, every data value) pass SCRUBS in order, after the
 *    QueryException ` (Connection: ` cut and before the repo base path is removed;
 *  - `where` and `doing` are NOT scrubbed: they are literals or templates (rule 6).
 *
 * The regexes are mirrored in errorfile/src/redact.ts; errorfile/test/parity.test.ts extracts the
 * strings below and asserts they equal the TypeScript ones. Keep each one a single-quoted
 * `/…/flags` literal.
 *
 * Every method is total.
 */
final class Redactor
{
    public const string SECRET_KEY = '/pass(word)?|secret|token|auth|cookie|session|key|signature|credential|bearer|csrf|xsrf/i';
    public const string LEDGER_KEY = '/amount|balance|payee|notes?|memo|account_?name|category_?name|description|imported_payee|statement|iban|bic|account_?number|source_?name|destination_?name|opening_balance|virtual_balance|foreign_amount|internal_reference|sepa_/i';

    /**
     * Scrub 1 (§12.4.1): a QueryException message is cut at ` (Connection: `, which drops the SQL
     * text. The cut stops before a trailing ` (code=…)` suffix and before the next ` | cause: `, so
     * it can run on a whole headline or cause chain.
     */
    public const string SQL_CUT = '/ \(Connection: .*?(?=(?: \(code=[^()]*\))?(?: \| cause: |$))/s';

    /**
     * Scrubs 2–8 (§12.4), in order. Each entry is [pattern, replacement].
     *
     * @var list<array{0: string, 1: string}>
     */
    public const array SCRUBS = [
        ['/([?&](?:token|code|state|key|password|secret|signature|sig|api_key|access_token)=)[^&#\s"\'<>]*/i', '$1[redacted]'],
        ['/[0-9a-fA-F]{32,}/', '[REDACTED-KEY]'],
        ['/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,4})?\b/', '[iban]'],
        ['/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/', '[email]'],
        ['/(?<![\w.])-?\d{1,15}\.\d{2,12}(?![\w.])/', '[amount]'],
        ['/(?<!\d)\d{9,17}(?!\d)/', '[number]'],
        ['/\/(?:Users|home)\/[^\/\s:()\'"]+/', '~'],
    ];

    public const string REDACTED       = '[redacted]';
    public const string LEDGER_REFUSED = '[ledger-field refused]';
    public const string WITHHELD       = '[withheld]';
    public const string PAYLOAD        = '[structured payload withheld]';

    /** Per-value cap inside the data block (§3.2); the whole block is capped again when formatted. */
    public const int VALUE_CAP = 300;
    public const int KEY_CAP   = 60;
    public const int MAX_KEYS  = 40;

    /** A `"key":` pair, for the payload guard. */
    private const string JSON_PAIR = '/"[^"\\\\\n]{1,100}"\s*:/';

    /** A payload-shaped key: always withheld. */
    private const string JSON_PAYLOAD_KEY = '/"(?:post|json|url|headers|body)"\s*:/i';

    /** Scrub one text value (§12.4). */
    public static function text(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        try {
            $out = str_contains($text, ' (Connection: ') ? (string) preg_replace(self::SQL_CUT, '', $text) : $text;
            $n   = count(self::SCRUBS);
            for ($i = 0; $i < $n; ++$i) {
                if (6 === $i) {
                    // rule 8 part 2 first: the repo's absolute base path → nothing, before the
                    // home-directory rule would turn it into `~/…`.
                    $out = str_replace(self::repoBase().'/', '', $out);
                }
                $out = (string) preg_replace(self::SCRUBS[$i][0], self::SCRUBS[$i][1], $out);
            }

            return $out;
        } catch (Throwable) {
            return '[unscrubbable text]';
        }
    }

    /**
     * Redact a whole data block (§12.3). Accepts anything, because the ingest route re-applies it to
     * data from a browser and trusts none of it.
     *
     * @return array<string, string>
     */
    public static function data(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }

        try {
            $out   = [];
            $count = 0;
            foreach ($data as $rawKey => $value) {
                if ($count >= self::MAX_KEYS) {
                    break;
                }
                $key = self::cleanKey((string) $rawKey);
                if ('' === $key) {
                    continue;
                }
                $out[$key] = self::value($key, $value);
                ++$count;
            }

            return $out;
        } catch (Throwable) {
            return ['data' => '[unreadable data]'];
        }
    }

    /** One data value under its (already cleaned) key. */
    public static function value(string $key, mixed $value): string
    {
        if (1 === preg_match(self::LEDGER_KEY, $key)) {
            return self::LEDGER_REFUSED;
        }
        if (1 === preg_match(self::SECRET_KEY, $key)) {
            return self::REDACTED;
        }
        if (null === $value) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return LineFormat::capMiddle(LineFormat::stripControlChars(self::text((string) $value)), self::VALUE_CAP);
        }

        return sprintf('[%s not allowed]', is_array($value) ? 'array' : (is_object($value) ? 'object' : gettype($value)));
    }

    /** `[\s={}] → _`, control characters stripped, cut to 60 characters (§3.2). */
    public static function cleanKey(string $key): string
    {
        $clean = (string) preg_replace('/[\s={}]/u', '_', LineFormat::stripControlChars($key));

        return mb_substr($clean, 0, self::KEY_CAP);
    }

    /**
     * Withhold-after (§4.6): for each prefix (ExpectedMessages::WITHHOLD_AFTER), everything after it
     * becomes `[withheld]`.
     *
     * @param list<string> $prefixes
     */
    public static function withhold(string $message, array $prefixes): string
    {
        try {
            foreach ($prefixes as $prefix) {
                $at = '' === $prefix ? false : strpos($message, $prefix);
                if (false !== $at) {
                    return substr($message, 0, $at + strlen($prefix)).self::WITHHELD;
                }
            }

            return $message;
        } catch (Throwable) {
            return self::WITHHELD;
        }
    }

    /**
     * PayloadGuard (§4.6): a bare message with three or more `"key":` pairs, or any
     * `"(post|json|url|headers|body)":`, becomes `[structured payload withheld]`.
     */
    public static function payloadGuard(string $message): string
    {
        try {
            if (1 === preg_match(self::JSON_PAYLOAD_KEY, $message) || preg_match_all(self::JSON_PAIR, $message) >= 3) {
                return self::PAYLOAD;
            }

            return $message;
        } catch (Throwable) {
            return self::PAYLOAD;
        }
    }

    /** The repo's absolute base path (app/Machine/ErrorFile → three levels up). */
    public static function repoBase(): string
    {
        static $base = null;

        return $base ??= dirname(__DIR__, 3);
    }
}
