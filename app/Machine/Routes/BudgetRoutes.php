<?php

/*
 * BudgetRoutes.php
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

use FireflyIII\Machine\Http\Controllers\BudgetController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.5 — budgets, budget limits, available budgets, and the composed budget period (the numbers of the UI's Budgets page). "No limit" is null, never "0" (§14.2).
 *
 * Every route is live — BudgetController.
 */
final class BudgetRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = BudgetController::class;

        return [
            RouteDef::live('GET', '/budgets', 'read', 'Budgets with their auto-budget settings (active=true|false filters; omitted lists all).', [$c, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/budgets/{id}', 'read', 'One budget, with its limits and spent in a range.', [$c, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/budgets/{id}/limits', 'read', 'A budget\'s limits in a range, each with spent and left.', [$c, 'limits'], ['phase' => 'P2']),
            RouteDef::live('GET', '/budget-period', 'read', 'The budget page for a period: per budget and currency limit (or null), spent, left; plus available, budgeted, spent and left to spend.', [$c, 'period'], ['phase' => 'P2', 'composed' => true]),
            RouteDef::live('GET', '/budget-period/gaps', 'read', 'Budgets with spending and no limit in the period, and withdrawals with no budget at all.', [$c, 'gaps'], ['phase' => 'P2', 'composed' => true]),
            RouteDef::live('GET', '/available-budgets', 'read', 'Available budgets (income to budget) in a range.', [$c, 'availableIndex'], ['phase' => 'P2']),
            RouteDef::live('POST', '/budgets', 'write', 'Create a budget, with an optional auto-budget.', [$c, 'store'], ['phase' => 'P6']),
            RouteDef::live('PUT', '/budgets/{id}', 'write', 'Edit a budget or its auto-budget.', [$c, 'update'], ['phase' => 'P6']),
            RouteDef::live('PUT', '/budgets/{id}/limits', 'write', 'SET (not add) the limit for exactly one period and currency.', [$c, 'setLimit'], ['phase' => 'P3']),
            RouteDef::live('DELETE', '/budgets/{id}/limits/{limit_id}', 'write', 'Remove one period\'s limit: the budget becomes UNBUDGETED for that period (not budgeted zero).', [$c, 'deleteLimit'], ['phase' => 'P6']),
            RouteDef::live('PUT', '/available-budgets', 'write', 'Set the income-to-budget for a period.', [$c, 'setAvailable'], ['phase' => 'P6']),
            RouteDef::live('POST', '/budget-period/copy-previous', 'write', 'Copy every limit from the previous period of the same length.', [$c, 'copyPrevious'], ['phase' => 'P6', 'composed' => true]),
            RouteDef::live('POST', '/budget-period/set-average', 'write', 'Set each limit to the average spent over the previous N periods.', [$c, 'setAverage'], ['phase' => 'P6', 'composed' => true]),
            RouteDef::live('POST', '/budget-period/reset', 'write', 'Remove every limit in the period (it becomes unbudgeted, stated as such).', [$c, 'reset'], ['phase' => 'P6', 'composed' => true]),
            RouteDef::live('DELETE', '/budgets/{id}', 'admin', 'Delete a budget (its transactions keep their amounts and lose the budget).', [$c, 'destroy'], ['phase' => 'P10']),
        ];
    }
}
