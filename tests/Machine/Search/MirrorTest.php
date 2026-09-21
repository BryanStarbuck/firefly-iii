<?php

/*
 * RouteDef.php
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

namespace Tests\Machine\Search;

use FireflyIII\Machine\Http\Controllers\MirrorController;
use FireflyIII\Models\Preference;
use FireflyIII\User;
use Illuminate\Routing\Route;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §8.12 — the mirror: upstream's /api/v1 GETs, in-process, as the operator, GET only,
 * behind a denylist, secrets never passed; plus the parity walk over upstream's route table.
 *
 * @internal
 *
 * @coversNothing
 */
final class MirrorTest extends MachineTestCase
{
    use SearchLedger;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->seedSpending($this->user);
    }

    public function testAListIsFlattenedOneLevel(): void
    {
        $env = $this->envelope($this->machine('GET', '/mirror/accounts', ['type' => 'asset']));
        $this->assertTrue($env['ok'], json_encode($env));
        $this->assertSame('GET /api/v1/accounts', $env['data']['route']);
        $this->assertSame(200, $env['data']['status']);
        $this->assertIsArray($env['data']['result']);
        $this->assertSame('Northbank Checking 4021', $env['data']['result'][0]['name'], 'attributes are flattened into the row');
        $this->assertSame('accounts', $env['data']['result'][0]['resource']);
        $this->assertSame('asset', $env['data']['result'][0]['type']);
        $this->assertArrayNotHasKey('attributes', $env['data']['result'][0]);
        $this->assertIsArray($env['data']['pagination']);
        $this->assertTrue($env['meta']['mirrored']);
        $this->assertSame('test@email.com', $env['meta']['operator'], 'the mirror answers as the bound operator');
    }

    public function testASingleObjectAndAParameterisedRoute(): void
    {
        $id  = (string) $this->user->accounts()->first()->id;
        $env = $this->envelope($this->machine('GET', '/mirror/accounts/'.$id));
        $this->assertTrue($env['ok'], json_encode($env));
        $this->assertSame($id, (string) $env['data']['result']['id']);
        $this->assertSame('GET /api/v1/accounts/{account}', $env['data']['route']);

        $about = $this->envelope($this->machine('GET', '/mirror/about/user'));
        $this->assertTrue($about['ok'], 'about/user is served — it is the operator');
        $this->assertSame('test@email.com', $about['data']['result']['email']);
        $this->assertArrayNotHasKey('password', $about['data']['result']);
    }

    public function testTheQueryStringPassesThroughAndUpstreamValidationIsInvalidInput(): void
    {
        $ok  = $this->envelope($this->machine('GET', '/mirror/insight/expense/total', ['start' => '2026-03-01', 'end' => '2026-03-31']));
        $this->assertTrue($ok['ok'], json_encode($ok));
        $env = $this->assertPlaneError($this->machine('GET', '/mirror/insight/expense/total'), 400, 'invalid_input');
        $this->assertSame(422, $env['error']['details']['upstream_status']);
    }

    public function testTheDenylistNamesTheTypedRoute(): void
    {
        foreach (['users' => 'admin/users', 'users/1' => 'admin/users', 'configuration' => 'admin/configuration', 'configuration/firefly.version' => 'admin/configuration', 'cron/abc' => 'admin/cron', 'data/export/transactions' => 'transactions/export'] as $path => $hint) {
            $env = $this->assertPlaneError($this->machine('GET', '/mirror/'.$path), 403, 'forbidden');
            $this->assertStringContainsString($hint, $env['error']['hint'], $path);
        }
        // encoding cannot slip past it: the denylist is applied to the matched upstream template
        $this->assertPlaneError($this->machine('GET', '/mirror/api/v1/users'), 403, 'forbidden');
        $this->assertPlaneError($this->machine('GET', '/mirror/data%2Fexport%2Ftransactions'), 403, 'forbidden');
    }

    public function testWritesAndUnknownOrDirtyPathsAreRefused(): void
    {
        $env = $this->assertPlaneError($this->machine('POST', '/mirror/accounts', ['name' => 'x']), 403, 'forbidden');
        $this->assertStringContainsString('read-only', $env['error']['message']);
        $this->assertPlaneError($this->machine('DELETE', '/mirror/data/destroy'), 403, 'forbidden');
        $this->assertPlaneError($this->machine('GET', '/mirror/no-such-thing'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/mirror/data/destroy'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/mirror/accounts/../users'), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/mirror/accounts/999999'), 404, 'not_found');
    }

    public function testSecretsNeverPass(): void
    {
        Preference::query()->create(['user_id' => $this->user->id, 'name' => 'access_token', 'data' => 'not-a-real-token-but-secret']);
        Preference::query()->create(['user_id' => $this->user->id, 'name' => 'listPageSize', 'data' => 50]);
        $env  = $this->envelope($this->machine('GET', '/mirror/preferences'));
        $this->assertTrue($env['ok'], json_encode($env));
        $names = array_column($env['data']['result'], 'name');
        $this->assertContains('listPageSize', $names);
        $this->assertNotContains('access_token', $names);
        $this->assertStringNotContainsString('not-a-real-token-but-secret', (string) json_encode($env));
        $this->assertPlaneError($this->machine('GET', '/mirror/preferences/access_token'), 403, 'forbidden');

        $this->assertSame(['title' => 'x', 'secret' => '[redacted]', 'nested' => [['client_secret' => '[redacted]', 'ok' => 1]]], MirrorController::scrubSecrets(['title' => 'x', 'secret' => 'abc', 'nested' => [['client_secret' => 'y', 'ok' => 1]]]));
    }

    /**
     * §8.12: every GET in upstream's route table is either mirrored or denied BY NAME. A new
     * upstream route is a failure here (add it to upstream_get_routes.txt, or deny it) — never a
     * silent new surface.
     */
    public function testParityWithUpstreamsRouteTable(): void
    {
        $current = [];
        foreach (app('router')->getRoutes() as $route) {
            /** @var Route $route */
            if (in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'api/v1/')) {
                $current[] = MirrorController::relativeTemplate($route);
            }
        }
        sort($current);
        $known = array_values(array_filter(array_map('trim', (array) file(__DIR__.'/upstream_get_routes.txt'))));
        sort($known);
        $this->assertSame($known, $current, 'upstream\'s GET routes changed: classify each new one as mirrored (upstream_get_routes.txt) or denied (MirrorController::DENIED_PREFIXES)');

        $denied = array_filter($current, static fn (string $t): bool => null !== MirrorController::deniedBy($t));
        foreach (array_keys(MirrorController::DENIED_PREFIXES) as $prefix) {
            $this->assertNotEmpty(array_filter($denied, static fn (string $t): bool => $t === $prefix || str_starts_with($t, $prefix.'/')), 'denylist entry '.$prefix.' matches no upstream route');
        }
        $this->assertGreaterThan(140, count($current) - count($denied), 'the mirror serves the ~150 upstream reads');
    }

    /** Every parameter-free mirrored GET answers the envelope — ok, or a NAMED refusal, never internal/HTML. */
    public function testEveryParameterFreeUpstreamReadAnswersTheEnvelope(): void
    {
        $query = ['start' => '2026-03-01', 'end' => '2026-03-31'];
        foreach ((array) file(__DIR__.'/upstream_get_routes.txt') as $template) {
            $template = trim((string) $template);
            if ('' === $template || str_contains($template, '{') || null !== MirrorController::deniedBy($template)) {
                continue;
            }
            $response = $this->machine('GET', '/mirror/'.$template, $query);
            $env      = $this->envelope($response);
            if (!$env['ok']) {
                $this->assertNotSame('internal', $env['error']['code'], $template.': '.json_encode($env['error']));
            }
        }
    }
}
