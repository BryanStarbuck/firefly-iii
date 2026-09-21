<?php

/*
 * Reports.php
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

use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Generator\Report\Audit\MonthReportGenerator as AuditReportGenerator;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountTaskerInterface;
use Illuminate\Support\Collection;

/**
 * The numbers behind Firefly's six report types (apis.mdx §8.9) — the HTML stays in the UI.
 *
 *   default   AccountTasker::getAccountReport (per-account balance change) + income/expense
 *             totals + budgets + categories
 *   audit     Firefly's Audit report generator (getAuditReport): every journal with the running
 *             balance before and after it
 *   budget    per budget per budget period: limit, spent, left
 *   category  spent and earned per category per period
 *   tag       spent and earned per tag per period
 *   double    per expense/revenue account: in, out, net
 *
 * Every report answers in sections (arrays of rows), per currency, with its provenance.
 */
final class Reports
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @return array<string, mixed> */
    public function default(Scope $scope): array
    {
        /** @var AccountTaskerInterface $tasker */
        $tasker   = $this->ledger->repo(AccountTaskerInterface::class);
        $report   = $tasker->getAccountReport($scope->accounts, $scope->start->copy()->startOfDay(), $scope->end->copy()->endOfDay());
        $accounts = [];
        foreach ($report['accounts'] as $entry) {
            $code       = (string) $entry['currency_code'];
            $start      = Money::strip((string) $entry['start_balance']);
            $end        = Money::strip((string) $entry['end_balance']);
            $accounts[] = [
                'account_id'    => (int) $entry['id'],
                'name'          => (string) $entry['name'],
                'currency_code' => $code,
                'start_balance' => $this->ledger->fmt($start, $code),
                'end_balance'   => $this->ledger->fmt($end, $code),
                'difference'    => $this->ledger->fmt(Money::sub($end, $start), $code),
            ];
            $scope->noteCurrency($code);
        }
        usort($accounts, static fn (array $a, array $b): int => $a['account_id'] <=> $b['account_id']);
        $none        = $scope->withAccounts($scope->accounts, $scope->accountsExplicit);
        $noneScope   = new Scope($none->start, $none->end, $none->accounts, $none->accountsExplicit, $none->categories, $none->budgets, $none->tags, $none->currencyCode, $none->includeTransfers, 'none');
        $flows       = new Flows($this->ledger)->incomeVsExpense($noneScope);
        $spending    = new Spending($this->ledger);
        $categories  = $this->spentAndEarned($spending->by($noneScope, 'category'), $spending->by($noneScope, 'category', null, true), 'category');
        $budgets     = new Budgets($this->ledger)->performance($noneScope);
        foreach ($noneScope->currenciesFound() as $code) {
            $scope->noteCurrency($code);
        }
        $scope->countRows($noneScope->provenance()['rows']);

        return [
            'accounts'   => $accounts,
            'totals'     => $flows['totals'],
            'budgets'    => $budgets['rows'],
            'categories' => $categories,
            'notes'      => [
                'accounts: Firefly\'s account report (balance at the end of the day before start, and at end)',
                'totals: income and expense in the range, per currency; budgets: per budget limit; categories: spent and earned',
            ],
            'excluded'   => $scope->excluded(),
            'provenance' => $scope->provenance(['report' => 'default']),
        ];
    }

    /** @return array<string, mixed> */
    public function audit(Scope $scope): array
    {
        /** @var AuditReportGenerator $generator */
        $generator = app(AuditReportGenerator::class);
        $generator->setStartDate($scope->start->copy()->startOfDay());
        $generator->setEndDate($scope->end->copy()->endOfDay());
        $generator->setAccounts($scope->accounts);
        $dayBefore = $scope->start->copy()->subDay()->endOfDay();
        $rows      = [];
        $summaries = [];
        foreach ($scope->accounts as $account) {
            /** @var Account $account */
            $audit  = $generator->getAuditReport($account, $dayBefore);
            $code   = (string) $audit['currency']->code;
            $this->ledger->rememberCurrency($audit['currency']);
            $scope->noteCurrency($code);
            $count  = 0;
            foreach ($audit['journals'] as $journal) {
                if (null !== $scope->currencyCode && $scope->currencyCode !== $code) {
                    continue;
                }
                $before = Money::strip((string) $journal['balance_before']);
                $after  = Money::strip((string) $journal['balance_after']);
                $rows[] = [
                    'account_id'     => (int) $account->id,
                    'account_name'   => (string) $account->name,
                    'date'           => Ledger::date($journal),
                    'group_id'       => (int) $journal['transaction_group_id'],
                    'journal_id'     => (int) $journal['transaction_journal_id'],
                    'type'           => strtolower((string) $journal['transaction_type_type']),
                    'description'    => (string) $journal['description'],
                    'currency_code'  => $code,
                    'balance_before' => $this->ledger->fmt($before, $code),
                    'amount'         => $this->ledger->fmt(Money::sub($after, $before), $code),
                    'balance_after'  => $this->ledger->fmt($after, $code),
                    'from'           => (string) ($journal['source_account_name'] ?? ''),
                    'to'             => (string) ($journal['destination_account_name'] ?? ''),
                    'category'       => $journal['category_name'] ?? null,
                    'budget'         => $journal['budget_name'] ?? null,
                    'bill'           => $journal['bill_name'] ?? null,
                ];
                ++$count;
            }
            $scope->countRows($count);
            $summaries[] = [
                'account_id'         => (int) $account->id,
                'name'               => (string) $account->name,
                'currency_code'      => $code,
                'balance_day_before' => $this->ledger->fmt(Money::strip((string) $audit['dayBeforeBalance']['balance']), $code),
                'end_balance'        => $this->ledger->fmt(Money::strip((string) $audit['endBalance']), $code),
                'journals'           => $count,
            ];
        }

        return [
            'journals'   => $rows,
            'accounts'   => $summaries,
            'notes'      => [
                'Firefly\'s audit report: every journal on each account in date order, with the running balance before and after it (in the account\'s currency, virtual balance included, as the audit report shows it)',
                'amount is signed from the account\'s side: money in is positive, money out negative',
            ],
            'excluded'   => [],
            'provenance' => $scope->provenance(['report' => 'audit', 'balance_day_before' => $dayBefore->format('Y-m-d')]),
        ];
    }

    /** @return array<string, mixed> */
    public function budget(Scope $scope): array
    {
        $performance = new Budgets($this->ledger)->performance($scope);

        return [
            'budgets'                  => $performance['rows'],
            'totals'                   => $performance['totals'],
            'budgets_without_activity' => $performance['budgets_without_activity'],
            'notes'                    => $performance['notes'],
            'excluded'                 => $performance['excluded'],
            'provenance'               => array_merge($performance['provenance'], ['report' => 'budget']),
        ];
    }

    /** @return array<string, mixed> */
    public function grouped(Scope $scope, string $by): array
    {
        $spending = new Spending($this->ledger);
        $rows     = $this->spentAndEarned($spending->by($scope, $by), $spending->by($scope, $by, null, true), $by);

        return [
            sprintf('%s', 'category' === $by ? 'categories' : 'tags') => $rows,
            'notes'                                                  => array_values(array_filter([
                'spent = money out, earned = money in, net = earned − spent, per period and currency',
                'tag' === $by ? 'a transaction with several tags counts under each of them' : null,
            ])),
            'excluded'                                               => $scope->excluded(),
            'provenance'                                             => $scope->provenance(['report' => $by, 'interval' => $scope->interval]),
        ];
    }

    /**
     * Per expense/revenue account: in (it paid you), out (you paid it), net.
     *
     * @param Collection<int, Account> $counterparties empty: every expense and revenue account
     *
     * @return array<string, mixed>
     */
    public function double(Scope $scope, Collection $counterparties): array
    {
        $wanted   = array_map('intval', $counterparties->pluck('id')->all());
        $rows     = [];
        foreach ($this->ledger->journals($scope, [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value]) as $journal) {
            $type = (string) $journal['transaction_type_type'];
            $out  = TransactionTypeEnum::WITHDRAWAL->value === $type;
            $id   = (int) ($out ? $journal['destination_account_id'] : $journal['source_account_id']);
            if ([] !== $wanted && !in_array($id, $wanted, true)) {
                continue;
            }
            $code = (string) $journal['currency_code'];
            $key  = sprintf('%d|%s', $id, $code);
            $rows[$key] ??= [
                'account_id'    => $id,
                'name'          => (string) ($out ? $journal['destination_account_name'] : $journal['source_account_name']),
                'type'          => (string) ($out ? $journal['destination_account_type'] : $journal['source_account_type']),
                'currency_code' => $code,
                'in'            => '0',
                'out'           => '0',
                'count'         => 0,
            ];
            $rows[$key][$out ? 'out' : 'in'] = Money::add($rows[$key][$out ? 'out' : 'in'], Ledger::amount($journal));
            ++$rows[$key]['count'];
        }
        ksort($rows, SORT_NATURAL);
        $list = [];
        foreach ($rows as $row) {
            $code   = $row['currency_code'];
            $list[] = array_merge($row, [
                'in'  => $this->ledger->fmt($row['in'], $code),
                'out' => $this->ledger->fmt($row['out'], $code),
                'net' => $this->ledger->fmt(Money::sub($row['in'], $row['out']), $code),
            ]);
        }

        return [
            'counterparties' => $list,
            'notes'          => ['in = deposits from the account, out = withdrawals to it, net = in − out'],
            'excluded'       => ['transfers', 'opening balances', 'reconciliations'],
            'provenance'     => $scope->provenance(['report' => 'double', 'counterparties' => [] === $wanted ? 'all expense and revenue accounts' : $wanted]),
        ];
    }

    /**
     * Merge a spent and an earned grouping into one row per (period, bucket, currency).
     *
     * @param array<string, mixed> $spent
     * @param array<string, mixed> $earned
     *
     * @return list<array<string, mixed>>
     */
    private function spentAndEarned(array $spent, array $earned, string $by): array
    {
        $idKey = sprintf('%s_id', $by);
        $merge = [];
        foreach ([['spent', $spent['rows']], ['earned', $earned['rows']]] as [$field, $rows]) {
            foreach ($rows as $row) {
                $key = sprintf('%s|%s|%s', $row['period'], $row['key'], $row['currency_code']);
                $merge[$key] ??= [
                    'period'        => $row['period'],
                    'period_start'  => $row['period_start'],
                    'period_end'    => $row['period_end'],
                    $idKey          => $row[$idKey],
                    'name'          => $row['name'],
                    'currency_code' => $row['currency_code'],
                    'spent'         => null,
                    'earned'        => null,
                ];
                $merge[$key][$field] = $row['spent'];
            }
        }
        ksort($merge, SORT_NATURAL);
        $out   = [];
        foreach ($merge as $row) {
            $row['net'] = $this->ledger->fmt(Money::sub($row['earned'] ?? '0', $row['spent'] ?? '0'), $row['currency_code']);
            $out[]      = $row;
        }

        return $out;
    }
}
