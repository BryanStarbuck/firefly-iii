<?php

/*
 * SeedsSavings.php
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

namespace Tests\Machine\Subscriptions;

use Carbon\Carbon;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;

/**
 * An INVENTED household ledger for the subscriptions / piggy-bank / recurrence tests: a Northbank
 * checking account (last-4 4021) in USD, funded by an Acme LLC payroll deposit. No real data, ever.
 */
trait SeedsSavings
{
    protected function usd(User $user): TransactionCurrency
    {
        $usd = TransactionCurrency::query()->where('code', 'USD')->first();
        if (null === $usd) {
            $usd = TransactionCurrency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'enabled' => true]);
        }
        $group = $user->userGroup;
        if (0 === $group->currencies()->where('transaction_currencies.id', $usd->id)->count()) {
            $group->currencies()->detach();
            $group->currencies()->attach($usd->id, ['group_default' => true]);
        }

        return $usd;
    }

    protected function checking(User $user, string $name = 'Northbank Checking 4021'): Account
    {
        auth()->setUser($user);
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($user);

        return $factory->create([
            'name'              => $name,
            'account_type_name' => 'asset',
            'account_role'      => 'defaultAsset',
            'currency_id'       => $this->usd($user)->id,
            'active'            => true,
            'iban'              => null,
            'virtual_balance'   => '0',
            'opening_balance'   => '',
            'notes'             => '',
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function transaction(User $user, string $type, Account $account, string $description, string $amount, string $date, string $other, array $extra = []): TransactionGroup
    {
        auth()->setUser($user);
        /** @var TransactionGroupFactory $factory */
        $factory = app(TransactionGroupFactory::class);
        $factory->setUser($user);
        $split   = [
            'type'          => $type,
            'date'          => Carbon::parse($date),
            'amount'        => $amount,
            'currency_code' => 'USD',
            'description'   => $description,
        ];
        if ('deposit' === $type) {
            $split += ['source_name' => $other, 'destination_id' => $account->id];
        }
        if ('withdrawal' === $type) {
            $split += ['source_id' => $account->id, 'destination_name' => $other];
        }

        return $factory->create(['user' => $user, 'user_group' => $user->userGroup, 'group_title' => null, 'transactions' => [$split + $extra]]);
    }

    protected function payroll(User $user, Account $account, string $amount = '500.00', string $date = '2026-09-01'): TransactionGroup
    {
        return $this->transaction($user, 'deposit', $account, 'Acme LLC payroll', $amount, $date, 'Acme LLC');
    }

    protected function bill(User $user, string $name, string $amount, string $date, string $freq = 'monthly', bool $active = true): Bill
    {
        auth()->setUser($user);
        /** @var BillRepositoryInterface $repo */
        $repo = app(BillRepositoryInterface::class);
        $repo->setUser($user);

        return $repo->store([
            'name'          => $name,
            'amount_min'    => $amount,
            'amount_max'    => $amount,
            'currency_code' => $this->usd($user)->code,
            'date'          => Carbon::parse($date),
            'repeat_freq'   => $freq,
            'skip'          => 0,
            'active'        => $active,
        ]);
    }

    protected function piggy(User $user, Account $account, string $name, string $target, string $saved = '0'): PiggyBank
    {
        auth()->setUser($user);
        /** @var PiggyBankRepositoryInterface $repo */
        $repo  = app(PiggyBankRepositoryInterface::class);
        $repo->setUser($user);
        $piggy = $repo->store([
            'name'                      => $name,
            'accounts'                  => [['account_id' => $account->id, 'current_amount' => null]],
            'target_amount'             => $target,
            'start_date'                => Carbon::parse('2026-01-01'),
            'target_date'               => Carbon::parse('2027-06-30'),
            'transaction_currency_code' => 'USD',
            'notes'                     => '',
        ]);
        if ('0' !== $saved) {
            DB::table('account_piggy_bank')->where('piggy_bank_id', $piggy->id)->where('account_id', $account->id)->update(['current_amount' => $saved]);
        }

        return $piggy->refresh();
    }

    /** @return array<string, string> table => content hash, for "a dry run changed nothing" */
    protected function hashTables(array $tables): array
    {
        $out = [];
        foreach ($tables as $table) {
            $out[$table] = hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()->all()));
        }

        return $out;
    }
}
