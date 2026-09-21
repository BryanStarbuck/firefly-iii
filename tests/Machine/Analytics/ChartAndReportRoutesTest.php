<?php

/*
 * ChartAndReportRoutesTest.php
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
 * pm/apis.mdx §10.5 (chart-ready series: decimal strings, null gaps, one route per chart and
 * /charts/series) and §8.9 (the six reports' numbers), over the invented AnalyticsFixture ledger.
 *
 * @internal
 *
 * @coversNothing
 */
final class ChartAndReportRoutesTest extends MachineTestCase
{
    use AnalyticsFixture;

    private const array Q3   = ['start' => '2026-07-01', 'end' => '2026-09-30'];
    private const array JULY = ['start' => '2026-07-01', 'end' => '2026-07-31'];

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

    public function testSpendingByCategoryChartHasNullGaps(): void
    {
        $data = $this->data('/charts/spending-by-category', self::Q3);
        $this->assertSame('spending-by-category', $data['chart']);
        $this->assertSame(['kind' => 'period', 'labels' => ['2026-07', '2026-08', '2026-09']], $data['x']);
        $series = $this->byKey($data['series']);
        $this->assertSame(['200.40', '110.10', '130.95'], $series['groceries-eur']['values']);
        $this->assertSame(['42.00', null, null], $series['dining-usd']['values'], 'no data is null, never "0"');
        $this->assertSame('USD', $series['dining-usd']['currency_code']);
        $this->assertSame('excluded', $data['provenance']['transfers']);
    }

    public function testIncomeVsExpenseAndNetWorthCharts(): void
    {
        $ive = $this->byKey($this->data('/charts/income-vs-expense', self::Q3)['series']);
        $this->assertSame(['250.89', '156.09', '396.94'], $ive['expense-eur']['values']);
        $this->assertSame(['42.00', null, null], $ive['expense-usd']['values']);

        $nw  = $this->byKey($this->data('/charts/net-worth', self::Q3)['series']);
        $this->assertSame(['-1250.89', '1593.02', '4196.08'], $nw['net-eur']['values']);
        $this->assertSame(['-5000.00', '-5000.00', '-5000.00'], $nw['liabilities-eur']['values']);
    }

    public function testAccountBalancesChart(): void
    {
        $data   = $this->data('/charts/account-balances', self::Q3);
        $series = $this->byKey($data['series']);
        $this->assertSame(['3249.11', '6093.02', '8696.08'], $series['northbank-checking-4021']['values']);
        $this->assertSame(['500.00', '500.00', '500.00'], $series['meridian-savings-7734']['values']);

        // a period that ends before the account's first transaction is a gap
        $early = $this->byKey($this->data('/charts/account-balances', ['start' => '2026-05-01', 'end' => '2026-07-31'])['series']);
        $this->assertSame([null, null, '500.00'], $early['meridian-savings-7734']['values']);
        $this->assertSame([null, '1000.00', '3249.11'], $early['northbank-checking-4021']['values']);
    }

    public function testOverviewCharts(): void
    {
        $budget = $this->data('/charts/budget-overview', self::Q3);
        $this->assertSame(['kind' => 'category', 'labels' => ['Household']], $budget['x']);
        $b      = $this->byKey($budget['series']);
        $this->assertSame([['350.00'], ['441.45'], ['-91.45']], [$b['limit-eur']['values'], $b['spent-eur']['values'], $b['left-eur']['values']]);

        $cat    = $this->data('/charts/category-overview', self::JULY + ['interval' => 'month']);
        $this->assertContains('Groceries', $cat['x']['labels']);
        $c      = $this->byKey($cat['series']);
        $gi     = array_search('Groceries', $cat['x']['labels'], true);
        $this->assertSame('200.40', $c['spent-eur']['values'][$gi]);
        $this->assertStringContainsString('interval=month is not used', implode(' ', $cat['notes']));

        $tag    = $this->data('/charts/tag-overview', self::Q3);
        $t      = $this->byKey($tag['series']);
        $vi     = array_search('vacation', $tag['x']['labels'], true);
        $this->assertSame('250.00', $t['spent-eur']['values'][$vi]);
    }

    public function testPiggyAndSubscriptionCharts(): void
    {
        $piggy = $this->data('/charts/piggy-bank/'.$this->ids['piggy']);
        $this->assertSame(['kind' => 'date', 'labels' => ['2026-07-16', '2026-08-16']], $piggy['x']);
        $p     = $this->byKey($piggy['series']);
        $this->assertSame(['200.00', '300.00'], $p['saved']['values']);
        $this->assertSame(['1200.00', '1200.00'], $p['target']['values']);
        $this->assertPlaneError($this->machine('GET', '/charts/piggy-bank/999999'), 404, 'not_found');

        $sub   = $this->data('/charts/subscription/'.$this->ids['gym'], self::Q3);
        $s     = $this->byKey($sub['series']);
        $this->assertSame(['30.00', '30.00', null], $s['paid']['values']);
        $this->assertSame(['30.00', '30.00', '30.00'], $s['expected']['values']);
        $this->assertPlaneError($this->machine('GET', '/charts/subscription/999999', self::Q3), 404, 'not_found');
    }

    public function testGenericSeries(): void
    {
        $cash = $this->data('/charts/series', self::Q3 + ['source' => '/analytics/cash-flow']);
        $this->assertSame('/analytics/cash-flow', $cash['source']);
        $c    = $this->byKey($cash['series']);
        $this->assertSame(['3749.11', '6593.02', '9196.08'], $c['closing-eur']['values']);

        $trend = $this->data('/charts/series', self::Q3 + ['source' => 'category-trend', 'category_name' => 'Dining']);
        $this->assertSame(['42.00', null, null], $trend['series'][0]['values']);

        $this->assertPlaneError($this->machine('GET', '/charts/series', self::Q3), 400, 'invalid_input');
        $env = $this->assertPlaneError($this->machine('GET', '/charts/series', self::Q3 + ['source' => '/analytics/runway']), 400, 'invalid_input');
        $this->assertContains('/analytics/cash-flow', $env['error']['details']['supported']);
        $this->assertPlaneError($this->machine('GET', '/charts/series', self::Q3 + ['source' => '/analytics/cash-flow', 'bogus' => '1']), 400, 'invalid_input');
    }

    public function testDefaultReport(): void
    {
        $data     = $this->data('/reports/default', self::JULY);
        $accounts = [];
        foreach ($data['accounts'] as $a) {
            $accounts[$a['name']] = [$a['currency_code'], $a['start_balance'], $a['end_balance'], $a['difference']];
        }
        $this->assertSame(['EUR', '1000.00', '3249.11', '2249.11'], $accounts['Northbank checking 4021']);
        $this->assertSame(['EUR', '0.00', '500.00', '500.00'], $accounts['Meridian savings 7734']);
        $this->assertSame(['USD', '0.00', '-42.00', '-42.00'], $accounts['Northbank USD 5512']);
        $this->assertSame(['currency_code' => 'EUR', 'income' => '3000.00', 'expense' => '250.89', 'net' => '2749.11'], $data['totals'][0]);
        $this->assertSame(['2026-07', '250.00', '200.40'], [$data['budgets'][0]['period'], $data['budgets'][0]['limit'], $data['budgets'][0]['spent']]);
        $groceries = array_values(array_filter($data['categories'], static fn (array $r): bool => 'Groceries' === $r['name']))[0];
        $this->assertSame(['200.40', null, '-200.40'], [$groceries['spent'], $groceries['earned'], $groceries['net']]);
    }

    public function testAuditReportRunningBalance(): void
    {
        $data = $this->data('/reports/audit', self::JULY + ['account_ids' => [(string) $this->acct['checking']->id]]);
        $this->assertSame(
            ['4000.00', '3970.00', '3849.60', '3833.61', '3829.11', '3329.11', '3249.11'],
            array_column($data['journals'], 'balance_after')
        );
        $this->assertSame(['3000.00', '-30.00'], array_slice(array_column($data['journals'], 'amount'), 0, 2));
        $this->assertSame('1000.00', $data['journals'][0]['balance_before']);
        $this->assertSame(['1000.00', '3249.11', 7], [$data['accounts'][0]['balance_day_before'], $data['accounts'][0]['end_balance'], $data['accounts'][0]['journals']]);
    }

    public function testBudgetCategoryTagAndDoubleReports(): void
    {
        $budget = $this->data('/reports/budget', self::Q3 + ['budget_ids' => [(string) $this->ids['household']]]);
        $this->assertSame(['250.00', '100.00', null], array_column($budget['budgets'], 'limit'));

        $cat    = $this->data('/reports/category', self::Q3 + ['category_names' => ['Groceries']]);
        $this->assertSame(['200.40', '110.10', '130.95'], array_column($cat['categories'], 'spent'));

        $tag    = $this->data('/reports/tag', self::Q3 + ['tags' => ['vacation']]);
        $this->assertSame([['2026-09', 'vacation', '250.00']], array_map(static fn (array $r): array => [$r['period'], $r['name'], $r['spent']], $tag['tags']));

        $double = $this->data('/reports/double', self::JULY);
        $rows   = [];
        foreach ($double['counterparties'] as $r) {
            $rows[$r['name'].'/'.$r['currency_code']] = [$r['in'], $r['out'], $r['net']];
        }
        $this->assertSame(['3000.00', '0.00', '3000.00'], $rows['Acme LLC payroll/EUR']);
        $this->assertSame(['0.00', '200.40', '-200.40'], $rows['Fresh Grocer/EUR']);
        $this->assertSame(['0.00', '42.00', '-42.00'], $rows['Corner Cafe/USD']);

        $only   = $this->data('/reports/double', self::JULY + ['counterparty_names' => ['Streamly']]);
        $this->assertSame(['Streamly'], array_column($only['counterparties'], 'name'));
        $this->assertPlaneError($this->machine('GET', '/reports/double', self::JULY + ['counterparty_names' => ['Northbank checking 4021']]), 404, 'not_found');
    }

    public function testReportRefusals(): void
    {
        $this->assertPlaneError($this->machine('GET', '/reports/default', ['start' => '2026-07-01']), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/reports/audit', self::JULY + ['budget_ids' => ['1']]), 400, 'invalid_input');
        $this->assertPlaneError($this->machine('GET', '/reports/budget', self::JULY + ['budget_ids' => ['999999']]), 404, 'not_found');
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

    /** @param list<array<string, mixed>> $series @return array<string, array<string, mixed>> */
    private function byKey(array $series): array
    {
        $out = [];
        foreach ($series as $s) {
            $out[$s['key']] = $s;
        }

        return $out;
    }
}
