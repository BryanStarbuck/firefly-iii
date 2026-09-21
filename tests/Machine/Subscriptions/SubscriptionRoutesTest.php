<?php

/*
 * SubscriptionRoutesTest.php
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

namespace Tests\Machine\Subscriptions;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.6 — /subscriptions (Firefly's bills), through the real router, gates and envelope.
 *
 * @internal
 *
 * @coversNothing
 */
final class SubscriptionRoutesTest extends MachineTestCase
{
    use SeedsSavings;

    private User $user;
    private Account $checking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->usd($this->user);
        $this->checking = $this->checking($this->user);
        $this->payroll($this->user, $this->checking, '2000.00', '2026-09-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testListShowsAmountsFrequencyAndPaidDates(): void
    {
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->transaction($this->user, 'withdrawal', $this->checking, 'Streaming September', '15.99', '2026-09-05', 'Streamco', ['bill_id' => $bill->id]);

        $env  = $this->envelope($this->machine('GET', '/subscriptions', ['start' => '2026-09-01', 'end' => '2026-09-30']));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame(['start' => '2026-09-01', 'end' => '2026-09-30', 'resolved_from' => null], $env['data']['range']);
        $row  = $env['data']['subscriptions'][0];
        $this->assertSame('Streaming', $row['name']);
        $this->assertSame('15.99', $row['amount_min']);
        $this->assertSame('15.99', $row['amount_max']);
        $this->assertSame('USD', $row['currency_code']);
        $this->assertSame('monthly', $row['repeat_freq']);
        $this->assertCount(1, $row['paid_dates']);
        $this->assertSame('2026-09-05', $row['paid_dates'][0]['date']);
        $this->assertSame('15.99', $row['paid_dates'][0]['amount']);
        $this->assertIsString($row['paid_dates'][0]['amount'], 'amounts are strings (§14.1)');
        $this->assertSame('read', $env['meta']['tier']);

        // no range: the operator's current viewRange period (1M by default), echoed
        $env  = $this->envelope($this->machine('GET', '/subscriptions'));
        $this->assertSame(Carbon::now(config('app.timezone'))->startOfMonth()->format('Y-m-d'), $env['data']['range']['start']);
        $this->assertSame(Carbon::now(config('app.timezone'))->endOfMonth()->format('Y-m-d'), $env['data']['range']['end']);
        $this->assertStringStartsWith('viewRange:', (string) $env['data']['range']['resolved_from']);
    }

    public function testStatusClassifiesPaidUnpaidAndExpected(): void
    {
        // read-only, so "today" can be pinned (write tests cannot: confirm tokens expire on the real clock)
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', config('app.timezone')));
        $paid     = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->bill($this->user, 'Gym', '40.00', '2026-01-03');
        $this->bill($this->user, 'Insurance', '120.00', '2026-01-28');
        $this->bill($this->user, 'Old magazine', '5.00', '2026-01-10', 'monthly', false);
        $this->transaction($this->user, 'withdrawal', $this->checking, 'Streaming September', '15.99', '2026-09-05', 'Streamco', ['bill_id' => $paid->id]);

        $env      = $this->envelope($this->machine('GET', '/subscriptions/status', ['start' => '2026-09-01', 'end' => '2026-09-30']));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $byName   = [];
        foreach ($env['data']['subscriptions'] as $row) {
            $byName[$row['name']] = $row;
        }
        $this->assertSame('paid', $byName['Streaming']['status']);
        $this->assertSame('15.99', $byName['Streaming']['paid_amount']);
        $this->assertSame('2026-09-05', $byName['Streaming']['paid_date']);
        $this->assertSame('unpaid', $byName['Gym']['status'], 'due on the 3rd, nothing seen by the 21st');
        $this->assertSame(['2026-09-03'], $byName['Gym']['unpaid_dates']);
        $this->assertSame('40.00', $byName['Gym']['unpaid_amount']);
        $this->assertSame('expected', $byName['Insurance']['status']);
        $this->assertSame('2026-09-28', $byName['Insurance']['expected_date']);
        $this->assertArrayNotHasKey('Old magazine', $byName, 'inactive subscriptions are not expected');
        $this->assertSame(1, $env['data']['counts']['inactive']);
        $this->assertSame('unpaid', $env['data']['subscriptions'][0]['status'], 'unpaid first');
        $this->assertSame([['currency_code' => 'USD', 'subscriptions' => 3, 'paid' => 1, 'unpaid' => 1, 'expected' => 1, 'not_expected' => 0, 'expected_amount' => '175.99', 'paid_amount' => '15.99', 'unpaid_amount' => '40.00']], $env['data']['totals']);
    }

    public function testShowResolvesIdOrNameAndRefusesUnknowns(): void
    {
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');

        $env  = $this->envelope($this->machine('GET', '/subscriptions/'.$bill->id));
        $this->assertSame($bill->id, $env['data']['subscription']['id']);
        $env  = $this->envelope($this->machine('GET', '/subscriptions/streaming'));
        $this->assertSame($bill->id, $env['data']['subscription']['id'], 'a name resolves case-insensitively');

        $this->assertPlaneError($this->machine('GET', '/subscriptions/99999'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/subscriptions/Nothing%20Like%20This'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/subscriptions', ['start' => '2026-09-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/subscriptions', ['start_date' => '2026-09-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/subscriptions', ['start' => '2026-09-30', 'end' => '2026-09-01']), 400, 'invalid_input');
    }

    public function testTransactionsListsTheLinkedGroups(): void
    {
        $bill  = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $group = $this->transaction($this->user, 'withdrawal', $this->checking, 'Streaming September', '15.99', '2026-09-05', 'Streamco', ['bill_id' => $bill->id]);
        $this->transaction($this->user, 'withdrawal', $this->checking, 'Groceries', '42.10', '2026-09-06', 'Corner Market');

        $env   = $this->envelope($this->machine('GET', '/subscriptions/'.$bill->id.'/transactions', ['start' => '2026-09-01', 'end' => '2026-09-30']));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertCount(1, $env['data']['transactions']);
        $this->assertSame($group->id, $env['data']['transactions'][0]['id']);
        $split = $env['data']['transactions'][0]['transactions'][0];
        $this->assertSame('15.99', $split['amount']);
        $this->assertSame('withdrawal', $split['type']);
        $this->assertSame('Northbank Checking 4021', $split['source_name']);
    }

    public function testCreateIsADryRunByDefaultThenAppliesWithTheToken(): void
    {
        $this->enableWrites();
        $body   = ['name' => 'Cloud storage', 'amount_min' => '9.99', 'amount_max' => '9.99', 'date' => '2026-09-10', 'repeat_freq' => 'monthly'];
        $before = $this->hashTables(['bills', 'notes']);

        $plan   = $this->envelope($this->machine('POST', '/subscriptions', $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(['created' => 1], $plan['data']['changes']);
        $this->assertSame('Cloud storage', $plan['data']['subscription']['name']);
        $this->assertSame('9.99', $plan['data']['subscription']['amount_min']);
        $this->assertSame($before, $this->hashTables(['bills', 'notes']), 'the dry run changed nothing');

        $done   = $this->envelope($this->machine('POST', '/subscriptions', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertFalse($done['data']['dry_run']);
        $this->assertIsInt($done['data']['operation_id']);
        $bill   = Bill::query()->where('name', 'Cloud storage')->first();
        $this->assertNotNull($bill);
        $this->assertTrue((bool) $bill->active);
        $this->assertSame('9.99', $done['data']['subscription']['amount_max']);

        // the token is single use
        $this->assertPlaneError($this->machine('POST', '/subscriptions', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
    }

    public function testCreateRefusesBadInput(): void
    {
        $this->enableWrites();
        $good = ['name' => 'Cloud storage', 'amount_min' => '9.99', 'amount_max' => '9.99', 'date' => '2026-09-10', 'repeat_freq' => 'monthly'];
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['amount_min' => 9.99] + $good), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['amount_min' => '9.999'] + $good), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['amount_min' => '20.00'] + $good), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['repeat_freq' => 'fortnightly'] + $good), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', $good + ['colour' => 'red']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['currency_code' => 'XXZ'] + $good), 404, 'not_found');
        $this->bill($this->user, 'Cloud storage', '9.99', '2026-01-10');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', $good), 400, 'invalid_input'); // upstream's uniqueness rule
        $this->assertSame(1, Bill::query()->count());
    }

    public function testWritesNeedTheWriteTier(): void
    {
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['name' => 'x']), 403, 'write_disabled');
    }

    public function testUpdateRenamesAndFollowsRulesAndAStaleTokenIsRefusedWithTheNewCounts(): void
    {
        $this->enableWrites();
        $bill   = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->ruleLinkingTo('Streaming');

        $plan   = $this->envelope($this->machine('PUT', '/subscriptions/'.$bill->id, ['name' => 'Streaming Plus', 'amount_max' => '19.99']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        $this->assertSame('19.99', $plan['data']['subscription']['amount_max']);
        $this->assertSame('15.99', $plan['data']['subscription']['amount_min'], 'the stored partner is kept');
        $this->assertSame('Streaming', $bill->refresh()->name, 'dry run');

        // somebody edits the subscription in the browser before the apply
        DB::table('bills')->where('id', $bill->id)->update(['amount_min' => '14.99']);
        $stale  = $this->assertPlaneError($this->machine('PUT', '/subscriptions/'.$bill->id, ['name' => 'Streaming Plus', 'amount_max' => '19.99', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(['updated' => 1], $stale['error']['details']['changes']);
        $this->assertSame('Streaming', $bill->refresh()->name);

        $plan   = $this->envelope($this->machine('PUT', '/subscriptions/'.$bill->id, ['name' => 'Streaming Plus', 'amount_max' => '19.99']));
        $done   = $this->envelope($this->machine('PUT', '/subscriptions/'.$bill->id, ['name' => 'Streaming Plus', 'amount_max' => '19.99', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame('Streaming Plus', $bill->refresh()->name);
        $this->assertSame('19.99', $done['data']['subscription']['amount_max']);
        $this->assertSame('Streaming Plus', DB::table('rule_actions')->where('action_type', 'link_to_bill')->value('action_value'), 'Firefly renamed the rule action with the subscription');

        $op     = DB::table('machine_operations')->where('id', $done['data']['operation_id'])->first();
        $classes = array_column(json_decode((string) $op->touched, true), 'class');
        $this->assertContains(Bill::class, $classes);
        $this->assertContains(\FireflyIII\Models\RuleAction::class, $classes, 'undo can restore the renamed rule action too');

        $this->assertPlaneError($this->machine('PUT', '/subscriptions/'.$bill->id, []), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/subscriptions/99999', ['name' => 'x']), 404, 'not_found');
    }

    public function testDeleteIsAdminAndADeletedSubscriptionIsDeletedZero(): void
    {
        $this->enableWrites();
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->assertPlaneError($this->machine('DELETE', '/subscriptions/'.$bill->id), 403, 'forbidden');

        $this->enableAdmin();
        $plan = $this->envelope($this->machine('DELETE', '/subscriptions/'.$bill->id));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['deleted' => 1], $plan['data']['changes']);
        $this->assertNull($bill->refresh()->deleted_at);

        $done = $this->envelope($this->machine('DELETE', '/subscriptions/'.$bill->id, ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertNotNull(Bill::withTrashed()->find($bill->id)->deleted_at);
        $op   = DB::table('machine_operations')->where('id', $done['data']['operation_id'])->first();
        $this->assertSame('deleted', json_decode((string) $op->touched, true)[0]['op']);

        $again = $this->envelope($this->machine('DELETE', '/subscriptions/'.$bill->id));
        $this->assertTrue($again['ok']);
        $this->assertSame(0, $again['data']['deleted']);
    }

    private function ruleLinkingTo(string $billName): void
    {
        auth()->setUser($this->user);
        /** @var RuleGroupRepositoryInterface $groups */
        $groups = app(RuleGroupRepositoryInterface::class);
        $groups->setUser($this->user);
        /** @var RuleGroup $group */
        $group  = $groups->store(['title' => 'Household rules', 'description' => null, 'active' => true]);
        /** @var RuleRepositoryInterface $rules */
        $rules  = app(RuleRepositoryInterface::class);
        $rules->setUser($this->user);
        $rules->store([
            'title'         => 'Streaming is a subscription',
            'rule_group_id' => $group->id,
            'trigger'       => 'store-journal',
            'active'        => true,
            'strict'        => true,
            'triggers'      => [['type' => 'description_contains', 'value' => 'streaming', 'active' => true, 'stop_processing' => false, 'prohibited' => false]],
            'actions'       => [['type' => 'link_to_bill', 'value' => $billName, 'active' => true, 'stop_processing' => false]],
        ]);
    }
}
