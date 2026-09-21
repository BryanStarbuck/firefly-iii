<?php

/*
 * RuleRoutesTest.php
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

namespace Tests\Machine\Rules;

use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.7 — rules and rule groups through the real router, gates and write protocol.
 *
 * @internal
 *
 * @coversNothing
 */
final class RuleRoutesTest extends MachineTestCase
{
    use SeedsLedger;

    private User $user;
    private Account $checking;
    private RuleGroup $group;
    private Rule $coffee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->primaryUsd($this->user);
        $this->checking = $this->asset($this->user);
        $this->withdrawal($this->user, $this->checking, 'Morning coffee', '4.50', '2026-08-03', 'Bean Cart');
        $this->withdrawal($this->user, $this->checking, 'Coffee beans', '18.00', '2026-08-15', 'Bean Cart');
        $this->withdrawal($this->user, $this->checking, 'Groceries run', '82.10', '2026-08-20');
        $this->withdrawal($this->user, $this->checking, 'Coffee with a friend', '7.25', '2026-07-02', 'Bean Cart');
        $this->group    = $this->ruleGroup($this->user);
        $this->coffee   = $this->rule($this->user, $this->group, 'Coffee is dining');
    }

    // ------------------------------------------------------------------ reads ---

    public function testRuleGroupsListTheirRulesInOrder(): void
    {
        $this->rule($this->user, $this->group, 'Second rule');
        $env = $this->envelope($this->machine('GET', '/rule-groups'));
        $this->assertTrue($env['ok']);
        $this->assertCount(1, $env['data']['rule_groups']);
        $group = $env['data']['rule_groups'][0];
        $this->assertSame('Household rules', $group['title']);
        $this->assertSame(2, $group['rule_count']);
        $this->assertSame(['Coffee is dining', 'Second rule'], array_column($group['rules'], 'title'));
        $this->assertArrayNotHasKey('links', $group);
        $this->assertFalse($env['meta']['truncated']);
    }

    public function testRulesListFiltersAndRendersFireflysShape(): void
    {
        $other = $this->ruleGroup($this->user, 'Business rules');
        $this->rule($this->user, $other, 'Invoices are income', ['trigger' => 'update-journal']);

        $env   = $this->envelope($this->machine('GET', '/rules'));
        $this->assertSame(['Coffee is dining', 'Invoices are income'], array_column($env['data']['rules'], 'title'));
        $first = $env['data']['rules'][0];
        $this->assertSame((string) $this->coffee->id, $first['id']);
        $this->assertSame('Household rules', $first['rule_group_title']);
        $this->assertSame('store-journal', $first['trigger']);
        $this->assertSame('description_contains', $first['triggers'][0]['type']);
        $this->assertSame('set_category', $first['actions'][0]['type']);
        $this->assertArrayNotHasKey('_execution', $first);

        $byGroup = $this->envelope($this->machine('GET', '/rules', ['rule_group_name' => 'business rules']));
        $this->assertSame(['Invoices are income'], array_column($byGroup['data']['rules'], 'title'));
        $byTrigger = $this->envelope($this->machine('GET', '/rules', ['trigger' => 'update-journal']));
        $this->assertSame(['Invoices are income'], array_column($byTrigger['data']['rules'], 'title'));
        $bySearch = $this->envelope($this->machine('GET', '/rules', ['search' => 'COFFEE']));
        $this->assertSame(['Coffee is dining'], array_column($bySearch['data']['rules'], 'title'));

        $this->assertPlaneError($this->machine('GET', '/rules', ['rule_group' => '3']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/rules', ['rule_group_name' => 'Nope']), 404, 'not_found');
    }

    public function testOneRuleByIdOrTitle(): void
    {
        $env = $this->envelope($this->machine('GET', '/rules/'.$this->coffee->id));
        $this->assertSame('Coffee is dining', $env['data']['rule']['title']);
        $env = $this->envelope($this->machine('GET', '/rules/'.rawurlencode('coffee is dining')));
        $this->assertSame((string) $this->coffee->id, $env['data']['rule']['id']);
        $this->assertPlaneError($this->machine('GET', '/rules/99999'), 404, 'not_found');
    }

    public function testValidateAnswersValidOrWhatIsWrong(): void
    {
        $good = $this->envelope($this->machine('POST', '/rules/validate', ['rule' => $this->ruleBody('Bakery is dining')]));
        $this->assertTrue($good['data']['valid']);

        $bad  = $this->envelope($this->machine('POST', '/rules/validate', ['rule' => [
            'title'         => 'Coffee is dining', // taken
            'rule_group_id' => (string) $this->group->id,
            'trigger'       => 'store-journal',
            'triggers'      => [['type' => 'not_a_trigger', 'value' => 'x'], ['type' => 'description_contains', 'value' => '']],
            'actions'       => [['type' => 'set_category']],
        ]]));
        $this->assertTrue($bad['ok']);
        $this->assertFalse($bad['data']['valid']);
        $this->assertArrayHasKey('rule.title', $bad['data']['errors']);
        $this->assertArrayHasKey('rule.triggers.0.type', $bad['data']['errors']);
        $this->assertArrayHasKey('rule.triggers.1.value', $bad['data']['errors']);
        $this->assertArrayHasKey('rule.actions.0.value', $bad['data']['errors']);

        // editing a rule may keep its own title
        $own  = $this->envelope($this->machine('POST', '/rules/validate', ['rule_id' => (string) $this->coffee->id, 'rule' => ['title' => 'Coffee is dining']]));
        $this->assertTrue($own['data']['valid']);
        $this->assertSame('update', $own['data']['mode']);

        $unknown = $this->envelope($this->machine('POST', '/rules/validate', ['rule' => ['title' => 'x', 'colour' => 'red']]));
        $this->assertFalse($unknown['data']['valid']);
        $this->assertStringContainsString('colour', $unknown['data']['errors']['rule'][0]);
        $this->assertPlaneError($this->machine('POST', '/rules/validate', []), 400, 'invalid_input');
    }

    public function testPreviewOfAnUnsavedRuleShowsMatchesAndChangesNothing(): void
    {
        $rules = Rule::query()->count();
        $groups = RuleGroup::query()->count();
        $env   = $this->envelope($this->machine('POST', '/rules/preview', ['rule' => [
            'strict'   => true,
            'triggers' => [['type' => 'description_contains', 'value' => 'coffee']],
            'actions'  => [['type' => 'set_category', 'value' => 'Coffee'], ['type' => 'add_tag', 'value' => 'caffeine']],
        ]]));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame(3, $env['data']['match_count']);
        $this->assertSame(3, $env['data']['would_change_count']);
        $this->assertFalse($env['data']['subject']['saved']);
        $match = $env['data']['matches'][0];
        $this->assertSame('Coffee beans', $match['description']);
        $this->assertSame('18.00', $match['amount']);
        $this->assertSame('USD', $match['currency_code']);
        $this->assertSame('withdrawal', $match['type']);
        $this->assertStringContainsString('category: (none) → Coffee', $match['would_change']);
        $this->assertStringContainsString('tags: +caffeine', $match['would_change']);
        $this->assertContains(['field' => 'category_name', 'from' => null, 'to' => 'Coffee'], $match['changes']);

        // nothing was saved: no rule, no group, no category, no tag
        $this->assertSame($rules, Rule::query()->count());
        $this->assertSame($groups, RuleGroup::query()->count());
        $this->assertSame(0, Category::query()->where('name', 'Coffee')->count());
        $this->assertSame(0, DB::table('tag_transaction_journal')->count());
        $this->assertContains('description', $env['meta']['untrusted']);
    }

    public function testPreviewHonoursTheRangeAccountsAndLimit(): void
    {
        $env = $this->envelope($this->machine('POST', '/rules/preview', ['rule_id' => (string) $this->coffee->id, 'start' => '2026-08-01', 'end' => '2026-08-31', 'limit' => 1]));
        $this->assertSame(2, $env['data']['match_count']);
        $this->assertCount(1, $env['data']['matches']);
        $this->assertTrue($env['data']['truncated_sample']);
        $this->assertTrue($env['meta']['truncated']);
        $this->assertTrue($env['data']['subject']['saved']);

        // ranges are inclusive on both ends (§14.3): the 3rd and the 15th are both in
        $edges   = $this->envelope($this->machine('POST', '/rules/preview', ['rule_id' => (string) $this->coffee->id, 'start' => '2026-08-03', 'end' => '2026-08-15']));
        $this->assertSame(2, $edges['data']['match_count']);

        $savings = $this->asset($this->user, 'Meridian Savings 7734');
        $none    = $this->envelope($this->machine('POST', '/rules/preview', ['rule_id' => (string) $this->coffee->id, 'account_names' => ['Meridian Savings 7734']]));
        $this->assertSame(0, $none['data']['match_count']);
        unset($savings);

        $this->assertPlaneError($this->machine('POST', '/rules/preview', ['rule_id' => (string) $this->coffee->id, 'start' => '2026-09-01', 'end' => '2026-08-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/rules/preview', ['start' => '2026-08-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/rules/preview', ['rule_id' => '424242']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/rules/preview', ['rule' => ['triggers' => [['type' => 'bogus', 'value' => 'x']], 'actions' => [['type' => 'set_category', 'value' => 'x']]]]), 400, 'invalid_input');
    }

    public function testGroupPreview(): void
    {
        $env = $this->envelope($this->machine('POST', '/rule-groups/'.$this->group->id.'/preview', []));
        $this->assertSame(3, $env['data']['match_count']);
        $this->assertSame('Household rules', $env['data']['subject']['rule_group_title']);
        $this->assertSame(0, Category::query()->where('name', 'Dining')->count());
        $this->assertPlaneError($this->machine('POST', '/rule-groups/777/preview', []), 404, 'not_found');
    }

    // ----------------------------------------------------------------- writes ---

    public function testCreateARuleDryRunThenApply(): void
    {
        $this->enableWrites();
        $before = Rule::query()->count();
        $plan   = $this->envelope($this->machine('POST', '/rules', ['rule' => $this->ruleBody('Bakery is dining')]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(1, $plan['data']['changes']['created']);
        $this->assertSame('Bakery is dining', $plan['data']['rule']['title']);
        $this->assertSame($before, Rule::query()->count(), 'the dry run saved nothing');

        $apply  = $this->envelope($this->machine('POST', '/rules', ['rule' => $this->ruleBody('Bakery is dining'), 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['dry_run']);
        $this->assertIsInt($apply['data']['operation_id']);
        $rule   = Rule::query()->where('title', 'Bakery is dining')->firstOrFail();
        $this->assertSame(2, $rule->ruleTriggers()->count(), 'the trigger moment plus one trigger');
        $this->assertSame(1, $rule->ruleActions()->count());

        // the operation log can reverse it (rule, triggers and actions)
        $this->bindForUndo();
        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame(0, Rule::withTrashed()->where('title', 'Bakery is dining')->count());
        $this->assertSame(0, RuleTrigger::query()->where('rule_id', $rule->id)->count());
        $this->assertSame(0, RuleAction::query()->where('rule_id', $rule->id)->count());
    }

    public function testCreateRefusesAnInvalidRuleAndTheWriteTierIsGated(): void
    {
        $this->assertPlaneError($this->machine('POST', '/rules', ['rule' => $this->ruleBody('X')]), 403, 'write_disabled');
        $this->enableWrites();
        $env = $this->assertPlaneError($this->machine('POST', '/rules', ['rule' => ['title' => 'No group', 'trigger' => 'store-journal', 'triggers' => [['type' => 'description_contains', 'value' => 'x']], 'actions' => [['type' => 'set_category', 'value' => 'x']]]]), 400, 'invalid_input');
        $this->assertArrayHasKey('rule.rule_group_id', $env['error']['details']['fields']);
        $this->assertStringContainsString('rules/validate', $env['error']['hint']);
        $this->assertPlaneError($this->machine('POST', '/rules', ['rule' => $this->ruleBody('Y'), 'dry_run' => false]), 403, 'forbidden');
    }

    public function testUpdateAStaleTokenIsRefusedWithTheNewCounts(): void
    {
        $this->enableWrites();
        $body = ['rule' => ['title' => 'Coffee is dining out', 'actions' => [['type' => 'set_category', 'value' => 'Dining out']]]];
        $plan = $this->envelope($this->machine('PUT', '/rules/'.$this->coffee->id, $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('Coffee is dining', Rule::query()->find($this->coffee->id)->title);

        // a human edits the rule in the browser meanwhile
        DB::table('rules')->where('id', $this->coffee->id)->update(['description' => 'edited by hand']);
        $stale = $this->assertPlaneError($this->machine('PUT', '/rules/'.$this->coffee->id, $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertArrayHasKey('changes', $stale['error']['details']);
        $this->assertSame('Coffee is dining', Rule::query()->find($this->coffee->id)->title);

        $plan  = $this->envelope($this->machine('PUT', '/rules/'.$this->coffee->id, $body));
        $apply = $this->envelope($this->machine('PUT', '/rules/'.$this->coffee->id, $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $fresh = Rule::query()->find($this->coffee->id);
        $this->assertSame('Coffee is dining out', $fresh->title);
        $this->assertSame('Dining out', $fresh->ruleActions()->first()->action_value);
        $this->assertSame('description_contains', $fresh->ruleTriggers()->where('trigger_type', '!=', 'user_action')->first()->trigger_type, 'triggers were not sent, so they were kept');
    }

    public function testRunRulesPreviewsThenCategorises(): void
    {
        $this->enableWrites();
        $plan = $this->envelope($this->machine('POST', '/rules/run', ['rule_ids' => [(string) $this->coffee->id], 'start' => '2026-08-01', 'end' => '2026-08-31']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['updated' => 2, 'deleted' => 0, 'matched' => 2], $plan['data']['changes']);
        $this->assertSame(2, $plan['data']['change_count']);
        $this->assertCount(2, $plan['data']['matches']);
        $this->assertSame(0, Category::query()->where('name', 'Dining')->count(), 'the preview changed nothing');

        $this->assertPlaneError($this->machine('POST', '/rules/run', ['rule_ids' => [(string) $this->coffee->id], 'start' => '2026-08-01', 'end' => '2026-08-31', 'max_changes' => 1]), 409, 'conflict');

        $apply = $this->envelope($this->machine('POST', '/rules/run', ['rule_ids' => [(string) $this->coffee->id], 'start' => '2026-08-01', 'end' => '2026-08-31', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(2, $apply['data']['changes']['updated']);
        $this->assertFalse($apply['data']['reversible']);
        $dining = Category::query()->where('name', 'Dining')->firstOrFail();
        $this->assertSame(2, DB::table('category_transaction_journal')->where('category_id', $dining->id)->count(), 'the July coffee was outside the range');

        $this->assertPlaneError($this->machine('POST', '/rules/run', ['start' => '2026-08-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/rules/run', ['rule_ids' => ['1'], 'rule_group_id' => (string) $this->group->id]), 400, 'invalid_input');
    }

    public function testRunARuleGroup(): void
    {
        $this->enableWrites();
        $plan  = $this->envelope($this->machine('POST', '/rules/run', ['rule_group_name' => 'Household rules']));
        $this->assertSame(3, $plan['data']['changes']['updated']);
        $apply = $this->envelope($this->machine('POST', '/rules/run', ['rule_group_name' => 'Household rules', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(3, DB::table('category_transaction_journal')->count());

        // run again: everything is already categorised, so nothing would change
        $again = $this->envelope($this->machine('POST', '/rules/run', ['rule_group_name' => 'Household rules']));
        $this->assertSame(0, $again['data']['change_count']);
        $this->assertSame(3, $again['data']['changes']['matched']);
    }

    public function testMoveAndReorder(): void
    {
        $this->enableWrites();
        $second = $this->rule($this->user, $this->group, 'Second rule');
        $other  = $this->ruleGroup($this->user, 'Business rules');

        $plan   = $this->envelope($this->machine('POST', '/rules/'.$second->id.'/move', ['order' => 1]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['moved']);
        $this->assertSame(1, $plan['data']['changes']['reordered']);
        $apply  = $this->envelope($this->machine('POST', '/rules/'.$second->id.'/move', ['order' => 1, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['Second rule', 'Coffee is dining'], $this->group->rules()->orderBy('order')->pluck('title')->all());

        $plan   = $this->envelope($this->machine('POST', '/rules/'.$this->coffee->id.'/move', ['rule_group_id' => (string) $other->id]));
        $apply  = $this->envelope($this->machine('POST', '/rules/'.$this->coffee->id.'/move', ['rule_group_id' => (string) $other->id, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame($other->id, Rule::query()->find($this->coffee->id)->rule_group_id);
        $this->assertSame('Business rules', $apply['data']['rule']['rule_group_title']);

        $this->assertPlaneError($this->machine('POST', '/rules/'.$second->id.'/move', []), 400, 'invalid_input');
    }

    public function testRuleGroupsCreateAndEdit(): void
    {
        $this->enableWrites();
        $plan  = $this->envelope($this->machine('POST', '/rule-groups', ['title' => 'Acme LLC rules', 'order' => 1]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(1, $plan['data']['changes']['created']);
        $this->assertSame(1, $plan['data']['changes']['reordered']);
        $this->assertSame(1, RuleGroup::query()->count());
        $apply = $this->envelope($this->machine('POST', '/rule-groups', ['title' => 'Acme LLC rules', 'order' => 1, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['Acme LLC rules', 'Household rules'], RuleGroup::query()->orderBy('order')->pluck('title')->all());

        $this->assertPlaneError($this->machine('POST', '/rule-groups', ['title' => 'Household rules']), 400, 'invalid_input');

        $plan  = $this->envelope($this->machine('PUT', '/rule-groups/'.$this->group->id, ['active' => false, 'title' => 'Home rules']));
        $apply = $this->envelope($this->machine('PUT', '/rule-groups/'.$this->group->id, ['active' => false, 'title' => 'Home rules', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(1, $apply['data']['changes']['updated']);
        $this->assertFalse(RuleGroup::query()->find($this->group->id)->active);
        $this->assertPlaneError($this->machine('PUT', '/rule-groups/'.$this->group->id, []), 400, 'invalid_input');
    }

    public function testDeletesAreAdminAndAGoneRuleIsDeletedZero(): void
    {
        $this->enableWrites();
        $this->assertPlaneError($this->machine('DELETE', '/rules/'.$this->coffee->id), 403, 'forbidden');
        $this->enableAdmin();
        $plan  = $this->envelope($this->machine('DELETE', '/rules/'.$this->coffee->id));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['deleted' => 1], $plan['data']['changes']);
        $this->assertNotNull(Rule::query()->find($this->coffee->id));
        $apply = $this->envelope($this->machine('DELETE', '/rules/'.$this->coffee->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(Rule::query()->find($this->coffee->id));

        $gone  = $this->envelope($this->machine('DELETE', '/rules/'.$this->coffee->id));
        $this->assertSame(0, $gone['data']['deleted']);

        // the operation log restores the rule with its triggers and actions
        $this->bindForUndo();
        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertNotNull(Rule::query()->find($this->coffee->id));
        $this->assertSame(2, RuleTrigger::query()->where('rule_id', $this->coffee->id)->count());

        $this->assertPlaneError($this->machine('DELETE', '/rules/no-such-rule'), 404, 'not_found');
        $this->assertPlaneError($this->machine('DELETE', '/rules/'.$this->coffee->id, [], ['X-Firefly-Client' => 'mcp']), 403, 'forbidden');
    }

    public function testDeleteAGroupMovingItsRules(): void
    {
        $this->enableWrites();
        $this->enableAdmin();
        $other = $this->ruleGroup($this->user, 'Business rules');
        $this->assertPlaneError($this->machine('DELETE', '/rule-groups/'.$this->group->id, ['move_rules_to' => (string) $this->group->id]), 400, 'invalid_input');
        $plan  = $this->envelope($this->machine('DELETE', '/rule-groups/'.$this->group->id, ['move_rules_to' => 'Business rules']));
        $this->assertSame(1, $plan['data']['changes']['rules_moved']);
        $apply = $this->envelope($this->machine('DELETE', '/rule-groups/'.$this->group->id, ['move_rules_to' => 'Business rules', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNull(RuleGroup::query()->find($this->group->id));
        $this->assertSame($other->id, Rule::query()->find($this->coffee->id)->rule_group_id);
    }

    public function testEveryRuleRouteIsLive(): void
    {
        $caps = $this->envelope($this->machine('GET', '/capabilities'));
        foreach ($caps['data']['routes'] as $route) {
            if (str_starts_with($route['path'], '/rule')) {
                foreach ($route['status'] as $method => $status) {
                    $this->assertSame('live', $status, $method.' '.$route['path']);
                }
            }
        }
    }

    // ------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function ruleBody(string $title): array
    {
        return [
            'title'         => $title,
            'rule_group_id' => (string) $this->group->id,
            'trigger'       => 'store-journal',
            'strict'        => true,
            'triggers'      => [['type' => 'description_contains', 'value' => 'bakery']],
            'actions'       => [['type' => 'set_category', 'value' => 'Dining']],
        ];
    }

    /** OperationLog scopes to the request's operator; bind it the way the plane does. */
    private function bindForUndo(): void
    {
        $request = request();
        $request->attributes->set('machine.operator', $this->user);
        $request->attributes->set('machine.administration', $this->user->userGroup);
        auth()->setUser($this->user);
    }
}
