<?php

/*
 * RawIngestTest.php
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

use FireflyIII\Machine\Ingest\Csv;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;

/**
 * Raw mode (cli.mdx §10) on the invented RAW tree:
 *   - September scanned twice with identical bytes      → duplicate_identical
 *   - October scanned twice, different bytes, one a subset → superseded by `more_rows`
 *   - acme savings November: two scans that disagree       → conflict, blocks the month; prefer resolves
 *   - a cycle-dated card (15th → 14th) with the same $5.00 coffee on 10/14 in two statements
 *     → ordinals 0 and 1 over the merged account-month, two ids, BOTH import
 *   - a re-run imports 0; the archive's own import/ directory is never used for staging
 *
 * @internal
 *
 * @coversNothing
 */
final class RawIngestTest extends IngestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useTree('raw');
    }

    public function testTheManifestIsSynthesizedFromTheTree(): void
    {
        $m    = $this->okData('GET', '/ingest/manifest', ['root' => $this->root]);
        $this->assertSame('raw', $m['mode']);
        $this->assertTrue($m['synthesized']);
        $keys = array_column($m['accounts'], 'key');
        $this->assertSame(['acme_llc/Northbank/Savings_x8812', 'household/Meridian/Card_x5150', 'household/Northbank/Checking_x4021'], $keys, 'import/ is the archive\'s, not an entity');
        $card = array_column($m['accounts'], null, 'key')['household/Meridian/Card_x5150'];
        $this->assertSame(['card', '5150'], [$card['kind'], $card['last4']]);
    }

    public function testLayerOneVerdicts(): void
    {
        $dupes  = $this->okData('GET', '/ingest/dupes', ['root' => $this->root]);
        $byFile = [];
        foreach ($dupes['groups'] as $g) {
            if ('statement' === $g['layer']) {
                $byFile[basename((string) $g['file'])] = $g;
            }
        }
        $this->assertSame('duplicate_identical', $byFile['20260930-statement-4021.pdf']['verdict'] === 'primary' ? $byFile['20260930-statement-4021-rescan.pdf']['verdict'] : $byFile['20260930-statement-4021.pdf']['verdict']);
        $this->assertSame('identical_bytes', $byFile['20260930-statement-4021.pdf']['rule'] ?? $byFile['20260930-statement-4021-rescan.pdf']['rule']);
        $this->assertSame('superseded', $byFile['20261031-statement-4021-phone.pdf']['verdict']);
        $this->assertSame('more_rows', $byFile['20261031-statement-4021-phone.pdf']['rule']);
        $this->assertSame('conflict', $byFile['20261130-statement-8812.pdf']['verdict']);
        $this->assertSame('conflict', $byFile['20261130-statement-8812-v2.pdf']['verdict']);
        $this->assertSame(1, $dupes['summary']['conflicts']);

        $scan   = $this->okData('POST', '/ingest/scan', ['root' => $this->root]);
        $this->assertSame('raw', $scan['mode']);
        $this->assertCount(3, $scan['duplicate_scans']);
        $this->assertSame([], $scan['unreadable']);
    }

    public function testTheCycleDatedCardKeepsBothCoffees(): void
    {
        $rows    = $this->okData('GET', '/ingest/rows', ['root' => $this->root, 'account' => '5150']);
        $coffees = array_values(array_filter($rows['rows'], static fn (array $r): bool => 'HARBOR COFFEE' === $r['description']));
        $this->assertCount(2, $coffees);
        $this->assertSame(['2026-10-14', '2026-10-14'], array_column($coffees, 'date'));
        $this->assertSame([0, 1], array_column($coffees, 'ordinal'), 'ordinals over the merged account-month, never per statement');
        $this->assertSame([2, 2], array_column($coffees, 'tie_group'));
        $this->assertNotSame($coffees[0]['external_id'], $coffees[1]['external_id']);
        $this->assertStringStartsWith('ff1:5150:20261014:-5:0:', $coffees[0]['external_id']);
        $this->assertSame(['claude', 'brew'], array_column($coffees, 'source_kind'));
        $payment = array_values(array_filter($rows['rows'], static fn (array $r): bool => 'PAYMENT THANK YOU' === $r['description']))[0];
        $this->assertSame('deposit', $payment['type'], 'a card statement prints charges positive: (300.00) is money in');

        $this->provisionAccounts();
        $apply   = $this->planAndApply(['accounts' => ['5150']]);
        $this->assertSame(5, $apply['changes']['created']);
        $stored  = TransactionJournal::query()->where('description', 'HARBOR COFFEE')->count();
        $this->assertSame(2, $stored, 'both genuine coffees import with distinct ids');
        $again   = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['5150']]);
        $this->assertSame(0, $again['change_count'], 're-run imports 0');
    }

    public function testConflictBlocksTheMonthUntilPreferred(): void
    {
        $this->provisionAccounts();
        $plan    = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['8812']]);
        $savings = $plan['accounts'][0];
        $this->assertTrue($savings['blocked']);
        $this->assertSame(1, $savings['conflicts']);
        $this->assertSame(0, $plan['change_count'], 'choosing silently is choosing which transactions exist');

        $this->assertPlaneError($this->machine('POST', '/ingest/prefer', ['root' => $this->root, 'path' => 'household/Northbank/Checking_x4021/2026/10/20261031-statement-4021.pdf']), 400, 'invalid_input');
        $pref    = $this->okData('POST', '/ingest/prefer', ['root' => $this->root, 'path' => $this->root.'/acme_llc/Northbank/Savings_x8812/2026/11/20261130-statement-8812-v2_claude.txt']);
        $this->assertTrue($pref['recorded']);
        $this->assertSame('acme_llc/Northbank/Savings_x8812/2026/11/20261130-statement-8812-v2.pdf', $pref['preferred']);
        $conflicts = Csv::decodeAssoc((string) file_get_contents($this->root.'/.firefly-staging/_conflicts.csv'));
        $resolved  = array_values(array_filter($conflicts, static fn (array $r): bool => 'resolved' === $r['status']));
        $this->assertCount(1, $resolved);

        $plan2   = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['8812']]);
        $this->assertSame(2, $plan2['change_count']);
        $this->okData('POST', '/ingest/apply', ['confirm_token' => $plan2['confirm_token'], 'dry_run' => false]);
        $interest = TransactionJournal::query()->where('description', 'INTEREST PAID')->with('transactions')->get();
        $this->assertCount(1, $interest);
        $this->assertSame('1.52', \FireflyIII\Machine\Money::strip((string) $interest->first()->transactions->firstWhere('amount', '>', 0)->amount));
    }

    public function testExtractWritesTheStagingTreeOnly(): void
    {
        $x = $this->okData('POST', '/ingest/extract', ['root' => $this->root]);
        $this->assertSame([], $x['empty']);
        $this->assertSame(1, $x['conflicts']);
        $this->assertGreaterThan(0, $x['rows']);
        $staging = $this->root.'/.firefly-staging';
        foreach (['_manifest.csv', '_rows.csv', '_dupes.csv', '_conflicts.csv', '_run.log', '.gitignore', '.firefly-staging.json'] as $f) {
            $this->assertFileExists($staging.'/'.$f);
        }
        $this->assertFileExists($staging.'/household/Meridian/Card_x5150/2026-10.csv');
        $month   = Csv::decodeAssoc((string) file_get_contents($staging.'/household/Meridian/Card_x5150/2026-10.csv'));
        $this->assertCount(3, $month, 'October: payment + two coffees (September\'s bookshop lives in 2026-09.csv)');
        $this->assertSame(['README.txt'], array_values(array_diff(scandir($this->root.'/import') ?: [], ['.', '..'])), 'the archive\'s import/ is untouched');

        $prepared = $this->root.'/import/accounts.csv';
        file_put_contents($prepared, "entity,institution,label,last4,kind,path\nhousehold,Northbank,Checking_x4021,4021,checking,household/Northbank/Checking_x4021\n");
        $this->assertPlaneError($this->machine('POST', '/ingest/extract', ['root' => $this->root]), 400, 'invalid_input');
    }

    public function testTheCanonicalRowHashIsFireflysOwn(): void
    {
        $this->provisionAccounts();
        $this->planAndApply(['accounts' => ['4021']]);
        $journal = TransactionJournal::query()->where('description', 'RENT EXAMPLE PROPERTIES')->firstOrFail();
        $meta    = TransactionJournalMeta::query()->where('transaction_journal_id', $journal->id)->pluck('data', 'name')->map(static fn ($v): string => (string) $v)->all();
        $this->assertStringStartsWith('ff1:4021:20261020:-1200:0:', $meta['external_id']);
        $this->assertSame('RENT EXAMPLE PROPERTIES', $meta['internal_reference']);
        $this->assertArrayHasKey('import_hash_v2', $meta);
        $this->assertSame('ff-machine-ingest|v1', $meta['original_source']);

        $account = (int) $journal->transactions()->where('amount', '<', 0)->value('account_id');
        $row     = ['date' => '2026-10-20', 'amount' => '-1200', 'description' => 'RENT EXAMPLE PROPERTIES', 'internal_reference' => 'RENT EXAMPLE PROPERTIES', 'external_id' => $meta['external_id']];
        $hash    = \FireflyIII\Machine\Ingest\CanonicalRow::hash(\FireflyIII\Machine\Ingest\CanonicalRow::fromStatementRow($row, $account, 'USD', 2));
        $this->assertSame($meta['import_hash_v2'], $hash, 'our canonical hash is exactly the hash Firefly stored');
    }
}
