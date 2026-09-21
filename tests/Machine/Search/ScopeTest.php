<?php

/*
 * ScopeTest.php
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

namespace Tests\Machine\Search;

use FireflyIII\Models\Category;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\Webhook;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * §4.9 / §16.1 — with two users on the install, the search, the mirror, undo and the webhook
 * routes see the bound operator's records and nobody else's.
 *
 * @internal
 *
 * @coversNothing
 */
final class ScopeTest extends MachineTestCase
{
    use SearchLedger;

    private User $user;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user  = $this->operatorUser();
        $this->other = User::create(['email' => 'other@example.test', 'password' => bcrypt('x')]);
        $group       = UserGroup::create(['title' => 'other@example.test']);
        $this->other->user_group_id = $group->id;
        $this->other->save();
        GroupMembership::create(['user_id' => $this->other->id, 'user_group_id' => $group->id, 'user_role_id' => 1]);
        config(['machine.operator' => 'test@email.com']);
        $this->seedSpending($this->user);
        $this->enableWrites();
    }

    public function testSearchAndMirrorAnswerOnlyTheOperatorsRecords(): void
    {
        $theirs = $this->assetAccount($this->other, 'Meridian Savings 7734');
        $this->spend($this->other, $theirs, 'Their coffee', '9.99', '2026-03-03');
        $this->spend($this->other, $theirs, 'Their hardware', '99.99', '2026-03-04');

        $env = $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:0']));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame(3, $env['data']['total'], 'only the operator\'s transactions');
        $this->assertSame(2, $this->envelope($this->machine('GET', '/search/count', ['query' => 'description_contains:coffee']))['data']['count']);
        $this->assertSame(0, $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'Meridian']))['data']['total']);
        $this->assertSame(0, $this->envelope($this->machine('GET', '/search/accounts', ['query' => '7734', 'field' => 'name']))['data']['total']);

        $this->assertPlaneError($this->machine('GET', '/mirror/accounts/'.$theirs->id), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/mirror/transactions/'.$this->other->transactionGroups()->first()->id), 404, 'not_found');
        $this->assertCount(3, $this->envelope($this->machine('GET', '/mirror/transactions'))['data']['result']);
        $this->assertCount(1, $this->envelope($this->machine('GET', '/mirror/accounts', ['type' => 'asset']))['data']['result']);

        // /search/accounts pages like every list (§5.5)
        $this->assetAccount($this->user, 'Northbank Savings 4022');
        $page  = $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'Northbank', 'limit' => 1]));
        $this->assertCount(1, $page['data']['accounts']);
        $this->assertSame(2, $page['data']['total']);
        $this->assertTrue($page['meta']['truncated']);
        $this->assertSame('Northbank Checking 4021', $page['data']['accounts'][0]['name']);
        $page2 = $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'Northbank', 'limit' => 1, 'offset' => 1, 'order' => '-name']));
        $this->assertSame('Northbank Checking 4021', $page2['data']['accounts'][0]['name']);
        $this->assertFalse($page2['meta']['truncated']);
    }

    public function testUndoSeesOnlyTheOperatorsOperations(): void
    {
        DB::table('machine_operations')->insert([
            'created_at' => now(), 'updated_at' => now(),
            'user_id'    => $this->other->id, 'user_group_id' => $this->other->user_group_id,
            'route'      => 'POST /categories', 'caller' => 'test', 'key_fingerprint' => null,
            'changes'    => json_encode(['created' => 1]), 'touched' => json_encode([['class' => Category::class, 'id' => 1, 'op' => 'created', 'before' => null]]), 'reversed_at' => null,
        ]);
        $env = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertNull($env['data']['operation_id'], 'another user\'s operation is invisible');
        $this->assertNull($env['data']['confirm_token']);
    }

    public function testWebhookRoutesResolveWithinTheOperatorAndReportAmbiguity(): void
    {
        $this->enableAdmin();
        AppConfiguration::set('allow_webhooks', true);
        foreach ([['Feed', $this->user], ['feed', $this->user], ['Theirs', $this->other]] as [$title, $owner]) {
            Webhook::create(['user_id' => $owner->id, 'user_group_id' => $owner->user_group_id, 'title' => $title, 'url' => 'http://127.0.0.1:9/'.$title, 'secret' => 'sekret-'.$title, 'active' => true, 'trigger' => 1, 'response' => 1, 'delivery' => 1]);
        }
        // two case-insensitive matches: never a silent pick
        $env    = $this->assertPlaneError($this->machine('PUT', '/webhooks/FEED', ['title' => 'x']), 400, 'invalid_input');
        $this->assertCount(2, $env['error']['details']['candidates']);
        $theirs = Webhook::query()->where('title', 'Theirs')->firstOrFail();
        $this->assertPlaneError($this->machine('PUT', '/webhooks/'.$theirs->id, ['title' => 'x']), 404, 'not_found');
        $this->assertPlaneError($this->machine('PUT', '/webhooks/Theirs', ['title' => 'x']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/webhooks/'.$theirs->id.'/submit'), 404, 'not_found');
        $gone   = $this->envelope($this->machine('DELETE', '/webhooks/'.$theirs->id));
        $this->assertSame(0, $gone['data']['deleted'], 'another user\'s webhook reads as already gone, never as a target');
        $this->assertSame(1, Webhook::query()->where('id', $theirs->id)->count());
        $this->assertPlaneError($this->machine('PUT', '/webhooks/Feed', ['title' => 'x', 'colour' => 'red']), 400, 'invalid_input');

        // the mirror redacts a webhook's signing secret (§16.2)
        $list   = $this->envelope($this->machine('GET', '/mirror/webhooks'));
        $this->assertTrue($list['ok'], (string) json_encode($list));
        $this->assertCount(2, $list['data']['result']);
        $this->assertStringNotContainsString('sekret', (string) json_encode($list));
        $one    = $this->envelope($this->machine('GET', '/mirror/webhooks/'.Webhook::query()->where('title', 'Feed')->value('id')));
        $this->assertStringNotContainsString('sekret', (string) json_encode($one));
        // regression: Firefly's Webhook binder answers 401 (not 404) for a webhook that is not the
        // user's; through the mirror the operator is always bound, so that 401 is a missing record
        $env    = $this->assertPlaneError($this->machine('GET', '/mirror/webhooks/'.$theirs->id), 404, 'not_found');
        $this->assertStringNotContainsString('Unauthenticated', (string) json_encode($env));
    }
}
