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

use FireflyIII\Machine\Http\Controllers\BatchController;
use FireflyIII\Models\Category;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §7.4 — POST /batch: one transaction, all or nothing, one confirm token, the
 * ceiling counting changes; each operation runs through its own live write route. The test-only
 * probe routes stand in for the real families' write routes (BatchController::registerTestOp).
 *
 * @internal
 *
 * @coversNothing
 */
final class BatchTest extends MachineTestCase
{
    protected array $machineFamilies = [ProbeRoutes::class];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        BatchController::registerTestOp('probe.categories', ['POST', '/_probe/categories']);
        BatchController::registerTestOp('probe.suffix', ['POST', '/_probe/categories/suffix']);
        BatchController::registerTestOp('probe.boom', ['POST', '/_probe/boom-write']);
        BatchController::registerTestOp('probe.direct', ['POST', '/_probe/direct']);
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    protected function tearDown(): void
    {
        BatchController::resetTestOps();
        parent::tearDown();
    }

    public function testTheDryRunIsTheDefaultAndChangesNothing(): void
    {
        $env = $this->envelope($this->machine('POST', '/batch', ['operations' => [
            ['op' => 'probe.categories', 'names' => ['Groceries', 'Dining']],
            ['op' => 'probe.categories', 'names' => ['Travel']],
            ['op' => 'probe.suffix', 'suffix' => '*'],
        ]]));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertTrue($env['data']['dry_run']);
        $this->assertSame(['created' => 3, 'updated' => 3], $env['data']['changes'], 'later operations see earlier ones');
        $this->assertSame(6, $env['data']['change_count']);
        $this->assertCount(3, $env['data']['operations']);
        $this->assertSame('probe.suffix', $env['data']['operations'][2]['op']);
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $env['data']['confirm_token']);
        $this->assertTrue($env['meta']['composed']);
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, DB::table('machine_operations')->count());
    }

    public function testApplyWritesEverythingAsOneOperationThatOneUndoReverses(): void
    {
        $body  = ['operations' => [['op' => 'probe.categories', 'names' => ['Groceries']], ['op' => 'probe.categories', 'names' => ['Dining']]]];
        $plan  = $this->envelope($this->machine('POST', '/batch', $body));
        $this->assertPlaneError($this->machine('POST', '/batch', $body + ['dry_run' => false]), 403, 'forbidden');
        $apply = $this->envelope($this->machine('POST', '/batch', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertFalse($apply['data']['dry_run']);
        $this->assertSame(['created' => 2], $apply['data']['changes']);
        $this->assertIsInt($apply['data']['operation_id']);
        $this->assertSame(['Dining', 'Groceries'], Category::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame(1, DB::table('machine_operations')->count(), 'one batch, one operation-log row');

        $undo  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('POST /batch', $undo['data']['route']);
        $done  = $this->envelope($this->machine('POST', '/undo', ['confirm_token' => $undo['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame(0, Category::query()->count(), 'one undo reverses the whole batch');
    }

    public function testAFailingOperationRollsBackTheWholeBatchAndNamesTheIndex(): void
    {
        $body = ['operations' => [['op' => 'probe.categories', 'names' => ['Groceries']], ['op' => 'probe.boom']]];
        $env  = $this->assertPlaneError($this->machine('POST', '/batch', $body), 500, 'internal');
        $this->assertSame(1, $env['error']['details']['index']);
        $this->assertSame('probe.boom', $env['error']['details']['op']);
        $this->assertStringContainsString('Nothing in the batch was written', $env['error']['message']);
        $this->assertSame(0, Category::query()->count());

        $bad  = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'probe.categories', 'names' => ['Ok']], ['op' => 'probe.categories', 'names' => []]]]), 400, 'invalid_input');
        $this->assertSame(1, $bad['error']['details']['index']);
        $this->assertArrayHasKey('fields', $bad['error']['details'], 'the operation\'s own validation details come through');
        $this->assertSame(0, Category::query()->count());
    }

    public function testTheOpSetIsClosed(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'category.delete_everything']]]), 400, 'invalid_input');
        $this->assertContains('probe.categories', $env['error']['details']['available']);
        $this->assertNotContains('probe.direct', $env['error']['details']['available'], 'a route without a dry run cannot be batched');
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'probe.direct', 'name' => 'x']]]), 503, 'not_ready');
        $env = $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'probe.categories', 'names' => ['A'], 'dry_run' => false]]]), 400, 'invalid_input');
        $this->assertSame('dry_run', $env['error']['details']['field']);
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => []]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/batch', ['ops' => []]), 400, 'invalid_input');

        $caps = $this->envelope($this->machine('GET', '/capabilities'));
        $this->assertContains('probe.categories', $caps['data']['batchOps']);
        $this->assertContains('batch', $caps['data']['features']);
    }

    public function testTheCeilingCountsChangesAndAStalePlanIsRefused(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/batch', ['max_changes' => 2, 'operations' => [['op' => 'probe.categories', 'names' => ['A', 'B']], ['op' => 'probe.categories', 'names' => ['C']]]]), 409, 'conflict');
        $this->assertSame(3, $env['error']['details']['change_count']);

        Category::create(['name' => 'Rent', 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        $body = ['operations' => [['op' => 'probe.suffix', 'suffix' => '!']]];
        $plan = $this->envelope($this->machine('POST', '/batch', $body));
        Category::create(['name' => 'Added in the browser', 'user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id]);
        $stale = $this->assertPlaneError($this->machine('POST', '/batch', $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]), 409, 'conflict');
        $this->assertSame(2, $stale['error']['details']['change_count'], 'the refusal carries the NEW counts');
        $this->assertSame(['Added in the browser', 'Rent'], Category::query()->orderBy('name')->pluck('name')->all());
    }

    public function testBatchIsAWriteTierRoute(): void
    {
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('POST', '/batch', ['operations' => [['op' => 'probe.categories', 'names' => ['A']]]]), 403, 'write_disabled');
    }
}
