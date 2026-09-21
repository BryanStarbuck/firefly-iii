<?php

/*
 * SubscriptionRoutes.php
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

use FireflyIII\Machine\Http\Controllers\PiggyBankController as P;
use FireflyIII\Machine\Http\Controllers\RecurrenceController as R;
use FireflyIII\Machine\Http\Controllers\SubscriptionController as S;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.6 — subscriptions (bills), piggy banks and recurring transactions. All live.
 *
 * Subscriptions:  SubscriptionController (BillRepository + SubscriptionEnrichment, BillDateCalculator)
 * Piggy banks:    PiggyBankController (PiggyBankRepository: canAddAmount / addAmount / removeAmount)
 * Recurrences:    RecurrenceController (RecurringRepository, CreateRecurringTransactions for trigger)
 */
final class SubscriptionRoutes implements RouteFamily
{
    public static function routes(): array
    {
        return [
            RouteDef::live('GET', '/subscriptions', 'read', 'Subscriptions, with amount range, frequency, paid and expected dates.', [S::class, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/subscriptions/status', 'read', 'Paid, unpaid, and expected-but-not-seen for a period.', [S::class, 'status'], ['phase' => 'P2']),
            RouteDef::live('GET', '/subscriptions/{id}', 'read', 'One subscription.', [S::class, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/subscriptions/{id}/transactions', 'read', 'A subscription\'s transactions in a range.', [S::class, 'transactions'], ['phase' => 'P2']),
            RouteDef::live('POST', '/subscriptions', 'write', 'Create a subscription.', [S::class, 'store'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/subscriptions/{id}', 'write', 'Edit a subscription.', [S::class, 'update'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/subscriptions/{id}', 'admin', 'Delete a subscription.', [S::class, 'destroy'], ['phase' => 'P10']),
            RouteDef::live('GET', '/piggy-banks', 'read', 'Piggy banks, with target, saved, left to save, target date and save-per-month.', [P::class, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/piggy-banks/{id}', 'read', 'One piggy bank.', [P::class, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/piggy-banks/{id}/events', 'read', 'Every add and remove, with dates.', [P::class, 'events'], ['phase' => 'P2']),
            RouteDef::live('POST', '/piggy-banks', 'write', 'Create a piggy bank.', [P::class, 'store'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/piggy-banks/{id}', 'write', 'Edit a piggy bank.', [P::class, 'update'], ['phase' => 'P8']),
            RouteDef::live('POST', '/piggy-banks/{id}/add', 'write', 'Add money (refuses beyond what the account can spare, with the figure).', [P::class, 'add'], ['phase' => 'P8']),
            RouteDef::live('POST', '/piggy-banks/{id}/remove', 'write', 'Remove money.', [P::class, 'remove'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/piggy-banks/{id}', 'admin', 'Delete a piggy bank.', [P::class, 'destroy'], ['phase' => 'P10']),
            RouteDef::live('GET', '/recurrences', 'read', 'Recurring transactions, with their next occurrences.', [R::class, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/recurrences/{id}', 'read', 'One recurring transaction and its next N dates.', [R::class, 'show'], ['phase' => 'P2']),
            RouteDef::live('GET', '/recurrences/{id}/transactions', 'read', 'Transactions a recurrence created in a range.', [R::class, 'transactions'], ['phase' => 'P2']),
            RouteDef::live('POST', '/recurrences', 'write', 'Create a recurring transaction.', [R::class, 'store'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/recurrences/{id}', 'write', 'Edit a recurring transaction.', [R::class, 'update'], ['phase' => 'P8']),
            RouteDef::live('POST', '/recurrences/{id}/trigger', 'write', 'Create the occurrence now (Firefly\'s trigger). No dry run: the preview is GET /recurrences/{id}.', [R::class, 'trigger'], ['phase' => 'P8', 'dryRun' => false]),
            RouteDef::live('DELETE', '/recurrences/{id}', 'admin', 'Delete a recurring transaction.', [R::class, 'destroy'], ['phase' => 'P10']),
        ];
    }
}
