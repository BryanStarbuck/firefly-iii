<?php

/*
 * ConcurrencyTest.php
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
use FireflyIII\Machine\ErrorFile\Level;
use FireflyIII\Machine\ErrorFile\Record;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.4, §4.7, AC 5 and AC 7 (PHP half), the §15 ConcurrencyTest row. Each child
 * is a real application boot (APP_ENV=testing) that finds its sandbox path only through the
 * explicit child env (§15 "Child processes"); a sandbox database, credentials file and state
 * directory keep it off everything the operator owns. The Node writer of AC 7 is a fifth child:
 * Fixtures/error_file_node_child.mjs drives the library's compiled RollingFileWriter (errorfile/.build,
 * built by `just test` before PHPUnit, else the vendored copy compiled into cli/code/dist) against
 * the same sandbox path, rolling at the same 64 KiB without a lock, as it does in production.
 *
 * @internal
 */
#[CoversNothing]
final class ConcurrencyTest extends MachineTestCase
{
    use ErrorFileSandbox;

    private string $path = '';

    private int $nodeLines = 0;

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

    public function testFourWritersAt64KibTearNoLineAndKeepAtMostFiveBackups(): void
    {
        $writer   = $this->nodeWriterModule();
        $children = [];
        for ($c = 0; $c < 4; ++$c) {
            $children[] = self::spawnChild(['distinct', 'c'.chr(103 + $c), '200', '65536'], $this->childEnv($this->path));
        }
        $node = proc_open(['node', __DIR__.'/Fixtures/error_file_node_child.mjs', $writer, $this->path, '200', '65536'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes, dirname(__DIR__, 3), $this->childEnv($this->path));
        self::assertIsResource($node, 'could not start the Node writer');
        fclose($nodePipes[0]);
        $children[] = [$node, $nodePipes];
        foreach ($children as [$proc, $pipes]) {
            [$code, , $err] = self::waitChild($proc, $pipes);
            self::assertSame(0, $code, $err);
            self::assertSame('', $err, 'no writer fell back to stderr');
        }

        self::assertFileExists($this->path);
        for ($i = 1; $i <= 5; ++$i) {
            self::assertFileExists($this->path.'.'.$i, 'rotation happened at 64 KiB');
        }
        self::assertFileDoesNotExist($this->path.'.6', 'at most 5 backups');

        $records = 0;
        foreach (['', '.1', '.2', '.3', '.4', '.5'] as $suffix) {
            $file = $this->path.$suffix;
            self::assertSame(0o600, fileperms($file) & 0o777);
            self::assertStringEndsWith("\n", (string) file_get_contents($file));
            foreach (self::lines($file) as $line) {
                if (str_starts_with($line, '    ')) {
                    self::assertMatchesRegularExpression('/^    at (FireflyIII\\\Support\\\Synthetic\\\Concurrent\d->run(Deep){30} \(app\/Support\/Synthetic\/Concurrent\d\.php:\d+\)|synthetic(Deep){30} \(cli\/code\/src\/synthetic\d\.ts:\d+:1\))$/', $line, 'a torn stack line in error.err'.$suffix);

                    continue;
                }
                if (str_contains($line, '] [ffx] [')) {
                    ++$this->nodeLines;
                    self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] \[ERROR\] \[ffx\] \[cli\/code\/src\/synthetic\.ts\] running ffx synthetic — Error: Synthetic node fault nd-[g-z]+ x{200} \{tag=nd end=yes\}$/', $line, 'a torn Node header in error.err'.$suffix);
                    ++$records;

                    continue;
                }
                self::assertMatchesRegularExpression('/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] \[ERROR\] \[php-artisan\] \[app\/Support\/Synthetic\/Concurrent1\.php:10\] running artisan firefly-iii:synthetic — RuntimeException: Synthetic concurrent fault c[g-j]-[g-z]+ x{200} \{tag=c[g-j] end=yes\}$/', $line, 'a torn header in error.err'.$suffix);
                ++$records;
            }
        }
        self::assertGreaterThan(100, $records);
        self::assertGreaterThan(0, $this->nodeLines, 'the Node writer\'s records are in the kept generations (it wrote alongside the PHP writers)');
        $fold = json_decode((string) file_get_contents(dirname($this->path).'/error.fold'), true);
        self::assertIsArray($fold, 'the fold file parses');
        self::assertSame(1, $fold['v']);
        // A fold-lock timeout under load writes a record unfolded and uncharged (§4.7 step 2), so the
        // budget count is at most the 800 records, never more (each was charged by one writer).
        self::assertLessThanOrEqual(800, $fold['b']['n']);
        self::assertGreaterThan(0, $fold['b']['n']);
    }

    /** The compiled RollingFileWriter the Node child drives: the library build, else the CLI's vendored copy. */
    private function nodeWriterModule(): string
    {
        $root = dirname(__DIR__, 3);
        foreach (['errorfile/.build/src/rolling-file-writer.js', 'cli/code/dist/src/vendor/error-file/rolling-file-writer.js'] as $candidate) {
            if (is_file($root.'/'.$candidate)) {
                return $root.'/'.$candidate;
            }
        }
        self::fail('AC 7 needs the compiled Node writer: run `just build-errorfile` (or `just test`, which builds it first)');
    }

    public function testFiveHundredIdenticalFaultsFromFiftyProcessesAreOneRecordAndOneSummary(): void
    {
        $start    = (int) floor(microtime(true) * 1000);
        $children = [];
        for ($c = 0; $c < 5; ++$c) {
            $children[] = self::spawnChild(['identical', '10', '10'], $this->childEnv($this->path));
        }
        foreach ($children as [$proc, $pipes]) {
            [$code, , $err] = self::waitChild($proc, $pipes);
            self::assertSame(0, $code, $err);
        }
        self::assertLessThan(55_000, (int) floor(microtime(true) * 1000) - $start, 'the burst (10 s in AC 5) stayed inside one 60 s window, even on a loaded machine');
        self::assertCount(1, self::headers($this->path), 'one record while the window is open');

        // the next write from any process, after the window, sweeps the summary
        Folder::admit($this->path, new Record('2026-09-21T00:00:00.000Z', Level::Error, 'php-web', 'app/Other.php', 'doing something else', 'RuntimeException: Synthetic other'), $start + 120_000);
        $identical = array_values(array_filter(self::headers($this->path), static fn (string $l): bool => str_contains($l, 'Synthetic identical fault')));
        self::assertCount(2, $identical, '1 record plus 1 summary (AC 5, M6)');
        self::assertStringEndsWith('importing a statement row — RuntimeException: Synthetic identical fault {pid='.substr((string) strrchr($identical[0], '='), 1), $identical[0]);
        self::assertMatchesRegularExpression('/ — ×499 more in the 60s window from \d\d:\d\d:\d\d: RuntimeException: Synthetic identical fault$/', $identical[1]);
    }
}
