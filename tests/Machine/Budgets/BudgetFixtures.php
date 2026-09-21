<?php

/*
 * BudgetFixtures.php
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

namespace Tests\Machine\Budgets;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;

/**
 * Invented fixtures for the budget and category families: household books at "Northbank" (…4021),
 * budgets Groceries / Dining / Travel, categories Groceries / Food / Dining Out. No real data.
 */
trait BudgetFixtures
{
    protected User $user;
    protected Account $checking;
    protected TransactionCurrency $primary;

    protected function bootLedger(): void
    {
        $this->user     = $this->operatorUser();
        $this->primary  = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);

        /** @var AccountRepositoryInterface $accounts */
        $accounts       = app(AccountRepositoryInterface::class);
        $accounts->setUser($this->user);
        $this->checking = $accounts->store([
            'name'              => 'Northbank Checking 4021',
            'account_type_name' => 'asset',
            'account_role'      => 'defaultAsset',
            'currency_id'       => $this->primary->id,
            'active'            => true,
            'iban'              => null,
            'virtual_balance'   => null,
            'opening_balance'   => null,
            'include_net_worth' => true,
        ]);
    }

    protected function budget(string $name, bool $active = true): Budget
    {
        return Budget::query()->create([
            'user_id'       => $this->user->id,
            'user_group_id' => $this->user->user_group_id,
            'name'          => $name,
            'active'        => $active,
            'order'         => Budget::query()->count() + 1,
        ]);
    }

    protected function category(string $name): Category
    {
        return Category::query()->create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => $name]);
    }

    protected function limit(Budget $budget, string $start, string $end, string $amount, ?TransactionCurrency $currency = null): BudgetLimit
    {
        $limit                          = new BudgetLimit();
        $limit->budget_id               = $budget->id;
        $limit->transaction_currency_id = ($currency ?? $this->primary)->id;
        $limit->start_date              = $start;
        $limit->start_date_tz           = 'UTC';
        $limit->end_date                = $end;
        $limit->end_date_tz             = 'UTC';
        $limit->amount                  = $amount;
        $limit->save();

        return $limit;
    }

    protected function available(string $start, string $end, string $amount): AvailableBudget
    {
        return AvailableBudget::query()->create([
            'user_id'                 => $this->user->id,
            'user_group_id'           => $this->user->user_group_id,
            'transaction_currency_id' => $this->primary->id,
            'amount'                  => $amount,
            'start_date'              => Carbon::parse($start)->startOfDay(),
            'start_date_tz'           => 'UTC',
            'end_date'                => Carbon::parse($end)->startOfDay(),
            'end_date_tz'             => 'UTC',
        ]);
    }

    /** A withdrawal from Northbank checking to an invented shop. */
    protected function spend(string $date, string $amount, ?Budget $budget = null, ?string $category = null, string $shop = 'Corner Grocer'): TransactionGroup
    {
        /** @var TransactionGroupRepositoryInterface $groups */
        $groups = app(TransactionGroupRepositoryInterface::class);
        $groups->setUser($this->user);

        return $groups->store([
            'user'                    => $this->user,
            'user_group'              => $this->user->userGroup,
            'group_title'             => null,
            'error_if_duplicate_hash' => false,
            'apply_rules'             => false,
            'fire_webhooks'           => false,
            'transactions'            => [[
                'type'             => 'withdrawal',
                'date'             => Carbon::parse($date.' 12:00:00'),
                'amount'           => $amount,
                'description'      => sprintf('%s %s', $shop, $date),
                'source_id'        => $this->checking->id,
                'destination_name' => $shop,
                'currency_id'      => $this->primary->id,
                'budget_id'        => $budget?->id,
                'category_name'    => $category,
                'reconciled'       => false,
                'tags'             => [],
            ]],
        ]);
    }

    /** A stable hash of a table's rows — "the dry run changed nothing" is byte-identical rows. */
    protected function tableHash(string $table): string
    {
        return hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all()));
    }

    /** Plan, then apply with the plan's token; returns the apply envelope. @param array<string, mixed> $body */
    protected function planAndApply(string $method, string $path, array $body): array
    {
        $plan = $this->envelope($this->machine($method, $path, $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);

        $apply = $this->envelope($this->machine($method, $path, $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['dry_run']);

        return $apply;
    }
}
