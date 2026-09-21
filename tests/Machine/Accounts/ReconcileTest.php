<?php

/*
 * ReconcileTest.php
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
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionJournal;
use Illuminate\Support\Facades\DB;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.2 reconciliation — plan (Firefly's start/end balance, the selected sum, the
 * operator's figure, the signed difference, a token), apply (journals marked reconciled; ONE
 * visible reconciliation transaction only when the difference is non-zero and asked for),
 * a stale plan refused, and unreconcile.
 *
 * @internal
 *
 * @coversNothing
 */
final class ReconcileTest extends MachineTestCase
{
    use AccountFixtures;

    private array $checking;
    private array $grocer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = $this->operatorUser();
        $this->enableWrites();
        $this->checking = $this->checking();                    // 1000.00 on 2026-01-01
        $this->grocer   = $this->expense();
        $this->withdrawal((int) $this->checking['id'], (int) $this->grocer['id'], '40.00', '2026-02-05', 'Feb shop 1');
        $this->withdrawal((int) $this->checking['id'], (int) $this->grocer['id'], '60.00', '2026-02-20', 'Feb shop 2');
    }

    private function path(string $suffix): string
    {
        return '/accounts/'.$this->checking['id'].'/reconcile/'.$suffix;
    }

    public function testPlanShowsFireflysFiguresAndChangesNothing(): void
    {
        $plan = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '900.00']));
        $this->assertTrue($plan['ok'], (string) json_encode($plan));
        $d    = $plan['data'];
        $this->assertSame('read', $plan['meta']['tier']);
        $this->assertTrue($plan['meta']['composed']);
        $this->assertSame('1000.00', $d['start_balance']);
        $this->assertSame('900.00', $d['end_balance']);
        $this->assertSame('-100.00', $d['selected_sum']);
        $this->assertSame('900.00', $d['target_balance']);
        $this->assertSame('0.00', $d['difference']);
        $this->assertSame(2, $d['selected_count']);
        $this->assertFalse($d['would_create_reconciliation']);
        $this->assertSame(['reconciled' => 2], $d['changes']);
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $d['confirm_token']);
        $this->assertSame(0, Transaction::query()->where('reconciled', true)->count(), 'a plan writes nothing');
    }

    public function testApplyMarksTheJournalsReconciledWithoutAPlugWhenTheDifferenceIsZero(): void
    {
        $plan    = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '900.00']));
        $journals = TransactionJournal::query()->count();

        // a dry run of apply (the token carries the plan) changes nothing
        $dry     = $this->envelope($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token']]));
        $this->assertTrue($dry['ok'], (string) json_encode($dry));
        $this->assertTrue($dry['data']['dry_run']);
        $this->assertSame(0, Transaction::query()->where('reconciled', true)->count());

        $apply   = $this->envelope($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false, 'create_reconciliation' => true]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['reconciled' => 2], $apply['data']['changes']);
        $this->assertNull($apply['data']['reconciliation_group_id']);
        $this->assertSame(4, Transaction::query()->where('reconciled', true)->count(), 'both sides of both journals');
        $this->assertSame($journals, TransactionJournal::query()->count(), 'no reconciliation transaction for a zero difference');

        // single use
        $this->assertPlaneError($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false]), 409, 'conflict');

        // the account's properties now know
        $props   = $this->envelope($this->machine('GET', '/accounts/'.$this->checking['id'].'/properties'));
        $this->assertSame('2026-02-20', $props['data']['last_reconciled_date']);
    }

    public function testANonZeroDifferenceCreatesOneVisibleReconciliationTransaction(): void
    {
        $plan  = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '895.50']));
        $this->assertSame('4.50', $plan['data']['difference'], 'Firefly holds 4.50 more than the statement');
        $this->assertTrue($plan['data']['would_create_reconciliation']);
        $this->assertSame(['reconciled' => 2, 'reconciliation_created' => 1], $plan['data']['changes']);
        $this->assertSame(['reconciled' => 2], $plan['data']['changes_without_reconciliation']);

        $apply = $this->envelope($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertNotNull($apply['data']['reconciliation_group_id']);
        $recon = TransactionJournal::query()->whereHas('transactionType', static fn ($q) => $q->where('type', 'Reconciliation'))->get();
        $this->assertCount(1, $recon);
        $balance = $this->envelope($this->machine('GET', '/accounts/'.$this->checking['id'].'/balance', ['as_of' => '2026-02-28']));
        $this->assertSame('895.50', $balance['data']['balance'], 'the account now matches the statement');

        // and the plane's undo takes it all back
        DB::transaction(static fn () => OperationLog::reverse($apply['data']['operation_id']));
        $this->assertSame(0, Transaction::query()->where('reconciled', true)->count());
        $balance = $this->envelope($this->machine('GET', '/accounts/'.$this->checking['id'].'/balance', ['as_of' => '2026-02-28']));
        $this->assertSame('900.00', $balance['data']['balance']);
    }

    public function testApplyCanDeclineTheReconciliationTransaction(): void
    {
        $plan  = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '910.00']));
        $this->assertSame('-10.00', $plan['data']['difference']);
        $dry   = $this->envelope($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'create_reconciliation' => false]));
        $this->assertSame(['reconciled' => 2], $dry['data']['changes']);
        $apply = $this->envelope($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false, 'create_reconciliation' => false]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(['reconciled' => 2], $apply['data']['changes']);
        $this->assertSame(0, TransactionJournal::query()->whereHas('transactionType', static fn ($q) => $q->where('type', 'Reconciliation'))->count());
    }

    public function testSelectedJournalsAndDefaults(): void
    {
        $first = (int) Transaction::query()->where('amount', '-40.000000000000')->value('transaction_journal_id');
        $plan  = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '960.00', 'journal_ids' => [$first]]));
        $this->assertSame('-40.00', $plan['data']['selected_sum']);
        $this->assertSame('0.00', $plan['data']['difference']);
        $this->assertSame([$first], $plan['data']['journal_ids']);

        // no start: from the first transaction; the opening balance is an uncleared row too
        $all   = $this->envelope($this->machine('POST', $this->path('plan'), ['end' => '2026-02-28', 'target_balance' => '900.00']));
        $this->assertSame('2026-01-01', $all['data']['start']);
        $this->assertSame('0.00', $all['data']['start_balance']);
        $this->assertSame(3, $all['data']['selected_count']);
        $this->assertSame('0.00', $all['data']['difference']);

        $this->assertPlaneError($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '900.00', 'journal_ids' => [99999]]), 400, 'invalid_input');
    }

    public function testPlanRefusesBadInput(): void
    {
        $this->assertPlaneError($this->machine('POST', $this->path('plan'), ['end' => '2026-02-28']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', $this->path('plan'), ['end' => '2026-02-28', 'target_balance' => 900]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', $this->path('plan'), ['end' => '2026-02-28', 'target_balance' => '900.001']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', $this->path('plan'), ['start' => '2026-03-01', 'end' => '2026-02-28', 'target_balance' => '1']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts/'.$this->grocer['id'].'/reconcile/plan', ['target_balance' => '1.00']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/accounts/424242/reconcile/plan', ['target_balance' => '1.00']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', $this->path('plan'), ['target_balance' => '1.00', 'dry_run' => true]), 400, 'invalid_input');
    }

    public function testApplyNeedsAPlanAndRefusesAStaleOne(): void
    {
        $this->assertPlaneError($this->machine('POST', $this->path('apply'), ['dry_run' => false]), 403, 'forbidden');
        $this->assertPlaneError($this->machine('POST', $this->path('apply'), ['confirm_token' => 'cf_'.str_repeat('0', 32)]), 409, 'conflict');

        $plan = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '900.00']));
        // the world moves: a new transaction lands in the period
        $this->withdrawal((int) $this->checking['id'], (int) $this->grocer['id'], '5.00', '2026-02-25', 'Late entry');
        $env  = $this->assertPlaneError($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false]), 409, 'conflict');
        $this->assertArrayHasKey('changes', $env['error']['details']);
        $this->assertSame(0, Transaction::query()->where('reconciled', true)->count(), 'nothing was written');

        // a plan applies only to its own account
        $other = $this->checking('Household · Meridian Savings ••7734', null);
        $plan  = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '895.00']));
        $this->assertPlaneError($this->machine('POST', '/accounts/'.$other['id'].'/reconcile/apply', ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false]), 403, 'forbidden');
    }

    public function testApplyNeedsTheWriteTier(): void
    {
        $plan = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '900.00']));
        config(['machine.allow_write' => false]);
        $this->assertPlaneError($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false]), 403, 'write_disabled');
    }

    public function testUnreconcileOneJournal(): void
    {
        $plan    = $this->envelope($this->machine('POST', $this->path('plan'), ['start' => '2026-02-01', 'end' => '2026-02-28', 'target_balance' => '900.00']));
        $this->envelope($this->machine('POST', $this->path('apply'), ['confirm_token' => $plan['data']['confirm_token'], 'dry_run' => false]));
        $jid     = $plan['data']['journal_ids'][0];
        $url     = '/accounts/'.$this->checking['id'].'/unreconcile/'.$jid;

        $dry     = $this->envelope($this->machine('POST', $url));
        $this->assertSame(['unreconciled' => 1], $dry['data']['changes']);
        $this->assertSame(4, Transaction::query()->where('reconciled', true)->count());
        $apply   = $this->envelope($this->machine('POST', $url, ['dry_run' => false, 'confirm_token' => $dry['data']['confirm_token']]));
        $this->assertTrue($apply['ok'], (string) json_encode($apply));
        $this->assertSame(2, Transaction::query()->where('reconciled', true)->count());
        $this->assertSame(0, Transaction::query()->where('transaction_journal_id', $jid)->where('reconciled', true)->count());

        $again   = $this->envelope($this->machine('POST', $url));
        $this->assertSame(['unchanged' => 1], $again['data']['changes']);

        $this->assertPlaneError($this->machine('POST', '/accounts/'.$this->checking['id'].'/unreconcile/99999'), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/accounts/'.$this->checking['id'].'/unreconcile/abc'), 400, 'invalid_input');
    }
}
