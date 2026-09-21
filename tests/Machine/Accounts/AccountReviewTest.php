<?php

/*
 * AccountReviewTest.php
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

use FireflyIII\Enums\UserRoleEnum;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * Regressions from the adversarial review of the accounts family (pm/apis.mdx §8.1, §8.2).
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountReviewTest extends MachineTestCase
{
    use AccountFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    /** Firefly's AccountUpdateService deletes a liability's "liability credit" journal on every edit — the plane must say so, and undo must bring it back. */
    public function testEditingALiabilityReportsAndUndoesTheJournalFireflyDeletes(): void
    {
        $loan     = $this->makeAccount(['name' => 'Meridian Car Loan', 'type' => 'loan', 'liability_direction' => 'debit', 'opening_balance' => '5000.00', 'opening_balance_date' => '2026-01-01']);
        $journals = TransactionJournal::query()->count();
        $before   = $this->envelope($this->machine('GET', '/accounts/'.$loan['id'].'/balance'))['data']['balance'];

        $plan     = $this->envelope($this->machine('PUT', '/accounts/'.$loan['id'], ['notes' => 'refinanced']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame($journals, TransactionJournal::query()->count(), 'a dry run changes nothing');
        $apply    = $this->envelope($this->machine('PUT', '/accounts/'.$loan['id'], ['notes' => 'refinanced', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $after    = TransactionJournal::query()->count();
        if ($after !== $journals) {
            // Firefly removed a journal: the change set must have said so in the plan …
            $this->assertArrayHasKey('journals_deleted', $plan['data']['changes'], (string) json_encode($plan['data']['changes']));
            $this->assertSame($journals - $after, $plan['data']['changes']['journals_deleted']);
        }
        // … and undo must return the ledger to its prior state
        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame($journals, TransactionJournal::query()->count(), 'undo restores every journal the edit removed');
        $this->assertSame($before, $this->envelope($this->machine('GET', '/accounts/'.$loan['id'].'/balance'))['data']['balance']);
    }

    /** With FIREFLY_MACHINE_ADMINISTRATION bound to another set of books, an account must land in THOSE books — or the write must refuse. */
    public function testCreatingAnAccountUnderABoundAdministrationLandsInThoseBooks(): void
    {
        $other = UserGroup::create(['title' => 'Acme LLC']);
        GroupMembership::create(['user_id' => $this->user->id, 'user_group_id' => $other->id, 'user_role_id' => UserRole::query()->where('title', UserRoleEnum::OWNER->value)->value('id')]);
        config(['machine.operator' => $this->user->email, 'machine.administration' => (string) $other->id]);

        $body = ['name' => 'Acme LLC · Northbank Checking ••7734', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '10.00', 'opening_balance_date' => '2026-01-01'];
        $plan = $this->machine('POST', '/accounts', $body);
        $env  = $this->envelope($plan);
        if (!$env['ok']) {
            $this->assertSame('not_ready', $env['error']['code'], (string) json_encode($env));
            $this->assertSame(0, Account::query()->where('name', $body['name'])->count());

            return;
        }
        $this->assertSame((int) $other->id, $env['meta']['administrationId']);
        $apply = $this->envelope($this->machine('POST', '/accounts', $body + ['dry_run' => false, 'confirm_token' => $env['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $account = Account::query()->where('name', $body['name'])->firstOrFail();
        $this->assertSame((int) $other->id, (int) $account->user_group_id, 'the account is in the bound administration');
        $this->assertSame(0, TransactionJournal::query()->where('user_group_id', '!=', $other->id)->count(), 'so is its opening balance');
        $list = $this->envelope($this->machine('GET', '/accounts', ['type' => 'asset']));
        $this->assertSame([$body['name']], array_column($list['data']['accounts'], 'name'));
    }

    /** Under a bound administration, a first reconciliation creates Firefly's reconciliation account — in the bound books. */
    public function testReconciliationUnderABoundAdministrationStaysInThoseBooks(): void
    {
        $other = UserGroup::create(['title' => 'Acme LLC']);
        GroupMembership::create(['user_id' => $this->user->id, 'user_group_id' => $other->id, 'user_role_id' => UserRole::query()->where('title', UserRoleEnum::OWNER->value)->value('id')]);
        config(['machine.operator' => $this->user->email, 'machine.administration' => (string) $other->id]);

        $checking = $this->makeAccount(['name' => 'Acme LLC · Northbank Checking ••7734', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '100.00', 'opening_balance_date' => '2026-01-01']);
        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/plan', ['start' => '2026-01-01', 'end' => '2026-01-31', 'target_balance' => '90.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('10.00', $plan['data']['difference']);
        $apply    = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/apply', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(0, Account::query()->where('user_group_id', '!=', $other->id)->count(), 'the reconciliation account is in the bound books');
        $this->assertSame(0, TransactionJournal::query()->where('user_group_id', '!=', $other->id)->count(), 'so is the reconciliation transaction');
        $this->assertSame('90.00', $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/balance', ['as_of' => '2026-01-31']))['data']['balance']);
    }

    /** Values Firefly would silently drop are refused instead (§14.2: the caller must never read back null for what it sent). */
    public function testValuesFireflyWouldDropAreRefused(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Zero', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '0.00', 'opening_balance_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertSame('opening_balance', $env['error']['details']['field']);
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Loan', 'type' => 'loan', 'liability_direction' => 'credit', 'virtual_balance' => '10.00']), 400, 'invalid_input');
        $this->assertSame('virtual_balance', $env['error']['details']['field']);
    }

    /** Deleting an account also removes its piggy-bank links: the dry run must report it, never hide it. */
    public function testDeletePreviewReportsThePiggyBanksItUnlinks(): void
    {
        $this->enableAdmin();
        $checking = $this->checking(opening: null);
        $piggy    = PiggyBank::create(['name' => 'Holiday', 'target_amount' => '500', 'start_date' => '2026-01-01', 'order' => 1, 'active' => true, 'transaction_currency_id' => $this->user->userGroup->currencies()->first()?->id ?? 1]);
        DB::table('account_piggy_bank')->insert(['account_id' => $checking['id'], 'piggy_bank_id' => $piggy->id, 'current_amount' => '0', 'native_current_amount' => null]);

        $plan = $this->envelope($this->machine('DELETE', '/accounts/'.$checking['id']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['piggy_banks_unlinked'] ?? null, (string) json_encode($plan['data']['changes']));
        $this->assertSame(1, DB::table('account_piggy_bank')->where('piggy_bank_id', $piggy->id)->count(), 'a dry run changes nothing');
        $this->assertStringContainsString('piggy-bank links', (string) $plan['data']['undo_note']);
    }

    /** move to the position an account already holds: nothing changes, and nothing is written. */
    public function testMoveToTheSamePositionIsUnchanged(): void
    {
        $a = $this->checking('A account', null);
        $b = $this->checking('B account', null);
        Account::query()->whereKey($a['id'])->update(['order' => 1]);
        Account::query()->whereKey($b['id'])->update(['order' => 2]);
        $plan = $this->envelope($this->machine('POST', '/accounts/'.$b['id'].'/move', ['order' => 2]));
        $this->assertSame(['unchanged' => 1], $plan['data']['changes']);
        $this->assertSame(0, $plan['data']['change_count']);
    }

    /** Orders with gaps (1, 5): Firefly renumbers on every move; a move that lands where it already is must still say what it wrote. */
    public function testMoveWithGappedOrdersReportsTheRenumbering(): void
    {
        $a = $this->checking('A account', null);
        $b = $this->checking('B account', null);
        Account::query()->whereKey($a['id'])->update(['order' => 1]);
        Account::query()->whereKey($b['id'])->update(['order' => 5]);
        $plan = $this->envelope($this->machine('POST', '/accounts/'.$b['id'].'/move', ['order' => 2]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(5, (int) $this->accountModel($b['id'])->order, 'a dry run changes nothing');
        $apply = $this->envelope($this->machine('POST', '/accounts/'.$b['id'].'/move', ['order' => 2, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(2, (int) $this->accountModel($b['id'])->order);
        $this->assertGreaterThan(0, $apply['data']['change_count'], 'the row moved from 5 to 2 — that is a change, not "unchanged"');
        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame(5, (int) $this->accountModel($b['id'])->order, 'undo restores the gapped order');
    }

    /** Ordering a list by a balance field while asking for no balances cannot mean anything. */
    public function testOrderingByBalanceWithoutBalancesIsRefused(): void
    {
        $this->checking(opening: null);
        $env = $this->assertPlaneError($this->machine('GET', '/accounts', ['type' => 'asset', 'order' => '-current_balance', 'with_balances' => 'false']), 400, 'invalid_input');
        $this->assertStringContainsString('with_balances', $env['error']['hint']);
    }

    /** Firefly keeps an IBAN (and an account number) on one account of a type — the plane refuses a twin like its own forms do. */
    public function testADuplicateIbanIsRefusedLikeFireflysOwnForms(): void
    {
        $this->makeAccount(['name' => 'Household · Northbank Checking ••4021', 'type' => 'asset', 'account_role' => 'defaultAsset', 'iban' => 'NL02ABNA0123456789', 'account_number' => '4021']);
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Household · Northbank Savings ••4021', 'type' => 'asset', 'account_role' => 'savingAsset', 'iban' => 'NL02 ABNA 0123 4567 89']), 409, 'conflict');
        $this->assertSame('iban', $env['error']['details']['field']);
        $this->assertSame('6789', $env['error']['details']['iban_last4']);
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Household · Northbank Savings ••4021', 'type' => 'asset', 'account_role' => 'savingAsset', 'account_number' => '4021']), 409, 'conflict');
        $this->assertSame('account_number', $env['error']['details']['field']);
        // an expense account may carry the IBAN of an asset account? No — only a revenue/expense pair may share one
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Northbank (payee)', 'type' => 'expense', 'iban' => 'NL02ABNA0123456789']), 409, 'conflict');

        $other = $this->checking('Household · Meridian Savings ••7734', null);
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$other['id'], ['iban' => 'NL02ABNA0123456789']), 409, 'conflict');
        // two rows of one batch with the same IBAN are refused before anything runs
        $env   = $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => [
            ['name' => 'A', 'type' => 'asset', 'account_role' => 'defaultAsset', 'iban' => 'NL91ABNA0417164300'],
            ['name' => 'B', 'type' => 'asset', 'account_role' => 'defaultAsset', 'iban' => 'NL91 ABNA 0417 1643 00'],
        ]]), 400, 'invalid_input');
        $this->assertSame(0, $env['error']['details']['duplicate_of']);
    }

    /** Renaming an account makes Firefly rewrite the rules that name it: the plan says so and undo puts the rules back. */
    public function testRenamingAnAccountReportsAndUndoesTheRuleRewrite(): void
    {
        $checking = $this->checking('Household · Northbank Checking ••4021', null);
        $group    = RuleGroup::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => 'Groceries', 'order' => 1, 'active' => true]);
        $rule     = Rule::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'rule_group_id' => $group->id, 'title' => 'From checking', 'order' => 1, 'active' => true, 'strict' => true, 'stop_processing' => false]);
        $trigger  = RuleTrigger::create(['rule_id' => $rule->id, 'trigger_type' => 'source_account_is', 'trigger_value' => 'Household · Northbank Checking ••4021', 'order' => 1, 'active' => true, 'stop_processing' => false]);
        $action   = RuleAction::create(['rule_id' => $rule->id, 'action_type' => 'set_source_account', 'action_value' => 'Household · Northbank Checking ••4021', 'order' => 1, 'active' => true, 'stop_processing' => false]);

        $plan     = $this->envelope($this->machine('PUT', '/accounts/'.$checking['id'], ['name' => 'Household · Northbank Everyday ••4021']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['updated' => 1, 'rule_conditions_updated' => 2], $plan['data']['changes']);
        $this->assertSame('Household · Northbank Checking ••4021', $trigger->fresh()->trigger_value, 'a dry run changes nothing');

        $apply    = $this->envelope($this->machine('PUT', '/accounts/'.$checking['id'], ['name' => 'Household · Northbank Everyday ••4021', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('Household · Northbank Everyday ••4021', $trigger->fresh()->trigger_value, 'Firefly rewrote the rule');
        $this->assertSame('Household · Northbank Everyday ••4021', $action->fresh()->action_value);

        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame('Household · Northbank Checking ••4021', $this->accountModel($checking['id'])->name);
        $this->assertSame('Household · Northbank Checking ••4021', $trigger->fresh()->trigger_value, 'undo put the rule back');
        $this->assertSame('Household · Northbank Checking ••4021', $action->fresh()->action_value);
    }

    /** Firefly's update service stores a new liability_direction but leaves the opening balance's sign alone — so the plane does not offer it. */
    public function testLiabilityDirectionIsNotEditable(): void
    {
        $loan = $this->makeAccount(['name' => 'Meridian Car Loan', 'type' => 'loan', 'liability_direction' => 'credit', 'opening_balance' => '5000.00', 'opening_balance_date' => '2026-01-01']);
        $env  = $this->assertPlaneError($this->machine('PUT', '/accounts/'.$loan['id'], ['liability_direction' => 'debit']), 400, 'invalid_input');
        $this->assertSame(['liability_direction'], $env['error']['details']['unknown']);
    }

    /** A plan's selected journals must be the account's own: another account's uncleared journal is refused by id. */
    public function testReconcilePlanRefusesAnotherAccountsJournal(): void
    {
        $checking = $this->checking();
        $savings  = $this->checking('Household · Meridian Savings ••7734', '50.00');
        $grocer   = $this->expense();
        $foreign  = $this->withdrawal((int) $savings['id'], (int) $grocer['id'], '5.00', '2026-02-10');
        $env      = $this->assertPlaneError($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/plan', ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '1000.00', 'journal_ids' => [$foreign]]), 400, 'invalid_input');
        $this->assertSame([$foreign], $env['error']['details']['journal_ids']);
    }
}
