<?php

/*
 * StoreTransactionTest.php
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

use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * POST /transactions — apis.mdx §8.3, §7, §11.6–11.7: dry run by default and byte-identical,
 * token + apply, Firefly's duplicate check pinned on (conflict naming the original), names
 * resolved server-side, money as decimal strings, the transfer hint, undo.
 *
 * @internal
 *
 * @coversNothing
 */
final class StoreTransactionTest extends MachineTestCase
{
    use TransactionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousehold();
        $this->enableWrites();
    }

    private function coffee(array $extra = []): array
    {
        return ['transactions' => [array_merge([
            'type'             => 'withdrawal',
            'date'             => '2026-09-14',
            'amount'           => '5.00',
            'description'      => 'Coffee',
            'source_name'      => 'Northbank Checking 4021',
            'destination_name' => 'Blue Bottle',
        ], $extra)]];
    }

    public function testADryRunChangesNothingAndPreviewsWhatFireflyWouldDo(): void
    {
        $before = $this->ledgerHash();
        $env    = $this->envelope($this->machine('POST', '/transactions', $this->coffee(['category_name' => 'Dining'])));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertTrue($env['data']['dry_run']);
        $this->assertSame(1, $env['data']['changes']['created']);
        $this->assertSame(1, $env['data']['changes']['accounts_created'], 'the new payee is reported');
        $this->assertSame(1, $env['data']['changes']['categories_created']);
        $this->assertSame('Blue Bottle', $env['data']['created_alongside']['accounts'][0]['name']);
        $split  = $env['data']['transactions'][0]['transactions'][0];
        $this->assertSame('5.00', $split['amount']);
        $this->assertSame('USD', $split['currency_code']);
        $this->assertSame('Dining', $split['category_name']);
        $this->assertMatchesRegularExpression('/^cf_/', $env['data']['confirm_token']);
        $this->assertSame($before, $this->ledgerHash(), 'the dry run left every ledger table byte-identical');
    }

    public function testApplyStoresOnceAndTheDuplicateIsAConflictNamingTheOriginal(): void
    {
        $done    = $this->applied('POST', '/transactions', $this->coffee());
        $groupId = (int) $done['data']['group_id'];
        $this->assertNotNull(TransactionGroup::query()->find($groupId));
        $this->assertIsInt($done['data']['operation_id']);

        $dup     = $this->machine('POST', '/transactions', $this->coffee());
        $env     = $this->assertPlaneError($dup, 409, 'conflict');
        $this->assertSame($groupId, $env['error']['details']['duplicate_of']);
        $this->assertStringContainsString((string) $groupId, $env['error']['hint']);
    }

    public function testTwoCoffeesWithDifferentExternalIdsBothStore(): void
    {
        $this->applied('POST', '/transactions', $this->coffee(['external_id' => 'ff1:4021:20260914:5.00:0:abcd1234']));
        $this->applied('POST', '/transactions', $this->coffee(['external_id' => 'ff1:4021:20260914:5.00:1:abcd1234']));
        $this->assertSame(2, DB::table('transaction_journals')->whereNull('deleted_at')->where('description', 'Coffee')->count());
    }

    public function testErrorIfDuplicateHashCannotBeTurnedOff(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee() + ['error_if_duplicate_hash' => false]), 400, 'invalid_input');
        $this->assertSame('error_if_duplicate_hash', $env['error']['details']['field']);
    }

    public function testAnApplyWithoutATokenIsRefused(): void
    {
        $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee() + ['dry_run' => false]), 403, 'forbidden');
    }

    public function testValidationRefusals(): void
    {
        // a JSON number for an amount
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['amount' => 5])), 400, 'invalid_input');
        $this->assertStringContainsString('decimal string', $env['error']['message']);
        // a negative amount names the positive one
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['amount' => '-5.00'])), 400, 'invalid_input');
        $this->assertStringContainsString('"5.00"', $env['error']['hint']);
        // more places than USD has
        $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['amount' => '5.001', 'currency_code' => 'USD'])), 400, 'invalid_input');
        // an unknown split field with a did-you-mean
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['category' => 'Dining'])), 400, 'invalid_input');
        $this->assertStringContainsString('category_name', $env['error']['hint']);
        // an unknown top-level field
        $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee() + ['start_date' => '2026-01-01']), 400, 'invalid_input');
        // Firefly's own validation (no description)
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['description' => null])), 400, 'invalid_input');
        $this->assertArrayHasKey('fields', $env['error']['details']);
        // an unknown budget name is not_found, not silently dropped
        $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['budget_name' => 'Nope'])), 404, 'not_found');
    }

    public function testAWithdrawalBetweenTwoOwnAccountsIsRefusedWithTheTransferHint(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->coffee(['destination_name' => 'Meridian Savings 7734'])), 400, 'invalid_input');
        $this->assertStringContainsString('transfer', $env['error']['hint']);
        $this->assertSame(0, Account::query()->where('name', 'Meridian Savings 7734')->count() - 1, 'no expense account was invented');
    }

    public function testATransferIsOneRow(): void
    {
        $done  = $this->applied('POST', '/transactions', ['transactions' => [[
            'type' => 'transfer', 'date' => '2026-09-15', 'amount' => '250.00', 'description' => 'To savings',
            'source_id' => (string) $this->checking->id, 'destination_id' => (string) $this->savings->id,
        ]]]);
        $split = $done['data']['transactions'][0]['transactions'][0];
        $this->assertSame('transfer', $split['type']);
        $this->assertSame('250.00', $split['amount']);
    }

    public function testBudgetNameIsResolvedCaseInsensitively(): void
    {
        $budget = $this->budget('Groceries');
        $done   = $this->applied('POST', '/transactions', $this->coffee(['budget_name' => 'groceries', 'description' => 'Market']));
        $this->assertSame((string) $budget->id, $done['data']['transactions'][0]['transactions'][0]['budget_id']);
    }

    public function testIdempotencyKeyReplaysTheOriginal(): void
    {
        $body  = $this->coffee() + ['idempotency_key' => 'k-001'];
        $plan  = $this->envelope($this->machine('POST', '/transactions', $body));
        $first = $this->envelope($this->machine('POST', '/transactions', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($first['ok']);
        $again = $this->envelope($this->machine('POST', '/transactions', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($again['ok'], (string) json_encode($again));
        $this->assertTrue($again['meta']['replayed']);
        $this->assertSame($first['data']['group_id'], $again['data']['group_id']);
    }

    public function testTheOperationLogUndoesTheTransactionAndThePayeeItCreated(): void
    {
        $before = DB::table('accounts')->count();
        $done   = $this->applied('POST', '/transactions', $this->coffee(['category_name' => 'Dining']));
        $this->assertSame($before + 1, DB::table('accounts')->count());
        auth()->setUser($this->user);
        DB::transaction(static fn () => OperationLog::reverse((int) $done['data']['operation_id']));
        $this->assertNull(TransactionGroup::withTrashed()->find((int) $done['data']['group_id']));
        $this->assertSame($before, DB::table('accounts')->count());
        $this->assertSame(0, DB::table('categories')->where('name', 'Dining')->count());
    }
}
