<?php

/*
 * RouteDef.php
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

namespace Tests\Machine\Search;

use Carbon\Carbon;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;

/**
 * An INVENTED ledger for the search / mirror / undo / admin tests: the household administration,
 * a Northbank checking account (last-4 4021), a Meridian card (7734) and a few withdrawals.
 * No real financial data, ever.
 */
trait SearchLedger
{
    protected function usd(User $user): TransactionCurrency
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

    protected function assetAccount(User $user, string $name = 'Northbank Checking 4021', ?string $iban = null): Account
    {
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($user);

        return $factory->create([
            'name'              => $name,
            'account_type_name' => 'asset',
            'account_role'      => 'defaultAsset',
            'currency_id'       => $this->usd($user)->id,
            'active'            => true,
            'iban'              => $iban,
            'virtual_balance'   => '0',
            'opening_balance'   => '',
            'notes'             => '',
        ]);
    }

    protected function spend(User $user, Account $from, string $description, string $amount, string $date, string $to = 'Corner Market'): TransactionGroup
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

    /** Three invented withdrawals from one Northbank account. */
    protected function seedSpending(User $user): Account
    {
        $checking = $this->assetAccount($user, 'Northbank Checking 4021', 'GB43NRTH00000000004021');
        $this->spend($user, $checking, 'Coffee at Blue Door', '4.50', '2026-03-02', 'Blue Door Cafe');
        $this->spend($user, $checking, 'Coffee beans', '18.00', '2026-03-09', 'Corner Market');
        $this->spend($user, $checking, 'Hardware store', '42.10', '2026-03-15', 'Acme Hardware');

        return $checking;
    }
}
