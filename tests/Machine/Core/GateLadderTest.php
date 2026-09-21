<?php

/*
 * GateLadderTest.php
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

namespace Tests\Machine\Core;

use FireflyIII\Machine\Envelope;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §4.7 and §18 "Gate ladder" / "CORS pin": every gate, in order, with a negative
 * for each.
 *
 * @internal
 *
 * @coversNothing
 */
final class GateLadderTest extends MachineTestCase
{
    protected array $machineFamilies = [ProbeRoutes::class];

    public function testLoopbackWithTheKeyAnswers(): void
    {
        $response = $this->machine('GET', '/ping');
        $env      = $this->envelope($response);
        $response->assertStatus(200);
        $this->assertTrue($env['ok']);
        $this->assertTrue($env['data']['pong']);
        $this->assertSame('v1', $env['meta']['planeVersion']);
    }

    public function testNonLoopbackPeerGetsTheStealth404(): void
    {
        $response = $this->machine('GET', '/ping', server: ['REMOTE_ADDR' => '192.168.1.20']);
        $response->assertStatus(404);
        $this->assertSame(Envelope::STEALTH_404_BODY, $response->getContent());
    }

    public function testForwardedForSpoofWithTrustedProxiesIsStill404(): void
    {
        config(['trustedproxy.proxies' => '*']);
        putenv('TRUSTED_PROXIES=*');
        $response = $this->machine('GET', '/ping', headers: ['X-Forwarded-For' => '127.0.0.1', 'X-Real-IP' => '127.0.0.1'], server: ['REMOTE_ADDR' => '10.1.2.3']);
        putenv('TRUSTED_PROXIES');
        $response->assertStatus(404);
        $this->assertSame(Envelope::STEALTH_404_BODY, $response->getContent());
    }

    public function testIpv6LoopbackPeersAreAccepted(): void
    {
        $this->machine('GET', '/ping', server: ['REMOTE_ADDR' => '::1'])->assertStatus(200);
        $this->machine('GET', '/ping', server: ['REMOTE_ADDR' => '::ffff:127.0.0.1'])->assertStatus(200);
    }

    public function testAnyOriginIs404(): void
    {
        foreach (['https://evil.example', 'null', 'http://127.0.0.1:7373'] as $origin) {
            $response = $this->machine('GET', '/ping', headers: ['Origin' => $origin]);
            $response->assertStatus(404);
            $this->assertSame(Envelope::STEALTH_404_BODY, $response->getContent());
        }
    }

    #[DataProvider('fetchSites')]
    public function testSecFetchSiteIs404(string $site): void
    {
        $this->machine('GET', '/ping', headers: ['Sec-Fetch-Site' => $site])->assertStatus(404);
    }

    public static function fetchSites(): array
    {
        return ['cross-site' => ['cross-site'], 'same-site' => ['same-site'], 'none' => ['none'], 'same-origin' => ['same-origin']];
    }

    public function testRebindingHostIs404(): void
    {
        foreach (['evil.example', 'evil.example:7373', '127.0.0.1.nip.io:7373', 'localhost.evil.example'] as $host) {
            $response = $this->machine('GET', '/ping', host: $host);
            $response->assertStatus(404);
            $this->assertSame(Envelope::STEALTH_404_BODY, $response->getContent(), $host);
        }
    }

    public function testLoopbackHostsOnAnyPortAreAccepted(): void
    {
        foreach (['127.0.0.1:7373', '127.0.0.1:55123', 'localhost:7373', 'localhost', '[::1]:7373'] as $host) {
            $this->machine('GET', '/ping', host: $host)->assertStatus(200);
        }
    }

    public function testKeyInTheQueryStringIs404(): void
    {
        $this->machine('GET', '/ping?key='.$this->machineKey)->assertStatus(404);
        $this->machine('GET', '/ping?x='.$this->machineKey)->assertStatus(404);
        $this->machine('GET', '/ping?api_key=anything', headers: [])->assertStatus(404);
        // even with no header at all: the URL carrying a key is refused before the key gate
        $this->machine('GET', '/ping?key='.$this->machineKey, headers: ['X-Firefly-Machine-Key' => null])->assertStatus(404);
    }

    public function testNoKeyAndWrongKeyGetByteIdentical401(): void
    {
        $none  = $this->machine('GET', '/ping', headers: ['X-Firefly-Machine-Key' => null]);
        $wrong = $this->machine('GET', '/ping', headers: ['X-Firefly-Machine-Key' => str_repeat('0', 64)]);
        $short = $this->machine('GET', '/ping', headers: ['X-Firefly-Machine-Key' => 'x']);
        $empty = $this->machine('GET', '/ping', headers: ['X-Firefly-Machine-Key' => '']);
        foreach ([$none, $wrong, $short, $empty] as $response) {
            $response->assertStatus(401);
            $this->assertSame('{"ok":false,"error":{"code":"unauthorized"}}', $response->getContent());
        }
    }

    public function testAPassportBearerTokenIsNotAMachineKey(): void
    {
        $response = $this->machine('GET', '/ping', headers: ['X-Firefly-Machine-Key' => null, 'Authorization' => 'Bearer '.$this->machineKey]);
        $response->assertStatus(401);
        $this->assertSame(Envelope::UNAUTHORIZED_BODY, $response->getContent());
    }

    public function testAnUnarmedPlaneIs404ForEverything(): void
    {
        unlink($this->credentialsPath());
        foreach (['/ping', '/whoami', '/accounts', '/nope'] as $path) {
            $response = $this->machine('GET', $path);
            $response->assertStatus(404);
            $this->assertSame(Envelope::STEALTH_404_BODY, $response->getContent());
        }
        $this->assertFileDoesNotExist($this->credentialsPath(), 'a test boot must never mint a key');
    }

    public function testWriteWithTheTierOffIsWriteDisabled(): void
    {
        $this->operatorUser();
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', ['transactions' => []]), 403, 'write_disabled');
        $this->assertStringContainsString('FIREFLY_MACHINE_ALLOW_WRITE', $env['error']['hint']);
        $this->assertStringContainsString('machine.audit', (string) glob($this->sandbox.'/state/*')[0]);
        $audit = (string) file_get_contents($this->sandbox.'/state/machine.audit');
        $this->assertStringContainsString('ok=false', $audit);
        $this->assertStringContainsString('code=write_disabled', $audit);
    }

    public function testWriteWithTheTierOnReachesTheHandler(): void
    {
        $this->operatorUser();
        $this->enableWrites();
        // The tier gate passes and the live handler's own validation answers (gate 5), not the tier gate (gate 4).
        $env = $this->assertPlaneError($this->machine('POST', '/transactions', ['transactions' => []]), 400, 'invalid_input');
        $this->assertArrayHasKey('transactions', $env['error']['details']['fields']);
    }

    public function testAdminWithOnlyTheWriteTierIsForbidden(): void
    {
        $this->operatorUser();
        $this->enableWrites();
        $env = $this->assertPlaneError($this->machine('POST', '/admin/cron'), 403, 'forbidden');
        $this->assertStringContainsString('FIREFLY_MACHINE_ALLOW_ADMIN', $env['error']['hint']);
    }

    public function testAdminFromTheMcpIsForbiddenEvenWithTheSwitchOn(): void
    {
        $this->operatorUser();
        $this->enableWrites();
        $this->enableAdmin();
        $env = $this->assertPlaneError($this->machine('POST', '/admin/cron', headers: ['X-Firefly-Client' => 'mcp']), 403, 'forbidden');
        $this->assertStringContainsString('MCP', $env['error']['message']);
        // the same call from ffx reaches the live handler (a dry run by default — apis.mdx §7.2)
        $env = $this->envelope($this->machine('POST', '/admin/cron', headers: ['X-Firefly-Client' => 'ffx']));
        $this->assertTrue($env['ok']);
        $this->assertTrue($env['data']['dry_run']);
    }

    public function testEveryResponseIsNoStoreVaryOriginAndHasNoCorsGrant(): void
    {
        $this->operatorUser();
        $responses = [
            $this->machine('GET', '/ping'),
            $this->machine('GET', '/ping', headers: ['X-Firefly-Machine-Key' => null]),
            $this->machine('GET', '/ping', headers: ['Origin' => 'https://evil.example']),
            $this->machine('GET', '/ping', server: ['REMOTE_ADDR' => '8.8.8.8']),
            $this->machine('GET', '/nope'),
            $this->machine('GET', '/_probe/throw/boom'),
            $this->machine('POST', '/transactions'),
        ];
        foreach ($responses as $response) {
            $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('Origin', (string) $response->headers->get('Vary'));
            $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
            $this->envelope($response);
        }
    }

    public function testCorsConfigurationNeverMatchesThePlane(): void
    {
        foreach ((array) config('cors.paths') as $pattern) {
            foreach (['machine/v1/ping', 'machine/v1/transactions', 'machine/v1'] as $path) {
                $this->assertFalse(Str::is($pattern, $path), sprintf('config/cors.php path "%s" matches %s — the plane must never be browser-reachable', $pattern, $path));
            }
        }
        // a pre-flight is answered by the gates, not by CORS
        $response = $this->call('OPTIONS', 'http://127.0.0.1:7373/machine/v1/ping', [], [], [], $this->transformHeadersToServerVars([
            'Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'GET', 'Access-Control-Request-Headers' => 'x-firefly-machine-key',
        ]));
        $this->assertFalse($response->headers->has('Access-Control-Allow-Origin'));
    }
}
