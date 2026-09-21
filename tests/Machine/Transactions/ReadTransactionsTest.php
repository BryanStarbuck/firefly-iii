<?php

/*
 * ReadTransactionsTest.php
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

use FireflyIII\Models\TransactionJournal;
use Tests\Machine\MachineTestCase;

/**
 * GET /transactions (every filter), /transactions/export, /transactions/{id}, /{id}/links,
 * /transaction-journals/{id} — apis.mdx §8.3, §5.5 (limits reported), §14.1 (money strings).
 *
 * @internal
 *
 * @coversNothing
 */
final class ReadTransactionsTest extends MachineTestCase
{
    use TransactionFixtures;

    private int $coffee;
    private int $groceries;
    private int $rent;
    private int $salary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousehold();
        $this->enableWrites();
        $dining          = $this->category('Dining');
        $this->budget('Food');
        $this->coffee    = $this->spend('4.50', 'Blue Bottle', '2026-09-02', ['category_id' => (string) $dining->id, 'tags' => ['work']]);
        $this->groceries = $this->spend('82.10', 'Corner Market', '2026-09-05', ['budget_name' => 'Food']);
        $this->rent      = $this->spend('1450.00', 'Landlord LLC', '2026-08-01');
        $this->salary    = (int) $this->applied('POST', '/transactions', ['transactions' => [[
            'type' => 'deposit', 'date' => '2026-09-01', 'amount' => '3200.00', 'description' => 'Salary acme_llc',
            'source_name' => 'Acme LLC', 'destination_id' => (string) $this->checking->id,
        ]]])['data']['group_id'];
    }

    /** @return list<int> group ids of a list response */
    private function ids(array $env): array
    {
        $this->assertTrue($env['ok'], (string) json_encode($env));

        return array_map(static fn (array $g): int => (int) $g['id'], $env['data']['transactions']);
    }

    public function testTheListIsNewestFirstWithStandardMeta(): void
    {
        $env = $this->envelope($this->machine('GET', '/transactions'));
        $this->assertSame([$this->groceries, $this->coffee, $this->salary, $this->rent], $this->ids($env));
        $this->assertSame(4, $env['data']['total']);
        $this->assertFalse($env['meta']['truncated']);
        $this->assertSame(200, $env['meta']['limit_applied']);
        $this->assertSame('-date', $env['meta']['order']);
        $this->assertSame('USD', $env['meta']['primaryCurrency']);
        $split = $env['data']['transactions'][0]['transactions'][0];
        $this->assertSame('82.10', $split['amount']);
        $this->assertIsString($split['amount']);
    }

    public function testFilters(): void
    {
        $get = fn (array $q): array => $this->ids($this->envelope($this->machine('GET', '/transactions', $q)));
        $this->assertSame([$this->groceries, $this->coffee, $this->salary], $get(['start' => '2026-09-01', 'end' => '2026-09-30']));
        $this->assertSame([$this->salary], $get(['type' => 'deposit']));
        $this->assertSame([$this->coffee], $get(['category_name' => 'dining']));
        $this->assertSame([$this->groceries, $this->salary, $this->rent], $get(['without_category' => 'true']));
        // without_budget means withdrawals without a budget
        $this->assertSame([$this->coffee, $this->rent], $get(['without_budget' => 'true']));
        $this->assertSame([$this->groceries], $get(['budget_name' => 'Food']));
        $this->assertSame([$this->coffee], $get(['tag' => 'work']));
        $this->assertSame([$this->groceries, $this->salary, $this->rent], $get(['without_tag' => 'true']));
        $this->assertSame([$this->groceries, $this->rent], $get(['min_amount' => '50', 'type' => 'withdrawal']));
        $this->assertSame([$this->coffee], $get(['max_amount' => '4.50']));
        $this->assertSame([$this->coffee], $get(['search' => 'blue bottle']));
        $this->assertSame([$this->groceries, $this->coffee, $this->salary, $this->rent], $get(['account_name' => 'Northbank Checking 4021']));
        $this->assertSame([$this->groceries, $this->coffee, $this->salary, $this->rent], $get(['account_ids' => [(string) $this->checking->id]]));
        $this->assertSame([], $get(['account_id' => (string) $this->savings->id]));
        $this->assertSame([$this->groceries, $this->coffee, $this->salary, $this->rent], $get(['currency_code' => 'USD']));
        $this->assertSame([], $get(['reconciled' => 'true']));
        $this->assertSame([$this->rent, $this->salary, $this->coffee, $this->groceries], $get(['order' => 'date']));
    }

    public function testLimitIsClampedAndReported(): void
    {
        $env = $this->envelope($this->machine('GET', '/transactions', ['limit' => '2']));
        $this->assertSame([$this->groceries, $this->coffee], $this->ids($env));
        $this->assertTrue($env['meta']['truncated']);
        $this->assertSame(2, $env['meta']['limit_applied']);
        $this->assertSame(2, $env['meta']['next_offset']);
        $env = $this->envelope($this->machine('GET', '/transactions', ['limit' => '2', 'offset' => '2']));
        $this->assertSame([$this->salary, $this->rent], $this->ids($env));
        $env = $this->envelope($this->machine('GET', '/transactions', ['limit' => '999999']));
        $this->assertSame(5000, $env['meta']['limit_applied']);
    }

    public function testFilterRefusals(): void
    {
        $this->assertPlaneError($this->machine('GET', '/transactions', ['start_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['start' => '2026-09-30', 'end' => '2026-09-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['start' => 'last month']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['category_name' => 'Dining', 'without_category' => 'true']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['min_amount' => '-5']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['type' => 'expense']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['order' => 'amount']), 400, 'invalid_input');
        $env = $this->assertPlaneError($this->machine('GET', '/transactions', ['account_name' => 'Nowhere Bank']), 404, 'not_found');
        $this->assertNotEmpty($env['error']['hint']);
        $this->assertPlaneError($this->machine('GET', '/transactions', ['currency_code' => 'XYZ']), 404, 'not_found');
    }

    public function testAmbiguousNamesCarryTheCandidates(): void
    {
        $this->category('Travel');
        $this->category('travel');
        $env = $this->assertPlaneError($this->machine('GET', '/transactions', ['category_name' => 'TRAVEL']), 400, 'invalid_input');
        $this->assertCount(2, $env['error']['details']['candidates']);
    }

    public function testShowAGroupWithItsLinks(): void
    {
        $env   = $this->envelope($this->machine('GET', '/transactions/'.$this->coffee));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($this->coffee, $env['data']['transaction']['id']);
        $this->assertSame('Dining', $env['data']['transaction']['transactions'][0]['category_name']);
        $this->assertSame([], $env['data']['links']);
        $this->assertPlaneError($this->machine('GET', '/transactions/999999'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/transactions/abc'), 400, 'invalid_input');
    }

    public function testShowOneJournal(): void
    {
        $journal = TransactionJournal::query()->where('transaction_group_id', $this->rent)->firstOrFail();
        $env     = $this->envelope($this->machine('GET', '/transaction-journals/'.$journal->id));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame((string) $this->rent, $env['data']['group_id']);
        $this->assertSame('1450.00', $env['data']['transaction_journal']['amount']);
        $this->assertPlaneError($this->machine('GET', '/transaction-journals/999999'), 404, 'not_found');
    }

    public function testLinksList(): void
    {
        $env = $this->envelope($this->machine('GET', '/transactions/'.$this->coffee.'/links'));
        $this->assertTrue($env['ok']);
        $this->assertSame([], $env['data']['links']);
    }

    public function testExportIsFireflysCsvWithTheSameFilters(): void
    {
        $env = $this->envelope($this->machine('GET', '/transactions/export', ['without_category' => 'true', 'format' => 'csv']));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame(3, $env['data']['rows']);
        $lines = array_values(array_filter(explode("\n", $env['data']['csv'])));
        $this->assertStringStartsWith('user_id,group_id,journal_id', $lines[0]);
        $this->assertCount(4, $lines);
        $this->assertStringNotContainsString('Blue Bottle', $env['data']['csv']);
        $this->assertStringContainsString("'-1450", $env['data']['csv'], 'Firefly\'s exporter signs withdrawals (and guards the cell against formula injection)');
        $this->assertPlaneError($this->machine('GET', '/transactions/export', ['format' => 'xlsx']), 400, 'invalid_input');
    }
}
