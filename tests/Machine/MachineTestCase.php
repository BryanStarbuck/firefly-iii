<?php

/*
 * MachineTestCase.php
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

namespace Tests\Machine;

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\RouteTable;
use FireflyIII\User;
use Illuminate\Testing\TestResponse;
use Tests\integration\TestCase;

/**
 * The base of every machine-plane test (pm/apis.mdx §18).
 *
 * SAFETY: the database is forced to SQLite in a per-process temp file (never the operator's
 * ledger, whatever .env says), the credentials file and the state directory live in a per-test
 * temp directory, and every machine.* setting is pinned — so no test can read or write
 * ~/.credentials/firefly_iii.json, ~/T/_firefly_iii, or a real database.
 *
 * Helpers:
 *   machine($method, $path, $body, $headers, $server, $host)  a /machine/v1 call from a loopback
 *                                                             peer with the test key
 *   operatorUser()                                            one user, bound as the operator
 *   enableWrites() / enableAdmin()                            the server tier switches
 */
abstract class MachineTestCase extends TestCase
{
    protected string $sandbox    = '';
    protected string $machineKey = '';

    /** @var list<class-string> extra route families this test class mounts (the test seam) */
    protected array $machineFamilies = [];

    private static ?string $database = null;

    public function createApplication()
    {
        if (null === self::$database) {
            self::$database = sprintf('%s/ffmachine-test-%d-%s.sqlite', sys_get_temp_dir(), getmypid(), bin2hex(random_bytes(4)));
            touch(self::$database);
            register_shutdown_function(static function (): void {
                if (null !== self::$database && is_file(self::$database)) {
                    @unlink(self::$database);
                }
            });
        }
        foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => self::$database, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array'] as $k => $v) {
            putenv(sprintf('%s=%s', $k, $v));
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
        }
        RouteTable::resetTestFamilies();
        foreach ($this->machineFamilies as $family) {
            RouteTable::registerTestFamily($family);
        }

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sprintf('%s/ffmachine-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        mkdir($this->sandbox, 0o700, true);
        config([
            'database.default'         => 'sqlite',
            'cache.default'            => 'array',
            'machine.credentials_file' => $this->sandbox.'/credentials/firefly_iii.json',
            'machine.state_dir'        => $this->sandbox.'/state',
            'machine.operator'         => null,
            'machine.administration'   => null,
            'machine.allow_write'      => false,
            'machine.allow_admin'      => false,
            'machine.write_lock_wait'  => 2,
        ]);
        foreach (['FIREFLY_MACHINE_KEY', 'FIREFLY_MACHINE_KEY_FILE'] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $this->machineKey = $this->writeCredentials();
        CredentialsFile::forget();
    }

    protected function tearDown(): void
    {
        CredentialsFile::forget();
        if ('' !== $this->sandbox && is_dir($this->sandbox)) {
            self::removeTree($this->sandbox);
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        RouteTable::resetTestFamilies();
        parent::tearDownAfterClass();
    }

    /**
     * Write a credentials file with a fresh (invented) key — or the given document — at 0600.
     *
     * @param null|array<string, mixed> $document
     */
    protected function writeCredentials(?array $document = null, int $mode = 0o600): string
    {
        $key  = bin2hex(random_bytes(32));
        $path = $this->credentialsPath();
        @mkdir(dirname($path), 0o700, true);
        $document ??= ['firefly_iii' => ['machine' => ['api_key' => $key, 'created' => '2026-09-21T00:00:00.000Z', 'created_by' => 'test', 'label' => 'test']]];
        file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        chmod($path, $mode);
        CredentialsFile::forget();

        return (string) ($document['firefly_iii']['machine']['api_key'] ?? $key);
    }

    protected function credentialsPath(): string
    {
        return $this->sandbox.'/credentials/firefly_iii.json';
    }

    /**
     * One /machine/v1 call. Defaults: a loopback peer (REMOTE_ADDR 127.0.0.1), Host
     * 127.0.0.1:7373, the test key in X-Firefly-Machine-Key, JSON in and out.
     *
     * @param array<string, mixed>       $body     sent as JSON (GET: as the query string)
     * @param array<string, null|string> $headers  a null value REMOVES a default header
     * @param array<string, string>      $server   raw server vars (REMOTE_ADDR…)
     */
    protected function machine(string $method, string $path, array $body = [], array $headers = [], array $server = [], string $host = '127.0.0.1:7373'): TestResponse
    {
        $headers = array_merge([
            'X-Firefly-Machine-Key' => $this->machineKey,
            'Accept'                => 'application/json',
            'Content-Type'          => 'application/json',
        ], $headers);
        $headers = array_filter($headers, static fn ($v): bool => null !== $v);
        $server  = array_merge(['REMOTE_ADDR' => '127.0.0.1'], $this->transformHeadersToServerVars($headers), $server);
        $url     = sprintf('http://%s/machine/v1%s', $host, $path);
        $method  = strtoupper($method);
        if ('GET' === $method && [] !== $body) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($body);
            $body = [];
        }
        $content = 'GET' === $method ? null : ([] === $body ? '' : (string) json_encode($body));

        return $this->call($method, $url, [], [], [], $server, $content);
    }

    /** A user who will be the (only) operator; returns it. */
    protected function operatorUser(): User
    {
        return $this->createAuthenticatedUser();
    }

    protected function enableWrites(): void
    {
        config(['machine.allow_write' => true]);
    }

    protected function enableAdmin(): void
    {
        config(['machine.allow_admin' => true]);
    }

    /** Assert the response is the envelope (JSON, never HTML) and return it decoded. */
    protected function envelope(TestResponse $response): array
    {
        $content = (string) $response->getContent();
        $this->assertStringStartsWith('{', ltrim($content), 'a plane response must be JSON, never HTML: '.substr($content, 0, 200));
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded);
        $this->assertIsBool($decoded['ok'] ?? null);

        return $decoded;
    }

    protected function assertPlaneError(TestResponse $response, int $status, string $code): array
    {
        $env = $this->envelope($response);
        $this->assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        $this->assertFalse($env['ok']);
        $this->assertSame($code, $env['error']['code'] ?? null, (string) $response->getContent());

        return $env;
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path) && !is_link($path)) {
                self::removeTree($path);

                continue;
            }
            @unlink($path);
        }
        @rmdir($dir);
    }
}
