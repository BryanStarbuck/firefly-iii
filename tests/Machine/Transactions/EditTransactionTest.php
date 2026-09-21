<?php

/*
 * EditTransactionTest.php
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

use Carbon\Carbon;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * PUT /transactions/{id}, POST /transactions/{id}/convert, POST /transactions/{id}/clone —
 * apis.mdx §8.3 and §7: dry runs change nothing, the token applies, a stale token after a
 * human edit is a conflict with the new counts, undo restores the before-image.
 *
 * @internal
 *
 * @coversNothing
 */
final class EditTransactionTest extends MachineTestCase
{
    use TransactionFixtures;

    private int $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousehold();
        $this->enableWrites();
        $this->group = $this->spend('42.00', 'Hardware Store', '2026-09-10');
    }

    public function testUpdateDryRunThenApply(): void
    {
        $this->category('Home');
        $before = $this->ledgerHash();
        $body   = ['transactions' => [['description' => 'Shelf brackets', 'amount' => '43.50', 'category_name' => 'Home']]];
        $plan   = $this->envelope($this->machine('PUT', '/transactions/'.$this->group, $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['updated']);
        $this->assertSame('43.50', $plan['data']['transactions'][0]['transactions'][0]['amount'], 'the preview shows the edited row');
        $this->assertSame($before, $this->ledgerHash());

        $done   = $this->envelope($this->machine('PUT', '/transactions/'.$this->group, $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $split  = $done['data']['transactions'][0]['transactions'][0];
        $this->assertSame('Shelf brackets', $split['description']);
        $this->assertSame('43.50', $split['amount']);
        $this->assertSame('Home', $split['category_name']);
        $this->assertFalse($done['data']['rules_applied']);

        // undo writes the before-image back — amount, description and the category link
        auth()->setUser($this->user);
        DB::transaction(static fn () => OperationLog::reverse((int) $done['data']['operation_id']));
        $env    = $this->envelope($this->machine('GET', '/transactions/'.$this->group));
        $split  = $env['data']['transaction']['transactions'][0];
        $this->assertSame('42.00', $split['amount']);
        $this->assertSame('Purchase at Hardware Store', $split['description']);
        $this->assertNull($split['category_name']);
    }

    public function testAStaleTokenAfterAHumanEditIsAConflict(): void
    {
        $body = ['transactions' => [['description' => 'Program edit']]];
        $plan = $this->envelope($this->machine('PUT', '/transactions/'.$this->group, $body));
        // the operator edits the same row in the browser meanwhile
        DB::table('transaction_journals')->where('transaction_group_id', $this->group)->update(['description' => 'Human edit', 'updated_at' => Carbon::now()->addMinute()]);
        $env  = $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertArrayHasKey('changes', $env['error']['details']);
        $this->assertSame('Human edit', TransactionJournal::query()->where('transaction_group_id', $this->group)->value('description'));
    }

    public function testUpdateRefusals(): void
    {
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, []), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, ['transactions' => [['amount' => '-1.00']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, ['transactions' => [['colour' => 'red']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, ['transactions' => [['transaction_journal_id' => '999999', 'description' => 'x']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/transactions/999999', ['transactions' => [['description' => 'x']]]), 404, 'not_found');
    }

    public function testReSplittingThroughTransactions(): void
    {
        $journal = TransactionJournal::query()->where('transaction_group_id', $this->group)->firstOrFail();
        $done    = $this->applied('PUT', '/transactions/'.$this->group, [
            'group_title'  => 'Hardware run',
            'transactions' => [
                ['transaction_journal_id' => (string) $journal->id, 'amount' => '30.00', 'description' => 'Brackets'],
                ['type' => 'withdrawal', 'date' => '2026-09-10', 'amount' => '12.00', 'description' => 'Screws', 'source_id' => (string) $this->checking->id, 'destination_name' => 'Hardware Store'],
            ],
        ]);
        $this->assertSame(1, $done['data']['changes']['splits_added']);
        $this->assertCount(2, $done['data']['transactions'][0]['transactions']);
        $this->assertSame('Hardware run', $done['data']['transactions'][0]['group_title']);
    }

    public function testConvertAWithdrawalToATransfer(): void
    {
        $refused = $this->assertPlaneError($this->machine('POST', '/transactions/'.$this->group.'/convert', ['to_type' => 'transfer']), 400, 'invalid_input');
        $this->assertSame('destination_id', $refused['error']['details']['field']);
        $this->assertPlaneError($this->machine('POST', '/transactions/'.$this->group.'/convert', ['to_type' => 'withdrawal']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/'.$this->group.'/convert', ['to_type' => 'loan']), 400, 'invalid_input');

        $before  = $this->ledgerHash();
        $plan    = $this->envelope($this->machine('POST', '/transactions/'.$this->group.'/convert', ['to_type' => 'transfer', 'destination_id' => (string) $this->savings->id]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['converted']);
        $this->assertSame($before, $this->ledgerHash());
        $done    = $this->applied('POST', '/transactions/'.$this->group.'/convert', ['to_type' => 'transfer', 'destination_id' => (string) $this->savings->id]);
        $split   = $done['data']['transactions'][0]['transactions'][0];
        $this->assertSame('transfer', $split['type']);
        $this->assertSame('Meridian Savings 7734', $split['destination_name']);
        $this->assertSame('Northbank Checking 4021', $split['source_name']);
    }

    public function testConvertAWithdrawalToADepositDefaultsTheSidesLikeTheUi(): void
    {
        $done  = $this->applied('POST', '/transactions/'.$this->group.'/convert', ['to_type' => 'deposit']);
        $split = $done['data']['transactions'][0]['transactions'][0];
        $this->assertSame('deposit', $split['type']);
        $this->assertSame('Hardware Store', $split['source_name'], 'the payee became the payer, by name');
        $this->assertSame('Northbank Checking 4021', $split['destination_name']);
        $this->assertSame('42.00', $split['amount']);
    }

    public function testCloneToADate(): void
    {
        $before = $this->ledgerHash();
        $plan   = $this->envelope($this->machine('POST', '/transactions/'.$this->group.'/clone', ['date' => '2026-10-10']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['created']);
        $this->assertSame($before, $this->ledgerHash());
        $done   = $this->applied('POST', '/transactions/'.$this->group.'/clone', ['date' => '2026-10-10']);
        $clone  = (int) $done['data']['group_id'];
        $this->assertNotSame($this->group, $clone);
        $this->assertStringStartsWith('2026-10-10', $done['data']['transactions'][0]['transactions'][0]['date']);
        $this->assertSame('42.00', $done['data']['transactions'][0]['transactions'][0]['amount']);
        $this->assertSame(2, TransactionGroup::query()->count());
        $this->assertPlaneError($this->machine('POST', '/transactions/'.$this->group.'/clone', ['date' => 'next week']), 400, 'invalid_input');
    }

    public function testWritesNeedTheWriteTier(): void
    {
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, ['transactions' => [['description' => 'x']]]), 403, 'write_disabled');
    }
}
