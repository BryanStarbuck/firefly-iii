<?php

/*
 * AccountRoutes.php
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

use FireflyIII\Machine\Http\Controllers\AccountController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.2 — accounts (asset, liability, expense, revenue) and reconciliation (composed: upstream computes it in web-only controllers).
 *
 * Every entry is live. Reconciliation is composed (R1's named exception): upstream computes it in
 * the web-only Account\ReconcileController and Json\ReconcileController, which /api/v1 cannot reach.
 */
final class AccountRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = AccountController::class;

        return [
            RouteDef::live('GET', '/accounts', 'read', 'Accounts by type, with balances.', [$c, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/accounts/{id}', 'read', 'One account.', [$c, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/accounts/{id}/balance', 'read', 'Firefly\'s running balance at the end of a day, per currency.', [$c, 'balance'], ['phase' => 'P2']),
            RouteDef::live('GET', '/accounts/{id}/properties', 'read', 'Counts, first/last transaction date, IBAN last-4, opening and virtual balance, liability details.', [$c, 'properties'], ['phase' => 'P2']),
            RouteDef::live('GET', '/accounts/{id}/transactions', 'read', 'The account\'s transactions in a period.', [$c, 'transactions'], ['phase' => 'P2']),
            RouteDef::live('POST', '/accounts', 'write', 'Create an account (dry run by default).', [$c, 'store'], ['phase' => 'P5']),
            RouteDef::live('POST', '/accounts/batch', 'write', 'Create many accounts in one transaction (manifest provisioning uses this).', [$c, 'storeBatch'], ['phase' => 'P5']),
            RouteDef::live('PUT', '/accounts/{id}', 'write', 'Edit an account: name, role, IBAN, notes, net-worth inclusion, order.', [$c, 'update'], ['phase' => 'P8']),
            RouteDef::live('POST', '/accounts/{id}/deactivate', 'write', 'Deactivate an account (Firefly\'s active: false — hidden, history intact).', [$c, 'deactivate'], ['phase' => 'P8']),
            RouteDef::live('POST', '/accounts/{id}/activate', 'write', 'Re-activate an account.', [$c, 'activate'], ['phase' => 'P8']),
            RouteDef::live('POST', '/accounts/{id}/move', 'write', 'Reorder an account.', [$c, 'move'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/accounts/{id}', 'admin', 'Delete an account, optionally moving its history to another account.', [$c, 'destroy'], ['phase' => 'P10']),
            RouteDef::live('POST', '/accounts/{id}/reconcile/plan', 'read', 'Plan a reconciliation against a statement balance: Firefly\'s start/end balance, the selected journals, the difference, and a confirm token.', [$c, 'reconcilePlan'], ['phase' => 'P8', 'composed' => true]),
            RouteDef::live('POST', '/accounts/{id}/reconcile/apply', 'write', 'Write the reconciliation: mark the journals reconciled and, if the difference is non-zero, create Firefly\'s own reconciliation transaction.', [$c, 'reconcileApply'], ['phase' => 'P8', 'composed' => true]),
            RouteDef::live('POST', '/accounts/{id}/unreconcile/{journal_id}', 'write', 'Un-reconcile one journal.', [$c, 'unreconcile'], ['phase' => 'P8']),
        ];
    }
}
