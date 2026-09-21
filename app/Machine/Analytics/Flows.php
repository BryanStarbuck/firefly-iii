<?php

/*
 * Flows.php
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
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Money over time — apis.mdx §10.2: income vs expense, cash flow over asset accounts, and net
 * worth. Flows come from Firefly's GroupCollector; balances from Firefly's NetWorth helper.
 */
final class Flows
{
    /** Per-account net-worth detail is included up to this many account × period cells. */
    public const int MAX_ACCOUNT_CELLS = 2400;

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * In, out and net per interval, per currency. A period with no transactions in a currency has
     * no row (absent is not zero, §14.2).
     *
     * @return array<string, mixed>
     */
    public function incomeVsExpense(Scope $scope): array
    {
        $periods   = Periods::split($scope->start, $scope->end, $scope->interval);
        $noneLabel = $periods[0]['label'];
        $cells     = [];
        foreach ($this->ledger->journals($scope, Ledger::flowTypes($scope)) as $journal) {
            $dir    = Ledger::direction($scope, $journal);
            $code   = (string) $journal['currency_code'];
            $label  = Periods::labelFor(Ledger::date($journal), $scope->interval, $noneLabel);
            $amount = Ledger::amount($journal);
            $cells[$label][$code] ??= ['income' => '0', 'expense' => '0', 'income_count' => 0, 'expense_count' => 0];
            if ($dir['in']) {
                $cells[$label][$code]['income'] = Money::add($cells[$label][$code]['income'], $amount);
                ++$cells[$label][$code]['income_count'];
            }
            if ($dir['out']) {
                $cells[$label][$code]['expense'] = Money::add($cells[$label][$code]['expense'], $amount);
                ++$cells[$label][$code]['expense_count'];
            }
        }
        $rows   = [];
        $totals = [];
        foreach ($periods as $period) {
            $byCode = $cells[$period['label']] ?? [];
            ksort($byCode);
            foreach ($byCode as $code => $cell) {
                $rows[]        = [
                    'period'        => $period['label'],
                    'period_start'  => $period['start'],
                    'period_end'    => $period['end'],
                    'currency_code' => $code,
                    'income'        => $this->ledger->fmt($cell['income'], $code),
                    'expense'       => $this->ledger->fmt($cell['expense'], $code),
                    'net'           => $this->ledger->fmt(Money::sub($cell['income'], $cell['expense']), $code),
                    'income_count'  => $cell['income_count'],
                    'expense_count' => $cell['expense_count'],
                ];
                $totals[$code] ??= ['income' => '0', 'expense' => '0'];
                $totals[$code]['income']  = Money::add($totals[$code]['income'], $cell['income']);
                $totals[$code]['expense'] = Money::add($totals[$code]['expense'], $cell['expense']);
            }
        }
        ksort($totals);
        $totalRows = [];
        foreach ($totals as $code => $t) {
            $totalRows[] = [
                'currency_code' => $code,
                'income'        => $this->ledger->fmt($t['income'], $code),
                'expense'       => $this->ledger->fmt($t['expense'], $code),
                'net'           => $this->ledger->fmt(Money::sub($t['income'], $t['expense']), $code),
            ];
        }

        return [
            'interval'   => $scope->interval,
            'periods'    => $periods,
            'rows'       => $rows,
            'totals'     => $totalRows,
            'notes'      => ['net = income − expense; expense and income are positive amounts'],
            'excluded'   => $scope->excluded(),
            'provenance' => $scope->provenance(['interval' => $scope->interval]),
        ];
    }

    /**
     * Opening, in, out and closing per interval over asset accounts. The identity
     * opening + in − out + transfers_net + adjustments = closing is checked per row and reported
     * (`reconciles`), never forced.
     *
     * @return array<string, mixed>
     */
    public function cashFlow(Scope $scope): array
    {
        if (!$scope->accountsExplicit) {
            $scope = $scope->withAccounts($this->ledger->accountsOfTypes(Ledger::ASSET_TYPES), false);
        }
        $periods   = Periods::split($scope->start, $scope->end, $scope->interval);
        $noneLabel = $periods[0]['label'];
        $types     = [
            TransactionTypeEnum::WITHDRAWAL->value,
            TransactionTypeEnum::DEPOSIT->value,
            TransactionTypeEnum::TRANSFER->value,
            TransactionTypeEnum::OPENING_BALANCE->value,
            TransactionTypeEnum::RECONCILIATION->value,
            TransactionTypeEnum::LIABILITY_CREDIT->value,
        ];
        $flows     = [];
        foreach ($this->ledger->journals($scope, $types) as $journal) {
            $type    = (string) $journal['transaction_type_type'];
            $code    = (string) $journal['currency_code'];
            $label   = Periods::labelFor(Ledger::date($journal), $scope->interval, $noneLabel);
            $amount  = Ledger::amount($journal);
            $leaves  = $scope->hasAccount((int) $journal['source_account_id']);
            $arrives = $scope->hasAccount((int) $journal['destination_account_id']);
            $flows[$label][$code] ??= ['in' => '0', 'out' => '0', 'transfers_in' => '0', 'transfers_out' => '0', 'adjustments' => '0', 'count' => 0];
            $cell    = &$flows[$label][$code];
            ++$cell['count'];
            $isTransfer = TransactionTypeEnum::TRANSFER->value === $type;
            $isAdjust   = in_array($type, [TransactionTypeEnum::OPENING_BALANCE->value, TransactionTypeEnum::RECONCILIATION->value, TransactionTypeEnum::LIABILITY_CREDIT->value], true);
            if ($arrives) {
                if ($isAdjust) {
                    $cell['adjustments'] = Money::add($cell['adjustments'], $amount);
                } elseif ($isTransfer && !$scope->includeTransfers) {
                    $cell['transfers_in'] = Money::add($cell['transfers_in'], $amount);
                } else {
                    $cell['in'] = Money::add($cell['in'], $amount);
                }
            }
            if ($leaves) {
                if ($isAdjust) {
                    $cell['adjustments'] = Money::sub($cell['adjustments'], $amount);
                } elseif ($isTransfer && !$scope->includeTransfers) {
                    $cell['transfers_out'] = Money::add($cell['transfers_out'], $amount);
                } else {
                    $cell['out'] = Money::add($cell['out'], $amount);
                }
            }
            unset($cell);
        }

        $rows = [];
        foreach ($periods as $period) {
            $opening = $this->ledger->balances($scope->accounts, Carbon::parse($period['start'])->subDay());
            $closing = $this->ledger->balances($scope->accounts, Carbon::parse($period['end']));
            $codes   = array_values(array_unique([...array_keys($opening), ...array_keys($closing), ...array_keys($flows[$period['label']] ?? [])]));
            sort($codes);
            foreach ($codes as $code) {
                $cell         = $flows[$period['label']][$code] ?? ['in' => '0', 'out' => '0', 'transfers_in' => '0', 'transfers_out' => '0', 'adjustments' => '0', 'count' => 0];
                $open         = $opening[$code] ?? null;
                $close        = $closing[$code] ?? null;
                $transfersNet = Money::sub($cell['transfers_in'], $cell['transfers_out']);
                $reconciles   = null;
                if (null !== $open && null !== $close) {
                    $expected   = Money::add(Money::add(Money::sub(Money::add($open, $cell['in']), $cell['out']), $transfersNet), $cell['adjustments']);
                    $reconciles = 0 === Money::compare($expected, $close);
                }
                $rows[]       = [
                    'period'        => $period['label'],
                    'period_start'  => $period['start'],
                    'period_end'    => $period['end'],
                    'currency_code' => $code,
                    'opening'       => $this->ledger->fmt($open, $code),
                    'in'            => $this->ledger->fmt($cell['in'], $code),
                    'out'           => $this->ledger->fmt($cell['out'], $code),
                    'transfers_net' => $this->ledger->fmt($transfersNet, $code),
                    'adjustments'   => $this->ledger->fmt($cell['adjustments'], $code),
                    'closing'       => $this->ledger->fmt($close, $code),
                    'count'         => $cell['count'],
                    'reconciles'    => $reconciles,
                ];
            }
        }

        return [
            'interval'   => $scope->interval,
            'periods'    => $periods,
            'rows'       => $rows,
            'notes'      => [
                'opening/closing are Firefly balances (NetWorth helper, virtual balance excluded) at the end of the day before the period and the last day of it',
                'transfers_net = transfers in − transfers out of these accounts (a transfer between two of them nets to zero); with include_transfers=true transfers are folded into in/out instead',
                'adjustments = opening balances, reconciliations and liability credits (never income or spending)',
                'reconciles says whether opening + in − out + transfers_net + adjustments = closing; false points at foreign-currency or converted balances, and is reported, never forced',
            ],
            'excluded'   => [],
            'provenance' => $scope->provenance(['interval' => $scope->interval, 'balances' => 'virtual balance excluded']),
        ];
    }

    /**
     * Assets, liabilities and net per interval (the balance at each period's last day), per
     * currency, and per account. Accounts whose include_net_worth is off are left out, and named.
     *
     * @return array<string, mixed>
     */
    public function netWorth(Scope $scope): array
    {
        $accounts = $scope->accountsExplicit
            ? $scope->accounts
            : $this->ledger->accountsOfTypes([...Ledger::ASSET_TYPES, ...Ledger::LIABILITY_TYPES], true);

        /** @var AccountRepositoryInterface $repository */
        $repository = $this->ledger->repo(AccountRepositoryInterface::class);
        $skipped    = [];
        $included   = $accounts->filter(static function (Account $account) use ($repository, &$skipped): bool {
            $flag = $repository->getMetaValue($account, 'include_net_worth');
            if (null !== $flag && '1' !== $flag && 'true' !== $flag) {
                $skipped[] = ['account_id' => (int) $account->id, 'name' => (string) $account->name];

                return false;
            }

            return true;
        })->values();
        $assets      = $included->filter(static fn (Account $a): bool => AccountTypeEnum::ASSET->value === $a->accountType?->type)->values();
        $liabilities = $included->filter(static fn (Account $a): bool => in_array($a->accountType?->type, Ledger::LIABILITY_TYPES, true))->values();
        $scope       = $scope->withAccounts($included, $scope->accountsExplicit);
        $periods     = Periods::split($scope->start, $scope->end, $scope->interval);
        $rows        = [];
        foreach ($periods as $period) {
            $at    = Carbon::parse($period['end']);
            $a     = $this->ledger->balances($assets, $at);
            $l     = $this->ledger->balances($liabilities, $at);
            $codes = array_values(array_unique([...array_keys($a), ...array_keys($l)]));
            sort($codes);
            foreach ($codes as $code) {
                $scope->noteCurrency($code);
                $assetSum = $a[$code] ?? '0';
                $liabSum  = $l[$code] ?? '0';
                $rows[]   = [
                    'period'        => $period['label'],
                    'date'          => $period['end'],
                    'currency_code' => $code,
                    'assets'        => $this->ledger->fmt($assetSum, $code),
                    'liabilities'   => $this->ledger->fmt($liabSum, $code),
                    'net'           => $this->ledger->fmt(Money::add($assetSum, $liabSum), $code),
                ];
            }
        }

        $perAccount = [];
        $detail     = $included->count() * count($periods) <= self::MAX_ACCOUNT_CELLS;
        if ($detail) {
            foreach ($included as $account) {
                $balances = [];
                foreach ($periods as $period) {
                    foreach ($this->ledger->balances(new Collection([$account]), Carbon::parse($period['end'])) as $code => $balance) {
                        $balances[] = ['period' => $period['label'], 'currency_code' => $code, 'balance' => $this->ledger->fmt($balance, $code)];
                    }
                }
                $perAccount[] = [
                    'account_id' => (int) $account->id,
                    'name'       => (string) $account->name,
                    'type'       => (string) $account->accountType?->type,
                    'kind'       => AccountTypeEnum::ASSET->value === $account->accountType?->type ? 'asset' : 'liability',
                    'balances'   => $balances,
                ];
            }
        }

        return [
            'interval'   => $scope->interval,
            'periods'    => $periods,
            'rows'       => $rows,
            'accounts'   => $detail ? $perAccount : null,
            'skipped'    => $skipped,
            'notes'      => array_values(array_filter([
                'net = assets + liabilities; liabilities are signed as Firefly stores them (money owed is negative)',
                'balances are at the end of each period\'s last day (Firefly NetWorth helper, virtual balance excluded)',
                [] === $skipped ? null : 'accounts with include_net_worth off are left out (see skipped)',
                $detail ? null : sprintf('per-account detail omitted: more than %d account × period cells — narrow the range or pass account_ids', self::MAX_ACCOUNT_CELLS),
            ])),
            'excluded'   => [],
            'provenance' => $scope->provenance(['interval' => $scope->interval, 'balances' => 'virtual balance excluded']),
        ];
    }
}
