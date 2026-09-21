<?php

/*
 * FormatsIngestTest.php
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

namespace Tests\Machine\Ingest;

use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;

/**
 * The other prepared formats — apis.mdx §11.1 (camt.053 .xml, .qfx) read off a JSON manifest
 * (§11.2), and the §12.4 extras a manifest may carry: an opening balance (stored as Firefly's own
 * opening-balance transaction) and a card's monthly payment date.
 *
 * Fixture: tests/Machine/Ingest/Fixtures/prepared_formats — invented entities household /
 * acme_llc at the bank Meridian, last-4 3308 (camt savings) and 9901 (QFX card).
 *
 * @internal
 *
 * @coversNothing
 */
final class FormatsIngestTest extends IngestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useTree('prepared_formats');
    }

    public function testAJsonManifestIsReadAndReportsItsOptionalColumns(): void
    {
        $roots = $this->okData('GET', '/ingest/roots');
        $this->assertSame('import/accounts.json', $roots['roots'][0]['manifest']);
        $m     = $this->okData('GET', '/ingest/manifest', ['root' => $this->root]);
        $this->assertSame('prepared', $m['mode']);
        $this->assertFalse($m['synthesized']);
        $this->assertSame([], $m['missing_columns']);
        $byKey = array_column($m['accounts'], null, 'key');
        $this->assertSame(['camt'], $byKey['household/Meridian/Savings_x3308']['files']['formats']);
        $this->assertSame(['qfx'], $byKey['acme_llc/Meridian/Card_x9901']['files']['formats']);
        $this->assertSame('1500.00', $byKey['household/Meridian/Savings_x3308']['opening_balance']);
        $this->assertSame('2026-07-25', $byKey['acme_llc/Meridian/Card_x9901']['payment_date']);
        // an explicit manifest_path that is not there is a not_found, never a guess
        $this->assertPlaneError($this->machine('GET', '/ingest/manifest', ['root' => $this->root, 'manifest_path' => 'import/other.json']), 404, 'not_found');
    }

    public function testCamt053RowsAreMintedAndQfxRowsKeepTheBankIds(): void
    {
        $savings = $this->okData('GET', '/ingest/rows', ['root' => $this->root, 'account' => '3308']);
        $this->assertSame(3, $savings['total']);
        $byDesc  = array_column($savings['rows'], null, 'description');
        $this->assertSame(['deposit', '250', '2026-07-03'], [$byDesc['TRANSFER FROM CHECKING']['type'], $byDesc['TRANSFER FROM CHECKING']['amount'], $byDesc['TRANSFER FROM CHECKING']['date']]);
        $this->assertSame(['withdrawal', '40'], [$byDesc['ATM WITHDRAWAL MAIN ST']['type'], $byDesc['ATM WITHDRAWAL MAIN ST']['amount']], 'DBIT is money out');
        $this->assertSame('1.75', $byDesc['INTEREST PAID']['amount'], 'AddtlNtryInf is the text when there is no remittance line');
        $this->assertStringStartsWith('ff1:3308:20260720:-40:0:', $byDesc['ATM WITHDRAWAL MAIN ST']['external_id'], 'camt carries no FITID, so the id is minted');
        $this->assertSame('camt053', $byDesc['INTEREST PAID']['source_kind']);

        $card    = $this->okData('GET', '/ingest/rows', ['root' => $this->root, 'account' => 'Card_x9901']);
        $this->assertSame(2, $card['total']);
        $ids     = array_column($card['rows'], 'external_id');
        sort($ids);
        $this->assertSame(['ofx:9901:MC202607060001', 'ofx:9901:MC202607250001'], $ids, 'QFX is OFX: the bank\'s own ids, never minted over');
        $this->assertSame(['qfx', 'qfx'], array_column($card['rows'], 'source_kind'));

        $scan    = $this->okData('POST', '/ingest/scan', ['root' => $this->root]);
        $this->assertSame([], $scan['unreadable']);
        $this->assertSame([], $scan['missing_months']);
        $this->assertSame(5, array_sum(array_column($scan['accounts'], 'rows')));
    }

    public function testOpeningBalanceAndCardPaymentDateComeFromTheManifest(): void
    {
        $plan  = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 2, 'link' => 0, 'skip' => 0, 'ambiguous' => 0], $plan['summary']);
        $byKey = array_column($plan['plan'], null, 'key');
        $this->assertSame('savingAsset', $byKey['household/Meridian/Savings_x3308']['proposed']['account_role']);
        $this->assertSame(['ccAsset', 'monthlyFull', '2026-07-25'], [
            $byKey['acme_llc/Meridian/Card_x9901']['proposed']['account_role'],
            $byKey['acme_llc/Meridian/Card_x9901']['proposed']['credit_card_type'],
            $byKey['acme_llc/Meridian/Card_x9901']['proposed']['monthly_payment_date'],
        ]);
        $this->assertSame(0, Account::query()->where('name', 'like', '%Meridian%')->count(), 'the plan creates nothing');

        $apply = $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(2, $apply['changes']['created']);
        $ids   = array_column($apply['plan'], 'account_id', 'key');
        $savings = Account::query()->findOrFail($ids['household/Meridian/Savings_x3308']);
        $this->assertSame('Household · Meridian Savings ••3308', $savings->name);
        // Firefly's own opening-balance transaction, not a row we invented
        $opening = TransactionJournal::query()
            ->whereHas('transactions', static fn ($q) => $q->where('account_id', $savings->id))
            ->whereHas('transactionType', static fn ($q) => $q->where('type', 'Opening balance'))
            ->with('transactions')
            ->first()
        ;
        $this->assertNotNull($opening, 'the opening balance is stored as Firefly\'s own opening-balance transaction');
        $this->assertSame('2026-06-30', $opening->date->format('Y-m-d'));
        $this->assertSame('1500.00', \FireflyIII\Machine\Money::format((string) $opening->transactions->firstWhere('account_id', $savings->id)->amount, 2));
        $card    = Account::query()->findOrFail($ids['acme_llc/Meridian/Card_x9901']);
        $this->assertSame('2026-07-25', (string) $card->accountMeta()->where('name', 'cc_monthly_payment_date')->value('data'));
        $this->assertSame('ccAsset', (string) $card->accountMeta()->where('name', 'account_role')->value('data'));

        // the whole tree imports through the same path as any other prepared tree, and re-runs to 0
        $done  = $this->planAndApply();
        $this->assertSame(5, $done['changes']['created']);
        $meta  = TransactionJournalMeta::query()->where('name', 'external_id')->pluck('data')->map(static fn ($d): string => (string) $d)->all();
        $this->assertContains('ofx:9901:MC202607060001', $meta);
        $again = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertSame(0, $again['change_count']);
        $this->assertSame(5, $again['changes']['duplicates']);
    }
}
