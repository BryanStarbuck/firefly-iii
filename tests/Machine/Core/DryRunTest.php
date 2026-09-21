<?php

/*
 * DryRunTest.php
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

use FireflyIII\Machine\Confirm\ConfirmTokens;
use FireflyIII\Machine\DryRun;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Category;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use RuntimeException;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §7.2 "the preview is the write": DryRun always rolls back — on success and on an
 * exception — holds jobs, mail and notifications behind recorders and swaps the real ones back,
 * and counts the webhooks a write would fire. Plus the token's argument hash.
 *
 * @internal
 *
 * @coversNothing
 */
final class DryRunTest extends MachineTestCase
{
    public function testItAlwaysRollsBackAndReturnsTheValue(): void
    {
        $user   = $this->operatorUser();
        $level  = DB::transactionLevel();
        $result = DryRun::run(static function () use ($user): WriteResult {
            $c = Category::create(['name' => 'Preview', 'user_id' => $user->id, 'user_group_id' => $user->user_group_id]);

            return (new WriteResult())->created($c)->count('created');
        });
        $this->assertInstanceOf(WriteResult::class, $result->value);
        $this->assertSame(1, $result->value->changeCount());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame($level, DB::transactionLevel());
    }

    public function testAnExceptionStillRollsBackAndPropagates(): void
    {
        $user  = $this->operatorUser();
        $level = DB::transactionLevel();

        try {
            DryRun::run(static function () use ($user): never {
                Category::create(['name' => 'Half', 'user_id' => $user->id, 'user_group_id' => $user->user_group_id]);

                throw new RuntimeException('nope');
            });
            $this->fail('the exception must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('nope', $e->getMessage());
        }
        $this->assertSame(0, Category::query()->count());
        $this->assertSame($level, DB::transactionLevel());
        $this->assertNotInstanceOf(BusFake::class, Bus::getFacadeRoot(), 'the real dispatcher is back after a failure');
    }

    public function testJobsMailAndNotificationsAreHeldThenTheRealOnesSwappedBack(): void
    {
        $ran    = false;
        $result = DryRun::run(static function () use (&$ran): WriteResult {
            dispatch(static function () use (&$ran): void {
                $ran = true;
            });
            Mail::raw('would have been sent', static function ($m): void {
                $m->to('nobody@example.invalid');
            });

            return new WriteResult();
        });
        $this->assertFalse($ran, 'a job dispatched in a dry run never runs');
        $this->assertSame(1, $result->jobs);
        $this->assertNotInstanceOf(BusFake::class, Bus::getFacadeRoot());
        $this->assertNotInstanceOf(MailFake::class, Mail::getFacadeRoot());
        $this->assertNotInstanceOf(\Illuminate\Support\Testing\Fakes\NotificationFake::class, Notification::getFacadeRoot());
        $this->assertSame('sync', config('queue.default'));
    }

    public function testWebhookMessagesAreCountedAndRolledBack(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('webhook_messages') || !\Illuminate\Support\Facades\Schema::hasTable('webhooks')) {
            $this->markTestSkipped('no webhook tables');
        }
        $user    = $this->operatorUser();
        $webhook = DB::table('webhooks')->insertGetId([
            'created_at' => now(), 'updated_at' => now(), 'user_id' => $user->id, 'user_group_id' => $user->user_group_id,
            'active' => true, 'title' => 'invented', 'secret' => 'invented-secret', 'trigger' => 100, 'response' => 200, 'delivery' => 300, 'url' => 'https://example.invalid/hook',
        ]);
        $before  = DB::table('webhook_messages')->count();
        $result  = DryRun::run(static function () use ($webhook): WriteResult {
            DB::table('webhook_messages')->insert([
                'created_at' => now(), 'updated_at' => now(), 'webhook_id' => $webhook, 'sent' => false, 'errored' => false,
                'uuid' => '00000000-0000-4000-8000-000000000001', 'message' => '{}',
            ]);

            return new WriteResult();
        });
        $this->assertSame(1, $result->webhooks);
        $this->assertSame($before, DB::table('webhook_messages')->count());
    }

    public function testTheArgumentHashIgnoresKeyOrderButNotValues(): void
    {
        $a = ConfirmTokens::argsHash(['b' => 2, 'a' => ['y' => 1, 'x' => '2']]);
        $b = ConfirmTokens::argsHash(['a' => ['x' => '2', 'y' => '1'], 'b' => '2']);
        $c = ConfirmTokens::argsHash(['a' => ['x' => '2', 'y' => '1'], 'b' => '3']);
        $d = ConfirmTokens::argsHash(['list' => [1, 2]]);
        $e = ConfirmTokens::argsHash(['list' => [2, 1]]);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertNotSame($d, $e, 'list order is meaningful');
    }
}
