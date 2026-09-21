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
use FireflyIII\Machine\Transactions\Pivots\CategoryJournalRow;
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
 * in the operation log is enough for POST /undo to reverse them — the saved amounts of a piggy
 * bank (the account_piggy_bank pivot) and the links a triggered transaction creates included.
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

    public function testUndoRestoresAPiggyBankMove(): void
    {
        $piggy = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '100.00');
        $this->apply('POST', '/piggy-banks/'.$piggy->id.'/add', ['amount' => '200.00']);
        $this->assertSame(0, bccomp('300', (string) DB::table('account_piggy_bank')->where('piggy_bank_id', $piggy->id)->value('current_amount'), 12));
        $this->assertSame(1, PiggyBank::query()->find($piggy->id)?->piggyBankEvents()->count());

        $last  = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['ok'], (string) json_encode($last));
        $this->assertTrue($last['data']['reversible'], 'the saved-amount pivot row has a record class, so undo can write it back: '.json_encode($last['data']));
        $this->assertSame([], $last['data']['blocked_by']);
        $this->assertContains('account_piggy_bank_row', array_column($last['data']['effects'], 'entity'));

        $this->undo();
        $this->assertSame(0, bccomp('100', (string) DB::table('account_piggy_bank')->where('piggy_bank_id', $piggy->id)->value('current_amount'), 12), 'the amount is back');
        $this->assertSame(0, PiggyBank::query()->find($piggy->id)?->piggyBankEvents()->count(), 'the event is gone');
        $this->assertSame('100.00', $this->envelope($this->machine('GET', '/piggy-banks/'.$piggy->id))['data']['piggy_bank']['current_amount']);
    }

    public function testUndoRemovesACreatedPiggyBankAndItsLinks(): void
    {
        $done = $this->apply('POST', '/piggy-banks', ['name' => 'Laptop', 'accounts' => [(string) $this->checking->id], 'target_amount' => '900.00']);
        $id   = (int) $done['data']['piggy_bank']['id'];
        $this->assertSame(1, DB::table('account_piggy_bank')->where('piggy_bank_id', $id)->count());
        $this->undo();
        $this->assertNull(PiggyBank::withTrashed()->find($id));
        $this->assertSame(0, DB::table('account_piggy_bank')->where('piggy_bank_id', $id)->count(), 'the link row is gone too');
    }

    public function testUndoOfATriggerRemovesTheCategoryLinkAsWell(): void
    {
        $first  = Carbon::now(config('app.timezone'))->startOfMonth()->addMonth();
        $made   = $this->apply('POST', '/recurrences', ['recurrence' => [
            'type'              => 'withdrawal',
            'title'             => 'Gym',
            'first_date'        => $first->format('Y-m-d'),
            'nr_of_repetitions' => 12,
            'repetitions'       => [['type' => 'monthly', 'moment' => '3']],
            'transactions'      => [['description' => 'Gym', 'amount' => '40.00', 'currency_code' => 'USD', 'source_id' => (string) $this->checking->id, 'destination_name' => 'Fitco', 'category_name' => 'Health', 'tags' => ['fitness']]],
        ]]);
        $id     = $made['data']['recurrence']['id'];
        $links  = DB::table('category_transaction_journal')->count();
        $tagged = DB::table('tag_transaction_journal')->count();

        $fired  = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger'));
        $this->assertTrue($fired['ok'], (string) json_encode($fired));
        $this->assertSame('Health', $fired['data']['transactions'][0]['transactions'][0]['category_name']);
        $this->assertSame($links + 1, DB::table('category_transaction_journal')->count());
        $this->assertSame($tagged + 1, DB::table('tag_transaction_journal')->count());
        $op     = DB::table('machine_operations')->where('id', $fired['data']['operation_id'])->first();
        $this->assertContains(CategoryJournalRow::class, array_column(json_decode((string) $op->touched, true), 'class'), 'the category link is in the log');

        $this->undo();
        $this->assertSame($links, DB::table('category_transaction_journal')->count(), 'the category link went with the journal');
        $this->assertSame($tagged, DB::table('tag_transaction_journal')->count(), 'so did the tag link');
    }

    public function testDeletingSomethingAlreadyGoneLeavesNoOperationToUndo(): void
    {
        $this->enableAdmin();
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->apply('DELETE', '/subscriptions/'.$bill->id, []);
        $ops  = DB::table('machine_operations')->count();
        $done = $this->envelope($this->machine('DELETE', '/subscriptions/'.$bill->id, ['dry_run' => false])); // §5.6: answered directly, no plan, no token
        $this->assertTrue($done['ok'], (string) json_encode($done));
        $this->assertSame(0, $done['data']['deleted']);
        $this->assertArrayNotHasKey('operation_id', $done['data']);
        $this->assertSame($ops, DB::table('machine_operations')->count(), 'a no-op is not an operation');
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertSame('DELETE /subscriptions/{id}', $last['data']['route']);
        $this->assertContains('bill', array_column($last['data']['effects'], 'entity'), 'the real delete is still the last operation');
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
