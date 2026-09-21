<?php

/*
 * BudgetThirdReviewTest.php
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

use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Category;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\RecurrenceTransaction;
use FireflyIII\Models\RecurrenceTransactionMeta;
use FireflyIII\Models\TransactionType;
use Tests\Machine\MachineTestCase;

/**
 * Regression tests from the third (adversarial) review of the budgets + categories families
 * (pm/apis.mdx §8.4, §8.5, §7.3, §7.5, §14.2, §14.4):
 *
 *   - /budget-period computed a budget's `limit` with BudgetLimitRepository::budgeted(), the
 *     available-budget-period figure, which multiplies the WHOLE period by a partial limit's
 *     daily rate — a 150.00 limit for Oct 1-15 read "310.00" for October. Firefly's per-budget
 *     service (BudgetRepository::budgetedInPeriodForBudget) counts a limit inside the period in
 *     full and pro-rates only the days of a limit that crosses the edge;
 *   - PUT /available-budgets reported plain success for a value this Firefly version recalculates
 *     away on the next limit change (AvailableBudgetCalculator overwrites it with the budgeted
 *     total, and deletes it at zero), without saying so;
 *   - DELETE /categories/{id} said `undoable: true` while Firefly's CategoryDestroyService had
 *     hard-deleted the recurring transactions' `category_id` links, which undo did not restore;
 *   - DELETE /budgets/{id}/limits/{limit_id} put the live spent/left figures in its fingerprint,
 *     so an unrelated transaction between plan and apply refused the delete with `conflict`
 *     although the change set (one limit row) had not moved.
 *
 * @internal
 *
 * @coversNothing
 */
final class BudgetThirdReviewTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
        $this->enableWrites();
    }

    public function testBudgetPeriodLimitIsTheLimitsOwnAmountNotTheWholePeriodAtItsDailyRate(): void
    {
        $groceries = $this->budget('Groceries');
        $this->limit($groceries, '2026-10-01', '2026-10-15', '150.00');   // inside the period: counts in full
        $travel    = $this->budget('Travel');
        $this->limit($travel, '2026-01-01', '2026-12-31', '3650.00');     // crosses the period: 31 of 365 days
        $this->limit($travel, '2026-10-01', '2026-10-31', '40.00');       // exactly the period

        $period = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $rows   = array_column($period['data']['budgets'], null, 'name');
        $this->assertSame('150.00', $rows['Groceries']['limit'], 'a half-month limit is not doubled into a month');
        $this->assertSame('150.00', $rows['Groceries']['left']);
        $this->assertSame('350.00', $rows['Travel']['limit'], '3650/365 × 31 days, plus the month\'s own 40.00');
        $this->assertSame(2, $period['data']['budgets_with_limit']);
        $this->assertSame('500.00', $period['data']['totals'][0]['budgeted_total'], 'the totals are the sum of the rows');
    }

    public function testSettingTheAvailableBudgetSaysFireflyRecalculatesIt(): void
    {
        $b   = $this->budget('Groceries');
        $set = $this->planAndApply('PUT', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '3100.00']);
        $this->assertTrue($set['data']['firefly_recalculates']);
        $this->assertStringContainsString('recalculates', $set['data']['warning']);
        $this->assertSame('3100.00', $set['data']['available_budget']['amount']);
        $list = $this->envelope($this->machine('GET', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame('3100.00', $list['data']['available_budgets'][0]['amount']);
        $this->assertNotEmpty($list['data']['notes']);

        // the fact the warning is about: Firefly's own listener overwrites the amount on the next limit write
        $this->planAndApply('PUT', '/budgets/'.$b->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00']);
        $this->assertSame(1, AvailableBudget::query()->count());
        $this->assertSame(0, bccomp('650', (string) AvailableBudget::query()->value('amount'), 2), 'Firefly recalculated the operator\'s 3100.00 into the budgeted total');
        $period = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame('650.00', $period['data']['totals'][0]['available']);
        $this->assertSame('0.00', $period['data']['totals'][0]['left_to_budget']);
        $this->assertTrue((bool) array_filter($period['data']['notes'], static fn (string $n): bool => str_contains($n, 'recalculates')));
    }

    public function testDeletingACategoryRecordsTheRecurrenceLinksFireflyRemovesSoUndoRestoresThem(): void
    {
        $housing = $this->category('Housing');
        $type    = TransactionType::query()->where('type', 'Withdrawal')->first();
        $rec     = Recurrence::query()->create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'transaction_type_id' => $type->id, 'title' => 'Rent (Meridian)', 'description' => 'rent', 'first_date' => '2026-01-01', 'first_date_tz' => 'UTC', 'repeat_until' => null, 'latest_date' => null, 'repetitions' => 0, 'apply_rules' => true, 'active' => true]);
        $rt      = RecurrenceTransaction::query()->create(['recurrence_id' => $rec->id, 'transaction_currency_id' => $this->primary->id, 'transaction_type_id' => $type->id, 'source_id' => $this->checking->id, 'destination_id' => $this->checking->id, 'amount' => '1200.00', 'description' => 'rent']);
        RecurrenceTransactionMeta::query()->create(['rt_id' => $rt->id, 'name' => 'category_id', 'value' => (string) $housing->id]);
        RecurrenceTransactionMeta::query()->create(['rt_id' => $rt->id, 'name' => 'category_name', 'value' => 'Housing']);
        $before  = $this->tableHash('rt_meta');
        $this->enableAdmin();

        $plan    = $this->envelope($this->machine('DELETE', '/categories/'.$housing->id));
        $this->assertSame(['recurrences_unlinked' => 1, 'deleted' => 1], $plan['data']['changes']);
        $this->assertSame(1, $plan['data']['recurrences_unlinked']);
        $this->assertSame($before, $this->tableHash('rt_meta'), 'the dry run changed nothing');

        $apply   = $this->envelope($this->machine('DELETE', '/categories/'.$housing->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertTrue($apply['data']['undoable']);
        $this->assertSame(1, RecurrenceTransactionMeta::query()->count(), 'Firefly hard-deleted the category_id link');

        $last    = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['data']['reversible']);
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $this->assertSame(1, Category::query()->count());
        $this->assertSame($before, $this->tableHash('rt_meta'), 'undo re-inserted the recurrence\'s category link');
    }

    public function testDeletingALimitIsNotRefusedBecauseOfUnrelatedSpending(): void
    {
        $b     = $this->budget('Groceries');
        $limit = $this->limit($b, '2026-10-01', '2026-10-31', '300.00');
        $plan  = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id));
        $this->assertSame('0.00', $plan['data']['limit']['spent']);
        $this->spend('2026-10-05', '5.00', $b); // a transaction booked between plan and apply
        $apply = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(1, $apply['data']['deleted']);
        $this->assertTrue($apply['data']['unbudgeted']);
        $this->assertSame(0, BudgetLimit::query()->count());

        // the change set itself moving is still refused: the limit's amount was edited since the plan
        $limit = $this->limit($b, '2026-11-01', '2026-11-30', '300.00');
        $plan  = $this->envelope($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id));
        BudgetLimit::query()->where('id', $limit->id)->update(['amount' => '310.00']);
        $this->assertPlaneError($this->machine('DELETE', '/budgets/'.$b->id.'/limits/'.$limit->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(1, BudgetLimit::query()->count());
    }

    public function testAnAmbiguousNameIsRefusedWithTheCandidates(): void
    {
        $this->budget('groceries');
        $this->budget('Groceries');
        $env = $this->assertPlaneError($this->machine('GET', '/budgets/GROCERIES'), 400, 'invalid_input');
        $this->assertSame(['groceries', 'Groceries'], array_column($env['error']['details']['candidates'], 'name'));
        $this->assertTrue($this->envelope($this->machine('GET', '/budgets/Groceries'))['ok'], 'an exact match wins');

        $this->category('food');
        $this->category('Food');
        $env = $this->assertPlaneError($this->machine('PUT', '/categories/FOOD', ['name' => 'Meals']), 400, 'invalid_input');
        $this->assertCount(2, $env['error']['details']['candidates']);
        $this->assertSame(2, Category::query()->count(), 'nothing was renamed');
    }
}
