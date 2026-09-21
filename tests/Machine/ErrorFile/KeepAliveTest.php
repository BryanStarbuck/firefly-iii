<?php

/*
 * KeepAliveTest.php
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

use FireflyIII\Machine\ErrorFile\ErrorFileServiceProvider;
use FireflyIII\Machine\ErrorFile\KeepAliveHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\ErrorFile\Fixtures\LogChannelSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.9 boot() item 1, N5, and the §15 KeepAliveTest row: with APP_LOG_LEVEL above
 * error the log net stays alive (a T3 is still written), and upstream's channel output is
 * byte-identical with and without the handler.
 *
 * @internal
 */
#[CoversNothing]
final class KeepAliveTest extends MachineTestCase
{
    use ErrorFileSandbox;
    use LogChannelSandbox;

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

    public function testBootAttachesExactlyOneHandlerToTheDefaultChannel(): void
    {
        $handlers = array_filter($this->monolog()->getHandlers(), static fn ($h): bool => $h instanceof KeepAliveHandler);
        self::assertCount(1, $handlers, 'ErrorFileServiceProvider::boot() pushed it onto the default channel');
        self::assertTrue(ErrorFileServiceProvider::keepLogNetAlive());
        $handlers = array_filter($this->monolog()->getHandlers(), static fn ($h): bool => $h instanceof KeepAliveHandler);
        self::assertCount(1, $handlers, 'attaching again never adds a second one');
    }

    public function testTheHandlerAcceptsErrorAndAboveAndAlwaysBubbles(): void
    {
        $handler = new KeepAliveHandler();
        foreach ([Level::Debug, Level::Info, Level::Notice, Level::Warning] as $below) {
            self::assertFalse($handler->isHandling(self::record($below)), $below->getName());
        }
        foreach ([Level::Error, Level::Critical, Level::Alert, Level::Emergency] as $level) {
            self::assertTrue($handler->isHandling(self::record($level)), $level->getName());
            self::assertFalse($handler->handle(self::record($level)), 'handle() returns false: the record bubbles on');
        }
        // the reason it is not Monolog's NullHandler: that one stops bubbling
        self::assertTrue((new NullHandler())->handle(self::record(Level::Error)));
    }

    public function testAboveTheLogLevelTheLogNetGoesBlindWithoutTheHandler(): void
    {
        $this->useSandboxLogChannel('keepalive_blind', 'critical', false);
        Log::error('Synthetic bare line nobody hears');
        self::assertSame([], self::lines($this->path), 'writeLog() dropped the record before MessageLogged fired');
    }

    public function testAboveTheLogLevelAT3IsStillWritten(): void
    {
        $channelFile = $this->useSandboxLogChannel('keepalive_on', 'critical');
        Log::error('Synthetic bare line above the log level');
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertStringContainsString('[WARN] [php-artisan] [tests/Machine/ErrorFile/KeepAliveTest.php:', $headers[0]);
        self::assertStringContainsString(' — Log::error: Synthetic bare line above the log level {net=log pid=', $headers[0]);
        self::assertFileDoesNotExist($channelFile, 'upstream\'s channel still writes nothing below critical');
    }

    public function testAboveTheLogLevelT1AndT2AreStillWritten(): void
    {
        $this->useSandboxLogChannel('keepalive_t1t2', 'critical');
        report(new RuntimeException('Synthetic reported fault above the log level'));
        Log::error('Synthetic logged exception', ['exception' => new RuntimeException('Synthetic T2 above the log level')]);
        $headers = self::headers($this->path);
        self::assertCount(2, $headers, implode("\n", self::lines($this->path)));
        self::assertStringContainsString('[ERROR] [php-artisan] [tests/Machine/ErrorFile/KeepAliveTest.php:', $headers[0]);
        self::assertStringContainsString('RuntimeException: Synthetic reported fault above the log level {net=report', $headers[0]);
        self::assertStringContainsString('RuntimeException: Synthetic T2 above the log level {net=log', $headers[1]);
    }

    public function testUpstreamOutputIsByteIdenticalWithAndWithoutTheHandler(): void
    {
        $plain = $this->useSandboxLogChannel('keepalive_plain', 'critical', false);
        $this->emitTheSequence();
        $alive = $this->useSandboxLogChannel('keepalive_alive', 'critical');
        self::assertNotEmpty(array_filter($this->monolog()->getHandlers(), static fn ($h): bool => $h instanceof KeepAliveHandler));
        $this->emitTheSequence();

        $a = self::normalise((string) file_get_contents($plain));
        $b = self::normalise((string) file_get_contents($alive));
        self::assertNotSame('', $a);
        self::assertStringContainsString('testing.CRITICAL: Synthetic critical line', $a);
        self::assertStringNotContainsString('Synthetic error line', $a, 'below the channel level');
        self::assertSame($a, $b);
    }

    private function emitTheSequence(): void
    {
        Log::info('Synthetic info line');
        Log::warning('Synthetic warning line');
        Log::error('Synthetic error line');
        Log::critical('Synthetic critical line', ['id' => 7]);
        Log::alert('Synthetic alert line');
        Log::emergency('Synthetic emergency line');
    }

    /** The two runs differ only in their timestamps. */
    private static function normalise(string $text): string
    {
        return (string) preg_replace('/^\[[^\]]+\] /m', '[t] ', $text);
    }

    private static function record(Level $level): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'testing', $level, 'Synthetic record');
    }
}
