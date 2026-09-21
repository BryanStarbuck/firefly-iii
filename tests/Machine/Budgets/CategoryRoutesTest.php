<?php

/*
 * CategoryRoutesTest.php
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

use FireflyIII\Models\Category;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.4 — categories: list, show with spent/earned per currency, transactions,
 * create, rename (with Firefly's rule cascade, undoable), merge, and the admin-tier delete.
 *
 * @internal
 *
 * @coversNothing
 */
final class CategoryRoutesTest extends MachineTestCase
{
    use BudgetFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootLedger();
    }

    public function testListSearchAndOrder(): void
    {
        $this->category('Groceries');
        $this->category('Dining Out');
        $this->category('Utilities');
        $env = $this->envelope($this->machine('GET', '/categories'));
        $this->assertSame(['Dining Out', 'Groceries', 'Utilities'], array_column($env['data']['categories'], 'name'));
        $this->assertIsString($env['data']['categories'][0]['id']);
        $this->assertArrayNotHasKey('links', $env['data']['categories'][0]);

        $search = $this->envelope($this->machine('GET', '/categories', ['search' => 'GROC']));
        $this->assertSame(['Groceries'], array_column($search['data']['categories'], 'name'));
        $desc = $this->envelope($this->machine('GET', '/categories', ['order' => '-name', 'limit' => '1', 'offset' => '1']));
        $this->assertSame(['Groceries'], array_column($desc['data']['categories'], 'name'));
        $this->assertPlaneError($this->machine('GET', '/categories', ['q' => 'x']), 400, 'invalid_input');
    }

    public function testShowSpentPerCurrencyAndTransactions(): void
    {
        $groceries = $this->category('Groceries');
        $this->spend('2026-08-03', '40.00', null, 'Groceries');
        $this->spend('2026-08-17', '22.50', null, 'Groceries');
        $this->spend('2026-07-01', '5.00', null, 'Groceries');

        $env = $this->envelope($this->machine('GET', '/categories/Groceries', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertSame((string) $groceries->id, $env['data']['category']['id']);
        $this->assertCount(1, $env['data']['category']['spent']);
        $this->assertSame('-62.50', $env['data']['category']['spent'][0]['sum']);
        $this->assertSame($this->primary->code, $env['data']['category']['spent'][0]['currency_code']);
        $this->assertSame('2026-08-01', $env['data']['start']);

        $tx  = $this->envelope($this->machine('GET', '/categories/'.$groceries->id.'/transactions', ['start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertCount(2, $tx['data']['transactions']);
        $this->assertSame('Corner Grocer 2026-08-17', $tx['data']['transactions'][0]['transactions'][0]['description'], 'newest first');
        $all = $this->envelope($this->machine('GET', '/categories/'.$groceries->id.'/transactions', ['limit' => '2']));
        $this->assertCount(2, $all['data']['transactions']);
        $this->assertTrue($all['meta']['truncated']);

        $this->assertPlaneError($this->machine('GET', '/categories/404404'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/categories/Nope/transactions'), 404, 'not_found');
    }

    public function testCreateIsADryRunThenCreatesAndRefusesADuplicate(): void
    {
        $this->enableWrites();
        $before = $this->tableHash('categories');
        $plan   = $this->envelope($this->machine('POST', '/categories', ['name' => 'Groceries', 'notes' => 'food at home']));
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame('Groceries', $plan['data']['category']['name']);
        $this->assertSame($before, $this->tableHash('categories'));

        $apply  = $this->planAndApply('POST', '/categories', ['name' => 'Groceries', 'notes' => 'food at home']);
        $this->assertSame('food at home', $apply['data']['category']['notes']);
        $this->assertSame(1, Category::query()->count());

        $dup    = $this->assertPlaneError($this->machine('POST', '/categories', ['name' => 'Groceries']), 409, 'conflict');
        $this->assertSame((string) Category::query()->value('id'), $dup['error']['details']['existing_id']);
        $this->assertPlaneError($this->machine('POST', '/categories', ['name' => '']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/categories', ['title' => 'x']), 400, 'invalid_input');
    }

    public function testRenameCascadesIntoRulesAndIsUndoable(): void
    {
        $this->enableWrites();
        $cat     = $this->category('Food');
        $rule    = $this->ruleSetting('Food');

        $plan    = $this->envelope($this->machine('PUT', '/categories/'.$cat->id, ['name' => 'Groceries']));
        $this->assertSame(['updated' => 1, 'rule_actions_renamed' => 1, 'rule_triggers_renamed' => 1], $plan['data']['changes']);
        $this->assertSame('Food', Category::query()->value('name'));

        $stale   = $this->envelope($this->machine('PUT', '/categories/'.$cat->id, ['name' => 'Groceries']));
        Category::query()->where('id', $cat->id)->update(['name' => 'Food (browser)']);
        $this->assertPlaneError($this->machine('PUT', '/categories/'.$cat->id, ['name' => 'Groceries', 'dry_run' => false, 'confirm_token' => $stale['data']['confirm_token']]), 409, 'conflict');
        Category::query()->where('id', $cat->id)->update(['name' => 'Food']);

        $this->planAndApply('PUT', '/categories/'.$cat->id, ['name' => 'Groceries']);
        $this->assertSame('Groceries', Category::query()->value('name'));
        $this->assertSame('Groceries', RuleAction::query()->where('rule_id', $rule->id)->value('action_value'));
        $this->assertSame('Groceries', RuleTrigger::query()->where('rule_id', $rule->id)->where('trigger_type', 'category_is')->value('trigger_value'));

        $same    = $this->envelope($this->machine('PUT', '/categories/'.$cat->id, ['name' => 'Groceries']));
        $this->assertSame(['unchanged' => 1], $same['data']['changes']);

        $last    = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('PUT /categories/{id}', $last['data']['route']);
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $this->assertSame('Food', Category::query()->value('name'));
        $this->assertSame('Food', RuleAction::query()->where('rule_id', $rule->id)->value('action_value'), 'undo reverses the cascade too');
        $this->assertSame('Food', RuleTrigger::query()->where('rule_id', $rule->id)->where('trigger_type', 'category_is')->value('trigger_value'));

        $this->category('Dining');
        $this->assertPlaneError($this->machine('PUT', '/categories/'.$cat->id, ['name' => 'Dining']), 409, 'conflict');
        $this->assertPlaneError($this->machine('PUT', '/categories/'.$cat->id, []), 400, 'invalid_input');
    }

    public function testMergeRepointsJournalsAndRulesThenRemovesTheMergedCategories(): void
    {
        $this->enableWrites();
        $keep  = $this->category('Groceries');
        $food  = $this->category('Food');
        $super = $this->category('Supermarket');
        $this->spend('2026-08-03', '40.00', null, 'Food');
        $this->spend('2026-08-04', '10.00', null, 'Supermarket');
        $this->spend('2026-08-05', '15.00', null, 'Groceries');
        $rule  = $this->ruleSetting('Food');

        $before = $this->tableHash('category_transaction_journal');
        $plan  = $this->envelope($this->machine('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => [(string) $food->id, 'supermarket']]));
        $this->assertSame(2, $plan['data']['changes']['deleted']);
        $this->assertSame(2, $plan['data']['changes']['journals_repointed']);
        $this->assertSame(2, $plan['data']['journals_repointed']);
        $this->assertFalse($plan['data']['undoable']);
        $this->assertSame($before, $this->tableHash('category_transaction_journal'), 'the dry run moved nothing');
        $this->assertSame(3, Category::query()->count());

        $this->planAndApply('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => [(string) $food->id, 'supermarket']]);
        $this->assertSame(['Groceries'], Category::query()->pluck('name')->all());
        $this->assertSame(3, DB::table('category_transaction_journal')->where('category_id', $keep->id)->count());
        $this->assertSame('Groceries', RuleAction::query()->where('rule_id', $rule->id)->value('action_value'));

        $last  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('POST /categories/merge', $last['data']['route']);
        $this->assertFalse($last['data']['reversible'], 'journal re-pointing is not in the undo log, so undo refuses rather than half-restores');

        $this->assertPlaneError($this->machine('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => ['Groceries']]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => []]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/categories/merge', ['keep_id' => 'Groceries', 'merge_ids' => ['Nope']]), 404, 'not_found');
        $this->assertSame(1, $super->newQuery()->withTrashed()->where('id', $super->id)->count());
    }

    public function testDeleteIsAdminTierAndIdempotent(): void
    {
        $this->enableWrites();
        $cat = $this->category('Groceries');
        $this->spend('2026-08-03', '40.00', null, 'Groceries');
        $this->assertPlaneError($this->machine('DELETE', '/categories/'.$cat->id), 403, 'forbidden');

        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/categories/'.$cat->id));
        $this->assertSame(['deleted' => 1], $plan['data']['changes']);
        $this->assertSame(1, Category::query()->count());
        $apply = $this->envelope($this->machine('DELETE', '/categories/'.$cat->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertSame(1, $apply['data']['deleted']);
        $this->assertSame(1, $apply['data']['journals_uncategorized']);
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, DB::table('category_transaction_journal')->count());

        $gone  = $this->envelope($this->machine('DELETE', '/categories/'.$cat->id));
        $this->assertSame(0, $gone['data']['deleted']);
        $this->assertPlaneError($this->machine('DELETE', '/categories/NoSuchName'), 404, 'not_found');
    }

    /** A rule "category is X → set category X" (invented). */
    private function ruleSetting(string $category): Rule
    {
        $group = RuleGroup::query()->create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'title' => 'Household rules', 'order' => 1, 'active' => true, 'stop_processing' => false]);
        $rule  = Rule::query()->create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'rule_group_id' => $group->id, 'title' => 'Shop to '.$category, 'order' => 1, 'active' => true, 'stop_processing' => false, 'strict' => true]);
        RuleTrigger::query()->create(['rule_id' => $rule->id, 'trigger_type' => 'category_is', 'trigger_value' => $category, 'order' => 1, 'active' => true, 'stop_processing' => false]);
        RuleAction::query()->create(['rule_id' => $rule->id, 'action_type' => 'set_category', 'action_value' => $category, 'order' => 1, 'active' => true, 'stop_processing' => false]);

        return $rule;
    }
}
