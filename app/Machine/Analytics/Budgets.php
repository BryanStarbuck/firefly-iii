<?php

/*
 * Budgets.php
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
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Limit vs spent vs left, per budget per budget period (apis.mdx §10.2 budget-performance,
 * §8.9 /reports/budget). The limits are Firefly's budget limits (BudgetLimitRepository);
 * spent is Firefly's withdrawals carrying the budget, in the limit's currency, inside the limit's
 * own period. A budget with no limit has `limit: null` — never "0" (R3).
 */
final class Budgets
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @return Collection<int, Budget> the scope's budgets, or every active budget */
    public function budgetsFor(Scope $scope): Collection
    {
        if ($scope->budgets->isNotEmpty()) {
            return $scope->budgets->values();
        }

        /** @var BudgetRepositoryInterface $repository */
        $repository = $this->ledger->repo(BudgetRepositoryInterface::class);

        return $repository->getActiveBudgets()->sortBy('id')->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function performance(Scope $scope): array
    {
        $budgets = $this->budgetsFor($scope);

        /** @var BudgetLimitRepositoryInterface $limitRepository */
        $limitRepository = $this->ledger->repo(BudgetLimitRepositoryInterface::class);
        $limits          = [];
        $first           = $scope->start->copy();
        $last            = $scope->end->copy();
        foreach ($budgets as $budget) {
            foreach ($limitRepository->getBudgetLimits($budget, $scope->start->copy()->startOfDay(), $scope->end->copy()->endOfDay()) as $limit) {
                /** @var BudgetLimit $limit */
                $limits[] = $limit;
                if ($limit->start_date->lt($first)) {
                    $first = $limit->start_date->copy();
                }
                if ($limit->end_date->gt($last)) {
                    $last = $limit->end_date->copy();
                }
            }
        }
        // a limit's spent is counted over the limit's own period, which can reach past the range
        $wide      = $scope->withRange($first->copy()->startOfDay(), $last->copy()->startOfDay());
        $wideScope = new Scope($wide->start, $wide->end, $scope->accounts, $scope->accountsExplicit, $scope->categories, $budgets, $scope->tags, $scope->currencyCode, false, $scope->interval);
        $journals  = $budgets->isEmpty() ? [] : $this->ledger->journals($wideScope, [TransactionTypeEnum::WITHDRAWAL->value]);
        foreach ($wideScope->currenciesFound() as $code) {
            $scope->noteCurrency($code);
        }
        $scope->countRows(count($journals));

        $byBudget = [];
        foreach ($journals as $journal) {
            if (null === $journal['budget_id']) {
                continue;
            }
            $byBudget[(int) $journal['budget_id']][] = $journal;
        }

        $rows    = [];
        $covered = []; // budget id → currency → list of [start, end] limit periods
        usort($limits, static fn (BudgetLimit $a, BudgetLimit $b): int => [$a->budget_id, $a->start_date->format('Y-m-d'), $a->id] <=> [$b->budget_id, $b->start_date->format('Y-m-d'), $b->id]);
        $names   = $budgets->keyBy('id');
        foreach ($limits as $limit) {
            $currency = $limit->transactionCurrency;
            $code     = (string) $currency->code;
            if (null !== $scope->currencyCode && $code !== $scope->currencyCode) {
                continue;
            }
            $this->ledger->rememberCurrency($currency);
            $scope->noteCurrency($code);
            $start                                          = $limit->start_date->format('Y-m-d');
            $end                                            = $limit->end_date->format('Y-m-d');
            $covered[(int) $limit->budget_id][$code][]      = [$start, $end];
            [$spent, $count]                                = $this->sum($byBudget[(int) $limit->budget_id] ?? [], $code, $start, $end);
            $left                                           = Money::sub(Money::strip((string) $limit->amount), $spent);
            $rows[]                                         = [
                'period'        => self::periodLabel($limit->start_date, $limit->end_date),
                'period_start'  => $start,
                'period_end'    => $end,
                'budget_id'     => (int) $limit->budget_id,
                'name'          => (string) ($names[$limit->budget_id]->name ?? ''),
                'budget_limit_id' => (int) $limit->id,
                'currency_code' => $code,
                'limit'         => $this->ledger->fmt(Money::strip((string) $limit->amount), $code),
                'spent'         => $this->ledger->fmt($spent, $code),
                'left'          => $this->ledger->fmt($left, $code),
                'variance'      => $this->ledger->fmt(Money::negate($left), $code),
                'over'          => Money::compare($left, '0') < 0,
                'count'         => $count,
            ];
        }

        // spending on a budget outside any of its limits (or on a budget with no limit at all)
        $unlimited = [];
        foreach ($budgets as $budget) {
            $spentByCode = [];
            foreach ($byBudget[(int) $budget->id] ?? [] as $journal) {
                $date = Ledger::date($journal);
                $code = (string) $journal['currency_code'];
                if ($date < $scope->startDate() || $date > $scope->endDate()) {
                    continue;
                }
                $inLimit = false;
                foreach ($covered[(int) $budget->id][$code] ?? [] as [$s, $e]) {
                    if ($date >= $s && $date <= $e) {
                        $inLimit = true;

                        break;
                    }
                }
                if ($inLimit) {
                    continue;
                }
                $spentByCode[$code] ??= ['spent' => '0', 'count' => 0];
                $spentByCode[$code]['spent'] = Money::add($spentByCode[$code]['spent'], Ledger::amount($journal));
                ++$spentByCode[$code]['count'];
            }
            ksort($spentByCode);
            foreach ($spentByCode as $code => $cell) {
                $rows[] = [
                    'period'          => sprintf('%s..%s', $scope->startDate(), $scope->endDate()),
                    'period_start'    => $scope->startDate(),
                    'period_end'      => $scope->endDate(),
                    'budget_id'       => (int) $budget->id,
                    'name'            => (string) $budget->name,
                    'budget_limit_id' => null,
                    'currency_code'   => $code,
                    'limit'           => null,
                    'spent'           => $this->ledger->fmt($cell['spent'], $code),
                    'left'            => null,
                    'variance'        => null,
                    'over'            => null,
                    'count'           => $cell['count'],
                ];
            }
            if (!array_key_exists((int) $budget->id, $covered) && [] === $spentByCode) {
                $unlimited[] = ['budget_id' => (int) $budget->id, 'name' => (string) $budget->name];
            }
        }

        $totals = [];
        foreach ($rows as $row) {
            $code = $row['currency_code'];
            $totals[$code] ??= ['currency_code' => $code, 'limit' => null, 'spent' => '0'];
            if (null !== $row['limit']) {
                $totals[$code]['limit'] = Money::add($totals[$code]['limit'] ?? '0', $row['limit']);
            }
            $totals[$code]['spent'] = Money::add($totals[$code]['spent'], $row['spent']);
        }
        ksort($totals);
        $totals = array_values(array_map(fn (array $t): array => [
            'currency_code' => $t['currency_code'],
            'limit'         => $this->ledger->fmt($t['limit'], $t['currency_code']),
            'spent'         => $this->ledger->fmt($t['spent'], $t['currency_code']),
        ], $totals));

        return [
            'rows'                      => $rows,
            'totals'                    => $totals,
            'budgets_without_activity'  => $unlimited,
            'notes'                     => [
                'one row per budget limit (Firefly\'s budget period) that overlaps the range; spent is counted over the limit\'s own period, in the limit\'s currency',
                'variance = spent − limit (positive means over budget); left = limit − spent',
                'a row with limit null is spending on a budget outside any limit — no limit was set, which is not a limit of zero',
            ],
            'excluded'                  => ['transfers', 'deposits', 'opening balances', 'reconciliations'],
            'provenance'                => $scope->provenance(['budgets' => array_values(array_map('intval', $budgets->pluck('id')->all()))]),
        ];
    }

    /**
     * @param list<array<string, mixed>> $journals
     *
     * @return array{0: string, 1: int}
     */
    private function sum(array $journals, string $code, string $start, string $end): array
    {
        $spent = '0';
        $count = 0;
        foreach ($journals as $journal) {
            $date = Ledger::date($journal);
            if ((string) $journal['currency_code'] !== $code || $date < $start || $date > $end) {
                continue;
            }
            $spent = Money::add($spent, Ledger::amount($journal));
            ++$count;
        }

        return [$spent, $count];
    }

    private static function periodLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($start->copy()->startOfMonth()) && $end->isSameDay($start->copy()->endOfMonth())) {
            return $start->format('Y-m');
        }

        return sprintf('%s..%s', $start->format('Y-m-d'), $end->format('Y-m-d'));
    }
}
