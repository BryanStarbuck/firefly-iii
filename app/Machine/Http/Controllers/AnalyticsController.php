<?php

/*
 * AnalyticsController.php
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
use FireflyIII\Machine\Analytics\AnomalyDetector;
use FireflyIII\Machine\Analytics\Budgets;
use FireflyIII\Machine\Analytics\Commitments;
use FireflyIII\Machine\Analytics\Flows;
use FireflyIII\Machine\Analytics\Ledger;
use FireflyIII\Machine\Analytics\RecurringDetector;
use FireflyIII\Machine\Analytics\Runway;
use FireflyIII\Machine\Analytics\ScopedController;
use FireflyIII\Machine\Analytics\Spending;
use FireflyIII\Machine\Analytics\Summary;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The analytics plane — apis.mdx §10.2. Every route is read-tier, answers per currency with
 * decimal strings, echoes what it excluded, and carries its provenance (§10.3). The sums live
 * here, once, so the CLI, the MCP and a chart can never disagree on a total.
 *
 * Each method validates (unknown arguments refused), builds the Scope, calls ONE calculator in
 * app/Machine/Analytics/, and renders. The calculators are also what ChartController and
 * ReportController call, so a chart and a table are the same numbers.
 */
final class AnalyticsController extends ScopedController
{
    public function summary(Request $request): JsonResponse
    {
        $args  = $this->input($request, self::commonRules(false), true);
        $today = $this->today();

        return $this->ok(new Summary($this->ledger())->boxes($this->scope($args, [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()])));
    }

    public function spendingByCategory(Request $request): JsonResponse
    {
        return $this->spendingBy($request, 'category');
    }

    public function spendingByBudget(Request $request): JsonResponse
    {
        return $this->spendingBy($request, 'budget');
    }

    public function spendingByTag(Request $request): JsonResponse
    {
        return $this->spendingBy($request, 'tag');
    }

    public function payeeLeaderboard(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::TOP_RULES, ['direction' => ['sometimes', 'in:out,in']]), true);

        return $this->ok(new Spending($this->ledger())->payees($this->scope($args), (int) ($args['top_n'] ?? 20), (string) ($args['direction'] ?? 'out')));
    }

    public function incomeVsExpense(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok(new Flows($this->ledger())->incomeVsExpense($this->scope($args)));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok(new Flows($this->ledger())->cashFlow($this->scope($args, null, Ledger::ASSET_TYPES)));
    }

    public function netWorth(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES), true);

        return $this->ok(new Flows($this->ledger())->netWorth($this->scope($args)));
    }

    public function categoryTrend(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES, [
            'category_id'   => ['sometimes', 'integer', 'min:1'],
            'category_name' => ['sometimes', 'string', 'min:1', 'max:1024'],
        ]), true);

        [$category, $scope] = $this->trendScope($args);

        return $this->ok(new Spending($this->ledger())->categoryTrend($scope, $category));
    }

    public function budgetPerformance(Request $request): JsonResponse
    {
        $args = $this->input($request, self::commonRules(), true);

        return $this->ok(new Budgets($this->ledger())->performance($this->scope($args)));
    }

    public function subscriptions(Request $request): JsonResponse
    {
        $args  = $this->input($request, self::commonRules(false), true);
        $today = $this->today();

        return $this->ok(new Commitments($this->ledger())->subscriptions($this->scope($args, [$today->copy()->startOfYear(), $today->copy()->endOfYear()]), $today));
    }

    public function recurring(Request $request): JsonResponse
    {
        $args  = $this->input($request, array_merge(self::commonRules(false), [
            'min_occurrences' => ['sometimes', 'integer', 'min:2', 'max:1000'],
            'tolerance_days'  => ['sometimes', 'integer', 'min:0', 'max:31'],
        ]), true);
        $today = $this->today();
        $scope = $this->scope($args, [$today->copy()->subMonthsNoOverflow(13)->addDay(), $today->copy()]);

        return $this->ok(new RecurringDetector($this->ledger())->detect($scope, (int) ($args['min_occurrences'] ?? 3), (int) ($args['tolerance_days'] ?? 3)));
    }

    public function runway(Request $request): JsonResponse
    {
        $args  = $this->input($request, array_merge(self::ACCOUNT_RULES, self::FILTER_RULES, [
            'basis' => ['sometimes', 'in:3,6,12'],
            'end'   => ['sometimes', 'date_format:Y-m-d'],
        ]), true);
        $asOf  = isset($args['end']) ? Carbon::createFromFormat('Y-m-d', (string) $args['end'], (string) config('app.timezone'))->startOfDay() : $this->today();
        $scope = $this->scope(array_merge($args, ['start' => $asOf->format('Y-m-d'), 'end' => $asOf->format('Y-m-d')]), null, Ledger::ASSET_TYPES);

        return $this->ok(new Runway($this->ledger())->compute($scope, (int) ($args['basis'] ?? 6), $asOf));
    }

    public function uncategorizedSummary(Request $request): JsonResponse
    {
        $args  = $this->input($request, self::commonRules(false), true);
        $today = $this->today();
        $first = TransactionJournal::query()->where('user_group_id', $this->administration()->id)->min('date');
        $from  = null === $first ? $today->copy() : Carbon::parse((string) $first)->startOfDay();

        return $this->ok(new Spending($this->ledger())->uncategorized($this->scope($args, [$from, $today->copy()])));
    }

    public function anomalies(Request $request): JsonResponse
    {
        $args = $this->input($request, array_merge(self::commonRules(false), [
            'z'          => ['sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,6})?$/'],
            'min_amount' => ['sometimes', 'string', 'regex:/^\d{1,20}(\.\d{1,12})?$/'],
            'trailing'   => ['sometimes', 'integer', 'min:2', 'max:36'],
        ]), true);
        $today = $this->today();
        $scope = $this->scope($args, [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()]);
        $z     = (string) ($args['z'] ?? '2.0');
        if (Money::isZero($z)) {
            throw MachineException::invalid('z must be greater than zero.', 'Send z as a positive decimal string such as "2.0" (standard deviations)');
        }

        return $this->ok(new AnomalyDetector($this->ledger())->detect($scope, $z, Money::strip((string) ($args['min_amount'] ?? '0')), (int) ($args['trailing'] ?? AnomalyDetector::DEFAULT_TRAILING)));
    }

    /**
     * Takes the shared arguments like every §10.2 route: account_ids[] narrows to the piggy
     * banks on those accounts; the range is not used (a piggy bank is as of today) and the
     * response says so rather than silently dropping it.
     */
    public function piggyProgress(Request $request): JsonResponse
    {
        $args  = $this->input($request, self::commonRules(false), true);
        $today = $this->today();

        return $this->ok(new Commitments($this->ledger())->piggyProgress($this->scope($args, [$today, $today], Ledger::ASSET_TYPES), $today));
    }

    private function spendingBy(Request $request, string $by): JsonResponse
    {
        $args  = $this->input($request, array_merge(self::commonRules(), self::INTERVAL_RULES, self::TOP_RULES), true);
        $topN  = isset($args['top_n']) ? (int) $args['top_n'] : null;

        return $this->ok(new Spending($this->ledger())->by($this->scope($args), $by, $topN));
    }
}
