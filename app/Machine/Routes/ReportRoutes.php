<?php

/*
 * ReportRoutes.php
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

use FireflyIII\Machine\Http\Controllers\ReportController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.9 — the numbers of Firefly's six report types (the HTML stays in the UI).
 *
 * Every route is live (§6.3a). Handlers: app/Machine/Http/Controllers/{Analytics,Chart,Report}Controller.php;
 * calculators: app/Machine/Analytics/.
 */
final class ReportRoutes implements RouteFamily
{
    public static function routes(): array
    {
        return [
            RouteDef::live('GET', '/reports/default', 'read', 'Income, expenses, per-account balance changes, budgets and categories for a period.', [ReportController::class, 'default'], ['phase' => 'P8', 'composed' => true]),
            RouteDef::live('GET', '/reports/audit', 'read', 'Every journal with the running balance after it — the reconciliation aid.', [ReportController::class, 'audit'], ['phase' => 'P8']),
            RouteDef::live('GET', '/reports/budget', 'read', 'Per budget per period: limit, spent, left.', [ReportController::class, 'budget'], ['phase' => 'P8', 'composed' => true]),
            RouteDef::live('GET', '/reports/category', 'read', 'Spent and earned per category per period.', [ReportController::class, 'category'], ['phase' => 'P8']),
            RouteDef::live('GET', '/reports/tag', 'read', 'Spent and earned per tag per period.', [ReportController::class, 'tag'], ['phase' => 'P8']),
            RouteDef::live('GET', '/reports/double', 'read', 'Per expense/revenue account: in, out, net.', [ReportController::class, 'double'], ['phase' => 'P8']),
        ];
    }
}
