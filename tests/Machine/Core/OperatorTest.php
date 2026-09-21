<?php

/*
 * OperatorTest.php
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

namespace Tests\Machine\Core;

use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §4.9 and §18 "Operator binding": one user → bound; two users and no setting →
 * not_ready naming both; a configured email (case-insensitively); a blocked user refused; an
 * administration the operator is not a member of refused; auth() bound for the request.
 *
 * @internal
 *
 * @coversNothing
 */
final class OperatorTest extends MachineTestCase
{
    protected array $machineFamilies = [ProbeRoutes::class];

    public function testExactlyOneUserIsBound(): void
    {
        $user = $this->operatorUser();
        $env  = $this->envelope($this->machine('GET', '/_probe/auth'));
        $this->assertSame($user->id, $env['data']['auth_id']);
        $this->assertSame($user->id, $env['data']['api_id'], 'the api guard is bound too');
        $this->assertSame($user->user_group_id, $env['data']['administration']);
        $this->assertSame('test@email.com', $env['meta']['operator']);

        $who  = $this->envelope($this->machine('GET', '/whoami'));
        $this->assertTrue($who['data']['operatorResolved']);
        $this->assertSame('test@email.com', $who['data']['operator']);
        $this->assertSame($user->user_group_id, $who['data']['administrationId']);
        $this->assertSame(['read' => true, 'write' => false, 'admin' => false], $who['data']['tiers']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{4}…\/sha256:[0-9a-f]{4}$/u', $who['data']['keyFingerprint']);
        $this->assertNull($who['data']['statementsRoot']);
    }

    public function testTwoUsersAndNoSettingIsNotReadyNamingBoth(): void
    {
        $this->operatorUser();
        $this->otherUser('second@example.invalid');
        $env = $this->assertPlaneError($this->machine('GET', '/_probe/auth'), 503, 'not_ready');
        $this->assertSame(['second@example.invalid', 'test@email.com'], $env['error']['details']['candidates']);
        $this->assertStringContainsString('FIREFLY_MACHINE_OPERATOR', $env['error']['hint']);

        // the diagnostics still answer, carrying the same refusal
        $who = $this->envelope($this->machine('GET', '/whoami'));
        $this->assertFalse($who['data']['operatorResolved']);
        $this->assertSame(2, $who['data']['users']);
        $this->assertSame(['second@example.invalid', 'test@email.com'], $who['data']['problem']['details']['candidates']);

        config(['machine.operator' => 'SECOND@example.invalid']);
        $env = $this->envelope($this->machine('GET', '/_probe/auth'));
        $this->assertSame(User::query()->where('email', 'second@example.invalid')->value('id'), $env['data']['auth_id']);

        config(['machine.operator' => 'nobody@example.invalid']);
        $env = $this->assertPlaneError($this->machine('GET', '/_probe/auth'), 503, 'not_ready');
        $this->assertCount(2, $env['error']['details']['candidates']);
    }

    public function testABlockedUserIsRefused(): void
    {
        $user = $this->operatorUser();
        $user->blocked      = true;
        $user->blocked_code = 'bounced';
        $user->save();
        $this->assertPlaneError($this->machine('GET', '/_probe/auth'), 403, 'forbidden');
    }

    public function testAnAdministrationTheOperatorIsNotAMemberOfIsRefused(): void
    {
        $user  = $this->operatorUser();
        $other = UserGroup::create(['title' => 'someone-elses-books']);
        config(['machine.administration' => (string) $other->id]);
        $env   = $this->assertPlaneError($this->machine('GET', '/_probe/auth'), 503, 'not_ready');
        $this->assertSame([['id' => (int) $user->user_group_id, 'title' => 'test@email.com']], $env['error']['details']['candidates']);

        config(['machine.administration' => 'abc']);
        $this->assertPlaneError($this->machine('GET', '/_probe/auth'), 503, 'not_ready');

        // a second administration the operator IS a member of can be chosen
        $role  = UserRole::query()->where('title', 'owner')->first();
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $other->id, 'user_role_id' => $role->id]);
        config(['machine.administration' => (string) $other->id]);
        $env   = $this->envelope($this->machine('GET', '/_probe/auth'));
        $this->assertSame($other->id, $env['data']['administration']);
        $this->assertSame('someone-elses-books', $env['meta']['administrationName']);
    }

    public function testNoUsersAtAllSaysRegisterFirst(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/_probe/auth'), 503, 'not_ready');
        $this->assertStringContainsString('Register', $env['error']['hint']);
    }

    private function otherUser(string $email): User
    {
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }
}
