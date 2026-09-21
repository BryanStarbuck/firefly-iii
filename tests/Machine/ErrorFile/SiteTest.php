<?php

/*
 * SiteTest.php
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
use FireflyIII\Machine\MachineException;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;
use Throwable;

/**
 * pm/error_err.mdx §7.1, R4, R5, R6 and the §15 SiteTest row: rethrow() throws an object === the
 * input (a MachineException keeps its class, code and status()) and writes exactly one record; a
 * throwable is written once however many sites see it; expected() writes nothing unless VERBOSE;
 * tryOr() returns the fallback and writes one ERROR; for() is memoised.
 *
 * @internal
 */
#[CoversNothing]
final class SiteTest extends MachineTestCase
{
    use ErrorFileSandbox;

    private const string WHERE = 'tests/Machine/ErrorFile/SiteTest.php';

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

    public function testRethrowThrowsTheSameObjectAndWritesOneRecord(): void
    {
        $e = MachineException::internal('Synthetic internal fault.', null, ['n' => 1]);
        try {
            ErrorFile::for(self::WHERE)->rethrow('rethrowing a synthetic fault', $e, ['op' => 3]);
        } catch (Throwable $caught) {
        }
        self::assertTrue(isset($caught));
        self::assertSame($e, $caught);
        self::assertInstanceOf(MachineException::class, $caught);
        self::assertSame('internal', $caught->errorCode);
        self::assertSame(500, $caught->status());
        $headers = self::headers($this->path);
        self::assertCount(1, $headers);
        self::assertStringStartsWith('[', $headers[0]);
        self::assertStringContainsString('[ERROR] [php-artisan] [tests/Machine/ErrorFile/SiteTest.php] rethrowing a synthetic fault — FireflyIII\Machine\MachineException: Synthetic internal fault. (code=500) {op=3 pid=', $headers[0]);
        self::assertStringNotContainsString('net=', $headers[0], 'an explicit site carries no net');
        self::assertTrue(ErrorFile::isReported($e));

        // R4: the handler net, and a second site, see the same object → still one record
        app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->report($e);
        ErrorFile::for(self::WHERE)->caught('catching it again', $e);
        self::assertCount(1, self::headers($this->path));
    }

    public function testExpectedWritesNothingUnlessVerbose(): void
    {
        ErrorFile::for(self::WHERE)->expected('reading an optional synthetic file', new RuntimeException('Synthetic missing file'));
        self::assertSame([], self::lines($this->path));
        config(['errorfile.verbose' => true]);
        ErrorFile::for(self::WHERE)->expected('reading an optional synthetic file', new RuntimeException('Synthetic missing file'));
        $headers = self::headers($this->path);
        self::assertCount(1, $headers);
        self::assertStringContainsString('[EXPECTED]', $headers[0]);
    }

    public function testWarnCaughtFatalAndTryOr(): void
    {
        $site = ErrorFile::for(self::WHERE);
        self::assertSame($site, ErrorFile::for(self::WHERE), 'memoised per where');
        $site->warn('checking a synthetic condition', null, ['count' => 2]);
        $site->caught('handling a synthetic fault', new RuntimeException('Synthetic caught'));
        $site->fatal('dying of a synthetic fault', new RuntimeException('Synthetic fatal'));
        self::assertSame(['fallback'], $site->tryOr('reading a synthetic value', static function (): array {
            throw new RuntimeException('Synthetic tryOr');
        }, ['fallback']));
        self::assertSame(7, $site->tryOr('reading a synthetic value', static fn (): int => 7, 0));
        $headers = self::headers($this->path);
        self::assertCount(4, $headers, implode("\n", $headers));
        self::assertStringContainsString('[WARN] [php-artisan] [tests/Machine/ErrorFile/SiteTest.php] checking a synthetic condition {count=2 pid=', $headers[0]);
        self::assertStringContainsString('[ERROR]', $headers[1]);
        self::assertStringContainsString('[FATAL]', $headers[2]);
        self::assertStringContainsString('— RuntimeException: Synthetic tryOr', $headers[3]);
    }

    public function testLedgerAndSecretDataNeverReachTheFile(): void
    {
        ErrorFile::for(self::WHERE)->caught('storing a synthetic row', new RuntimeException('Synthetic 4211.08 for someone@example.test'), ['amount' => '4211.08', 'api_key' => str_repeat('0', 64), 'row' => 3]);
        $raw = (string) file_get_contents($this->path);
        self::assertStringNotContainsString('4211.08', $raw);
        self::assertStringNotContainsString('someone@example.test', $raw);
        self::assertStringNotContainsString(str_repeat('0', 64), $raw);
        self::assertStringContainsString('amount="[ledger-field refused]" api_key=[redacted] row=3', $raw);
    }

    public function testNoPathMeansNothingIsWritten(): void
    {
        ErrorFile::usePath(null);
        self::assertNull(ErrorFile::path());
        ErrorFile::for(self::WHERE)->caught('handling a synthetic fault', new RuntimeException('Synthetic'));
        self::assertFileDoesNotExist($this->path);
        self::assertDirectoryDoesNotExist(dirname($this->path));
    }
}
