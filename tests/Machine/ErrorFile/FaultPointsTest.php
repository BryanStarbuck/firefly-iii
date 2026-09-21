<?php

/*
 * FaultPointsTest.php
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

use FireflyIII\Machine\Audit;
use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Ingest\MapFile;
use FireflyIII\Machine\Ingest\Staging;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\MachinePlaneServiceProvider;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\CoversNothing;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §8.1–§8.2 and §18.1 H1, H18, H19: the fork-owned PHP fault points converted in
 * §16.2 step 6 — the audit append and the plane's arming report (Pattern 1, `caught`/`warn`), the
 * designed fallbacks (Pattern 2, `expected`: nothing unless VERBOSE), and the canonical hint.
 *
 * @internal
 */
#[CoversNothing]
final class FaultPointsTest extends MachineTestCase
{
    use ErrorFileSandbox;

    private const string H = 'The detail is in ~/T/firefly/error.err — ffx logs --errors';

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

    public function testAFailedAuditAppendIsCaught(): void
    {
        mkdir($this->sandbox.'/state/machine.audit', 0o700, true);   // a directory where the file should be
        Audit::line(Request::create('/machine/v1/categories', 'POST'), ['route' => 'POST /categories', 'tier' => 'write', 'ok' => true]);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertMatchesRegularExpression('/\[ERROR\] \[php-artisan\] \[app\/Machine\/Audit\.php\] appending to the plane audit log — ErrorException: /u', $headers[0]);
    }

    public function testAPlaneThatStaysUnarmedIsAWarnWithItsProblem(): void
    {
        chmod($this->credentialsPath(), 0o644);   // readable by others: refused, so the plane stays unarmed
        CredentialsFile::forget();
        (new MachinePlaneServiceProvider(app()))->armPlane();
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertMatchesRegularExpression('/\[WARN\] \[php-artisan\] \[app\/Machine\/MachinePlaneServiceProvider\.php\] arming the machine plane \{problem=".+" pid=\d+\}$/u', $headers[0]);

        (new MachinePlaneServiceProvider(app()))->armPlane();
        self::assertCount(1, self::headers($this->path), 'once per announce signature');
    }

    public function testAnArmedPlaneWritesNothing(): void
    {
        (new MachinePlaneServiceProvider(app()))->armPlane();
        self::assertSame([], self::lines($this->path));
    }

    public function testADesignedFallbackIsExpected(): void
    {
        mkdir($this->sandbox.'/statements', 0o700, true);
        $staging = new Staging((string) realpath($this->sandbox.'/statements'));
        $staging->write(MapFile::FILE, '{not json');
        self::assertSame([], MapFile::read($staging));
        self::assertSame([], self::lines($this->path), 'expected() writes nothing without VERBOSE (R6)');

        config(['errorfile.verbose' => true]);
        self::assertSame([], MapFile::read($staging), 'the fallback value is unchanged');
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertStringContainsString('[EXPECTED] [php-artisan] [app/Machine/Ingest/MapFile.php] reading the staged map file — JsonException: ', $headers[0]);
    }

    public function testTheInternalHintNamesTheErrorFile(): void
    {
        self::assertSame(self::H, MachineException::internal()->hint);
        self::assertSame(MachineException::internal()->status(), MachineException::internal('x', null, [], new \RuntimeException('cause'))->status());
    }
}
