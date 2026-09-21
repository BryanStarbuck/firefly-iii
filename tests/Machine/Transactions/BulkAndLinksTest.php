<?php

/*
 * BulkAndLinksTest.php
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
use FireflyIII\Models\LinkType;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * POST /transactions/bulk, /categorize, /set-budget, /transaction-links, DELETE /transaction-links/{id},
 * and the admin deletes — apis.mdx §8.3, §7 (ceiling reports the real count), §6.1 (admin tier,
 * never the MCP), §5.6 (a delete of something gone is ok, deleted 0).
 *
 * @internal
 *
 * @coversNothing
 */
final class BulkAndLinksTest extends MachineTestCase
{
    use TransactionFixtures;

    /** @var list<int> */
    private array $groups = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousehold();
        $this->enableWrites();
        $this->groups[] = $this->spend('61.20', 'WHOLE FOODS #102', '2026-09-03');
        $this->groups[] = $this->spend('18.75', 'WHOLE FOODS #102', '2026-09-09');
        $this->groups[] = $this->spend('9.99', 'Streaming Co', '2026-09-11');
    }

    private function journal(int $group): int
    {
        return (int) TransactionJournal::query()->where('transaction_group_id', $group)->value('id');
    }

    private function categoryOf(int $group): ?string
    {
        return TransactionJournal::query()->where('transaction_group_id', $group)->firstOrFail()->categories()->value('name');
    }

    public function testCategorizeByFilterDryRunThenApplyAndUndo(): void
    {
        $this->category('Groceries');
        $before = $this->ledgerHash();
        $body   = ['filter' => ['search' => 'whole foods', 'without_category' => true], 'category_name' => 'groceries'];
        $plan   = $this->envelope($this->machine('POST', '/transactions/categorize', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(2, $plan['data']['changes']['updated']);
        $this->assertSame(2, $plan['data']['change_count']);
        $this->assertSame('Groceries', $plan['data']['affected'][0]['new_category_name']);
        $this->assertSame($before, $this->ledgerHash());

        $done   = $this->envelope($this->machine('POST', '/transactions/categorize', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame('Groceries', $this->categoryOf($this->groups[0]));
        $this->assertSame('Groceries', $this->categoryOf($this->groups[1]));
        $this->assertNull($this->categoryOf($this->groups[2]));

        auth()->setUser($this->user);
        DB::transaction(static fn () => OperationLog::reverse((int) $done['data']['operation_id']));
        $this->assertNull($this->categoryOf($this->groups[0]), 'undo removed the category link again');
    }

    public function testTheCeilingReportsTheRealCount(): void
    {
        $this->category('Misc');
        $env = $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['filter' => ['start' => '2026-09-01'], 'category_name' => 'Misc', 'max_changes' => 1]), 409, 'conflict');
        $this->assertSame(3, $env['error']['details']['change_count']);
        $this->assertStringContainsString('3', $env['error']['hint']);
    }

    public function testSelectionRefusals(): void
    {
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['category_name' => 'X']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['journal_ids' => ['1'], 'filter' => ['start' => '2026-09-01'], 'category_name' => 'X']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['filter' => [], 'category_name' => 'X']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['filter' => ['start_date' => '2026-09-01'], 'category_name' => 'X']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['journal_ids' => ['999999'], 'category_name' => 'X']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['journal_ids' => ['1']]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/set-budget', ['journal_ids' => ['1'], 'budget_name' => 'Nope']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/transactions/bulk', ['journal_ids' => ['1'], 'set' => ['colour' => 'red']]), 400, 'invalid_input');
    }

    public function testSetBudgetAndClearIt(): void
    {
        $budget = $this->budget('Food');
        $ids    = [(string) $this->journal($this->groups[0]), (string) $this->journal($this->groups[1])];
        $this->applied('POST', '/transactions/set-budget', ['journal_ids' => $ids, 'budget_id' => (string) $budget->id]);
        $this->assertSame(2, DB::table('budget_transaction_journal')->where('budget_id', $budget->id)->count());
        $plan   = $this->envelope($this->machine('POST', '/transactions/set-budget', ['journal_ids' => $ids, 'budget_id' => (string) $budget->id]));
        $this->assertSame(0, $plan['data']['change_count'], 'nothing left to change');
        $this->assertSame(2, $plan['data']['changes']['unchanged']);
        $this->applied('POST', '/transactions/set-budget', ['journal_ids' => $ids, 'budget_id' => null]);
        $this->assertSame(0, DB::table('budget_transaction_journal')->count());
    }

    public function testBulkTags(): void
    {
        $id = (string) $this->journal($this->groups[2]);
        $this->applied('POST', '/transactions/bulk', ['journal_ids' => [$id], 'set' => ['tags_add' => ['subscription', 'tax-2026']]]);
        $this->applied('POST', '/transactions/bulk', ['journal_ids' => [$id], 'set' => ['tags_remove' => ['tax-2026'], 'category_name' => 'Streaming']]);
        $journal = TransactionJournal::query()->findOrFail((int) $id);
        $this->assertSame(['subscription'], $journal->tags()->pluck('tag')->all());
        $this->assertSame('Streaming', $this->categoryOf($this->groups[2]));
        $this->assertPlaneError($this->machine('POST', '/transactions/bulk', ['journal_ids' => [$id], 'set' => ['tags_replace' => ['a'], 'tags_add' => ['b']]]), 400, 'invalid_input');
    }

    public function testLinkAndUnlink(): void
    {
        $type  = LinkType::query()->firstOrFail();
        $a     = $this->journal($this->groups[0]);
        $b     = $this->journal($this->groups[1]);
        $done  = $this->applied('POST', '/transaction-links', ['link_type_name' => $type->name, 'inward_id' => (string) $a, 'outward_id' => (string) $b, 'notes' => 'split receipt']);
        $link  = $done['data']['link'];
        $this->assertSame((string) $b, $link['other_journal_id']);
        $env   = $this->envelope($this->machine('GET', '/transactions/'.$this->groups[1].'/links'));
        $this->assertCount(1, $env['data']['links']);
        // the same link twice is Firefly's validation error
        $this->assertPlaneError($this->machine('POST', '/transaction-links', ['link_type_name' => $type->name, 'inward_id' => (string) $a, 'outward_id' => (string) $b]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transaction-links', ['link_type_name' => 'Nope', 'inward_id' => (string) $a, 'outward_id' => (string) $b]), 404, 'not_found');

        $this->applied('DELETE', '/transaction-links/'.$link['id']);
        $this->assertSame(0, DB::table('journal_links')->count());
        $this->assertSame(3, TransactionGroup::query()->count(), 'the transactions survive');
        $gone  = $this->envelope($this->machine('DELETE', '/transaction-links/'.$link['id']));
        $this->assertTrue($gone['ok']);
        $this->assertSame(0, $gone['data']['deleted']);
    }

    public function testDeletesAreAdminTierAndNeverFromTheMcp(): void
    {
        $this->assertPlaneError($this->machine('DELETE', '/transactions/'.$this->groups[0]), 403, 'forbidden');
        $this->enableAdmin();
        $this->assertPlaneError($this->machine('DELETE', '/transactions/'.$this->groups[0], [], ['X-Firefly-Client' => 'mcp']), 403, 'forbidden');

        $before = $this->ledgerHash();
        $plan   = $this->envelope($this->machine('DELETE', '/transactions/'.$this->groups[0]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['deleted']);
        $this->assertSame($before, $this->ledgerHash());
        $done   = $this->applied('DELETE', '/transactions/'.$this->groups[0]);
        $this->assertNull(TransactionGroup::query()->find($this->groups[0]));
        $again  = $this->envelope($this->machine('DELETE', '/transactions/'.$this->groups[0]));
        $this->assertSame(0, $again['data']['deleted'], 'a retry terminates');

        // undo restores the soft-deleted group
        auth()->setUser($this->user);
        DB::transaction(static fn () => OperationLog::reverse((int) $done['data']['operation_id']));
        $this->assertNotNull(TransactionGroup::query()->find($this->groups[0]));
    }

    public function testDeleteOneJournalAndMassDelete(): void
    {
        $this->enableAdmin();
        $this->applied('DELETE', '/transaction-journals/'.$this->journal($this->groups[2]));
        $this->assertNull(TransactionJournal::query()->find($this->journal($this->groups[2])));

        $this->assertPlaneError($this->machine('POST', '/transactions/mass-delete', ['group_ids' => ['999999']]), 404, 'not_found');
        $done = $this->applied('POST', '/transactions/mass-delete', ['group_ids' => [(string) $this->groups[0], (string) $this->groups[1], (string) $this->groups[2]]]);
        $this->assertSame(2, $done['data']['deleted']);
        $this->assertSame(1, $done['data']['already_deleted']);
        $this->assertSame(0, TransactionGroup::query()->count());
    }
}
