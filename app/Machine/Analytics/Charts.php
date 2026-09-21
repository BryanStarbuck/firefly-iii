<?php

/*
 * Charts.php
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

namespace FireflyIII\Machine\Analytics;

use Carbon\Carbon;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Chart-ready series (apis.mdx §10.5) from the analytics calculators — one method per chart the
 * UI draws, and the pivots /charts/series uses to turn any §10.2 answer into series. A chart's
 * numbers are the analytics route's numbers, so a chart can never disagree with the terminal.
 */
final class Charts
{
    /** The account-balances chart draws at most this many account × period points (§15). */
    public const int MAX_ACCOUNT_CELLS = 2400;

    public function __construct(private readonly Ledger $ledger) {}

    /** Spending (by category, budget or tag) per period: one series per bucket and currency. */
    public function fromSpending(array $data, string $chart): array
    {
        $series = new Series($chart, 'period', array_column($data['periods'], 'label'));
        $multi  = count(array_unique(array_column($data['rows'], 'currency_code'))) > 1;
        foreach ($data['rows'] as $row) {
            $series->set($row['key'].'|'.$row['currency_code'], Series::slug($row['name'], $multi ? $row['currency_code'] : null), $row['name'], $row['currency_code'], $row['period'], $row['spent']);
        }

        return $series->render(self::prov($data), ['excluded' => $data['excluded'] ?? []]);
    }

    public function fromIncomeVsExpense(array $data, string $chart = 'income-vs-expense'): array
    {
        return $this->periodFields($data, $chart, ['income' => 'Income', 'expense' => 'Expense', 'net' => 'Net']);
    }

    public function fromCashFlow(array $data, string $chart = 'cash-flow'): array
    {
        return $this->periodFields($data, $chart, ['opening' => 'Opening', 'in' => 'In', 'out' => 'Out', 'transfers_net' => 'Transfers (net)', 'closing' => 'Closing']);
    }

    public function fromNetWorth(array $data, string $chart = 'net-worth'): array
    {
        return $this->periodFields($data, $chart, ['assets' => 'Assets', 'liabilities' => 'Liabilities', 'net' => 'Net worth']);
    }

    public function fromTrend(array $data, string $chart = 'category-trend'): array
    {
        $labels = array_values(array_unique(array_column($data['rows'], 'period')));
        $series = new Series($chart, 'period', $labels);
        foreach ($data['rows'] as $row) {
            $series->set($row['currency_code'], Series::slug($row['name'], $row['currency_code']), sprintf('%s', $row['name']), $row['currency_code'], $row['period'], $row['spent']);
        }

        return $series->render(self::prov($data), ['stats' => $data['stats'], 'excluded' => $data['excluded'] ?? []]);
    }

    /**
     * Budgets as categories on x: limit, spent and left per currency over the range — from the
     * calculator's per_budget section, which adds the stored amounts (never the rounded display
     * strings of the rows).
     */
    public function fromBudgets(array $data, string $chart = 'budget-overview'): array
    {
        $series = new Series($chart, 'category', []);
        foreach ($data['per_budget'] as $sum) {
            $code = $sum['currency_code'];
            $series->set('limit|'.$code, 'limit-'.strtolower($code), 'Limit', $code, $sum['name'], $sum['limit']);
            $series->set('spent|'.$code, 'spent-'.strtolower($code), 'Spent', $code, $sum['name'], $sum['spent']);
            $series->set('left|'.$code, 'left-'.strtolower($code), 'Left', $code, $sum['name'], $sum['left']);
        }

        return $series->render(self::prov($data), ['excluded' => $data['excluded'] ?? []]);
    }

    /** Categories (tags) as categories on x: spent and earned per currency over the range. */
    public function fromOverview(array $spent, array $earned, string $chart): array
    {
        $series = new Series($chart, 'category', []);
        foreach ([['spent', 'Spent', $spent], ['earned', 'Earned', $earned]] as [$field, $title, $data]) {
            foreach ($data['rows'] as $row) {
                $code = $row['currency_code'];
                $series->label($row['name']);
                $series->set($field.'|'.$code, $field.'-'.strtolower($code), $title, $code, $row['name'], $row['spent']);
            }
        }

        return $series->render(self::prov($spent), ['excluded' => $spent['excluded'] ?? []]);
    }

    public function fromPayees(array $data, string $chart = 'payee-leaderboard'): array
    {
        $series = new Series($chart, 'category', []);
        foreach ($data['rows'] as $row) {
            $code = $row['currency_code'];
            $series->set($code, 'amount-'.strtolower($code), 'in' === $data['direction'] ? 'Received' : 'Paid', $code, $row['name'], $row['amount']);
        }

        return $series->render(self::prov($data), ['excluded' => $data['excluded'] ?? []]);
    }

    public function fromUncategorized(array $data, string $chart = 'uncategorized-summary'): array
    {
        // the calculator's by_period section adds the stored amounts once; the chart never
        // re-adds rounded display strings
        $labels = array_values(array_unique(array_column($data['by_period'], 'period')));
        sort($labels);
        $series = new Series($chart, 'period', $labels);
        foreach ($data['by_period'] as $row) {
            $code = (string) $row['currency_code'];
            $series->set($code, 'uncategorized-'.strtolower($code), 'Uncategorised', $code, (string) $row['period'], $row['amount']);
        }

        return $series->render(self::prov($data), ['excluded' => $data['excluded'] ?? []]);
    }

    /**
     * Account balances at the end of each period (Firefly NetWorth helper, virtual balance
     * excluded). A period that ends before the account's first transaction is a gap (null).
     *
     * @return array<string, mixed>
     */
    public function accountBalances(Scope $scope): array
    {
        $periods = Periods::split($scope->start, $scope->end, $scope->interval);
        $cells   = $scope->accounts->count() * count($periods);
        if ($cells > self::MAX_ACCOUNT_CELLS) {
            throw MachineException::invalid(
                sprintf('%d accounts × %d periods is %d balance points; the ceiling is %d.', $scope->accounts->count(), count($periods), $cells, self::MAX_ACCOUNT_CELLS),
                'Narrow the range, use a coarser interval (quarter, year), or pass account_ids[] for the accounts you want drawn',
                ['cells' => $cells, 'max' => self::MAX_ACCOUNT_CELLS, 'accounts' => $scope->accounts->count(), 'periods' => count($periods)],
            );
        }
        $series  = new Series('account-balances', 'period', array_column($periods, 'label'));

        /** @var AccountRepositoryInterface $repository */
        $repository = $this->ledger->repo(AccountRepositoryInterface::class);
        $multi      = [];
        $points     = [];
        foreach ($scope->accounts as $account) {
            /** @var Account $account */
            $first = $repository->oldestJournalDate($account);
            foreach ($periods as $period) {
                if (null === $first || $first->format('Y-m-d') > $period['end']) {
                    continue;
                }
                foreach ($this->ledger->balances(new Collection([$account]), Carbon::parse($period['end'])) as $code => $balance) {
                    if (null !== $scope->currencyCode && $code !== $scope->currencyCode) {
                        continue;
                    }
                    $scope->noteCurrency($code);
                    $multi[(int) $account->id][$code] = true;
                    $points[]                         = [$account, $code, $period['label'], $this->ledger->fmt($balance, $code)];
                }
            }
        }
        foreach ($points as [$account, $code, $label, $value]) {
            $suffix = count($multi[(int) $account->id]) > 1 ? $code : null;
            $series->set(sprintf('%d|%s', $account->id, $code), Series::slug((string) $account->name, $suffix), (string) $account->name, $code, $label, $value);
        }
        $scope->countRows(count($points));

        return $series->render($scope->provenance(['interval' => $scope->interval, 'balances' => 'end of each period, '.Ledger::BALANCE_NOTE]), [
            'notes' => ['a period before the account\'s first transaction is a gap (null), not a zero'],
        ]);
    }

    /** One piggy bank's saved amount over time, with its target alongside. */
    public function piggyBank(array $history): array
    {
        $labels = array_column($history['points'], 'date');
        $series = new Series('piggy-bank', 'date', $labels);
        $code   = $history['code'];
        foreach ($history['points'] as $point) {
            $series->set('saved', 'saved', 'Saved', $code, $point['date'], $this->ledger->fmt($point['saved'], $code));
            $series->set('target', 'target', 'Target', $code, $point['date'], $this->ledger->fmt($history['target'], $code));
        }
        $piggy  = $history['piggy'];

        return $series->render([
            'piggy_bank_id' => (int) $piggy->id,
            'name'          => (string) $piggy->name,
            'events'        => count($history['points']),
            'currencies'    => [$code],
        ], ['notes' => ['saved is the running total of the piggy bank\'s events; target null means it has no target']]);
    }

    /** One subscription's paid and expected amounts per period over the range. */
    public function subscription(Bill $bill, Scope $scope): array
    {
        /** @var BillRepositoryInterface $repository */
        $repository = $this->ledger->repo(BillRepositoryInterface::class);
        $periods    = Periods::split($scope->start, $scope->end, $scope->interval);
        $noneLabel  = $periods[0]['label'];
        $series     = new Series('subscription', 'period', array_column($periods, 'label'));
        $code       = (string) $bill->transactionCurrency->code;
        $this->ledger->rememberCurrency($bill->transactionCurrency);
        $scope->noteCurrency($code);
        $start      = $scope->start->copy()->startOfDay();
        $end        = $scope->end->copy()->endOfDay();
        $paid       = [];
        $other      = 0;
        foreach ($repository->getPaidDatesInRange($bill, $start, $end) as $row) {
            if ((string) $row->transaction_currency_code !== $code) {
                ++$other;

                continue;
            }
            $label        = Periods::labelFor(Carbon::parse((string) $row->date)->format('Y-m-d'), $scope->interval, $noneLabel);
            $paid[$label] = Money::add($paid[$label] ?? '0', Money::abs(Money::strip((string) $row->amount)));
            $scope->countRows(1);
        }
        $expected   = [];
        $average    = Commitments::average($bill);
        foreach ($repository->getPayDatesInRange($bill, $start, $end) as $date) {
            $label            = Periods::labelFor($date->format('Y-m-d'), $scope->interval, $noneLabel);
            $expected[$label] = Money::add($expected[$label] ?? '0', $average);
        }
        foreach ($periods as $period) {
            $series->set('paid', 'paid', 'Paid', $code, $period['label'], $this->ledger->fmt($paid[$period['label']] ?? null, $code));
            $series->set('expected', 'expected', 'Expected', $code, $period['label'], $this->ledger->fmt($expected[$period['label']] ?? null, $code));
        }

        return $series->render($scope->provenance(['subscription_id' => (int) $bill->id, 'name' => (string) $bill->name, 'interval' => $scope->interval]), [
            'notes' => array_values(array_filter([
                'expected = the midpoint of the amount range for each date Firefly expects the subscription; null = none expected / none paid in that period',
                $other > 0 ? sprintf('%d payment(s) in another currency are not drawn', $other) : null,
            ])),
        ]);
    }

    /**
     * Rows with a period and several amount fields → one series per field and currency.
     *
     * @param array<string, string> $fields field => label
     */
    private function periodFields(array $data, string $chart, array $fields): array
    {
        $labels = array_key_exists('periods', $data) ? array_column($data['periods'], 'label') : array_values(array_unique(array_column($data['rows'], 'period')));
        $series = new Series($chart, 'period', $labels);
        $codes  = array_values(array_unique(array_column($data['rows'], 'currency_code')));
        sort($codes);
        // declare every series first (field order, then currency) so the order is stable
        foreach ($fields as $field => $title) {
            foreach ($codes as $code) {
                foreach ($data['rows'] as $row) {
                    if ($row['currency_code'] === $code && array_key_exists($field, $row)) {
                        $series->set($field.'|'.$code, $field.'-'.strtolower($code), $title, $code, $row['period'], $row[$field]);
                    }
                }
            }
        }

        return $series->render(self::prov($data), ['excluded' => $data['excluded'] ?? []]);
    }

    private static function prov(array $data): array
    {
        return (array) ($data['provenance'] ?? []);
    }
}
