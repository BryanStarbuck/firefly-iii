<?php

/*
 * RouteDef.php
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

namespace Tests\Machine\Undo;

use Carbon\Carbon;
use FireflyIII\Models\Category;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §7.5 — GET /undo/last (the plan, with the token) and POST /undo (token required,
 * no dry run), over real plane writes made through the test-only probe family.
 *
 * @internal
 *
 * @coversNothing
 */
final class UndoTest extends MachineTestCase
{

    protected array $machineFamilies = [ProbeRoutes::class];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    public function testNothingToUndoIsAnAnswerNotAnError(): void
    {
        $env = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($env['ok']);
        $this->assertNull($env['data']['operation_id']);
        $this->assertNull($env['data']['confirm_token']);
        $this->assertFalse($env['data']['reversible']);
        $this->assertStringContainsString('Nothing to undo', $env['data']['description']);
        $this->assertSame('read', $env['meta']['tier']);
    }

    public function testThePlanNamesTheOperationAndCarriesTheToken(): void
    {
        $op   = $this->apply('/_probe/categories', ['names' => ['Groceries', 'Dining']]);
        $plan = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame($op['data']['operation_id'], $plan['data']['operation_id']);
        $this->assertSame('POST /_probe/categories', $plan['data']['route']);
        $this->assertTrue($plan['data']['reversible']);
        $this->assertCount(2, $plan['data']['effects']);
        $this->assertSame('delete', $plan['data']['effects'][0]['undo']);
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $plan['data']['confirm_token']);
        $this->assertStringContainsString('2 × created category', $plan['data']['description']);
        $this->assertSame(2, Category::query()->count(), 'the plan changes nothing');
    }

    public function testUndoNeedsTheTokenAndReversesExactlyThePlannedOperation(): void
    {
        $this->apply('/_probe/categories', ['names' => ['Groceries']]);
        $this->assertPlaneError($this->machine('POST', '/undo'), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/undo', ['confirm_token' => 'cf_'.str_repeat('0', 32)]), 409, 'conflict');
        $this->assertPlaneError($this->machine('POST', '/undo', ['confirm_token' => 'x', 'dry_run' => true]), 400, 'invalid_input');

        $plan = $this->envelope($this->machine('GET', '/undo/last'));
        $env  = $this->envelope($this->machine('POST', '/undo', ['confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($plan['data']['operation_id'], $env['data']['operation_id']);
        $this->assertSame(['deleted' => 1], $env['data']['changes']);
        $this->assertStringContainsString('Undid operation', $env['data']['description']);
        $this->assertSame(0, Category::query()->count());
        $this->assertSame('write', $env['meta']['tier']);
        // single use, and now nothing is left to undo
        $this->assertPlaneError($this->machine('POST', '/undo', ['confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertNull($this->envelope($this->machine('GET', '/undo/last'))['data']['operation_id']);
        $audit = (string) file_get_contents($this->sandbox.'/state/machine.audit');
        $this->assertStringContainsString('route=POST /undo', $audit);
    }

    public function testUndoWalksBackOperationByOperation(): void
    {
        Category::create(['name' => 'Keep', 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        $before = $this->tableHash('categories');
        $this->apply('/_probe/categories', ['names' => ['New']]);
        $this->apply('/_probe/categories/suffix', ['suffix' => '!']);
        $this->apply('/_probe/categories/delete', []);
        $this->undo();
        $this->assertSame(['Keep!', 'New!'], Category::query()->orderBy('name')->pluck('name')->all());
        $this->undo();
        $this->undo();
        $this->assertSame($before, $this->tableHash('categories'), 'three undos restore the table exactly');
    }

    public function testAStalePlanIsRefusedWhenANewerWriteHappened(): void
    {
        $this->apply('/_probe/categories', ['names' => ['First']]);
        $plan = $this->envelope($this->machine('GET', '/undo/last'));
        $this->apply('/_probe/categories', ['names' => ['Second']]);
        $env  = $this->assertPlaneError($this->machine('POST', '/undo', ['confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame($plan['data']['operation_id'], $env['error']['details']['planned_operation_id']);
        $this->assertSame(2, Category::query()->count(), 'nothing was undone');
    }

    public function testUndoRefusesWhenAHumanChangedATouchedRow(): void
    {
        Category::create(['name' => 'Rent', 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        $this->apply('/_probe/categories/suffix', ['suffix' => '-plane']);
        $plan = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($plan['data']['reversible']);
        // a human edits the row in the browser after the plan
        Category::query()->where('name', 'Rent-plane')->update(['name' => 'Rent (human)', 'updated_at' => Carbon::now()->addMinute()]);
        $this->assertPlaneError($this->machine('POST', '/undo', ['confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame('Rent (human)', Category::query()->value('name'), 'undo never destroys a human change');

        $again = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertFalse($again['data']['reversible']);
        $this->assertNull($again['data']['confirm_token']);
        $this->assertStringContainsString('CANNOT be undone', $again['data']['description']);
    }

    public function testUndoIsAWriteTierRoute(): void
    {
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('POST', '/undo', ['confirm_token' => 'cf_'.str_repeat('0', 32)]), 403, 'write_disabled');
        $this->assertTrue($this->envelope($this->machine('GET', '/undo/last'))['ok'], 'the plan is read-tier');
    }

    // ------------------------------------------------------------------------

    /** @param array<string, mixed> $args */
    private function apply(string $path, array $args): array
    {
        $plan = $this->envelope($this->machine('POST', $path, $args));
        $env  = $this->envelope($this->machine('POST', $path, $args + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($env['ok'], (string) json_encode($env));

        return $env;
    }

    private function undo(): void
    {
        $plan = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertIsString($plan['data']['confirm_token'], (string) json_encode($plan));
        $env  = $this->envelope($this->machine('POST', '/undo', ['confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($env['ok'], (string) json_encode($env));
    }

    private function tableHash(string $table): string
    {
        return hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all()));
    }
}
