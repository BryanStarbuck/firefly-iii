<?php

/*
 * AnalyticsRegressionTest.php
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

namespace Tests\Machine\Analytics;

use Carbon\Carbon;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Bill;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Category;
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\Tag;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * Regressions found by the adversarial review of the analytics family (apis.mdx §8.9, §10):
 * every test here pins a defect that was fixed — authorization scope, name resolution, the
 * currency filter on balance-derived rows, hard caps, and money summed at full precision.
 *
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsRegressionTest extends MachineTestCase
{
    use AnalyticsFixture;

    private const array Q3 = ['start' => '2026-07-01', 'end' => '2026-09-30'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildLedger();
    }

    protected function tearDown(): void
    {
        $this->tearDownLedger();
        parent::tearDown();
    }

    /** Another administration's accounts, categories, tags, subscriptions and journals are invisible (§4.9, §16). */
    public function testAnotherAdministrationIsInvisible(): void
    {
        $other = $this->otherUser('neighbour@example.invalid');
        config(['machine.operator' => $this->user->email]);
        $theirChecking = Account::create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'account_type_id' => AccountType::query()->where('type', 'Asset account')->firstOrFail()->id, 'name' => 'Their checking', 'active' => true]);
        $theirShop     = Account::create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'account_type_id' => AccountType::query()->where('type', 'Expense account')->firstOrFail()->id, 'name' => 'Their shop', 'active' => true]);
        $theirCategory = Category::create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'name' => 'Their groceries']);
        $theirTag      = Tag::create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'tag' => 'theirs', 'tag_mode' => 'nothing']);
        $theirBill     = Bill::create([
            'user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'name' => 'Their gym', 'match' => 'x',
            'amount_min' => '1', 'amount_max' => '1', 'date' => Carbon::parse('2026-07-01'), 'repeat_freq' => 'monthly', 'skip' => 0,
            'automatch' => false, 'active' => true, 'transaction_currency_id' => $this->eur->id,
        ]);
        // a big withdrawal in THEIR ledger (written as their user)
        $mine       = $this->user;
        $this->user = $other;
        $this->journal('Withdrawal', '2026-07-03', '99999', $theirChecking, $theirShop, $this->eur, 'Not ours', $theirCategory->id);
        $this->user = $mine;

        // their entities cannot be named…
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['account_ids' => [(string) $theirChecking->id]]), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['account_names' => ['Their checking']]), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/analytics/category-trend', self::Q3 + ['category_id' => (string) $theirCategory->id]), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-tag', self::Q3 + ['tags' => ['theirs']]), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/charts/subscription/'.$theirBill->id, self::Q3), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/reports/double', self::Q3 + ['counterparty_ids' => [(string) $theirShop->id]]), 404, 'not_found');

        // …and their journals are never counted: the totals are ours alone
        $data = $this->data('/analytics/spending-by-category', self::Q3);
        $this->assertSame([['currency_code' => 'EUR', 'spent' => '803.92', 'count' => 11], ['currency_code' => 'USD', 'spent' => '42.00', 'count' => 1]], $data['totals']);
        $this->assertNotContains($theirChecking->id, $data['provenance']['accounts']);
        $payees = $this->data('/analytics/payee-leaderboard', self::Q3 + ['top_n' => 50]);
        $this->assertNotContains('Their shop', array_column($payees['rows'], 'name'));
        $subs = $this->data('/analytics/subscriptions', self::Q3);
        $this->assertSame(['Gym'], array_column($subs['subscriptions'], 'name'));
    }

    /** A tag (or a category, budget, account) whose NAME is a number is resolved by name, never mistaken for an id (§14.4). */
    public function testNumericNamesResolveAsNames(): void
    {
        $tag2026 = Tag::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'tag' => '2026', 'tag_mode' => 'nothing']);
        $cat     = Category::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => '4021']);
        $this->journal('Withdrawal', '2026-08-20', '77.00', $this->acct['checking'], $this->acct['cafe'], $this->eur, 'Numeric names', $cat->id, tags: [$tag2026->id]);

        $tag = $this->data('/analytics/spending-by-tag', self::Q3 + ['interval' => 'none', 'tags' => ['2026']]);
        $this->assertSame([['2026', '77.00']], array_map(static fn (array $r): array => [$r['name'], $r['spent']], $tag['rows']));
        $this->assertSame(['2026'], $tag['provenance']['filters']['tags']);

        $byName = $this->data('/analytics/spending-by-category', self::Q3 + ['interval' => 'none', 'category_names' => ['4021']]);
        $this->assertSame([['4021', '77.00']], array_map(static fn (array $r): array => [$r['name'], $r['spent']], $byName['rows']));
        $this->assertSame([$cat->id], $byName['provenance']['filters']['category_ids']);

        $trend  = $this->data('/analytics/category-trend', self::Q3 + ['category_name' => '4021']);
        $this->assertSame($cat->id, $trend['category']['id']);

        $byAcct = $this->data('/analytics/spending-by-category', self::Q3 + ['interval' => 'none', 'account_names' => ['northbank checking 4021']]);
        $this->assertSame([$this->acct['checking']->id], $byAcct['provenance']['accounts']);

        $report = $this->data('/reports/category', self::Q3 + ['category_names' => ['4021']]);
        $this->assertSame(['77.00'], array_column($report['categories'], 'spent'));

        // an unknown numeric name is not_found by NAME, not "no category with id"
        $env = $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['category_names' => ['9999']]), 404, 'not_found');
        $this->assertSame('9999', $env['error']['details']['name']);
    }

    /** Two categories that differ only in case are ambiguous, and the candidates are returned (§14.4). */
    public function testAmbiguousNameCarriesCandidates(): void
    {
        Category::create(['user_id' => $this->user->id, 'user_group_id' => $this->user->user_group_id, 'name' => 'GROCERIES']);
        $env = $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['category_names' => ['groceries']]), 400, 'invalid_input');
        $this->assertCount(2, $env['error']['details']['candidates']);
        $this->assertContains('Groceries', array_column($env['error']['details']['candidates'], 'name'));
    }

    /** currency_code narrows balance-derived rows too (cash flow, net worth, account balances), not only journals. */
    public function testCurrencyFilterAppliesToBalanceRows(): void
    {
        $cash = $this->data('/analytics/cash-flow', self::Q3 + ['currency_code' => 'EUR']);
        $this->assertSame(['EUR'], array_values(array_unique(array_column($cash['rows'], 'currency_code'))));
        $this->assertSame(['EUR'], $cash['provenance']['currencies']);
        $this->assertSame('EUR', $cash['provenance']['filters']['currency_code']);

        $net  = $this->data('/analytics/net-worth', self::Q3 + ['currency_code' => 'USD']);
        $this->assertSame(['USD', 'USD', 'USD'], array_column($net['rows'], 'currency_code'));
        $this->assertSame(['-42.00', '-42.00', '-42.00'], array_column($net['rows'], 'net'));
        foreach ($net['accounts'] as $account) {
            $this->assertSame([], array_filter($account['balances'], static fn (array $b): bool => 'USD' !== $b['currency_code']));
        }

        $chart = $this->data('/charts/account-balances', self::Q3 + ['currency_code' => 'USD']);
        $this->assertSame(['USD'], array_values(array_unique(array_column($chart['series'], 'currency_code'))));
        $this->assertSame([], array_filter($chart['series'], static fn (array $s): bool => str_contains($s['label'], 'Meridian')));

        $run = $this->data('/analytics/runway', ['currency_code' => 'USD']);
        $this->assertSame(['USD'], array_column($run['rows'], 'currency_code'));
    }

    /** No account in scope means no journals — never "every journal in the ledger". */
    public function testNoAccountsInScopeCountsNothing(): void
    {
        foreach (['checking', 'savings', 'usd'] as $key) {
            $this->acct[$key]->active = false;
            $this->acct[$key]->save();
        }
        $run = $this->data('/analytics/runway', ['basis' => '3']);
        $this->assertSame([], $run['rows']);
        $this->assertSame([], $run['provenance']['accounts']);
        $this->assertSame(0, $run['provenance']['rows']);
    }

    /** The per-account balance chart has a hard ceiling on account × period cells (§15). */
    public function testAccountBalancesChartRefusesTooManyCells(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/charts/account-balances', ['start' => '1927-01-01', 'end' => '2026-12-31', 'interval' => 'month']), 400, 'invalid_input');
        $this->assertStringContainsString('account', $env['error']['hint']);
        $this->assertSame(3600, $env['error']['details']['cells']);
        $this->assertSame(2400, $env['error']['details']['max']);

        // the same range at a coarser interval is fine
        $ok = $this->data('/charts/account-balances', ['start' => '1927-01-01', 'end' => '2026-12-31', 'interval' => 'year']);
        $this->assertCount(100, $ok['x']['labels']);
    }

    /** The audit report is bounded: limit (cap 5,000) and offset, with meta.truncated when a cap bound it (§5.5). */
    public function testAuditReportIsBounded(): void
    {
        $response = $this->machine('GET', '/reports/audit', self::Q3 + ['account_ids' => [(string) $this->acct['checking']->id], 'limit' => 3]);
        $env      = $this->envelope($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertCount(3, $env['data']['journals']);
        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-05'], array_column($env['data']['journals'], 'date'));
        $this->assertTrue($env['meta']['truncated']);
        $this->assertSame(3, $env['meta']['limit_applied']);
        $this->assertSame(3, $env['meta']['next_offset']);
        $this->assertSame(15, $env['data']['accounts'][0]['journals'], 'the per-account count is the whole account, not the page');

        $page2 = $this->envelope($this->machine('GET', '/reports/audit', self::Q3 + ['account_ids' => [(string) $this->acct['checking']->id], 'limit' => 3, 'offset' => 3]));
        $this->assertSame(['2026-07-10', '2026-07-12', '2026-07-15'], array_column($page2['data']['journals'], 'date'));
        $this->assertSame('3849.60', $page2['data']['journals'][0]['balance_before'], 'the running balance is the account\'s, not the page\'s');

        $all = $this->envelope($this->machine('GET', '/reports/audit', self::Q3 + ['account_ids' => [(string) $this->acct['checking']->id]]));
        $this->assertFalse($all['meta']['truncated']);
        $this->assertCount(15, $all['data']['journals']);

        $clamped = $this->envelope($this->machine('GET', '/reports/audit', self::Q3 + ['limit' => 99999]));
        $this->assertSame(5000, $clamped['meta']['limit_applied']);
        $this->assertSame(99999, $clamped['meta']['limit_requested']);

        // the currency filter narrows the accounts section as well as the journals
        $usd = $this->data('/reports/audit', self::Q3 + ['currency_code' => 'USD']);
        $this->assertSame(['USD'], array_values(array_unique(array_column($usd['accounts'], 'currency_code'))));
        $this->assertSame(['USD'], array_values(array_unique(array_column($usd['journals'], 'currency_code'))));
    }

    /** Budget totals add the stored amounts, not the rounded display strings. */
    public function testBudgetTotalsAddAtFullPrecision(): void
    {
        // two limits of 250.005: shown as 250.01 each, but the total is 500.01, not 500.02
        BudgetLimit::query()->delete();
        BudgetLimit::create(['budget_id' => $this->ids['household'], 'start_date' => Carbon::parse('2026-07-01'), 'end_date' => Carbon::parse('2026-07-31'), 'amount' => '250.005', 'transaction_currency_id' => $this->eur->id]);
        BudgetLimit::create(['budget_id' => $this->ids['household'], 'start_date' => Carbon::parse('2026-08-01'), 'end_date' => Carbon::parse('2026-08-31'), 'amount' => '250.005', 'transaction_currency_id' => $this->eur->id]);
        $data = $this->data('/analytics/budget-performance', self::Q3);
        $this->assertSame(['250.01', '250.01'], array_column(array_filter($data['rows'], static fn (array $r): bool => null !== $r['limit']), 'limit'));
        $this->assertSame([['currency_code' => 'EUR', 'limit' => '500.01', 'spent' => '441.45']], $data['totals']);

        $chart = $this->data('/charts/budget-overview', self::Q3);
        $limit = array_values(array_filter($chart['series'], static fn (array $s): bool => 'limit-eur' === $s['key']))[0];
        $this->assertSame(['500.01'], $limit['values']);
    }

    /** With a wide tolerance several cadences can match; the nearest one wins, not the first in the list. */
    public function testRecurringPicksTheNearestCadence(): void
    {
        foreach (['2026-06-05', '2026-06-30', '2026-07-25', '2026-08-19', '2026-09-13'] as $d) {
            $this->journal('Withdrawal', $d, '60.00', $this->acct['checking'], $this->acct['gym'], $this->eur, 'Water Co');
        }
        $data  = $this->data('/analytics/recurring', ['start' => '2026-06-01', 'end' => '2026-09-30', 'tolerance_days' => 12]);
        $water = array_values(array_filter($data['items'], static fn (array $i): bool => '60.00' === $i['typical_amount']))[0];
        $this->assertSame([25, 25, 25, 25], $water['intervals_days']);
        $this->assertSame('monthly', $water['cadence'], '25 days is 3 from monthly (28..31) and 11 from biweekly (14)');
    }

    /** A per-object chart takes a name as well as an id, like every other {id} route (§14.4). */
    public function testPerObjectChartsResolveNames(): void
    {
        $bill = $this->data('/charts/subscription/Gym', self::Q3);
        $this->assertSame($this->ids['gym'], $bill['provenance']['subscription_id']);
        $piggy = $this->data('/charts/piggy-bank/holiday fund');
        $this->assertSame($this->ids['piggy'], $piggy['provenance']['piggy_bank_id']);
        $this->assertPlaneError($this->machine('GET', '/charts/subscription/Nope', self::Q3), 404, 'not_found');
    }

    /** A primary-currency total appears only when every contributing row is convertible, labelled converted_to (§10.3). */
    public function testConvertedTotalOnlyWhenComplete(): void
    {
        $data = $this->data('/analytics/spending-by-category', self::Q3);
        $this->assertNull($data['converted'], 'the USD dinner has no primary-currency amount, so there is no complete conversion');
        $this->assertStringContainsString('converted', implode(' ', $data['notes']));

        $eur = $this->data('/analytics/spending-by-category', self::Q3 + ['currency_code' => 'EUR']);
        $this->assertSame(['converted_to' => 'EUR', 'spent' => '803.92', 'count' => 11, 'complete' => true], $eur['converted']);

        $flows = $this->data('/analytics/income-vs-expense', self::Q3 + ['currency_code' => 'EUR']);
        $this->assertSame(['converted_to' => 'EUR', 'income' => '9000.00', 'expense' => '803.92', 'net' => '8196.08', 'count' => 14, 'complete' => true], $flows['converted']);
    }

    /** piggy-progress takes the shared arguments (§10.2): account_ids narrows to piggy banks on those accounts; the range is unused and says so. */
    public function testPiggyProgressTakesSharedArguments(): void
    {
        $data = $this->data('/analytics/piggy-progress', self::Q3 + ['account_ids' => [(string) $this->acct['savings']->id]]);
        $this->assertSame(['Holiday fund'], array_column($data['piggy_banks'], 'name'));
        $this->assertSame('not used (piggy banks are as of today)', $data['provenance']['range']);

        $none = $this->data('/analytics/piggy-progress', ['account_ids' => [(string) $this->acct['checking']->id]]);
        $this->assertSame([], $none['piggy_banks']);
    }

    /** @return array<string, mixed> */
    private function data(string $path, array $query = []): array
    {
        $response = $this->machine('GET', $path, $query);
        $env      = $this->envelope($response);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertTrue($env['ok']);
        array_walk_recursive($env['data'], function (mixed $v, int|string $k): void {
            $this->assertFalse(is_float($v), sprintf('%s is a float — amounts are strings', (string) $k));
        });

        return $env['data'];
    }

    private function otherUser(string $email): User
    {
        $group = UserGroup::create(['title' => $email]);
        $role  = UserRole::query()->where('title', 'owner')->first();
        $user  = User::create(['email' => $email, 'password' => 'password', 'user_group_id' => $group->id]);
        GroupMembership::create(['user_id' => $user->id, 'user_group_id' => $group->id, 'user_role_id' => $role->id]);

        return $user;
    }
}
