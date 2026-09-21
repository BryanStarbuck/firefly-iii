<?php

/*
 * SubscriptionUndoTest.php
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
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §7.5 for this family: what the subscription, piggy-bank and recurrence writes put
 * in the operation log is enough for POST /undo to reverse them — or, where it is not (the piggy
 * bank pivot has no model yet), undo REFUSES instead of half-reversing.
 *
 * @internal
 *
 * @coversNothing
 */
final class SubscriptionUndoTest extends MachineTestCase
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
        $this->payroll($this->user, $this->checking, '5000.00', Carbon::now()->startOfMonth()->format('Y-m-d'));
        $this->enableWrites();
    }

    public function testUndoRestoresAnEditedSubscription(): void
    {
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->apply('PUT', '/subscriptions/'.$bill->id, ['name' => 'Streaming Plus', 'amount_max' => '19.99']);
        $this->assertSame('Streaming Plus', $bill->refresh()->name);

        $this->undo();
        $bill->refresh();
        $this->assertSame('Streaming', $bill->name);
        $this->assertSame(0, bccomp('15.99', (string) $bill->amount_max, 2));
    }

    public function testUndoRemovesACreatedSubscription(): void
    {
        $done = $this->apply('POST', '/subscriptions', ['name' => 'Cloud storage', 'amount_min' => '9.99', 'amount_max' => '9.99', 'date' => '2026-09-10', 'repeat_freq' => 'monthly']);
        $this->assertNotNull(Bill::query()->find($done['data']['subscription']['id']));
        $this->undo();
        $this->assertNull(Bill::withTrashed()->find($done['data']['subscription']['id']));
    }

    public function testUndoRemovesWhatATriggerCreated(): void
    {
        $first  = Carbon::now(config('app.timezone'))->startOfMonth()->addMonth();
        $made   = $this->apply('POST', '/recurrences', ['recurrence' => [
            'type'              => 'withdrawal',
            'title'             => 'Rent',
            'first_date'        => $first->format('Y-m-d'),
            'nr_of_repetitions' => 12,
            'repetitions'       => [['type' => 'monthly', 'moment' => '1']],
            'transactions'      => [['description' => 'Rent', 'amount' => '1200.00', 'currency_code' => 'USD', 'source_id' => (string) $this->checking->id, 'destination_name' => 'Meridian Property']],
        ]]);
        $groups = TransactionGroup::query()->count();
        $id     = $made['data']['recurrence']['id'];

        $fired  = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger'));
        $this->assertTrue($fired['ok'], (string) json_encode($fired));
        $this->assertSame($groups + 1, TransactionGroup::query()->count());

        $this->undo();
        $this->assertSame($groups, TransactionGroup::query()->count(), 'the triggered transaction is gone');
        $this->assertNotNull(Recurrence::query()->find($id), 'the recurrence itself stays (that was an earlier operation)');
    }

    public function testUndoOfAPiggyBankMoveRefusesRatherThanHalfReversing(): void
    {
        $piggy = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '100.00');
        $this->apply('POST', '/piggy-banks/'.$piggy->id.'/add', ['amount' => '200.00']);

        $last  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['ok'], (string) json_encode($last));
        if (true === $last['data']['reversible']) {
            // the core has shipped a model for the account_piggy_bank pivot: undo must then restore the amount
            $this->undo();
            $this->assertSame(0, bccomp('100', (string) DB::table('account_piggy_bank')->where('piggy_bank_id', $piggy->id)->value('current_amount'), 12));
            $this->assertSame(0, PiggyBank::query()->find($piggy->id)?->piggyBankEvents()->count());

            return;
        }
        $this->assertNotEmpty($last['data']['blocked_by']);
        $this->assertNull($last['data']['confirm_token']);
        $this->assertSame(0, bccomp('300', (string) DB::table('account_piggy_bank')->where('piggy_bank_id', $piggy->id)->value('current_amount'), 12), 'nothing was half-undone');
    }

    /** @return array<string, mixed> the applied envelope */
    private function apply(string $method, string $path, array $body): array
    {
        $plan = $this->envelope($this->machine($method, $path, $body));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $done = $this->envelope($this->machine($method, $path, $body + ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));

        return $done;
    }

    private function undo(): void
    {
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['ok'], (string) json_encode($last));
        $this->assertTrue($last['data']['reversible'], (string) json_encode($last['data']));
        $done = $this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));
    }
}
