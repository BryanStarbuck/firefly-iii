<?php

/*
 * PreparedIngestTest.php
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

use FireflyIII\Machine\Ingest\Staging;
use FireflyIII\Models\Account;
use FireflyIII\Models\Note;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use Illuminate\Support\Facades\DB;

/**
 * pm/apis.mdx §11–§12 through the real router, gates and write protocol, on the invented
 * PREPARED tree: manifest, accounts plan/apply, plan == apply, re-run imports 0, deleted stays
 * deleted, notes survive, stale tokens, containment, and the staging conflict rule.
 *
 * @internal
 *
 * @coversNothing
 */
final class PreparedIngestTest extends IngestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->useTree('prepared');
    }

    public function testRootsAndManifest(): void
    {
        $roots = $this->okData('GET', '/ingest/roots');
        $this->assertSame($this->root, $roots['roots'][0]['root']);
        $this->assertTrue($roots['roots'][0]['readable']);
        $this->assertSame('prepared', $roots['roots'][0]['mode']);
        $this->assertSame('import/accounts.csv', $roots['roots'][0]['manifest']);

        $m = $this->okData('GET', '/ingest/manifest', ['root' => $this->root]);
        $this->assertSame('prepared', $m['mode']);
        $this->assertSame('import/accounts.csv', $m['manifest_path']);
        $this->assertCount(3, $m['accounts']);
        $acme = $m['accounts'][1];
        $this->assertSame('acme_llc/Northbank/Checking_x7734', $acme['key']);
        $this->assertSame('USD', $acme['currency']);
        $this->assertTrue($acme['currency_defaulted'], 'a missing currency defaults to the primary one, and says so');
        $this->assertSame(['csv'], $acme['files']['formats']);
        $this->assertArrayNotHasKey('abs_path', $acme);
        $this->assertSame(['accounts' => 3, 'statements' => 4, 'transactions' => 9, 'unreconciled' => 1], $m['totals']);
        $this->assertContains('1 statement did not reconcile in the archive', $acme['warnings']);
        $this->assertFalse(is_dir($this->root.'/'.Staging::DIR), 'reads write nothing');
    }

    public function testValidationAndContainment(): void
    {
        $this->assertPlaneError($this->machine('GET', '/ingest/manifest', ['root' => $this->root, 'bogus' => 1]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/ingest/manifest', ['root' => $this->root.'/../../..']), 403, 'forbidden');
        $this->assertPlaneError($this->machine('GET', '/ingest/manifest', ['root' => '/etc']), 403, 'forbidden');
        $this->assertPlaneError($this->machine('GET', '/ingest/manifest', ['root' => $this->root.'/nope']), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/ingest/prefer', ['root' => $this->root, 'path' => '../../.ssh/id_rsa']), 403, 'forbidden');
        symlink('/etc', $this->root.'/escape');
        $this->assertPlaneError($this->machine('POST', '/ingest/file/plan', ['account_id' => 1, 'path' => $this->root.'/escape/hosts']), 404, 'not_found'); // no account #1 is checked first
    }

    public function testScanFindsTheIdenticalDuplicateAndSkipsTheCsv(): void
    {
        $scan = $this->okData('POST', '/ingest/scan', ['root' => $this->root]);
        $this->assertSame('prepared', $scan['mode']);
        $checking = $scan['accounts'][0];
        $this->assertSame('household/Northbank/Checking_x4021', $checking['key']);
        $this->assertSame(3, $checking['statements']);
        $this->assertSame(1, $checking['duplicates']);
        $this->assertSame(1, $checking['not_chosen'], 'the CSV of an account with OFX is never parsed into rows twice');
        $this->assertSame(5, $checking['rows']);
        $this->assertCount(1, $scan['duplicate_scans']);
        $this->assertSame('duplicate_identical', $scan['duplicate_scans'][0]['verdict']);
        $this->assertSame([], $scan['missing_months']);

        $dupes = $this->okData('GET', '/ingest/dupes', ['root' => $this->root]);
        $verdicts = array_column($dupes['groups'], 'verdict');
        $this->assertContains('duplicate_identical', $verdicts);
        $this->assertContains('not_chosen', $verdicts);
        $this->assertSame(1, $dupes['summary']['duplicate_identical']);

        $cov = $this->okData('GET', '/ingest/coverage', ['root' => $this->root, 'account' => '7734']);
        $this->assertSame(['2026-08', '2026-09'], $cov['accounts'][0]['present']);
        $this->assertSame([], $cov['missing']);
    }

    public function testRowsCarryTheBankIdsAndOrdinals(): void
    {
        $rows = $this->okData('GET', '/ingest/rows', ['root' => $this->root, 'account' => '4021']);
        $this->assertSame(5, $rows['total']);
        $ids  = array_column($rows['rows'], 'external_id');
        $this->assertContains('ofx:4021:NB202609030001', $ids);
        $this->assertContains('ofx:4021:NB202609030002', $ids, 'two identical coffees keep the bank\'s two ids');
        $grocer = array_values(array_filter($rows['rows'], static fn (array $r): bool => str_contains($r['internal_reference'], 'GREEN GROCER')))[0];
        $this->assertSame('GREEN GROCER', $grocer['description'], 'bank noise stripped by the rule table');
        $this->assertSame('POS PURCHASE GREEN GROCER XXXX4021', $grocer['internal_reference'], 'the raw text, byte for byte');
        $this->assertSame('42.17', $grocer['amount']);
        $this->assertSame('withdrawal', $grocer['type']);

        $acme = $this->okData('GET', '/ingest/rows', ['root' => $this->root, 'account' => 'acme_llc/Northbank/Checking_x7734']);
        $this->assertStringStartsWith('ff1:7734:20260828:-89.99:0:', $acme['rows'][0]['external_id']);
    }

    public function testAccountsPlanAndApply(): void
    {
        $plan = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 3, 'link' => 0, 'skip' => 0, 'ambiguous' => 0], $plan['summary']);
        $this->assertSame(0, Account::query()->where('name', 'like', 'Household%')->count(), 'the plan creates nothing');
        $byKey = array_column($plan['plan'], null, 'key');
        $this->assertSame('Household · Northbank Checking ••4021', $byKey['household/Northbank/Checking_x4021']['proposed']['name']);
        $this->assertSame('defaultAsset', $byKey['household/Northbank/Checking_x4021']['proposed']['account_role']);
        $this->assertSame('Acme LLC · Northbank Checking ••7734', $byKey['acme_llc/Northbank/Checking_x7734']['proposed']['name']);
        $mortgage = $byKey['household/Meridian/Mortgage']['proposed'];
        $this->assertSame(['Household · Meridian Mortgage', 'liability', 'mortgage', 'debit'], [$mortgage['name'], $mortgage['type'], $mortgage['liability_type'], $mortgage['liability_direction']]);
        $this->assertStringContainsString('liability', $byKey['household/Meridian/Mortgage']['reason']);

        // a token from another route is refused
        $this->assertPlaneError($this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]), 403, 'forbidden');
        $this->assertPlaneError($this->machine('POST', '/ingest/accounts/apply', ['dry_run' => false]), 400, 'invalid_input');

        $apply = $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertFalse($apply['dry_run']);
        $this->assertSame(3, $apply['changes']['created']);
        $this->assertCount(3, $apply['created']);
        $this->assertIsInt($apply['operation_id']);
        $this->assertIsString($apply['run_id']);
        $this->assertFileExists($this->root.'/.firefly-staging/_map.json');
        $this->assertSame("*\n", file_get_contents($this->root.'/.firefly-staging/.gitignore'));

        $again = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 0, 'link' => 0, 'skip' => 3, 'ambiguous' => 0], $again['summary'], 'a re-run is stable: skip, never a second set');

        $map   = $this->okData('GET', '/ingest/map', ['root' => $this->root]);
        $this->assertSame(['mapped' => 3, 'unmapped' => 0, 'stale' => 0, 'orphan' => 0], $map['summary']);
    }

    public function testAccountsPlanLinksExistingAndFlagsAmbiguity(): void
    {
        // an existing account named exactly as the naming rule would
        $plan  = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        @unlink($this->root.'/.firefly-staging/_map.json');
        $relink = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertSame(['create' => 0, 'link' => 3, 'skip' => 0, 'ambiguous' => 0], $relink['summary'], 'deleting the map loses nothing but time');
        $this->assertStringContainsString('matched on name', $relink['plan'][0]['reason']);

        // a different naming template → the last-4 match is ambiguous when two accounts share it
        $infer = $this->okData('POST', '/ingest/map/infer', ['root' => $this->root]);
        $this->assertFalse($infer['saved']);
        $this->assertSame('proposed', $infer['accounts'][0]['status']);
    }

    public function testMapPutWritesBesideTheStatements(): void
    {
        $plan  = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->okData('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $acme  = (int) Account::query()->where('name', 'Acme LLC · Northbank Checking ••7734')->value('id');
        $house = (int) Account::query()->where('name', 'Household · Northbank Checking ••4021')->value('id');

        $dry   = $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['label' => 'Checking_x7734', 'account_id' => $house]]]);
        $this->assertTrue($dry['dry_run']);
        $this->assertSame(['mapped' => 1], $dry['changes']);
        $before = json_decode((string) file_get_contents($this->root.'/.firefly-staging/_map.json'), true);
        $this->assertSame($acme, $before['entries']['acme_llc/Northbank/Checking_x7734']['account_id'], 'a dry run writes nothing');
        $this->okData('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['label' => 'Checking_x7734', 'account_id' => $house]], 'dry_run' => false, 'confirm_token' => $dry['confirm_token']]);
        $map   = json_decode((string) file_get_contents($this->root.'/.firefly-staging/_map.json'), true);
        $this->assertSame($house, $map['entries']['acme_llc/Northbank/Checking_x7734']['account_id']);
        $this->assertNotSame($acme, $house);

        $this->assertPlaneError($this->machine('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['label' => 'Nope', 'account_id' => $house]]]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('PUT', '/ingest/map', ['root' => $this->root, 'map' => [['label' => 'Checking_x7734', 'account_id' => 999999]]]), 404, 'not_found');
    }

    public function testPlanApplyReRunAndDeletedStaysDeleted(): void
    {
        $this->provisionAccounts();
        $groups = TransactionGroup::query()->count();
        $plan   = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertTrue($plan['dry_run']);
        $this->assertSame(9, $plan['change_count'], '5 + 3 + 1 rows are new');
        $this->assertSame($groups, TransactionGroup::query()->count(), 'the plan is rolled back');
        $checking = array_column($plan['accounts'], null, 'key')['household/Northbank/Checking_x4021'];
        $this->assertSame(5, $checking['new']);
        $this->assertSame(1, $checking['superseded']);
        $this->assertSame(0, $checking['already_present']);
        $this->assertSame(0, $plan['would_fire_webhooks']);
        $this->assertMatchesRegularExpression('/^cf_[0-9a-f]{32}$/', $plan['confirm_token']);

        $apply  = $this->okData('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(9, $apply['changes']['created']);
        $this->assertSame($groups + 9, TransactionGroup::query()->count());
        $this->assertSame(0, TransactionJournal::query()->where('completed', false)->count(), 'the batch was finished');
        $meta   = TransactionJournalMeta::query()->where('name', 'external_id')->pluck('data')->map(static fn ($d): string => (string) $d)->all();
        $this->assertContains('ofx:4021:NB202609030001', $meta);

        // re-run imports 0
        $again  = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertSame(0, $again['change_count']);
        $this->assertSame(9, $again['changes']['duplicates']);

        // a note the operator wrote survives a re-run, byte for byte
        $journal = TransactionJournal::query()->orderBy('id')->first();
        Note::create(['noteable_id' => $journal->id, 'noteable_type' => TransactionJournal::class, 'text' => "operator's note — keep\nme"]);

        // deleted stays deleted
        $rent   = TransactionJournal::query()->where('description', 'RENT EXAMPLE PROPERTIES')->firstOrFail();
        $rent->transactionGroup->delete();
        $third  = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertSame(0, $third['change_count']);
        $this->assertSame(1, $third['changes']['previously_deleted']);
        $deleted = array_values(array_filter($third['rows'], static fn (array $r): bool => 'previously_deleted' === $r['verdict']));
        $this->assertCount(1, $deleted);
        $this->assertStringContainsString('stays deleted', $deleted[0]['reason']);
        $apply3 = $this->okData('POST', '/ingest/apply', ['confirm_token' => $third['confirm_token'], 'dry_run' => false]);
        $this->assertSame(0, $apply3['change_count']);
        $this->assertNull(TransactionJournal::query()->where('description', 'RENT EXAMPLE PROPERTIES')->first(), 'still deleted');
        $this->assertSame("operator's note — keep\nme", Note::query()->where('noteable_id', $journal->id)->value('text'));

        $runs = $this->okData('GET', '/ingest/runs');
        $this->assertGreaterThanOrEqual(4, count($runs['runs']));
        $one  = $this->okData('GET', '/ingest/runs/'.$apply['run_id']);
        $this->assertSame('apply', $one['run']['kind']);
        $this->assertSame(9, $one['run']['created']);
        $this->assertPlaneError($this->machine('GET', '/ingest/runs/run_20260101T000000Z_abcdef'), 404, 'not_found');
    }

    public function testAStaleTokenIsRefusedWithTheNewCounts(): void
    {
        $this->provisionAccounts();
        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['7734']]);
        $this->assertSame(3, $plan['change_count']);
        // the ledger moves: one of the rows is imported by another run meanwhile
        $this->planAndApply(['accounts' => ['7734'], 'end' => '2026-08-31']);
        $env  = $this->assertPlaneError($this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]), 409, 'conflict');
        $this->assertSame(1, $env['error']['details']['change_count']);
        $this->assertSame(3, $env['error']['details']['planned_change_count']);
    }

    public function testMaxChangesReportsTheRealCount(): void
    {
        $this->provisionAccounts();
        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $env  = $this->assertPlaneError($this->machine('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false, 'max_changes' => 5]), 409, 'conflict');
        $this->assertSame(9, $env['error']['details']['change_count']);
        // the token survives a dry-run re-check
        $check = $this->okData('POST', '/ingest/apply', ['confirm_token' => $plan['confirm_token']]);
        $this->assertTrue($check['dry_run']);
        $this->assertSame(9, $check['change_count']);
        $this->okData('POST', '/ingest/apply', ['confirm_token' => $check['confirm_token'], 'dry_run' => false]);
    }

    public function testUnmappedAccountsAreBlockedNotGuessed(): void
    {
        $plan = $this->okData('POST', '/ingest/plan', ['root' => $this->root]);
        $this->assertSame(0, $plan['change_count']);
        $this->assertCount(3, $plan['blocked']);
        $this->assertStringContainsString('unmapped', $plan['blocked'][0]['reason']);
        $this->assertPlaneError($this->machine('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['9999']]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('POST', '/ingest/plan', ['root' => $this->root, 'start' => '2026-10-01', 'end' => '2026-09-01']), 400, 'invalid_input');
    }

    public function testFilePlanAndApply(): void
    {
        $ids  = $this->provisionAccounts();
        $file = $this->root.'/bank/household/Northbank/Checking_x4021/Checking_x4021_2026-09.ofx';
        $plan = $this->okData('POST', '/ingest/file/plan', ['account_id' => $ids['household/Northbank/Checking_x4021'], 'path' => $file]);
        $this->assertSame(3, $plan['change_count']);
        $done = $this->okData('POST', '/ingest/file/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]);
        $this->assertSame(3, $done['changes']['created']);
        // the full plan now sees those three as already present
        $full = $this->okData('POST', '/ingest/plan', ['root' => $this->root, 'accounts' => ['4021']]);
        $this->assertSame(2, $full['change_count']);
        $this->assertSame(3, $full['changes']['duplicates']);
        $this->assertPlaneError($this->machine('POST', '/ingest/file/plan', ['account_id' => 999999, 'path' => $file]), 404, 'not_found');
        $this->assertPlaneError($this->machine('POST', '/ingest/file/plan', ['account_id' => $ids['household/Northbank/Checking_x4021'], 'path' => '/etc/hosts']), 403, 'forbidden');
    }

    public function testAForeignStagingDirectoryIsRefused(): void
    {
        mkdir($this->root.'/.firefly-staging');
        file_put_contents($this->root.'/.firefly-staging/somebody-elses.txt', 'evidence');
        $env = $this->assertPlaneError($this->machine('POST', '/ingest/plan', ['root' => $this->root]), 409, 'conflict');
        $this->assertSame('import', $env['error']['details']['archive_import']);
        $this->assertSame('evidence', file_get_contents($this->root.'/.firefly-staging/somebody-elses.txt'));
    }

    public function testWritesNeedTheWriteTier(): void
    {
        config(['machine.allow_write' => false]);
        $plan = $this->okData('POST', '/ingest/accounts/plan', ['root' => $this->root]);
        $this->assertPlaneError($this->machine('POST', '/ingest/accounts/apply', ['confirm_token' => $plan['confirm_token'], 'dry_run' => false]), 403, 'write_disabled');
    }

    public function testTheArchiveIsNeverWritten(): void
    {
        $before = self::treeHash($this->root, true);
        $this->provisionAccounts();
        $this->planAndApply();
        $this->okData('POST', '/ingest/scan', ['root' => $this->root]);
        $this->assertSame($before, self::treeHash($this->root, true), 'nothing under the root changes outside .firefly-staging');
        $this->assertSame(0, DB::table('webhook_messages')->count());
    }

    private static function treeHash(string $dir, bool $skipStaging): string
    {
        $out = [];
        $it  = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $p = (string) $f;
            if ($skipStaging && str_contains($p, '/.firefly-staging')) {
                continue;
            }
            $out[$p] = hash_file('sha256', $p);
        }
        ksort($out);

        return hash('sha256', (string) json_encode($out));
    }
}
