<?php

/*
 * CsvStatementParser.php
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

use FireflyIII\Machine\Ingest\Csv;
use FireflyIII\Machine\Ingest\StatementRow;
use FireflyIII\Machine\Ingest\Values;
use FireflyIII\Machine\Money;

/**
 * A bank's CSV export. The header decides the columns (case-insensitive, first match wins):
 *
 *   date         date · posted date · posting date · transaction date · booking date · value date
 *   description  description · payee · name · merchant · details · narrative · memo · transaction
 *   amount       amount · value · transaction amount           (signed: negative is money out)
 *   or split     debit / withdrawal / money out  +  credit / deposit / money in   (both unsigned)
 *
 * A column it cannot find is reported, not guessed. Rows are signed from the account holder's
 * view; `$chargesPositive` flips a card export that lists purchases as positive numbers.
 */
final class CsvStatementParser
{
    private const array DATE   = ['date', 'posted date', 'posting date', 'post date', 'transaction date', 'trans date', 'booking date', 'value date'];
    private const array DESC   = ['description', 'payee', 'name', 'merchant', 'details', 'narrative', 'transaction', 'memo', 'reference'];
    private const array AMOUNT = ['amount', 'value', 'transaction amount', 'amount (usd)'];
    private const array DEBIT  = ['debit', 'debits', 'withdrawal', 'withdrawals', 'money out', 'paid out', 'debit amount'];
    private const array CREDIT = ['credit', 'credits', 'deposit', 'deposits', 'money in', 'paid in', 'credit amount'];
    private const array MEMO   = ['memo', 'notes', 'extended description'];

    /** True when the text looks like a CSV statement (a header naming a date and an amount). */
    public static function looksLikeCsv(string $text): bool
    {
        $records = Csv::decode(substr($text, 0, 4096));
        if ([] === $records) {
            return false;
        }
        $header  = array_map(static fn (string $h): string => strtolower(trim($h)), $records[0]['cells']);

        return null !== self::find($header, self::DATE) && (null !== self::find($header, self::AMOUNT) || null !== self::find($header, self::DEBIT));
    }

    /** @return array<string, mixed> see ParseResult */
    public static function parse(string $content, bool $chargesPositive = false): array
    {
        $result  = ParseResult::empty();
        $records = Csv::decode($content);
        if ([] === $records) {
            $result['warnings'][] = 'empty CSV';
            $result['unreadable'] = true;

            return $result;
        }
        $header  = array_map(static fn (string $h): string => strtolower(trim($h)), $records[0]['cells']);
        $cDate   = self::find($header, self::DATE);
        $cDesc   = self::find($header, self::DESC);
        $cAmount = self::find($header, self::AMOUNT);
        $cDebit  = self::find($header, self::DEBIT);
        $cCredit = self::find($header, self::CREDIT);
        $cMemo   = self::find($header, self::MEMO);
        if ($cMemo === $cDesc) {
            $cMemo = null;
        }
        $missing = [];
        if (null === $cDate) {
            $missing[] = 'date';
        }
        if (null === $cAmount && null === $cDebit && null === $cCredit) {
            $missing[] = 'amount';
        }
        if ([] !== $missing) {
            $result['warnings'][] = sprintf('CSV header has no %s column (found: %s)', implode(' or ', $missing), implode(', ', $header));
            $result['unreadable'] = true;

            return $result;
        }
        if (null === $cDesc) {
            $result['warnings'][] = 'CSV header has no description column — rows get "(no description)"';
        }
        // day-first dates when any row's first part is over 12 and none's second part is
        $dayFirst = false;
        foreach (array_slice($records, 1) as $r) {
            $d = trim((string) ($r['cells'][$cDate] ?? ''));
            if (1 === preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-]/', $d, $m) && (int) $m[1] > 12) {
                $dayFirst = true;

                break;
            }
        }

        foreach (array_slice($records, 1) as $r) {
            $cells = $r['cells'];
            $text  = null === $cDesc ? '' : trim((string) ($cells[$cDesc] ?? ''));
            if (null !== $cMemo && '' !== trim((string) ($cells[$cMemo] ?? ''))) {
                $text = trim($text.' '.trim((string) $cells[$cMemo]));
            }
            $date  = Values::date((string) ($cells[$cDate] ?? ''), null, $dayFirst);
            $value = null;
            if (null !== $cAmount && '' !== trim((string) ($cells[$cAmount] ?? ''))) {
                $value = Values::amount((string) $cells[$cAmount]);
                if (null !== $value && $chargesPositive) {
                    $value = Money::negate($value);
                }
            } else {
                $debit  = null === $cDebit ? '' : trim((string) ($cells[$cDebit] ?? ''));
                $credit = null === $cCredit ? '' : trim((string) ($cells[$cCredit] ?? ''));
                if ('' !== $debit && null !== ($d = Values::amount($debit)) && '0' !== $d) {
                    $value = '-'.ltrim($d, '-');
                } elseif ('' !== $credit && null !== ($c = Values::amount($credit))) {
                    $value = ltrim($c, '-');
                }
            }
            if (null === $date || null === $value) {
                $result['bad'][] = ['line' => $r['line'], 'text' => $text, 'reason' => null === $date ? 'unreadable date' : 'unreadable amount'];

                continue;
            }
            $result['rows'][] = new StatementRow($date, $value, $text, $r['line']);
        }

        return $result;
    }

    /**
     * @param list<string> $header
     * @param list<string> $names
     */
    private static function find(array $header, array $names): ?int
    {
        foreach ($names as $name) {
            $i = array_search($name, $header, true);
            if (false !== $i) {
                return (int) $i;
            }
        }

        return null;
    }
}
