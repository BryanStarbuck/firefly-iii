<?php

/*
 * BudgetWriteTest.php
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

use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.5 writes, through the real write protocol (§7): dry run by default and
 * byte-identical tables; token; apply; stale token → conflict with the new counts; undo.
 *
 * @internal
 *
 * @coversNothing
 */
final class BudgetWriteTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
        $this->enableWrites();
    }

    public function testWritesNeedTheWriteTier(): void
    {
        config(['machine.allow_write' => false]);
        $b = $this->budget('Groceries');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00']), 403, 'write_disabled');
    }

    public function testSetLimitIsADryRunThenSetsNotAdds(): void
    {
        $b      = $this->budget('Groceries');
        $before = $this->tableHash('budget_limits');
        $body   = ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00'];

        $plan   = $this->envelope($this->machine('PUT', '/budgets/Groceries/limits', $body));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame('created', $plan['data']['action']);
        $this->assertSame('650.00', $plan['data']['limits'][0]['amount']);
        $this->assertNull($plan['data']['limits'][0]['previous']);
        $this->assertSame($before, $this->tableHash('budget_limits'), 'the dry run wrote nothing');
        $this->assertSame(0, DB::table('machine_operations')->count());

        $this->assertPlaneError($this->machine('PUT', '/budgets/Groceries/limits', $body + ['dry_run' => false]), 403, 'forbidden');

        $apply  = $this->envelope($this->machine('PUT', '/budgets/Groceries/limits', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertIsInt($apply['data']['operation_id']);
        $limit  = BudgetLimit::query()->where('budget_id', $b->id)->sole();
        $this->assertSame(0, bccomp('650', (string) $limit->amount, 2));

        // SET, not add: a second call replaces the amount of the same limit
        $again  = $this->planAndApply('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '500']);
        $this->assertSame(['updated' => 1], $again['data']['changes']);
        $this->assertSame('650.00', $again['data']['limits'][0]['previous']);
        $this->assertSame('500.00', $again['data']['limits'][0]['amount']);
        $this->assertSame(1, BudgetLimit::query()->where('budget_id', $b->id)->count());
        $this->assertSame(0, bccomp('500', (string) BudgetLimit::query()->where('budget_id', $b->id)->value('amount'), 2));

        // the same amount again is unchanged, and "0" is a real limit of zero
        $same   = $this->envelope($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '500.00']));
        $this->assertSame(['unchanged' => 1], $same['data']['changes']);
        $zero   = $this->planAndApply('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '0']);
        $this->assertSame('0.00', $zero['data']['limits'][0]['amount']);
    }

    public function testSetLimitRefusals(): void
    {
        $b = $this->budget('Groceries');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => 650]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '-5.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '5.001']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'amount' => '5.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '5.00', 'currency_code' => 'XXQ']), 404, 'not_found');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', ['start_date' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '5.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/424242/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '5.00']), 404, 'not_found');
        $this->assertSame(0, BudgetLimit::query()->count());
    }

    public function testAStaleTokenIsRefusedWithTheNewCounts(): void
    {
        $b    = $this->budget('Groceries');
        $body = ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00'];
        $plan = $this->envelope($this->machine('PUT', '/budgets/'.$b->id.'/limits', $body));
        // the world moves: someone sets the limit in the browser
        $this->limit($b, '2026-10-01', '2026-10-31', '300.00');
        $env  = $this->assertPlaneError($this->machine('PUT', '/budgets/'.$b->id.'/limits', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(['updated' => 1], $env['error']['details']['changes']);
        $this->assertSame(0, bccomp('300', (string) BudgetLimit::query()->value('amount'), 2), 'nothing was written');
    }

    public function testDeleteLimitLeavesTheBudgetUnbudgetedAndIsIdempotent(): void
    {
        $b     = $this->budget('Groceries');
        $limit = $this->limit($b, '2026-10-01', '2026-10-31', '650.00');
        $plan  = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id));
        $this->assertSame(['deleted' => 1], $plan['data']['changes']);
        $this->assertSame(1, BudgetLimit::query()->count());

        $apply = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertSame(1, $apply['data']['deleted']);
        $this->assertTrue($apply['data']['unbudgeted']);
        $this->assertStringContainsString('UNBUDGETED', $apply['data']['message']);
        $this->assertSame(0, BudgetLimit::query()->count());
        $this->assertSame(1, Budget::query()->count(), 'the budget itself is untouched');

        $gone  = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id));
        $this->assertTrue($gone['ok']);
        $this->assertSame(0, $gone['data']['deleted']);

        $period = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertNull($period['data']['budgets'][0]['limit']);

        // undo brings the limit back
        $last  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('DELETE /budgets/{id}/limits/{limit_id}', $last['data']['route']);
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $this->assertSame(1, BudgetLimit::query()->count());
    }

    public function testSetAvailableCreatesThenUpdates(): void
    {
        $before = $this->tableHash('available_budgets');
        $plan   = $this->envelope($this->machine('PUT', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '3100.00']));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame($before, $this->tableHash('available_budgets'));

        $this->planAndApply('PUT', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '3100.00']);
        $this->assertSame(1, AvailableBudget::query()->count());
        $update = $this->planAndApply('PUT', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '3200.50']);
        $this->assertSame(['updated' => 1], $update['data']['changes']);
        $this->assertSame('3100.00', $update['data']['available_budget']['previous']);
        $this->assertSame('3200.50', $update['data']['available_budget']['amount']);
        $this->assertSame(1, AvailableBudget::query()->count(), 'set, not a second row');

        $period = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame('3200.50', $period['data']['totals'][0]['available']);
    }

    public function testCopyPreviousCopiesLastMonthsLimits(): void
    {
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $travel    = $this->budget('Travel');
        $this->limit($groceries, '2026-09-01', '2026-09-30', '600.00');
        $this->limit($dining, '2026-09-01', '2026-09-30', '150.00');
        $this->limit($dining, '2026-10-01', '2026-10-31', '100.00'); // replaced (set, not add)

        $before = $this->tableHash('budget_limits');
        $plan   = $this->envelope($this->machine('POST', '/budget-period/copy-previous', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame(['created' => 1, 'updated' => 1], $plan['data']['changes']);
        $this->assertSame('2026-09-01', $plan['data']['previous_start']);
        $this->assertSame('2026-09-30', $plan['data']['previous_end']);
        $this->assertSame([['budget_id' => (string) $travel->id, 'name' => 'Travel']], $plan['data']['left_unbudgeted']);
        $this->assertSame(['Travel'], array_column($plan['data']['skipped'], 'name'));
        $this->assertSame($before, $this->tableHash('budget_limits'));

        $apply  = $this->planAndApply('POST', '/budget-period/copy-previous', ['start' => '2026-10-01', 'end' => '2026-10-31']);
        $rows   = array_column($apply['data']['limits'], null, 'name');
        $this->assertSame('600.00', $rows['Groceries']['amount']);
        $this->assertSame('150.00', $rows['Dining']['amount']);
        $this->assertSame('100.00', $rows['Dining']['previous']);
        $this->assertSame(2, BudgetLimit::query()->whereDate('start_date', '2026-10-01')->count());

        // a selection by name only touches that budget
        $only   = $this->envelope($this->machine('POST', '/budget-period/copy-previous', ['start' => '2026-11-01', 'end' => '2026-11-30', 'budget_names' => ['Groceries']]));
        $this->assertSame(['Groceries'], array_column($only['data']['limits'], 'name'));
        $this->assertPlaneError($this->machine('POST', '/budget-period/copy-previous', ['start' => '2026-11-01', 'end' => '2026-11-30', 'budget_ids' => ['999999']]), 404, 'not_found');
    }

    public function testSetAverageUsesAverageSpentOverNPeriods(): void
    {
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $this->spend('2026-07-10', '300.00', $groceries);
        $this->spend('2026-08-10', '200.00', $groceries);
        $this->spend('2026-09-10', '100.00', $groceries);
        $this->spend('2026-09-12', '0.01', $groceries);
        $this->spend('2026-06-10', '999.00', $groceries); // outside the three periods

        $plan  = $this->envelope($this->machine('POST', '/budget-period/set-average', ['start' => '2026-10-01', 'end' => '2026-10-31', 'periods' => 3]));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $row   = $plan['data']['limits'][0];
        $this->assertSame('Groceries', $row['name']);
        $this->assertSame('200.00', $row['amount'], '(300 + 200 + 100.01) / 3 = 200.003… at two places');
        $this->assertSame('-600.01', $row['spent_total']);
        $this->assertSame(['2026-09-01', '2026-08-01', '2026-07-01'], array_column($row['history'], 'start'));
        $this->assertSame(['Dining'], array_column($plan['data']['skipped'], 'name'), 'nothing spent: left alone, not set to 0.00');
        $this->assertSame([['budget_id' => (string) $dining->id, 'name' => 'Dining']], $plan['data']['left_unbudgeted']);
        $this->assertSame(0, BudgetLimit::query()->count());

        $this->planAndApply('POST', '/budget-period/set-average', ['start' => '2026-10-01', 'end' => '2026-10-31', 'periods' => 3]);
        $this->assertSame(0, bccomp('200', (string) BudgetLimit::query()->where('budget_id', $groceries->id)->value('amount'), 2));

        $this->assertPlaneError($this->machine('POST', '/budget-period/set-average', ['start' => '2026-10-01', 'end' => '2026-10-31', 'periods' => 0]), 400, 'invalid_input');
    }

    public function testResetRemovesEveryLimitInThePeriod(): void
    {
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $this->limit($groceries, '2026-10-01', '2026-10-31', '600.00');
        $this->limit($dining, '2026-10-01', '2026-10-31', '150.00');
        $this->limit($dining, '2026-09-01', '2026-09-30', '150.00'); // another period, kept

        $plan  = $this->envelope($this->machine('POST', '/budget-period/reset', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame(['deleted' => 2], $plan['data']['changes']);
        $this->assertCount(2, $plan['data']['left_unbudgeted']);
        $this->assertSame(3, BudgetLimit::query()->count());

        $this->planAndApply('POST', '/budget-period/reset', ['start' => '2026-10-01', 'end' => '2026-10-31']);
        $this->assertSame(1, BudgetLimit::query()->count());
        $this->assertStringContainsString('UNBUDGETED', $plan['data']['message']);
    }

    public function testTheCeilingReportsTheRealCount(): void
    {
        $this->budget('Groceries');
        $this->budget('Dining');
        foreach (Budget::query()->get() as $b) {
            $this->limit($b, '2026-09-01', '2026-09-30', '10.00');
        }
        $env = $this->assertPlaneError($this->machine('POST', '/budget-period/copy-previous', ['start' => '2026-10-01', 'end' => '2026-10-31', 'max_changes' => 1]), 409, 'conflict');
        $this->assertSame(2, $env['error']['details']['change_count']);
    }

    public function testCreateAndUpdateABudgetWithAnAutoBudget(): void
    {
        $plan  = $this->envelope($this->machine('POST', '/budgets', ['name' => 'Groceries', 'auto_budget_type' => 'reset', 'auto_budget_amount' => '450.00', 'auto_budget_period' => 'monthly']));
        $this->assertSame(1, $plan['data']['changes']['created']);
        $this->assertSame(0, Budget::query()->count());

        $apply = $this->planAndApply('POST', '/budgets', ['name' => 'Groceries', 'auto_budget_type' => 'reset', 'auto_budget_amount' => '450.00', 'auto_budget_period' => 'monthly']);
        $this->assertSame('Groceries', $apply['data']['budget']['name']);
        $this->assertSame('reset', $apply['data']['budget']['auto_budget_type']);
        $this->assertSame('450.00', $apply['data']['budget']['auto_budget_amount']);
        $this->assertSame($this->primary->code, $apply['data']['budget']['auto_budget_currency_code']);
        $budget = Budget::query()->sole();
        $this->assertSame(1, AutoBudget::query()->where('budget_id', $budget->id)->count());

        $this->assertPlaneError($this->machine('POST', '/budgets', ['name' => 'Groceries']), 409, 'conflict');
        $this->assertPlaneError($this->machine('POST', '/budgets', ['name' => 'Dining', 'auto_budget_type' => 'reset']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/budgets', ['name' => 'Dining', 'auto_budget_type' => 'weekly']), 400, 'invalid_input');

        $renamed = $this->planAndApply('PUT', '/budgets/'.$budget->id, ['name' => 'Food', 'auto_budget_amount' => '500.00']);
        $this->assertSame('Food', $renamed['data']['budget']['name']);
        $this->assertSame('500.00', $renamed['data']['budget']['auto_budget_amount']);

        $off = $this->planAndApply('PUT', '/budgets/Food', ['auto_budget_type' => 'none', 'active' => false]);
        $this->assertNull($off['data']['budget']['auto_budget_type']);
        $this->assertFalse($off['data']['budget']['active']);
        $this->assertSame(0, AutoBudget::query()->where('budget_id', $budget->id)->count());

        $this->assertPlaneError($this->machine('PUT', '/budgets/Food', []), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/Nope', ['name' => 'X']), 404, 'not_found');
    }

    public function testUndoReversesALimitWrite(): void
    {
        $b = $this->budget('Groceries');
        $this->limit($b, '2026-10-01', '2026-10-31', '300.00');
        $this->planAndApply('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00']);
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('PUT /budgets/{id}/limits', $last['data']['route']);
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $this->assertSame(0, bccomp('300', (string) BudgetLimit::query()->value('amount'), 2));
    }

    public function testUndoAlsoReversesTheAvailableBudgetsFireflyRecalculated(): void
    {
        $b      = $this->budget('Groceries');
        $before = $this->tableHash('available_budgets');
        $apply  = $this->planAndApply('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-11-01', 'end' => '2026-11-30', 'amount' => '120.00']);
        $made   = AvailableBudget::query()->count();
        $this->assertGreaterThan(0, $made, 'Firefly\'s own listener (sync queue) creates the period\'s available budget');
        $this->assertSame($made, $apply['data']['available_budgets_recalculated'], 'and the plane says so');
        $last   = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['data']['reversible']);
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $this->assertSame(0, BudgetLimit::query()->count());
        $this->assertSame($before, $this->tableHash('available_budgets'), 'undo leaves no side effect behind');
    }

    public function testDeleteBudgetIsAdminOnly(): void
    {
        $b = $this->budget('Groceries');
        $this->limit($b, '2026-10-01', '2026-10-31', '300.00');
        $this->spend('2026-10-03', '20.00', $b);
        $this->assertPlaneError($this->machine('DELETE', '/budgets/'.$b->id), 403, 'forbidden');

        $this->enableAdmin();
        $this->assertPlaneError($this->machine('DELETE', '/budgets/'.$b->id, [], ['X-Firefly-Client' => 'mcp']), 403, 'forbidden');
        $plan  = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id));
        $this->assertSame(['limits_deleted' => 1, 'deleted' => 1], $plan['data']['changes']);
        $this->assertSame(1, Budget::query()->count());
        $apply = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertSame(1, $apply['data']['deleted']);
        $this->assertSame(1, $apply['data']['journals_unbudgeted']);
        $this->assertFalse($apply['data']['undoable']);
        $this->assertSame(0, Budget::query()->count());

        $gone = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id));
        $this->assertSame(0, $gone['data']['deleted']);
    }
}
