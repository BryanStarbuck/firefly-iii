<?php

/*
 * FatalChildTest.php
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

use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §15, the FatalChildTest row: uncatchable fatals in a real child process are still
 * written, as `[FATAL] … Symfony\Component\ErrorHandler\Error\FatalError … {net=shutdown …}`, through
 * HandleExceptions::handleShutdown() → MachineExceptionHandler::report() (N2, R9):
 *
 *  - the artisan canary `fatal` (§13.4 C11): an eval()'d redeclaration of an existing function, an
 *    E_COMPILE_ERROR — it refuses to exist without FIREFLY_ERROR_FILE_CANARY=1, so the child env
 *    passes it (§15 "Child processes");
 *  - an out-of-memory;
 *  - an out-of-memory while Reporter::$busy is set (a stale busy flag never stops a FATAL, §4.7).
 *
 * Each child gets the explicit sandbox env (both error-file variables, a sandbox database,
 * credentials file and state directory), so nothing here reaches anything the operator owns.
 *
 * @internal
 */
#[CoversNothing]
final class FatalChildTest extends MachineTestCase
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

    public function testTheArtisanCanaryFatalIsOneFatalLine(): void
    {
        [$code, , $err] = $this->runChild(
            [PHP_BINARY, 'artisan', 'firefly-machine:error-file-canary', 'fatal', '--no-interaction'],
            $this->childEnv($this->path, ['FIREFLY_ERROR_FILE_CANARY' => '1'])
        );
        self::assertNotSame(0, $code, 'a compile-time fatal ends the process: '.$err);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertMatchesRegularExpression(self::fatal('Cannot redeclare function strlen\(\)'), $headers[0]);
    }

    public function testTheArtisanCanaryRefusesWithoutTheSwitch(): void
    {
        [$code] = $this->runChild(
            [PHP_BINARY, 'artisan', 'firefly-machine:error-file-canary', 'fatal', '--no-interaction'],
            $this->childEnv($this->path, ['FIREFLY_ERROR_FILE_CANARY' => ''])
        );
        self::assertNotSame(0, $code, 'the command does not exist without the canary switch');
        self::assertSame([], array_values(array_filter(self::headers($this->path), static fn (string $l): bool => str_contains($l, '[FATAL]'))));
    }

    public function testAnOutOfMemoryIsOneFatalLine(): void
    {
        [$code, , $err] = $this->runChild([PHP_BINARY, __DIR__.'/Fixtures/error_file_child.php', 'oom'], $this->childEnv($this->path));
        self::assertNotSame(0, $code, $err);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertMatchesRegularExpression(self::fatal('Allowed memory size of'), $headers[0]);
    }

    public function testAnOutOfMemoryWhileBusyIsStillWritten(): void
    {
        [$code, , $err] = $this->runChild([PHP_BINARY, __DIR__.'/Fixtures/error_file_child.php', 'oom', 'busy'], $this->childEnv($this->path));
        self::assertNotSame(0, $code, $err);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", self::lines($this->path)));
        self::assertMatchesRegularExpression(self::fatal('Allowed memory size of'), $headers[0]);
    }

    /** One [FATAL] [php-artisan] line headed by FatalError: $message, with net=shutdown. */
    private static function fatal(string $message): string
    {
        return '/^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] \[FATAL\] \[php-artisan\] \[[^\]]+\] .+? — '
            .'Symfony\\\\Component\\\\ErrorHandler\\\\Error\\\\FatalError: '.$message.'.*\{net=shutdown pid=\d+\}$/u';
    }

    /**
     * @param list<string>          $cmd
     * @param array<string, string> $env
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runChild(array $cmd, array $env): array
    {
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start the fatal child');
        }
        fclose($pipes[0]);

        return self::waitChild($proc, $pipes);
    }
}
