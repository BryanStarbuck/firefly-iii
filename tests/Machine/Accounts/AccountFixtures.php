<?php

/*
 * AccountFixtures.php
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

namespace Tests\Machine\Accounts;

use Carbon\Carbon;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;

/**
 * Invented fixtures only (CLAUDE.md private-data boundary): entities household / acme_llc,
 * banks Northbank / Meridian, last-4 4021 / 7734.
 */
trait AccountFixtures
{
    protected User $user;

    /** Create an account through the plane itself (plan, then apply); returns the rendered account. */
    protected function makeAccount(array $body): array
    {
        $plan  = $this->envelope($this->machine('POST', '/accounts', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $apply = $this->envelope($this->machine('POST', '/accounts', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));

        return $apply['data']['account'];
    }

    protected function checking(string $name = 'Household · Northbank Checking ••4021', ?string $opening = '1000.00'): array
    {
        $body = ['name' => $name, 'type' => 'asset', 'account_role' => 'defaultAsset'];
        if (null !== $opening) {
            $body += ['opening_balance' => $opening, 'opening_balance_date' => '2026-01-01'];
        }

        return $this->makeAccount($body);
    }

    protected function expense(string $name = 'Corner Grocer'): array
    {
        return $this->makeAccount(['name' => $name, 'type' => 'expense']);
    }

    /** A withdrawal of $amount from $source to $destination on $date; returns the journal id. */
    protected function withdrawal(int $source, int $destination, string $amount, string $date, string $description = 'Groceries'): int
    {
        auth()->setUser($this->user);

        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($this->user);
        $currency = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);

        /** @var TransactionGroup $group */
        $group    = $factory->create([
            'user'         => $this->user,
            'user_group'   => $this->user->userGroup,
            'group_title'  => null,
            'transactions' => [[
                'user'           => $this->user,
                'user_group'     => $this->user->userGroup,
                'type'           => 'withdrawal',
                'date'           => Carbon::parse($date),
                'order'          => 0,
                'currency_id'    => $currency->id,
                'amount'         => $amount,
                'description'    => $description,
                'source_id'      => $source,
                'destination_id' => $destination,
                'reconciled'     => false,
            ]],
        ]);

        return (int) $group->transactionJournals()->first()->id;
    }

    /** A transfer of $amount ($inCurrency, the source's currency) from $source to $destination, with $foreignAmount in the primary currency; returns the journal id. */
    protected function transfer(int $source, int $destination, string $amount, string $date, TransactionCurrency $inCurrency, string $foreignAmount): int
    {
        auth()->setUser($this->user);

        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($this->user);
        $primary = Amount::getPrimaryCurrencyByUserGroup($this->user->userGroup);

        /** @var TransactionGroup $group */
        $group   = $factory->create([
            'user'         => $this->user,
            'user_group'   => $this->user->userGroup,
            'group_title'  => null,
            'transactions' => [[
                'user'                => $this->user,
                'user_group'          => $this->user->userGroup,
                'type'                => 'transfer',
                'date'                => Carbon::parse($date),
                'order'               => 0,
                'currency_id'         => $inCurrency->id,
                'amount'              => $amount,
                'foreign_currency_id' => $primary->id,
                'foreign_amount'      => $foreignAmount,
                'description'         => 'Moved home',
                'source_id'           => $source,
                'destination_id'      => $destination,
                'reconciled'          => false,
            ]],
        ]);

        return (int) $group->transactionJournals()->first()->id;
    }

    protected function accountModel(int|string $id): Account
    {
        return Account::query()->findOrFail((int) $id);
    }
}
