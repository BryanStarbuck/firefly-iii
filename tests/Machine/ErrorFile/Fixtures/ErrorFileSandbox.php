<?php

/*
 * ErrorFileSandbox.php
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

namespace Tests\Machine\ErrorFile\Fixtures;

use FireflyIII\Machine\ErrorFile\Appender;
use FireflyIII\Machine\ErrorFile\Folder;
use FireflyIII\Machine\ErrorFile\Paths;
use RuntimeException;

/**
 * Shared helpers for the error-file tests (pm/error_err.mdx §15). Every path is inside the
 * per-test sandbox of MachineTestCase; nothing here can reach ~/T/firefly/.
 */
trait ErrorFileSandbox
{
    /** Reset every static of the library and point it at the sandbox. */
    protected function resetErrorFile(): string
    {
        Paths::resetForTests();
        Folder::resetForTests();
        Appender::resetForTests();
        $path = $this->sandbox.'/firefly/error.err';
        Paths::usePath($path);

        return $path;
    }

    protected function clearErrorFile(): void
    {
        Paths::resetForTests();
        Folder::resetForTests();
        Appender::resetForTests();
    }

    /** The error file's lines (header and stack lines), or [] when it does not exist. @return list<string> */
    protected static function lines(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = (string) file_get_contents($path);

        return '' === $raw ? [] : explode("\n", rtrim($raw, "\n"));
    }

    /** Header lines only. @return list<string> */
    protected static function headers(string $path): array
    {
        return array_values(array_filter(self::lines($path), static fn (string $l): bool => str_starts_with($l, '[')));
    }

    /**
     * The explicit child env of §15: a sandbox error file (both variables), a sandbox database,
     * credentials file and state directory. Never the operator's.
     *
     * @return array<string, string>
     */
    protected function childEnv(?string $errorFile, array $extra = []): array
    {
        $db = $this->sandbox.'/child.sqlite';
        if (!is_file($db)) {
            touch($db);
        }
        $env = [
            'PATH'                              => (string) getenv('PATH'),
            'HOME'                              => $this->sandbox.'/home',
            'APP_ENV'                           => 'testing',
            'DB_CONNECTION'                     => 'sqlite',
            'DB_DATABASE'                       => $db,
            'CACHE_STORE'                       => 'array',
            'QUEUE_CONNECTION'                  => 'sync',
            'MAIL_MAILER'                       => 'array',
            'FIREFLY_MACHINE_CREDENTIALS_FILE'  => $this->sandbox.'/credentials/firefly_iii.json',
            'FIREFLY_MACHINE_STATE_DIR'         => $this->sandbox.'/state',
            'FIREFLY_ERROR_FILE'                => $errorFile ?? '',
            'FIREFLY_ERROR_FILE_TEST_PATH'      => $errorFile ?? '',
        ];

        return array_merge($env, $extra);
    }

    /**
     * Start the child script; returns the process handle and its pipes.
     *
     * @param list<string>          $args
     * @param array<string, string> $env
     *
     * @return array{0: resource, 1: array<int, resource>}
     */
    protected static function spawnChild(array $args, array $env): array
    {
        $cmd  = array_merge([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__.'/error_file_child.php'], $args);
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 4), $env);
        if (!is_resource($proc)) {
            throw new RuntimeException('could not start the error-file child');
        }
        fclose($pipes[0]);

        return [$proc, $pipes];
    }

    /**
     * Wait for a child; returns [exit code, stdout, stderr].
     *
     * @param resource              $proc
     * @param array<int, resource>  $pipes
     *
     * @return array{0: int, 1: string, 2: string}
     */
    protected static function waitChild($proc, array $pipes): array
    {
        $out  = (string) stream_get_contents($pipes[1]);
        $err  = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return [$code, $out, $err];
    }
}
