<?php

/*
 * IngestEngineTest.php
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

use FireflyIII\Machine\Ingest\CanonicalRow;
use FireflyIII\Machine\Ingest\Normaliser;
use FireflyIII\Machine\Ingest\Progress;
use FireflyIII\Machine\Ingest\Staging;
use FireflyIII\Machine\Ingest\Values;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\TransactionGroup;

/**
 * The engine's pinned behaviour (apis.mdx §18): the canonical hash's golden vectors, the
 * description rule table, determinism of the staging tree, the prepared-mode source set (a
 * lesser format is read when it is the only source of a month), NDJSON progress streaming, and
 * the provisioning acceptance case (27 rows, 5 entities, one shared last-4 → exactly one
 * `ambiguous`, zero silent creates, a re-run links).
 *
 * @internal
 *
 * @coversNothing
 */
final class IngestEngineTest extends IngestTestCase
{
    // ------------------------------------------------------------ golden vectors ---

    /**
     * ANY CHANGE TO THESE HASHES RE-KEYS EVERY FUTURE IMPORT (apis.mdx §11.6). The date is inside
     * the hash as Carbon's JSON form, so the vector is pinned under UTC.
     */
    public function testCanonicalRowGoldenVectors(): void
    {
        config(['app.timezone' => 'UTC']);
        $out = CanonicalRow::fromStatementRow(
            ['date' => '2026-09-03', 'amount' => '-5', 'description' => 'CORNER CAFE #12', 'internal_reference' => 'POS PURCHASE CORNER CAFE #12 XXXX4021', 'external_id' => 'ff1:4021:20260903:-5:1:2d846b86'],
            14,
            'USD',
            2,
        );
        $this->assertSame(
            ['type', 'date', 'amount', 'currency_code', 'description', 'source_id', 'destination_name', 'external_id', 'internal_reference'],
            array_keys($out),
            'KEY_ORDER, absent keys omitted, nothing the rule engine decides, no notes',
        );
        $this->assertSame('5.00', $out['amount'], 'positive, at the currency\'s places; direction is the type');
        $this->assertSame('withdrawal', $out['type']);
        $this->assertSame(14, $out['source_id']);
        $this->assertSame('CORNER CAFE #12', $out['destination_name']);
        $this->assertSame('8a8fd17f60ee684c668604622261b4c5'.'50c2698b131e36ada794f98c0755f72b', CanonicalRow::hash($out));

        $in  = CanonicalRow::fromStatementRow(
            ['date' => '2026-09-15', 'amount' => '2500', 'description' => '  PAYROLL   EXAMPLE CO DIRECT DEP ', 'internal_reference' => 'PAYROLL EXAMPLE CO DIRECT DEP', 'external_id' => 'ofx:4021:NB202609150001'],
            14,
            'USD',
            2,
        );
        $this->assertSame('deposit', $in['type']);
        $this->assertSame('PAYROLL EXAMPLE CO DIRECT DEP', $in['description'], 'whitespace is squashed: a trailing space cannot re-key a row');
        $this->assertSame(14, $in['destination_id']);
        $this->assertSame('4a497998ca5bd4ee3d5d23a2950e0ced'.'efe30b266cae48cb5a2135b58225b12b', CanonicalRow::hash($in));

        // the same row with keys in another order, or with rule-set fields, hashes the same
        $shuffled = array_reverse($out, true);
        $this->assertSame(CanonicalRow::hash($out), CanonicalRow::hash(CanonicalRow::fromStatementRow(
            ['external_id' => 'ff1:4021:20260903:-5:1:2d846b86', 'internal_reference' => 'POS PURCHASE CORNER CAFE #12 XXXX4021', 'description' => 'CORNER CAFE #12', 'amount' => '-5.00', 'date' => '2026-09-03', 'category_name' => 'Coffee', 'budget_id' => 3],
            14,
            'USD',
            2,
        )), 'key order of the input, a category or a budget cannot change the hash');
        $this->assertNotSame(array_keys($shuffled), array_keys($out));
        $factory = CanonicalRow::forFactory($out);
        $this->assertSame('ff-machine-ingest|v1', $factory['original_source']);
        $this->assertSame('"2026-09-03T00:00:00.000000Z"', json_encode($factory['date'], JSON_THROW_ON_ERROR), 'the date reaches the hash as Carbon\'s JSON form');
    }

    public function testDescriptionRuleTableAndIdDigest(): void
    {
        $this->assertSame('GREEN GROCER', Normaliser::description('POS PURCHASE GREEN GROCER XXXX4021', 'Northbank'));
        $this->assertSame('CORNER CAFE #12', Normaliser::description('NB PURCHASE AUTHORIZED ON 09/03 CORNER CAFE #12 REF 12345678901 09/04', 'Northbank'), 'per-bank prefix, bank noise, reference numbers and a trailing date are stripped');
        $this->assertSame('(no description)', Normaliser::description('   ', null));
        $this->assertSame('2d846b86', Normaliser::sha1_8('CORNER CAFE #12'));
        $this->assertSame(Normaliser::sha1_8('corner  cafe #12'), Normaliser::sha1_8('CORNER CAFE #12'), 'the digest is over the case-folded, single-spaced key');
        $this->assertSame('-1234.56', Values::amount('($1,234.56)'));
        $this->assertSame('-12.5', Values::amount('12.50 DR'));
        $this->assertSame('1234.56', Values::amount('1.234,56'));
        $this->assertNull(Values::amount('1e3'), 'nothing is ever rounded into existence');
        $this->assertSame(30, Values::daysBetween('2026-09-01', '2026-10-01'));
        $this->assertSame(['2026-11', '2026-12', '2027-01'], Values::months('2026-11-15', '2027-01-02'));
    }

    // -------------------------------------------------------------- determinism ---

    public function testTheStagingTreeIsDeterministic(): void
    {
        $this->useTree('raw');
        $this->okData('POST', '/ingest/extract', ['root' => $this->root]);
        $rows  = (string) file_get_contents($this->root.'/.firefly-staging/_rows.csv');
        $dupes = (string) file_get_contents($this->root.'/.firefly-staging/_dupes.csv');
        $this->assertStringContainsString('ff1:5150:20261014:-5:1:', $rows, 'the second coffee carries ordinal 1');
        $this->okData('POST', '/ingest/extract', ['root' => $this->root, 'force' => true]);
        $this->assertSame($rows, (string) file_get_contents($this->root.'/.firefly-staging/_rows.csv'), '_rows.csv is byte-identical across runs');
        $this->assertSame($dupes, (string) file_get_contents($this->root.'/.firefly-staging/_dupes.csv'));
        $this->assertSame(2, count(array_filter(explode("\n", (string) file_get_contents($this->root.'/.firefly-staging/_run.log')))), 'the run log is append-only');
    }

    // ----------------------------------------------------- prepared source set ---

    public function testALesserFormatIsReadWhenItIsTheOnlySourceOfAMonth(): void
    {
        $this->useTree('prepared');
        $dir = $this->root.'/bank/household/Northbank/Checking_x4021';
        file_put_contents($dir.'/Checking_x4021_2026-08.csv', "Date,Description,Amount\n2026-08-12,HARDWARE STORE EXAMPLE,-31.40\n2026-08-12,HARDWARE STORE EXAMPLE,-31.40\n");

        $scan = $this->okData('POST', '/ingest/scan', ['root' => $this->root]);
        $checking = $scan['accounts'][0];
        $this->assertSame(4, $checking['statements'], 'the August CSV is read: nothing else covers August');
        $this->assertSame(1, $checking['not_chosen'], 'the September CSV is still not chosen: the September OFX covers it');
        $this->assertSame(7, $checking['rows']);

        $dupes = $this->okData('GET', '/ingest/dupes', ['root' => $this->root, 'account' => '4021']);
        $notChosen = array_values(array_filter($dupes['groups'], static fn (array $g): bool => 'not_chosen' === $g['verdict']));
        $this->assertCount(1, $notChosen);
        $this->assertStringEndsWith('Checking_x4021_2026-09.csv', $notChosen[0]['file']);
        $this->assertSame('better_format_present', $notChosen[0]['rule']);

        $rows = $this->okData('GET', '/ingest/rows', ['root' => $this->root, 'account' => '4021', 'end' => '2026-08-31']);
        $this->assertSame(2, $rows['total']);
        $this->assertSame([0, 1], array_column($rows['rows'], 'ordinal'), 'two identical rows in one CSV are two transactions');
        $this->assertSame(['csv', 'csv'], array_column($rows['rows'], 'source_kind'));
        $this->assertNotSame($rows['rows'][0]['external_id'], $rows['rows'][1]['external_id']);

        // a combined file over the monthly files of its format, unless a monthly file has a month of its own
        file_put_contents($dir.'/Checking_x4021_ALL.csv', "Date,Description,Amount\n2026-08-12,HARDWARE STORE EXAMPLE,-31.40\n2026-11-03,NOVEMBER ONLY IN ALL,-1.00\n");
        file_put_contents($dir.'/Checking_x4021_2026-07.csv', "Date,Description,Amount\n2026-07-01,JULY ONLY IN MONTHLY,-2.00\n");
        $scan2 = $this->okData('POST', '/ingest/scan', ['root' => $this->root]);
        $rules = array_column(array_filter($this->okData('GET', '/ingest/dupes', ['root' => $this->root, 'account' => '4021'])['groups'], static fn (array $g): bool => 'not_chosen' === $g['verdict']), 'rule', 'file');
        $this->assertSame('combined_file_preferred', $rules['bank/household/Northbank/Checking_x4021/Checking_x4021_2026-08.csv'], 'August is now covered by the combined file');
        $this->assertArrayNotHasKey('bank/household/Northbank/Checking_x4021/Checking_x4021_2026-07.csv', $rules, 'July exists only in its monthly file, so it is read');
        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], $this->okData('GET', '/ingest/coverage', ['root' => $this->root, 'account' => '4021'])['accounts'][0]['present']);
        $this->assertSame(5, $scan2['accounts'][0]['statements']);

        // the plan says what it read and warns about the one way a prepared tree still doubles a month
        file_put_contents($this->root.'/bank/acme_llc/Northbank/Checking_x7734/Checking_x7734_2026-09b.csv', "Date,Description,Amount\n2026-09-01,SOFTWARE SUBSCRIPTION EXAMPLE,-12.00\n2026-10-02,OCTOBER ONLY HERE,-3.00\n");
        $this->provisionAccounts();
        $plan  = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $byKey = array_column($plan['accounts'], null, 'key');
        $this->assertStringContainsString('only source of 2026-07', implode("\n", $byKey['household/Northbank/Checking_x4021']['warnings']));
        $this->assertStringContainsString('overlap', implode("\n", $byKey['acme_llc/Northbank/Checking_x7734']['warnings']));
        $this->assertSame([], $byKey['household/Meridian/Mortgage']['warnings']);
    }

    // ----------------------------------------------------------- NDJSON progress ---

    public function testTheLongRoutesStreamNdjsonProgressWhenAsked(): void
    {
        $this->useTree('prepared');
        $this->provisionAccounts();
        $plan     = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $response = $this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false], ['Accept' => 'application/x-ndjson, application/json']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/x-ndjson', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $lines    = array_values(array_filter(explode("\n", $response->streamedContent()), static fn (string $l): bool => '' !== trim($l)));
        $this->assertGreaterThanOrEqual(3, count($lines), 'progress lines, then the envelope');
        $phases   = [];
        foreach (array_slice($lines, 0, -1) as $line) {
            $row = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            $this->assertArrayHasKey('progress', $row, $line);
            $phases[$row['progress']['phase']] = true;
            $this->assertIsInt($row['progress']['done']);
        }
        $this->assertArrayHasKey('planning', $phases, 'the recheck of the plan reports itself');
        $this->assertArrayHasKey('storing', $phases);
        $this->assertArrayHasKey('finishing', $phases);
        $final    = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($final['ok'], (string) end($lines));
        $this->assertSame(9, $final['data']['changes']['created']);
        $this->assertFalse($final['data']['dry_run']);
        $this->assertSame(9, TransactionGroup::query()->count(), 'the rows were stored');
        $this->assertFalse(Progress::active(), 'the sink is removed after the call');

        // a refusal while streaming ends with an error envelope as the last line
        $stale    = $this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false], ['Accept' => 'application/x-ndjson']);
        $this->assertSame(200, $stale->getStatusCode(), 'the status was sent before the verdict was known; the envelope carries it');
        $lines    = array_values(array_filter(explode("\n", $stale->streamedContent()), static fn (string $l): bool => '' !== trim($l)));
        $final    = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        $this->assertFalse($final['ok']);
        $this->assertSame('conflict', $final['error']['code'], 'a used token');

        // without the Accept header the same route answers plain JSON
        $plain    = $this->machine('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertStringContainsString('application/json', (string) $plain->headers->get('Content-Type'));
        $this->assertTrue($this->envelope($plain)['ok']);

        // extract streams too (raw mode)
        $this->useTree('raw');
        $x        = $this->machine('POST', '/ingest/extract', ['root' => $this->root], ['Accept' => 'application/x-ndjson']);
        $lines    = array_values(array_filter(explode("\n", $x->streamedContent()), static fn (string $l): bool => '' !== trim($l)));
        $this->assertStringContainsString('"extracting"', $lines[0]);
        $final    = json_decode((string) end($lines), true, 32, JSON_THROW_ON_ERROR);
        $this->assertTrue($final['ok']);
        $this->assertSame(1, $final['data']['conflicts']);
    }

    // ------------------------------------------------------- staging robustness ---

    public function testAnAppliedRunIsAnsweredEvenWhenItCannotBeLogged(): void
    {
        $this->useTree('prepared');
        $this->provisionAccounts();
        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        chmod($this->root.'/.firefly-staging/_runs', 0o500);

        try {
            $response = $this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
            $env      = $this->envelope($response);
            $this->assertTrue($env['ok'], (string) $response->getContent());
            $this->assertSame(9, $env['data']['changes']['created'], 'the ledger write stands');
            $this->assertNull($env['data']['run_id']);
            $this->assertStringContainsString('could not be logged', implode(' ', $env['meta']['warnings']));
        } finally {
            chmod($this->root.'/.firefly-staging/_runs', 0o700);
        }
        $this->assertSame(0, $this->okData('POST', '/ingest/plan', ['root' => $this->root])['change_count'], 'and a re-plan sees every row present');
    }

    public function testAPlanAnswersOnAReadOnlyArchive(): void
    {
        $this->useTree('prepared');
        $ids = $this->provisionAccounts();
        chmod($this->root.'/.firefly-staging', 0o500);
        chmod($this->root, 0o500);

        try {
            $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
            $this->assertSame(9, $plan['change_count']);
            $this->assertIsString($plan['confirm_token']);
            // the map (a read) still works; the run log (a write) does not, and the plan says so
            $this->assertCount(3, $ids);
        } finally {
            chmod($this->root, 0o700);
            chmod($this->root.'/.firefly-staging', 0o700);
        }
    }

    // ------------------------------------------------------------- provisioning ---

    public function testTwentySevenRowsFiveEntitiesOneSharedLast4(): void
    {
        $this->useTree('prepared');
        // two existing ledger accounts share the last-4 9001 — the normal case in a real archive
        $asset = AccountType::whereType('Asset account')->firstOrFail();
        foreach (['Old Personal Checking', 'Old Business Checking'] as $name) {
            $account = Account::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'account_type_id' => $asset->id, 'name' => $name, 'active' => true, 'virtual_balance' => '0']);
            AccountMeta::create(['account_id' => $account->id, 'name' => 'account_number', 'data' => '11119001']);
            AccountMeta::create(['account_id' => $account->id, 'name' => 'account_role', 'data' => 'defaultAsset']);
        }
        $entities = ['household', 'acme_llc', 'beta_corp', 'gamma_trust', 'delta_partners'];
        $kinds    = ['checking', 'savings', 'card', 'brokerage', 'loan', 'mortgage'];
        $rows     = [];
        $n        = 0;
        foreach ($entities as $e) {
            foreach ($kinds as $k) {
                if (27 === count($rows)) {
                    break;
                }
                ++$n;
                $last4  = 1 === $n ? '9001' : sprintf('%04d', 1000 + $n);
                $rows[] = ['entity' => $e, 'institution' => 0 === $n % 2 ? 'Northbank' : 'Meridian', 'label' => ucfirst($k).'_x'.$last4, 'last4' => $last4, 'kind' => $k, 'path' => sprintf('bank/%s/%s/%s_x%s', $e, 0 === $n % 2 ? 'Northbank' : 'Meridian', ucfirst($k), $last4)];
            }
        }
        $this->assertCount(27, $rows);
        unlink($this->root.'/import/accounts.csv');
        file_put_contents($this->root.'/import/accounts.json', json_encode(['accounts' => $rows]));
        $this->assertSame('import/accounts.json', $this->okData('GET', '/ingest/manifest', ['root' => $this->root])['manifest_path']);
        $plan = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 26, 'link' => 0, 'skip' => 0, 'ambiguous' => 1], $plan['summary'], 'exactly one ambiguous, zero silent creates');
        $amb  = array_values(array_filter($plan['plan'], static fn (array $p): bool => 'ambiguous' === $p['action']))[0];
        $this->assertSame('9001', $amb['manifest']['last4']);
        $this->assertCount(2, $amb['candidates']);
        $this->assertSame(26, $plan['change_count']);
        $this->assertSame(0, Account::query()->where('name', 'like', '%·%')->count(), 'the plan creates nothing');

        $apply = $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(26, $apply['changes']['created']);
        $this->assertSame(1, $apply['changes']['blocked']);
        $this->assertSame(26, Account::query()->where('name', 'like', '%·%')->count());
        $mortgages = Account::query()->where('name', 'like', '%Mortgage%')->with('accountType')->get();
        $this->assertGreaterThan(0, $mortgages->count());
        foreach ($mortgages as $m) {
            $this->assertSame('Mortgage', $m->accountType->type);
        }

        // re-run with the map gone: everything links by its stable name, the shared last-4 is still ambiguous
        unlink($this->root.'/.firefly-staging/_map.json');
        $again = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 0, 'link' => 26, 'skip' => 0, 'ambiguous' => 1], $again['summary']);
        $this->assertSame(26, $again['change_count'], 'links count as changes (the map is written)');

        // resolve the ambiguity by hand: the row maps, and the plan then skips it
        $pick = (int) Account::query()->where('name', 'Old Business Checking')->value('id');
        $put  = $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['last4' => '9001', 'account_id' => $pick]]]);
        $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['last4' => '9001', 'account_id' => $pick]], 'dry_run' => false, 'confirm_token' => $put['confirm_token']]);
        $third = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 0, 'link' => 26, 'skip' => 1, 'ambiguous' => 0], $third['summary']);
    }

    public function testANameTakenByAnotherMappedRowIsAmbiguousNeverATwin(): void
    {
        $this->useTree('prepared');
        $ids   = $this->provisionAccounts();
        $house = $ids['household/Northbank/Checking_x4021'];
        // point acme's row at household's account, and unmap household's own row
        $put   = $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['label' => 'Checking_x7734', 'account_id' => $house], ['label' => 'Checking_x4021', 'account_id' => null]]]);
        $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['label' => 'Checking_x7734', 'account_id' => $house], ['label' => 'Checking_x4021', 'account_id' => null]], 'dry_run' => false, 'confirm_token' => $put['confirm_token']]);

        $plan  = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $byKey = array_column($plan['plan'], null, 'key');
        $this->assertSame('ambiguous', $byKey['household/Northbank/Checking_x4021']['action']);
        $this->assertStringContainsString('already mapped to another manifest row', $byKey['household/Northbank/Checking_x4021']['reason']);
        $this->assertSame($house, $byKey['household/Northbank/Checking_x4021']['candidates'][0]['id']);
        $this->assertSame(['create' => 0, 'link' => 0, 'skip' => 2, 'ambiguous' => 1], $plan['summary']);
        $apply = $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(0, $apply['change_count']);
        $this->assertSame(3, Account::query()->where('name', 'like', '%·%')->count(), 'no twin, no silent re-link');
    }

    public function testStagingSegmentsAndPathsAreContained(): void
    {
        $this->useTree('raw');
        $staging = new Staging($this->root);
        $this->assertSame('Checking_x4021', Staging::segment('Checking x4021'));
        $this->assertSame('_', Staging::segment('…'));
        $this->expectExceptionMessage('escaped the staging directory');
        $staging->path('../outside.csv');
    }
}
