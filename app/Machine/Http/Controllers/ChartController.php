<?php

/*
 * ChartController.php
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

namespace FireflyIII\Machine\Http\Controllers;

use Carbon\Carbon;
use FireflyIII\Machine\Analytics\Budgets;
use FireflyIII\Machine\Analytics\Charts;
use FireflyIII\Machine\Analytics\Commitments;
use FireflyIII\Machine\Analytics\Flows;
use FireflyIII\Machine\Analytics\Ledger;
use FireflyIII\Machine\Analytics\ScopedController;
use FireflyIII\Machine\Analytics\Spending;
use FireflyIII\Machine\MachineException;
use FireflyIII\Models\Bill;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chart-ready series — apis.mdx §10.5. One route per chart the UI draws, plus /charts/series,
 * which turns a §10.2 analytics route into the same shape. Every chart is computed by the same
 * calculator as the analytics route it draws, so the picture and the table cannot disagree.
 *
 * Values are decimal strings and null is "no data" (a gap), never "0".
 */
final class ChartController extends ScopedController
{
    /** The analytics routes /charts/series can turn into series. */
    public const array SERIES_SOURCES = [
        'spending-by-category',
        'spending-by-budget',
        'spending-by-tag',
        'income-vs-expense',
        'cash-flow',
        'net-worth',
        'category-trend',
        'budget-performance',
        'payee-leaderboard',
        'uncategorized-summary',
    ];

    public function accountBalances(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok($this->charts()->accountBalances($this->scope($args, null, Ledger::ASSET_TYPES)));
    }

    public function netWorth(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok($this->charts()->fromNetWorth(new Flows($this->ledger())->netWorth($this->scope($args))));
    }

    public function budgetOverview(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok($this->categorical($this->charts()->fromBudgets(new Budgets($this->ledger())->performance($this->scope($args))), $args));
    }

    public function categoryOverview(Request $request): JsonResponse
    {
        return $this->overview($request, 'category', 'category-overview');
    }

    public function tagOverview(Request $request): JsonResponse
    {
        return $this->overview($request, 'tag', 'tag-overview');
    }

    public function spendingByCategory(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES, self::TOP_RULES), true);

        return $this->ok($this->charts()->fromSpending(new Spending($this->ledger())->by($this->scope($args), 'category', isset($args['top_n']) ? (int) $args['top_n'] : null), 'spending-by-category'));
    }

    public function incomeVsExpense(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok($this->charts()->fromIncomeVsExpense(new Flows($this->ledger())->incomeVsExpense($this->scope($args))));
    }

    public function piggyBank(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, array_merge(self::commonRules(false), self::INTERVAL_RULES), true);
        $group = $this->administration()->id;

        /** @var PiggyBank $piggy */
        $piggy = $this->resolve(PiggyBank::class, $id, 'name', static function (Builder $query) use ($group): void {
            $query->whereHas('accounts', static fn (Builder $a): Builder => $a->where('accounts.user_group_id', $group));
        });
        $history = new Commitments($this->ledger())->piggyHistory($piggy);
        if (isset($args['start']) || isset($args['end'])) {
            $from               = (string) ($args['start'] ?? '0000-01-01');
            $to                 = (string) ($args['end'] ?? '9999-12-31');
            $history['points']  = array_values(array_filter($history['points'], static fn (array $p): bool => $p['date'] >= $from && $p['date'] <= $to));
        }
        $chart   = $this->charts()->piggyBank($history);
        $chart['provenance']['range'] = isset($args['start']) || isset($args['end'])
            ? sprintf('%s..%s', $args['start'] ?? 'first event', $args['end'] ?? 'last event')
            : 'every event';

        return $this->ok($chart);
    }

    public function subscription(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, array_merge(self::commonRules(false), self::INTERVAL_RULES), true);

        /** @var Bill $bill */
        $bill  = $this->resolve(Bill::class, $id);
        $today = $this->today();

        return $this->ok($this->charts()->subscription($bill, $this->scope($args, [$today->copy()->startOfYear(), $today->copy()->endOfYear()])));
    }

    /**
     * Any §10.2 analytics route as chart series: source=/analytics/cash-flow&interval=month.
     * The source's own arguments are accepted (and validated) alongside `source`.
     */
    public function series(Request $request): JsonResponse
    {
        $raw    = $request->query('source', '');
        if (!is_string($raw)) {
            // source[]=… is an array: refused as input, never a PHP "Array to string" warning turned internal error
            throw MachineException::invalid('source must be one string.', sprintf('Send source=/analytics/<route>, one of: %s', implode(', ', self::SERIES_SOURCES)), ['field' => 'source']);
        }
        $source = (string) preg_replace('#^(/?machine/v1)?/?(analytics/)?#', '', trim($raw));
        if ('' === $raw) {
            throw MachineException::invalid('source is required.', sprintf('Send source=/analytics/<route>, one of: %s', implode(', ', self::SERIES_SOURCES)));
        }
        if (!in_array($source, self::SERIES_SOURCES, true)) {
            throw MachineException::invalid(
                sprintf('"%s" cannot be drawn as series.', $raw),
                sprintf('Use one of: %s (the other analytics routes are not time series — call them directly)', implode(', ', array_map(static fn (string $s): string => '/analytics/'.$s, self::SERIES_SOURCES))),
                ['supported' => array_map(static fn (string $s): string => '/analytics/'.$s, self::SERIES_SOURCES)],
            );
        }
        $base   = ['source' => ['required', 'string', 'max:200']];
        $charts = $this->charts();
        $ledger = $this->ledger();
        $chart  = 'series:'.$source;
        switch ($source) {
            case 'spending-by-category':
            case 'spending-by-budget':
            case 'spending-by-tag':
                $args = $this->input($request, array_merge($base, self::commonRules(), self::INTERVAL_RULES, self::TOP_RULES), true);
                $data = $charts->fromSpending(new Spending($ledger)->by($this->scope($args), substr($source, strlen('spending-by-')), isset($args['top_n']) ? (int) $args['top_n'] : null), $chart);

                break;

            case 'income-vs-expense':
                $args = $this->input($request, array_merge($base, self::commonRules(), self::INTERVAL_RULES), true);
                $data = $charts->fromIncomeVsExpense(new Flows($ledger)->incomeVsExpense($this->scope($args)), $chart);

                break;

            case 'cash-flow':
                $args = $this->input($request, array_merge($base, self::commonRules(), self::INTERVAL_RULES), true);
                $data = $charts->fromCashFlow(new Flows($ledger)->cashFlow($this->scope($args, null, Ledger::ASSET_TYPES)), $chart);

                break;

            case 'net-worth':
                $args = $this->input($request, array_merge($base, self::commonRules(), self::INTERVAL_RULES), true);
                $data = $charts->fromNetWorth(new Flows($ledger)->netWorth($this->scope($args)), $chart);

                break;

            case 'category-trend':
                $args                = $this->input($request, array_merge($base, self::commonRules(), self::INTERVAL_RULES, [
                    'category_id'   => ['sometimes', 'integer', 'min:1'],
                    'category_name' => ['sometimes', 'string', 'min:1', 'max:1024'],
                ]), true);
                [$category, $scope]  = $this->trendScope($args);
                $data                = $charts->fromTrend(new Spending($ledger)->categoryTrend($scope, $category), $chart);

                break;

            case 'budget-performance':
                $args = $this->input($request, array_merge($base, self::commonRules()), true);
                $data = $charts->fromBudgets(new Budgets($ledger)->performance($this->scope($args)), $chart);

                break;

            case 'payee-leaderboard':
                $args = $this->input($request, array_merge($base, self::commonRules(), self::TOP_RULES, ['direction' => ['sometimes', 'in:out,in']]), true);
                $data = $charts->fromPayees(new Spending($ledger)->payees($this->scope($args), (int) ($args['top_n'] ?? 20), (string) ($args['direction'] ?? 'out')), $chart);

                break;

            default: // uncategorized-summary
                $args  = $this->input($request, array_merge($base, self::commonRules(false)), true);
                $today = $this->today();
                $first = TransactionJournal::query()->where('user_group_id', $this->administration()->id)->min('date');
                $from  = null === $first ? $today->copy() : Carbon::parse((string) $first)->startOfDay();
                $data  = $charts->fromUncategorized(new Spending($ledger)->uncategorized($this->scope($args, [$from, $today->copy()])), $chart);
        }
        $data['source'] = '/analytics/'.$source;

        return $this->ok($data);
    }

    private function overview(Request $request, string $by, string $chart): JsonResponse
    {
        $args     = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);
        $scope    = $this->scope(array_merge($args, ['interval' => 'none']));
        $spending = new Spending($this->ledger());
        $spent    = $spending->by($scope, $by);
        $earned   = $spending->by($scope, $by, null, true);

        return $this->ok($this->categorical($this->charts()->fromOverview($spent, $earned, $chart), $args));
    }

    /**
     * A chart whose x axis is categories covers the whole range; an `interval` the caller sent
     * is said to be unused rather than silently dropped.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function categorical(array $data, array $args): array
    {
        if (isset($args['interval']) && 'none' !== $args['interval']) {
            $data['notes']   = array_merge((array) ($data['notes'] ?? []), [sprintf('interval=%s is not used: this chart\'s x axis is categories over the whole range', $args['interval'])]);
        }
        $data['provenance']['interval'] = 'none (x is categories)';

        return $data;
    }

    private function charts(): Charts
    {
        return new Charts($this->ledger());
    }
}
