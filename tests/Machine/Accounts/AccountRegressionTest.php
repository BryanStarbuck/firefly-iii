<?php

/*
 * AccountRegressionTest.php
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

use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * Regression tests for defects found in the adversarial review of the accounts family.
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountRegressionTest extends MachineTestCase
{
    use AccountFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    public function testDeletingAnAlreadyDeletedAccountIsOkWithZero(): void
    {
        $this->enableAdmin();
        $grocer = $this->expense();
        $plan   = $this->envelope($this->machine('DELETE', '/accounts/'.$grocer['id']));
        $this->assertSame(1, $plan['data']['deleted']);
        $apply  = $this->envelope($this->machine('DELETE', '/accounts/'.$grocer['id'], ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));

        // §5.6: a retry of the DELETE is ok with deleted: 0, not a 404
        $again  = $this->envelope($this->machine('DELETE', '/accounts/'.$grocer['id'], ['dry_run' => false]));
        $this->assertTrue($again['ok'], (string) json_encode($again));
        $this->assertSame(0, $again['data']['deleted']);
        $this->assertSame(0, $again['data']['change_count']);
        // an id that never existed is still not_found
        $this->assertPlaneError($this->machine('DELETE', '/accounts/424242'), 404, 'not_found');
    }

    public function testAPlanMadeByNameConfirmsById(): void
    {
        $checking = $this->checking();
        $name     = rawurlencode('Household · Northbank Checking ••4021');

        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$name.'/reconcile/plan', ['start' => '2026-01-01', 'end' => '2026-01-31', 'target_balance' => '1000.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $apply    = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/apply', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));

        $edit     = $this->envelope($this->machine('PUT', '/accounts/'.$name, ['notes' => 'statement on the 1st']));
        $this->assertTrue($edit['ok'], (string) json_encode($edit));
        $done     = $this->envelope($this->machine('PUT', '/accounts/'.$checking['id'], ['notes' => 'statement on the 1st', 'dry_run' => false, 'confirm_token' => $edit['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame('statement on the 1st', $done['data']['account']['notes']);
    }

    public function testAnExpenseAccountRefusesAnOpeningBalanceInsteadOfDroppingIt(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Corner Grocer', 'type' => 'expense', 'opening_balance' => '10.00', 'opening_balance_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertSame('opening_balance', $env['error']['details']['field']);
    }

    public function testLiabilityAndCreditCardFieldsAreRefusedOnTheWrongAccount(): void
    {
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Payee', 'type' => 'expense', 'interest' => '4.25']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Savings', 'type' => 'asset', 'account_role' => 'savingAsset', 'liability_direction' => 'credit']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Savings', 'type' => 'asset', 'account_role' => 'savingAsset', 'monthly_payment_date' => '2026-01-15']), 400, 'invalid_input');

        $checking = $this->checking();
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$checking['id'], ['liability_direction' => 'credit']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$checking['id'], ['interest' => '4.25']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$checking['id'], ['credit_card_type' => 'monthlyFull']), 400, 'invalid_input');
        // switching the role to a credit card in the same edit is allowed
        $ok = $this->envelope($this->machine('PUT', '/accounts/'.$checking['id'], ['account_role' => 'ccAsset', 'credit_card_type' => 'monthlyFull', 'monthly_payment_date' => '2026-01-15']));
        $this->assertTrue($ok['ok'], (string) json_encode($ok));

        $loan = $this->makeAccount(['name' => 'Meridian Car Loan', 'type' => 'loan', 'liability_direction' => 'credit', 'interest' => '4.25', 'interest_period' => 'monthly']);
        $ok   = $this->envelope($this->machine('PUT', '/accounts/'.$loan['id'], ['interest' => '3.90']));
        $this->assertTrue($ok['ok'], (string) json_encode($ok));
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        $this->checking();
        $this->expense('Corner Grocer');
        $this->expense('100% Organic');

        $pct   = $this->envelope($this->machine('GET', '/accounts', ['type' => 'all', 'search' => '%']));
        $this->assertSame(['100% Organic'], array_column($pct['data']['accounts'], 'name'));
        $under = $this->envelope($this->machine('GET', '/accounts', ['type' => 'all', 'search' => '_']));
        $this->assertSame([], $under['data']['accounts']);
    }

    public function testNameOrderedPagesRenderOnlyThePageInOrder(): void
    {
        foreach (['Delta Payee', 'alpha Payee', 'Charlie Payee', 'bravo Payee'] as $name) {
            $this->expense($name);
        }
        $first  = $this->envelope($this->machine('GET', '/accounts', ['type' => 'expense', 'limit' => '2']));
        $this->assertSame(['alpha Payee', 'bravo Payee'], array_column($first['data']['accounts'], 'name'), 'case-insensitive natural order');
        $this->assertArrayHasKey('current_balance', $first['data']['accounts'][0]);
        $this->assertTrue($first['meta']['truncated']);
        $second = $this->envelope($this->machine('GET', '/accounts', ['type' => 'expense', 'limit' => '2', 'offset' => '2']));
        $this->assertSame(['Charlie Payee', 'Delta Payee'], array_column($second['data']['accounts'], 'name'));
        $this->assertFalse($second['meta']['truncated']);
        $desc   = $this->envelope($this->machine('GET', '/accounts', ['type' => 'expense', 'order' => '-name', 'limit' => '1']));
        $this->assertSame(['Delta Payee'], array_column($desc['data']['accounts'], 'name'));
    }

    public function testReconcileReachesAnotherMembersJournalsInASharedAdministration(): void
    {
        $checking = $this->checking();
        $grocer   = $this->expense();
        $journal  = $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '25.00', '2026-02-10');

        // a second member of the same administration entered that journal
        $member   = User::create(['email' => 'member@household.example', 'password' => 'password', 'user_group_id' => $this->user->user_group_id]);
        GroupMembership::create(['user_id' => $member->id, 'user_group_id' => $this->user->user_group_id, 'user_role_id' => UserRole::query()->where('title', 'owner')->value('id')]);
        $tj       = TransactionJournal::query()->findOrFail($journal);
        $tj->update(['user_id' => $member->id]);
        TransactionGroup::query()->whereKey($tj->transaction_group_id)->update(['user_id' => $member->id]);
        config(['machine.operator' => $this->user->email]);

        $list     = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['start' => '2026-02-01', 'end' => '2026-02-28']));
        $this->assertCount(1, $list['data']['transactions'], 'the account\'s history includes the other member\'s row');

        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/plan', ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '975.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame([$journal], $plan['data']['journal_ids']);
        $this->assertSame('0.00', $plan['data']['difference']);
        $apply    = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/apply', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(2, Transaction::query()->where('transaction_journal_id', $journal)->where('reconciled', true)->count(), 'both sides really reconciled');

        $un       = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/unreconcile/'.$journal));
        $un       = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/unreconcile/'.$journal, ['dry_run' => false, 'confirm_token' => $un['data']['confirm_token']]));
        $this->assertTrue($un['ok'], (string) json_encode($un));
        $this->assertSame(0, Transaction::query()->where('transaction_journal_id', $journal)->where('reconciled', true)->count());
    }
}
