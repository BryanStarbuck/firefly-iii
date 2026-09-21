<?php

/*
 * BatchRealOpsTest.php
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

namespace Tests\Machine\Undo;

use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;
use Tests\Machine\Search\SearchLedger;

/**
 * pm/apis.mdx §7.4 / §7.5 — POST /batch through the REAL write routes (the closed op set), and
 * one POST /undo reversing the whole batch without leaving an orphan row behind.
 *
 * @internal
 *
 * @coversNothing
 */
final class BatchRealOpsTest extends MachineTestCase
{
    use SearchLedger;

    private const array TABLES = ['categories', 'budgets', 'budget_limits', 'transaction_groups', 'transaction_journals', 'transactions', 'category_transaction_journal', 'budget_transaction_journal', 'machine_operations'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    /** A later operation resolves a name an earlier one created; the plan changes nothing; one undo takes it all back. */
    public function testRealOpsPlanApplyAndOneUndoWithNoOrphans(): void
    {
        $checking = $this->seedSpending($this->user);
        $body     = ['operations' => [
            ['op' => 'category.create', 'name' => 'Groceries'],
            ['op' => 'budget.create', 'name' => 'Food'],
            ['op' => 'budget_limit.set', 'budget_id' => 'Food', 'start' => '2026-10-01', 'end' => '2026-10-31', 'currency_code' => 'USD', 'amount' => '450.00'],
            ['op' => 'transaction.create', 'transactions' => [[
                'type'      => 'withdrawal', 'date' => '2026-10-02', 'amount' => '12.34', 'currency_code' => 'USD', 'description' => 'Batch coffee',
                'source_id' => (string) $checking->id, 'destination_name' => 'Blue Door Cafe', 'category_name' => 'Groceries', 'budget_name' => 'Food',
            ]]],
        ]];
        $before   = $this->hashes();
        $plan     = $this->envelope($this->machine('POST', '/batch', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(4, $plan['data']['change_count']);
        $this->assertSame($before, $this->hashes(), 'the plan changed nothing');

        $apply    = $this->envelope($this->machine('POST', '/batch', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(1, Category::query()->count());
        $this->assertSame(1, Budget::query()->count());
        $this->assertSame(4, TransactionGroup::query()->count());
        $journal  = TransactionJournal::query()->where('description', 'Batch coffee')->firstOrFail();
        $this->assertSame(['Groceries'], $journal->categories()->pluck('name')->all(), 'the category made by operation 0 was resolved by name in operation 3');
        $this->assertSame(['Food'], $journal->budgets()->pluck('name')->all());
        $this->assertSame(1, DB::table('machine_operations')->count(), 'one batch, one operation');

        $undo     = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('POST /batch', $undo['data']['route']);
        $this->assertTrue($undo['data']['reversible'], (string) json_encode($undo['data']['blocked_by']));
        $done     = $this->envelope($this->machine('POST', '/undo', ['confirm_token' => $undo['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Budget::query()->count());
        $this->assertSame(0, DB::table('budget_limits')->count());
        $this->assertSame(3, TransactionGroup::query()->count());
        $this->assertSame(3, TransactionJournal::query()->count(), 'no orphan journal');
        $this->assertSame(6, DB::table('transactions')->count(), 'no orphan transaction');
        $this->assertSame(0, DB::table('category_transaction_journal')->count(), 'no orphan pivot');
        $this->assertSame(0, DB::table('budget_transaction_journal')->count(), 'no orphan pivot');
    }

    /**
     * Regression: a transaction duplicating an EARLIER operation of the same batch was reported as
     * "a duplicate of transaction group #N" with a hint to GET it — an id that only existed inside
     * the rolled-back plan. The refusal now names the operation it duplicates.
     */
    public function testADuplicateOfAnEarlierOperationNamesThatOperation(): void
    {
        $checking = $this->seedSpending($this->user);
        $tx       = ['type' => 'withdrawal', 'date' => '2026-10-02', 'amount' => '12.34', 'currency_code' => 'USD', 'description' => 'Batch coffee', 'source_id' => (string) $checking->id, 'destination_name' => 'Blue Door Cafe'];
        $env      = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'transaction.create', 'transactions' => [$tx]], ['op' => 'transaction.create', 'transactions' => [$tx]]]]), 409, 'conflict');
        $this->assertSame(1, $env['error']['details']['index']);
        $this->assertSame(0, $env['error']['details']['duplicate_of_operation']);
        $this->assertArrayNotHasKey('duplicate_of', $env['error']['details']);
        $this->assertStringContainsString('duplicate of operation 0', $env['error']['message']);
        $this->assertStringNotContainsString('GET /machine/v1/transactions/', (string) $env['error']['hint']);
        $this->assertSame(3, TransactionGroup::query()->count(), 'nothing was written');

        // a duplicate of a row that already EXISTS still names that row
        $this->envelope($this->machine('POST', '/transactions', ['transactions' => [$tx], 'dry_run' => false, 'confirm_token' => $this->envelope($this->machine('POST', '/transactions', ['transactions' => [$tx]]))['data']['confirm_token']]));
        $existing = TransactionGroup::query()->orderByDesc('id')->value('id');
        $env      = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'transaction.create', 'transactions' => [$tx]]]]), 409, 'conflict');
        $this->assertSame((int) $existing, (int) $env['error']['details']['duplicate_of']);
    }

    public function testTheTokenIsBoundToTheOperationsAndAnOperationIsRefusedByName(): void
    {
        $a    = ['operations' => [['op' => 'category.create', 'name' => 'A']]];
        $b    = ['operations' => [['op' => 'category.create', 'name' => 'B']]];
        $plan = $this->envelope($this->machine('POST', '/batch', $a));
        $this->assertPlaneError($this->machine('POST', '/batch', $b + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 403, 'forbidden');
        $this->assertSame(0, Category::query()->count());

        // a token minted by another route never applies a batch
        $cat  = $this->envelope($this->machine('POST', '/categories', ['name' => 'Other']));
        $this->assertPlaneError($this->machine('POST', '/batch', $a + ['dry_run' => false, 'confirm_token' => $cat['data']['confirm_token']]), 403, 'forbidden');

        // an operation's own refusals come through with the index: an unknown field, a missing
        // path field, a name that resolves to nothing, and another user's record
        $env  = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'category.create', 'name' => 'A', 'colour' => 'red']]]), 400, 'invalid_input');
        $this->assertSame(0, $env['error']['details']['index']);
        $this->assertSame(['colour'], $env['error']['details']['unknown']);
        $env  = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'budget_limit.set', 'budget_id' => '', 'start' => '2026-01-01']]]), 400, 'invalid_input');
        $this->assertSame('budget_id', $env['error']['details']['field']);
        $env  = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'budget_limit.set', 'budget_id' => 'Nope', 'start' => '2026-01-01', 'end' => '2026-01-31', 'amount' => '1']]]), 404, 'not_found');
        $this->assertSame(0, $env['error']['details']['index']);
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 5]]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => 'x']), 400, 'invalid_input');

        $other = User::create(['email' => 'other@example.test', 'password' => bcrypt('x')]);
        config(['machine.operator' => 'test@email.com']);
        $theirs = Category::create(['name' => 'Theirs', 'user_id' => $other->id, 'user_group_id' => $other->user_group_id]);
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'category.update', 'category_id' => (string) $theirs->id, 'name' => 'Mine now']]]), 404, 'not_found');
        $this->assertSame('Theirs', Category::query()->where('id', $theirs->id)->value('name'));
    }

    public function testAnIdempotencyKeyReplaysTheBatch(): void
    {
        $one  = ['operations' => [['op' => 'category.create', 'name' => 'Idem']]];
        $plan = $this->envelope($this->machine('POST', '/batch', $one));
        $a    = $this->envelope($this->machine('POST', '/batch', $one + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token'], 'idempotency_key' => 'k1']));
        $this->assertTrue($a['ok'], (string) json_encode($a));
        $b    = $this->envelope($this->machine('POST', '/batch', $one + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token'], 'idempotency_key' => 'k1']));
        $this->assertTrue($b['ok'], (string) json_encode($b));
        $this->assertTrue($b['meta']['replayed']);
        $this->assertSame($a['data']['operation_id'], $b['data']['operation_id']);
        $this->assertSame(1, Category::query()->count(), 'the replay created nothing');
    }

    /** @return array<string, string> */
    private function hashes(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all()));
        }

        return $out;
    }
}
