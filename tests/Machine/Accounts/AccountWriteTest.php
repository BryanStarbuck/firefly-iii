<?php

/*
 * AccountWriteTest.php
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

use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.2 + §7 — the account writes through the write protocol: dry run by default and
 * side-effect free, token, apply, stale token, ceiling; create / batch / update / deactivate /
 * activate / move / delete (admin); the operation log reverses them.
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountWriteTest extends MachineTestCase
{
    use AccountFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    public function testCreateIsADryRunByDefaultAndThenApplies(): void
    {
        $body   = ['name' => 'Household · Northbank Checking ••4021', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '1500.00', 'opening_balance_date' => '2026-01-01', 'iban' => 'NL02 ABNA 0123 4567 89'];
        $before = [Account::query()->count(), TransactionJournal::query()->count(), AccountMeta::query()->count()];
        $plan   = $this->envelope($this->machine('POST', '/accounts', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame('Household · Northbank Checking ••4021', $plan['data']['account']['name']);
        $this->assertSame('1500.00', $plan['data']['account']['current_balance'], 'the preview ran the real factory, opening balance included');
        $this->assertSame('NL02ABNA0123456789', $plan['data']['account']['iban']);
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $plan['data']['confirm_token']);
        $this->assertSame($before, [Account::query()->count(), TransactionJournal::query()->count(), AccountMeta::query()->count()], 'a dry run changes nothing');

        $this->assertPlaneError($this->machine('POST', '/accounts', $body + ['dry_run' => false]), 403, 'forbidden');

        $apply  = $this->envelope($this->machine('POST', '/accounts', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['dry_run']);
        $this->assertIsInt($apply['data']['operation_id']);
        $account = Account::query()->where('name', 'Household · Northbank Checking ••4021')->firstOrFail();
        $this->assertSame((string) $account->id, $apply['data']['account']['id']);
        $this->assertSame('1500.00', $apply['data']['account']['current_balance']);

        // the token was single use
        $this->assertPlaneError($this->machine('POST', '/accounts', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
    }

    public function testAStaleTokenIsRefusedWhenTheWorldMoved(): void
    {
        $body = ['name' => 'Acme LLC · Northbank Checking ••7734', 'type' => 'asset', 'account_role' => 'defaultAsset'];
        $plan = $this->envelope($this->machine('POST', '/accounts', $body));
        // someone creates the same account in the browser meanwhile
        $this->checking('Acme LLC · Northbank Checking ••7734', null);
        $env  = $this->assertPlaneError($this->machine('POST', '/accounts', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertArrayHasKey('existing', $env['error']['details']);
        $this->assertSame(1, Account::query()->where('name', 'Acme LLC · Northbank Checking ••7734')->count());
    }

    public function testCreateRefusesBadInput(): void
    {
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset']), 400, 'invalid_input'); // no account_role
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => 12.5, 'opening_balance_date' => '2026-01-01']), 400, 'invalid_input'); // a JSON number
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '12.505', 'opening_balance_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertStringContainsString('decimal places', $env['error']['message']);
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '12.50']), 400, 'invalid_input'); // no date
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'liability', 'liability_direction' => 'credit']), 400, 'invalid_input'); // no liability_type
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset', 'account_role' => 'defaultAsset', 'iban' => 'NL00NOTANIBAN']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset', 'account_role' => 'defaultAsset', 'currency_code' => 'ZZZ']), 404, 'not_found');
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'asset', 'account_role' => 'defaultAsset', 'nmae' => 'typo']), 400, 'invalid_input');
        $this->assertSame(['nmae'], $env['error']['details']['unknown']);
        $this->assertSame(0, Account::query()->where('name', 'X')->count());
    }

    public function testADuplicateNameIsAConflictNotASilentTwin(): void
    {
        $existing = $this->checking('Household · Northbank Checking ••4021', null);
        $env      = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'household · northbank checking ••4021', 'type' => 'asset', 'account_role' => 'savingAsset']), 409, 'conflict');
        $this->assertSame($existing['id'], $env['error']['details']['existing']['id']);
        // an expense account of the same name is a different name space
        $this->assertTrue($this->envelope($this->machine('POST', '/accounts', ['name' => 'Household · Northbank Checking ••4021', 'type' => 'expense']))['ok']);
    }

    public function testCreateALiabilityWithAnOpeningBalance(): void
    {
        $loan = $this->makeAccount(['name' => 'Household · Meridian Mortgage', 'type' => 'liability', 'liability_type' => 'mortgage', 'liability_direction' => 'credit', 'opening_balance' => '250000.00', 'opening_balance_date' => '2026-01-01', 'interest' => '5.1', 'interest_period' => 'monthly']);
        $this->assertSame('liabilities', $loan['type']);
        $this->assertSame('mortgage', $loan['liability_type']);
        $this->assertSame('credit', $loan['liability_direction']);
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'Car loan', 'type' => 'loan', 'liability_direction' => 'credit', 'opening_balance' => '-100.00', 'opening_balance_date' => '2026-01-01']), 400, 'invalid_input');
    }

    public function testBatchIsAllOrNothing(): void
    {
        $rows = [
            ['name' => 'Household · Northbank Checking ••4021', 'type' => 'asset', 'account_role' => 'defaultAsset'],
            ['name' => 'Acme LLC · Northbank Checking ••7734', 'type' => 'asset', 'account_role' => 'defaultAsset', 'opening_balance' => '10.00', 'opening_balance_date' => '2026-02-01'],
            ['name' => 'Household · Meridian Mortgage', 'type' => 'mortgage', 'liability_direction' => 'credit'],
        ];
        $plan = $this->envelope($this->machine('POST', '/accounts/batch', ['accounts' => $rows]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['created' => 3], $plan['data']['changes']);
        $this->assertCount(3, $plan['data']['accounts']);
        $this->assertSame(0, Account::query()->where('name', 'like', '%Northbank%')->count());

        $apply = $this->envelope($this->machine('POST', '/accounts/batch', ['accounts' => $rows, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame([0, 1, 2], array_column($apply['data']['accounts'], 'index'));
        $this->assertSame(2, Account::query()->where('name', 'like', '%Northbank Checking%')->where('name', 'not like', '%initial%')->count());

        // a row that collides fails the whole batch and names its index
        $env = $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => [
            ['name' => 'Fresh', 'type' => 'asset', 'account_role' => 'defaultAsset'],
            ['name' => 'Household · Northbank Checking ••4021', 'type' => 'asset', 'account_role' => 'defaultAsset'],
        ]]), 409, 'conflict');
        $this->assertSame(1, $env['error']['details']['index']);
        $this->assertSame(0, Account::query()->where('name', 'Fresh')->count());

        $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => [$rows[0], $rows[0]]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => [$rows[0] + ['colour' => 'red']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => []]), 400, 'invalid_input');
    }

    public function testTheCeilingCountsAccounts(): void
    {
        $rows = [];
        foreach (range(1, 3) as $i) {
            $rows[] = ['name' => 'Payee '.$i, 'type' => 'expense'];
        }
        $env = $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => $rows, 'max_changes' => 2]), 409, 'conflict');
        $this->assertSame(3, $env['error']['details']['change_count']);
    }

    public function testUpdateRenamesAndReportsUnchanged(): void
    {
        $checking = $this->checking();
        $id       = $checking['id'];
        $plan     = $this->envelope($this->machine('PUT', '/accounts/'.$id, ['name' => 'Household · Northbank Everyday ••4021', 'notes' => 'Joint account']));
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        $this->assertSame('Household · Northbank Everyday ••4021', $plan['data']['account']['name']);
        $this->assertSame('Household · Northbank Checking ••4021', $this->accountModel($id)->name, 'dry run changed nothing');

        $apply    = $this->envelope($this->machine('PUT', '/accounts/'.$id, ['name' => 'Household · Northbank Everyday ••4021', 'notes' => 'Joint account', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('Household · Northbank Everyday ••4021', $this->accountModel($id)->name);
        $this->assertSame('Joint account', $apply['data']['account']['notes']);

        $same     = $this->envelope($this->machine('PUT', '/accounts/'.$id, ['name' => 'Household · Northbank Everyday ••4021']));
        $this->assertSame(['unchanged' => 1], $same['data']['changes']);
        $this->assertSame(0, $same['data']['change_count']);

        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$id, []), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/accounts/999999', ['name' => 'x']), 404, 'not_found');
        $other    = $this->checking('Household · Meridian Savings ••7734', null);
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$other['id'], ['name' => 'Household · Northbank Everyday ••4021']), 409, 'conflict');
        $grocer   = $this->expense();
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$grocer['id'], ['account_role' => 'savingAsset']), 400, 'invalid_input');
    }

    public function testDeactivateAndActivate(): void
    {
        $checking = $this->checking(opening: null);
        $id       = $checking['id'];
        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$id.'/deactivate'));
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        $this->assertFalse($plan['data']['account']['active']);
        $this->assertTrue((bool) $this->accountModel($id)->active);
        $apply    = $this->envelope($this->machine('POST', '/accounts/'.$id.'/deactivate', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse((bool) $this->accountModel($id)->active);

        $again    = $this->envelope($this->machine('POST', '/accounts/'.$id.'/deactivate'));
        $this->assertSame(['unchanged' => 1], $again['data']['changes']);

        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$id.'/activate'));
        $this->envelope($this->machine('POST', '/accounts/'.$id.'/activate', ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue((bool) $this->accountModel($id)->active);
        $this->assertPlaneError($this->machine('POST', '/accounts/'.$id.'/activate', ['active' => true]), 400, 'invalid_input');
    }

    public function testMoveReordersAndUndoRestoresTheOrder(): void
    {
        $a = $this->checking('A account', null);
        $b = $this->checking('B account', null);
        $c = $this->checking('C account', null);
        $orders = static fn (): array => Account::query()->whereIn('name', ['A account', 'B account', 'C account'])->orderBy('name')->pluck('order', 'name')->map(static fn ($o): int => (int) $o)->all();
        $this->accountModel($a['id'])->update(['order' => 1]);
        $this->accountModel($b['id'])->update(['order' => 2]);
        $this->accountModel($c['id'])->update(['order' => 3]);
        $start  = $orders();

        $plan   = $this->envelope($this->machine('POST', '/accounts/'.$c['id'].'/move', ['order' => 1]));
        $this->assertSame(3, $plan['data']['changes']['updated']);
        $this->assertSame($start, $orders());
        $apply  = $this->envelope($this->machine('POST', '/accounts/'.$c['id'].'/move', ['order' => 1, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['A account' => 2, 'B account' => 3, 'C account' => 1], $orders());

        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame($start, $orders(), 'undo writes the before-images back');

        $grocer = $this->expense();
        $this->assertPlaneError($this->machine('POST', '/accounts/'.$grocer['id'].'/move', ['order' => 1]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts/'.$a['id'].'/move', []), 400, 'invalid_input');
    }

    public function testCreateCanBeUndone(): void
    {
        $account = $this->checking();
        $op      = (int) DB::table('machine_operations')->max('id');
        $this->assertSame(2, Transaction::query()->count(), 'the opening balance journal');
        DB::transaction(static fn () => OperationLog::reverse($op));
        $this->assertNull(Account::query()->find($account['id']));
        $this->assertSame(0, Transaction::query()->count());
        $this->assertSame(0, TransactionJournal::query()->count());
    }

    public function testDeleteIsAdminTier(): void
    {
        $checking = $this->checking();
        $this->assertPlaneError($this->machine('DELETE', '/accounts/'.$checking['id']), 403, 'forbidden');
        $this->assertNotNull(Account::query()->find($checking['id']));
    }

    public function testDeleteRemovesTheHistoryAndUndoRestoresIt(): void
    {
        $this->enableAdmin();
        $checking = $this->checking();
        $grocer   = $this->expense();
        $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '12.00', '2026-02-02');
        $journals = TransactionJournal::query()->count();

        $plan     = $this->envelope($this->machine('DELETE', '/accounts/'.$checking['id']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['deleted' => 1, 'journals_deleted' => 2], $plan['data']['changes']);
        $this->assertNotNull(Account::query()->find($checking['id']), 'dry run');
        $apply    = $this->envelope($this->machine('DELETE', '/accounts/'.$checking['id'], ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(Account::query()->find($checking['id']));
        $this->assertSame(0, TransactionJournal::query()->count());

        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertNotNull(Account::query()->find($checking['id']));
        $this->assertSame($journals, TransactionJournal::query()->count());
        $env      = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/balance'));
        $this->assertSame('988.00', $env['data']['balance'], 'history and opening balance are back');
    }

    public function testDeleteCanMoveTheHistory(): void
    {
        $this->enableAdmin();
        $old    = $this->checking('Household · Northbank Checking ••4021', null);
        $new    = $this->checking('Household · Meridian Checking ••7734', null);
        $grocer = $this->expense();
        $this->withdrawal((int) $old['id'], (int) $grocer['id'], '12.00', '2026-02-02');

        $this->assertPlaneError($this->machine('DELETE', '/accounts/'.$old['id'], ['move_transactions_to' => (string) $grocer['id']]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('DELETE', '/accounts/'.$old['id'], ['move_transactions_to' => (string) $old['id']]), 400, 'invalid_input');

        $body  = ['move_transactions_to' => 'Household · Meridian Checking ••7734'];
        $plan  = $this->envelope($this->machine('DELETE', '/accounts/'.$old['id'], $body));
        $this->assertSame(['deleted' => 1, 'journals_moved' => 1, 'journals_deleted' => 0], $plan['data']['changes']);
        $apply = $this->envelope($this->machine('DELETE', '/accounts/'.$old['id'], $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame($new['id'], $apply['data']['moved_to']['id']);
        $env   = $this->envelope($this->machine('GET', '/accounts/'.$new['id'].'/balance'));
        $this->assertSame('-12.00', $env['data']['balance']);
    }

    public function testInternalAccountsCannotBeEdited(): void
    {
        $this->checking();
        $ib = Account::query()->whereHas('accountType', static fn ($q) => $q->where('type', 'Initial balance account'))->firstOrFail();
        $this->assertPlaneError($this->machine('PUT', '/accounts/'.$ib->id, ['name' => 'x']), 400, 'invalid_input');
    }

    public function testWritesNeedTheWriteTier(): void
    {
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => 'X', 'type' => 'expense']), 403, 'write_disabled');
    }
}
