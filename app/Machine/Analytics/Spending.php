<?php

/*
 * Spending.php
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

use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Category;

/**
 * "Where is my money going" — apis.mdx §10.2: spending by category / budget / tag per
 * interval (with the "none" bucket), the payee leaderboard, one category's trend, and the
 * uncategorised summary. Built on Firefly's GroupCollector (what the insight routes use);
 * grouped and added here with bcmath, per currency, never across currencies.
 */
final class Spending
{
    public const array BY = ['category', 'budget', 'tag'];

    private const array NONE_LABEL = ['category' => '(no category)', 'budget' => '(no budget)', 'tag' => '(no tag)'];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * Spent per category (budget, tag) per interval, per currency, plus the "none" bucket.
     *
     * @return array<string, mixed>
     */
    public function by(Scope $scope, string $by, ?int $topN = null, bool $inflow = false): array
    {
        $periods   = Periods::split($scope->start, $scope->end, $scope->interval);
        $byLabel   = Periods::byLabel($periods);
        $noneLabel = $periods[0]['label'];
        $types     = $inflow ? Ledger::inTypes($scope) : Ledger::outTypes($scope);
        $journals  = $this->ledger->journals($scope, $types, 'tag' === $by);
        $cells     = []; // [period][currency][key] => spent,count,name,id
        $bucketSum = []; // [currency][key] => total
        $totals    = []; // [currency] => spent,count  (per JOURNAL, so a multi-tag journal counts once)
        foreach ($journals as $journal) {
            $dir = Ledger::direction($scope, $journal);
            if (!($inflow ? $dir['in'] : $dir['out'])) {
                continue;
            }
            $code   = (string) $journal['currency_code'];
            $amount = Ledger::amount($journal);
            $label  = Periods::labelFor(Ledger::date($journal), $scope->interval, $noneLabel);
            if (!array_key_exists($label, $byLabel)) {
                continue;
            }
            $totals[$code] ??= ['spent' => '0', 'count' => 0];
            $totals[$code]['spent'] = Money::add($totals[$code]['spent'], $amount);
            ++$totals[$code]['count'];
            foreach ($this->keysOf($journal, $by) as [$id, $name]) {
                $key                                = null === $id ? 'none' : (string) $id;
                $cells[$label][$code][$key] ??= ['id' => $id, 'name' => $name, 'spent' => '0', 'count' => 0];
                $cells[$label][$code][$key]['spent'] = Money::add($cells[$label][$code][$key]['spent'], $amount);
                ++$cells[$label][$code][$key]['count'];
                $bucketSum[$code][$key]                 = Money::add($bucketSum[$code][$key] ?? '0', $amount);
            }
        }

        // top_n: keep the N largest buckets per currency (over the whole range); report the rest.
        $keep    = [];
        $omitted = [];
        foreach ($bucketSum as $code => $sums) {
            uksort($sums, static fn (string $a, string $b): int => 0 !== ($c = Money::compare($sums[$b], $sums[$a])) ? $c : strcmp($a, $b));
            $ranked      = array_keys($sums);
            $keep[$code] = array_flip(null === $topN ? $ranked : array_slice($ranked, 0, $topN));
            if (null !== $topN && count($ranked) > $topN) {
                $rest      = array_slice($ranked, $topN);
                $omitted[] = [
                    'currency_code' => $code,
                    'buckets'       => count($rest),
                    'spent'         => $this->ledger->fmt(Money::sum(array_map(static fn (string $k): string => $sums[$k], $rest)), $code),
                ];
            }
        }

        $rows    = [];
        $idKey   = sprintf('%s_id', $by);
        foreach ($periods as $period) {
            foreach ($cells[$period['label']] ?? [] as $code => $buckets) {
                $list = [];
                foreach ($buckets as $key => $cell) {
                    if (!array_key_exists((string) $key, $keep[$code] ?? [])) {
                        continue;
                    }
                    $list[] = [
                        'period'        => $period['label'],
                        'period_start'  => $period['start'],
                        'period_end'    => $period['end'],
                        'key'           => (string) $key,
                        $idKey          => $cell['id'],
                        'name'          => $cell['name'],
                        'currency_code' => $code,
                        'spent'         => $this->ledger->fmt($cell['spent'], $code),
                        'count'         => $cell['count'],
                        '_raw'          => $cell['spent'],
                    ];
                }
                usort($list, static fn (array $a, array $b): int => 0 !== ($c = Money::compare($b['_raw'], $a['_raw'])) ? $c : strcmp((string) $a['name'], (string) $b['name']));
                foreach ($list as $row) {
                    unset($row['_raw']);
                    $rows[] = $row;
                }
            }
        }
        ksort($totals);
        $totalRows = [];
        foreach ($totals as $code => $t) {
            $totalRows[] = ['currency_code' => $code, 'spent' => $this->ledger->fmt($t['spent'], $code), 'count' => $t['count']];
        }
        $notes   = [];
        if ('tag' === $by) {
            $notes[] = 'a transaction with several tags counts under each of them, so the rows can add up to more than the totals (which count each transaction once)';
        }
        if ($inflow) {
            $notes[] = 'amounts are money in (earned), not spent';
        }

        return [
            'by'         => $by,
            'interval'   => $scope->interval,
            'periods'    => $periods,
            'rows'       => $rows,
            'totals'     => $totalRows,
            'omitted'    => $omitted,
            'notes'      => $notes,
            'excluded'   => $scope->excluded(),
            'provenance' => $scope->provenance(['interval' => $scope->interval, 'top_n' => $topN]),
        ];
    }

    /**
     * Who got the money (direction out: expense accounts) or who paid it (in: revenue accounts).
     *
     * @return array<string, mixed>
     */
    public function payees(Scope $scope, int $topN, string $direction): array
    {
        $out      = 'out' === $direction;
        $journals = $this->ledger->journals($scope, $out ? Ledger::outTypes($scope) : Ledger::inTypes($scope));
        $groups   = [];
        foreach ($journals as $journal) {
            $dir = Ledger::direction($scope, $journal);
            if (!($out ? $dir['out'] : $dir['in'])) {
                continue;
            }
            $code      = (string) $journal['currency_code'];
            $accountId = (int) ($out ? $journal['destination_account_id'] : $journal['source_account_id']);
            $name      = (string) ($out ? $journal['destination_account_name'] : $journal['source_account_name']);
            $type      = (string) ($out ? $journal['destination_account_type'] : $journal['source_account_type']);
            $date      = Ledger::date($journal);
            $key       = sprintf('%d|%s', $accountId, $code);
            $groups[$key] ??= ['account_id' => $accountId, 'name' => $name, 'account_type' => $type, 'currency_code' => $code, 'amount' => '0', 'count' => 0, 'first_date' => $date, 'last_date' => $date];
            $groups[$key]['amount'] = Money::add($groups[$key]['amount'], Ledger::amount($journal));
            ++$groups[$key]['count'];
            $groups[$key]['first_date'] = min($groups[$key]['first_date'], $date);
            $groups[$key]['last_date']  = max($groups[$key]['last_date'], $date);
        }
        $byCurrency = [];
        foreach ($groups as $group) {
            $byCurrency[$group['currency_code']][] = $group;
        }
        ksort($byCurrency);
        $rows       = [];
        $cut        = [];
        foreach ($byCurrency as $code => $list) {
            usort($list, static fn (array $a, array $b): int => 0 !== ($c = Money::compare($b['amount'], $a['amount'])) ? $c : $a['account_id'] <=> $b['account_id']);
            $total = Money::sum(array_column($list, 'amount'));
            if (count($list) > $topN) {
                $rest  = array_slice($list, $topN);
                $cut[] = ['currency_code' => $code, 'payees' => count($rest), 'amount' => $this->ledger->fmt(Money::sum(array_column($rest, 'amount')), $code)];
            }
            foreach (array_slice($list, 0, $topN) as $i => $group) {
                $rows[] = array_merge($group, [
                    'rank'   => $i + 1,
                    'amount' => $this->ledger->fmt($group['amount'], $code),
                    'total'  => $this->ledger->fmt($total, $code),
                ]);
            }
        }

        return [
            'direction'  => $direction,
            'rows'       => $rows,
            'omitted'    => $cut,
            'notes'      => ['a share is amount ÷ total — both are given as strings; the presentation layer divides'],
            'excluded'   => $scope->excluded(),
            'provenance' => $scope->provenance(['direction' => $direction, 'top_n' => $topN]),
        ];
    }

    /**
     * One category over time, with mean and median per currency.
     *
     * @return array<string, mixed>
     */
    public function categoryTrend(Scope $scope, Category $category): array
    {
        $periods   = Periods::split($scope->start, $scope->end, $scope->interval);
        $noneLabel = $periods[0]['label'];
        $journals  = $this->ledger->journals($scope, Ledger::outTypes($scope));
        $cells     = [];
        foreach ($journals as $journal) {
            if (!Ledger::direction($scope, $journal)['out']) {
                continue;
            }
            $code                          = (string) $journal['currency_code'];
            $label                         = Periods::labelFor(Ledger::date($journal), $scope->interval, $noneLabel);
            $cells[$code][$label] ??= ['spent' => '0', 'count' => 0];
            $cells[$code][$label]['spent'] = Money::add($cells[$code][$label]['spent'], Ledger::amount($journal));
            ++$cells[$code][$label]['count'];
        }
        ksort($cells);
        $rows  = [];
        $stats = [];
        foreach ($cells as $code => $byPeriod) {
            $withData = [];
            $allZeros = [];
            foreach ($periods as $period) {
                $cell       = $byPeriod[$period['label']] ?? null;
                $rows[]     = [
                    'period'        => $period['label'],
                    'period_start'  => $period['start'],
                    'period_end'    => $period['end'],
                    'category_id'   => (int) $category->id,
                    'name'          => (string) $category->name,
                    'currency_code' => $code,
                    'spent'         => null === $cell ? null : $this->ledger->fmt($cell['spent'], $code),
                    'count'         => null === $cell ? 0 : $cell['count'],
                ];
                if (null !== $cell) {
                    $withData[] = $cell['spent'];
                }
                $allZeros[] = null === $cell ? '0' : $cell['spent'];
            }
            $stats[] = [
                'currency_code'       => $code,
                'mean'                => $this->ledger->fmt(Stats::mean($withData), $code),
                'median'              => $this->ledger->fmt(Stats::median($withData), $code),
                'min'                 => $this->ledger->fmt(Stats::min($withData), $code),
                'max'                 => $this->ledger->fmt(Stats::max($withData), $code),
                'total'               => $this->ledger->fmt(Money::sum($withData), $code),
                'periods_with_data'   => count($withData),
                'periods_total'       => count($periods),
                'mean_including_gaps' => $this->ledger->fmt(Stats::mean($allZeros), $code),
            ];
        }

        return [
            'category'   => ['id' => (int) $category->id, 'name' => (string) $category->name],
            'interval'   => $scope->interval,
            'rows'       => $rows,
            'stats'      => $stats,
            'notes'      => ['spent is null in a period with no transactions (a gap, not a zero); mean and median are over the periods with data, mean_including_gaps counts the gaps as zero'],
            'excluded'   => $scope->excluded(),
            'provenance' => $scope->provenance(['interval' => $scope->interval]),
        ];
    }

    /**
     * Count and total of uncategorised withdrawals, by account and by month.
     *
     * @return array<string, mixed>
     */
    public function uncategorized(Scope $scope): array
    {
        $journals  = $this->ledger->journals($scope, [TransactionTypeEnum::WITHDRAWAL->value]);
        $byMonth   = [];
        $byAccount = [];
        $totals    = [];
        foreach ($journals as $journal) {
            if (null !== $journal['category_id']) {
                continue;
            }
            $code      = (string) $journal['currency_code'];
            $amount    = Ledger::amount($journal);
            $month     = Periods::labelFor(Ledger::date($journal), 'month');
            $accountId = (int) $journal['source_account_id'];
            $name      = (string) $journal['source_account_name'];
            $mKey      = sprintf('%s|%d|%s', $month, $accountId, $code);
            $aKey      = sprintf('%d|%s', $accountId, $code);
            $byMonth[$mKey] ??= ['period' => $month, 'account_id' => $accountId, 'account_name' => $name, 'currency_code' => $code, 'count' => 0, 'amount' => '0'];
            $byAccount[$aKey] ??= ['account_id' => $accountId, 'account_name' => $name, 'currency_code' => $code, 'count' => 0, 'amount' => '0'];
            $totals[$code] ??= ['currency_code' => $code, 'count' => 0, 'amount' => '0'];
            foreach ([&$byMonth[$mKey], &$byAccount[$aKey], &$totals[$code]] as &$bucket) {
                ++$bucket['count'];
                $bucket['amount'] = Money::add($bucket['amount'], $amount);
            }
            unset($bucket);
        }
        ksort($byMonth);
        ksort($byAccount);
        ksort($totals);
        $fmt = function (array $list): array {
            return array_values(array_map(fn (array $r): array => array_merge($r, ['amount' => $this->ledger->fmt($r['amount'], $r['currency_code'])]), $list));
        };

        return [
            'rows'       => $fmt($byMonth),
            'by_account' => $fmt($byAccount),
            'totals'     => $fmt($totals),
            'notes'      => ['withdrawals only; list them with GET /transactions?without_category=true'],
            'excluded'   => ['transfers', 'deposits', 'opening balances', 'reconciliations'],
            'provenance' => $scope->provenance(),
        ];
    }

    /**
     * The (id, name) buckets a journal falls into for this grouping; the "none" bucket when it
     * has no category (budget, tag).
     *
     * @return list<array{0: null|int, 1: string}>
     */
    private function keysOf(array $journal, string $by): array
    {
        if ('tag' === $by) {
            $tags = (array) ($journal['tags'] ?? []);
            if ([] === $tags) {
                return [[null, self::NONE_LABEL['tag']]];
            }
            $out  = [];
            foreach ($tags as $tag) {
                $out[] = [(int) $tag['id'], (string) $tag['name']];
            }
            usort($out, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

            return $out;
        }
        $id = $journal[sprintf('%s_id', $by)] ?? null;
        if (null === $id) {
            return [[null, self::NONE_LABEL[$by]]];
        }

        return [[(int) $id, (string) $journal[sprintf('%s_name', $by)]]];
    }
}
