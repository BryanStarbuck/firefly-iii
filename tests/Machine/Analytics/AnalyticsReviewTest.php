<?php

/*
 * AnalyticsReviewTest.php
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
use FireflyIII\Models\GroupMembership;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\Preference;
use FireflyIII\Models\UserGroup;
use FireflyIII\Models\UserRole;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Support\Singleton\PreferencesSingleton;
use FireflyIII\User;
use Tests\Machine\MachineTestCase;

/**
 * Regressions from the second adversarial review of the analytics family (apis.mdx §8.9, §10):
 * each test pins a defect that was fixed — balances silently converted to the primary currency,
 * an expense account booked as a "liability", a provenance block that contradicted `excluded`,
 * the anomaly detector blind to a constant norm, an internal error on a malformed argument, a
 * list cap that rejected instead of clamping, and a chart that re-added rounded strings.
 *
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsReviewTest extends MachineTestCase
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
        PreferencesSingleton::getInstance()->resetPreferences();
        $this->tearDownLedger();
        parent::tearDown();
    }

    /**
     * With the operator's "convert to primary currency" preference on, Firefly's NetWorth helper
     * folds a USD account's balance into the EUR figure through an exchange rate ("0" when none
     * is stored). The plane answers per currency and never mixes (§10.3, §14.1): the USD row
     * stays USD on every balance-derived route.
     */
    public function testBalancesAreNeverConvertedToThePrimaryCurrency(): void
    {
        $before = $this->data('/analytics/net-worth', self::Q3 + ['interval' => 'none']);
        $this->assertSame(['EUR', 'USD'], array_column($before['rows'], 'currency_code'));

        Preference::create(['user_id' => $this->user->id, 'name' => 'convert_to_primary', 'data' => true]);
        AppConfiguration::set('enable_exchange_rates', true);
        PreferencesSingleton::getInstance()->resetPreferences();
        PreferencesSingleton::getInstance()->setPreference('convert_to_primary_no_user', true);

        $net = $this->data('/analytics/net-worth', self::Q3 + ['interval' => 'none']);
        $this->assertSame($before['rows'], $net['rows'], 'the preference must not change a per-currency answer');
        $this->assertSame(['-42.00'], array_column(array_filter($net['rows'], static fn (array $r): bool => 'USD' === $r['currency_code']), 'assets'));
        $this->assertStringContainsString('never converted', $net['provenance']['balances']);

        $boxes = [];
        foreach ($this->data('/analytics/summary', ['start' => '2026-09-01', 'end' => '2026-09-30'])['boxes'] as $box) {
            $boxes[$box['key']] = $box['value'];
        }
        $this->assertSame(['4196.08', '-42.00'], [$boxes['net_worth-in-EUR'], $boxes['net_worth-in-USD']]);

        $cash = $this->data('/analytics/cash-flow', self::Q3 + ['interval' => 'none']);
        $this->assertSame(['EUR', 'USD'], array_column($cash['rows'], 'currency_code'));
        $this->assertSame(['-42.00', true], [$cash['rows'][1]['closing'], $cash['rows'][1]['reconciles']]);

        $run = $this->data('/analytics/runway', ['basis' => '3']);
        $this->assertSame(['EUR', 'USD'], array_column($run['rows'], 'currency_code'));
        $this->assertSame('9196.08', $run['rows'][0]['liquid']);

        $chart = $this->data('/charts/account-balances', self::Q3 + ['interval' => 'none']);
        $this->assertContains('USD', array_column($chart['series'], 'currency_code'));
    }

    /** Net worth is assets + liabilities: an expense account named in account_ids[] is skipped and named, never booked as a liability. */
    public function testNetWorthSkipsAccountsThatAreNeitherAssetNorLiability(): void
    {
        $data = $this->data('/analytics/net-worth', self::Q3 + ['account_ids' => [(string) $this->acct['cafe']->id, (string) $this->acct['loan']->id], 'interval' => 'none']);
        $this->assertSame([['2026-07-01..2026-09-30', 'EUR', '0.00', '-5000.00', '-5000.00']], array_map(static fn (array $r): array => [$r['period'], $r['currency_code'], $r['assets'], $r['liabilities'], $r['net']], $data['rows']));
        $this->assertSame([$this->acct['loan']->id], $data['provenance']['accounts']);
        $this->assertSame($this->acct['cafe']->id, $data['skipped'][0]['account_id']);
        $this->assertStringContainsString('not an asset or liability', $data['skipped'][0]['reason']);
        $this->assertSame([$this->acct['loan']->id], array_column($data['accounts'], 'account_id'));

        // the chart is the same numbers
        $chart = $this->data('/charts/net-worth', self::Q3 + ['account_ids' => [(string) $this->acct['cafe']->id], 'interval' => 'none']);
        $this->assertSame([], $chart['series']);
    }

    /** A route that only ever counts withdrawals says so in provenance.transfers, even when include_transfers=true was sent — it never contradicts `excluded`. */
    public function testProvenanceNeverClaimsTransfersWereCountedWhenTheyCannotBe(): void
    {
        foreach (['/analytics/budget-performance', '/analytics/recurring', '/analytics/uncategorized-summary'] as $route) {
            $data = $this->data($route, self::Q3 + ['include_transfers' => 'true']);
            $this->assertContains('transfers', $data['excluded'], $route);
            $this->assertStringStartsWith('not counted', $data['provenance']['transfers'], $route);
        }
        // the reports take no include_transfers (§8.9) and say the same
        foreach (['/reports/budget', '/reports/double'] as $route) {
            $data = $this->data($route, self::Q3);
            $this->assertContains('transfers', $data['excluded'], $route);
            $this->assertStringStartsWith('not counted', $data['provenance']['transfers'], $route);
        }
        $this->assertSame('not applicable', $this->data('/analytics/subscriptions', self::Q3 + ['include_transfers' => 'true'])['provenance']['transfers']);
        $this->assertSame('not applicable', $this->data('/analytics/piggy-progress', ['include_transfers' => 'true'])['provenance']['transfers']);

        // …while a route that does honour it still says "included"
        $this->assertSame('included', $this->data('/analytics/income-vs-expense', self::Q3 + ['include_transfers' => 'true'])['provenance']['transfers']);
    }

    /** A malformed argument is invalid_input with a hint, never an internal error (§5.1: Laravel's errors never reach a caller). */
    public function testMalformedSeriesSourceIsInvalidInputNotInternal(): void
    {
        $env = $this->assertPlaneError($this->machine('GET', '/charts/series', self::Q3 + ['source' => ['cash-flow']]), 400, 'invalid_input');
        $this->assertSame('source', $env['error']['details']['field']);
        $this->assertStringContainsString('/analytics/', $env['error']['hint']);
    }

    /** The audit report's limit is clamped at both ends, never rejected (§5.5). */
    public function testAuditLimitIsClampedNotRejected(): void
    {
        $env = $this->envelope($this->machine('GET', '/reports/audit', self::Q3 + ['account_ids' => [(string) $this->acct['checking']->id], 'limit' => 0]));
        $this->assertTrue($env['ok']);
        $this->assertCount(1, $env['data']['journals']);
        $this->assertSame([1, 0, true], [$env['meta']['limit_applied'], $env['meta']['limit_requested'], $env['meta']['truncated']]);
    }

    /** A subscription paid in another currency reports that sum at the currency's own places, like every other amount. */
    public function testSubscriptionPaidInAnotherCurrencyIsFormatted(): void
    {
        $this->journal('Withdrawal', '2026-09-03', '42.5', $this->acct['usd'], $this->acct['gym'], $this->usd, 'Gym abroad', bill: $this->ids['gym']);
        $gym = $this->data('/analytics/subscriptions', self::Q3)['subscriptions'][0];
        $this->assertSame(['USD' => '42.50'], $gym['paid_other_currencies']);
        $this->assertSame('60.00', $gym['paid_amount'], 'the EUR total counts EUR payments only');
        $this->assertSame(3, $gym['paid_count']);
    }

    /** The uncategorised summary answers by month (by_period) as well as by month × account, and the chart draws by_period rather than re-adding rounded rows. */
    public function testUncategorizedByPeriodFeedsTheChart(): void
    {
        $this->journal('Withdrawal', '2026-08-21', '0.005', $this->acct['savings'], $this->acct['cafe'], $this->eur, 'Half a cent');
        $this->journal('Withdrawal', '2026-08-22', '0.005', $this->acct['checking'], $this->acct['cafe'], $this->eur, 'Another half');
        $data = $this->data('/analytics/uncategorized-summary');
        $aug  = array_values(array_filter($data['by_period'], static fn (array $r): bool => '2026-08' === $r['period']))[0];
        $this->assertSame(['2026-08', 'EUR', 3, '30.01'], [$aug['period'], $aug['currency_code'], $aug['count'], $aug['amount']]);
        // the two per-account rows each show 0.01 (rounded), and 30.01 + 0.01 would be the wrong month total
        $rows = array_values(array_filter($data['rows'], static fn (array $r): bool => '2026-08' === $r['period']));
        $this->assertSame(['30.01', '0.01'], array_column($rows, 'amount'));

        $chart = $this->data('/charts/series', ['source' => '/analytics/uncategorized-summary']);
        $this->assertSame(['2026-07', '2026-08', '2026-09'], $chart['x']['labels']);
        $this->assertSame(['34.50', '30.01', '250.00'], $chart['series'][0]['values']);
        $this->assertContains('transfers', $chart['excluded']);
    }

    /** Another administration's piggy bank is invisible by id and by name, and never listed. */
    public function testAnotherAdministrationsPiggyBankIsInvisible(): void
    {
        $other = $this->otherUser('neighbour@example.invalid');
        config(['machine.operator' => $this->user->email]);
        $theirSavings = Account::create(['user_id' => $other->id, 'user_group_id' => $other->user_group_id, 'account_type_id' => AccountType::query()->where('type', 'Asset account')->firstOrFail()->id, 'name' => 'Their savings', 'active' => true]);
        $piggy        = PiggyBank::create(['name' => 'Their fund', 'order' => 1, 'target_amount' => '100', 'start_date' => Carbon::parse('2026-07-01'), 'target_date' => Carbon::parse('2026-12-31'), 'transaction_currency_id' => $this->eur->id]);
        $piggy->accounts()->attach($theirSavings->id, ['current_amount' => '10']);

        $this->assertPlaneError($this->machine('GET', '/charts/piggy-bank/'.$piggy->id), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/charts/piggy-bank/Their fund'), 404, 'not_found');
        $this->assertSame(['Holiday fund'], array_column($this->data('/analytics/piggy-progress')['piggy_banks'], 'name'));
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
