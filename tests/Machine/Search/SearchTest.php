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

use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §13 — GET /search, /search/count, /search/operators, /search/accounts.
 *
 * @internal
 *
 * @coversNothing
 */
final class SearchTest extends MachineTestCase
{
    use SearchLedger;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->operatorUser();
        $this->seedSpending($this->user);
    }

    public function testSearchEchoesTheParsedOperatorsAndReturnsTransactions(): void
    {
        $env = $this->envelope($this->machine('GET', '/search', ['query' => 'description_contains:coffee']));
        $this->assertTrue($env['ok'], json_encode($env));
        $this->assertSame(2, $env['data']['total']);
        $this->assertCount(2, $env['data']['transactions']);
        $descriptions = array_map(static fn (array $g): string => $g['transactions'][0]['description'], $env['data']['transactions']);
        sort($descriptions);
        $this->assertSame(['Coffee at Blue Door', 'Coffee beans'], $descriptions);
        $this->assertSame([['operator' => 'description_contains', 'value' => 'coffee', 'prohibited' => false]], $env['data']['parsed_operators']);
        $this->assertSame([], $env['data']['free_text']);
        $this->assertIsString($env['data']['transactions'][0]['transactions'][0]['amount'], 'amounts are decimal strings');
        $this->assertArrayNotHasKey('links', $env['data']['transactions'][0]);
        $this->assertContains('description', $env['meta']['untrusted']);
        $this->assertFalse($env['meta']['truncated']);
        $this->assertSame('read', $env['meta']['tier']);
    }

    public function testFreeTextIsReportedAsFreeText(): void
    {
        $env = $this->envelope($this->machine('GET', '/search', ['query' => 'hardware']));
        $this->assertSame(['hardware'], $env['data']['free_text']);
        $this->assertSame([], $env['data']['parsed_operators']);
        $this->assertSame(1, $env['data']['total']);
    }

    public function testAMisspeltOperatorIsRefusedNotSilentlyDropped(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'desciption_contains:coffee']), 400, 'invalid_input');
        $this->assertSame('desciption_contains', $env['error']['details']['invalid_operators'][0]['operator']);
        $this->assertSame('description_contains', $env['error']['details']['invalid_operators'][0]['did_you_mean']);
        $this->assertStringContainsString('/search/operators', $env['error']['hint']);
    }

    public function testValidationRefusals(): void
    {
        $this->assertPlaneError($this->machine('GET', '/search'), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/search', ['query' => '   ']), 400, 'invalid_input');
        $env = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'coffee', 'start_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertSame(['start_date'], $env['error']['details']['unknown']);
        $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'coffee', 'limit' => 'many']), 400, 'invalid_input');
    }

    public function testLimitAndOffsetPageThroughTheMatches(): void
    {
        $first  = $this->envelope($this->machine('GET', '/search', ['query' => 'description_contains:coffee', 'limit' => 1]));
        $this->assertCount(1, $first['data']['transactions']);
        $this->assertTrue($first['meta']['truncated']);
        $this->assertSame(1, $first['meta']['next_offset']);
        $second = $this->envelope($this->machine('GET', '/search', ['query' => 'description_contains:coffee', 'limit' => 1, 'offset' => 1]));
        $this->assertCount(1, $second['data']['transactions']);
        $this->assertFalse($second['meta']['truncated']);
        $this->assertNotSame($first['data']['transactions'][0]['id'], $second['data']['transactions'][0]['id']);
        // an offset that is not a multiple of the limit
        $third  = $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:1', 'limit' => 2, 'offset' => 1]));
        $this->assertCount(2, $third['data']['transactions']);
        $this->assertSame(3, $third['data']['total']);
        $this->assertFalse($third['meta']['truncated']);
        $clamped = $this->envelope($this->machine('GET', '/search', ['query' => 'coffee', 'limit' => 999999]));
        $this->assertSame(5000, $clamped['meta']['limit_applied']);
        $this->assertSame(999999, $clamped['meta']['limit_requested']);
    }

    public function testCount(): void
    {
        $env = $this->envelope($this->machine('GET', '/search/count', ['query' => 'amount_more:10']));
        $this->assertSame(2, $env['data']['count']);
        $this->assertSame('amount_more', $env['data']['parsed_operators'][0]['operator']);
        $this->assertPlaneError($this->machine('GET', '/search/count', ['query' => 'coffee', 'limit' => 3]), 400, 'invalid_input');
    }

    public function testOperatorsAreGeneratedFromTheConfig(): void
    {
        $env = $this->envelope($this->machine('GET', '/search/operators'));
        $this->assertCount(count((array) config('search.operators')), $env['data']['operators']);
        $byName = [];
        foreach ($env['data']['operators'] as $op) {
            $byName[$op['operator']] = $op;
        }
        $this->assertSame('text', $byName['description_contains']['argument']);
        $this->assertSame('true', $byName['has_any_category']['argument']);
        $this->assertSame('date', $byName['date_after']['argument']);
        $this->assertSame('amount', $byName['amount_more']['argument']);
        $this->assertSame('id', $byName['account_id']['argument']);
        $this->assertSame('description_is', $byName['description']['alias_for']);
        $this->assertStringStartsWith('description_contains:', $byName['description_contains']['example']);
        $this->assertArrayHasKey('negate', $env['data']['syntax']);
        $this->assertPlaneError($this->machine('GET', '/search/operators', ['x' => '1']), 400, 'invalid_input');

        // every generated example is something Firefly's parser accepts
        foreach (['description_contains', 'has_any_category', 'date_after', 'amount_more', 'account_id', 'currency_is', 'transaction_type'] as $name) {
            $ok = $this->envelope($this->machine('GET', '/search/count', ['query' => $byName[$name]['example']]));
            $this->assertTrue($ok['ok'], $name.': '.json_encode($ok));
        }
    }

    public function testAccounts(): void
    {
        $env = $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'northbank', 'field' => 'name']));
        $this->assertTrue($env['ok'], json_encode($env));
        $this->assertSame(1, $env['data']['total']);
        $this->assertSame('Northbank Checking 4021', $env['data']['accounts'][0]['name']);
        $this->assertArrayNotHasKey('links', $env['data']['accounts'][0]);

        $byIban = $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'NRTH0000', 'field' => 'iban']));
        $this->assertSame(1, $byIban['data']['total']);

        $none = $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'Meridian']));
        $this->assertSame([], $none['data']['accounts']);

        $this->assertPlaneError($this->machine('GET', '/search/accounts', ['query' => 'x', 'field' => 'colour']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/search/accounts', ['field' => 'name']), 400, 'invalid_input');
    }
}
