<?php

/*
 * Values.php
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

use FireflyIII\Machine\Money;

/**
 * Amount and date parsing for statement text — strings in, strings out, never a float
 * (apis.mdx §14.1). An amount that cannot be read is NULL, and the caller names the row in
 * _conflicts.csv; nothing is ever rounded into existence (§11.8).
 */
final class Values
{
    /**
     * A signed canonical decimal string ("-1234.56"), or null. Accepts "$1,234.56", "(12.00)",
     * "12.00-", "12.00 CR" / "12.00 DR" (a DR suffix means −), "1.234,56".
     */
    public static function amount(string $raw): ?string
    {
        $s        = trim(str_replace(["\u{00A0}", '−'], [' ', '-'], $raw));
        if ('' === $s) {
            return null;
        }
        $negative = false;
        if (1 === preg_match('/^\((.*)\)$/', $s, $m)) {
            $negative = true;
            $s        = trim($m[1]);
        }
        if (1 === preg_match('/^(.*?)\s*(CR|DR)$/i', $s, $m)) {
            $s = trim($m[1]);
            if ('DR' === strtoupper($m[2])) {
                $negative = !$negative;
            }
        }
        if (str_ends_with($s, '-')) {
            $negative = !$negative;
            $s        = trim(substr($s, 0, -1));
        }
        if (str_starts_with($s, '-')) {
            $negative = !$negative;
            $s        = trim(substr($s, 1));
        } elseif (str_starts_with($s, '+')) {
            $s = trim(substr($s, 1));
        }
        $s        = trim(str_replace(['$', '£', '€', ' ', "'"], '', $s));
        if (str_starts_with($s, '-')) {
            $negative = !$negative;
            $s        = substr($s, 1);
        }
        if (1 === preg_match('/^\d{1,3}(?:\.\d{3})+,\d+$/', $s) || 1 === preg_match('/^\d+,\d{1,2}$/', $s)) {
            // European: 1.234,56 or 12,50
            $s = str_replace(['.', ','], ['', '.'], $s);
        } else {
            if (1 !== preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?$/', $s)) {
                return null;
            }
            $s = str_replace(',', '', $s);
        }
        $s        = (string) preg_replace('/^0+(?=\d)/', '', $s);
        if (!Money::isDecimal($s)) {
            return null;
        }
        $s        = Money::strip($s);
        if ('0' === $s) {
            return '0';
        }

        return $negative ? '-'.$s : $s;
    }

    /**
     * A date as Y-m-d, or null. Accepts 2026-10-03, 20261003, 10/03/2026, 10/03/26, 10-03-2026,
     * 03.10.2026, "Oct 3, 2026", "3 Oct 2026", "October 3 2026", and — with $defaultYear — 10/03
     * or "Oct 3". Slash dates are month-first unless the first part cannot be a month.
     */
    public static function date(string $raw, ?int $defaultYear = null, bool $dayFirst = false): ?string
    {
        $s = trim($raw);
        if (1 === preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[T ].*)?$/', $s, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (1 === preg_match('/^(\d{4})(\d{2})(\d{2})(?:\d{0,6})(?:\.\d+)?(?:\[.*\])?$/', $s, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (1 === preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{2,4})$/', $s, $m)) {
            return self::ymd(self::year($m[3]), (int) $m[2], (int) $m[1]);
        }
        if (1 === preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{2,4})$/', $s, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            if ($dayFirst || $a > 12) {
                [$a, $b] = [$b, $a];
            }

            return self::ymd(self::year($m[3]), $a, $b);
        }
        if (null !== $defaultYear && 1 === preg_match('/^(\d{1,2})[\/-](\d{1,2})$/', $s, $m)) {
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            if ($dayFirst || $a > 12) {
                [$a, $b] = [$b, $a];
            }

            return self::ymd($defaultYear, $a, $b);
        }
        $months = '(jan|feb|mar|apr|may|jun|jul|aug|sep|sept|oct|nov|dec)[a-z]*\.?';
        if (1 === preg_match('/^'.$months.'\s+(\d{1,2})(?:st|nd|rd|th)?,?\s*(\d{4})?$/i', $s, $m)) {
            $year = isset($m[3]) && '' !== $m[3] ? (int) $m[3] : $defaultYear;

            return null === $year ? null : self::ymd($year, self::month($m[1]), (int) $m[2]);
        }
        if (1 === preg_match('/^(\d{1,2})\s+'.$months.',?\s*(\d{4})?$/i', $s, $m)) {
            $year = isset($m[3]) && '' !== $m[3] ? (int) $m[3] : $defaultYear;

            return null === $year ? null : self::ymd($year, self::month($m[2]), (int) $m[1]);
        }

        return null;
    }

    /** "Oct" / "October" → 10 (0 when not a month name). */
    public static function month(string $monthName): int
    {
        return match (strtolower(substr($monthName, 0, 3))) {
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
            default => 0,
        };
    }

    /** @return list<string> every YYYY-MM from $start to $end inclusive (both Y-m-d or Y-m) */
    public static function months(string $start, string $end): array
    {
        $y   = (int) substr($start, 0, 4);
        $m   = (int) substr($start, 5, 2);
        $ey  = (int) substr($end, 0, 4);
        $em  = (int) substr($end, 5, 2);
        $out = [];
        $cap = 0;
        while (($y < $ey || ($y === $ey && $m <= $em)) && $cap < 1200) {
            $out[] = sprintf('%04d-%02d', $y, $m);
            ++$m;
            if ($m > 12) {
                $m = 1;
                ++$y;
            }
            ++$cap;
        }

        return $out;
    }

    public static function daysBetween(string $a, string $b): int
    {
        $ta = strtotime($a.' 00:00:00 UTC');
        $tb = strtotime($b.' 00:00:00 UTC');

        // whole days between two UTC midnights — integer arithmetic, never a float
        return false === $ta || false === $tb ? 0 : intdiv(abs($tb - $ta), 86400);
    }

    private static function ymd(int $y, int $m, int $d): ?string
    {
        if ($y < 1900 || $y > 2200 || !checkdate($m, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private static function year(string $y): int
    {
        $n = (int) $y;

        return strlen($y) <= 2 ? ($n >= 70 ? 1900 + $n : 2000 + $n) : $n;
    }
}
