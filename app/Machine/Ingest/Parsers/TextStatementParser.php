<?php

/*
 * TextStatementParser.php
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

namespace FireflyIII\Machine\Ingest\Parsers;

use FireflyIII\Machine\Ingest\StatementRow;
use FireflyIII\Machine\Ingest\Values;
use FireflyIII\Machine\Money;

/**
 * A statement's text — the `_claude.txt` / `_brew.txt` / `_ocr.txt` sidecars, or the PDF's own
 * text (raw mode, cli.mdx §10.3). A sidecar that is really a CSV (a header naming a date and an
 * amount) is handed to the CSV parser.
 *
 * Header facts are read from INSIDE the document (layer one groups on them, never on the
 * directory name):
 *
 *   Statement period: 2026-09-15 to 2026-10-14       (also "Period", "for", "through", "-", any date format)
 *   Account ending in 4021 · Account number ****4021 · Card x4021 · ••4021
 *   Institution: Northbank · Bank: Northbank
 *   Account holder: household · Entity: household
 *   Currency: USD
 *   Sign convention: charges-positive | charges-negative
 *
 * Transaction lines: a date first, the description, then an amount, optionally followed by a
 * running balance (the LAST amount is then the balance and is ignored):
 *
 *   2026-10-03  CORNER CAFE #12            -5.00
 *   10/03       CORNER CAFE                 5.00      1,234.56
 *   Oct 3       PAYMENT THANK YOU        (500.00)
 *
 * Signs: on an asset account (checking, savings, brokerage) a negative / (…) / DR amount is money
 * out. On a card or loan statement charges are printed POSITIVE, so the sign is flipped — unless
 * the document says `Sign convention: charges-negative`. Lines that look like a transaction but
 * cannot be read are returned in `bad`, never dropped.
 */
final class TextStatementParser
{
    /** An amount WITH cents: "5.00", "-5.00", "$1,234.56", "(500.00)", "12.00 CR", "1.234,56". */
    private const string AMOUNT = '(?:\\(\\s*-?[$£€]?\\s*\\d[\\d,.\']*[.,]\\d{2}\\s*\\)|[-−+]?\\s?[$£€]?\\s?\\d[\\d,.\']*[.,]\\d{2}(?:\\s?-|\\s?(?:CR|DR|Cr|Dr|cr|dr))?)';

    private const string SKIP = '/^(?:beginning|opening|ending|closing|previous|new|starting|available|statement)\s+balance\b|^balance\s+(?:forward|brought)|^total\b|^subtotal\b|^page\s+\d+/i';

    /**
     * @param bool $chargesPositive the account kind's default (card/loan/mortgage: true)
     *
     * @return array<string, mixed> see ParseResult
     */
    public static function parse(string $content, bool $chargesPositive = false): array
    {
        if (CsvStatementParser::looksLikeCsv($content)) {
            return CsvStatementParser::parse($content, $chargesPositive);
        }
        $result = ParseResult::empty();
        $lines  = explode("\n", str_replace(["\r\n", "\r"], "\n", $content));

        // ---- header facts
        foreach ($lines as $raw) {
            $line = trim($raw);
            if (null === $result['period_start'] && 1 === preg_match('/\b(?:statement\s+)?(?:period|dates?|cycle)\b[^:\d]*:?\s*(.+?)\s+(?:to|through|thru|until|-|–|—)\s+(.+)$/i', $line, $m)) {
                $a = Values::date(self::dateToken($m[1]));
                $b = Values::date(self::dateToken($m[2]));
                if (null !== $a && null !== $b) {
                    $result['period_start'] = min($a, $b);
                    $result['period_end']   = max($a, $b);
                }
            }
            if (null === $result['last4'] && 1 === preg_match('/\b(?:account|acct|card)\b[^\n]{0,40}?(?:ending(?:\s+in)?|number|no\.?|#|x|\*{2,}|•+)\s*[:#]?\s*[x*•]*\s*(\d{4})\b/i', $line, $m)) {
                $result['last4'] = $m[1];
            }
            if (null === $result['last4'] && 1 === preg_match('/(?:••|\*{4}|\bx)(\d{4})\b/i', $line, $m) && 1 === preg_match('/\b(?:account|acct|card)\b/i', $line)) {
                $result['last4'] = $m[1];
            }
            if (null === $result['institution'] && 1 === preg_match('/^(?:institution|bank)\s*:\s*(.+)$/i', $line, $m)) {
                $result['institution'] = trim($m[1]);
            }
            if (null === $result['entity'] && 1 === preg_match('/^(?:entity|account\s+holder)\s*:\s*(.+)$/i', $line, $m)) {
                $result['entity'] = trim($m[1]);
            }
            if (null === $result['currency'] && 1 === preg_match('/^currency\s*:\s*([A-Z]{3})\b/i', $line, $m)) {
                $result['currency'] = strtoupper($m[1]);
            }
            if (1 === preg_match('/^sign\s+convention\s*:\s*charges[-\s]?(positive|negative)/i', $line, $m)) {
                $chargesPositive = 'positive' === strtolower($m[1]);
            }
        }
        $year   = null === $result['period_end'] ? null : (int) substr($result['period_end'], 0, 4);
        $sYear  = null === $result['period_start'] ? null : (int) substr($result['period_start'], 0, 4);
        $sMonth = null === $result['period_start'] ? null : (int) substr($result['period_start'], 5, 2);

        // ---- transaction lines
        $datePattern = '(\d{4}-\d{1,2}-\d{1,2}|\d{1,2}\/\d{1,2}(?:\/\d{2,4})?|\d{1,2}-\d{1,2}-\d{2,4}|(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?\s+\d{1,2}(?:,?\s+\d{4})?|\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?(?:\s+\d{4})?)';
        $amount      = self::AMOUNT;
        foreach ($lines as $index => $raw) {
            $line = trim(str_replace("\t", '  ', $raw));
            if ('' === $line || 1 !== preg_match('/^'.$datePattern.'\s+(.*)$/i', $line, $m)) {
                continue;
            }
            $dateToken = $m[1];
            $rest      = trim($m[2]);
            // a second date column (posting date) right after the first: skip it
            if (1 === preg_match('/^'.$datePattern.'\s+(.*)$/i', $rest, $m2)) {
                $rest = trim($m2[2]);
            }
            if (1 === preg_match(self::SKIP, $rest)) {
                continue;
            }
            // trailing amounts: amount + running balance, else one amount (with cents — a bare
            // integer at the end of a description is a store number, not money)
            if (1 !== preg_match('/^(.*)\s+('.$amount.')\s+('.$amount.')\s*$/u', $rest, $am)
                && 1 !== preg_match('/^(.*)\s+('.$amount.')\s*$/u', $rest, $am)) {
                if (1 === preg_match('/\d+[.,]\d{2}/', $rest)) {
                    $result['bad'][] = ['line' => $index + 1, 'text' => $rest, 'reason' => 'no readable amount'];
                }

                continue;
            }
            $text  = trim($am[1]);
            $value = Values::amount($am[2]);
            $date  = self::rowDate($dateToken, $year, $sYear, $sMonth);
            if ('' === $text || 1 === preg_match(self::SKIP, $text)) {
                continue;
            }
            if (null === $date || null === $value) {
                $result['bad'][] = ['line' => $index + 1, 'text' => $text, 'reason' => null === $date ? 'unreadable date' : 'unreadable amount'];

                continue;
            }
            if ($chargesPositive && '0' !== $value) {
                $value = Money::negate($value);
            }
            $result['rows'][] = new StatementRow($date, $value, $text, $index + 1);
        }
        if ([] === $result['rows'] && null === $result['period_start']) {
            $result['warnings'][] = 'no statement period and no transaction lines found';
            $result['unreadable'] = true;
        }

        return $result;
    }

    /** The date text at the start of a header value ("Sep 15, 2026 to …" → "Sep 15, 2026"). */
    private static function dateToken(string $text): string
    {
        return trim((string) preg_replace('/[^\w\/\-., ].*$/u', '', trim($text)), ' ,.');
    }

    /** A row date; a year-less date takes the period's year (the start year for the start's month and later, when the period crosses a year). */
    private static function rowDate(string $token, ?int $endYear, ?int $startYear, ?int $startMonth): ?string
    {
        $full = Values::date($token);
        if (null !== $full) {
            return $full;
        }
        if (null === $endYear) {
            return null;
        }
        $year = $endYear;
        if (null !== $startYear && $startYear !== $endYear && null !== $startMonth) {
            $probe = Values::date($token, $endYear);
            if (null !== $probe && (int) substr($probe, 5, 2) >= $startMonth) {
                $year = $startYear;
            }
        }

        return Values::date($token, $year);
    }
}
