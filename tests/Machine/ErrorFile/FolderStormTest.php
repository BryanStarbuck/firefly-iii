<?php

/*
 * FolderStormTest.php
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
 * pm/error_err.mdx §4.7 (L1), §10 "Storms in one request", AC 5 (second half): 10,000 identical
 * faults in one process are one line and one L2 write, cost under 50 ms, and become one summary
 * after the fake clock passes 60 s.
 *
 * This drives Folder directly; the `Log::error` variant arrives with the LogListener (§16.2 step 5).
 *
 * @internal
 */
#[CoversNothing]
final class FolderStormTest extends MachineTestCase
{
    use ErrorFileSandbox;

    private const int T0 = 1_790_016_062_118;

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

    public function testTenThousandIdenticalFaultsAreOneLineAndOneSidecarWrite(): void
    {
        $record = new Record('2026-09-21T18:41:02.118Z', Level::Error, 'php-artisan', 'app/Console/Commands/Correction/CorrectsAmounts.php:455', 'running artisan correction:amounts', 'Log::error: Could not find source OR destination for journal #101 .', data: ['net' => 'log']);
        self::assertSame('written', Folder::admit($this->path, $record, self::T0));
        $fold = dirname($this->path).'/error.fold';
        self::assertFileExists($fold);
        unlink($fold);   // any further L2 write during the storm would recreate it

        $start = hrtime(true);
        for ($i = 1; $i < 10_000; ++$i) {
            if ('folded' !== Folder::admit($this->path, $record, self::T0 + intdiv($i, 1000))) {
                self::fail('repeat '.$i.' was not folded in L1');
            }
        }
        $ms = (hrtime(true) - $start) / 1e6;

        self::assertLessThan(50, $ms, sprintf('the storm adds under 50 ms (§10); took %.1f ms', $ms));
        self::assertFileDoesNotExist($fold, 'zero I/O for a repeat: no L2 write during the storm');
        self::assertCount(1, self::headers($this->path), 'one line');
        self::assertSame(['keys' => 1, 'owed' => 9999], Folder::stats());

        // The shutdown flush is the one L2 write. The test removed the sidecar, so L2 no longer holds
        // this window: the flush writes the window's own summary and never opens a new window.
        Folder::flushOwed(self::T0 + 10_000);
        $headers = self::headers($this->path);
        self::assertCount(2, $headers);
        self::assertStringContainsString('×9999 more in the 60s window from 18:41:02: Log::error: Could not find source OR destination', $headers[1]);
        self::assertSame([], json_decode((string) file_get_contents($fold), true)['k']);
    }

    public function testTheStormSummaryAfterTheWindow(): void
    {
        $record = new Record('2026-09-21T18:41:02.118Z', Level::Error, 'php-artisan', 'app/X.php:1', 'running artisan firefly-iii:synthetic', 'RuntimeException: Synthetic storm', data: ['net' => 'log']);
        for ($i = 0; $i < 10_000; ++$i) {
            Folder::admit($this->path, $record, self::T0 + intdiv($i, 1000));
        }
        Folder::flushOwed(self::T0 + 10_000);
        self::assertCount(1, self::headers($this->path), 'the flush adds to the open window and writes nothing');
        $entry = array_values(json_decode((string) file_get_contents(dirname($this->path).'/error.fold'), true)['k'])[0];
        self::assertSame(9999, $entry['n']);

        Folder::resetForTests();
        Folder::admit($this->path, new Record('2026-09-21T18:42:03.118Z', Level::Error, 'php-artisan', 'app/Y.php:2', 'running artisan firefly-iii:synthetic', 'RuntimeException: Something else'), self::T0 + 61_000);
        $headers = self::headers($this->path);
        self::assertCount(3, $headers);
        self::assertSame('[2026-09-21T18:42:03.118Z] [ERROR] [php-artisan] [app/X.php:1] running artisan firefly-iii:synthetic — ×9999 more in the 60s window from 18:41:02: RuntimeException: Synthetic storm', $headers[1]);
    }

    public function testL1IsCappedAndAnEvictedCountIsNotLost(): void
    {
        config(['errorfile.l1_max_keys' => 2, 'errorfile.fold_max_keys' => 1000]);
        $a = new Record('t', Level::Error, 'php-artisan', 'app/A.php', 'doing a', 'RuntimeException: Synthetic alpha');
        Folder::admit($this->path, $a, self::T0);
        Folder::admit($this->path, $a, self::T0 + 1);
        Folder::admit($this->path, $a, self::T0 + 2);
        Folder::admit($this->path, new Record('t', Level::Error, 'php-artisan', 'app/B.php', 'doing b', 'RuntimeException: Synthetic beta'), self::T0 + 3);
        Folder::admit($this->path, new Record('t', Level::Error, 'php-artisan', 'app/C.php', 'doing c', 'RuntimeException: Synthetic gamma'), self::T0 + 4);
        self::assertSame(['keys' => 2, 'owed' => 2], Folder::stats(), 'alpha was evicted with its owed count kept for the flush');
        Folder::flushOwed(self::T0 + 5);
        Folder::resetForTests();
        Folder::admit($this->path, new Record('t', Level::Error, 'php-artisan', 'app/D.php', 'doing d', 'RuntimeException: Synthetic delta'), self::T0 + 70_000);
        self::assertStringContainsString('×2 more in the 60s window from 18:41:02: RuntimeException: Synthetic alpha', implode("\n", self::headers($this->path)));
    }
}
