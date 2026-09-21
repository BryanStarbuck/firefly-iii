<?php

/*
 * Normaliser.php
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

namespace FireflyIII\Machine\Ingest;

/**
 * The description rule table — cli.mdx §10.5: "the bank's text with the per-bank noise stripped
 * (a rule table, not code per bank)". The unmodified text goes to internal_reference.
 *
 * CHANGING A RULE RE-KEYS EVERY FUTURE ff1 id (the description is inside the id and the hash).
 * That is a deliberate, reviewed change — the golden-vector test in tests/Machine/Ingest pins it.
 */
final class Normaliser
{
    /**
     * pattern => replacement. '*' applies to every institution; a lower-cased institution name
     * adds its own rules after the common ones.
     *
     * @var array<string, array<string, string>>
     */
    public const array RULES = [
        '*'         => [
            '/\b(?:POS|DEBIT CARD|CHECK CARD|DBT CRD|VISA|MC|DEBIT)\s+(?:PURCHASE|PURCH|DEBIT|PMT)\b/i' => ' ',
            '/\bPURCHASE AUTHORIZED ON \d{1,2}\/\d{1,2}\b/i'                                            => ' ',
            '/\b(?:RECURRING|RECUR)\s+(?:PAYMENT|PMT)\b/i'                                             => ' ',
            '/(?:\bX{2,}|\*{2,}|#|••|\bCARD\s+)\d{4}\b/i'                                              => ' ',
            '/\b(?:REF|TRACE|SEQ|CONF|AUTH)(?:ERENCE)?\s*(?:NO\.?|NUMBER|#|:)?\s*[A-Z0-9]*\d[A-Z0-9]{5,}\b/i' => ' ',
            '/\b\d{10,}\b/'                                                                            => ' ',
            '/\s+\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\s*$/'                                                   => ' ',
        ],
        // invented example institutions (fixtures only — no real bank is named in this repo)
        'northbank' => [
            '/^NB\s+/i' => '',
        ],
        'meridian'  => [
            '/^MERIDIAN\s+(?:ONLINE|MOBILE)\s+/i' => '',
        ],
    ];

    public static function description(string $raw, ?string $institution = null): string
    {
        $text  = self::squash($raw);
        $rules = self::RULES['*'];
        $inst  = strtolower(trim((string) $institution));
        if ('' !== $inst && isset(self::RULES[$inst])) {
            $rules += self::RULES[$inst];
        }
        $clean = $text;
        foreach ($rules as $pattern => $replacement) {
            $clean = (string) preg_replace($pattern, $replacement, $clean);
        }
        $clean = trim(self::squash($clean), " \t-–—:;,.|/");

        return '' === $clean ? ('' === $text ? '(no description)' : $text) : $clean;
    }

    /** The tie-group / id key of a normalised description: case-folded, single-spaced. */
    public static function tieKey(string $normalised): string
    {
        return mb_strtolower(self::squash($normalised));
    }

    /** sha1_8 — the first 8 hex chars of sha1 of the tie key (apis.mdx §11.5). */
    public static function sha1_8(string $normalised): string
    {
        return substr(sha1(self::tieKey($normalised)), 0, 8);
    }

    public static function squash(string $text): string
    {
        $text = str_replace(["\u{00A0}", "\t", "\r", "\n"], ' ', $text);
        $text = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $text);

        return trim((string) preg_replace('/\s{2,}/', ' ', $text));
    }
}
