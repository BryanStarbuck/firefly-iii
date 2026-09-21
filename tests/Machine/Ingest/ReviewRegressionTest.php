<?php

/*
 * ReviewRegressionTest.php
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

namespace Tests\Machine\Ingest;

use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;

/**
 * Regressions from the adversarial review of the ingest family (apis.mdx §11–§12, cli.mdx §10):
 *
 *   - a statement (or manifest) in one currency is never stored into an account of another
 *     currency as if the numbers were the same — those rows are blocked and named (§11.8, §14.1)
 *   - undoing an ingest apply removes every row it created (transactions, meta, rule joins, the
 *     expense/revenue accounts Firefly created on the side), not only the group and journal rows
 *   - an account of ANOTHER administration cannot be mapped or targeted (§16.1)
 *   - a confirm token works once; the apply route refuses unknown fields; a list clamps
 *
 * @internal
 *
 * @coversNothing
 */
final class ReviewRegressionTest extends IngestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useTree('prepared');
    }

    public function testAStatementInAnotherCurrencyIsBlockedNeverRelabelled(): void
    {
        $ids  = $this->provisionAccounts();
        $eur  = TransactionCurrency::query()->where('code', 'EUR')->firstOrFail();
        $eur->enabled = true;
        $eur->save();
        // the household checking account (statements say USD) is re-mapped to an EUR account
        $euro = Account::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'account_type_id' => AccountType::whereType('Asset account')->first()->id, 'name' => 'Euro Wallet', 'active' => true, 'virtual_balance' => '0']);
        AccountMeta::create(['account_id' => $euro->id, 'name' => 'currency_id', 'data' => $eur->id]);
        AccountMeta::create(['account_id' => $euro->id, 'name' => 'account_role', 'data' => 'defaultAsset']);
        $dry  = $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['key' => 'household/Northbank/Checking_x4021', 'account_id' => $euro->id]]]);
        $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['key' => 'household/Northbank/Checking_x4021', 'account_id' => $euro->id]], 'dry_run' => false, 'confirm_token' => $dry['confirm_token']]);

        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['4021']]);
        $this->assertSame(0, $plan['change_count'], 'a USD statement is never stored as EUR amounts');
        $acct = $plan['accounts'][0];
        $this->assertTrue($acct['blocked']);
        $this->assertSame(5, $acct['currency_mismatch']);
        $this->assertStringContainsString('USD', (string) $acct['blocked_reason']);
        $this->assertStringContainsString('EUR', (string) $acct['blocked_reason']);
        $this->assertSame(5, $plan['changes']['blocked']);
        $this->okData('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(0, Transaction::query()->where('account_id', $euro->id)->count());

        // the other accounts still import; the file route refuses the same mismatch
        $file = $this->root.'/bank/household/Northbank/Checking_x4021/Checking_x4021_2026-09.ofx';
        $one  = $this->okData('POST', '/ingest/file/plan', ['account_id' => $euro->id, 'path' => $file]);
        $this->assertSame(0, $one['change_count']);
        $this->assertSame(3, $one['accounts'][0]['currency_mismatch']);
        $ok   = $this->okData('POST', '/ingest/file/plan', ['account_id' => $ids['household/Northbank/Checking_x4021'], 'path' => $file]);
        $this->assertSame(3, $ok['change_count']);
    }

    public function testUndoOfAnApplyLeavesNoOrphanRows(): void
    {
        $this->provisionAccounts();
        $counts = static fn (): array => [
            'groups'   => TransactionGroup::query()->count(),
            'journals' => TransactionJournal::query()->count(),
            'txs'      => Transaction::query()->count(),
            'meta'     => TransactionJournalMeta::query()->count(),
            'accounts' => Account::query()->count(),
            'category' => DB::table('category_transaction_journal')->count(),
        ];
        $before = $counts();
        $apply  = $this->planAndApply();
        $this->assertSame(9, $apply['changes']['created']);
        $mid    = $counts();
        $this->assertSame($before['groups'] + 9, $mid['groups']);
        $this->assertGreaterThan($before['txs'], $mid['txs']);
        $this->assertGreaterThan($before['meta'], $mid['meta'], 'external_id, internal_reference and import_hash_v2 are journal meta');
        $this->assertGreaterThan($before['accounts'], $mid['accounts'], 'Firefly created expense/revenue accounts on the side');

        $last = $this->okData('GET', '/undo/last');
        $this->assertSame($apply['operation_id'], $last['operation_id'] ?? $last['operation']['id'] ?? null);
        $this->okData('POST', '/undo', ['confirm_token' => $last['confirm_token']]);
        $this->assertSame($before, $counts(), 'undo removes everything the apply created — no orphan transactions, meta or side accounts');

        // and the rows can be imported again afterwards: the orphan hash is gone with its journal
        $again = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertSame(9, $again['change_count']);
    }

    public function testUndoOfAnAccountsApplyRemovesTheAccountsTheirMetaAndTheOpeningBalance(): void
    {
        $this->useTree('prepared_formats');
        $counts = static fn (): array => [
            'accounts' => Account::query()->count(),
            'meta'     => AccountMeta::query()->count(),
            'groups'   => TransactionGroup::query()->count(),
            'journals' => TransactionJournal::query()->count(),
            'txs'      => Transaction::query()->count(),
        ];
        $before = $counts();
        $plan   = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $apply  = $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(2, $apply['changes']['created']);
        $mid    = $counts();
        $this->assertSame($before['accounts'] + 3, $mid['accounts'], 'two accounts plus Firefly\'s initial-balance account');
        $this->assertSame($before['groups'] + 1, $mid['groups'], 'the opening balance is Firefly\'s own transaction');
        $this->assertGreaterThan($before['meta'], $mid['meta']);

        $last = $this->okData('GET', '/undo/last');
        $this->okData('POST', '/undo', ['confirm_token' => $last['confirm_token']]);
        $this->assertSame($before, $counts(), 'undo removes the accounts, their meta rows and the opening-balance rows');
        $map  = $this->okData('GET', '/ingest/map', ['root' => $this->root]);
        $this->assertSame(2, $map['summary']['stale'], 'the map now points at accounts that are gone, and says so');
    }

    public function testAnotherAdministrationsAccountIsNeverATarget(): void
    {
        $this->provisionAccounts();
        $group = UserGroup::create(['title' => 'someone-else']);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $other = User::create(['email' => 'someone-else@example.test', 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);
        config(['machine.operator' => $this->user->email]); // two users exist now: the plane never guesses
        $theirs = Account::create(['user_id' => $other->id, 'user_group_id' => $group->id, 'account_type_id' => AccountType::whereType('Asset account')->first()->id, 'name' => 'Household · Northbank Checking ••4021', 'active' => true, 'virtual_balance' => '0']);

        $this->assertPlaneError($this->machine('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['key' => 'household/Northbank/Checking_x4021', 'account_id' => $theirs->id]]]), 404, 'not_found');
        $file = $this->root.'/bank/household/Northbank/Checking_x4021/Checking_x4021_2026-09.ofx';
        $this->assertPlaneError($this->machine('POST', '/ingest/file/plan', ['account_id' => $theirs->id, 'path' => $file]), 404, 'not_found');
        // the same name in another administration never links or counts as a candidate
        @unlink($this->root.'/.firefly-staging/_map.json');
        $plan = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $row  = array_column($plan['plan'], null, 'key')['household/Northbank/Checking_x4021'];
        $this->assertSame('link', $row['action']);
        $this->assertNotSame($theirs->id, $row['existing']['id']);
        $infer = $this->okData('POST', '/ingest/map/infer', ['root' => $this->root]);
        foreach ($infer['accounts'] as $a) {
            $this->assertNotSame($theirs->id, $a['account_id']);
        }
    }

    public function testATokenWorksOnceAndTheApplyRouteRefusesUnknownFields(): void
    {
        $this->provisionAccounts();
        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['7734']]);
        $this->assertPlaneError($this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false, 'root' => $this->root]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/ingest/apply', ['confirm_token' => 'cf_'.str_repeat('0', 32), 'dry_run' => false]), 409, 'conflict');
        $this->okData('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertPlaneError($this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]), 409, 'conflict');

        $env = $this->envelope($this->machine('GET', '/ingest/rows', ['root' => $this->root, 'limit' => '999999']));
        $this->assertTrue($env['ok']);
        $this->assertSame(5000, $env['meta']['limit_applied']);
        $env = $this->envelope($this->machine('GET', '/ingest/rows', ['root' => $this->root, 'limit' => '2', 'order' => 'amount']));
        $this->assertTrue($env['meta']['truncated']);
        $this->assertCount(2, $env['data']['rows']);
        $this->assertPlaneError($this->machine('GET', '/ingest/rows', ['root' => $this->root, 'order' => 'description']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/ingest/runs/not-a-run-id'), 400, 'invalid_input');

        // a lexical ".." under a component that does not exist is refused before realpath() is asked
        $this->assertPlaneError($this->machine('POST', '/ingest/prefer', ['root' => $this->root, 'path' => 'nope/../../../etc/hosts']), 403, 'forbidden');
        $this->assertPlaneError($this->machine('GET', '/ingest/manifest', ['root' => $this->root, 'manifest_path' => 'bank/../import/accounts.csv']), 403, 'forbidden');
    }

    /**
     * apis.mdx §4.9: the plane can be bound to an administration other than the operator's
     * current one. Firefly's factories stamp new rows with the CURRENT one; everything an ingest
     * write creates must still land in — and be read back from — the bound books.
     */
    public function testEverythingCreatedLandsInTheBoundAdministration(): void
    {
        $bound = UserGroup::create(['title' => 'second books']);
        $role  = UserRole::query()->where('title', 'owner')->first();
        GroupMembership::create(['user_id' => $this->user->id, 'user_group_id' => $bound->id, 'user_role_id' => $role->id]);
        $usd   = TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        $bound->currencies()->syncWithoutDetaching([$usd->id => ['group_default' => true]]);
        config(['machine.administration' => (string) $bound->id]);
        $current = (int) $this->user->user_group_id;
        $this->assertNotSame($current, (int) $bound->id);

        $ids = $this->provisionAccounts();
        $this->assertCount(3, $ids);
        $this->assertSame(0, Account::query()->whereIn('id', $ids)->where('user_group_id', '!=', $bound->id)->count(), 'created accounts belong to the bound administration');
        $again = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 0, 'link' => 0, 'skip' => 3, 'ambiguous' => 0], $again['summary'], 'and the very next plan finds them');

        $apply = $this->planAndApply();
        $this->assertSame(9, $apply['changes']['created']);
        $this->assertSame(0, TransactionJournal::query()->where('user_group_id', '!=', $bound->id)->count());
        $this->assertSame(0, Account::query()->where('user_id', $this->user->id)->where('user_group_id', '!=', $bound->id)->count(), 'the expense/revenue accounts Firefly created on the side were claimed too');
        $this->assertSame(0, $this->okData('POST', '/ingest/plan', ['root' => $this->root])['change_count']);

        $last = $this->okData('GET', '/undo/last');
        $this->okData('POST', '/undo', ['confirm_token' => $last['confirm_token']]);
        $this->assertSame(3, Account::query()->where('user_id', $this->user->id)->count(), 'undo removed the side accounts with the rows');
    }
}
