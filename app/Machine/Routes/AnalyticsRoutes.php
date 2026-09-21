<?php

/*
 * AnalyticsRoutes.php
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

namespace FireflyIII\Machine\Routes;

use FireflyIII\Machine\Http\Controllers\AnalyticsController;
use FireflyIII\Machine\Http\Controllers\ChartController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §10 — the analytics plane (§10.2) and chart-ready series (§10.5). Every figure is a decimal string per currency; transfers, opening balances and reconciliations are never spending; a chart gap is null, never "0".
 *
 * Every route is live (§6.3a). Handlers: app/Machine/Http/Controllers/{Analytics,Chart,Report}Controller.php;
 * calculators: app/Machine/Analytics/.
 */
final class AnalyticsRoutes implements RouteFamily
{
    public static function routes(): array
    {
        return [
            RouteDef::live('GET', '/analytics/summary', 'read', 'The dashboard boxes: balance, bills paid and unpaid, left to spend, net worth.', [AnalyticsController::class, 'summary'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/analytics/spending-by-category', 'read', 'Spent per category per interval, plus "no category".', [AnalyticsController::class, 'spendingByCategory'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/spending-by-budget', 'read', 'Spent per budget per interval, plus "no budget".', [AnalyticsController::class, 'spendingByBudget'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/spending-by-tag', 'read', 'Spent per tag per interval, plus "no tag".', [AnalyticsController::class, 'spendingByTag'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/payee-leaderboard', 'read', 'Who got the money (or who paid it): per expense or revenue account.', [AnalyticsController::class, 'payeeLeaderboard'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/income-vs-expense', 'read', 'In, out and net per interval.', [AnalyticsController::class, 'incomeVsExpense'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/cash-flow', 'read', 'Opening, in, out and closing per interval, over asset accounts.', [AnalyticsController::class, 'cashFlow'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/analytics/net-worth', 'read', 'Assets, liabilities and net per interval and per account.', [AnalyticsController::class, 'netWorth'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/analytics/category-trend', 'read', 'One category over time, with mean and median.', [AnalyticsController::class, 'categoryTrend'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/budget-performance', 'read', 'Limit vs spent vs left per budget per period, and the variance.', [AnalyticsController::class, 'budgetPerformance'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/analytics/subscriptions', 'read', 'Per subscription: expected, paid, missed, annualised cost.', [AnalyticsController::class, 'subscriptions'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/analytics/recurring', 'read', 'Recurring payments that are not yet a subscription, with evidence (a detector, not an oracle).', [AnalyticsController::class, 'recurring'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/runway', 'read', 'Liquid assets divided by trailing average outflow, in months, with both figures.', [AnalyticsController::class, 'runway'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/analytics/uncategorized-summary', 'read', 'Count and total of uncategorised withdrawals, by account and by month.', [AnalyticsController::class, 'uncategorizedSummary'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/anomalies', 'read', 'Categories and payees unusually far from their own trailing norm, with evidence.', [AnalyticsController::class, 'anomalies'], ['phase' => 'P7']),
            RouteDef::live('GET', '/analytics/piggy-progress', 'read', 'Per piggy bank: saved, target, left, and on track for its date.', [AnalyticsController::class, 'piggyProgress'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/account-balances', 'read', 'Chart series: account balances over time.', [ChartController::class, 'accountBalances'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/net-worth', 'read', 'Chart series: net worth over time.', [ChartController::class, 'netWorth'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/charts/budget-overview', 'read', 'Chart series: budgets, limit vs spent.', [ChartController::class, 'budgetOverview'], ['phase' => 'P7', 'composed' => true]),
            RouteDef::live('GET', '/charts/category-overview', 'read', 'Chart series: categories, spent and earned.', [ChartController::class, 'categoryOverview'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/spending-by-category', 'read', 'Chart series: spending by category per interval.', [ChartController::class, 'spendingByCategory'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/income-vs-expense', 'read', 'Chart series: income vs expense per interval.', [ChartController::class, 'incomeVsExpense'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/piggy-bank/{id}', 'read', 'Chart series: one piggy bank\'s progress (id or name).', [ChartController::class, 'piggyBank'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/subscription/{id}', 'read', 'Chart series: one subscription\'s payments (id or name).', [ChartController::class, 'subscription'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/tag-overview', 'read', 'Chart series: tags, spent and earned.', [ChartController::class, 'tagOverview'], ['phase' => 'P7']),
            RouteDef::live('GET', '/charts/series', 'read', 'Any analytics route as chart series (source=/analytics/cash-flow&interval=month).', [ChartController::class, 'series'], ['phase' => 'P7']),
        ];
    }
}
