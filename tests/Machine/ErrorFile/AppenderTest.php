<?php

/*
 * AppenderTest.php
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

use FireflyIII\Machine\ErrorFile\Appender;
use FireflyIII\Machine\ErrorFile\FoldState;
use FireflyIII\Machine\ErrorFile\Level;
use FireflyIII\Machine\ErrorFile\LineFormat;
use FireflyIII\Machine\ErrorFile\Record;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.4, AC 2 and the §15 AppenderTest row: lazy 0600/0700 for error.err and
 * error.fold; the process umask unchanged afterwards; rotation at max_bytes 2048 with backups 2
 * (no .3); the inode reopen; a held lock costs at most ~200 ms and the record is still written; an
 * unwritable path gives one stderr line through the fallback seam, no throw and no second line.
 *
 * @internal
 */
#[CoversNothing]
final class AppenderTest extends MachineTestCase
{
    use ErrorFileSandbox;

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

    public function testLazyModesAndTheUmaskIsRestored(): void
    {
        $old = umask(0o022);

        try {
            self::assertDirectoryDoesNotExist(dirname($this->path));
            self::assertTrue(Appender::append($this->path, self::line('first')));
            self::assertSame(0o022, umask(), 'the umask is restored after a write');
            self::assertSame(0o700, fileperms(dirname($this->path)) & 0o777);
            self::assertSame(0o600, fileperms($this->path) & 0o777);

            // the sidecar, first file in a fresh directory
            $other = $this->sandbox.'/fresh/error.err';
            $state = FoldState::open($other, 1_790_016_062_118);
            self::assertNotNull($state);
            $state->saveAndClose();
            self::assertSame(0o022, umask(), 'the umask is restored after the sidecar');
            self::assertSame(0o700, fileperms(dirname($other)) & 0o777);
            self::assertSame(0o600, fileperms($this->sandbox.'/fresh/error.fold') & 0o777);
            self::assertFileDoesNotExist($other, 'opening the sidecar does not create error.err');

            // a file created by "upstream" afterwards keeps the usual mode
            $upstream = $this->sandbox.'/upstream.log';
            file_put_contents($upstream, 'x');
            self::assertSame(0o644, fileperms($upstream) & 0o777);
        } finally {
            umask($old);
        }
    }

    public function testRotationKeepsTheConfiguredBackupsAndNeverMore(): void
    {
        config(['errorfile.max_bytes' => 2048, 'errorfile.backups' => 2]);
        for ($i = 0; $i < 60; ++$i) {
            self::assertTrue(Appender::append($this->path, self::line(sprintf('record %02d %s', $i, str_repeat('x', 200)))));
        }
        self::assertFileExists($this->path);
        self::assertFileExists($this->path.'.1');
        self::assertFileExists($this->path.'.2');
        self::assertFileDoesNotExist($this->path.'.3');
        foreach (['', '.1', '.2'] as $suffix) {
            self::assertLessThan(2048 + 400, filesize($this->path.$suffix));
            self::assertSame(0o600, fileperms($this->path.$suffix) & 0o777);
            foreach (self::lines($this->path.$suffix) as $line) {
                self::assertStringStartsWith('[', $line);
            }
        }
        self::assertStringContainsString('record 59', (string) file_get_contents($this->path), 'the newest record is in the live file');
    }

    public function testFiveMegabytesTimesFiveByDefault(): void
    {
        self::assertSame(5_242_880, config('errorfile.max_bytes'));
        self::assertSame(5, config('errorfile.backups'));
        self::assertSame(200, config('errorfile.lock_wait_ms'));
    }

    public function testAnotherProcessRotatingUnderUsIsNoticedByTheInodeCheck(): void
    {
        self::assertTrue(Appender::append($this->path, self::line('before')));
        // A child takes the lock, waits until we are spinning on it, then rotates the file away
        // (error.err → error.err.1) and releases. Our open handle then points at .1: the inode
        // check must reopen error.err and write the record there.
        $script = sprintf(
            '$h = fopen(%1$s, "ab"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(120000); rename(%1$s, %1$s . ".1"); flock($h, LOCK_UN); fclose($h);',
            var_export($this->path, true)
        );
        [$proc, $pipes] = self::inline($script);
        self::assertSame("locked\n", fgets($pipes[1]));
        self::assertTrue(Appender::append($this->path, self::line('after the rotation')));
        self::finish($proc, $pipes);
        self::assertStringContainsString('after the rotation', (string) file_get_contents($this->path));
        self::assertStringNotContainsString('after the rotation', (string) file_get_contents($this->path.'.1'));
        self::assertStringContainsString('before', (string) file_get_contents($this->path.'.1'));
    }

    public function testAHeldLockCostsAtMostTheBoundedWaitAndTheRecordIsStillWritten(): void
    {
        self::assertTrue(Appender::append($this->path, self::line('seed')));
        $script         = sprintf('$h = fopen(%s, "ab"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(1500000);', var_export($this->path, true));
        [$proc, $pipes] = self::inline($script);
        self::assertSame("locked\n", fgets($pipes[1]));
        $batch = '';
        for ($i = 0; $i < 5; ++$i) {
            $batch .= LineFormat::record(new Record('2026-09-21T00:00:00.000Z', Level::Error, 'php-web', 'app/X.php', 'writing unlocked', 'RuntimeException: big '.$i, '', array_fill(0, 12, 'at A->b (app/A.php:1) '.str_repeat('y', 400))));
        }
        $start = hrtime(true);
        self::assertTrue(Appender::append($this->path, $batch));
        $ms = (hrtime(true) - $start) / 1e6;
        self::finish($proc, $pipes);
        self::assertGreaterThanOrEqual(150, $ms, 'it did wait for the lock');
        self::assertLessThan(450, $ms, 'a held lock delays a request by at most ~200 ms (AC 6)');
        $content = (string) file_get_contents($this->path);
        for ($i = 0; $i < 5; ++$i) {
            self::assertStringContainsString('RuntimeException: big '.$i, $content);
        }
        foreach (self::lines($this->path) as $line) {
            self::assertTrue(str_starts_with($line, '[') || str_starts_with($line, '    '), 'no torn line: '.substr($line, 0, 60));
        }
    }

    public function testAHeldLockIsPaidOnceAcrossManyAppends(): void
    {
        self::assertTrue(Appender::append($this->path, self::line('seed')));
        $script         = sprintf('$h = fopen(%s, "ab"); flock($h, LOCK_EX); fwrite(STDOUT, "locked\n"); fflush(STDOUT); usleep(3000000);', var_export($this->path, true));
        [$proc, $pipes] = self::inline($script);
        self::assertSame("locked\n", fgets($pipes[1]));
        $start = hrtime(true);
        for ($i = 0; $i < 50; ++$i) {
            self::assertTrue(Appender::append($this->path, self::line('held '.$i)));
        }
        $ms = (hrtime(true) - $start) / 1e6;
        self::finish($proc, $pipes);
        self::assertLessThan(450, $ms, 'the timeout is sticky: 50 appends under a held lock cost one bounded wait');
        $content = (string) file_get_contents($this->path);
        for ($i = 0; $i < 50; ++$i) {
            self::assertStringContainsString('held '.$i."\n", $content);
        }
        // once the holder is gone, the next append locks again
        self::assertTrue(Appender::append($this->path, self::line('after')));
        self::assertStringContainsString('after', (string) file_get_contents($this->path));
    }

    public function testAnUnwritablePathGivesOneStderrLineAndNeverThrows(): void
    {
        $blocker = $this->sandbox.'/blocker';
        file_put_contents($blocker, 'a file where the directory should be');
        $lines = [];
        Appender::useFallback(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $path = $blocker.'/error.err';
        self::assertFalse(Appender::append($path, self::line('one')));
        self::assertFalse(Appender::append($path, self::line('two')));
        self::assertCount(1, $lines, 'one stderr line per process, then silence');
        self::assertStringContainsString('firefly error file: could not write', $lines[0]);
        self::assertStringEndsWith("\n", $lines[0]);
        self::assertNull(FoldState::open($path, 1_790_016_062_118), 'the sidecar fails closed too');
    }

    public function testAppendingNothingTouchesNothing(): void
    {
        self::assertTrue(Appender::append($this->path, ''));
        self::assertDirectoryDoesNotExist(dirname($this->path));
    }

    private static function line(string $message): string
    {
        return LineFormat::record(new Record('2026-09-21T00:00:00.000Z', Level::Error, 'php-artisan', 'app/X.php', 'testing the appender', 'RuntimeException: '.$message));
    }

    /**
     * A plain PHP child (no application) running $code.
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    private static function inline(string $code): array
    {
        $proc = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start the lock holder');
        }
        fclose($pipes[0]);

        return [$proc, $pipes];
    }

    /**
     * @param resource             $proc
     * @param array<int, resource> $pipes
     */
    private static function finish($proc, array $pipes): void
    {
        proc_terminate($proc);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
}
