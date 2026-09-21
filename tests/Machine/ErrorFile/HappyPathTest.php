<?php

/*
 * HappyPathTest.php
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

use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\ErrorFile\Folder;
use FireflyIII\Machine\ErrorFile\Paths;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\ErrorFile\Fixtures\LogChannelSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx invariant 3 / R3, §10 "Happy path, PHP", M7 and the §15 HappyPathTest row: with a
 * path SET, 100 non-faulting kernel requests (plane GETs, logged-out and logged-in web GETs, each
 * alongside Log::info and Log::debug lines) open the error file zero times. Every open of
 * error.err or error.fold goes through Paths::ensureDir() on its first failure, so a directory that
 * still does not exist afterwards proves no Appender or FoldState open ever happened.
 *
 * @internal
 */
#[CoversNothing]
final class HappyPathTest extends MachineTestCase
{
    use ErrorFileSandbox;
    use LogChannelSandbox;

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetErrorFile();
        $this->path = $this->sandbox.'/sub/error.err';
        ErrorFile::usePath($this->path);
        $this->useSandboxLogChannel('happypath');
    }

    protected function tearDown(): void
    {
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testAHundredNonFaultingRequestsTouchNothing(): void
    {
        self::assertSame($this->path, Paths::errorFile());
        $user = $this->createAuthenticatedUser();
        for ($i = 0; $i < 100; ++$i) {
            Log::info('Synthetic happy-path info line '.$i);
            Log::debug('Synthetic happy-path debug line');
            $status = match ($i % 4) {
                0       => $this->machine('GET', '/capabilities')->getStatusCode(),
                1       => $this->machine('GET', '/health')->getStatusCode(),
                2       => $this->get('/accounts/asset')->getStatusCode(),
                default => $this->actingAs($user)->get('/categories')->getStatusCode(),
            };
            self::assertLessThan(500, $status, sprintf('request %d must not fault', $i));
            if (2 === $i % 4) {
                auth()->logout();
            }
        }
        Folder::flushOwed();

        self::assertSame(['keys' => 0, 'owed' => 0], Folder::stats(), 'nothing was admitted');
        self::assertDirectoryDoesNotExist(dirname($this->path), 'no ensureDir, no error.err, no error.fold');
        self::assertFileDoesNotExist($this->path);
    }
}
