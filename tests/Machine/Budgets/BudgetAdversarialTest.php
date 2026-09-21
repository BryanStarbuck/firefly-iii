<?php

/*
 * BudgetAdversarialTest.php
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
use FireflyIII\Models\Attachment;
use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Support\Facades\Storage;
use Tests\Machine\MachineTestCase;

/**
 * Regression tests from the adversarial review of the budgets + categories families
 * (pm/apis.mdx §8.4, §8.5): isolation from another user's books, blank renames, JSON-number
 * amounts, available budgets that belong to the administration, "unbudgeted" only when it is
 * true, merge dry runs that change nothing, and per-currency totals that are never summed.
 *
 * @internal
 *
 * @coversNothing
 */
final class BudgetAdversarialTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
    }

    /** A second, invented household in its own administration. */
    private function otherHousehold(): User
    {
        $group = UserGroup::create(['title' => 'acme_llc@example.invalid']);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $other = User::create(['email' => 'acme_llc@example.invalid', 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);
        config(['machine.operator' => $this->user->email]);

        return $other;
    }

    public function testAnotherAdministrationsBudgetsAndCategoriesAreInvisible(): void
    {
        $other       = $this->otherHousehold();
        $mine        = $this->budget('Groceries');
        $theirs      = Budget::query()->create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'name' => 'Acme Payroll', 'active' => true, 'order' => 9]);
        $theirCat    = Category::query()->create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'name' => 'Acme Supplies']);
        $this->category('Food');
        AvailableBudget::query()->create([
            'user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'transaction_currency_id' => $this->primary->id, 'amount' => '9999.00',
            'start_date' => Carbon::parse('2026-10-01'), 'start_date_tz' => 'UTC', 'end_date' => Carbon::parse('2026-10-31'), 'end_date_tz' => 'UTC',
        ]);
        $this->limit($theirs, '2026-10-01', '2026-10-31', '5000.00');

        $list = $this->envelope($this->machine('GET', '/budgets'));
        $this->assertSame(['Groceries'], array_column($list['data']['budgets'], 'name'));
        $this->assertPlaneError($this->machine('GET', '/budgets/'.$theirs->id), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/budgets/Acme%20Payroll'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/budgets/'.$theirs->id.'/limits'), 404, 'not_found');

        $period = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame([(string) $mine->id], array_column($period['data']['budgets'], 'budget_id'));
        foreach ($period['data']['totals'] as $total) {
            $this->assertNull($total['available'], 'another household\'s available budget must not leak into the totals');
            $this->assertSame('0.00', $total['budgeted_total']);
        }
        $available = $this->envelope($this->machine('GET', '/available-budgets'));
        $this->assertSame([], $available['data']['available_budgets']);

        $cats = $this->envelope($this->machine('GET', '/categories'));
        $this->assertSame(['Food'], array_column($cats['data']['categories'], 'name'));
        $this->assertPlaneError($this->machine('GET', '/categories/'.$theirCat->id), 404, 'not_found');

        $this->enableWrites();
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$theirs->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '1.00']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/categories/merge', ['keep_id' => 'Food', 'merge_ids' => [(string) $theirCat->id]]), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/budget-period/reset', ['start' => '2026-10-01', 'end' => '2026-10-31', 'budget_ids' => [(string) $theirs->id]]), 404, 'not_found');
    }

    public function testSetAvailableCreatesARowThatBelongsToTheAdministration(): void
    {
        $this->enableWrites();
        $apply = $this->planAndApply('PUT', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '4200.00']);
        $this->assertSame('4200.00', $apply['data']['available_budget']['amount']);

        $row = AvailableBudget::query()->findOrFail((int) $apply['data']['available_budget']['id']);
        $this->assertSame((int) $this->user->user_group_id, (int) $row->user_group_id, 'an available budget with no user_group_id is invisible to administration-scoped code (primary-currency recalculation)');

        $period = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $this->assertSame('4200.00', $period['data']['totals'][0]['available']);
    }

    public function testABlankRenameIsRefused(): void
    {
        $this->enableWrites();
        $budget = $this->budget('Groceries');
        $env    = $this->assertPlaneError($this->machine('PUT', '/budgets/'.$budget->id, ['name' => '   ']), 400, 'invalid_input');
        $this->assertTrue(isset($env['error']['details']['fields']['name']) || 'name' === ($env['error']['details']['field'] ?? null));
        $this->assertSame('Groceries', Budget::query()->findOrFail($budget->id)->name);

        $this->assertPlaneError($this->machine('POST', '/budgets', ['name' => '   ']), 400, 'invalid_input');
        $this->assertSame(1, Budget::query()->count());
    }

    public function testAmountsMustBeDecimalStrings(): void
    {
        $this->enableWrites();
        $budget = $this->budget('Groceries');
        $env    = $this->assertPlaneError($this->machine('PUT', '/budgets/'.$budget->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => 650.5]), 400, 'invalid_input');
        $this->assertSame('amount', $env['error']['details']['field'] ?? null);
        $this->assertPlaneError($this->machine('PUT', '/available-budgets', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => 100]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$budget->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.505']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/budgets/'.$budget->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '-5.00']), 400, 'invalid_input');
        $this->assertSame(0, $budget->budgetlimits()->count());
    }

    public function testDeletingALimitCoveredByAnotherIsNotCalledUnbudgeted(): void
    {
        $this->enableWrites();
        $budget  = $this->budget('Travel');
        $month   = $this->limit($budget, '2026-10-01', '2026-10-31', '300.00');
        $this->limit($budget, '2026-10-01', '2026-12-31', '900.00'); // a quarterly limit still covers October

        $apply   = $this->planAndApply('DELETE', '/budgets/'.$budget->id.'/limits/'.$month->id, []);
        $this->assertSame(1, $apply['data']['deleted']);
        $this->assertFalse($apply['data']['unbudgeted']);
        $this->assertStringNotContainsString('UNBUDGETED', $apply['data']['message']);

        $solo    = $this->budget('Dining');
        $only    = $this->limit($solo, '2026-10-01', '2026-10-31', '100.00');
        $apply   = $this->planAndApply('DELETE', '/budgets/'.$solo->id.'/limits/'.$only->id, []);
        $this->assertTrue($apply['data']['unbudgeted']);
        $this->assertStringContainsString('UNBUDGETED', $apply['data']['message']);
    }

    public function testLimitsListPagesAndCarriesSpentOnEveryReturnedRow(): void
    {
        $budget = $this->budget('Groceries');
        foreach (['2026-07', '2026-08', '2026-09', '2026-10'] as $month) {
            $this->limit($budget, $month.'-01', date('Y-m-t', (int) strtotime($month.'-01')), '400.00');
        }
        $this->spend('2026-09-12', '55.25', $budget);

        $page = $this->envelope($this->machine('GET', '/budgets/'.$budget->id.'/limits', ['limit' => '2', 'offset' => '1']));
        $this->assertSame(['2026-09-01', '2026-08-01'], array_column($page['data']['limits'], 'start'));
        $this->assertSame(['-55.25', '0.00'], array_column($page['data']['limits'], 'spent'));
        $this->assertSame(['344.75', '400.00'], array_column($page['data']['limits'], 'left'));
        $this->assertTrue($page['meta']['truncated']);
        $this->assertSame(3, $page['meta']['next_offset']);
    }

    public function testMergeDryRunChangesNothing(): void
    {
        $this->enableWrites();
        $this->category('Groceries');
        $food = $this->category('Food');
        $this->spend('2026-10-03', '12.00', null, 'Food');
        $before = [$this->tableHash('categories'), $this->tableHash('category_transaction_journal')];

        $plan   = $this->envelope($this->machine('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => ['Food']]));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(1, $plan['data']['changes']['journals_repointed']);
        $this->assertSame($before, [$this->tableHash('categories'), $this->tableHash('category_transaction_journal')]);
        $this->assertNotNull(Category::query()->find($food->id));
    }

    public function testBudgetPeriodNeverSumsAcrossCurrencies(): void
    {
        $eur    = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail();
        if ((int) $eur->id === (int) $this->primary->id) {
            $eur = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        }
        $budget = $this->budget('Travel');
        $this->limit($budget, '2026-10-01', '2026-10-31', '500.00');
        $this->limit($budget, '2026-10-01', '2026-10-31', '200.00', $eur);
        $this->spend('2026-10-05', '120.00', $budget);

        $env    = $this->envelope($this->machine('GET', '/budget-period', ['start' => '2026-10-01', 'end' => '2026-10-31']));
        $rows   = [];
        foreach ($env['data']['budgets'] as $row) {
            $rows[$row['currency_code']] = [$row['limit'], $row['spent'], $row['left']];
        }
        $this->assertSame(['500.00', '-120.00', '380.00'], $rows[$this->primary->code]);
        $this->assertSame(['200.00', '0.00', '200.00'], $rows[$eur->code]);
        $totals = array_column($env['data']['totals'], 'budgeted_total', 'currency_code');
        $this->assertSame('500.00', $totals[$this->primary->code]);
        $this->assertSame('200.00', $totals[$eur->code]);
        $this->assertSame(1, $env['data']['budgets_with_limit']);
        $this->assertSame(0, $env['data']['budgets_without_limit']);
    }

    public function testDeleteBudgetDryRunKeepsAttachmentFilesAndTheApplyIsNotUndoable(): void
    {
        Storage::fake('upload');
        $this->enableWrites();
        $this->enableAdmin();
        $budget     = $this->budget('Travel');
        $attachment = Attachment::query()->create([
            'attachable_id' => $budget->id, 'attachable_type' => Budget::class, 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id,
            'md5' => md5('receipt'), 'filename' => 'northbank-receipt.txt', 'mime' => 'text/plain', 'title' => 'receipt', 'size' => 7, 'uploaded' => true,
        ]);
        $file       = sprintf('at-%d.data', $attachment->id);
        Storage::disk('upload')->put($file, 'receipt');

        $plan  = $this->envelope($this->machine('DELETE', '/budgets/'.$budget->id));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(1, $plan['data']['changes']['attachments_deleted']);
        $this->assertTrue(Storage::disk('upload')->exists($file), 'a dry run must not delete an attachment file — the DB rollback cannot restore it');
        $this->assertNotNull(Budget::query()->find($budget->id));

        $apply = $this->envelope($this->machine('DELETE', '/budgets/'.$budget->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['undoable']);
        $this->assertFalse(Storage::disk('upload')->exists($file));

        $undo  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertNotEmpty($undo['data']['blocked_by'] ?? $undo['data']['operation']['blocked_by'] ?? [], (string) json_encode($undo));
    }
}
