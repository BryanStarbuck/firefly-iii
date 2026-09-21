<?php

/*
 * Summary.php
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

use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Navigation;

/**
 * GET /analytics/summary — the dashboard boxes (apis.mdx §10.2), composed the way upstream's
 * Summary\BasicController::basic() composes them, from the same repositories:
 *
 *   balance / spent / earned  withdrawals and deposits in the range (GroupCollector)
 *   bills_paid / bills_unpaid BillRepository::sumPaidInRange / sumUnpaidInRange
 *   left_to_spend             available budget − budgeted spending, in the operator's budget
 *                             period (viewRange) containing `start` — null when no available
 *                             budget is set (not "0": nothing was decided)
 *   net_worth                 NetWorth helper over active asset + liability accounts with
 *                             include_net_worth on, at `end`
 *
 * One box per (key, currency); never summed across currencies.
 */
final class Summary
{
    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function boxes(Scope $scope): array
    {
        $boxes = [];
        $flows = [];
        foreach ($this->ledger->journals($scope, Ledger::flowTypes($scope)) as $journal) {
            $dir  = Ledger::direction($scope, $journal);
            $code = (string) $journal['currency_code'];
            $flows[$code] ??= ['spent' => '0', 'earned' => '0'];
            if ($dir['out']) {
                $flows[$code]['spent'] = Money::add($flows[$code]['spent'], Ledger::amount($journal));
            }
            if ($dir['in']) {
                $flows[$code]['earned'] = Money::add($flows[$code]['earned'], Ledger::amount($journal));
            }
        }
        ksort($flows);
        foreach ($flows as $code => $f) {
            $boxes[] = $this->box('balance', 'Balance (earned − spent)', $code, Money::sub($f['earned'], $f['spent']));
            $boxes[] = $this->box('spent', 'Spent', $code, $f['spent']);
            $boxes[] = $this->box('earned', 'Earned', $code, $f['earned']);
        }

        // subscriptions
        /** @var BillRepositoryInterface $bills */
        $bills = $this->ledger->repo(BillRepositoryInterface::class);
        $start = $scope->start->copy()->startOfDay();
        $end   = $scope->end->copy()->endOfDay();
        foreach ($bills->sumPaidInRange($start, $end) as $entry) {
            $code = (string) $entry['code'];
            if ($this->wanted($scope, $code)) {
                $this->ledger->rememberCurrency($this->currency($entry));
                $boxes[] = $this->box('bills_paid', 'Subscriptions paid', $code, Money::abs(Money::strip((string) $entry['sum'])));
            }
        }
        foreach ($bills->sumUnpaidInRange($start, $end) as $entry) {
            $code = (string) $entry['code'];
            if ($this->wanted($scope, $code)) {
                $this->ledger->rememberCurrency($this->currency($entry));
                $boxes[] = $this->box('bills_unpaid', 'Subscriptions still expected', $code, Money::abs(Money::strip((string) $entry['sum'])));
            }
        }

        // left to spend, in the budget period that contains `start` (as upstream does)
        $range   = Navigation::getViewRange(true);
        $abStart = Navigation::startOfPeriod($scope->start->copy(), $range);
        $abEnd   = Navigation::endOfPeriod($abStart->copy(), $range);

        /** @var AvailableBudgetRepositoryInterface $abRepository */
        $abRepository = $this->ledger->repo(AvailableBudgetRepositoryInterface::class);

        /** @var BudgetRepositoryInterface $budgetRepository */
        $budgetRepository = $this->ledger->repo(BudgetRepositoryInterface::class);

        /** @var OperationsRepositoryInterface $opsRepository */
        $opsRepository = $this->ledger->repo(OperationsRepositoryInterface::class);
        $available     = $abRepository->getAvailableBudgetWithCurrency($abStart, $abEnd);
        $spent         = $opsRepository->sumExpenses($abStart, $abEnd, null, $budgetRepository->getActiveBudgets(), null, false);
        $currencyIds   = array_values(array_unique([...array_keys($available), ...array_keys($spent)]));
        sort($currencyIds);
        foreach ($currencyIds as $currencyId) {
            $currency = TransactionCurrency::find($currencyId);
            if (null === $currency || !$this->wanted($scope, (string) $currency->code)) {
                continue;
            }
            $this->ledger->rememberCurrency($currency);
            $budgetSpent = Money::abs(Money::strip((string) ($spent[$currencyId]['sum'] ?? '0')));
            $avail       = array_key_exists($currencyId, $available) ? Money::strip((string) $available[$currencyId]) : null;
            $box         = $this->box('left_to_spend', 'Left to spend', (string) $currency->code, null === $avail ? null : Money::sub($avail, $budgetSpent));
            $box['available']      = $this->ledger->fmt($avail, (string) $currency->code);
            $box['budgeted_spent'] = $this->ledger->fmt($budgetSpent, (string) $currency->code);
            $box['period_start']   = $abStart->format('Y-m-d');
            $box['period_end']     = $abEnd->format('Y-m-d');
            $boxes[]               = $box;
        }

        // net worth at `end`
        /** @var AccountRepositoryInterface $accounts */
        $accounts = $this->ledger->repo(AccountRepositoryInterface::class);
        $all      = $this->ledger->accountsOfTypes([AccountTypeEnum::ASSET->value, AccountTypeEnum::DEFAULT->value, ...Ledger::LIABILITY_TYPES], true);
        $filtered = $all->filter(static function (Account $account) use ($accounts): bool {
            $flag = $accounts->getMetaValue($account, 'include_net_worth');

            return null === $flag || '1' === $flag;
        })->values();
        foreach ($this->ledger->balances($filtered, $scope->end) as $code => $balance) {
            if ($this->wanted($scope, $code)) {
                $boxes[] = $this->box('net_worth', 'Net worth', $code, $balance);
            }
        }
        foreach ($boxes as $box) {
            $scope->noteCurrency((string) $box['currency_code']);
        }

        return [
            'boxes'      => $boxes,
            'notes'      => [
                'one box per currency — never summed across currencies',
                sprintf('left_to_spend is for the budget period %s..%s (your view range, containing start); null means no available budget was set', $abStart->format('Y-m-d'), $abEnd->format('Y-m-d')),
                'net_worth is at end, over active asset and liability accounts with include_net_worth on',
            ],
            'excluded'   => $scope->excluded(),
            'provenance' => $scope->provenance(['budget_period' => ['start' => $abStart->format('Y-m-d'), 'end' => $abEnd->format('Y-m-d'), 'view_range' => $range]]),
        ];
    }

    /** @return array<string, mixed> */
    private function box(string $key, string $title, string $code, ?string $value): array
    {
        return ['key' => sprintf('%s-in-%s', $key, $code), 'kind' => $key, 'title' => $title, 'currency_code' => $code, 'value' => $this->ledger->fmt($value, $code)];
    }

    private function wanted(Scope $scope, string $code): bool
    {
        return null === $scope->currencyCode || $scope->currencyCode === $code;
    }

    /** @param array<string, mixed> $entry a BillRepository sum entry */
    private function currency(array $entry): TransactionCurrency
    {
        $currency                 = new TransactionCurrency();
        $currency->code           = (string) $entry['code'];
        $currency->decimal_places = (int) $entry['decimal_places'];

        return $currency;
    }
}
