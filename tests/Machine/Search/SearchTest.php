<?php

/*
 * SearchTest.php
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

        // on an edit-distance tie the longest shared prefix wins: amount_moar is amount_more, not amount_max
        $tie = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'amount_moar:1']), 400, 'invalid_input');
        $this->assertSame('amount_more', $tie['error']['details']['invalid_operators'][0]['did_you_mean']);
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

    /**
     * §5.5 / §13: a search has Firefly's one order (date desc). `order` is accepted so a list
     * client (ffx --order) can name it; any other order is refused by name, never ignored.
     */
    public function testOrderIsTheOneFireflyHasAndOthersAreRefusedByName(): void
    {
        $env = $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:1', 'order' => '-date']));
        $this->assertTrue($env['ok'], (string) json_encode($env));
        $this->assertSame(['2026-03-15', '2026-03-09', '2026-03-02'], array_map(static fn (array $g): string => substr((string) $g['transactions'][0]['date'], 0, 10), $env['data']['transactions']));
        $this->assertSame($env['data']['transactions'], $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:1']))['data']['transactions'], 'the default is that order');
        foreach (['date', 'amount', '-id'] as $order) {
            $bad = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'amount_more:1', 'order' => $order]), 400, 'invalid_input');
            $this->assertSame(['-date'], $bad['error']['details']['accepted'], $order);
        }
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

    /**
     * Regression: Firefly's mapAccountTypes() turns a type it does not know into "normal" — the
     * caller asked for one search and silently got another (§5.7). Refused by name instead.
     */
    public function testAnUnknownAccountTypeIsRefusedNotSilentlyWidened(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/search/accounts', ['query' => 'north', 'type' => 'bogus']), 400, 'invalid_input');
        $this->assertSame(['bogus'], $env['error']['details']['unknown']);
        $this->assertContains('asset', $env['error']['details']['accepted']);
        $this->assertPlaneError($this->machine('GET', '/search/accounts', ['query' => 'north', 'type' => 'asset,bogus']), 400, 'invalid_input');
        $ok  = $this->envelope($this->machine('GET', '/search/accounts', ['query' => 'north', 'type' => 'asset,liabilities']));
        $this->assertTrue($ok['ok'], json_encode($ok));
        $this->assertSame(1, $ok['data']['total']);
    }

    /**
     * Regression: a typed value Firefly would COERCE rather than refuse — amount_more:abc is
     * amount_more:0 (every transaction), id:abc is id:0 (none), has_any_category:maybe is true —
     * is refused with the value named, before Firefly sees it. And a KNOWN operator whose value
     * Firefly itself could not read (a date) is reported as a bad value, not as an unknown
     * operator that "did you mean" itself.
     */
    public function testAValueFireflyWouldSilentlyCoerceIsRefused(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'amount_more:abc']), 400, 'invalid_input');
        $this->assertSame('amount_more', $env['error']['details']['invalid_values'][0]['operator']);
        $this->assertSame('abc', $env['error']['details']['invalid_values'][0]['value']);
        $this->assertSame('amount', $env['error']['details']['invalid_values'][0]['expected']);
        $this->assertStringContainsString('amount_more:50.00', $env['error']['hint']);
        $this->assertArrayNotHasKey('invalid_operators', $env['error']['details']);

        $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'id:abc']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/search/count', ['query' => 'has_any_category:maybe']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'coffee -amount_less:lots']), 400, 'invalid_input');

        $date = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'date_after:not-a-date']), 400, 'invalid_input');
        $this->assertSame('date_after', $date['error']['details']['invalid_values'][0]['operator']);
        $this->assertSame('date', $date['error']['details']['invalid_values'][0]['expected']);
        $this->assertStringNotContainsString('Unknown search operator', $date['error']['message']);
        $this->assertStringContainsString('YYYY-MM-DD', $date['error']['hint']);

        // the values Firefly reads correctly still run: a comma decimal, a real date
        foreach (['amount_more:4,50', 'date_after:2026-03-01', 'has_any_category:false', 'id:1'] as $query) {
            $ok = $this->envelope($this->machine('GET', '/search/count', ['query' => $query]));
            $this->assertTrue($ok['ok'], $query.': '.json_encode($ok));
        }
    }

    /**
     * Regression: Firefly's parser reads the minus AFTER the colon as a prohibition of a new token,
     * so amount_more:-50 is "NOT amount_more:50" (and source_balance_lt:-100 is "NOT balance < 100",
     * i.e. balance >= 100) — the complement of what the caller meant. Such a value is refused by
     * name instead of running silently; the sign-free forms and the explicit -operator: still run.
     */
    public function testANegativeAmountValueIsRefusedBecauseFireflyReadsItAsANegation(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'amount_more:-4.50']), 400, 'invalid_input');
        $this->assertSame('NOT amount_more:4.50', $env['error']['details']['invalid_values'][0]['read_as']);
        $this->assertStringContainsString('-amount_more:4.50', $env['error']['hint']);
        $this->assertPlaneError($this->machine('GET', '/search/count', ['query' => 'coffee amount_is:-18']), 400, 'invalid_input');
        $balance = $this->assertPlaneError($this->machine('GET', '/search', ['query' => 'source_balance_lt:-100']), 400, 'invalid_input');
        $this->assertStringContainsString('negative balance', $balance['error']['hint']);
        $this->assertPlaneError($this->machine('GET', '/search', ['query' => '-amount_is:-4.50']), 400, 'invalid_input');
        // what Firefly does read as the caller meant still runs, and the echo shows the negation
        $ok = $this->envelope($this->machine('GET', '/search', ['query' => '-amount_is:4.50']));
        $this->assertTrue($ok['ok'], json_encode($ok));
        $this->assertSame(2, $ok['data']['total']);
        $this->assertTrue($ok['data']['parsed_operators'][0]['prohibited']);
        $this->assertSame(1, $this->envelope($this->machine('GET', '/search', ['query' => 'amount_is:4.50']))['data']['total']);
        // a minus inside quotes or inside a word is not a sign
        $this->assertTrue($this->envelope($this->machine('GET', '/search', ['query' => 'description_contains:"coffee-1"']))['ok']);
    }

    /**
     * §13 / §15: the 10 s timeout is `upstream_error` with a narrowing hint. Under SQLite there is
     * no statement timeout through PDO, so the wall clock judges — a limit no query can meet
     * proves the refusal, and an unusable setting falls back to the default.
     */
    public function testASearchOverTheTimeoutIsUpstreamErrorWithANarrowingHint(): void
    {
        config(['machine.limits.search_timeout' => 0.000001]);
        foreach (['/search' => 'amount_more:1', '/search/count' => 'amount_more:1', '/search/accounts' => 'north'] as $route => $query) {
            $env = $this->assertPlaneError($this->machine('GET', $route, ['query' => $query]), 502, 'upstream_error');
            $this->assertStringContainsString('longer than the', $env['error']['message'], $route);
            $this->assertStringContainsString('Narrow the query', $env['error']['hint'], $route);
            $this->assertSame('0.000001', $env['error']['details']['timeout_seconds'], $route.': seconds are decimal strings, never floats');
            $this->assertSame(1, bccomp((string) $env['error']['details']['elapsed_seconds'], '0', 6), $route);
        }

        config(['machine.limits.search_timeout' => 0]);
        $this->assertTrue($this->envelope($this->machine('GET', '/search/count', ['query' => 'amount_more:1']))['ok']);
        config(['machine.limits.search_timeout' => 'soon']);
        $this->assertTrue($this->envelope($this->machine('GET', '/search/count', ['query' => 'amount_more:1']))['ok']);
    }

    /** §13: the byte cap — over 8 MiB the page is cut, meta.truncated says so and next_offset continues. */
    public function testTheByteCapCutsThePageAndSaysWhereToContinue(): void
    {
        $full = $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:1']));
        $this->assertCount(3, $full['data']['transactions']);
        $this->assertFalse($full['meta']['truncated']);

        // one transaction fits, three do not (the cap keeps 64 KiB of headroom for the envelope)
        $one = strlen((string) json_encode($full['data']['transactions'][0]));
        config(['machine.limits.max_response_bytes' => 65536 + $one + 50]);
        $cut = $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:1']));
        $this->assertTrue($cut['ok'], json_encode($cut));
        $this->assertCount(1, $cut['data']['transactions']);
        $this->assertSame(3, $cut['data']['total']);
        $this->assertTrue($cut['meta']['truncated']);
        $this->assertSame(2, $cut['meta']['dropped_rows']);
        $this->assertSame(1, $cut['meta']['next_offset']);
        $this->assertStringContainsString('offset=1', $cut['meta']['hint']);
        $this->assertSame($full['data']['transactions'][0]['id'], $cut['data']['transactions'][0]['id']);

        $next = $this->envelope($this->machine('GET', '/search', ['query' => 'amount_more:1', 'offset' => 1]));
        $this->assertSame($full['data']['transactions'][1]['id'], $next['data']['transactions'][0]['id'], 'the caller can continue where the cut left off');
    }
}
