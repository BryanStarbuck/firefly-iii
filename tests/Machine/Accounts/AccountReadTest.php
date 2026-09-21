<?php

/*
 * AccountReadTest.php
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

use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.2 — the account reads: list (type, active, search, order, limit), show by id
 * and by name, balance as_of per currency, properties, and the account's transactions.
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountReadTest extends MachineTestCase
{
    use AccountFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    public function testListFiltersByTypeAndCarriesBalances(): void
    {
        $checking = $this->checking();
        $this->makeAccount(['name' => 'Household · Meridian Savings ••7734', 'type' => 'asset', 'account_role' => 'savingAsset']);
        $grocer   = $this->expense();
        $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '42.50', '2026-02-03');

        $env = $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset']));
        $this->assertTrue($env['ok']);
        $this->assertSame('read', $env['meta']['tier']);
        $names = array_column($env['data']['accounts'], 'name');
        $this->assertSame(['Household · Meridian Savings ••7734', 'Household · Northbank Checking ••4021'], $names, 'default order is by name');
        $row   = $env['data']['accounts'][1];
        $this->assertSame('957.50', $row['current_balance'], 'Firefly\'s balance: 1000.00 opening − 42.50');
        $this->assertIsString($row['currency_code']);
        $this->assertSame('asset', $row['type']);
        $this->assertSame('defaultAsset', $row['account_role']);
        $this->assertArrayNotHasKey('links', $row);

        $expense = $this->envelope($this->machine('GET', '/accounts', ['type' => 'expense']));
        $this->assertSame(['Corner Grocer'], array_column($expense['data']['accounts'], 'name'));

        $ordered = $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset', 'order' => '-current_balance']));
        $this->assertSame('Household · Northbank Checking ••4021', $ordered['data']['accounts'][0]['name']);

        $search  = $this->envelope($this->machine('GET', '/accounts', ['type' => 'all', 'search' => 'meridian']));
        $this->assertSame(['Household · Meridian Savings ••7734'], array_column($search['data']['accounts'], 'name'));

        $limited = $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset', 'limit' => '1']));
        $this->assertCount(1, $limited['data']['accounts']);
        $this->assertTrue($limited['meta']['truncated']);
        $this->assertSame(1, $limited['meta']['next_offset']);

        $noBal   = $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset', 'with_balances' => 'false']));
        $this->assertArrayNotHasKey('current_balance', $noBal['data']['accounts'][0]);

        $asOf    = $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset', 'as_of' => '2026-01-15', 'search' => 'northbank']));
        $this->assertSame('1000.00', $asOf['data']['accounts'][0]['current_balance'], 'before the withdrawal');
    }

    public function testActiveFilter(): void
    {
        $checking = $this->checking(opening: null);
        $this->accountModel($checking['id'])->update(['active' => false]);
        $this->assertCount(0, $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset']))['data']['accounts']);
        $this->assertCount(1, $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset', 'active' => 'false']))['data']['accounts']);
        $this->assertCount(1, $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset', 'active' => 'all']))['data']['accounts']);
    }

    public function testListRefusesBadArguments(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/accounts', ['start_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertSame(['start_date'], $env['error']['details']['unknown']);
        $this->assertPlaneError($this->machine('GET', '/accounts', ['type' => 'piggy']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/accounts', ['order' => 'iban']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/accounts', ['as_of' => '2026-13-01']), 400, 'invalid_input');
    }

    public function testShowByIdAndByName(): void
    {
        $checking = $this->checking();
        $byId     = $this->envelope($this->machine('GET', '/accounts/'.$checking['id']));
        $this->assertSame($checking['id'], $byId['data']['account']['id']);
        $byName   = $this->envelope($this->machine('GET', '/accounts/'.rawurlencode('household · northbank checking ••4021')));
        $this->assertSame($checking['id'], $byName['data']['account']['id'], 'case-insensitive name resolution');

        $this->assertPlaneError($this->machine('GET', '/accounts/99999'), 404, 'not_found');
        $env = $this->assertPlaneError($this->machine('GET', '/accounts/Nowhere'), 404, 'not_found');
        $this->assertSame('Nowhere', $env['error']['details']['name']);
    }

    public function testAnAmbiguousNameListsTheCandidates(): void
    {
        $this->checking('Northbank');
        $this->expense('Northbank');
        $env = $this->assertPlaneError($this->machine('GET', '/accounts/Northbank'), 400, 'invalid_input');
        $this->assertCount(2, $env['error']['details']['candidates']);
    }

    public function testAnotherAdministrationsAccountIsNotFound(): void
    {
        $checking = $this->checking();
        $this->accountModel($checking['id'])->update(['user_group_id' => 999]);
        $this->assertPlaneError($this->machine('GET', '/accounts/'.$checking['id']), 404, 'not_found');
    }

    public function testBalanceAsOfIsPerCurrency(): void
    {
        $checking = $this->checking();
        $grocer   = $this->expense();
        $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '100.25', '2026-03-10');

        $before = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/balance', ['as_of' => '2026-03-09']));
        $this->assertSame('1000.00', $before['data']['balance']);
        $this->assertSame('2026-03-09', $before['data']['as_of']);
        $this->assertCount(1, $before['data']['balances']);
        $this->assertSame($before['data']['currency_code'], $before['data']['balances'][0]['currency_code']);

        $after  = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/balance', ['as_of' => '2026-03-10']));
        $this->assertSame('899.75', $after['data']['balance'], 'the end of that day includes that day');

        $this->assertPlaneError($this->machine('GET', '/accounts/'.$checking['id'].'/balance', ['date' => '2026-03-10']), 400, 'invalid_input');
    }

    public function testProperties(): void
    {
        $checking = $this->makeAccount(['name' => 'Acme LLC · Northbank Checking ••7734', 'type' => 'asset', 'account_role' => 'defaultAsset', 'iban' => 'NL02ABNA0123456789', 'opening_balance' => '250.00', 'opening_balance_date' => '2026-01-01']);
        $grocer   = $this->expense();
        $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '10.00', '2026-02-01');

        $env = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/properties'));
        $d   = $env['data'];
        $this->assertSame(2, $d['journal_count'], 'opening balance + one withdrawal');
        $this->assertSame(2, $d['uncleared_count']);
        $this->assertSame('2026-01-01', $d['first_transaction']);
        $this->assertSame('2026-02-01', $d['last_transaction']);
        $this->assertSame('6789', $d['iban_last4']);
        $this->assertSame('250.00', $d['opening_balance']);
        $this->assertSame('2026-01-01', $d['opening_balance_date']);
        $this->assertNull($d['liability']);
        $this->assertNull($d['last_reconciled_date']);

        $loan = $this->makeAccount(['name' => 'Household · Meridian Mortgage', 'type' => 'mortgage', 'liability_direction' => 'credit', 'interest' => '4.25', 'interest_period' => 'monthly']);
        $env  = $this->envelope($this->machine('GET', '/accounts/'.$loan['id'].'/properties'));
        $this->assertSame('mortgage', $env['data']['liability']['liability_type']);
        $this->assertSame('credit', $env['data']['liability']['liability_direction']);
        $this->assertSame('4.25', $env['data']['liability']['interest']);
    }

    public function testTransactionsInAPeriod(): void
    {
        $checking = $this->checking();
        $grocer   = $this->expense();
        $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '10.00', '2026-02-01', 'Feb groceries');
        $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '20.00', '2026-03-01', 'Mar groceries');

        $env  = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['start' => '2026-02-01', 'end' => '2026-03-31', 'type' => 'withdrawal']));
        $rows = $env['data']['transactions'];
        $this->assertCount(2, $rows);
        $this->assertSame('Mar groceries', $rows[0]['transactions'][0]['description'], 'newest first');
        $this->assertSame('withdrawal', $rows[0]['transactions'][0]['type']);

        $all  = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions'));
        $this->assertCount(3, $all['data']['transactions'], 'type all includes the opening balance');

        $page = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['limit' => '1', 'offset' => '1']));
        $this->assertCount(1, $page['data']['transactions']);
        $this->assertSame('Feb groceries', $page['data']['transactions'][0]['transactions'][0]['description']);

        $this->assertPlaneError($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['start' => '2026-04-01', 'end' => '2026-03-01']), 400, 'invalid_input');
    }
}
