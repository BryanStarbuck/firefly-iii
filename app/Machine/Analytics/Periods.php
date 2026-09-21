<?php

/*
 * Periods.php
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

use Carbon\Carbon;
use FireflyIII\Machine\MachineException;

/**
 * Splits an inclusive date range into labelled intervals (apis.mdx §10.2 `interval`).
 *
 *   month    "2026-07"      calendar months, the first and last clipped to the range
 *   quarter  "2026-Q3"
 *   year     "2026"
 *   none     "2026-07-01..2026-09-30"  the whole range as one period
 *
 * Every period carries its own resolved start and end (YYYY-MM-DD, inclusive) so a clipped
 * first or last month says so instead of pretending to be a whole month.
 */
final class Periods
{
    public const array INTERVALS = ['month', 'quarter', 'year', 'none'];

    /** A hard ceiling on the number of periods one answer may carry (100 years of months). */
    public const int MAX_PERIODS = 1200;

    /**
     * @return list<array{label: string, start: string, end: string}>
     */
    public static function split(Carbon $start, Carbon $end, string $interval): array
    {
        $first = $start->copy()->startOfDay();
        $last  = $end->copy()->startOfDay();
        if ('none' === $interval) {
            return [['label' => sprintf('%s..%s', $first->format('Y-m-d'), $last->format('Y-m-d')), 'start' => $first->format('Y-m-d'), 'end' => $last->format('Y-m-d')]];
        }
        if (!in_array($interval, self::INTERVALS, true)) {
            throw MachineException::invalid(sprintf('Unknown interval "%s".', $interval), 'Use one of: month, quarter, year, none');
        }
        $periods = [];
        $cursor  = $first->copy();
        while ($cursor->lte($last)) {
            $periodStart = self::startOf($cursor, $interval);
            $periodEnd   = self::endOf($periodStart, $interval);
            $clipStart   = $periodStart->lt($first) ? $first : $periodStart;
            $clipEnd     = $periodEnd->gt($last) ? $last : $periodEnd;
            $periods[]   = ['label' => self::label($periodStart, $interval), 'start' => $clipStart->format('Y-m-d'), 'end' => $clipEnd->format('Y-m-d')];
            if (count($periods) > self::MAX_PERIODS) {
                throw MachineException::invalid(
                    sprintf('The range splits into more than %d %s periods.', self::MAX_PERIODS, $interval),
                    'Use a shorter range or a coarser interval (quarter, year, none)',
                );
            }
            $cursor      = $periodEnd->copy()->addDay();
        }

        return $periods;
    }

    /** The label of the period (of this interval) that contains $date. */
    public static function labelFor(string $date, string $interval, string $noneLabel = ''): string
    {
        if ('none' === $interval) {
            return $noneLabel;
        }
        $year  = substr($date, 0, 4);
        $month = substr($date, 5, 2);

        return match ($interval) {
            'month'   => sprintf('%s-%s', $year, $month),
            'quarter' => sprintf('%s-Q%d', $year, intdiv(((int) $month) - 1, 3) + 1),
            'year'    => $year,
            default   => $noneLabel,
        };
    }

    /**
     * Index a list of periods by label so a journal date can be assigned in O(1).
     *
     * @param list<array{label: string, start: string, end: string}> $periods
     *
     * @return array<string, array{label: string, start: string, end: string}>
     */
    public static function byLabel(array $periods): array
    {
        $out = [];
        foreach ($periods as $period) {
            $out[$period['label']] = $period;
        }

        return $out;
    }

    /** Number of whole calendar months a period of this interval stands for (none: null). */
    public static function monthsPer(string $interval): ?int
    {
        return match ($interval) {
            'month'   => 1,
            'quarter' => 3,
            'year'    => 12,
            default   => null,
        };
    }

    private static function startOf(Carbon $date, string $interval): Carbon
    {
        return match ($interval) {
            'month'   => $date->copy()->startOfMonth(),
            'quarter' => $date->copy()->startOfQuarter(),
            'year'    => $date->copy()->startOfYear(),
        };
    }

    private static function endOf(Carbon $periodStart, string $interval): Carbon
    {
        return match ($interval) {
            'month'   => $periodStart->copy()->endOfMonth()->startOfDay(),
            'quarter' => $periodStart->copy()->endOfQuarter()->startOfDay(),
            'year'    => $periodStart->copy()->endOfYear()->startOfDay(),
        };
    }

    private static function label(Carbon $periodStart, string $interval): string
    {
        return self::labelFor($periodStart->format('Y-m-d'), $interval);
    }
}
