<?php

/*
 * EnvelopeTest.php
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

use FireflyIII\Machine\MachineException;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §5 and §18 "Envelope": every response is the envelope — 404 and 500 included —
 * never HTML, never a stack or a class name; the vocabulary is exactly nine codes and the MCP's
 * is a strict superset; input validation (unknown fields, booleans, the body cap); lists (clamp,
 * order, truncation); name resolution.
 *
 * @internal
 *
 * @coversNothing
 */
final class EnvelopeTest extends MachineTestCase
{
    protected array $machineFamilies = [ProbeRoutes::class];

    public function testAnUnknownRouteIsTheEnvelopeNotHtml(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/definitely/not/here'), 404, 'not_found');
        $this->assertStringContainsString('/capabilities', $env['error']['hint']);
        $env = $this->assertPlaneError($this->machine('GET', '/undo'), 404, 'not_found');
        $this->assertSame(['POST'], $env['error']['details']['methods'], 'a wrong method names the right one');
        $this->assertPlaneError($this->machine('GET', ''), 404, 'not_found');
    }

    public function testAnInternalErrorIsGenericAndLeaksNothing(): void
    {
        $this->operatorUser();
        $response = $this->machine('GET', '/_probe/throw/boom');
        $env      = $this->assertPlaneError($response, 500, 'internal');
        $body     = (string) $response->getContent();
        foreach (['/Users/', 'Secret.php', 'Illuminate\\', 'SQLSTATE', 'select * from', 'RuntimeException', '#0 ', 'stack'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }
        $this->assertStringContainsString('~/T/firefly/error.err', $env['error']['hint']);
        $this->assertSame('The detail is in ~/T/firefly/error.err — ffx logs --errors', $env['error']['hint'], 'the canonical hint H (pm/error_err.mdx R17)');
    }

    public function testLaravelAndFireflyExceptionsMapOntoTheNineCodes(): void
    {
        $this->operatorUser();
        $cases = [
            'firefly'    => [502, 'upstream_error'],
            'duplicate'  => [409, 'conflict'],
            'model'      => [404, 'not_found'],
            'validation' => [400, 'invalid_input'],
            'authz'      => [403, 'forbidden'],
            'machine'    => [409, 'conflict'],
        ];
        foreach ($cases as $kind => [$status, $code]) {
            $env = $this->assertPlaneError($this->machine('GET', '/_probe/throw/'.$kind), $status, $code);
            $this->assertNotEmpty($env['error']['hint'] ?? '', sprintf('%s: every error names a fix (R6)', $kind));
        }
        $dup = $this->envelope($this->machine('GET', '/_probe/throw/duplicate'));
        $this->assertSame(42, $dup['error']['details']['duplicate_of']);
        $this->assertStringContainsString('/transactions/42', $dup['error']['hint']);
        $val = $this->envelope($this->machine('GET', '/_probe/throw/validation'));
        $this->assertArrayHasKey('amount', $val['error']['details']['fields']);
    }

    public function testTheVocabularyIsExactlyNineAndTheMcpsIsASuperset(): void
    {
        $nine = ['unauthorized', 'forbidden', 'not_found', 'invalid_input', 'conflict', 'write_disabled', 'not_ready', 'upstream_error', 'internal'];
        $this->assertSame($nine, array_keys(MachineException::CODES));
        $mcp  = (string) file_get_contents(base_path('pm/mcp.mdx'));
        $this->assertSame(1, preg_match('/### 12\.3 The closed error vocabulary\s+(.+?)\n\n/s', $mcp, $m));
        preg_match_all('/`([a-z_]+)`/', $m[1], $codes);
        $this->assertCount(12, $codes[1]);
        foreach ($nine as $code) {
            $this->assertContains($code, $codes[1], sprintf('mcp.mdx §12.3 must include the plane code %s', $code));
        }
        $this->assertSame(503, MachineException::CODES['not_ready']);
        $this->assertSame(403, MachineException::CODES['write_disabled']);
    }

    public function testDataAndMetaAreObjectsAndMetaIsComplete(): void
    {
        $this->operatorUser();
        $response = $this->machine('GET', '/ping');
        $raw      = (string) $response->getContent();
        $env      = $this->envelope($response);
        foreach (['target', 'operator', 'administrationId', 'administrationName', 'primaryCurrency', 'serverVersion', 'planeVersion', 'asOf', 'tookMs', 'truncated', 'tier', 'untrusted'] as $field) {
            $this->assertArrayHasKey($field, $env['meta'], $field);
        }
        $this->assertSame('test@email.com', $env['meta']['operator']);
        $this->assertIsInt($env['meta']['administrationId']);
        $this->assertSame('test@email.com', $env['meta']['administrationName']);
        $this->assertMatchesRegularExpression('/^[A-Z]{3}$/', (string) $env['meta']['primaryCurrency']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $env['meta']['asOf']);
        $this->assertStringStartsWith('{"ok":true,"data":{', $raw);
    }

    public function testUnknownArgumentsAreRefusedNotIgnored(): void
    {
        $this->operatorUser();
        $env = $this->assertPlaneError($this->machine('GET', '/_probe/list', ['start_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertSame(['start_date'], $env['error']['details']['unknown']);
        $this->assertContains('start', $env['error']['details']['accepted']);

        $env = $this->assertPlaneError($this->machine('POST', '/_probe/body', ['name' => 'x', 'nmae' => 'y']), 400, 'invalid_input');
        $this->assertSame(['nmae'], $env['error']['details']['unknown']);

        $ok  = $this->envelope($this->machine('POST', '/_probe/body', ['name' => 'x', 'tags' => ['a']]));
        $this->assertSame(['name' => 'x', 'tags' => ['a']], $ok['data']['args']);

        $env = $this->assertPlaneError($this->machine('POST', '/_probe/body', ['tags' => ['a']]), 400, 'invalid_input');
        $this->assertArrayHasKey('name', $env['error']['details']['fields']);
    }

    public function testQueryBooleansAcceptTheWords(): void
    {
        $this->operatorUser();
        $env = $this->envelope($this->machine('GET', '/_probe/list', ['flag' => 'true']));
        $this->assertTrue($env['data']['args']['flag']);
        $env = $this->envelope($this->machine('GET', '/_probe/list', ['flag' => 'false']));
        $this->assertFalse($env['data']['args']['flag']);
        $this->assertPlaneError($this->machine('GET', '/_probe/list', ['flag' => 'maybe']), 400, 'invalid_input');
    }

    public function testBodyShapeAndSize(): void
    {
        $this->operatorUser();
        $response = $this->call('POST', 'http://127.0.0.1:7373/machine/v1/_probe/body', [], [], [], $this->transformHeadersToServerVars(['X-Firefly-Machine-Key' => $this->machineKey, 'Content-Type' => 'application/json']), '{"name": "x",');
        $this->assertPlaneError($response, 400, 'invalid_input');

        $response = $this->call('POST', 'http://127.0.0.1:7373/machine/v1/_probe/body', [], [], [], $this->transformHeadersToServerVars(['X-Firefly-Machine-Key' => $this->machineKey, 'Content-Type' => 'application/json']), '["x"]');
        $this->assertPlaneError($response, 400, 'invalid_input');

        $response = $this->call('POST', 'http://127.0.0.1:7373/machine/v1/_probe/body', [], [], [], $this->transformHeadersToServerVars(['X-Firefly-Machine-Key' => $this->machineKey, 'Content-Type' => 'text/plain']), 'name=x');
        $this->assertPlaneError($response, 400, 'invalid_input');

        $response = $this->machine('POST', '/_probe/body', ['name' => 'x'], headers: ['Content-Length' => (string) (9 * 1024 * 1024)]);
        $env      = $this->assertPlaneError($response, 400, 'invalid_input');
        $this->assertStringContainsString('8 MiB', $env['error']['message']);
    }

    public function testListsClampOrderAndReportTruncation(): void
    {
        $this->operatorUser();
        $env = $this->envelope($this->machine('GET', '/_probe/list', ['limit' => '3']));
        $this->assertSame(['alpha', 'alpha', 'bravo'], array_column($env['data']['rows'], 'name'));
        $this->assertSame([2, 6], array_column(array_slice($env['data']['rows'], 0, 2), 'id'), 'ties break on id, so the order is total');
        $this->assertTrue($env['meta']['truncated']);
        $this->assertSame(3, $env['meta']['limit_applied']);
        $this->assertSame(3, $env['meta']['next_offset']);

        $env = $this->envelope($this->machine('GET', '/_probe/list', ['limit' => '99999']));
        $this->assertSame(5000, $env['meta']['limit_applied'], 'clamped, never rejected');
        $this->assertSame(99999, $env['meta']['limit_requested']);
        $this->assertFalse($env['meta']['truncated']);
        $this->assertCount(7, $env['data']['rows']);

        $env = $this->envelope($this->machine('GET', '/_probe/list', ['order' => '-amount']));
        $this->assertSame(['1000.01', '100.00', '10.00', '9.50', '9.50', '0.00', '-3.25'], array_column($env['data']['rows'], 'amount'), 'decimal strings order numerically, with bcmath');

        $env = $this->envelope($this->machine('GET', '/_probe/list', ['offset' => '5', 'limit' => '5']));
        $this->assertSame(['echo', 'foxtrot'], array_column($env['data']['rows'], 'name'));
        $this->assertFalse($env['meta']['truncated']);

        $this->assertPlaneError($this->machine('GET', '/_probe/list', ['limit' => 'ten']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/_probe/list', ['offset' => '-1']), 400, 'invalid_input');
        $env = $this->assertPlaneError($this->machine('GET', '/_probe/list', ['order' => 'secret_column']), 400, 'invalid_input');
        $this->assertSame(['name', 'amount', 'id'], $env['error']['details']['accepted']);
    }

    public function testResolveByIdThenExactThenCaseInsensitiveAndAmbiguityIsAnError(): void
    {
        $user = $this->operatorUser();
        $make = static fn (string $name) => \FireflyIII\Models\Category::create(['name' => $name, 'user_id' => $user->id, 'user_group_id' => $user->user_group_id]);
        $g    = $make('Groceries');
        $make('Dining');
        $make('dining');

        $this->assertSame($g->id, $this->envelope($this->machine('GET', '/_probe/resolve/'.$g->id))['data']['id']);
        $this->assertSame($g->id, $this->envelope($this->machine('GET', '/_probe/resolve/Groceries'))['data']['id']);
        $this->assertSame($g->id, $this->envelope($this->machine('GET', '/_probe/resolve/groceries'))['data']['id']);
        $this->assertSame('Dining', $this->envelope($this->machine('GET', '/_probe/resolve/Dining'))['data']['name'], 'an exact match wins over case-insensitive twins');
        $env = $this->assertPlaneError($this->machine('GET', '/_probe/resolve/DINING'), 400, 'invalid_input');
        $this->assertCount(2, $env['error']['details']['candidates']);
        $this->assertPlaneError($this->machine('GET', '/_probe/resolve/Nothing'), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/_probe/resolve/99999'), 404, 'not_found');

        // another administration's category is invisible
        $other = \FireflyIII\Models\UserGroup::create(['title' => 'other-books']);
        \FireflyIII\Models\Category::create(['name' => 'Hidden', 'user_id' => $user->id, 'user_group_id' => $other->id]);
        $this->assertPlaneError($this->machine('GET', '/_probe/resolve/Hidden'), 404, 'not_found');
    }
}
