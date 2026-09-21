<?php

/*
 * CapabilityParityTest.php
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

use FireflyIII\Machine\RouteDef;
use FireflyIII\Machine\RouteTable;
use Illuminate\Support\Facades\Route;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §6.3, §6.3a and §18 "Capability parity": /capabilities lists exactly the routes the
 * router mounts (one array, both directions); every published entry ANSWERS rather than falling
 * through to the terminal 404; a planned route refuses not_ready naming its phase.
 *
 * @internal
 *
 * @coversNothing
 */
final class CapabilityParityTest extends MachineTestCase
{
    public function testCapabilitiesListsExactlyTheMountedRoutes(): void
    {
        $env       = $this->envelope($this->machine('GET', '/capabilities'));
        $published = [];
        foreach ($env['data']['routes'] as $entry) {
            foreach ($entry['methods'] as $method) {
                $published[] = $method.' '.$entry['path'];
                $this->assertContains($entry['tier'][$method], ['read', 'write', 'admin']);
                $this->assertContains($entry['status'][$method], ['live', 'planned']);
                if ('planned' === $entry['status'][$method]) {
                    $this->assertStringContainsString('NOT IMPLEMENTED YET (phase P', $entry['summary'][$method]);
                }
            }
        }
        $table   = array_map(static fn (RouteDef $d): string => $d->key(), RouteTable::all());
        $mounted = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $key = $route->getAction('machine_key');
            if (is_string($key) && 'ANY *' !== $key) {
                $mounted[] = $key;
            }
        }
        sort($published);
        sort($table);
        sort($mounted);
        $this->assertSame($table, $published, '/capabilities is generated from RouteTable::all()');
        $this->assertSame($table, $mounted, 'the router is built from RouteTable::all()');
        $this->assertGreaterThan(150, count($table), 'the whole §8–§13 catalogue is declared');
        $this->assertSame(count($table), $env['data']['counts']['routes']);
        foreach (['apiVersion', 'serverVersion', 'tiers', 'operatorResolved', 'limits', 'features', 'batchOps'] as $field) {
            $this->assertArrayHasKey($field, $env['data']);
        }
        $this->assertSame(5000, $env['data']['limits']['maxLimit']);
        $this->assertSame(200, $env['data']['limits']['maxChangesDefault']);
        $this->assertSame(8388608, $env['data']['limits']['maxBodyBytes']);
    }

    public function testEveryPublishedRouteAnswers(): void
    {
        $this->operatorUser();
        $this->enableWrites();
        $this->enableAdmin();
        foreach (RouteTable::all() as $def) {
            $path     = (string) preg_replace('/\{[^}]+\}/', '1', $def->path);
            $response = $this->machine($def->method, $path);
            $body     = (string) $response->getContent();
            if ($def->isLive() && str_ends_with($def->path, '/download') && $response->isSuccessful()) {
                continue; // the one read route whose body is not the envelope (§8.8)
            }
            $env      = $this->envelope($response);
            $this->assertStringNotContainsString('on the machine plane', $body, sprintf('%s fell through to the terminal 404', $def->key()));
            if (!$def->isLive()) {
                $this->assertSame(503, $response->getStatusCode(), $def->key().': '.$body);
                $this->assertSame('not_ready', $env['error']['code']);
                $this->assertSame($def->phase, $env['error']['details']['phase'], $def->key());
                $this->assertSame('planned', $env['error']['details']['status']);
                $this->assertStringContainsString($def->phase, $env['error']['message']);
            }
        }
    }

    public function testTheCatalogueFollowsTheSpecsInvariants(): void
    {
        $shapes = [];
        foreach (RouteTable::all() as $def) {
            $shape = $def->method.' '.preg_replace('/\{[^}]+\}/', '{}', $def->path);
            $this->assertArrayNotHasKey($shape, $shapes, 'declared twice: '.$def->key());
            $shapes[$shape] = true;
            if ('DELETE' === $def->method && !in_array($def->path, ['/transaction-links/{id}', '/budgets/{id}/limits/{limit_id}'], true)) {
                $this->assertSame('admin', $def->tier, sprintf('%s: every destructive delete is admin-tier (§7.6)', $def->key()));
            }
            if ('GET' === $def->method) {
                $this->assertFalse($def->dryRun, $def->key().': a GET has no dry run');
                $this->assertNotSame('write', $def->tier, $def->key().': a GET is never a write');
            }
            if (!$def->isLive()) {
                $this->assertMatchesRegularExpression('/^P\d+$/', $def->phase, $def->key());
            }
        }
        foreach (['POST /undo', 'POST /recurrences/{id}/trigger'] as $noDryRun) {
            $def = RouteTable::find(...explode(' ', $noDryRun, 2));
            $this->assertNotNull($def, $noDryRun);
            $this->assertFalse($def->dryRun, $noDryRun.' has no dry run (§7.2)');
        }
        foreach (['GET /ping', 'GET /whoami', 'GET /capabilities', 'GET /health'] as $diagnostic) {
            $def = RouteTable::find(...explode(' ', $diagnostic, 2));
            $this->assertTrue($def?->isLive(), $diagnostic.' is live');
            $this->assertFalse($def?->needsOperator, $diagnostic.' answers without an operator');
        }
        // every route a client spec names is at least declared
        foreach (['GET /accounts', 'POST /transactions', 'GET /budget-period', 'POST /ingest/plan', 'GET /analytics/runway', 'GET /charts/series', 'GET /mirror/{path}', 'GET /undo/last', 'POST /batch', 'GET /search/operators', 'POST /accounts/{id}/reconcile/apply', 'PUT /budgets/{id}/limits', 'POST /rules/preview', 'GET /reports/default'] as $key) {
            $this->assertNotNull(RouteTable::find(...explode(' ', $key, 2)), $key.' is declared');
        }
    }

    public function testStaticSegmentsAreMountedBeforeParameters(): void
    {
        $this->operatorUser();
        // /transactions/export must not be captured by /transactions/{group_id} (which would answer
        // not_found for a group named "export"); the static route answers ok. Same for the other two.
        foreach (['/transactions/export', '/subscriptions/status', '/currencies/primary'] as $path) {
            $env = $this->envelope($this->machine('GET', $path));
            $this->assertTrue($env['ok'], $path.' reached its static route: '.json_encode($env['error'] ?? null));
        }
    }

    public function testTheDiagnosticsAnswerWithoutAnOperator(): void
    {
        // no users at all: the diagnostics still answer, and say what is wrong
        foreach (['/ping', '/whoami', '/capabilities', '/health'] as $path) {
            $this->machine('GET', $path)->assertStatus(200);
        }
        $who = $this->envelope($this->machine('GET', '/whoami'));
        $this->assertFalse($who['data']['operatorResolved']);
        $this->assertSame('not_ready', $who['data']['problem']['code']);
        $this->assertSame(0, $who['data']['users']);
        $health = $this->envelope($this->machine('GET', '/health', ['probe' => 'true']));
        $this->assertFalse($health['data']['healthy'], 'a reachable database with no operator is NOT healthy');
        $this->assertTrue($health['data']['database']['reachable']);
        $this->assertTrue($health['data']['database']['probed']);
        $this->assertStringContainsString('register', strtolower($health['data']['next']));
        // …and a ledger route refuses
        $this->assertPlaneError($this->machine('GET', '/accounts'), 503, 'not_ready');

        $this->operatorUser();
        $health = $this->envelope($this->machine('GET', '/health'));
        $this->assertTrue($health['data']['healthy']);
        $this->assertTrue($health['data']['operator']['resolved']);
    }
}
