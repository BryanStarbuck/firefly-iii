<?php

/*
 * CategoryTreeTest.php
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

namespace Tests\Machine\Budgets;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use FireflyIII\Machine\Categories\CategoryTree;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.4a — the "Group > Sub" category tree (GET /categories/tree, and its YAML), and
 * §8.3's POST /transactions/categorize-by-import: categories for rows already in the books, keyed
 * by account + the import id (external_id). Invented books only.
 *
 * @internal
 *
 * @coversNothing
 */
final class CategoryTreeTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------- the tree ---

    public function testSplitsOnTheFirstSeparatorOnly(): void
    {
        $this->assertSame(['Food', 'Groceries'], CategoryTree::split('Food > Groceries'));
        $this->assertSame(['Food', null], CategoryTree::split('Food'));
        $this->assertSame(['Home', 'Repairs > Plumbing'], CategoryTree::split('Home > Repairs > Plumbing'));
        $this->assertSame(['Food>Snacks', null], CategoryTree::split('Food>Snacks'), 'only " > " with its spaces separates');
        $this->assertSame(['Food > ', null], CategoryTree::split('Food > '), 'a blank side does not split');
        $this->assertSame([' > Groceries', null], CategoryTree::split(' > Groceries'));
    }

    public function testTreeRouteNestsSortsAndMarksPrefixOnlyGroups(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 22:00:00', 'UTC'));
        $food      = $this->category('Food');
        $groceries = $this->category('Food > Groceries');
        $dining    = $this->category('Food > Dining Out');
        $fuel      = $this->category('Transport > Fuel');
        $gifts     = $this->category('gifts');

        $env  = $this->envelope($this->machine('GET', '/categories/tree'));
        $data = $env['data'];
        $this->assertSame('firefly_iii', $data['app']);
        $this->assertSame(['groups' => 3, 'subcategories' => 3], $data['counts']);
        $this->assertSame(['Food', 'gifts', 'Transport'], array_column($data['groups'], 'name'), 'sorted by name, case-insensitively');
        $this->assertSame((string) $food->id, $data['groups'][0]['id']);
        $this->assertSame([
            ['name' => 'Dining Out', 'full_name' => 'Food > Dining Out', 'id' => (string) $dining->id],
            ['name' => 'Groceries', 'full_name' => 'Food > Groceries', 'id' => (string) $groceries->id],
        ], $data['groups'][0]['subcategories']);
        $this->assertSame((string) $gifts->id, $data['groups'][1]['id']);
        $this->assertSame([], $data['groups'][1]['subcategories']);
        $this->assertNull($data['groups'][2]['id'], 'Transport exists only as a prefix');
        $this->assertSame((string) $fuel->id, $data['groups'][2]['subcategories'][0]['id']);
        $this->assertContains('yaml', $env['meta']['untrusted']);

        $want = implode("\n", [
            'app: firefly_iii',
            'generated_at: 2026-09-21T22:00:00Z',
            'counts:',
            '  groups: 3',
            '  subcategories: 3',
            'groups:',
            '  - name: Food',
            '    id: "'.$food->id.'"',
            '    subcategories:',
            '      - name: Dining Out',
            '        full_name: Food > Dining Out',
            '        id: "'.$dining->id.'"',
            '      - name: Groceries',
            '        full_name: Food > Groceries',
            '        id: "'.$groceries->id.'"',
            '  - name: gifts',
            '    id: "'.$gifts->id.'"',
            '    subcategories: []',
            '  - name: Transport',
            '    id: null',
            '    subcategories:',
            '      - name: Fuel',
            '        full_name: Transport > Fuel',
            '        id: "'.$fuel->id.'"',
        ])."\n";
        $this->assertSame($want, $data['yaml']);

        $yaml = $this->envelope($this->machine('GET', '/categories/tree', ['format' => 'yaml']));
        $this->assertSame(['app', 'format', 'counts', 'yaml'], array_keys($yaml['data']));
        $this->assertSame('yaml', $yaml['data']['format']);
        $this->assertSame($want, $yaml['data']['yaml']);

        $this->assertPlaneError($this->machine('GET', '/categories/tree', ['format' => 'xml']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/categories/tree', ['depth' => '2']), 400, 'invalid_input');
    }

    public function testEmptyBooksAreAnEmptyTree(): void
    {
        $env = $this->envelope($this->machine('GET', '/categories/tree'));
        $this->assertSame(['groups' => 0, 'subcategories' => 0], $env['data']['counts']);
        $this->assertSame([], $env['data']['groups']);
        $this->assertStringEndsWith("  subcategories: 0\ngroups: []\n", $env['data']['yaml']);
    }

    public function testYamlQuotesEveryNameAReaderCouldMisread(): void
    {
        $this->assertSame('Groceries', CategoryTree::scalar('Groceries'));
        $this->assertSame('Food > Dining Out', CategoryTree::scalar('Food > Dining Out'));
        $this->assertSame("Kids' Clothes (school)", CategoryTree::scalar("Kids' Clothes (school)"));
        $this->assertSame('Café', CategoryTree::scalar('Café'));
        $this->assertSame('"Tax: 2026"', CategoryTree::scalar('Tax: 2026'));
        $this->assertSame('"Bills & Utilities"', CategoryTree::scalar('Bills & Utilities'));
        $this->assertSame('"Gifts #1"', CategoryTree::scalar('Gifts #1'));
        $this->assertSame('"#hashtag"', CategoryTree::scalar('#hashtag'));
        $this->assertSame('"- dash"', CategoryTree::scalar('- dash'));
        $this->assertSame('"*star"', CategoryTree::scalar('*star'));
        $this->assertSame('"say \"hi\""', CategoryTree::scalar('say "hi"'));
        $this->assertSame('"back\\\\slash"', CategoryTree::scalar('back\slash'));
        $this->assertSame('"line\nbreak"', CategoryTree::scalar("line\nbreak"));
        $this->assertSame('"trailing "', CategoryTree::scalar('trailing '));
        $this->assertSame('"2026"', CategoryTree::scalar('2026'));
        $this->assertSame('"12"', CategoryTree::scalar('12'));
        foreach (['yes', 'No', 'on', 'OFF', 'true', 'False', 'null', 'y', 'N'] as $word) {
            $this->assertSame('"'.$word.'"', CategoryTree::scalar($word), $word);
        }
        $this->assertSame('null', CategoryTree::scalar(null));
        $this->assertSame('3', CategoryTree::scalar(3));

        $tree = CategoryTree::build([
            ['id' => 7, 'name' => 'Bills & Utilities > Power: Grid'],
            ['id' => 8, 'name' => 'Bills & Utilities'],
            ['id' => 9, 'name' => 'Gifts #1'],
        ]);
        $yaml = CategoryTree::yaml($tree, CarbonImmutable::parse('2026-09-21T22:00:00Z'));
        $this->assertStringContainsString("  - name: \"Bills & Utilities\"\n    id: \"8\"\n    subcategories:\n      - name: \"Power: Grid\"\n        full_name: \"Bills & Utilities > Power: Grid\"\n        id: \"7\"\n", $yaml);
        $this->assertStringContainsString("  - name: \"Gifts #1\"\n    id: \"9\"\n    subcategories: []\n", $yaml);
    }

    // ------------------------------------------- categorize by import id ---

    public function testCategorizeByImportIsADryRunThenSetsOnlyTheNamedRowsAndIsUndoable(): void
    {
        $this->enableWrites();
        $groceries = $this->category('Food > Groceries');
        $this->category('Food > Dining Out');
        $a         = $this->imported('2026-09-03', '41.20', 'ofx:4021:20260903001', 'Corner Grocer');
        $b         = $this->imported('2026-09-04', '18.00', 'ofx:4021:20260904001', 'Noodle Bar');
        $c         = $this->imported('2026-09-05', '9.99', 'ofx:4021:20260905001', 'Streaming Co');
        $body      = ['assignments' => [
            ['account' => 'Northbank Checking 4021', 'import_id' => 'ofx:4021:20260903001', 'category' => 'Food > Groceries'],
            ['account' => (string) $this->checking->id, 'import_id' => 'ofx:4021:20260904001', 'category' => 'food > dining out'],
            ['account' => 'Northbank Checking 4021', 'import_id' => 'ofx:4021:29990101001', 'category' => 'Food > Groceries'],
        ]];

        $before    = $this->tableHash('category_transaction_journal');
        $plan      = $this->envelope($this->machine('POST', '/transactions/categorize-by-import', $body));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(2, $plan['data']['changes']['updated']);
        $this->assertSame(1, $plan['data']['changes']['unmatched']);
        $this->assertSame(2, $plan['data']['change_count']);
        $this->assertSame(['not_found' => 1], $plan['data']['outcomes']);
        $this->assertSame(['updated', 'updated', 'not_found'], array_column($plan['data']['results'], 'outcome'));
        $this->assertSame('Food > Dining Out', $plan['data']['results'][1]['new_category_name']);
        $this->assertSame($before, $this->tableHash('category_transaction_journal'), 'the dry run changed nothing');

        $this->planAndApply('POST', '/transactions/categorize-by-import', $body);
        $this->assertSame('Food > Groceries', $this->categoryOf($a));
        $this->assertSame('Food > Dining Out', $this->categoryOf($b));
        $this->assertNull($this->categoryOf($c), 'a row nobody named is left alone');
        $this->assertSame(2, Category::query()->count(), 'nothing was created');

        $again     = $this->envelope($this->machine('POST', '/transactions/categorize-by-import', $body));
        $this->assertSame(0, $again['data']['change_count']);
        $this->assertSame(2, $again['data']['changes']['unchanged']);

        $last      = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('POST /transactions/categorize-by-import', $last['data']['route']);
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $this->assertNull($this->categoryOf($a));
        $this->assertNull($this->categoryOf($b));
        $this->assertSame((string) $groceries->id, (string) Category::query()->where('name', 'Food > Groceries')->value('id'));
    }

    public function testUnknownCategoriesAreReportedNotCreatedUnlessAsked(): void
    {
        $this->enableWrites();
        $a    = $this->imported('2026-09-03', '41.20', 'ff1:4021:20260903:41.20:0:ab12cd34', 'Corner Grocer');
        $body = ['assignments' => [['account' => 'Northbank Checking 4021', 'import_id' => 'ff1:4021:20260903:41.20:0:ab12cd34', 'category' => 'Food > Groceries']]];

        $plan = $this->envelope($this->machine('POST', '/transactions/categorize-by-import', $body));
        $this->assertSame(['Food > Groceries'], $plan['data']['unknown_categories']);
        $this->assertSame('unknown_category', $plan['data']['results'][0]['outcome']);
        $this->assertSame(0, $plan['data']['change_count']);
        $this->planAndApply('POST', '/transactions/categorize-by-import', $body);
        $this->assertSame(0, Category::query()->count(), 'an unknown name is reported, never invented');
        $this->assertNull($this->categoryOf($a));

        $this->planAndApply('POST', '/transactions/categorize-by-import', $body + ['create_missing' => true]);
        $this->assertSame(['Food > Groceries'], Category::query()->pluck('name')->all());
        $this->assertSame('Food > Groceries', $this->categoryOf($a));
    }

    public function testTheImportIdIsScopedToItsAccountAndNeverGuessed(): void
    {
        $this->enableWrites();
        $this->category('Groceries');
        $this->imported('2026-09-03', '41.20', 'ofx:4021:20260903001', 'Corner Grocer');
        $this->imported('2026-09-06', '12.00', 'ofx:4021:dup', 'Corner Grocer');
        $this->imported('2026-09-07', '13.00', 'ofx:4021:dup', 'Corner Grocer');

        $plan = $this->envelope($this->machine('POST', '/transactions/categorize-by-import', ['assignments' => [
            ['account' => 'Meridian Savings 3308', 'import_id' => 'ofx:4021:20260903001', 'category' => 'Groceries'],
            ['account' => 'Northbank Checking 4021', 'import_id' => 'ofx:4021:dup', 'category' => 'Groceries'],
        ]]));
        $this->assertSame(['unknown_account', 'ambiguous_import_id'], array_column($plan['data']['results'], 'outcome'));
        $this->assertCount(2, $plan['data']['results'][1]['journal_ids']);
        $this->assertSame(0, $plan['data']['change_count']);

        $this->assertPlaneError($this->machine('POST', '/transactions/categorize-by-import', ['assignments' => [
            ['account' => 'Northbank Checking 4021', 'import_id' => 'ofx:4021:20260903001', 'category' => 'Groceries'],
            ['account' => 'Northbank Checking 4021', 'import_id' => 'ofx:4021:20260903001', 'category' => 'Dining'],
        ]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize-by-import', ['assignments' => []]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize-by-import', ['assignments' => [['account' => 'x', 'import_id' => 'y', 'category' => 'z', 'payee' => 'w']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize-by-import', ['journal_ids' => [1]]), 400, 'invalid_input');
    }

    public function testWritesAreOffUntilSwitchedOn(): void
    {
        $this->assertPlaneError($this->machine('POST', '/transactions/categorize-by-import', ['assignments' => [['account' => 'x', 'import_id' => 'y', 'category' => 'z']]]), 403, 'write_disabled');
    }

    /** A withdrawal from Northbank checking carrying an import id, as the statement pipeline stores it. */
    private function imported(string $date, string $amount, string $externalId, string $shop): TransactionGroup
    {
        /** @var TransactionGroupRepositoryInterface $groups */
        $groups = app(TransactionGroupRepositoryInterface::class);
        $groups->setUser($this->user);

        return $groups->store([
            'user'                    => $this->user,
            'user_group'              => $this->user->userGroup,
            'group_title'             => null,
            'error_if_duplicate_hash' => false,
            'apply_rules'             => false,
            'fire_webhooks'           => false,
            'transactions'            => [[
                'type'             => 'withdrawal',
                'date'             => Carbon::parse($date.' 12:00:00'),
                'amount'           => $amount,
                'description'      => sprintf('%s %s', $shop, $date),
                'source_id'        => $this->checking->id,
                'destination_name' => $shop,
                'currency_id'      => $this->primary->id,
                'external_id'      => $externalId,
                'reconciled'       => false,
                'tags'             => [],
            ]],
        ]);
    }

    private function categoryOf(TransactionGroup $group): ?string
    {
        $journalId = (int) $group->transactionJournals()->value('id');
        $id        = DB::table('category_transaction_journal')->where('transaction_journal_id', $journalId)->value('category_id');

        return null === $id ? null : (string) Category::query()->where('id', $id)->value('name');
    }
}
