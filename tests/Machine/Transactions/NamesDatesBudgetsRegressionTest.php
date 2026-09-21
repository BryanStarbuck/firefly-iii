<?php

/*
 * NamesDatesBudgetsRegressionTest.php
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

use FireflyIII\Machine\Transactions\Snapshot;
use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * Regressions from the second adversarial review of the transactions family:
 *
 *   - a split's source_name / destination_name / category_name went to Firefly's own
 *     case-SENSITIVE lookup, so "corner cafe" created a second payee beside "Corner Cafe" and
 *     "COFFEE" a second category beside "Coffee", while "northbank checking 4021" was refused —
 *     apis.mdx §8 and §14.4 say exact first, then case-insensitive, ambiguity refused with the
 *     candidates; the same for convert's source_name / destination_name;
 *   - an unparseable date ("2026-13-01") passed upstream's rule, its converter read it as null,
 *     and the factory dated the row TODAY (§7.6: never invent a value; §14.3);
 *   - a budget on a deposit or a transfer was dropped silently (§5.7: nothing is ignored);
 *   - Snapshot::capture() bound every selected id in one statement, so a filter over more
 *     journals than the database binds at once would fail before the ceiling could report the count.
 *
 *   - GET /transactions had no response byte cap (§15: 8 MiB, truncated: true + a narrowing hint),
 *     and a page of 5,000 split groups passes it;
 *   - without_category=1 (or "1", or a JSON 1) passed Laravel's boolean rule but was read as "not
 *     true", so the filter was silently DROPPED — on GET a wider list, on a bulk write's filter{}
 *     a selection silently widened to every transaction (the exact hazard of apis.mdx §5.7).
 *
 * Also pinned: undoing an unlink re-inserts the link under its original id, so the note Firefly
 * leaves on it (its delete does not cascade to notes) is attached again.
 *
 * @internal
 *
 * @coversNothing
 */
final class NamesDatesBudgetsRegressionTest extends MachineTestCase
{
    use TransactionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHousehold();
        $this->enableWrites();
        config(['machine.operator' => 'test@email.com']);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function withdrawal(array $extra): array
    {
        return ['transactions' => [array_merge([
            'type'             => 'withdrawal',
            'date'             => '2026-09-05',
            'amount'           => '3.00',
            'description'      => 'Purchase',
            'source_id'        => (string) $this->checking->id,
            'destination_name' => 'Corner Cafe',
        ], $extra)]];
    }

    public function testACaseVariantPayeeAndCategoryReuseTheExistingRows(): void
    {
        $this->spend('12.50', 'Corner Cafe', '2026-09-03', ['category_name' => 'Coffee']);
        $payee    = Account::query()->where('name', 'Corner Cafe')->firstOrFail();
        $category = Category::query()->where('name', 'Coffee')->firstOrFail();

        $plan     = $this->envelope($this->machine('POST', '/transactions', $this->withdrawal(['destination_name' => 'corner cafe', 'category_name' => 'COFFEE'])));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame([], $plan['data']['created_alongside']['accounts'], 'no second payee');
        $this->assertSame([], $plan['data']['created_alongside']['categories'], 'no second category');
        $split    = $plan['data']['transactions'][0]['transactions'][0];
        $this->assertSame((string) $payee->id, $split['destination_id']);
        $this->assertSame('Corner Cafe', $split['destination_name']);
        $this->assertSame((string) $category->id, $split['category_id']);

        $this->applied('POST', '/transactions', $this->withdrawal(['destination_name' => 'corner cafe', 'category_name' => 'COFFEE']));
        $this->assertSame(1, Account::query()->whereRaw('LOWER(name) = ?', ['corner cafe'])->count());
        $this->assertSame(1, Category::query()->whereRaw('LOWER(name) = ?', ['coffee'])->count());
    }

    public function testACaseVariantOwnAccountNameResolvesInsteadOfBeingRefused(): void
    {
        $done = $this->applied('POST', '/transactions', $this->withdrawal(['source_id' => null, 'source_name' => 'NORTHBANK checking 4021']));
        $this->assertSame((string) $this->checking->id, $done['data']['transactions'][0]['transactions'][0]['source_id']);
        $this->assertSame(['Corner Cafe'], array_column($done['data']['created_alongside']['accounts'], 'name'), 'only the payee is new');
    }

    public function testAnAmbiguousNameIsRefusedWithTypedCandidatesAndAnExactOneIsNot(): void
    {
        $this->account('Amazon', 'expense');
        $this->account('AMAZON', 'expense');

        $env  = $this->assertPlaneError($this->machine('POST', '/transactions', $this->withdrawal(['destination_name' => 'amazon'])), 400, 'invalid_input');
        $this->assertSame('transactions.0.destination_name', $env['error']['details']['field']);
        $this->assertCount(2, $env['error']['details']['candidates']);
        $this->assertSame('Expense account', $env['error']['details']['candidates'][0]['type']);

        $plan = $this->envelope($this->machine('POST', '/transactions', $this->withdrawal(['destination_name' => 'AMAZON'])));
        $this->assertTrue($plan['ok'], 'an exact match is Firefly\'s own lookup');
        $this->assertSame('AMAZON', $plan['data']['transactions'][0]['transactions'][0]['destination_name']);
        $this->assertSame([], $plan['data']['created_alongside']['accounts']);
    }

    public function testTheMatchIsAmongTheTypesFireflyAllowsOnThatSide(): void
    {
        // "Acme LLC" exists as a REVENUE account (a payer); a withdrawal's payee of that name is
        // an EXPENSE account, which does not exist yet — Firefly creates it, as the UI would
        $this->applied('POST', '/transactions', ['transactions' => [['type' => 'deposit', 'date' => '2026-09-07', 'amount' => '500.00', 'description' => 'salary', 'source_name' => 'Acme LLC', 'destination_id' => (string) $this->checking->id]]]);
        $revenue = Account::query()->where('name', 'Acme LLC')->firstOrFail();

        $plan    = $this->envelope($this->machine('POST', '/transactions', $this->withdrawal(['destination_name' => 'acme llc'])));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertNotSame((string) $revenue->id, $plan['data']['transactions'][0]['transactions'][0]['destination_id'], 'never the revenue account');
        $this->assertSame('Expense account', $plan['data']['transactions'][0]['transactions'][0]['destination_type']);
        $this->assertSame('acme llc', $plan['data']['created_alongside']['accounts'][0]['name']);
    }

    public function testAnInvalidDateIsRefusedNotStoredAsToday(): void
    {
        foreach (['2026-13-01', '2026-02-30', '05/09/2026', '2026-9-5', '1969-12-31', 'yesterday', '2026-09-05T25:00:00'] as $bad) {
            $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->withdrawal(['date' => $bad])), 400, 'invalid_input');
            $this->assertSame('transactions.0.date', $env['error']['details']['field'], $bad);
        }
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', $this->withdrawal(['book_date' => '2026-02-30'])), 400, 'invalid_input');
        $this->assertSame('transactions.0.book_date', $env['error']['details']['field']);
        $this->assertSame(0, TransactionGroup::query()->count(), 'nothing was dated today');

        $plan = $this->envelope($this->machine('POST', '/transactions', $this->withdrawal(['date' => '2026-09-05T10:30:00+02:00'])));
        $this->assertTrue($plan['ok'], 'a full ISO 8601 instant is Firefly\'s own date-or-time');
        $this->assertStringStartsWith('2026-09-05T10:30:00', $plan['data']['transactions'][0]['transactions'][0]['date']);
    }

    public function testABudgetOnAnythingButAWithdrawalIsRefusedNotDropped(): void
    {
        $budget = $this->budget('Food');
        $body   = static fn (string $type, array $extra): array => ['transactions' => [array_merge([
            'type' => $type, 'date' => '2026-09-08', 'amount' => '5.00', 'description' => 'x',
        ], $extra)]];

        $env    = $this->assertPlaneError($this->machine('POST', '/transactions', $body('deposit', ['source_name' => 'Acme LLC', 'destination_id' => (string) $this->checking->id, 'budget_name' => 'Food'])), 400, 'invalid_input');
        $this->assertSame('transactions.0.budget_name', $env['error']['details']['field']);
        $this->assertSame('deposit', $env['error']['details']['type']);
        $this->assertPlaneError($this->machine('POST', '/transactions', $body('transfer', ['source_id' => (string) $this->checking->id, 'destination_id' => (string) $this->savings->id, 'budget_id' => (string) $budget->id])), 400, 'invalid_input');

        // on an update the journal's own type counts
        $group  = $this->applied('POST', '/transactions', $body('deposit', ['source_name' => 'Acme LLC', 'destination_id' => (string) $this->checking->id]));
        $this->assertPlaneError($this->machine('PUT', '/transactions/'.$group['data']['group_id'], ['transactions' => [['budget_id' => (string) $budget->id]]]), 400, 'invalid_input');

        // a withdrawal still takes one, by a case-variant name
        $done   = $this->applied('POST', '/transactions', $this->withdrawal(['budget_name' => 'food']));
        $this->assertSame('Food', $done['data']['transactions'][0]['transactions'][0]['budget_name']);
    }

    public function testConvertResolvesACaseVariantNameToTheExistingAccount(): void
    {
        $this->spend('12.50', 'Corner Cafe', '2026-09-03');
        $payee = Account::query()->where('name', 'Corner Cafe')->firstOrFail();
        $env   = $this->applied('POST', '/transactions', ['transactions' => [['type' => 'transfer', 'date' => '2026-09-06', 'amount' => '100.00', 'description' => 'to savings', 'source_id' => (string) $this->checking->id, 'destination_id' => (string) $this->savings->id]]]);
        $group = (int) $env['data']['group_id'];

        $done  = $this->applied('POST', '/transactions/'.$group.'/convert', ['to_type' => 'withdrawal', 'destination_name' => 'CORNER cafe']);
        $split = $done['data']['transactions'][0]['transactions'][0];
        $this->assertSame('withdrawal', $split['type']);
        $this->assertSame((string) $payee->id, $split['destination_id']);
        $this->assertSame([], $done['data']['created_alongside']['accounts']);
        $this->assertSame(1, Account::query()->whereRaw('LOWER(name) = ?', ['corner cafe'])->count());

        $this->account('Amazon', 'revenue');
        $this->account('AMAZON', 'revenue');
        $env   = $this->assertPlaneError($this->machine('POST', '/transactions/'.$group.'/convert', ['to_type' => 'deposit', 'source_name' => 'amazon']), 400, 'invalid_input');
        $this->assertSame('source_name', $env['error']['details']['field'] ?? null, (string) json_encode($env));
        $this->assertCount(2, $env['error']['details']['candidates']);
    }

    public function testASnapshotOverMoreIdsThanOneStatementBindsIsComplete(): void
    {
        $groups   = [$this->spend('1.00', 'A', '2026-09-01'), $this->spend('2.00', 'B', '2026-09-02'), $this->spend('3.00', 'C', '2026-09-03')];
        $captured = Snapshot::capture([...range(100000, 101300), ...$groups]);
        $this->assertSame($groups, array_map('intval', array_keys($captured[TransactionGroup::class])));
        $this->assertCount(3, $captured[TransactionJournal::class]);
        $this->assertCount(6, $captured[\FireflyIII\Models\Transaction::class]);
    }

    public function testUndoingAnUnlinkReattachesTheNote(): void
    {
        $a    = $this->spend('61.20', 'Store', '2026-09-03');
        $b    = $this->spend('18.75', 'Store', '2026-09-09');
        $type = LinkType::query()->firstOrFail();
        $ja   = (int) TransactionJournal::query()->where('transaction_group_id', $a)->value('id');
        $jb   = (int) TransactionJournal::query()->where('transaction_group_id', $b)->value('id');
        $link = $this->applied('POST', '/transaction-links', ['link_type_name' => $type->name, 'inward_id' => (string) $ja, 'outward_id' => (string) $jb, 'notes' => 'split receipt'])['data']['link'];
        $this->assertSame(1, DB::table('notes')->where('noteable_type', \FireflyIII\Models\TransactionJournalLink::class)->count());

        $done = $this->applied('DELETE', '/transaction-links/'.$link['id']);
        $this->assertSame(0, DB::table('journal_links')->count());
        auth()->setUser($this->user);
        DB::transaction(static fn () => OperationLog::reverse((int) $done['data']['operation_id']));
        $this->assertSame((int) $link['id'], (int) DB::table('journal_links')->value('id'), 'the same link id');
        $env  = $this->envelope($this->machine('GET', '/transactions/'.$a.'/links'));
        $this->assertSame('split receipt', $env['data']['links'][0]['notes']);
    }

    public function testTheListIsCutAtTheByteCapWithANarrowingHint(): void
    {
        $ids = [$this->spend('1.00', 'A', '2026-09-01'), $this->spend('2.00', 'B', '2026-09-02'), $this->spend('3.00', 'C', '2026-09-03')];
        config(['machine.limits.max_body_bytes' => 4000]);
        $env = $this->envelope($this->machine('GET', '/transactions'));
        $this->assertTrue($env['ok']);
        $this->assertTrue($env['meta']['truncated']);
        $this->assertCount($env['meta']['count'], $env['data']['transactions']);
        $this->assertLessThan(3, count($env['data']['transactions']));
        $this->assertSame($ids[2], (int) $env['data']['transactions'][0]['id'], 'newest first, whole groups only');
        $this->assertSame(3, $env['data']['total']);
        $this->assertSame(count($env['data']['transactions']), $env['meta']['next_offset']);
        $this->assertStringContainsString('offset=', $env['meta']['hint']);
        $this->assertLessThanOrEqual(4000, strlen((string) json_encode($env['data']['transactions'])));

        $next = $this->envelope($this->machine('GET', '/transactions?offset='.$env['meta']['next_offset']));
        $this->assertSame($ids[2 - count($env['data']['transactions'])], (int) $next['data']['transactions'][0]['id'], 'the next page starts where the cut was');
    }

    public function testAOneOrZeroFlagIsAFlagNotAFilterSilentlyDropped(): void
    {
        $with    = $this->spend('12.50', 'Corner Cafe', '2026-09-03', ['category_name' => 'Coffee']);
        $without = $this->spend('40.00', 'Hardware Store', '2026-09-04');

        foreach (['1', 'true', 'TRUE', 'yes'] as $yes) {
            $env = $this->envelope($this->machine('GET', '/transactions?without_category='.$yes));
            $this->assertSame([$without], array_map(static fn (array $g): int => (int) $g['id'], $env['data']['transactions']), $yes);
            $this->assertTrue($env['data']['filters']['without_category']);
        }
        foreach (['0', 'false'] as $no) {
            $env = $this->envelope($this->machine('GET', '/transactions?without_category='.$no));
            $this->assertCount(2, $env['data']['transactions'], $no);
            $this->assertArrayNotHasKey('without_category', $env['data']['filters']);
        }
        $this->assertPlaneError($this->machine('GET', '/transactions?without_category=maybe'), 400, 'invalid_input');
        $env = $this->envelope($this->machine('GET', '/transactions?reconciled=1'));
        $this->assertCount(0, $env['data']['transactions']);
        $this->assertTrue($env['data']['filters']['reconciled']);

        // the same flag inside a bulk write's filter{} — as a JSON number, a string, a bool
        foreach ([1, '1', true] as $yes) {
            $plan = $this->envelope($this->machine('POST', '/transactions/categorize', ['filter' => ['without_category' => $yes], 'category_name' => 'Coffee']));
            $this->assertTrue($plan['ok'], (string) json_encode($plan));
            $this->assertSame(1, $plan['data']['selected'], 'only the uncategorised one — never everything');
            $this->assertSame(1, $plan['data']['changes']['updated']);
        }
        // a flag that is OFF is no filter at all: an empty filter is refused, not "everything"
        foreach ([0, '0', false] as $no) {
            $env = $this->assertPlaneError($this->machine('POST', '/transactions/categorize', ['filter' => ['without_category' => $no], 'category_name' => 'Coffee']), 400, 'invalid_input');
            $this->assertStringContainsString('empty filter', $env['error']['message']);
        }
        $this->assertSame('Coffee', TransactionJournal::query()->where('transaction_group_id', $with)->firstOrFail()->categories()->value('name'));
        $this->assertNull(TransactionJournal::query()->where('transaction_group_id', $without)->firstOrFail()->categories()->value('name'), 'nothing was applied');
    }
}
