<?php

/*
 * AnalyticsRoutesTest.php
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

use Tests\Machine\MachineTestCase;

/**
 * pm/apis.mdx §10.2–§10.3: every analytics route against a hand-computed invented ledger
 * (AnalyticsFixture) — per-currency decimal strings, transfers/opening balances excluded and
 * echoed, provenance, validation refusals and not_found.
 *
 * @internal
 *
 * @coversNothing
 */
final class AnalyticsRoutesTest extends MachineTestCase
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

    public function testSpendingByCategoryPerMonthPerCurrency(): void
    {
        $env  = $this->ok('/analytics/spending-by-category', self::Q3);
        $data = $env['data'];
        $july = array_values(array_filter($data['rows'], static fn (array $r): bool => '2026-07' === $r['period'] && 'EUR' === $r['currency_code']));
        $this->assertSame(['Groceries', '(no category)', 'Subscriptions'], array_column($july, 'name'));
        $this->assertSame(['200.40', '34.50', '15.99'], array_column($july, 'spent'));
        $this->assertSame([2, 2, 1], array_column($july, 'count'));
        $this->assertNull($july[1]['category_id']);
        $usd  = array_values(array_filter($data['rows'], static fn (array $r): bool => 'USD' === $r['currency_code']));
        $this->assertSame([['2026-07', 'Dining', '42.00']], array_map(static fn (array $r): array => [$r['period'], $r['name'], $r['spent']], $usd));
        $this->assertSame([['currency_code' => 'EUR', 'spent' => '803.92', 'count' => 11], ['currency_code' => 'USD', 'spent' => '42.00', 'count' => 1]], $data['totals']);
        $this->assertSame('excluded', $data['provenance']['transfers']);
        $this->assertSame(['EUR', 'USD'], $data['provenance']['currencies']);
        $this->assertSame('2026-07-01', $data['provenance']['start']);
        $this->assertStringStartsWith('transfers', $data['excluded'][0]);
        $this->assertContains('opening balances', $data['excluded']);
        $this->assertAllAmountsAreStrings($data);

        // top_n keeps the largest buckets and reports the rest
        $top = $this->ok('/analytics/spending-by-category', self::Q3 + ['top_n' => 1, 'currency_code' => 'eur'])['data'];
        $this->assertSame(['Groceries'], array_values(array_unique(array_column($top['rows'], 'name'))));
        $this->assertSame([['currency_code' => 'EUR', 'buckets' => 2, 'spent' => '362.47']], $top['omitted']);

        // include_transfers counts the transfer, and says so
        $with = $this->ok('/analytics/spending-by-category', self::Q3 + ['include_transfers' => 'true', 'interval' => 'none'])['data'];
        $this->assertSame('included', $with['provenance']['transfers']);
        $this->assertSame('1303.92', $with['totals'][0]['spent']);
    }

    public function testSpendingByBudgetAndTag(): void
    {
        $budget = $this->ok('/analytics/spending-by-budget', self::Q3 + ['interval' => 'none'])['data'];
        $names  = array_column(array_filter($budget['rows'], static fn (array $r): bool => 'EUR' === $r['currency_code']), 'spent', 'name');
        $this->assertSame(['Household' => '441.45', '(no budget)' => '362.47'], $names);

        $tag    = $this->ok('/analytics/spending-by-tag', self::Q3 + ['interval' => 'none', 'tags' => ['vacation']])['data'];
        $this->assertSame([['vacation', '250.00']], array_map(static fn (array $r): array => [$r['name'], $r['spent']], $tag['rows']));
        $this->assertSame(['vacation'], $tag['provenance']['filters']['tags']);
    }

    public function testPayeeLeaderboard(): void
    {
        $data = $this->ok('/analytics/payee-leaderboard', self::Q3 + ['top_n' => 2, 'currency_code' => 'EUR'])['data'];
        $this->assertSame([['Fresh Grocer', '441.45', 1], ['Corner Cafe', '254.50', 2]], array_map(static fn (array $r): array => [$r['name'], $r['amount'], $r['rank']], $data['rows']));
        $this->assertSame('803.92', $data['rows'][0]['total']);
        $this->assertSame(2, $data['omitted'][0]['payees']);

        $in   = $this->ok('/analytics/payee-leaderboard', self::Q3 + ['direction' => 'in'])['data'];
        $this->assertSame([['Acme LLC payroll', '9000.00', 3]], array_map(static fn (array $r): array => [$r['name'], $r['amount'], $r['count']], $in['rows']));
    }

    public function testIncomeVsExpense(): void
    {
        $data = $this->ok('/analytics/income-vs-expense', self::Q3)['data'];
        $eur  = array_values(array_filter($data['rows'], static fn (array $r): bool => 'EUR' === $r['currency_code']));
        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($eur, 'period'));
        $this->assertSame(['250.89', '156.09', '396.94'], array_column($eur, 'expense'));
        $this->assertSame(['2749.11', '2843.91', '2603.06'], array_column($eur, 'net'));
        $usd  = array_values(array_filter($data['rows'], static fn (array $r): bool => 'USD' === $r['currency_code']));
        $this->assertCount(1, $usd, 'a month with no USD activity has no USD row (absent is not zero)');
        $this->assertSame(['0.00', '42.00', '-42.00'], [$usd[0]['income'], $usd[0]['expense'], $usd[0]['net']]);
        $this->assertSame(['currency_code' => 'EUR', 'income' => '9000.00', 'expense' => '803.92', 'net' => '8196.08'], $data['totals'][0]);

        $q    = $this->ok('/analytics/income-vs-expense', self::Q3 + ['interval' => 'quarter'])['data'];
        $this->assertSame('2026-Q3', $q['rows'][0]['period']);
    }

    public function testCashFlowReconciles(): void
    {
        $data = $this->ok('/analytics/cash-flow', self::Q3)['data'];
        $july = $this->row($data['rows'], '2026-07', 'EUR');
        $this->assertSame(['1000.00', '3000.00', '250.89', '0.00', '0.00', '3749.11', true], [$july['opening'], $july['in'], $july['out'], $july['transfers_net'], $july['adjustments'], $july['closing'], $july['reconciles']]);
        $usd  = $this->row($data['rows'], '2026-07', 'USD');
        $this->assertSame(['0.00', '42.00', '-42.00', true], [$usd['opening'], $usd['out'], $usd['closing'], $usd['reconciles']]);
        $sep  = $this->row($data['rows'], '2026-09', 'EUR');
        $this->assertSame('9196.08', $sep['closing']);

        // only checking: the transfer to savings leaves, the payroll arrives
        $one  = $this->ok('/analytics/cash-flow', self::Q3 + ['account_ids' => [(string) $this->acct['checking']->id], 'interval' => 'none'])['data'];
        $row  = $this->row($one['rows'], '2026-07-01..2026-09-30', 'EUR');
        $this->assertSame(['1000.00', '-500.00', '8696.08', true], [$row['opening'], $row['transfers_net'], $row['closing'], $row['reconciles']]);
    }

    public function testNetWorth(): void
    {
        $data = $this->ok('/analytics/net-worth', self::Q3)['data'];
        $july = $this->row($data['rows'], '2026-07', 'EUR');
        $this->assertSame(['3749.11', '-5000.00', '-1250.89'], [$july['assets'], $july['liabilities'], $july['net']]);
        $this->assertSame('2026-07-31', $july['date']);
        $sep  = $this->row($data['rows'], '2026-09', 'EUR');
        $this->assertSame('4196.08', $sep['net']);
        $loan = array_values(array_filter($data['accounts'], fn (array $a): bool => $a['account_id'] === $this->acct['loan']->id))[0];
        $this->assertSame('liability', $loan['kind']);
        $this->assertSame('-5000.00', $loan['balances'][0]['balance']);
    }

    public function testCategoryTrendWithMeanAndMedian(): void
    {
        $data  = $this->ok('/analytics/category-trend', self::Q3 + ['category_name' => 'groceries'])['data'];
        $this->assertSame(['200.40', '110.10', '130.95'], array_column($data['rows'], 'spent'));
        $this->assertSame(['currency_code' => 'EUR', 'mean' => '147.15', 'median' => '130.95'], array_intersect_key($data['stats'][0], array_flip(['currency_code', 'mean', 'median'])));

        $dining = $this->ok('/analytics/category-trend', self::Q3 + ['category_id' => (string) $this->ids['dining']])['data'];
        $this->assertSame(['42.00', null, null], array_column($dining['rows'], 'spent'), 'a month with no data is null, not "0"');
        $this->assertSame('14.00', $dining['stats'][0]['mean_including_gaps']);

        $this->assertPlaneError($this->machine('GET', '/analytics/category-trend', self::Q3), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/analytics/category-trend', self::Q3 + ['category_name' => 'Nope']), 404, 'not_found');
    }

    public function testBudgetPerformance(): void
    {
        $data = $this->ok('/analytics/budget-performance', self::Q3 + ['budget_names' => ['Household']])['data'];
        $this->assertSame(
            [
                ['2026-07', '250.00', '200.40', '49.60', '-49.60', false],
                ['2026-08', '100.00', '110.10', '-10.10', '10.10', true],
                ['2026-07-01..2026-09-30', null, '130.95', null, null, null],
            ],
            array_map(static fn (array $r): array => [$r['period'], $r['limit'], $r['spent'], $r['left'], $r['variance'], $r['over']], $data['rows'])
        );
        $this->assertSame(['currency_code' => 'EUR', 'limit' => '350.00', 'spent' => '441.45'], $data['totals'][0]);
    }

    public function testSubscriptions(): void
    {
        $data = $this->ok('/analytics/subscriptions', self::Q3)['data'];
        $gym  = $data['subscriptions'][0];
        $this->assertSame(['Gym', 3, 2, 1, '60.00', '360.00'], [$gym['name'], $gym['expected_count'], $gym['paid_count'], $gym['missed_count'], $gym['paid_amount'], $gym['annualised']]);
        $this->assertSame([['currency_code' => 'EUR', 'annualised' => '360.00']], $data['totals']);
    }

    public function testRecurringDetectorFindsStreamlyWithEvidence(): void
    {
        $data = $this->ok('/analytics/recurring', ['start' => '2026-06-01', 'end' => '2026-09-30'])['data'];
        $this->assertTrue($data['detector']);
        $this->assertCount(1, $data['items']);
        $item = $data['items'][0];
        $this->assertSame(['Streamly', 'monthly', 3, '15.99', '191.88', [31, 31], '2026-10-10'], [$item['name'], $item['cadence'], $item['occurrences'], $item['typical_amount'], $item['annualised'], $item['intervals_days'], $item['next_expected']]);
        $this->assertSame(['2026-07-10', '2026-08-10', '2026-09-10'], array_column($item['evidence'], 'date'));

        // the defaulted range (13 months back from today) is echoed
        $default = $this->ok('/analytics/recurring')['data'];
        $this->assertSame('defaulted', $default['provenance']['range']);
        $this->assertSame('2026-09-21', $default['provenance']['end']);

        $none = $this->ok('/analytics/recurring', ['min_occurrences' => 4])['data'];
        $this->assertSame([], $none['items']);
    }

    public function testRunway(): void
    {
        $data = $this->ok('/analytics/runway', ['basis' => '3'])['data'];
        $eur  = $this->row($data['rows'], null, 'EUR');
        $this->assertSame(['9196.08', '406.98', '135.66', '67.8', null], [$eur['liquid'], $eur['outflow_total'], $eur['average_outflow'], $eur['months'], $eur['months_net']]);
        $this->assertSame(['months' => 3, 'window_start' => '2026-06-01', 'window_end' => '2026-08-31', 'balance_date' => '2026-09-21'], $data['basis']);
        $usd  = $this->row($data['rows'], null, 'USD');
        $this->assertSame('the liquid balance is zero or negative', $usd['reason']);

        $this->assertPlaneError($this->machine('GET', '/analytics/runway', ['basis' => '5']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/analytics/runway', ['start' => '2026-01-01']), 400, 'invalid_input');
    }

    public function testUncategorizedSummary(): void
    {
        $data = $this->ok('/analytics/uncategorized-summary')['data'];
        $this->assertSame([['2026-07', 'EUR', 2, '34.50'], ['2026-08', 'EUR', 1, '30.00'], ['2026-09', 'EUR', 1, '250.00']], array_map(static fn (array $r): array => [$r['period'], $r['currency_code'], $r['count'], $r['amount']], $data['rows']));
        $this->assertSame([['currency_code' => 'EUR', 'count' => 4, 'amount' => '314.50']], $data['totals']);
        $this->assertSame('2026-06-30', $data['provenance']['start']);
    }

    public function testAnomalies(): void
    {
        $data = $this->ok('/analytics/anomalies', ['start' => '2026-09-01', 'end' => '2026-09-30', 'trailing' => 2])['data'];
        // the gym was paid 30.00 in July and August (a norm with no spread) and not at all in
        // September: a deviation from a constant norm, flagged first, with deviation null
        $this->assertSame(
            [['payee', 'Iron Gym', '0.00', '30.00', null], ['payee', 'Corner Cafe', '250.00', '2.25', '110.11'], ['category', '(no category)', '250.00', '32.25', '96.78']],
            array_map(static fn (array $i): array => [$i['kind'], $i['name'], $i['amount'], $i['trailing_mean'], $i['deviation']], $data['items'])
        );
        $this->assertSame(['below', '-30.00'], [$data['items'][0]['direction'], $data['items'][0]['difference']]);
        $this->assertStringContainsString('never varied', $data['items'][0]['reason']);
        $this->assertSame(['2026-07-01', '2026-08-01'], array_column($data['trailing'], 'start'));
        $this->assertSame(['4.50', '0.00'], array_column($data['items'][1]['trailing_values'], 'amount'));
        // Streamly (15.99, 15.99, 15.99) and its category match their unvarying norm: counted, not flagged
        $this->assertSame(2, $data['summary']['skipped_no_variation']);

        $high = $this->ok('/analytics/anomalies', ['start' => '2026-09-01', 'end' => '2026-09-30', 'trailing' => 2, 'z' => '100'])['data'];
        $this->assertSame(['Iron Gym', 'Corner Cafe'], array_column($high['items'], 'name'), 'z does not gate a flat-norm deviation (it has no z)');
        $min  = $this->ok('/analytics/anomalies', ['start' => '2026-09-01', 'end' => '2026-09-30', 'trailing' => 2, 'min_amount' => '31'])['data'];
        $this->assertNotContains('Iron Gym', array_column($min['items'], 'name'), 'min_amount does gate it');
        $this->assertPlaneError($this->machine('GET', '/analytics/anomalies', ['z' => '2,0']), 400, 'invalid_input');
    }

    public function testPiggyProgress(): void
    {
        $data  = $this->ok('/analytics/piggy-progress')['data'];
        $piggy = $data['piggy_banks'][0];
        $this->assertSame(['Holiday fund', '300.00', '1200.00', '900.00', false, '537.70', '2026-12-31'], [$piggy['name'], $piggy['saved'], $piggy['target'], $piggy['left'], $piggy['on_track'], $piggy['expected_by_now'], $piggy['target_date']]);
        $this->assertSame('225.00', $piggy['monthly_needed']);
    }

    public function testSummaryBoxes(): void
    {
        $data  = $this->ok('/analytics/summary', ['start' => '2026-09-01', 'end' => '2026-09-30'])['data'];
        $boxes = [];
        foreach ($data['boxes'] as $box) {
            $boxes[$box['key']] = $box['value'];
        }
        $this->assertSame('396.94', $boxes['spent-in-EUR']);
        $this->assertSame('3000.00', $boxes['earned-in-EUR']);
        $this->assertSame('2603.06', $boxes['balance-in-EUR']);
        $this->assertSame('30.00', $boxes['bills_unpaid-in-EUR']);
        $this->assertSame('4196.08', $boxes['net_worth-in-EUR']);
        $this->assertSame('-42.00', $boxes['net_worth-in-USD']);
        $this->assertArrayHasKey('left_to_spend-in-EUR', $boxes);
        $this->assertNull($boxes['left_to_spend-in-EUR'], 'no available budget is null, not "0"');
    }

    public function testValidationRefusalsAndNotFound(): void
    {
        $this->assertPlaneError($this->machine('GET', '/analytics/income-vs-expense', ['start' => '2026-07-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/analytics/income-vs-expense', ['start' => '2026-09-01', 'end' => '2026-07-01']), 400, 'invalid_input');
        $env = $this->assertPlaneError($this->machine('GET', '/analytics/income-vs-expense', self::Q3 + ['start_date' => '2026-01-01']), 400, 'invalid_input');
        $this->assertSame(['start_date'], $env['error']['details']['unknown']);
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['interval' => 'week']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['account_ids' => ['999999']]), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['currency_code' => 'ZZZ']), 404, 'not_found');
        $this->assertPlaneError($this->machine('GET', '/analytics/spending-by-category', self::Q3 + ['tags' => ['nope']]), 404, 'not_found');
    }

    public function testCapabilitiesShowTheFamilyLive(): void
    {
        $caps = $this->envelope($this->machine('GET', '/capabilities'))['data'];
        $text = json_encode($caps);
        $this->assertStringNotContainsString('/analytics/summary","methods":["GET"],"tier":{"GET":"read"},"status":{"GET":"planned"', (string) $text);
        $this->assertContains('analytics', $caps['features'] ?? []);
        $this->assertContains('charts', $caps['features'] ?? []);
        $this->assertContains('reports', $caps['features'] ?? []);
    }

    /** @return array<string, mixed> */
    private function ok(string $path, array $query = []): array
    {
        $response = $this->machine('GET', $path, $query);
        $env      = $this->envelope($response);
        $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $this->assertTrue($env['ok']);
        $this->assertSame('read', $env['meta']['tier']);

        return $env;
    }

    /** @param list<array<string, mixed>> $rows */
    private function row(array $rows, ?string $period, string $code): array
    {
        foreach ($rows as $row) {
            if ($row['currency_code'] === $code && (null === $period || $row['period'] === $period)) {
                return $row;
            }
        }
        $this->fail(sprintf('no %s row for %s', $code, (string) $period));
    }

    /** No amount is ever a JSON number (§14.1). */
    private function assertAllAmountsAreStrings(mixed $data): void
    {
        array_walk_recursive($data, function (mixed $value, int|string $key): void {
            if (in_array($key, ['spent', 'amount', 'income', 'expense', 'net', 'limit', 'left', 'balance'], true)) {
                $this->assertTrue(null === $value || is_string($value), sprintf('%s must be a string or null', (string) $key));
            }
        });
    }
}
