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

namespace Tests\Machine\Transactions;

use FireflyIII\Models\Category;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * Regressions from the adversarial review of the transactions family (apis.mdx §8.3, §7):
 *
 *   - a multi-split edit without transaction_journal_id used to be a silent no-op ("unchanged")
 *     because Firefly treats such a split as a NEW one and drops it when incomplete;
 *   - a re-split never removes the splits it does not name (a write-tier route never deletes a
 *     journal, §7.6) — Firefly's own PUT would;
 *   - a null (or "", which Laravel reads as null) category_name clears, like a null id, and the
 *     plan shows it;
 *   - the ceiling refusal counts every journal that would change, without writing past the
 *     ceiling inside the rolled-back preview — and the real apply writes them all;
 *   - another administration's transactions are invisible on every route that takes an id.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReviewRegressionTest extends MachineTestCase
{
    use TransactionFixtures;

    private int $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousehold();
        $this->enableWrites();
        config(['machine.operator' => 'test@email.com']);
        $this->group = $this->spend('42.00', 'Hardware Store', '2026-09-10');
    }

    /** Turn the one-split group into two splits, as a re-split does. */
    private function split(): TransactionJournal
    {
        $journal = TransactionJournal::query()->where('transaction_group_id', $this->group)->firstOrFail();
        $this->applied('PUT', '/transactions/'.$this->group, [
            'group_title'  => 'Hardware run',
            'transactions' => [
                ['transaction_journal_id' => (string) $journal->id, 'amount' => '30.00', 'description' => 'Brackets'],
                ['type' => 'withdrawal', 'date' => '2026-09-10', 'amount' => '12.00', 'description' => 'Screws', 'source_id' => (string) $this->checking->id, 'destination_name' => 'Hardware Store'],
            ],
        ]);
        $this->assertSame(2, TransactionJournal::query()->where('transaction_group_id', $this->group)->count());

        return $journal;
    }

    public function testAMultiSplitEditWithoutAJournalIdIsRefusedNotSilentlyIgnored(): void
    {
        $journal = $this->split();
        $before  = $this->ledgerHash();
        // the CLI's shape ("ffx transactions update N --description …") against a split group
        $env     = $this->assertPlaneError($this->machine('PUT', '/transactions/'.$this->group, ['transactions' => [['description' => 'Renamed']]]), 400, 'invalid_input');
        $this->assertSame('transactions.0.transaction_journal_id', $env['error']['details']['field']);
        $this->assertContains('amount', $env['error']['details']['missing']);
        $this->assertContains($journal->id, $env['error']['details']['journal_ids']);
        $this->assertStringContainsString((string) $journal->id, $env['error']['hint']);
        $this->assertStringContainsString('kept', $env['error']['hint']);
        $this->assertSame($before, $this->ledgerHash());

        // naming the split is the fix, and it works
        $done    = $this->applied('PUT', '/transactions/'.$this->group, ['transactions' => [['transaction_journal_id' => (string) $journal->id, 'description' => 'Renamed']]]);
        $this->assertSame(1, $done['data']['changes']['updated']);
        $this->assertSame(2, TransactionJournal::query()->where('transaction_group_id', $this->group)->count(), 'the other split survived');
        $this->assertSame('Renamed', TransactionJournal::query()->find($journal->id)?->description);

        // a one-split group still takes the simple shape
        $solo    = $this->spend('5.00', 'Kiosk', '2026-09-12');
        $done    = $this->applied('PUT', '/transactions/'.$solo, ['transactions' => [['description' => 'Newspaper']]]);
        $this->assertSame('Newspaper', $done['data']['transactions'][0]['transactions'][0]['description']);
    }

    public function testAnUnnamedSplitIsKeptNeverRemovedByAWriteTierEdit(): void
    {
        $journal = $this->split();
        $other   = (int) TransactionJournal::query()->where('transaction_group_id', $this->group)->where('id', '!=', $journal->id)->value('id');
        // Firefly's own PUT would delete the split that is not listed; the plane keeps it (§7.6)
        $plan    = $this->envelope($this->machine('PUT', '/transactions/'.$this->group, ['transactions' => [['transaction_journal_id' => (string) $journal->id, 'description' => 'Only this one']]]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertArrayNotHasKey('splits_removed', $plan['data']['changes']);
        $this->assertSame([(string) $other], $plan['data']['splits_kept']);
        $this->assertStringContainsString('DELETE /machine/v1/transaction-journals', (string) $plan['data']['note']);
        $done    = $this->applied('PUT', '/transactions/'.$this->group, ['transactions' => [['transaction_journal_id' => (string) $journal->id, 'description' => 'Only this one']]]);
        $this->assertSame(1, $done['data']['changes']['updated']);
        $this->assertSame(2, TransactionJournal::query()->where('transaction_group_id', $this->group)->count());
        $this->assertSame('Screws', TransactionJournal::query()->find($other)?->description, 'the unnamed split is untouched');

        // adding a third split names nobody and still keeps both
        $done    = $this->applied('PUT', '/transactions/'.$this->group, ['transactions' => [
            ['type' => 'withdrawal', 'date' => '2026-09-10', 'amount' => '1.00', 'description' => 'Washers', 'source_id' => (string) $this->checking->id, 'destination_name' => 'Hardware Store'],
        ]]);
        $this->assertSame(1, $done['data']['changes']['splits_added']);
        $this->assertSame(3, TransactionJournal::query()->where('transaction_group_id', $this->group)->count());
    }

    public function testANullNameClearsLikeANullIdAndThePlanSaysSo(): void
    {
        // Laravel reads "" as null on the wire, so category_name: "" IS category_name: null — a
        // clear, exactly like category_id: null; the plan must show the category going away
        $this->category('Tools');
        $journal = TransactionJournal::query()->where('transaction_group_id', $this->group)->firstOrFail();
        $this->applied('POST', '/transactions/categorize', ['journal_ids' => [(string) $journal->id], 'category_name' => 'Tools']);
        $this->assertSame('Tools', $journal->categories()->value('name'));

        $plan    = $this->envelope($this->machine('POST', '/transactions/categorize', ['journal_ids' => [(string) $journal->id], 'category_name' => '']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('Tools', $plan['data']['affected'][0]['category_name']);
        $this->assertNull($plan['data']['affected'][0]['new_category_name']);
        $this->assertSame(1, $plan['data']['changes']['updated']);
        $this->assertSame('Tools', $journal->categories()->value('name'), 'a plan clears nothing');

        $env     = $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['journal_ids' => [(string) $journal->id]]), 400, 'invalid_input');
        $this->assertStringContainsString('null', $env['error']['hint']);
        $this->applied('POST', '/transactions/categorize', ['journal_ids' => [(string) $journal->id], 'category_id' => null]);
        $this->assertNull($journal->categories()->value('name'));
    }

    public function testTheCeilingCountsEverythingButOnlyTheApplyWritesEverything(): void
    {
        $this->category('Misc');
        $this->spend('1.00', 'A', '2026-09-11');
        $this->spend('2.00', 'B', '2026-09-12');
        $this->spend('3.00', 'C', '2026-09-13');
        $body    = ['filter' => ['start' => '2026-09-01'], 'category_name' => 'Misc'];
        $before  = $this->ledgerHash();

        $refused = $this->assertPlaneError($this->machine('POST', '/transactions/categorize', $body + ['max_changes' => 2]), 409, 'conflict');
        $this->assertSame(4, $refused['error']['details']['change_count'], 'the real count, past the ceiling');
        $this->assertSame(4, $refused['error']['details']['changes']['updated']);
        $this->assertSame($before, $this->ledgerHash());

        $plan    = $this->envelope($this->machine('POST', '/transactions/categorize', $body + ['max_changes' => 4]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(4, $plan['data']['change_count']);
        $this->assertCount(4, $plan['data']['affected']);
        $done    = $this->envelope($this->machine('POST', '/transactions/categorize', $body + ['max_changes' => 4, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame(4, $done['data']['changes']['updated']);
        $this->assertSame(4, DB::table('category_transaction_journal')->count(), 'every journal was written, not just the first');
    }

    public function testAnotherAdministrationsTransactionsAreInvisible(): void
    {
        $ownJournal = (int) TransactionJournal::query()->where('transaction_group_id', $this->group)->value('id');
        $otherGroup = $this->otherAdministrationGroup();
        $theirs     = (int) TransactionJournal::query()->where('transaction_group_id', $otherGroup)->value('id');

        $env        = $this->envelope($this->machine('GET', '/transactions'));
        $this->assertSame([$this->group], array_map(static fn (array $g): int => (int) $g['id'], $env['data']['transactions']));
        $this->assertPlaneError($this->machine('GET', '/transactions/'.$otherGroup), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/transactions/'.$otherGroup.'/links'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/transaction-journals/'.$theirs), 404, 'not_found');
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$otherGroup, ['group_title' => 'Mine now']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/transactions/'.$otherGroup.'/clone', []), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/transactions/'.$otherGroup.'/convert', ['to_type' => 'deposit']), 404, 'not_found');
        $env        = $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['journal_ids' => [(string) $theirs], 'category_name' => 'X']), 404, 'not_found');
        $this->assertSame([(string) $theirs], $env['error']['details']['missing']);
        $this->assertPlaneError($this->machine('POST', '/transaction-links', ['link_type_name' => 'Refund', 'inward_id' => (string) $ownJournal, 'outward_id' => (string) $theirs]), 404, 'not_found');
        $this->enableAdmin();
        $this->assertPlaneError($this->machine('DELETE', '/transactions/'.$otherGroup), 404, 'not_found');
        $this->assertPlaneError($this->machine('DELETE', '/transaction-journals/'.$theirs), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/transactions/mass-delete', ['group_ids' => [(string) $otherGroup]]), 404, 'not_found');
        $this->assertNotNull(TransactionGroup::query()->find($otherGroup), 'untouched');

        // their category is not a target for our writes, by id or by name
        $theirCat   = Category::query()->where('name', 'Their Category')->firstOrFail();
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['journal_ids' => [(string) $ownJournal], 'category_id' => (string) $theirCat->id]), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/transactions', ['category_id' => (string) $theirCat->id]), 404, 'not_found');
    }

    public function testAGroupTitleOnlyEditChangesNothingElse(): void
    {
        $done = $this->applied('PUT', '/transactions/'.$this->group, ['group_title' => 'Saturday errands']);
        $this->assertSame(1, $done['data']['changes']['updated']);
        $this->assertSame('Saturday errands', $done['data']['transactions'][0]['group_title']);
        $this->assertSame('42.00', $done['data']['transactions'][0]['transactions'][0]['amount']);
        $this->assertSame(1, TransactionJournal::query()->where('transaction_group_id', $this->group)->count());
    }

    /** A second user in a second administration, with one withdrawal and one category of their own. */
    private function otherAdministrationGroup(): int
    {
        $userGroup = UserGroup::create(['title' => 'acme_llc']);
        $role      = UserRole::query()->where('title', 'owner')->firstOrFail();
        $other     = User::create(['email' => 'books@acme-llc.invalid', 'password' => 'password', 'user_group_id' => $userGroup->id]);
        GroupMembership::create(['user_id' => $other->id, 'user_group_id' => $userGroup->id, 'user_role_id' => $role->id]);
        Category::query()->create(['user_id' => $other->id, 'user_group_id' => $userGroup->id, 'name' => 'Their Category']);

        $mine      = $this->user;
        $this->user = $other;
        auth()->setUser($other);
        $usd       = \FireflyIII\Models\TransactionCurrency::query()->where('code', 'USD')->firstOrFail();
        $userGroup->currencies()->syncWithoutDetaching([$usd->id => ['group_default' => true]]);
        $checking  = $this->account('Meridian Business 7734', 'asset');
        $groupId   = (int) TransactionGroup::query()->create(['user_id' => $other->id, 'user_group_id' => $userGroup->id, 'title' => null])->id;
        $type      = \FireflyIII\Models\TransactionType::query()->where('type', 'Withdrawal')->firstOrFail();
        $payee     = $this->account('Their Supplier', 'expense');
        $journal   = TransactionJournal::query()->create([
            'user_id' => $other->id, 'user_group_id' => $userGroup->id, 'transaction_group_id' => $groupId, 'transaction_type_id' => $type->id,
            'transaction_currency_id' => $usd->id, 'description' => 'Their purchase', 'date' => '2026-09-10 00:00:00', 'order' => 0, 'tag_count' => 0, 'completed' => true,
        ]);
        \FireflyIII\Models\Transaction::query()->create(['transaction_journal_id' => $journal->id, 'account_id' => $checking->id, 'transaction_currency_id' => $usd->id, 'amount' => '-9.00']);
        \FireflyIII\Models\Transaction::query()->create(['transaction_journal_id' => $journal->id, 'account_id' => $payee->id, 'transaction_currency_id' => $usd->id, 'amount' => '9.00']);
        $this->user = $mine;
        auth()->setUser($mine);

        return $groupId;
    }
}
