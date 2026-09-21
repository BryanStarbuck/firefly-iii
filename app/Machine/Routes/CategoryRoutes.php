<?php

/*
 * CategoryRoutes.php
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

use FireflyIII\Machine\Http\Controllers\CategoryController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.4 — categories (and §8.4a, the "Group > Sub" tree).
 *
 * Every route is live — CategoryController.
 */
final class CategoryRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = CategoryController::class;

        return [
            RouteDef::live('GET', '/categories', 'read', 'Categories (search matches part of the name).', [$c, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/categories/tree', 'read', 'Every category as the two-level "Group > Sub" tree, with the same tree as a YAML document.', [$c, 'tree'], ['phase' => 'P2']),
            RouteDef::live('GET', '/categories/{id}', 'read', 'One category, with spent and earned in a range, per currency.', [$c, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/categories/{id}/transactions', 'read', 'The category\'s transactions in a range.', [$c, 'transactions'], ['phase' => 'P2']),
            RouteDef::live('POST', '/categories', 'write', 'Create a category.', [$c, 'store'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/categories/{id}', 'write', 'Rename a category or edit its notes.', [$c, 'update'], ['phase' => 'P8']),
            RouteDef::live('POST', '/categories/merge', 'write', 'Merge categories: re-point every journal, then remove the merged ones.', [$c, 'merge'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/categories/{id}', 'admin', 'Delete a category (journals keep their amounts and lose the category).', [$c, 'destroy'], ['phase' => 'P10']),
        ];
    }
}
