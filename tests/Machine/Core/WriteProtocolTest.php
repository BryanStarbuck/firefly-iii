<?php

/*
 * WriteProtocolTest.php
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

use Carbon\Carbon;
use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Models\Category;
use FireflyIII\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §7 and §18 "Write protocol" / "Dry-run purity" / "Undo", through a test-only route
 * family so the real router, gates, controller base and renderer are all in the path:
 * dry run by default and side-effect free; no token refused; the token is single use, bound to
 * the route, the arguments and the key, and expires; a moved fingerprint is refused WITH THE NEW
 * COUNTS; the ceiling reports the real count; the operation log reverses creates, updates and
 * deletes and refuses when a human changed a row since; idempotency replays.
 *
 * @internal
 *
 * @coversNothing
 */
final class WriteProtocolTest extends MachineTestCase
{
    protected array $machineFamilies = [ProbeRoutes::class];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    public function testADryRunIsTheDefaultAndChangesNothing(): void
    {
        $before = $this->tableHash('categories');
        $ops    = DB::table('machine_operations')->count();
        $env    = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['Groceries', 'Dining']]));
        $this->assertTrue($env['ok']);
        $this->assertTrue($env['data']['dry_run']);
        $this->assertSame(['created' => 2], $env['data']['changes']);
        $this->assertSame(2, $env['data']['change_count']);
        $this->assertCount(2, $env['data']['category_ids'], 'the preview ran the real code');
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $env['data']['confirm_token']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $env['data']['expires_at']);
        $this->assertStringStartsWith('sha256:', $env['data']['fingerprint']);
        $this->assertSame(0, $env['data']['would_fire_webhooks']);
        $this->assertSame(['jobs' => 0, 'mail' => 0, 'notifications' => 0], $env['data']['held']);
        $this->assertSame($before, $this->tableHash('categories'), 'a dry run leaves the table byte-identical');
        $this->assertSame($ops, DB::table('machine_operations')->count());
        $this->assertSame('write', $env['meta']['tier']);
    }

    public function testARealWriteNeedsTheToken(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A'], 'dry_run' => false]), 403, 'forbidden');
        $this->assertStringContainsString('confirm_token', $env['error']['hint']);
        $this->assertSame(0, Category::query()->count());
        $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A'], 'dry_run' => false, 'confirm_token' => 'cf_invented']), 403, 'forbidden');
        $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A'], 'dry_run' => false, 'confirm_token' => 'cf_'.str_repeat('a', 32)]), 409, 'conflict');
        $this->assertSame(0, Category::query()->count());
    }

    public function testPlanThenApplyWritesOnceAndLogsTheOperation(): void
    {
        $plan  = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['Groceries', 'Dining']]));
        $apply = $this->machine('POST', '/_probe/categories', ['names' => ['Groceries', 'Dining'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]);
        $env   = $this->envelope($apply);
        $apply->assertStatus(200);
        $this->assertFalse($env['data']['dry_run']);
        $this->assertSame(['created' => 2], $env['data']['changes']);
        $this->assertIsInt($env['data']['operation_id']);
        $this->assertSame(['Dining', 'Groceries'], Category::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(1, DB::table('machine_operations')->count());

        $audit = (string) file_get_contents($this->sandbox.'/state/machine.audit');
        $this->assertStringContainsString('route=POST /_probe/categories', $audit);
        $this->assertStringContainsString('op='.$env['data']['operation_id'], $audit);
        $this->assertStringContainsString('changed=2', $audit);
        $this->assertStringContainsString('ok=true', $audit);
        $this->assertStringNotContainsString('Groceries', $audit, 'the audit line says what happened, never what it was about');
        $this->assertStringNotContainsString($plan['data']['confirm_token'], $audit, 'tokens are shortened in the audit line');

        // single use
        $again = $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['Groceries', 'Dining'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertStringContainsString('dry_run', $again['error']['hint']);
        $this->assertSame(2, Category::query()->count());
    }

    public function testTheTokenIsBoundToTheArgumentsAndTheRoute(): void
    {
        $plan = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['A']]));
        $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['B'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 403, 'forbidden');

        $plan = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['A']]));
        $env  = $this->assertPlaneError($this->machine('POST', '/_probe/categories/suffix', ['suffix' => 'x', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 403, 'forbidden');
        $this->assertSame('POST /_probe/categories', $env['error']['details']['token_route']);
        $this->assertSame(0, Category::query()->count());
    }

    public function testATokenExpiresAfterTenMinutes(): void
    {
        $plan = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['A']]));
        $this->travel(11)->minutes();
        $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(0, Category::query()->count());
    }

    public function testATokenDiesWithARotatedKey(): void
    {
        $plan = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['A']]));
        (new CredentialsFile($this->credentialsPath()))->rotate();
        $this->machineKey = (string) json_decode((string) file_get_contents($this->credentialsPath()), true)['firefly_iii']['machine']['api_key'];
        $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(0, Category::query()->count());
    }

    public function testAMovedFingerprintIsRefusedWithTheNewCounts(): void
    {
        $this->category('One');
        $this->category('Two');
        $plan = $this->envelope($this->machine('POST', '/_probe/categories/suffix', ['suffix' => ' (x)']));
        $this->assertSame(['updated' => 2], $plan['data']['changes']);

        $this->category('Three'); // the world moves: someone added a category in the browser
        $env = $this->assertPlaneError($this->machine('POST', '/_probe/categories/suffix', ['suffix' => ' (x)', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(['updated' => 3], $env['error']['details']['changes']);
        $this->assertSame(3, $env['error']['details']['change_count']);
        $this->assertSame(2, $env['error']['details']['planned_change_count']);
        $this->assertSame(['One', 'Three', 'Two'], Category::query()->orderBy('name')->pluck('name')->all(), 'nothing was written');

        // an edit to a row the plan updates moves the fingerprint too (its before-image changed)
        $plan = $this->envelope($this->machine('POST', '/_probe/categories/suffix', ['suffix' => ' (x)']));
        Category::query()->where('name', 'One')->update(['name' => 'Uno']);
        $this->assertPlaneError($this->machine('POST', '/_probe/categories/suffix', ['suffix' => ' (x)', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
    }

    public function testTheCeilingRefusesWithTheRealCount(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A', 'B', 'C'], 'max_changes' => 2]), 409, 'conflict');
        $this->assertSame(3, $env['error']['details']['change_count']);
        $this->assertSame(2, $env['error']['details']['max_changes']);
        $this->assertStringContainsString('3', $env['error']['hint']);

        // raised on the plan, lowered on the apply: the apply's ceiling still binds
        $plan = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['A', 'B', 'C'], 'max_changes' => 5]));
        $env  = $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A', 'B', 'C'], 'max_changes' => 2, 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(3, $env['error']['details']['change_count']);
        $this->assertSame(0, Category::query()->count());

        // the default ceiling is 200
        $names = array_map(static fn (int $i): string => 'c'.$i, range(1, 201));
        $env   = $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => $names]), 409, 'conflict');
        $this->assertSame(201, $env['error']['details']['change_count']);
        $this->assertSame(200, $env['error']['details']['max_changes']);
    }

    public function testAFailingWriteRollsBackAndIsTheEnvelope(): void
    {
        $this->assertPlaneError($this->machine('POST', '/_probe/boom-write'), 500, 'internal');
        $this->assertSame(0, Category::query()->count());
    }

    public function testWritesWithoutADryRunApplyDirectlyAndAreLogged(): void
    {
        $env = $this->envelope($this->machine('POST', '/_probe/direct', ['name' => 'Now']));
        $this->assertFalse($env['data']['dry_run']);
        $this->assertFalse($env['data']['dry_run_flag']);
        $this->assertIsInt($env['data']['operation_id']);
        $this->assertSame(1, Category::query()->count());
        $this->assertPlaneError($this->machine('POST', '/_probe/direct', ['name' => 'Now', 'dry_run' => true]), 400, 'invalid_input');
    }

    public function testUndoReversesCreatesUpdatesAndDeletes(): void
    {
        $this->category('Keep');
        $before = $this->tableHash('categories');

        $this->apply('/_probe/categories', ['names' => ['New']]);
        $this->apply('/_probe/categories/suffix', ['suffix' => '!']);
        $this->apply('/_probe/categories/delete', []);
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(2, Category::withTrashed()->count());

        $last = $this->envelope($this->machine('GET', '/_probe/undo/last'));
        $this->assertSame('POST /_probe/categories/delete', $last['data']['route']);
        $this->assertTrue($last['data']['reversible']);
        $this->assertSame([], $last['data']['blocked_by']);

        $this->assertPlaneError($this->machine('POST', '/_probe/undo'), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/_probe/undo', ['confirm_token' => 'cf_'.str_repeat('0', 32)]), 409, 'conflict');
        $this->undo();
        $this->assertSame(['Keep!', 'New!'], Category::query()->orderBy('name')->pluck('name')->all());
        $this->undo();
        $this->assertSame(['Keep', 'New'], Category::query()->orderBy('name')->pluck('name')->all());
        $this->undo();
        $this->assertSame($before, $this->tableHash('categories'), 'three undos restore the table exactly');
        $this->assertPlaneError($this->machine('GET', '/_probe/undo/last'), 404, 'not_found');
    }

    public function testUndoRefusesWhenAHumanChangedATouchedRowSince(): void
    {
        $this->category('Rent');
        $this->apply('/_probe/categories/suffix', ['suffix' => '-plane']);
        // a human edits the same row in the browser, a minute later
        Category::query()->where('name', 'Rent-plane')->update(['name' => 'Rent (human)', 'updated_at' => Carbon::now()->addMinute()]);

        $last = $this->envelope($this->machine('GET', '/_probe/undo/last'));
        $this->assertFalse($last['data']['reversible']);
        $this->assertCount(1, $last['data']['blocked_by']);
        $this->assertNull($last['data']['confirm_token'], 'no token for an operation that cannot be undone');
        $token = $this->planToken('POST /_probe/undo', (int) $last['data']['operation_id']);
        $env   = $this->assertPlaneError($this->machine('POST', '/_probe/undo', ['confirm_token' => $token]), 409, 'conflict');
        $this->assertCount(1, $env['error']['details']['blocked_by']);
        $this->assertSame('Rent (human)', Category::query()->value('name'), 'undo never destroys a human change');
    }

    public function testIdempotencyReplaysTheOriginalResponse(): void
    {
        $args  = ['names' => ['Once'], 'idempotency_key' => 'create-once-1'];
        $plan  = $this->envelope($this->machine('POST', '/_probe/categories', $args));
        $first = $this->envelope($this->machine('POST', '/_probe/categories', $args + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $retry = $this->envelope($this->machine('POST', '/_probe/categories', $args + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($retry['meta']['replayed']);
        $this->assertSame($first['data']['operation_id'], $retry['data']['operation_id']);
        $this->assertSame(1, Category::query()->count());

        $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['Other'], 'idempotency_key' => 'create-once-1', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
    }

    public function testASecondWriterWaitsThenGetsAConflict(): void
    {
        config(['machine.write_lock_wait' => 1]);
        $plan = $this->envelope($this->machine('POST', '/_probe/categories', ['names' => ['A']]));
        $lock = Cache::lock('machine:write', 60);
        $this->assertTrue($lock->get());

        try {
            $env = $this->assertPlaneError($this->machine('POST', '/_probe/categories', ['names' => ['A'], 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
            $this->assertStringContainsString('Retry', $env['error']['hint']);
        } finally {
            $lock->release();
        }
        $this->assertSame(0, Category::query()->count());
    }

    public function testAnAdminWriteNeedsTheAdminTier(): void
    {
        $this->assertPlaneError($this->machine('POST', '/_probe/admin-thing', ['names' => ['A']]), 403, 'forbidden');
        $this->enableAdmin();
        $this->assertTrue($this->envelope($this->machine('POST', '/_probe/admin-thing', ['names' => ['A']]))['data']['dry_run']);
    }

    // ------------------------------------------------------------------------

    private function category(string $name): Category
    {
        return Category::create(['name' => $name, 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
    }

    /** @param array<string, mixed> $args */
    private function apply(string $path, array $args): array
    {
        $plan = $this->envelope($this->machine('POST', $path, $args));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $env  = $this->envelope($this->machine('POST', $path, $args + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($env['ok'], (string) json_encode($env));

        return $env;
    }

    private function undo(): void
    {
        $plan = $this->envelope($this->machine('GET', '/_probe/undo/last'));
        $this->assertIsString($plan['data']['confirm_token']);
        $env  = $this->envelope($this->machine('POST', '/_probe/undo', ['confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame($plan['data']['operation_id'], $env['data']['operation_id'], 'undo reverses exactly the planned operation');
        // the token is single use
        $this->assertPlaneError($this->machine('POST', '/_probe/undo', ['confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
    }

    /** A token as the plan route would mint it (to prove reverse() itself refuses). */
    private function planToken(string $route, int $operationId): string
    {
        return \FireflyIII\Machine\Confirm\ConfirmTokens::mint($route, \FireflyIII\Machine\Confirm\ConfirmTokens::argsHash(['args' => [], 'params' => []]), 'op:'.$operationId, ['operation_id' => $operationId])['confirm_token'];
    }

    private function tableHash(string $table): string
    {
        return hash('sha256', (string) json_encode(DB::table($table)->orderBy('id')->get()->map(static fn ($r): array => (array) $r)->all()));
    }
}
