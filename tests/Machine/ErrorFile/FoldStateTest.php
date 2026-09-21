<?php

/*
 * FoldStateTest.php
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

namespace Tests\Machine\ErrorFile;

use FireflyIII\Machine\ErrorFile\Folder;
use FireflyIII\Machine\ErrorFile\FoldState;
use FireflyIII\Machine\ErrorFile\Level;
use FireflyIII\Machine\ErrorFile\Record;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §3.5, §4.7, AC 6 and the §15 FoldStateTest row: the sidecar created 0600; an
 * unopenable or locked sidecar → the record written unfolded; the window and the lazy summary;
 * repeats of an open key never charged to the budget; LRU with an owed summary; a corrupt, 2 MiB or
 * `v:2` sidecar → reset plus one WARN and no exception; clock rollback; the 600 budget and its WARN
 * (and a FATAL still written); the ingest rate slot and its lazy WARN.
 *
 * A "new process" is simulated with Folder::resetForTests() (an empty L1), so every sighting of a
 * key reaches L2, as 50 separate `php -S` requests would.
 *
 * @internal
 */
#[CoversNothing]
final class FoldStateTest extends MachineTestCase
{
    use ErrorFileSandbox;

    private const int T0 = 1_790_016_062_118; // 2026-09-21T18:41:02.118Z

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->resetErrorFile();
    }

    protected function tearDown(): void
    {
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testTheSidecarIsCreatedPrivateAndParses(): void
    {
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0));
        $fold = dirname($this->path).'/error.fold';
        self::assertSame(0o600, fileperms($fold) & 0o777);
        $doc = self::sidecar($fold);
        self::assertSame(1, $doc['v']);
        self::assertCount(1, $doc['k']);
        $entry = array_values($doc['k'])[0];
        self::assertSame(['f', 's', 'n', 'l', 'a', 'W', 'd', 'h'], array_keys($entry));
        self::assertSame(self::T0, $entry['f']);
        self::assertSame('RuntimeException: Synthetic fault A', $entry['h']);
        self::assertSame(['w' => self::T0 - self::T0 % 60000, 'n' => 1, 'x' => 0], $doc['b']);
        self::assertSame(['w', 'g', 'x', 'c'], array_keys($doc['r']));
    }

    public function testAnUnopenableSidecarWritesTheRecordUnfolded(): void
    {
        mkdir(dirname($this->path), 0o700, true);
        mkdir(dirname($this->path).'/error.fold');   // a directory where the sidecar should be
        self::assertSame('unfolded', Folder::admit($this->path, self::fault('A'), self::T0));
        Folder::resetForTests();
        self::assertSame('unfolded', Folder::admit($this->path, self::fault('A'), self::T0 + 1000));
        self::assertCount(2, self::headers($this->path), 'duplicated, never lost');
    }

    public function testALockedSidecarFallsThroughWithinTheBoundedWait(): void
    {
        mkdir(dirname($this->path), 0o700, true);
        $fold = dirname($this->path).'/error.fold';
        touch($fold);
        $proc = proc_open([PHP_BINARY, '-r', sprintf('$h = fopen(%s, "c+"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(1500000);', var_export($fold, true))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start the lock holder');
        }
        self::assertSame("locked\n", fgets($pipes[1]));
        $start = hrtime(true);
        self::assertSame('unfolded', Folder::admit($this->path, self::fault('A'), self::T0));
        $ms    = (hrtime(true) - $start) / 1e6;
        proc_terminate($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        self::assertLessThan(450, $ms, 'at most the 200 ms lock wait (AC 6)');
        self::assertCount(1, self::headers($this->path));
    }

    public function testAHeldSidecarLockIsPaidOnceNotOncePerRecord(): void
    {
        mkdir(dirname($this->path), 0o700, true);
        $fold = dirname($this->path).'/error.fold';
        touch($fold);
        $proc = proc_open([PHP_BINARY, '-r', sprintf('$h = fopen(%s, "c+"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(4000000);', var_export($fold, true))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start the lock holder');
        }
        self::assertSame("locked\n", fgets($pipes[1]));
        $start  = hrtime(true);
        $states = [];
        for ($i = 0; $i < 1000; ++$i) {
            $state          = Folder::admit($this->path, self::fault('A'), self::T0 + $i);
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        // a second, distinct fault under the same held lock makes one try and does not spin again
        self::assertSame('unfolded', Folder::admit($this->path, self::fault('B'), self::T0 + 1000));
        $ms = (hrtime(true) - $start) / 1e6;
        // the shutdown flush, lock still held: the owed count becomes its own summary line
        Folder::flushOwed(self::T0 + 2000);
        proc_terminate($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        self::assertSame(['unfolded' => 1, 'folded' => 999], $states);
        self::assertLessThan(450, $ms, 'a held lock delays a request by at most ~200 ms, once (AC 6)');
        $headers = self::headers($this->path);
        self::assertCount(3, $headers, 'A once, B once, then A\'s summary');
        self::assertStringContainsString('Synthetic fault A', $headers[0]);
        self::assertStringContainsString('Synthetic fault B', $headers[1]);
        self::assertStringContainsString('×999 more in the 60s window', $headers[2]);
    }

    public function testTheWindowFoldsAcrossProcessesAndTheSummaryIsLazy(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            Folder::resetForTests();
            self::assertSame(0 === $i ? 'written' : 'folded', Folder::admit($this->path, self::fault('A', 'id '.(1000 + $i)), self::T0 + $i * 10_000));
        }
        self::assertCount(1, self::headers($this->path), 'one line while the window is open');
        $entry = array_values(self::sidecar(dirname($this->path).'/error.fold')['k'])[0];
        self::assertSame(4, $entry['n']);
        self::assertSame(self::T0 + 40_000, $entry['s']);

        // nothing is written when the window closes; the next write from any process sweeps it
        Folder::resetForTests();
        self::assertSame('written', Folder::admit($this->path, self::fault('B'), self::T0 + 61_000));
        $headers = self::headers($this->path);
        self::assertCount(3, $headers);
        self::assertSame(
            '[2026-09-21T18:42:03.118Z] [ERROR] [php-machine] [app/Machine/Ingest/Importer.php] importing a statement row — ×4 more in the 60s window from 18:41:02: RuntimeException: Synthetic fault A id 1000',
            $headers[1]
        );
        self::assertStringContainsString('Synthetic fault B', $headers[2]);
        self::assertCount(1, self::sidecar(dirname($this->path).'/error.fold')['k'], 'the closed window is gone');
    }

    public function testARolloverHandsTheOldCountOverBeforeTheSweep(): void
    {
        // one long artisan command: L1 keeps the key across the window boundary
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0));
        for ($i = 1; $i <= 7; ++$i) {
            self::assertSame('folded', Folder::admit($this->path, self::fault('A'), self::T0 + $i * 1000));
        }
        self::assertSame(['keys' => 1, 'owed' => 7], Folder::stats());
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0 + 65_000), 'a rollover opens a new window');
        $headers = self::headers($this->path);
        self::assertCount(3, $headers);
        self::assertStringContainsString('×7 more in the 60s window from 18:41:02', $headers[1]);
        self::assertStringContainsString('Synthetic fault A', $headers[2]);
        self::assertSame(['keys' => 1, 'owed' => 0], Folder::stats());
    }

    public function testTheShutdownFlushNeverOpensAWindow(): void
    {
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0));
        self::assertSame('folded', Folder::admit($this->path, self::fault('A'), self::T0 + 1000));
        self::assertSame('folded', Folder::admit($this->path, self::fault('A'), self::T0 + 2000));
        // another process swept the window meanwhile: the owed count gets its own window's summary
        $other = FoldState::open($this->path, self::T0 + 70_000);
        self::assertNotNull($other);
        self::assertSame([], $other->drainQueued(), 'n was 0 in L2: nothing owed there');
        $other->saveAndClose();
        Folder::flushOwed(self::T0 + 71_000);
        $headers = self::headers($this->path);
        self::assertCount(2, $headers);
        self::assertStringContainsString('×2 more in the 60s window from 18:41:02', $headers[1]);
        self::assertSame([], self::sidecar(dirname($this->path).'/error.fold')['k'] ?: [], 'no window was opened for the count');

        // same window still open in L2: the flush adds to it, nothing is written yet
        Folder::resetForTests();
        self::assertSame('written', Folder::admit($this->path, self::fault('C'), self::T0 + 100_000));
        self::assertSame('folded', Folder::admit($this->path, self::fault('C'), self::T0 + 100_500));
        Folder::flushOwed(self::T0 + 101_000);
        self::assertCount(3, self::headers($this->path));
        self::assertSame(1, array_values(self::sidecar(dirname($this->path).'/error.fold')['k'])[0]['n']);

        // a flush for a sandbox whose directory has gone is skipped
        Folder::resetForTests();
        $gone = $this->sandbox.'/gone/error.err';
        self::assertSame('written', Folder::admit($gone, self::fault('D'), self::T0));
        self::assertSame('folded', Folder::admit($gone, self::fault('D'), self::T0 + 1));
        unlink($gone);
        unlink(dirname($gone).'/error.fold');
        rmdir(dirname($gone));
        Folder::flushOwed(self::T0 + 2);
        self::assertDirectoryDoesNotExist(dirname($gone));
    }

    public function testRepeatsOfAnOpenKeyAreNeverChargedToTheBudget(): void
    {
        config(['errorfile.file_budget_per_minute' => 3]);
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0));
        for ($i = 0; $i < 100; ++$i) {
            Folder::resetForTests();
            config(['errorfile.file_budget_per_minute' => 3]);
            self::assertSame('folded', Folder::admit($this->path, self::fault('A'), self::T0 + 10 + $i));
        }
        self::assertSame(1, self::sidecar(dirname($this->path).'/error.fold')['b']['n']);
        self::assertSame('written', Folder::admit($this->path, self::fault('B'), self::T0 + 500));
        self::assertSame('written', Folder::admit($this->path, self::fault('C'), self::T0 + 600));
        self::assertSame('dropped', Folder::admit($this->path, self::fault('D'), self::T0 + 700));
        self::assertCount(3, self::headers($this->path));
    }

    public function testLruEvictionWritesTheOwedSummaryFirst(): void
    {
        config(['errorfile.fold_max_keys' => 3]);
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0));
        Folder::resetForTests();
        config(['errorfile.fold_max_keys' => 3]);
        self::assertSame('folded', Folder::admit($this->path, self::fault('A'), self::T0 + 1));
        self::assertSame('written', Folder::admit($this->path, self::fault('B'), self::T0 + 2));
        self::assertSame('written', Folder::admit($this->path, self::fault('C'), self::T0 + 3));
        self::assertSame('written', Folder::admit($this->path, self::fault('D'), self::T0 + 4));
        $headers = self::headers($this->path);
        self::assertCount(5, $headers);
        self::assertStringContainsString('×1 more in the 60s window from 18:41:02: RuntimeException: Synthetic fault A', $headers[3], 'the evicted key\'s summary comes before the record that evicted it');
        self::assertStringContainsString('Synthetic fault D', $headers[4]);
        self::assertCount(3, self::sidecar(dirname($this->path).'/error.fold')['k']);
    }

    public function testTheWriterKeepsTheSidecarUnderItsByteCap(): void
    {
        config(['errorfile.fold_file_max_bytes' => 4096, 'errorfile.fold_max_keys' => 1000]);
        for ($i = 0; $i < 60; ++$i) {
            Folder::resetForTests();
            config(['errorfile.fold_file_max_bytes' => 4096]);
            Folder::admit($this->path, self::fault('K'.$i, str_repeat('z', 150)), self::T0 + $i);
        }
        $fold = dirname($this->path).'/error.fold';
        self::assertLessThan(4096, filesize($fold));
        self::assertSame(1, self::sidecar($fold)['v']);
    }

    /** @return array<string, array{0: string}> */
    public static function corruptSidecars(): array
    {
        return [
            'garbage'         => ['this is not json at all'],
            'truncated'       => ['{"v":1,"k":{"abc":{"f":1,"s":2,'],
            'version 2'       => ['{"v":2,"k":{},"b":{"w":0,"n":0,"x":0},"r":{"w":0,"g":0,"x":0,"c":{}}}'],
            'bad field types' => ['{"v":1,"k":{"abc":{"f":"1","s":2,"n":3,"l":"ERROR","a":"x","W":"x","d":"x","h":"x"}},"b":{"w":0,"n":0,"x":0},"r":{"w":0,"g":0,"x":0,"c":{}}}'],
            'bad sid'         => ['{"v":1,"k":{},"b":{"w":0,"n":0,"x":0},"r":{"w":0,"g":0,"x":0,"c":{"Raw Client String":1}}}'],
            'a list'          => ['[1,2,3]'],
            'two mebibytes'   => ['2MiB'],
        ];
    }

    #[DataProvider('corruptSidecars')]
    public function testACorruptSidecarIsResetWithOneWarn(string $content): void
    {
        mkdir(dirname($this->path), 0o700, true);
        $fold = dirname($this->path).'/error.fold';
        file_put_contents($fold, '2MiB' === $content ? '{"v":1,"k":{},"pad":"'.str_repeat('p', 2 * 1_048_576).'"}' : $content);
        self::assertSame('written', Folder::admit($this->path, self::fault('A'), self::T0));
        $headers = self::headers($this->path);
        self::assertCount(2, $headers);
        self::assertSame('[2026-09-21T18:41:02.118Z] [WARN] [php-machine] [app/Machine/ErrorFile/FoldState.php] reading the fold state — the fold state was unreadable and was reset {pid='.getmypid().'}', $headers[0]);
        self::assertStringContainsString('Synthetic fault A', $headers[1]);
        $doc = self::sidecar($fold);
        self::assertSame(1, $doc['v']);
        self::assertCount(1, $doc['k']);
        self::assertLessThan(10_000, filesize($fold));
        // the next fault finds a healthy sidecar: no second WARN
        Folder::resetForTests();
        Folder::admit($this->path, self::fault('B'), self::T0 + 1);
        self::assertCount(3, self::headers($this->path));
    }

    public function testAnEmptySidecarIsFreshNotCorrupt(): void
    {
        mkdir(dirname($this->path), 0o700, true);
        touch(dirname($this->path).'/error.fold');
        Folder::admit($this->path, self::fault('A'), self::T0);
        self::assertCount(1, self::headers($this->path));
    }

    public function testAClockThatWentBackwardsClosesTheWindow(): void
    {
        Folder::admit($this->path, self::fault('A'), self::T0);
        Folder::resetForTests();
        Folder::admit($this->path, self::fault('A'), self::T0 + 5);
        Folder::resetForTests();
        self::assertSame('written', Folder::admit($this->path, self::fault('B'), self::T0 - 120_000));
        $headers = self::headers($this->path);
        self::assertCount(3, $headers);
        self::assertStringContainsString('×1 more in the 60s window from 18:41:02', $headers[1]);
    }

    public function testTheFileBudgetCapsDistinctFaultsAndAFatalIsStillWritten(): void
    {
        $written = 0;
        $dropped = 0;
        for ($i = 0; $i < 5000; ++$i) {
            Folder::resetForTests();
            $state = Folder::admit($this->path, self::fault('distinct '.self::word($i)), self::T0 + intdiv($i, 100));
            'written' === $state ? ++$written : ('dropped' === $state ? ++$dropped : null);
        }
        self::assertSame(600, $written);
        self::assertSame(4400, $dropped);
        self::assertCount(600, self::headers($this->path));

        $fatal = new Record('2026-09-21T18:41:30.000Z', Level::Fatal, 'php-web', 'app/Support/Steam.php:612', 'handling GET /', 'Symfony\Component\ErrorHandler\Error\FatalError: Synthetic fatal', data: ['net' => 'shutdown']);
        self::assertSame('written', Folder::admit($this->path, $fatal, self::T0 + 30_000));
        self::assertCount(601, self::headers($this->path));

        Folder::resetForTests();
        self::assertSame('written', Folder::admit($this->path, self::fault('after the window'), self::T0 + 61_000));
        $headers = self::headers($this->path);
        self::assertCount(603, $headers);
        self::assertSame('[2026-09-21T18:42:03.118Z] [WARN] [php-machine] [app/Machine/ErrorFile/FoldState.php] enforcing the file budget — dropped 4400 records over the file budget of 600/min {pid='.getmypid().'}', $headers[601]);
        self::assertStringContainsString('after the window', $headers[602]);
    }

    public function testTheIngestRateSlot(): void
    {
        $state = FoldState::open($this->path, self::T0, 'php-web');
        self::assertNotNull($state);
        self::assertSame(240, $state->rate('k3x9q2ab', 300));
        self::assertSame(0, $state->rate('k3x9q2ab', 5));
        self::assertSame(40, $state->rate('Not A Sid!', 40, 10), 'a bad sid is counted against the _ bucket');
        self::assertSame(10, $state->rate('', 10));
        $snap = $state->snapshot();
        self::assertSame(['k3x9q2ab' => 240, '_' => 50], $snap['r']['c'], 'a raw client string never becomes a key');
        self::assertSame(290, $snap['r']['g']);
        self::assertSame(60 + 5 + 10, $snap['r']['x'], 'rate drops and event-cap drops');
        self::assertSame([], $state->drainQueued());
        $state->saveAndClose();
        self::assertFileDoesNotExist($this->path, 'rating writes no line');
        self::assertStringNotContainsString('Not A Sid', (string) file_get_contents(dirname($this->path).'/error.fold'));

        // the global cap and the client-entry cap
        config(['errorfile.ingest.rate_max_clients' => 3]);
        $state = FoldState::open($this->path, self::T0 + 1000, 'php-web');
        self::assertNotNull($state);
        $total = 0;
        foreach (['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd', 'eeeeeeee'] as $sid) {
            $total += $state->rate($sid, 240);
        }
        self::assertSame(1200 - 290, $total, 'at most 1,200 per window globally');
        self::assertCount(3, $state->snapshot()['r']['c'], 'at most rate_max_clients entries, oldest evicted');
        $state->saveAndClose();

        // exactly one lazy WARN when the window closes
        Folder::resetForTests();
        Folder::admit($this->path, self::fault('A'), self::T0 + 61_000);
        $headers = self::headers($this->path);
        self::assertCount(2, $headers);
        $dropped = 75 + (5 * 240 - 910);
        self::assertSame(sprintf('[2026-09-21T18:42:03.118Z] [WARN] [php-web] [app/Machine/ErrorFile/Ingest.php] receiving browser error reports — dropped %d browser reports over the ingest limits {via=php-web pid=%d}', $dropped, getmypid()), $headers[0]);
        Folder::resetForTests();
        Folder::admit($this->path, self::fault('B'), self::T0 + 62_000);
        self::assertCount(3, self::headers($this->path), 'one WARN per window');
    }

    private static function fault(string $which, string $detail = ''): Record
    {
        return new Record(
            ts: '2026-09-21T18:41:02.118Z',
            level: Level::Error,
            app: 'php-machine',
            where: 'app/Machine/Ingest/Importer.php',
            doing: 'importing a statement row',
            error: trim('RuntimeException: Synthetic fault '.$which.' '.$detail),
            data: ['net' => 'report'],
        );
    }

    /** A distinct word per number: digits and hex runs would normalise to the same fold key. */
    private static function word(int $n): string
    {
        $out = '';
        do {
            $out .= chr(97 + $n % 26);
            $n = intdiv($n, 26);
        } while ($n > 0);

        return $out.'q';
    }

    /** @return array<string, mixed> */
    private static function sidecar(string $fold): array
    {
        $doc = json_decode((string) file_get_contents($fold), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($doc);

        return $doc;
    }
}
