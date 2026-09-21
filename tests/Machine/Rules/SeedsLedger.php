<?php

/*
 * SeedsLedger.php
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

namespace Tests\Machine\Rules;

use Carbon\Carbon;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\User;

/**
 * An INVENTED ledger for the rules and reference tests: the household administration with one
 * Northbank checking account (last-4 4021) and a few withdrawals. No real data, ever.
 */
trait SeedsLedger
{
    protected function primaryUsd(User $user): TransactionCurrency
    {
        $usd = TransactionCurrency::query()->where('code', 'USD')->first();
        if (null === $usd) {
            $usd = TransactionCurrency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'enabled' => true]);
        }
        $group = $user->userGroup;
        $group->currencies()->detach();
        $group->currencies()->attach($usd->id, ['group_default' => true]);

        return $usd;
    }

    protected function asset(User $user, string $name = 'Northbank Checking 4021'): Account
    {
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($user);

        return $factory->create([
            'name'              => $name,
            'account_type_name' => 'asset',
            'account_role'      => 'defaultAsset',
            'currency_id'       => $this->primaryUsd($user)->id,
            'active'            => true,
            'iban'              => null,
            'virtual_balance'   => '0',
            'opening_balance'   => '',
            'notes'             => '',
        ]);
    }

    protected function withdrawal(User $user, Account $from, string $description, string $amount, string $date, string $to = 'Corner Market'): TransactionGroup
    {
        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($user);

        return $factory->create([
            'user'         => $user,
            'user_group'   => $user->userGroup,
            'group_title'  => null,
            'transactions' => [[
                'type'             => 'withdrawal',
                'date'             => Carbon::parse($date),
                'amount'           => $amount,
                'currency_code'    => 'USD',
                'description'      => $description,
                'source_id'        => $from->id,
                'destination_name' => $to,
            ]],
        ]);
    }

    protected function ruleGroup(User $user, string $title = 'Household rules'): RuleGroup
    {
        /** @var RuleGroupRepositoryInterface $repo */
        $repo = app(RuleGroupRepositoryInterface::class);
        $repo->setUser($user);

        return $repo->store(['title' => $title, 'description' => null, 'active' => true]);
    }

    /** @param array<string, mixed> $overrides */
    protected function rule(User $user, RuleGroup $group, string $title, array $overrides = []): Rule
    {
        /** @var RuleRepositoryInterface $repo */
        $repo = app(RuleRepositoryInterface::class);
        $repo->setUser($user);

        return $repo->store($overrides + [
            'title'         => $title,
            'rule_group_id' => $group->id,
            'trigger'       => 'store-journal',
            'active'        => true,
            'strict'        => true,
            'triggers'      => [['type' => 'description_contains', 'value' => 'coffee', 'active' => true, 'stop_processing' => false, 'prohibited' => false]],
            'actions'       => [['type' => 'set_category', 'value' => 'Dining', 'active' => true, 'stop_processing' => false]],
        ]);
    }
}
