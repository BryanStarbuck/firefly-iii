<?php

/*
 * TransactionRoutes.php
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

use FireflyIII\Machine\Http\Controllers\TransactionController as T;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.3 — transaction groups, journals (splits), conversion, cloning, links and bulk edits.
 *
 * Every route is live. Writes take the write protocol (§7: dry run by default, confirm token,
 * fingerprint, ceiling, undo); the deletes are admin-tier and never reachable from the MCP.
 *
 * Code: ~/BGit/Bryan_git/firefly-iii/app/Machine/Http/Controllers/TransactionController.php
 */
final class TransactionRoutes implements RouteFamily
{
    public static function routes(): array
    {
        return [
            RouteDef::live('GET', '/transactions', 'read', 'Transaction groups with filters, including without_category, without_budget and without_tag.', [T::class, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/transactions/export', 'read', 'The same filters as CSV, from Firefly\'s own exporter.', [T::class, 'export'], ['phase' => 'P2']),
            RouteDef::live('GET', '/transactions/{group_id}', 'read', 'One transaction group, with every split and its links.', [T::class, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/transactions/{group_id}/links', 'read', 'The group\'s journal links (relates to, refunds, reimburses…).', [T::class, 'links'], ['phase' => 'P2']),
            RouteDef::live('GET', '/transaction-journals/{journal_id}', 'read', 'One split, with its group id.', [T::class, 'showJournal'], ['phase' => 'P2']),
            RouteDef::live('POST', '/transactions', 'write', 'Store a transaction group through Firefly\'s factory: its validation, its rules, its duplicate check (error_if_duplicate_hash pinned on).', [T::class, 'store'], ['phase' => 'P3']),
            RouteDef::live('PUT', '/transactions/{group_id}', 'write', 'Edit a group, including re-splitting; rules re-run only with apply_rules: true.', [T::class, 'update'], ['phase' => 'P8']),
            RouteDef::live('POST', '/transactions/{group_id}/convert', 'write', 'Convert between withdrawal, deposit and transfer.', [T::class, 'convert'], ['phase' => 'P8']),
            RouteDef::live('POST', '/transactions/{group_id}/clone', 'write', 'Clone a group to a new date.', [T::class, 'cloneGroup'], ['phase' => 'P8']),
            RouteDef::live('POST', '/transactions/bulk', 'write', 'Bulk edit — category, budget, tags — over journal ids or a filter.', [T::class, 'bulk'], ['phase' => 'P8']),
            RouteDef::live('POST', '/transactions/categorize', 'write', 'Set the category of many journals (ids or a filter).', [T::class, 'categorize'], ['phase' => 'P8']),
            RouteDef::live('POST', '/transactions/set-budget', 'write', 'Set or clear the budget of many journals (ids or a filter).', [T::class, 'setBudget'], ['phase' => 'P8']),
            RouteDef::live('POST', '/transaction-links', 'write', 'Link two transactions.', [T::class, 'storeLink'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/transaction-links/{id}', 'write', 'Remove a link; the transactions survive.', [T::class, 'destroyLink'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/transactions/{group_id}', 'admin', 'Delete a transaction group.', [T::class, 'destroy'], ['phase' => 'P10']),
            RouteDef::live('DELETE', '/transaction-journals/{journal_id}', 'admin', 'Delete one split.', [T::class, 'destroyJournal'], ['phase' => 'P10']),
            RouteDef::live('POST', '/transactions/mass-delete', 'admin', 'Delete many transaction groups.', [T::class, 'massDelete'], ['phase' => 'P10']),
        ];
    }
}
