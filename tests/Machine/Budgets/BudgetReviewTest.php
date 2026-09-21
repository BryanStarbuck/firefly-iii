<?php

/*
 * BudgetReviewTest.php
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

use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * Regression tests from the second (adversarial) review of the budgets + categories families
 * (pm/apis.mdx §8.4, §8.5, §7.3, §14.1):
 *
 *   - a plan that created a limit could never be applied on MySQL/Postgres, because the
 *     rolled-back row's id was part of the confirm-token fingerprint (§7.3: created ids are
 *     deliberately NOT in it — a rolled-back insert does not get the same id again);
 *   - a "+" in a category or budget name was double-decoded into a space (Laravel already
 *     decodes route parameters), so "A+B" was not found;
 *   - /categories/{id}/transactions rendered amounts at Firefly's 12 stored places
 *     ("10.000000000000") instead of the currency's (§14.1), and rendered every group before
 *     paging;
 *   - PUT /budgets/{id} with auto_budget_type "none" on a budget with no auto-budget (or the
 *     same auto-budget again) reported "updated" when nothing changed;
 *   - DELETE of a limit that belongs to another budget of the same books said "already gone";
 *   - POST /budgets silently dropped auto_budget_amount/period sent with type "none";
 *   - min_spent was echoed padded to 12 places.
 *
 * @internal
 *
 * @coversNothing
 */
final class BudgetReviewTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
    }

    public function testAPlusInANameIsNotDecodedTwice(): void
    {
        $this->category('R&D + Ops');
        $budget = $this->budget('A+B');

        $cat = $this->envelope($this->machine('GET', '/categories/'.rawurlencode('R&D + Ops')));
        $this->assertTrue($cat['ok'], (string) json_encode($cat));
        $this->assertSame('R&D + Ops', $cat['data']['category']['name']);

        $env = $this->envelope($this->machine('GET', '/budgets/'.rawurlencode('A+B')));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame((string) $budget->id, $env['data']['budget']['id']);

        // percent-encoded characters still decode exactly once
        $this->category('50% off');
        $env = $this->envelope($this->machine('GET', '/categories/'.rawurlencode('50% off')));
        $this->assertTrue($env['ok'], (string) json_encode($env));
    }

    /**
     * SQLite's AUTOINCREMENT never reuses a deleted id and MySQL never rolls an auto-increment
     * back: creating and deleting a throwaway row between the plan and the apply gives the
     * applied limit a different id than the rolled-back preview had. The token must survive that.
     */
    public function testACreatedLimitsIdIsNotPartOfTheFingerprint(): void
    {
        $this->enableWrites();
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $body      = ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00'];

        $plan      = $this->envelope($this->machine('PUT', '/budgets/'.$groceries->id.'/limits', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('created', $plan['data']['limits'][0]['action']);
        $this->assertNull($plan['data']['limits'][0]['limit_id'], 'a row the dry run rolled back has no id yet');

        $throwaway = $this->limit($dining, '2026-09-01', '2026-09-30', '1.00');
        $throwaway->delete();

        $apply     = $this->envelope($this->machine('PUT', '/budgets/'.$groceries->id.'/limits', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['created' => 1], $apply['data']['changes']);
        $this->assertNotNull($apply['data']['limits'][0]['limit_id']);
        $this->assertSame(1, BudgetLimit::query()->where('budget_id', $groceries->id)->count());
    }

    public function testCopyPreviousAndSetAverageSurviveAnIdBumpToo(): void
    {
        $this->enableWrites();
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $this->limit($groceries, '2026-09-01', '2026-09-30', '600.00');
        $this->spend('2026-09-10', '90.00', $groceries);

        $plan = $this->envelope($this->machine('POST', '/budget-period/copy-previous', ['start' => '2026-10-01', 'end' => '2026-10-31', 'budget_ids' => [(string) $groceries->id]]));
        $this->assertNull($plan['data']['limits'][0]['limit_id']);
        $this->limit($dining, '2026-01-01', '2026-01-31', '1.00')->delete();
        $apply = $this->envelope($this->machine('POST', '/budget-period/copy-previous', ['start' => '2026-10-01', 'end' => '2026-10-31', 'budget_ids' => [(string) $groceries->id], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('600.00', $apply['data']['limits'][0]['amount']);

        $plan = $this->envelope($this->machine('POST', '/budget-period/set-average', ['start' => '2026-11-01', 'end' => '2026-11-30', 'periods' => 2, 'budget_ids' => [(string) $groceries->id]]));
        $this->assertNull($plan['data']['limits'][0]['limit_id']);
        $this->limit($dining, '2026-02-01', '2026-02-28', '1.00')->delete();
        $apply = $this->envelope($this->machine('POST', '/budget-period/set-average', ['start' => '2026-11-01', 'end' => '2026-11-30', 'periods' => 2, 'budget_ids' => [(string) $groceries->id], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('45.00', $apply['data']['limits'][0]['amount'], '90 spent in one of two periods → average 45');
    }

    public function testSettingALimitNamesTheOtherLimitsThatOverlapThePeriod(): void
    {
        $this->enableWrites();
        $groceries = $this->budget('Groceries');
        $yearly    = $this->limit($groceries, '2026-01-01', '2026-12-31', '3650.00');

        $plan = $this->envelope($this->machine('PUT', '/budgets/'.$groceries->id.'/limits', ['start' => '2026-10-01', 'end' => '2026-10-31', 'amount' => '650.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('created', $plan['data']['limits'][0]['action'], 'a yearly limit is not "the limit for October" — SET creates October\'s own');
        $others = $plan['data']['limits'][0]['other_limits_in_period'];
        $this->assertCount(1, $others);
        $this->assertSame((string) $yearly->id, $others[0]['id']);
        $this->assertSame('3650.00', $others[0]['amount']);
    }

    public function testUpdatingABudgetWithTheSameFactsIsUnchanged(): void
    {
        $this->enableWrites();
        $budget = $this->budget('Groceries');

        $none = $this->envelope($this->machine('PUT', '/budgets/'.$budget->id, ['auto_budget_type' => 'none']));
        $this->assertTrue($none['ok'], (string) json_encode($none));
        $this->assertSame(['unchanged' => 1], $none['data']['changes'], 'removing an auto-budget that does not exist changes nothing');

        $this->planAndApply('PUT', '/budgets/'.$budget->id, ['auto_budget_type' => 'reset', 'auto_budget_amount' => '450.00', 'auto_budget_period' => 'monthly']);
        $again = $this->envelope($this->machine('PUT', '/budgets/'.$budget->id, ['auto_budget_type' => 'reset', 'auto_budget_amount' => '450.00', 'auto_budget_period' => 'monthly']));
        $this->assertSame(['unchanged' => 1], $again['data']['changes'], 'the same auto-budget again changes nothing');

        $more  = $this->envelope($this->machine('PUT', '/budgets/'.$budget->id, ['auto_budget_amount' => '500.00']));
        $this->assertSame(['updated' => 1], $more['data']['changes']);
    }

    public function testCreatingABudgetRefusesAutoBudgetFieldsWithoutAType(): void
    {
        $this->enableWrites();
        $env = $this->assertPlaneError($this->machine('POST', '/budgets', ['name' => 'Groceries', 'auto_budget_type' => 'none', 'auto_budget_amount' => '450.00']), 400, 'invalid_input');
        $this->assertSame(['auto_budget_amount'], $env['error']['details']['fields']);
        $this->assertSame(0, Budget::query()->count());
    }

    public function testDeletingAnotherBudgetsLimitIsAWrongAddressNotAlreadyGone(): void
    {
        $this->enableWrites();
        $groceries = $this->budget('Groceries');
        $dining    = $this->budget('Dining');
        $limit     = $this->limit($dining, '2026-10-01', '2026-10-31', '120.00');

        $env = $this->assertPlaneError($this->machine('DELETE', '/budgets/'.$groceries->id.'/limits/'.$limit->id), 400, 'invalid_input');
        $this->assertSame((string) $dining->id, $env['error']['details']['belongs_to_budget_id']);
        $this->assertStringContainsString('/budgets/'.$dining->id.'/limits/'.$limit->id, $env['error']['hint']);
        $this->assertSame(1, BudgetLimit::query()->count());

        // a limit that belongs to another administration's budget is simply not there — no owner leaks
        $group = UserGroup::create(['title' => 'acme_llc@example.invalid']);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $other = User::create(['email' => 'acme_llc@example.invalid', 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);
        config(['machine.operator' => $this->user->email]);
        $theirs = Budget::query()->create(['user_id' => $other->id, 'user_group_id' => $group->id, 'name' => 'Acme Payroll', 'active' => true, 'order' => 1]);
        $theirLimit = $this->limit($theirs, '2026-10-01', '2026-10-31', '9999.00');

        $gone = $this->envelope($this->machine('DELETE', '/budgets/'.$groceries->id.'/limits/'.$theirLimit->id));
        $this->assertTrue($gone['ok'], (string) json_encode($gone));
        $this->assertSame(0, $gone['data']['deleted']);
        $this->assertStringNotContainsString('Acme', (string) json_encode($gone));
    }

    public function testGapsEchoMinSpentAsGiven(): void
    {
        $env = $this->envelope($this->machine('GET', '/budget-period/gaps', ['start' => '2026-08-01', 'end' => '2026-08-31', 'min_spent' => '10.50']));
        $this->assertSame('10.5', $env['data']['min_spent']);
        $env = $this->envelope($this->machine('GET', '/budget-period/gaps', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertNull($env['data']['min_spent']);
    }

    public function testCategoryTransactionsRenderAtTheCurrencysPlacesAndPage(): void
    {
        $groceries = $this->category('Groceries');
        $this->spend('2026-08-03', '40.00', null, 'Groceries');
        $this->spend('2026-08-04', '10.50', null, 'Groceries');
        $this->spend('2026-08-05', '15.00', null, 'Groceries');
        $this->spend('2026-08-06', '99.00', null, 'Dining Out');

        $env = $this->envelope($this->machine('GET', '/categories/'.$groceries->id.'/transactions', ['limit' => '2']));
        $this->assertCount(2, $env['data']['transactions']);
        $this->assertSame(3, $env['data']['total']);
        $this->assertTrue($env['meta']['truncated']);
        $this->assertSame(2, $env['meta']['next_offset']);
        $this->assertSame('15.00', $env['data']['transactions'][0]['transactions'][0]['amount'], 'newest first, at the currency\'s places (§14.1)');
        $this->assertSame('10.50', $env['data']['transactions'][1]['transactions'][0]['amount']);

        $rest = $this->envelope($this->machine('GET', '/categories/'.$groceries->id.'/transactions', ['limit' => '2', 'offset' => '2']));
        $this->assertCount(1, $rest['data']['transactions']);
        $this->assertSame('40.00', $rest['data']['transactions'][0]['transactions'][0]['amount']);
        $this->assertFalse($rest['meta']['truncated']);

        $asc = $this->envelope($this->machine('GET', '/categories/'.$groceries->id.'/transactions', ['order' => 'date', 'limit' => '1']));
        $this->assertSame('40.00', $asc['data']['transactions'][0]['transactions'][0]['amount']);

        foreach ($env['data']['transactions'] as $group) {
            foreach ($group['transactions'] as $split) {
                $this->assertIsString($split['amount']);
                $this->assertMatchesRegularExpression('/^-?\d+\.\d{2}$/', $split['amount']);
            }
        }
    }

    public function testUndoOfAMergeAndOfADeleteSaysWhyItRefuses(): void
    {
        $this->enableWrites();
        $this->enableAdmin();
        $this->category('Groceries');
        $food = $this->category('Food');
        $this->spend('2026-08-03', '40.00', null, 'Food');

        $this->planAndApply('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => [(string) $food->id]]);
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertFalse($last['data']['reversible']);
        $blocked = $last['data']['blocked_by'];
        $this->assertNotEmpty($blocked);
        $this->assertStringContainsString('journal', (string) $blocked[0]['reason']);
        $this->assertStringContainsString('Groceries', (string) $blocked[0]['reason']);

        $budget = $this->budget('Travel');
        $this->spend('2026-08-04', '10.00', $budget);
        $this->planAndApply('DELETE', '/budgets/'.$budget->id, []);
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertFalse($last['data']['reversible']);
        $this->assertStringContainsString('Travel', (string) $last['data']['blocked_by'][0]['reason']);
    }
}
