<?php

/*
 * AnomalyDetector.php
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
use FireflyIII\Machine\Money;

/**
 * GET /analytics/anomalies — categories and payees unusually far from their own trailing norm
 * (apis.mdx §10.2, §10.3). A detector, not an oracle: every item carries the trailing values,
 * their mean and standard deviation, and the deviation (z) it was flagged on.
 *
 * The current window is [start, end]. The norm is the `trailing` windows immediately before it,
 * of the same shape: whole calendar months when the range is whole months, otherwise the same
 * number of days. A window with no spending counts as zero — for a norm of spending, "nothing
 * this month" is a real observation. z = (current − mean) ÷ population stddev, in bcmath.
 */
final class AnomalyDetector
{
    public const int DEFAULT_TRAILING = 6;

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function detect(Scope $scope, string $z, string $minAmount, int $trailing): array
    {
        $windows      = self::windows($scope->start, $scope->end, $trailing);
        $wide         = $scope->withRange(Carbon::parse($windows[0]['start']), $scope->end->copy());
        $journals     = $this->ledger->journals($wide, Ledger::outTypes($wide));
        $current      = count($windows) - 1;
        $cells        = []; // kind|id|currency => [name, values per window]
        foreach ($journals as $journal) {
            if (!Ledger::direction($wide, $journal)['out']) {
                continue;
            }
            $date  = Ledger::date($journal);
            $index = null;
            foreach ($windows as $i => $w) {
                if ($date >= $w['start'] && $date <= $w['end']) {
                    $index = $i;

                    break;
                }
            }
            if (null === $index) {
                continue;
            }
            $code   = (string) $journal['currency_code'];
            $amount = Ledger::amount($journal);
            $keys   = [
                ['category', $journal['category_id'] ?? null, null === ($journal['category_id'] ?? null) ? '(no category)' : (string) $journal['category_name']],
                ['payee', (int) $journal['destination_account_id'], (string) $journal['destination_account_name']],
            ];
            foreach ($keys as [$kind, $id, $name]) {
                $key                          = sprintf('%s|%s|%s', $kind, null === $id ? 'none' : (string) $id, $code);
                $cells[$key] ??= ['kind' => $kind, 'id' => null === $id ? null : (int) $id, 'name' => $name, 'currency_code' => $code, 'values' => array_fill(0, count($windows), '0')];
                $cells[$key]['values'][$index] = Money::add($cells[$key]['values'][$index], $amount);
            }
        }
        foreach ($wide->currenciesFound() as $code) {
            $scope->noteCurrency($code);
        }
        $scope->countRows(count($journals));

        $items       = [];
        $newSpending = [];
        $flat        = 0;
        ksort($cells);
        foreach ($cells as $cell) {
            $code     = $cell['currency_code'];
            $now      = $cell['values'][$current];
            $history  = array_slice($cell['values'], 0, $current);
            $mean     = (string) Stats::mean($history);
            $stddev   = (string) Stats::stddev($history);
            $nonZero  = array_filter($history, static fn (string $v): bool => !Money::isZero($v));
            if ([] === $nonZero) {
                if (!Money::isZero($now) && Money::compare($now, $minAmount) >= 0) {
                    $newSpending[] = ['kind' => $cell['kind'], 'id' => $cell['id'], 'name' => $cell['name'], 'currency_code' => $code, 'amount' => $this->ledger->fmt($now, $code)];
                }

                continue;
            }
            $size     = Money::compare($now, $mean) >= 0 ? $now : $mean;
            $flatNorm = Money::isZero($stddev);
            if ($flatNorm && 0 === Money::compare($now, $mean)) {
                ++$flat; // the norm never varied and this window matches it: nothing to flag

                continue;
            }
            // a norm that never varied (rent, a subscription: 30, 30, 30…) has no spread to
            // measure a deviation in, so z is undefined — but ANY change from it is a deviation
            // (a missed payment, a doubled bill), and the most certain kind: flagged, with
            // deviation null and the reason beside it, never skipped as "no variation"
            $score    = $flatNorm ? null : (string) Stats::zScore($now, $mean, $stddev);
            if (Money::compare($size, $minAmount) < 0 || (null !== $score && Money::compare(Money::abs($score), $z) < 0)) {
                continue;
            }
            $items[]  = [
                'kind'            => $cell['kind'],
                'id'              => $cell['id'],
                'name'            => $cell['name'],
                'currency_code'   => $code,
                'amount'          => $this->ledger->fmt($now, $code),
                'trailing_mean'   => $this->ledger->fmt($mean, $code),
                'trailing_stddev' => $this->ledger->fmt($stddev, $code),
                'deviation'       => null === $score ? null : Money::format($score, 2),
                'direction'       => Money::compare($now, $mean) > 0 ? 'above' : 'below',
                'difference'      => $this->ledger->fmt(Money::sub($now, $mean), $code),
                'reason'          => null === $score
                    ? 'the trailing norm never varied (stddev 0), so z is undefined — any change from it is a deviation'
                    : sprintf('|z| ≥ %s', $z),
                'trailing_values' => array_map(fn (array $w, string $v): array => ['start' => $w['start'], 'end' => $w['end'], 'amount' => $this->ledger->fmt($v, $code)], array_slice($windows, 0, $current), $history),
                '_abs'            => null === $score ? null : Money::abs($score),
            ];
        }
        // the flat-norm deviations first (the most certain), then by |z| descending, then by name
        usort($items, static function (array $a, array $b): int {
            if (null === $a['_abs'] || null === $b['_abs']) {
                $c = (null === $b['_abs']) <=> (null === $a['_abs']);
            } else {
                $c = Money::compare($b['_abs'], $a['_abs']);
            }

            return 0 !== $c ? $c : strcmp($a['kind'].$a['name'], $b['kind'].$b['name']);
        });
        foreach ($items as &$item) {
            unset($item['_abs']);
        }
        unset($item);

        return [
            'detector'       => true,
            'items'          => $items,
            'new_spending'   => $newSpending,
            'current_window' => ['start' => $windows[$current]['start'], 'end' => $windows[$current]['end']],
            'trailing'       => array_map(static fn (array $w): array => ['start' => $w['start'], 'end' => $w['end']], array_slice($windows, 0, $current)),
            'summary'        => ['flagged' => count($items), 'new' => count($newSpending), 'skipped_no_variation' => $flat],
            'notes'          => [
                'a detector, not an oracle: each item carries its trailing values, mean, standard deviation and z (deviation) — show the work before acting on it',
                sprintf('flagged when |z| ≥ %s and the larger of the current amount and the norm is at least %s', $z, $minAmount),
                'a norm that never varied (stddev 0) has no z: any change from it is flagged with deviation null and the reason; one that matches it is counted under skipped_no_variation',
                'new_spending lists categories and payees with no spending in any trailing window (no norm to compare with)',
            ],
            'excluded'       => $scope->excluded(),
            'provenance'     => $scope->provenance(['z' => $z, 'min_amount' => $minAmount, 'trailing_windows' => $trailing, 'window_shape' => $windows[$current]['shape']]),
        ];
    }

    /**
     * The trailing windows followed by the current one (last).
     *
     * @return list<array{start: string, end: string, shape: string}>
     */
    public static function windows(Carbon $start, Carbon $end, int $trailing): array
    {
        $s          = $start->copy()->startOfDay();
        $e          = $end->copy()->startOfDay();
        $monthAlign = $s->day === 1 && $e->isSameDay($e->copy()->endOfMonth());
        $windows    = [];
        if ($monthAlign) {
            $months = (($e->year - $s->year) * 12) + ($e->month - $s->month) + 1;
            for ($i = $trailing; $i >= 1; --$i) {
                $ws        = $s->copy()->subMonthsNoOverflow($months * $i);
                $we        = $ws->copy()->addMonthsNoOverflow($months)->subDay();
                $windows[] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d'), 'shape' => sprintf('%d calendar month(s)', $months)];
            }
            $windows[] = ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d'), 'shape' => sprintf('%d calendar month(s)', $months)];

            return $windows;
        }
        $days = (int) $s->diffInDays($e, true) + 1;
        for ($i = $trailing; $i >= 1; --$i) {
            $ws        = $s->copy()->subDays($days * $i);
            $windows[] = ['start' => $ws->format('Y-m-d'), 'end' => $ws->copy()->addDays($days - 1)->format('Y-m-d'), 'shape' => sprintf('%d day(s)', $days)];
        }
        $windows[] = ['start' => $s->format('Y-m-d'), 'end' => $e->format('Y-m-d'), 'shape' => sprintf('%d day(s)', $days)];

        return $windows;
    }
}
