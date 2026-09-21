<?php

/*
 * TransactionFixtures.php
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

namespace Tests\Machine\Transactions;

use FireflyIII\Factory\AccountFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * An invented household (CLAUDE.md: no real financial data): Northbank checking ending 4021,
 * Meridian savings ending 7734, a couple of payees, categories and budgets. USD is primary.
 *
 * @mixin MachineTestCase
 */
trait TransactionFixtures
{
    protected User $user;
    protected Account $checking;
    protected Account $savings;

    protected function setUpHousehold(): void
    {
        $this->user     = $this->operatorUser();
        $usd            = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        $usd->enabled   = true;
        $usd->save();
        $this->user->userGroup->currencies()->syncWithoutDetaching([$usd->id => ['group_default' => true]]);
        auth()->setUser($this->user);
        $this->checking = $this->account('Northbank Checking 4021', 'asset');
        $this->savings  = $this->account('Meridian Savings 7734', 'asset');
    }

    protected function account(string $name, string $type): Account
    {
        /** @var AccountFactory $factory */
        $factory = app(AccountFactory::class);
        $factory->setUser($this->user);
        $usd     = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();

        return $factory->create([
            'name'              => $name,
            'account_type_name' => $type,
            'account_role'      => 'asset' === $type ? 'defaultAsset' : null,
            'currency_id'       => $usd->id,
            'active'            => true,
            'iban'              => null,
        ]);
    }

    protected function category(string $name): Category
    {
        return Category::query()->create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => $name]);
    }

    protected function budget(string $name): Budget
    {
        return Budget::query()->create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => $name, 'active' => true, 'order' => 1]);
    }

    /**
     * Store a withdrawal through the plane (dry run, then apply) and return its group id.
     *
     * @param array<string, mixed> $extra
     */
    protected function spend(string $amount, string $payee, string $date = '2026-09-01', array $extra = []): int
    {
        $body = ['transactions' => [array_merge([
            'type'             => 'withdrawal',
            'date'             => $date,
            'amount'           => $amount,
            'description'      => 'Purchase at '.$payee,
            'source_id'        => (string) $this->checking->id,
            'destination_name' => $payee,
        ], $extra)]];
        $env  = $this->applied('POST', '/transactions', $body);

        return (int) $env['data']['group_id'];
    }

    /**
     * Dry run, then apply with the token; returns the applied envelope (asserting it is ok).
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    protected function applied(string $method, string $path, array $body = []): array
    {
        $plan = $this->envelope($this->machine($method, $path, $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $done = $this->envelope($this->machine($method, $path, array_merge($body, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']])));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertFalse($done['data']['dry_run']);

        return $done;
    }

    /** A content hash of the ledger tables a transaction write touches. */
    protected function ledgerHash(): string
    {
        $out = [];
        foreach (['transaction_groups', 'transaction_journals', 'transactions', 'journal_meta', 'category_transaction_journal', 'budget_transaction_journal', 'tag_transaction_journal', 'accounts', 'categories', 'tags', 'journal_links', 'notes', 'machine_operations'] as $table) {
            $out[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all();
        }

        return hash('sha256', (string) json_encode($out));
    }
}
