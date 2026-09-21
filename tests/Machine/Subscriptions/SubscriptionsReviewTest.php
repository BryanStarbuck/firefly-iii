<?php

/*
 * SubscriptionsReviewTest.php
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
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Recurring\RecurringRepositoryInterface;
use FireflyIII\User;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * Regression tests from the adversarial review of the subscriptions family (pm/apis.mdx §8.6):
 * the administration is the scope (§4.9), never the operator's own rows; a bad argument is
 * invalid_input, never a 500; an error message never rewrites the caller's own name; a bare
 * trigger advances past occurrences already created; a deleted piggy bank comes back whole.
 *
 * @internal
 *
 * @coversNothing
 */
final class SubscriptionsReviewTest extends MachineTestCase
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
        $this->payroll($this->user, $this->checking, '5000.00', Carbon::now(config('app.timezone'))->startOfMonth()->format('Y-m-d'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------ scope (§4.9) ---

    public function testAnotherMembersRowsInTheSameAdministrationAreListed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', config('app.timezone')));
        $member   = $this->member($this->user);
        $memberAc = $this->checking($member, 'Northbank Joint 4022');
        $theirs   = $this->bill($member, 'Water utility', '30.00', '2026-01-15');
        $mine     = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $piggy    = $this->piggy($member, $memberAc, 'Member savings', '250.00', '25.00');
        $rec      = $this->recurrence($member, 'Member rent', $memberAc);
        config(['machine.operator' => $this->user->email]);

        $list     = $this->envelope($this->machine('GET', '/subscriptions', ['start' => '2026-09-01', 'end' => '2026-09-30']));
        $this->assertTrue($list['ok'], (string) json_encode($list));
        $this->assertSame([$mine->id, $theirs->id], array_column($list['data']['subscriptions'], 'id'), 'the administration\'s subscriptions, whoever entered them');

        $status   = $this->envelope($this->machine('GET', '/subscriptions/status', ['start' => '2026-09-01', 'end' => '2026-09-30']));
        $this->assertContains('Water utility', array_column($status['data']['subscriptions'], 'name'));
        $this->assertSame(2, $status['data']['totals'][0]['subscriptions']);

        $piggies  = $this->envelope($this->machine('GET', '/piggy-banks'));
        $this->assertContains($piggy->id, array_column($piggies['data']['piggy_banks'], 'id'));

        $recs     = $this->envelope($this->machine('GET', '/recurrences'));
        $this->assertContains($rec, array_column($recs['data']['recurrences'], 'id'));
        $this->assertSame($rec, $this->envelope($this->machine('GET', '/recurrences/'.$rec))['data']['recurrence']['id']);
    }

    public function testAnotherAdministrationIsInvisibleEverywhere(): void
    {
        $stranger   = $this->stranger();
        $their      = $this->checking($stranger, 'Meridian Savings 7734');
        $theirBill  = $this->bill($stranger, 'Their gym', '40.00', '2026-01-03');
        $theirPiggy = $this->piggy($stranger, $their, 'Their car', '900.00', '10.00');
        $theirRec   = $this->recurrence($stranger, 'Their rent', $their);
        $mine       = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '100.00');
        config(['machine.operator' => $this->user->email]);
        $this->enableWrites();

        $this->assertPlaneError($this->machine('GET', '/subscriptions/'.$theirBill->id), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/subscriptions/Their%20gym'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/subscriptions/'.$theirBill->id.'/transactions'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/piggy-banks/'.$theirPiggy->id), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/piggy-banks/'.$theirPiggy->id.'/events'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/recurrences/'.$theirRec), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/recurrences/'.$theirRec.'/transactions'), 404, 'not_found');
        $this->assertPlaneError($this->machine('PUT', '/subscriptions/'.$theirBill->id, ['name' => 'x']), 404, 'not_found');
        $this->assertPlaneError($this->machine('PUT', '/recurrences/'.$theirRec, ['recurrence' => ['title' => 'x']]), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/recurrences/'.$theirRec.'/trigger'), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/'.$theirPiggy->id.'/add', ['amount' => '1.00']), 404, 'not_found');
        // my piggy bank, their account: refused, and nothing about their account is echoed
        $env = $this->assertPlaneError($this->machine('POST', '/piggy-banks/'.$mine->id.'/add', ['amount' => '1.00', 'account_id' => (string) $their->id]), 404, 'not_found');
        $this->assertStringNotContainsString('Meridian', (string) json_encode($env));
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => [(string) $their->id]]), 404, 'not_found');
        $this->assertPlaneError($this->machine('PUT', '/piggy-banks/'.$mine->id, ['accounts' => [(string) $this->checking->id, (string) $their->id]]), 404, 'not_found');
        $this->assertSame([$mine->id], array_column($this->envelope($this->machine('GET', '/piggy-banks'))['data']['piggy_banks'], 'id'));
        $this->assertSame([], $this->envelope($this->machine('GET', '/recurrences'))['data']['recurrences']);
        $this->assertSame([], $this->envelope($this->machine('GET', '/subscriptions'))['data']['subscriptions']);
    }

    // --------------------------------------------------------------- input ---

    public function testANonScalarAccountReferenceIsInvalidInputNotAnInternalError(): void
    {
        $this->enableWrites();
        $piggy = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '100.00');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/'.$piggy->id.'/add', ['amount' => '1.00', 'account_id' => [(string) $this->checking->id]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/'.$piggy->id.'/remove', ['amount' => '1.00', 'account_name' => ['Northbank']]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks/'.$piggy->id.'/add', ['amount' => '1.00', 'account_id' => true]), 400, 'invalid_input');
        // a JSON number for the id is fine (ids are the contract)
        $plan  = $this->envelope($this->machine('POST', '/piggy-banks/'.$piggy->id.'/add', ['amount' => '1.00', 'account_id' => $this->checking->id]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
    }

    public function testAnErrorNeverRewritesTheCallersOwnName(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/subscriptions/water%20bill'), 404, 'not_found');
        $this->assertStringContainsString('"water bill"', $env['error']['message']);
        $this->assertStringNotContainsString('bill ', $env['error']['message']);
        $this->assertSame('water bill', $env['error']['details']['name']);

        $this->bill($this->user, 'Water bill', '5.00', '2026-01-01');
        $this->bill($this->user, 'water bill', '6.00', '2026-01-02');
        $env = $this->assertPlaneError($this->machine('GET', '/subscriptions/WATER%20BILL'), 400, 'invalid_input');
        $this->assertStringContainsString('"WATER BILL"', $env['error']['message']);
        $this->assertStringContainsString('subscription', $env['error']['message']);
        $this->assertCount(2, $env['error']['details']['candidates']);
    }

    public function testATypoInsideASplitOrRepetitionIsRefusedNotDropped(): void
    {
        // upstream validates the nested keys it knows and silently drops the rest (§5.7, one level down)
        $this->enableWrites();
        $bad = $this->rentShape('Rent', $this->checking);
        $bad['transactions'][0]['categroy_name'] = 'Housing';
        $env = $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => $bad]), 400, 'invalid_input');
        $this->assertSame(['categroy_name'], $env['error']['details']['unknown']);
        $this->assertSame('recurrence.transactions.0', $env['error']['details']['field']);
        $bad = $this->rentShape('Rent', $this->checking);
        $bad['repetitions'][0]['momment'] = '1';
        $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => $bad]), 400, 'invalid_input');
        $bad = $this->rentShape('Rent', $this->checking);
        $bad['repetitions'] = ['monthly'];
        $this->assertPlaneError($this->machine('POST', '/recurrences', ['recurrence' => $bad]), 400, 'invalid_input');
        $this->assertSame(0, Recurrence::query()->count());

        $id  = $this->recurrence($this->user, 'Rent');
        $this->assertPlaneError($this->machine('PUT', '/recurrences/'.$id, ['recurrence' => ['transactions' => [['id' => '1', 'ammount' => '5.00']]]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/recurrences/'.$id, ['count' => '0']), 400, 'invalid_input');
    }

    public function testMoreRefusalsAreInvalidInputWithTheField(): void
    {
        $this->enableWrites();
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        // only the flag: nothing else is sent to upstream, and the flag lands
        $done = $this->apply('PUT', '/subscriptions/'.$bill->id, ['active' => false]);
        $this->assertFalse($done['data']['subscription']['active']);
        $this->assertFalse((bool) $bill->refresh()->active);

        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => [['account_id' => (string) $this->checking->id, 'current_amount' => '-5.00']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => [['account_id' => (string) $this->checking->id, 'current_amount' => 5]]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => [['account_id' => (string) $this->checking->id, 'colour' => 'red']]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => [(string) $this->checking->id], 'target_amount' => '-1.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/piggy-banks', ['name' => 'Car', 'accounts' => [(string) $this->checking->id], 'target_amount' => '1.001']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['name' => 'Gym', 'amount_min' => '0.00', 'amount_max' => '0.00', 'date' => '2026-09-10', 'repeat_freq' => 'monthly']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/subscriptions', ['name' => 'Gym', 'amount_min' => '10', 'amount_max' => '10', 'date' => '2026-09-10', 'repeat_freq' => 'monthly', 'skip' => -1]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/subscriptions', ['active' => 'maybe']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/recurrences', ['type' => 'Withdrawal']), 400, 'invalid_input');
        $this->assertSame(0, PiggyBank::query()->count());
    }

    public function testSubscriptionEditKeepsMinBelowMaxAgainstTheStoredPartner(): void
    {
        $this->enableWrites();
        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->assertPlaneError($this->machine('PUT', '/subscriptions/'.$bill->id, ['amount_min' => '50.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/subscriptions/'.$bill->id, ['amount_max' => '1.00']), 400, 'invalid_input');
        $this->assertSame(0, bccomp('15.99', (string) $bill->refresh()->amount_min, 2));
    }

    // ------------------------------------------------------------- writes ---

    public function testRecurrenceDryRunLeavesNoCounterpartyAccountOrCategoryBehind(): void
    {
        $this->enableWrites();
        $before = $this->hashTables(['accounts', 'account_meta', 'categories']);
        $plan   = $this->envelope($this->machine('POST', '/recurrences', ['recurrence' => array_replace($this->rentShape('Rent', $this->checking), ['transactions' => [[
            'description' => 'Rent', 'amount' => '1200.00', 'currency_code' => 'USD', 'source_id' => (string) $this->checking->id,
            'destination_name' => 'Brand New Landlord', 'category_name' => 'Brand New Category',
        ]]])]));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('Brand New Landlord', $plan['data']['recurrence']['transactions'][0]['destination_name']);
        $this->assertSame($before, $this->hashTables(['accounts', 'account_meta', 'categories']), 'the counterparty the preview created was rolled back');
        $this->assertSame(0, Account::query()->where('name', 'Brand New Landlord')->count());
    }

    public function testABareTriggerAdvancesPastOccurrencesAlreadyCreated(): void
    {
        $this->enableWrites();
        $id     = $this->recurrence($this->user, 'Rent');
        $first  = Carbon::now(config('app.timezone'))->startOfMonth()->addMonth()->startOfDay();
        $groups = TransactionGroup::query()->count();

        $one    = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger'));
        $this->assertTrue($one['ok'], (string) json_encode($one));
        $this->assertSame($first->format('Y-m-d'), $one['data']['occurrence_date']);

        // the next occurrence not yet created, not "already created" forever
        $two    = $this->envelope($this->machine('POST', '/recurrences/'.$id.'/trigger'));
        $this->assertTrue($two['ok'], (string) json_encode($two));
        $this->assertSame((clone $first)->addMonth()->format('Y-m-d'), $two['data']['occurrence_date']);
        $this->assertSame($groups + 2, TransactionGroup::query()->count());

        // an explicit date that was created is still a conflict, and the show route says which dates exist
        $this->assertPlaneError($this->machine('POST', '/recurrences/'.$id.'/trigger', ['date' => $first->format('Y-m-d')]), 409, 'conflict');
        $show   = $this->envelope($this->machine('GET', '/recurrences/'.$id, ['count' => 3]));
        $this->assertSame([$first->format('Y-m-d'), (clone $first)->addMonth()->format('Y-m-d')], $show['data']['recurrence']['created_dates']);
        $this->assertSame((clone $first)->addMonths(2)->format('Y-m-d'), $show['data']['recurrence']['next_uncreated_date']);
    }

    public function testUndoBringsADeletedPiggyBankBackWithItsMoneyAndEvents(): void
    {
        $this->enableWrites();
        $this->enableAdmin();
        $piggy = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '100.00');
        $this->apply('POST', '/piggy-banks/'.$piggy->id.'/add', ['amount' => '20.00']);
        $this->apply('DELETE', '/piggy-banks/'.$piggy->id, []);
        $this->assertNull(PiggyBank::query()->find($piggy->id));

        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['data']['reversible'], (string) json_encode($last['data']));
        $done = $this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]));
        $this->assertTrue($done['ok'], (string) json_encode($done));

        $env  = $this->envelope($this->machine('GET', '/piggy-banks/'.$piggy->id));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame('120.00', $env['data']['piggy_bank']['current_amount'], 'the saved amount is back');
        $events = $this->envelope($this->machine('GET', '/piggy-banks/'.$piggy->id.'/events'));
        $this->assertSame(['20.00'], array_column($events['data']['events'], 'amount'), 'so is the event');
    }

    public function testARetriedDeleteTerminatesWithDeletedZero(): void
    {
        // §5.6: a DELETE of something already gone is ok with deleted: 0 — including the retry that
        // re-sends the token the first apply consumed, so a retry loop ends instead of conflicting
        $this->enableWrites();
        $this->enableAdmin();
        $bill  = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $piggy = $this->piggy($this->user, $this->checking, 'Vacation', '1000.00', '0');
        $rec   = $this->recurrence($this->user, 'Rent');
        foreach (['/subscriptions/'.$bill->id, '/piggy-banks/'.$piggy->id, '/recurrences/'.$rec] as $path) {
            $plan  = $this->envelope($this->machine('DELETE', $path));
            $apply = ['dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']];
            $done  = $this->envelope($this->machine('DELETE', $path, $apply));
            $this->assertSame(1, $done['data']['deleted'], $path);
            $ops   = DB::table('machine_operations')->count();

            $retry = $this->envelope($this->machine('DELETE', $path, $apply));
            $this->assertTrue($retry['ok'], $path.': '.json_encode($retry));
            $this->assertSame(0, $retry['data']['deleted']);
            $this->assertSame(0, $retry['data']['change_count']);
            $bare  = $this->envelope($this->machine('DELETE', $path, ['dry_run' => false]));
            $this->assertTrue($bare['ok'], $path.': '.json_encode($bare));
            $this->assertSame(0, $bare['data']['deleted']);
            $this->assertArrayNotHasKey('confirm_token', $this->envelope($this->machine('DELETE', $path))['data'], 'nothing to plan');
            $this->assertSame($ops, DB::table('machine_operations')->count(), 'a no-op is not an operation');
        }
    }

    public function testUndoBringsADeletedRecurrenceAndSubscriptionBackWhole(): void
    {
        $this->enableWrites();
        $this->enableAdmin();
        $rec  = $this->recurrence($this->user, 'Rent');
        $shown = $this->envelope($this->machine('GET', '/recurrences/'.$rec))['data']['recurrence'];
        $this->apply('DELETE', '/recurrences/'.$rec, []);
        $this->assertNull(Recurrence::query()->find($rec));
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['data']['reversible'], (string) json_encode($last['data']));
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $back = $this->envelope($this->machine('GET', '/recurrences/'.$rec));
        $this->assertTrue($back['ok'], (string) json_encode($back));
        $this->assertSame($shown['transactions'], $back['data']['recurrence']['transactions'], 'the splits are back as they were');
        $this->assertSame($shown['repetitions'], $back['data']['recurrence']['repetitions']);
        $this->assertSame($shown['next_dates'], $back['data']['recurrence']['next_dates']);

        $bill = $this->bill($this->user, 'Streaming', '15.99', '2026-01-05');
        $this->transaction($this->user, 'withdrawal', $this->checking, 'Streaming', '15.99', Carbon::now(config('app.timezone'))->startOfMonth()->addDays(4)->format('Y-m-d'), 'Streamco', ['bill_id' => $bill->id]);
        $this->apply('DELETE', '/subscriptions/'.$bill->id, []);
        $this->assertNotNull(Bill::withTrashed()->find($bill->id)->deleted_at);
        $last = $this->envelope($this->machine('GET', '/undo/last'));
        $this->assertTrue($last['data']['reversible'], (string) json_encode($last['data']));
        $this->assertTrue($this->envelope($this->machine('POST', '/undo', ['confirm_token' => $last['data']['confirm_token']]))['ok']);
        $back = $this->envelope($this->machine('GET', '/subscriptions/'.$bill->id));
        $this->assertTrue($back['ok'], (string) json_encode($back));
        $this->assertSame('Streaming', $back['data']['subscription']['name']);
    }

    public function testStatusTotalsArePerCurrencyAndNeverSummedAcrossThem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 12:00:00', config('app.timezone')));
        $this->bill($this->user, 'Streaming', '15.99', '2026-01-28');
        $eur = TransactionCurrency::query()->where('code', 'EUR')->first() ?? TransactionCurrency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => 'E', 'decimal_places' => 2, 'enabled' => true]);
        $this->user->userGroup->currencies()->syncWithoutDetaching([$eur->id => ['group_default' => false]]);
        auth()->setUser($this->user);
        $repo = app(BillRepositoryInterface::class);
        $repo->setUser($this->user);
        $repo->store(['name' => 'EU magazine', 'amount_min' => '10.00', 'amount_max' => '10.00', 'currency_code' => 'EUR', 'date' => Carbon::parse('2026-01-28'), 'repeat_freq' => 'monthly', 'skip' => 0, 'active' => true]);

        $env    = $this->envelope($this->machine('GET', '/subscriptions/status', ['start' => '2026-09-01', 'end' => '2026-09-30']));
        $totals = array_column($env['data']['totals'], null, 'currency_code');
        $this->assertSame(['EUR', 'USD'], array_keys($totals));
        $this->assertSame('15.99', $totals['USD']['expected_amount']);
        $this->assertSame('10.00', $totals['EUR']['expected_amount']);
        foreach ($env['data']['totals'] as $t) {
            foreach (['expected_amount', 'paid_amount', 'unpaid_amount'] as $k) {
                $this->assertIsString($t[$k]);
            }
        }
    }

    // ------------------------------------------------------------ helpers ---

    /** A second member of the operator's administration. */
    private function member(User $owner): User
    {
        $member = User::create(['email' => 'member@household.example', 'password' => 'password', 'user_group_id' => $owner->user_group_id]);
        GroupMembership::create(['user_id' => $member->id, 'user_group_id' => $owner->user_group_id, 'user_role_id' => UserRole::query()->where('title', 'owner')->value('id')]);

        return $member;
    }

    /** A user in a different administration. */
    private function stranger(): User
    {
        $group = UserGroup::create(['title' => 'acme_llc']);
        $user  = User::create(['email' => 'books@acme.example', 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => UserRole::query()->where('title', 'owner')->value('id')]);
        $this->usd($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function rentShape(string $title, Account $source): array
    {
        $first = Carbon::now(config('app.timezone'))->startOfMonth()->addMonth();

        return [
            'type'              => 'withdrawal',
            'title'             => $title,
            'first_date'        => $first->format('Y-m-d'),
            'nr_of_repetitions' => 12,
            'repetitions'       => [['type' => 'monthly', 'moment' => '1']],
            'transactions'      => [['description' => $title, 'amount' => '1200.00', 'currency_code' => 'USD', 'source_id' => (string) $source->id, 'destination_name' => 'Meridian Property']],
        ];
    }

    /** A recurrence stored as $user through Firefly's own repository; returns its id. */
    private function recurrence(User $user, string $title, ?Account $source = null): int
    {
        auth()->setUser($user);
        $source ??= $this->checking;
        $shape = $this->rentShape($title, $source);
        /** @var AccountFactory $accounts */
        $accounts = app(AccountFactory::class);
        $accounts->setUser($user);
        $landlord = $accounts->findOrCreate('Meridian Property', 'Expense account');
        /** @var RecurringRepositoryInterface $repo */
        $repo  = app(RecurringRepositoryInterface::class);
        $repo->setUser($user);
        $rec   = $repo->store([
            'recurrence'   => [
                'type'         => $shape['type'],
                'title'        => $title,
                'description'  => '',
                'first_date'   => Carbon::parse($shape['first_date']),
                'repeat_until' => null,
                'repetitions'  => 12,
                'apply_rules'  => true,
                'active'       => true,
                'notes'        => '',
            ],
            'repetitions'  => [['type' => 'monthly', 'moment' => '1', 'skip' => 0, 'weekend' => 1]],
            'transactions' => [[
                'description'    => $title,
                'amount'         => '1200.00',
                'currency_code'  => 'USD',
                'currency_id'    => $this->usd($user)->id,
                'source_id'      => $source->id,
                'destination_id' => $landlord->id,
                'foreign_amount' => null,
                'tags'           => [],
            ]],
        ]);

        return (int) $rec->id;
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
}
