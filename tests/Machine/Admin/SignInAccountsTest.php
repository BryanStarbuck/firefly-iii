<?php

/*
 * SignInAccountsTest.php
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

namespace Tests\Machine\Admin;

use FireflyIII\Machine\Audit;
use FireflyIII\Machine\SignIn\SignInUsers;
use FireflyIII\Models\GroupMembership;
use FireflyIII\User;
use Illuminate\Support\Facades\Hash;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.11a and pm/accounts.mdx §4.3 — the three sign-in-account routes.
 *
 * The property that matters most here is the one that is easiest to lose in a refactor: the
 * plaintext password never leaves the request. It is not in the answer, not in the audit line,
 * and not in the error file. Two tests assert exactly that.
 *
 * @internal
 *
 * @coversNothing
 */
final class SignInAccountsTest extends MachineTestCase
{
    private const string PASSWORD = 'a-long-enough-passphrase';
    private const string SECOND   = 'another-long-passphrase!';

    // ------------------------------------------------- the bootstrap ---

    public function testTheFirstUserRouteMakesAWholeFireflyUserOnAnEmptyInstall(): void
    {
        $this->enableWrites();
        self::assertSame(0, User::query()->count());

        $env  = $this->envelope($this->machine('POST', '/admin/first-user', ['email' => 'you@example.com', 'password' => self::PASSWORD]));
        self::assertTrue($env['ok'], (string) json_encode($env));

        $user = $env['data']['user'];
        self::assertSame('you@example.com', $user['email']);
        self::assertTrue($user['is_owner'], 'the first account must be the owner');
        self::assertFalse($user['blocked']);
        self::assertNotNull($user['administration_id'], 'without an administration the operator can never bind');

        // The event ran, not just the INSERT: the role, the group and the membership all exist.
        $model = User::query()->firstOrFail();
        self::assertTrue(SignInUsers::isOwner($model));
        self::assertSame(1, GroupMembership::query()->where('user_id', $model->id)->count());
        self::assertSame((int) $model->user_group_id, $user['administration_id']);

        // And the password is a password: it signs in, which is the only thing that matters.
        self::assertTrue(Hash::check(self::PASSWORD, (string) $model->password));
    }

    public function testTheFirstUserRouteRefusesOnceAnAccountExists(): void
    {
        $this->enableWrites();
        $this->operatorUser();

        $env = $this->assertPlaneError($this->machine('POST', '/admin/first-user', ['email' => 'second@example.com', 'password' => self::PASSWORD]), 409, 'conflict');
        self::assertStringContainsString('already has', $env['error']['message']);
        self::assertStringContainsString('set-password', (string) $env['error']['hint']);
        self::assertSame(1, User::query()->count(), 'nothing may be created on the refusal path');
    }

    public function testTheFirstUserRouteIsTheWriteTierNotTheAdminTier(): void
    {
        // It has to work on a machine whose .env was never touched beyond the write switch:
        // an install with no users is exactly the install nobody has configured yet.
        config(['machine.allow_write' => false, 'machine.allow_admin' => true]);
        $this->assertPlaneError($this->machine('POST', '/admin/first-user', ['email' => 'you@example.com', 'password' => self::PASSWORD]), 403, 'write_disabled');

        config(['machine.allow_write' => true, 'machine.allow_admin' => false]);
        $env = $this->envelope($this->machine('POST', '/admin/first-user', ['email' => 'you@example.com', 'password' => self::PASSWORD]));
        self::assertTrue($env['ok'], 'the bootstrap must not need FIREFLY_MACHINE_ALLOW_ADMIN');
        self::assertSame('write', $env['meta']['tier']);
    }

    public function testTheFirstUserRouteAnswersWithoutAResolvedOperator(): void
    {
        // The whole point: on an install with no users there IS no operator to bind, so a route
        // that needed one could never be the route that creates the first one.
        $this->enableWrites();
        $env = $this->envelope($this->machine('POST', '/admin/first-user', ['email' => 'you@example.com', 'password' => self::PASSWORD]));
        self::assertTrue($env['ok']);
        self::assertNull($env['meta']['operator'], 'the binding happened before the account existed');
    }

    // ------------------------------------------------ further accounts ---

    public function testCreateUserIsAdminTierAndRefusesADuplicateEmail(): void
    {
        $this->enableAdmin();
        $first = $this->operatorUser();

        $env   = $this->envelope($this->machine('POST', '/admin/users', ['email' => 'second@example.com', 'password' => self::PASSWORD]));
        self::assertTrue($env['ok'], (string) json_encode($env));
        self::assertFalse($env['data']['user']['is_owner'], 'a second account is not automatically the owner');
        self::assertNotNull($env['data']['user']['administration_id']);

        $dup   = $this->assertPlaneError($this->machine('POST', '/admin/users', ['email' => 'second@example.com', 'password' => self::SECOND]), 409, 'conflict');
        self::assertStringContainsString('already exists', $dup['error']['message']);
        self::assertSame(2, User::query()->count());
        self::assertNotSame((int) $first->id, (int) $env['data']['user']['id']);
    }

    public function testCreateUserCanGrantTheOwnerRole(): void
    {
        $this->enableAdmin();
        $this->operatorUser();
        $env = $this->envelope($this->machine('POST', '/admin/users', ['email' => 'second@example.com', 'password' => self::PASSWORD, 'owner' => true]));
        self::assertTrue($env['data']['user']['is_owner']);
    }

    public function testCreateUserSaysWhichOperatorTheClientsStillActAs(): void
    {
        $this->enableAdmin();
        $this->operatorUser();
        config(['machine.operator' => null]);

        $env = $this->envelope($this->machine('POST', '/admin/users', ['email' => 'second@example.com', 'password' => self::PASSWORD]));
        // Two users and no FIREFLY_MACHINE_OPERATOR: the plane can no longer pick one, and the
        // answer has to say so rather than let the next call fail mysteriously (§4.9).
        self::assertIsString($env['data']['next']);
        self::assertStringContainsString('FIREFLY_MACHINE_OPERATOR', $env['data']['next']);
    }

    // --------------------------------------------------- the way back in ---

    public function testSetPasswordReplacesTheHashByIdOrByEmail(): void
    {
        $this->enableAdmin();
        $user = $this->operatorUser();

        $env  = $this->envelope($this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD]));
        self::assertTrue($env['data']['password_set']);
        self::assertFalse($env['data']['undoable'], 'the old hash is not kept, so this can never be undone');
        self::assertTrue(Hash::check(self::PASSWORD, (string) $user->fresh()->password));

        $env  = $this->envelope($this->machine('POST', '/admin/users/'.rawurlencode((string) $user->email).'/password', ['password' => self::SECOND]));
        self::assertTrue($env['ok']);
        self::assertTrue(Hash::check(self::SECOND, (string) $user->fresh()->password));
        self::assertFalse(Hash::check(self::PASSWORD, (string) $user->fresh()->password), 'the old password must stop working');
    }

    public function testSetPasswordClearsMfaAndUnblocksOnlyWhenAsked(): void
    {
        $this->enableAdmin();
        $user               = $this->operatorUser();
        $user->mfa_secret   = 'SECRETSECRETSECR';
        $user->blocked      = true;
        $user->blocked_code = 'email_changed';
        $user->save();

        // Not asked: the password changes and the two obstacles are REPORTED, not silently removed.
        $env = $this->envelope($this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD]));
        self::assertTrue($env['data']['user']['has_mfa']);
        self::assertTrue($env['data']['user']['blocked']);
        $notes = implode(' ', $env['data']['notes']);
        self::assertStringContainsString('two-factor', $notes);
        self::assertStringContainsString('BLOCKED', $notes);

        $env = $this->envelope($this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::SECOND, 'clear_mfa' => true, 'unblock' => true]));
        self::assertFalse($env['data']['user']['has_mfa']);
        self::assertFalse($env['data']['user']['blocked']);
    }

    public function testSetPasswordRefusesAnUnknownAccountAndAShortPassword(): void
    {
        $this->enableAdmin();
        $user = $this->operatorUser();

        $this->assertPlaneError($this->machine('POST', '/admin/users/nobody@example.com/password', ['password' => self::PASSWORD]), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/admin/users/999/password', ['password' => self::PASSWORD]), 404, 'not_found');

        // Upstream's own sign-up asks for 16; a password set here that the browser would reject
        // would be a password that cannot be used to sign in.
        $short = $this->assertPlaneError($this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => 'short']), 400, 'invalid_input');
        self::assertStringContainsString('16', (string) $short['error']['hint']);
    }

    public function testTheseRoutesHaveNoDryRunAndSayNothingAboutOne(): void
    {
        $this->enableAdmin();
        $user = $this->operatorUser();

        // A password has no preview, so the control fields are not accepted: sending dry_run is a
        // typo, and a typo that was silently ignored would look like a preview that never ran.
        $env  = $this->assertPlaneError($this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD, 'dry_run' => true]), 400, 'invalid_input');
        self::assertContains('dry_run', $env['error']['details']['unknown']);
        self::assertArrayNotHasKey('confirm_token', $this->envelope($this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD]))['data']);
    }

    // ------------------------------------------------------ the secret ---

    public function testThePasswordNeverAppearsInTheAnswerOrTheAuditLine(): void
    {
        $this->enableAdmin();
        $user     = $this->operatorUser();

        $response = $this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD]);
        self::assertStringNotContainsString(self::PASSWORD, (string) $response->getContent());
        // Nor the bcrypt hash: a hash is a secret too (§16.2).
        self::assertStringNotContainsString('$2y$', (string) $response->getContent());

        $audit    = (string) Audit::path();
        self::assertFileExists($audit);
        $lines    = (string) file_get_contents($audit);
        self::assertStringNotContainsString(self::PASSWORD, $lines);
        self::assertStringContainsString('route=POST /admin/users/{id}/password', $lines, 'the route IS logged — only the secret is not');
        self::assertStringContainsString('ok=true', $lines);
    }

    public function testTheMcpCanNeverChangeAPassword(): void
    {
        $this->enableAdmin();
        $this->enableWrites();
        $user = $this->operatorUser();

        $env  = $this->assertPlaneError(
            $this->machine('POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD], ['X-Firefly-Client' => 'mcp']),
            403,
            'forbidden',
        );
        self::assertStringContainsString('never available to the MCP', $env['error']['message']);
        self::assertFalse(Hash::check(self::PASSWORD, (string) $user->fresh()->password));
    }

    public function testEveryOneOfTheseRoutesNeedsTheMachineKey(): void
    {
        $this->enableAdmin();
        $this->enableWrites();
        $user  = $this->operatorUser();
        $calls = [
            ['POST', '/admin/first-user', ['email' => 'you@example.com', 'password' => self::PASSWORD]],
            ['POST', '/admin/users', ['email' => 'you@example.com', 'password' => self::PASSWORD]],
            ['POST', sprintf('/admin/users/%d/password', $user->id), ['password' => self::PASSWORD]],
            ['GET', '/admin/users', []],
        ];
        foreach ($calls as [$method, $path, $body]) {
            $this->assertPlaneError($this->machine($method, $path, $body, ['X-Firefly-Machine-Key' => null]), 401, 'unauthorized');
            $this->assertPlaneError($this->machine($method, $path, $body, ['X-Firefly-Machine-Key' => str_repeat('0', 64)]), 401, 'unauthorized');
        }
        self::assertSame(1, User::query()->count(), 'no unauthenticated call may have changed anything');
    }

    // ------------------------------------------------------- the list ---

    public function testAdminUsersListsBlockedAccountsAndAnswersWithoutAnOperator(): void
    {
        $this->enableAdmin();
        $this->operatorUser();
        $blocked               = User::create(['email' => 'locked@example.com', 'password' => bcrypt(self::PASSWORD)]);
        $blocked->blocked      = true;
        $blocked->blocked_code = 'email_changed';
        $blocked->save();

        // Two users and no configured operator — the state a person is in when they most need
        // this list, and the state in which the operator binding refuses.
        config(['machine.operator' => null]);
        $env    = $this->envelope($this->machine('GET', '/admin/users'));
        self::assertTrue($env['ok'], (string) json_encode($env));
        $emails = array_column($env['data']['users'], 'email');
        self::assertContains('locked@example.com', $emails, 'the blocked account is the one you are locked out of');
        self::assertCount(2, $emails);
    }
}
