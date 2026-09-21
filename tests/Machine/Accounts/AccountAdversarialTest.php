<?php

/*
 * AccountAdversarialTest.php
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

use FireflyIII\Machine\Undo\OperationLog;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\Note;
use FireflyIII\Models\TransactionCurrency;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * Second adversarial review of the accounts family — each test is a defect that was found and fixed.
 *
 * @internal
 *
 * @coversNothing
 */
final class AccountAdversarialTest extends MachineTestCase
{
    use AccountFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->enableWrites();
    }

    public function testAWhitespaceOnlyNameIsRefusedNotStoredEmpty(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/accounts', ['name' => '   ', 'type' => 'expense']), 400, 'invalid_input');
        $this->assertArrayHasKey('name', $env['error']['details']['fields'] ?? [], (string) json_encode($env));
        $this->assertSame(0, Account::query()->where('name', '')->count());

        $checking = $this->checking();
        $env      = $this->assertPlaneError($this->machine('PUT', '/accounts/'.$checking['id'], ['name' => " \t "]), 400, 'invalid_input');
        $this->assertArrayHasKey('name', $env['error']['details']['fields'] ?? [], (string) json_encode($env));
        $this->assertSame($checking['name'], $this->accountModel($checking['id'])->name);

        $env      = $this->assertPlaneError($this->machine('POST', '/accounts/batch', ['accounts' => [['name' => 'Fine Payee', 'type' => 'expense'], ['name' => '  ', 'type' => 'expense']]]), 400, 'invalid_input');
        $this->assertArrayHasKey('accounts.1.name', $env['error']['details']['fields'] ?? [], (string) json_encode($env));
        $this->assertSame(0, Account::query()->where('name', 'Fine Payee')->count());
    }

    public function testANameWithAPercentSignResolvesFromTheUrl(): void
    {
        $account = $this->expense('Rate 5%20 Fund');
        $shown   = $this->envelope($this->machine('GET', '/accounts/'.rawurlencode('Rate 5%20 Fund')));
        $this->assertTrue($shown['ok'], (string) json_encode($shown));
        $this->assertSame($account['id'], $shown['data']['account']['id']);
    }

    public function testSwitchingToACreditCardNeedsTheMonthlyPaymentDate(): void
    {
        $checking = $this->checking();
        $env      = $this->assertPlaneError($this->machine('PUT', '/accounts/'.$checking['id'], ['account_role' => 'ccAsset']), 400, 'invalid_input');
        $this->assertSame('monthly_payment_date', $env['error']['details']['field'] ?? null, (string) json_encode($env));
        $ok       = $this->envelope($this->machine('PUT', '/accounts/'.$checking['id'], ['account_role' => 'ccAsset', 'monthly_payment_date' => '2026-01-15']));
        $this->assertTrue($ok['ok'], (string) json_encode($ok));
    }

    public function testReconcilePlanBeforeTheFirstTransactionStartsAtEnd(): void
    {
        $checking = $this->checking(); // opening balance dated 2026-01-01
        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/plan', ['end' => '2025-12-15', 'target_balance' => '0.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame('2025-12-15', $plan['data']['start']);
        $this->assertSame('2025-12-15', $plan['data']['end']);
        $this->assertSame('0.00', $plan['data']['start_balance']);
        $this->assertSame('0.00', $plan['data']['difference']);
        $this->assertSame([], $plan['data']['journal_ids']);
    }

    public function testPropertiesNameTheLiabilityTypeAsFireflyDoes(): void
    {
        $loan  = $this->makeAccount(['name' => 'Meridian Card', 'type' => 'debt', 'liability_direction' => 'credit']);
        $props = $this->envelope($this->machine('GET', '/accounts/'.$loan['id'].'/properties'));
        $this->assertSame('liabilities', $props['data']['type'], 'the same word AccountTransformer uses');
        $this->assertSame('debt', $props['data']['liability']['liability_type']);
        $this->assertSame('Debt', $props['data']['firefly_type']);

        $card  = $this->makeAccount(['name' => 'Northbank Visa', 'type' => 'liability', 'liability_type' => 'debt', 'liability_direction' => 'credit', 'opening_balance' => '1250.5', 'opening_balance_date' => '2026-01-01']);
        $liab  = $this->envelope($this->machine('GET', '/accounts/'.$card['id'].'/properties'))['data']['liability'];
        $this->assertSame('debt', $liab['liability_type']);
        $this->assertSame('1250.50', $liab['current_debt'], 'a money field at the currency\'s places (§14.1)');
        $this->assertSame('0.00', $props['data']['liability']['current_debt'], 'Firefly records a zero debt for a liability opened without a balance — a real "0.00", rendered at the currency\'s places');

        $checking = $this->checking();
        $props    = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/properties'));
        $this->assertSame('asset', $props['data']['type']);
        $this->assertNull($props['data']['liability']);
    }

    public function testUndoOfAnEditRemovesTheRowsTheEditCreated(): void
    {
        $grocer = $this->expense();
        $notes  = Note::query()->count();
        $metas  = AccountMeta::query()->where('account_id', $grocer['id'])->count();

        $plan   = $this->envelope($this->machine('PUT', '/accounts/'.$grocer['id'], ['notes' => 'the corner shop', 'account_number' => '7734']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame($notes, Note::query()->count(), 'a dry run creates nothing');
        $apply  = $this->envelope($this->machine('PUT', '/accounts/'.$grocer['id'], ['notes' => 'the corner shop', 'account_number' => '7734', 'dry_run' => false, 'confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame($notes + 1, Note::query()->count());
        $this->assertSame($metas + 1, AccountMeta::query()->where('account_id', $grocer['id'])->count());

        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame($notes, Note::query()->count(), 'undo removes the note the edit created');
        $this->assertSame($metas, AccountMeta::query()->where('account_id', $grocer['id'])->count(), 'undo removes the meta row the edit created');
        $this->assertNull($this->envelope($this->machine('GET', '/accounts/'.$grocer['id']))['data']['account']['notes']);
    }

    public function testReconcileCountsAForeignCurrencyJournalAtItsAmountInTheAccountsCurrency(): void
    {
        $checking = $this->checking(); // primary currency, opening 1000.00 on 2026-01-01
        $code     = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/balance'))['data']['currency_code'];
        $other    = TransactionCurrency::query()->where('code', '!=', $code)->orderBy('id')->firstOrFail();
        $other->update(['enabled' => true]);
        $abroad   = $this->makeAccount(['name' => 'Acme LLC · Meridian Abroad ••7734', 'type' => 'asset', 'account_role' => 'defaultAsset', 'currency_code' => $other->code]);
        // a transfer INTO checking booked in the other account's currency, with its amount in checking's currency beside it
        $journal  = $this->transfer((int) $abroad['id'], (int) $checking['id'], '40.00', '2026-02-10', $other, '36.50');

        $plan     = $this->envelope($this->machine('POST', '/accounts/'.$checking['id'].'/reconcile/plan', ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '1036.50']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $this->assertSame([$journal], $plan['data']['journal_ids']);
        $this->assertSame('36.50', $plan['data']['selected_sum'], 'the foreign amount, in the account\'s currency — as Json\\ReconcileController::processJournal() counts it');
        $this->assertSame('0.00', $plan['data']['difference']);
        $this->assertSame('1036.50', $plan['data']['end_balance']);
    }

    public function testAccountTransactionsArePagedPerGroupAndCarryATotal(): void
    {
        $checking = $this->checking();
        $grocer   = $this->expense();
        foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $date) {
            $this->withdrawal((int) $checking['id'], (int) $grocer['id'], '5.00', $date);
        }
        $first = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['start' => '2026-02-01', 'end' => '2026-02-28', 'limit' => '2']));
        $this->assertCount(2, $first['data']['transactions']);
        $this->assertSame(3, $first['data']['total']);
        $this->assertTrue($first['meta']['truncated']);
        $this->assertSame(2, $first['meta']['next_offset']);
        $this->assertSame('2026-02-03', substr((string) $first['data']['transactions'][0]['transactions'][0]['date'], 0, 10), 'newest first');
        $this->assertSame('5.00', $first['data']['transactions'][0]['transactions'][0]['amount'], 'amounts at the currency\'s places');
        $rest  = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['start' => '2026-02-01', 'end' => '2026-02-28', 'limit' => '2', 'offset' => '2']));
        $this->assertCount(1, $rest['data']['transactions']);
        $this->assertSame('2026-02-01', substr((string) $rest['data']['transactions'][0]['transactions'][0]['date'], 0, 10));
        $this->assertFalse($rest['meta']['truncated']);
        $asc   = $this->envelope($this->machine('GET', '/accounts/'.$checking['id'].'/transactions', ['start' => '2026-02-01', 'end' => '2026-02-28', 'order' => 'date', 'limit' => '1']));
        $this->assertSame('2026-02-01', substr((string) $asc['data']['transactions'][0]['transactions'][0]['date'], 0, 10));
    }
}
