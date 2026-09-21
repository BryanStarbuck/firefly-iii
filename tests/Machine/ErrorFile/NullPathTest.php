<?php

/*
 * NullPathTest.php
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
use FireflyIII\Machine\ErrorFile\Paths;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx R13, §3.1 rule 1, AC 11: under PHPUnit the path is null — nothing is written —
 * unless a test set one explicitly (Paths::usePath(), the seam ErrorFile::usePath() delegates to)
 * or a spawned child got FIREFLY_ERROR_FILE_TEST_PATH. The env-derived `errorfile.path`
 * (FIREFLY_ERROR_FILE) is ignored, even when a shell exported it to a canary path.
 *
 * @internal
 */
#[CoversNothing]
final class NullPathTest extends MachineTestCase
{
    use ErrorFileSandbox;

    /** @var array<string, false|string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clearErrorFile();
        foreach (['FIREFLY_ERROR_FILE', 'FIREFLY_ERROR_FILE_TEST_PATH'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);

                continue;
            }
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name]    = $value;
            $_SERVER[$name] = $value;
        }
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testNoPathUnderTestsEvenWithTheEnvExportedToACanary(): void
    {
        $canary = $this->sandbox.'/canary/error.err';
        putenv('FIREFLY_ERROR_FILE='.$canary);
        $_ENV['FIREFLY_ERROR_FILE']    = $canary;
        $_SERVER['FIREFLY_ERROR_FILE'] = $canary;
        config(['errorfile.path' => $canary, 'errorfile.test_path' => null]);

        self::assertNull(Paths::errorFile());
        self::assertNull(Paths::foldFile());
        self::assertNull(Paths::coverageFile());
        self::assertFileDoesNotExist($canary);
        self::assertDirectoryDoesNotExist(dirname($canary));
    }

    public function testTheInProcessSeamAndTheChildSeam(): void
    {
        config(['errorfile.path' => $this->sandbox.'/ignored/error.err']);
        Paths::usePath($this->sandbox.'/seam/error.err');
        self::assertSame($this->sandbox.'/seam/error.err', Paths::errorFile());
        self::assertSame($this->sandbox.'/seam/error.fold', Paths::foldFile());
        self::assertSame($this->sandbox.'/seam/error_file_coverage.json', Paths::coverageFile());
        Paths::usePath(null);
        self::assertNull(Paths::errorFile());
        config(['errorfile.test_path' => $this->sandbox.'/child/error.err']);
        self::assertSame($this->sandbox.'/child/error.err', Paths::errorFile());
        config(['errorfile.test_path' => '  ']);
        self::assertNull(Paths::errorFile());
    }

    public function testNothingIsWrittenWhenThePathIsNull(): void
    {
        $canary = $this->sandbox.'/canary/error.err';
        config(['errorfile.path' => $canary]);
        $path = Paths::errorFile();
        self::assertNull($path);
        // The pipeline's step 2 (§4.3) returns on a null path; nothing below it can run. Prove the
        // library writes nowhere on its own: a fold with no explicit path reaches no file.
        Folder::flushOwed();
        self::assertFileDoesNotExist($canary);
        self::assertSame([], glob($this->sandbox.'/*/error.*') ?: []);
    }

    public function testASpawnedChildHonoursOnlyTheTestPath(): void
    {
        $canary = $this->sandbox.'/canary/error.err';
        [$proc, $pipes]    = self::spawnChild(['path'], $this->childEnv(null, ['FIREFLY_ERROR_FILE' => $canary]));
        [$code, $out, $err] = self::waitChild($proc, $pipes);
        self::assertSame(0, $code, $err);
        self::assertSame('NULL', trim($out), 'a child with FIREFLY_ERROR_FILE but no test path writes nothing');

        $sandboxed = $this->sandbox.'/child/error.err';
        [$proc, $pipes]    = self::spawnChild(['path'], $this->childEnv($sandboxed));
        [$code, $out, $err] = self::waitChild($proc, $pipes);
        self::assertSame(0, $code, $err);
        self::assertSame($sandboxed, trim($out));
        self::assertFileDoesNotExist($canary);
    }

    public function testThePhpunitConfigForceBlanksBothVariables(): void
    {
        $xml = (string) file_get_contents(__DIR__.'/../../../phpunit.machine.xml');
        foreach (['env', 'server'] as $tag) {
            foreach (['FIREFLY_ERROR_FILE', 'FIREFLY_ERROR_FILE_TEST_PATH'] as $name) {
                self::assertStringContainsString(sprintf('<%s name="%s" value="" force="true"/>', $tag, $name), $xml);
            }
        }
    }

    public function testOutsideTestsTheDefaultIsUnderHome(): void
    {
        // Rule 3 and 4 of §3.1 are exercised through expand(); the unit-test branch is covered above.
        self::assertSame($this->sandbox.'/x', Paths::expand($this->sandbox.'/x'));
        $home = $_SERVER['HOME'] ?? getenv('HOME');
        if (is_string($home) && '' !== $home) {
            self::assertSame(rtrim($home, '/').'/T/firefly/error.err', Paths::expand('~/'.Paths::DEFAULT_RELATIVE));
        }
    }
}
