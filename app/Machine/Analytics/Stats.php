<?php

/*
 * Stats.php
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

namespace FireflyIII\Machine\Analytics;

use FireflyIII\Machine\Money;

/**
 * Descriptive statistics over decimal strings, in bcmath (apis.mdx §10.3: no floats, ever).
 *
 * Every function returns null for an empty list — a mean of nothing is absent, not "0" (R3).
 */
final class Stats
{
    /** @param list<string> $values */
    public static function mean(array $values): ?string
    {
        if ([] === $values) {
            return null;
        }

        return Money::div(Money::sum($values), (string) count($values));
    }

    /** @param list<string> $values */
    public static function median(array $values): ?string
    {
        if ([] === $values) {
            return null;
        }
        $sorted = self::sorted($values);
        $n      = count($sorted);
        $mid    = intdiv($n, 2);
        if (1 === $n % 2) {
            return Money::strip($sorted[$mid]);
        }

        return Money::div(Money::add($sorted[$mid - 1], $sorted[$mid]), '2');
    }

    /**
     * Population standard deviation (the spread of the trailing window itself — the window is
     * the whole population being compared against, not a sample of a larger one).
     *
     * @param list<string> $values
     */
    public static function stddev(array $values): ?string
    {
        $mean = self::mean($values);
        if (null === $mean) {
            return null;
        }
        $squares = [];
        foreach ($values as $value) {
            $diff      = Money::sub($value, $mean);
            $squares[] = Money::mul($diff, $diff);
        }
        $variance = Money::div(Money::sum($squares), (string) count($values));
        if (null === $variance) {
            return null;
        }

        return Money::strip(bcsqrt($variance, Money::SCALE));
    }

    /** (value − mean) ÷ stddev; null when the spread is zero (no norm to deviate from). */
    public static function zScore(string $value, string $mean, string $stddev): ?string
    {
        return Money::div(Money::sub($value, $mean), $stddev);
    }

    /** @param list<string> $values */
    public static function min(array $values): ?string
    {
        return [] === $values ? null : Money::strip(self::sorted($values)[0]);
    }

    /** @param list<string> $values */
    public static function max(array $values): ?string
    {
        if ([] === $values) {
            return null;
        }
        $sorted = self::sorted($values);

        return Money::strip($sorted[count($sorted) - 1]);
    }

    /**
     * Median of integers (day intervals — dates, not money).
     *
     * @param list<int> $values
     */
    public static function medianInt(array $values): ?int
    {
        if ([] === $values) {
            return null;
        }
        sort($values);
        $n = count($values);

        return 1 === $n % 2 ? $values[intdiv($n, 2)] : intdiv($values[intdiv($n, 2) - 1] + $values[intdiv($n, 2)], 2);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        usort($values, static fn (string $a, string $b): int => Money::compare($a, $b));

        return array_values($values);
    }
}
