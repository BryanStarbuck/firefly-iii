<?php

/*
 * LogListenerTest.php
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

use ErrorException;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\ErrorFile\Folder;
use FireflyIII\Machine\ErrorFile\Normalizer;
use FireflyIII\Machine\ErrorFile\Reporter;
use FireflyIII\Machine\ErrorFile\RequestState;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\ErrorFile\Fixtures\LogChannelSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.6 — the log net (N4) through the real Log facade and MessageLogged: every tier
 * and every drop, the render duplicate, the busy gate, VERBOSE, and the §15 FolderStormTest row's
 * `Log::error` variant (10,000 identical lines in one request → one line, then one summary).
 *
 * @internal
 */
#[CoversNothing]
final class LogListenerTest extends MachineTestCase
{
    use ErrorFileSandbox;
    use LogChannelSandbox;

    private const int T0 = 1_790_016_062_118;

    private string $path = '';

    private int $now = self::T0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->resetErrorFile();
        $this->useSandboxLogChannel('loglistener');
        $this->now = self::T0;
        ErrorFile::useClock(fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        ErrorFile::useClock(null);
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testABareErrorIsAT3Warn(): void
    {
        Log::error('Could not find currency with code "XTS".');
        $headers = self::headers($this->path);
        self::assertCount(1, $headers);
        self::assertMatchesRegularExpression('/^\[[^\]]+\] \[WARN\] \[php-artisan\] \[tests\/Machine\/ErrorFile\/LogListenerTest\.php:\d+\] \S.* — Log::error: Could not find currency with code "XTS"\. \{net=log pid=\d+\}$/u', $headers[0]);
        self::assertCount(1, self::lines($this->path), 'a bare line has no stack');
    }

    public function testEveryErrorLevelIsAFaultCandidate(): void
    {
        Log::critical('Synthetic critical');
        Log::alert('Synthetic alert');
        Log::emergency('Synthetic emergency');
        $headers = self::headers($this->path);
        self::assertCount(3, $headers);
        self::assertStringContainsString('Log::critical: Synthetic critical', $headers[0]);
        self::assertStringContainsString('Log::alert: Synthetic alert', $headers[1]);
        self::assertStringContainsString('Log::emergency: Synthetic emergency', $headers[2]);
    }

    public function testAnExceptionInContextIsAT2Error(): void
    {
        $e = new RuntimeException('Synthetic logged exception');
        Log::error('whatever the message says', ['exception' => $e, 'amount' => '4211.08']);
        Log::error('logged again', ['exception' => $e]);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, 'the same throwable is written once (R4)');
        self::assertMatchesRegularExpression('/\[ERROR\] \[php-artisan\] \[tests\/Machine\/ErrorFile\/LogListenerTest\.php:\d+\] .* — RuntimeException: Synthetic logged exception \{net=log pid=\d+\}$/u', $headers[0]);
        self::assertStringNotContainsString('4211.08', (string) file_get_contents($this->path), 'no other context key is read');
        self::assertTrue(ErrorFile::isReported($e));
    }

    public function testAThrowableTheHandlerAlreadyWroteIsNotWrittenAgain(): void
    {
        $e = new RuntimeException('Synthetic handler fault');
        ErrorFile::for('tests/Machine/ErrorFile/LogListenerTest.php')->caught('doing a synthetic thing', $e);
        Log::error($e->getMessage(), ['exception' => $e]);   // what parent::report() logs next
        self::assertCount(1, self::headers($this->path));
    }

    public function testTheDropsWriteNothing(): void
    {
        Log::error('#0 /synthetic/path.php(12): Synthetic->frame()');
        Log::error('Exception is: {"class":"RuntimeException","post":"amount=4211.08"}');
        Log::error('Duplicate of transaction #17.');
        Log::error('Could not parse search: "amount:>4211.08".');
        Log::warning('Synthetic warning without VERBOSE');
        Log::notice('Synthetic notice without VERBOSE');
        Log::info('Synthetic info');
        Log::error('Synthetic deprecation', ['exception' => new ErrorException('Synthetic deprecated', 0, E_DEPRECATED)]);
        Log::warning('Synthetic thing is deprecated in /x.php on line 12');
        self::assertSame([], self::lines($this->path));
    }

    public function testTheRenderDuplicateIsDropped(): void
    {
        RequestState::current()?->addWritten(Normalizer::message('Synthetic rendered fault 42'));
        Log::error('Synthetic rendered fault 42');
        self::assertSame([], self::lines($this->path), 'Handler.php:216 echo of a throwable handlerReport() saw');
    }

    public function testNothingIsWrittenWhileTheLibraryIsBusy(): void
    {
        Reporter::$busy = true;

        try {
            Log::error('Synthetic line during a write');
        } finally {
            Reporter::$busy = false;
        }
        self::assertSame([], self::lines($this->path));
    }

    public function testPayloadsAreWithheld(): void
    {
        Log::error('The body of the error response is: {"secret":"x"}');
        Log::error('Synthetic dump {"a":1,"b":2,"c":3}');
        $headers = self::headers($this->path);
        self::assertCount(2, $headers);
        self::assertStringContainsString('Log::error: The body of the error response is: [withheld] {net=log', $headers[0]);
        self::assertStringContainsString('Log::error: [structured payload withheld] {net=log', $headers[1]);
    }

    public function testVerboseAddsExpectedAndT4(): void
    {
        config(['errorfile.verbose' => true]);
        Log::error('Duplicate of transaction #17.');
        Log::warning('Synthetic warning under VERBOSE');
        Log::notice('Synthetic notice under VERBOSE');
        Log::info('Synthetic info is never written');
        Log::warning('#0 /synthetic/path.php(12): Synthetic->frame()');
        $headers = self::headers($this->path);
        self::assertCount(3, $headers, implode("\n", $headers));
        self::assertStringContainsString('[EXPECTED] [php-artisan]', $headers[0]);
        self::assertStringContainsString('Log::error: Duplicate of transaction #17.', $headers[0]);
        self::assertStringContainsString('[WARN] [php-artisan]', $headers[1]);
        self::assertStringContainsString('Log::warning: Synthetic warning under VERBOSE', $headers[1]);
        self::assertStringContainsString('Log::notice: Synthetic notice under VERBOSE', $headers[2]);
    }

    public function testTheFastPathNeverFoldsTwoSitesTogether(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            Log::error('Synthetic line from two sites');
            Log::error('Synthetic line from two sites');
        }
        self::assertCount(2, self::headers($this->path), 'one line per call site; the repeats are folded');
        self::assertSame(['keys' => 2, 'owed' => 4], Folder::stats());
    }

    public function testTheFastPathHonoursALaterRenderDuplicate(): void
    {
        $this->stormLine();
        $this->stormLine();
        self::assertSame(['keys' => 1, 'owed' => 1], Folder::stats());
        RequestState::current()?->addWritten(Normalizer::message('Could not find source OR destination for journal #101 .'));
        $this->stormLine();
        self::assertSame(['keys' => 1, 'owed' => 1], Folder::stats(), 'the written-set changed: the full path runs and drops it');
    }

    public function testTenThousandIdenticalLogErrorsAreOneLineThenOneSummary(): void
    {
        // upstream's own channel writes nothing at this level, so the timing is the net's alone
        $this->useSandboxLogChannel('loglistener_storm', 'emergency');
        $fold = dirname($this->path).'/error.fold';
        $this->stormLine();
        self::assertCount(1, self::headers($this->path));
        self::assertFileExists($fold);
        unlink($fold);   // any L2 write during the storm would recreate it

        // the storm: 9,999 more identical lines fold in L1 with zero I/O
        for ($i = 1; $i < 10_000; ++$i) {
            $this->stormLine();
        }
        self::assertFileDoesNotExist($fold, 'zero I/O for a repeat: no L2 write during the storm');
        self::assertCount(1, self::headers($this->path), 'one line');
        self::assertSame(['keys' => 1, 'owed' => 9999], Folder::stats());

        // AC 5: under 50 ms added. Each round times the same 10,000 calls with the net returning at
        // its busy gate (step 2, the baseline) and then through the fast path; the best round counts,
        // so a machine loaded by parallel suites does not fail the run spuriously.
        $best = INF;
        for ($round = 0; $round < 3; ++$round) {
            Reporter::$busy = true;
            $start          = hrtime(true);
            for ($i = 0; $i < 10_000; ++$i) {
                $this->stormLine();
            }
            $baseline       = (hrtime(true) - $start) / 1e6;
            Reporter::$busy = false;
            $start          = hrtime(true);
            for ($i = 0; $i < 10_000; ++$i) {
                $this->stormLine();
            }
            $best = min($best, (hrtime(true) - $start) / 1e6 - $baseline);
        }
        self::assertLessThan(50, $best, sprintf('10,000 folded Log::error lines added %.1f ms (best of 3)', $best));
        self::assertFileDoesNotExist($fold);
        self::assertCount(1, self::headers($this->path));
        self::assertSame(['keys' => 1, 'owed' => 39_999], Folder::stats());

        // the shutdown flush hands the owed count to L2; the fake clock passes 60 s; the next write
        // from any process sweeps the closed window into its summary
        Folder::flushOwed($this->now);
        $this->now += 61_000;
        Log::error('Synthetic line after the storm');
        $headers = self::headers($this->path);
        self::assertCount(3, $headers, implode("\n", $headers));
        self::assertStringContainsString('×39999 more in the 60s window from ', $headers[1]);
        self::assertStringContainsString(': Log::error: Could not find source OR destination for journal #101 .', $headers[1]);
        self::assertStringContainsString('Log::error: Synthetic line after the storm', $headers[2]);
    }

    /** One call site, so every storm line has the same `where` (and therefore the same fold key). */
    private function stormLine(): void
    {
        Log::error('Could not find source OR destination for journal #101 .');
    }
}
