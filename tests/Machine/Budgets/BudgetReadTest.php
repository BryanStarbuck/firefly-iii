<?php

/*
 * BudgetReadTest.php
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
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.5 reads: /budgets, /budgets/{id}, /budgets/{id}/limits, /budget-period (composed,
 * limit null vs "0.00"), /budget-period/gaps, /available-budgets.
 *
 * @internal
 *
 * @coversNothing
 */
final class BudgetReadTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
    }

    public function testListsBudgetsWithActiveFilterAndAutoBudgetKeys(): void
    {
        $this->budget('Groceries');
        $this->budget('Dining');
        $this->budget('Old Hobby', false);

        $env = $this->envelope($this->machine('GET', '/budgets'));
        $this->assertTrue($env['ok']);
        $this->assertSame(['Groceries', 'Dining', 'Old Hobby'], array_column($env['data']['budgets'], 'name'), 'default order is Firefly\'s budget order, then id');
        $this->assertArrayHasKey('auto_budget_type', $env['data']['budgets'][0]);
        $this->assertArrayHasKey('auto_budget_currency_code', $env['data']['budgets'][0]);
        $this->assertArrayNotHasKey('links', $env['data']['budgets'][0]);
        $this->assertSame('read', $env['meta']['tier']);

        $active = $this->envelope($this->machine('GET', '/budgets', ['active' => 'true']));
        $this->assertSame(['Groceries', 'Dining'], array_column($active['data']['budgets'], 'name'));
        $inactive = $this->envelope($this->machine('GET', '/budgets', ['active' => 'false']));
        $this->assertSame(['Old Hobby'], array_column($inactive['data']['budgets'], 'name'));

        $byName = $this->envelope($this->machine('GET', '/budgets', ['order' => '-name', 'limit' => '2']));
        $this->assertSame(['Old Hobby', 'Groceries'], array_column($byName['data']['budgets'], 'name'));
        $this->assertTrue($byName['meta']['truncated']);
        $this->assertSame(2, $byName['meta']['limit_applied']);
    }

    public function testListRefusesUnknownArgumentsAndBadOrder(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/budgets', ['activ' => 'true']), 400, 'invalid_input');
        $this->assertSame(['activ'], $env['error']['details']['unknown']);
        $this->assertPlaneError($this->machine('GET', '/budgets', ['order' => 'spent']), 400, 'invalid_input');
    }

    public function testShowsOneBudgetByIdOrNameWithLimitsSpentAndLeft(): void
    {
        $groceries = $this->budget('Groceries');
        $this->limit($groceries, '2026-08-01', '2026-08-31', '400.00');
        $this->spend('2026-08-05', '120.50', $groceries);
        $this->spend('2026-08-20', '30.25', $groceries);
        $this->spend('2026-07-20', '99.00', $groceries); // outside the range

        $env = $this->envelope($this->machine('GET', '/budgets/'.$groceries->id, ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertSame('Groceries', $env['data']['budget']['name']);
        $this->assertSame('2026-08-01', $env['data']['start']);
        $this->assertCount(1, $env['data']['limits']);
        $limit = $env['data']['limits'][0];
        $this->assertSame('400.00', $limit['amount']);
        $this->assertSame('-150.75', $limit['spent']);
        $this->assertSame('249.25', $limit['left']);
        $this->assertSame($this->primary->code, $limit['currency_code']);
        $this->assertSame('2026-08-31', $limit['end']);

        $byName = $this->envelope($this->machine('GET', '/budgets/groceries', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertSame((string) $groceries->id, $byName['data']['budget']['id'], 'a name resolves case-insensitively');
    }

    public function testShowNotFoundAndHalfARange(): void
    {
        $this->assertPlaneError($this->machine('GET', '/budgets/9999'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/budgets/Nope'), 404, 'not_found');
        $this->budget('Groceries');
        $env = $this->assertPlaneError($this->machine('GET', '/budgets/Groceries', ['start' => '2026-08-01']), 400, 'invalid_input');
        $this->assertStringContainsString('end', $env['error']['hint']);
        $this->assertPlaneError($this->machine('GET', '/budgets/Groceries', ['start' => '2026-08-31', 'end' => '2026-08-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/budgets/Groceries', ['start' => '08/01/2026', 'end' => '2026-08-31']), 400, 'invalid_input');
    }

    public function testLimitsListsAllOrTheRange(): void
    {
        $b = $this->budget('Groceries');
        $this->limit($b, '2026-07-01', '2026-07-31', '350.00');
        $this->limit($b, '2026-08-01', '2026-08-31', '400.00');

        $all = $this->envelope($this->machine('GET', '/budgets/'.$b->id.'/limits'));
        $this->assertSame(['2026-08-01', '2026-07-01'], array_column($all['data']['limits'], 'start'), 'default order -start');
        $this->assertNull($all['data']['start']);

        $aug = $this->envelope($this->machine('GET', '/budgets/'.$b->id.'/limits', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertSame(['400.00'], array_column($aug['data']['limits'], 'amount'));
        $this->assertPlaneError($this->machine('GET', '/budgets/424242/limits'), 404, 'not_found');
    }

    public function testBudgetPeriodKeepsNoLimitAndZeroLimitApart(): void
    {
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $travel    = $this->budget('Travel');
        $this->budget('Old Hobby', false);
        $this->limit($groceries, '2026-08-01', '2026-08-31', '400.00');
        $this->limit($travel, '2026-08-01', '2026-08-31', '0'); // "I decided nothing"
        $this->available('2026-08-01', '2026-08-31', '3000.00');
        $this->spend('2026-08-03', '100.00', $groceries);
        $this->spend('2026-08-04', '45.50', $dining);
        $this->spend('2026-08-09', '12.00'); // no budget at all

        $env  = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertTrue($env['meta']['composed']);
        $data = $env['data'];
        $rows = [];
        foreach ($data['budgets'] as $row) {
            $rows[$row['name']] = $row;
        }
        $this->assertSame(['Groceries', 'Dining', 'Travel'], array_keys($rows), 'active budgets only');

        $this->assertSame('400.00', $rows['Groceries']['limit']);
        $this->assertSame('-100.00', $rows['Groceries']['spent']);
        $this->assertSame('300.00', $rows['Groceries']['left']);
        $this->assertSame((string) $groceries->id, $rows['Groceries']['budget_id']);

        $this->assertNull($rows['Dining']['limit'], 'no limit is null — never "0"');
        $this->assertNull($rows['Dining']['left']);
        $this->assertSame('-45.50', $rows['Dining']['spent']);

        $this->assertSame('0.00', $rows['Travel']['limit'], 'a limit of zero is "0.00" — a decision');
        $this->assertSame('0.00', $rows['Travel']['spent']);

        $this->assertSame(2, $data['budgets_with_limit']);
        $this->assertSame(1, $data['budgets_without_limit']);

        $this->assertCount(1, $data['totals']);
        $total = $data['totals'][0];
        $this->assertSame($this->primary->code, $total['currency_code']);
        $this->assertSame('3000.00', $total['available']);
        $this->assertSame('400.00', $total['budgeted_total']);
        $this->assertSame('-145.50', $total['spent_total']);
        $this->assertSame('2854.50', $total['left_to_spend']);
        $this->assertSame('2600.00', $total['left_to_budget']);
        $this->assertSame('254.50', $total['left_in_budgets']);

        foreach ($data['budgets'] as $row) {
            foreach (['spent', 'limit', 'left'] as $field) {
                $this->assertTrue(null === $row[$field] || is_string($row[$field]), 'amounts are strings or null, never JSON numbers');
            }
        }
    }

    public function testBudgetPeriodDefaultsToTheCurrentViewRange(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21 10:00:00'));
        $this->budget('Groceries');
        $env = $this->envelope($this->machine('GET', '/budget-period'));
        $this->assertSame('2026-09-01', $env['data']['start']);
        $this->assertSame('2026-09-30', $env['data']['end']);
        $this->assertSame('viewRange 1M', $env['data']['period_source']);
        $this->assertNull($env['data']['totals'][0]['available'], 'no available budget is null, not "0"');
        $this->assertNull($env['data']['totals'][0]['left_to_spend']);
    }

    public function testGapsFindSpendingWithNoLimitAndWithdrawalsWithNoBudget(): void
    {
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $travel    = $this->budget('Travel');
        $this->limit($groceries, '2026-08-01', '2026-08-31', '400.00');
        $this->spend('2026-08-03', '100.00', $groceries);  // has a limit: not a gap
        $this->spend('2026-08-04', '45.50', $dining);      // gap
        $this->spend('2026-08-05', '4.50', $dining);       // gap
        $this->spend('2026-08-06', '3.00', $travel);       // gap, small
        $this->spend('2026-08-09', '12.00');               // no budget
        $this->spend('2026-08-10', '8.00');                // no budget

        $env  = $this->envelope($this->machine('GET', '/budget-period/gaps', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $gaps = array_column($env['data']['budgets_without_limit'], null, 'name');
        $this->assertSame(['Dining', 'Travel'], array_keys($gaps));
        $this->assertSame('-50.00', $gaps['Dining']['spent']);
        $this->assertSame(2, $gaps['Dining']['count']);
        $this->assertNull($gaps['Dining']['limit']);
        $this->assertCount(1, $env['data']['without_budget']);
        $this->assertSame('-20.00', $env['data']['without_budget'][0]['spent']);
        $this->assertSame(2, $env['data']['without_budget'][0]['count']);

        $min = $this->envelope($this->machine('GET', '/budget-period/gaps', ['start' => '2026-08-01', 'end' => '2026-08-31', 'min_spent' => '10.00']));
        $this->assertSame(['Dining'], array_column($min['data']['budgets_without_limit'], 'name'));
        $this->assertSame('10', $min['data']['min_spent']);

        $this->assertPlaneError($this->machine('GET', '/budget-period/gaps', ['start' => '2026-08-01', 'end' => '2026-08-31', 'min_spent' => '1,000']), 400, 'invalid_input');
    }

    public function testAvailableBudgetsList(): void
    {
        $this->available('2026-07-01', '2026-07-31', '2900.00');
        $this->available('2026-08-01', '2026-08-31', '3000.00');
        $all = $this->envelope($this->machine('GET', '/available-budgets'));
        $this->assertSame(['2026-07-01', '2026-08-01'], array_column($all['data']['available_budgets'], 'start'));
        $this->assertSame(['2900.00', '3000.00'], array_column($all['data']['available_budgets'], 'amount'));

        $aug = $this->envelope($this->machine('GET', '/available-budgets', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertSame(['3000.00'], array_column($aug['data']['available_budgets'], 'amount'));
        $this->assertSame('2026-08-31', $aug['data']['available_budgets'][0]['end']);
    }
}
