<?php

/*
 * RecurringDetector.php
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
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Machine\Money;

/**
 * GET /analytics/recurring — recurring payments that are NOT yet a Firefly subscription
 * (apis.mdx §10.2, §10.3). A detector, not an oracle: every item carries its evidence.
 *
 * Method (bcmath on amounts, integer days on dates):
 *   1. Withdrawals in the range, grouped by payee (the expense account) and currency.
 *   2. Within a payee, amounts are banded: sorted ascending, a band holds every amount within
 *      AMOUNT_BAND (10%) of the band's smallest amount — a price rise opens a new band.
 *   3. A band with at least min_occurrences payments is tested for a cadence: the median gap in
 *      days must fall inside a known cadence's window widened by tolerance_days, and at least
 *      three quarters of the gaps must fall inside it too.
 *   4. A band any of whose payments is already linked to a subscription (bill) is skipped and
 *      counted — it is a subscription already.
 */
final class RecurringDetector
{
    /** A band holds amounts up to this factor of its smallest amount. */
    public const string AMOUNT_BAND = '1.10';

    /** cadence => [lowest gap, highest gap, occurrences per year, nominal step] */
    public const array CADENCES = [
        'weekly'    => [7, 7, '52', '7 days'],
        'biweekly'  => [14, 14, '26', '14 days'],
        'monthly'   => [28, 31, '12', '1 month'],
        'quarterly' => [89, 92, '4', '3 months'],
        'half-year' => [181, 184, '2', '6 months'],
        'yearly'    => [365, 366, '1', '1 year'],
    ];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function detect(Scope $scope, int $minOccurrences, int $toleranceDays): array
    {
        $journals = $this->ledger->journals($scope, [TransactionTypeEnum::WITHDRAWAL->value], false, true);
        $groups   = [];
        foreach ($journals as $journal) {
            $key            = sprintf('%d|%s', (int) $journal['destination_account_id'], (string) $journal['currency_code']);
            $groups[$key][] = $journal;
        }
        ksort($groups);
        $items        = [];
        $alreadyBills = 0;
        $tested       = 0;
        foreach ($groups as $group) {
            foreach ($this->bands($group) as $band) {
                if (count($band) < $minOccurrences) {
                    continue;
                }
                ++$tested;
                $linked = array_filter($band, static fn (array $j): bool => null !== ($j['bill_id'] ?? null));
                if ([] !== $linked) {
                    ++$alreadyBills;

                    continue;
                }
                $item = $this->test($band, $minOccurrences, $toleranceDays);
                if (null !== $item) {
                    $items[] = $item;
                }
            }
        }
        usort($items, static function (array $a, array $b): int {
            if (0 !== ($c = strcmp($a['currency_code'], $b['currency_code']))) {
                return $c;
            }
            if (0 !== ($c = Money::compare($b['_annual'], $a['_annual']))) {
                return $c;
            }

            return [$a['payee_account_id'], $a['first_seen']] <=> [$b['payee_account_id'], $b['first_seen']];
        });
        foreach ($items as &$item) {
            unset($item['_annual']);
        }
        unset($item);

        return [
            'detector'   => true,
            'items'      => $items,
            'summary'    => [
                'payment_groups_tested'      => $tested,
                'skipped_already_subscribed' => $alreadyBills,
                'detected'                   => count($items),
            ],
            'notes'      => [
                'a detector, not an oracle: each item carries its evidence (the payments and the gaps between them) — check it before creating a subscription',
                sprintf('amounts are banded within %s× of the band\'s smallest amount; a cadence needs the median gap and ≥ 75%% of the gaps inside its window ± %d day(s)', self::AMOUNT_BAND, $toleranceDays),
                'payments already linked to a subscription are skipped (they are one already)',
            ],
            'excluded'   => ['transfers', 'deposits', 'opening balances', 'reconciliations'],
            'provenance' => $scope->provenance(['min_occurrences' => $minOccurrences, 'tolerance_days' => $toleranceDays, 'amount_band' => self::AMOUNT_BAND]),
        ];
    }

    /**
     * @param list<array<string, mixed>> $journals one payee, one currency
     *
     * @return list<list<array<string, mixed>>>
     */
    private function bands(array $journals): array
    {
        usort($journals, static fn (array $a, array $b): int => 0 !== ($c = Money::compare(Ledger::amount($a), Ledger::amount($b))) ? $c : [Ledger::date($a), $a['transaction_journal_id']] <=> [Ledger::date($b), $b['transaction_journal_id']]);
        $bands   = [];
        $current = [];
        $ceiling = null;
        foreach ($journals as $journal) {
            $amount = Ledger::amount($journal);
            if (null !== $ceiling && Money::compare($amount, $ceiling) <= 0) {
                $current[] = $journal;

                continue;
            }
            if ([] !== $current) {
                $bands[] = $current;
            }
            $current = [$journal];
            $ceiling = Money::mul($amount, self::AMOUNT_BAND);
        }
        if ([] !== $current) {
            $bands[] = $current;
        }

        return $bands;
    }

    /**
     * @param list<array<string, mixed>> $band
     *
     * @return null|array<string, mixed>
     */
    private function test(array $band, int $minOccurrences, int $tolerance): ?array
    {
        usort($band, static fn (array $a, array $b): int => [Ledger::date($a), $a['transaction_journal_id']] <=> [Ledger::date($b), $b['transaction_journal_id']]);
        $gaps = [];
        for ($i = 1, $n = count($band); $i < $n; ++$i) {
            $gaps[] = (int) Carbon::parse(Ledger::date($band[$i - 1]))->diffInDays(Carbon::parse(Ledger::date($band[$i])), true);
        }
        $median = Stats::medianInt($gaps);
        if (null === $median) {
            return null;
        }
        foreach (self::CADENCES as $cadence => [$low, $high, $perYear, $step]) {
            if ($median < $low - $tolerance || $median > $high + $tolerance) {
                continue;
            }
            $matched = count(array_filter($gaps, static fn (int $g): bool => $g >= $low - $tolerance && $g <= $high + $tolerance));
            if ($matched * 4 < count($gaps) * 3 || $matched + 1 < $minOccurrences) {
                return null;
            }
            $amounts  = array_map(static fn (array $j): string => Ledger::amount($j), $band);
            $code     = (string) $band[0]['currency_code'];
            $typical  = (string) Stats::median($amounts);
            $annual   = Money::mul($typical, $perYear);
            $last     = end($band);
            $lastDate = Ledger::date($last);

            return [
                'name'                 => (string) $band[0]['destination_account_name'],
                'payee_account_id'     => (int) $band[0]['destination_account_id'],
                'currency_code'        => $code,
                'cadence'              => $cadence,
                'occurrences'          => count($band),
                'typical_amount'       => $this->ledger->fmt($typical, $code),
                'amount_min'           => $this->ledger->fmt(Stats::min($amounts), $code),
                'amount_max'           => $this->ledger->fmt(Stats::max($amounts), $code),
                'annualised'           => $this->ledger->fmt($annual, $code),
                'first_seen'           => Ledger::date($band[0]),
                'last_seen'            => $lastDate,
                'next_expected'        => Carbon::parse($lastDate)->add($step)->format('Y-m-d'),
                'median_interval_days' => $median,
                'intervals_days'       => $gaps,
                'matched_intervals'    => $matched,
                'confidence'           => $matched === count($gaps) && count($band) > $minOccurrences ? 'high' : 'medium',
                'evidence'             => array_map(fn (array $j): array => [
                    'date'        => Ledger::date($j),
                    'amount'      => $this->ledger->fmt(Ledger::amount($j), $code),
                    'description' => (string) $j['description'],
                    'journal_id'  => (int) $j['transaction_journal_id'],
                    'group_id'    => (int) $j['transaction_group_id'],
                    'category'    => $j['category_name'] ?? null,
                ], $band),
                '_annual'              => $annual,
            ];
        }

        return null;
    }
}
