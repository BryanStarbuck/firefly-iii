<?php

/*
 * AdministrationTest.php
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

namespace Tests\Machine\Accounts;

use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Support\Facades\Amount;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.1 — administrations: list (the bound one marked), show (member count, primary
 * currency), rename / change primary currency through the write protocol; never another user's.
 *
 * @internal
 *
 * @coversNothing
 */
final class AdministrationTest extends MachineTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    private function secondAdministration(): UserGroup
    {
        $group = UserGroup::create(['title' => 'acme_llc books']);
        $role  = UserRole::query()->where('title', 'owner')->first();
        GroupMembership::create(['user_id' => $this->user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $group;
    }

    public function testListMarksTheBoundAdministration(): void
    {
        $second = $this->secondAdministration();
        $env    = $this->envelope($this->machine('GET', '/administrations'));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $rows   = $env['data']['administrations'];
        $this->assertCount(2, $rows);
        $bound  = array_values(array_filter($rows, static fn (array $r): bool => $r['bound']));
        $this->assertCount(1, $bound);
        $this->assertSame((int) $this->user->user_group_id, (int) $bound[0]['id']);
        $this->assertSame($env['meta']['administrationId'], (int) $bound[0]['id']);
        $this->assertIsString($bound[0]['primary_currency_code']);
        $this->assertSame(1, $bound[0]['member_count']);
        $this->assertContains((int) $second->id, array_map('intval', array_column($rows, 'id')));
    }

    public function testShowAndNotFound(): void
    {
        $env = $this->envelope($this->machine('GET', '/administrations/'.$this->user->user_group_id));
        $this->assertSame('test@email.com', $env['data']['administration']['title']);
        $this->assertTrue($env['data']['administration']['bound']);

        $byTitle = $this->envelope($this->machine('GET', '/administrations/'.rawurlencode('TEST@email.com')));
        $this->assertSame((int) $this->user->user_group_id, (int) $byTitle['data']['administration']['id']);

        // someone else's books are not found, not forbidden
        $foreign = UserGroup::create(['title' => 'household elsewhere']);
        $this->assertPlaneError($this->machine('GET', '/administrations/'.$foreign->id), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/administrations/99999'), 404, 'not_found');
    }

    public function testRenameThroughTheWriteProtocol(): void
    {
        $id   = (string) $this->user->user_group_id;
        $plan = $this->envelope($this->machine('PUT', '/administrations/'.$id, ['title' => 'Household']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertTrue($plan['data']['dry_run']);
        $this->assertSame(['updated' => 1], $plan['data']['changes']);
        $this->assertSame('Household', $plan['data']['administration']['title']);
        $this->assertSame('test@email.com', UserGroup::query()->find($id)->title, 'dry run');

        $apply = $this->envelope($this->machine('PUT', '/administrations/'.$id, ['title' => 'Household', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame('Household', UserGroup::query()->find($id)->title);
        $this->assertSame('Household', $apply['meta']['administrationName']);

        $same  = $this->envelope($this->machine('PUT', '/administrations/'.$id, ['title' => 'Household']));
        $this->assertSame(['unchanged' => 1], $same['data']['changes']);

        $this->assertPlaneError($this->machine('PUT', '/administrations/'.$id, []), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/administrations/'.$id, ['name' => 'x']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/administrations/'.$id, ['primary_currency_code' => 'ZZZ']), 404, 'not_found');
    }

    public function testChangeThePrimaryCurrency(): void
    {
        $id      = (string) $this->user->user_group_id;
        $group   = UserGroup::query()->findOrFail($id);
        $current = Amount::getPrimaryCurrencyByUserGroup($group)->code;
        $target  = 'EUR' === $current ? 'USD' : 'EUR';
        $plan    = $this->envelope($this->machine('PUT', '/administrations/'.$id, ['primary_currency_code' => $target]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame(['primary_currency_changed' => 1], $plan['data']['changes']);
        $this->assertSame($target, $plan['data']['administration']['primary_currency_code']);
        $this->assertIsInt($plan['data']['pc_amounts_recalculated']);
        $this->assertSame($current, Amount::getPrimaryCurrencyByUserGroup($group->refresh())->code, 'dry run');

        $apply   = $this->envelope($this->machine('PUT', '/administrations/'.$id, ['primary_currency_code' => $target, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame($target, Amount::getPrimaryCurrencyByUserGroup($group->refresh())->code);
    }

    public function testRenameNeedsTheWriteTier(): void
    {
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('PUT', '/administrations/'.$this->user->user_group_id, ['title' => 'Household']), 403, 'write_disabled');
    }
}
