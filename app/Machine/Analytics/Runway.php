<?php

/*
 * Runway.php
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
use FireflyIII\Machine\Money;

/**
 * GET /analytics/runway — liquid asset balance ÷ trailing average monthly outflow, in months
 * (apis.mdx §10.2). The ratio is a decimal string with its numerator and denominator beside it
 * (§10.3: no percentages or ratios as floats).
 *
 *   liquid           the asset accounts' balance today (Firefly NetWorth helper)
 *   window           the `basis` full calendar months before the current month
 *   average_outflow  withdrawals out of those accounts in the window ÷ basis
 *   months           liquid ÷ average_outflow — "if income stopped today"
 *   months_net       liquid ÷ (average outflow − average inflow), only when that is positive
 */
final class Runway
{
    public const array BASES = [3, 6, 12];

    public function __construct(private readonly Ledger $ledger) {}

    /**
     * @return array<string, mixed>
     */
    public function compute(Scope $scope, int $basis, Carbon $today): array
    {
        if (!$scope->accountsExplicit) {
            $scope = $scope->withAccounts($this->ledger->accountsOfTypes(Ledger::ASSET_TYPES, true), false);
        }
        $windowEnd   = $today->copy()->startOfMonth()->subDay();
        $windowStart = $windowEnd->copy()->startOfMonth()->subMonths($basis - 1);
        $window      = $scope->withRange($windowStart, $windowEnd);
        $flows       = [];
        foreach ($this->ledger->journals($window, Ledger::flowTypes($window)) as $journal) {
            $dir  = Ledger::direction($window, $journal);
            $code = (string) $journal['currency_code'];
            $flows[$code] ??= ['out' => '0', 'in' => '0'];
            if ($dir['out']) {
                $flows[$code]['out'] = Money::add($flows[$code]['out'], Ledger::amount($journal));
            }
            if ($dir['in']) {
                $flows[$code]['in'] = Money::add($flows[$code]['in'], Ledger::amount($journal));
            }
        }
        $liquid = $this->ledger->balances($scope->accounts, $today);
        $codes  = array_values(array_unique([...array_keys($liquid), ...array_keys($flows)]));
        sort($codes);
        $rows   = [];
        foreach ($codes as $code) {
            if (null !== $scope->currencyCode && $code !== $scope->currencyCode) {
                continue;
            }
            $window->noteCurrency($code);
            $balance    = $liquid[$code] ?? '0';
            $out        = $flows[$code]['out'] ?? '0';
            $in         = $flows[$code]['in'] ?? '0';
            $avgOut     = (string) Money::div($out, (string) $basis);
            $avgIn      = (string) Money::div($in, (string) $basis);
            $avgNet     = Money::sub($avgOut, $avgIn);
            $months     = Money::div($balance, $avgOut);
            $monthsNet  = Money::compare($avgNet, '0') > 0 ? Money::div($balance, $avgNet) : null;
            $reason     = null;
            if (null === $months) {
                $reason = 'no outflow in the window — runway cannot be computed (it is not infinite, it is unknown)';
            } elseif (Money::compare($balance, '0') <= 0) {
                $reason = 'the liquid balance is zero or negative';
            }
            $rows[]     = [
                'currency_code'       => $code,
                'liquid'              => $this->ledger->fmt($balance, $code),
                'outflow_total'       => $this->ledger->fmt($out, $code),
                'inflow_total'        => $this->ledger->fmt($in, $code),
                'average_outflow'     => $this->ledger->fmt($avgOut, $code),
                'average_inflow'      => $this->ledger->fmt($avgIn, $code),
                'average_net_outflow' => $this->ledger->fmt($avgNet, $code),
                'months'              => null === $months ? null : Money::format($months, 1),
                'months_net'          => null === $monthsNet ? null : Money::format($monthsNet, 1),
                'basis_months'        => $basis,
                'reason'              => $reason,
            ];
        }

        return [
            'rows'       => $rows,
            'basis'      => [
                'months'       => $basis,
                'window_start' => $windowStart->format('Y-m-d'),
                'window_end'   => $windowEnd->format('Y-m-d'),
                'balance_date' => $today->format('Y-m-d'),
            ],
            'notes'      => [
                sprintf('months = liquid ÷ average monthly outflow over %s..%s (%d full months): how long the money lasts if income stops and spending continues at that average', $windowStart->format('Y-m-d'), $windowEnd->format('Y-m-d'), $basis),
                'months_net = liquid ÷ (average outflow − average inflow), only when you spend more than you earn',
                'liquid = asset accounts (not liabilities) today, virtual balance excluded',
            ],
            'excluded'   => $scope->excluded(),
            'provenance' => $window->provenance(['basis_months' => $basis, 'balance_date' => $today->format('Y-m-d'), 'balances' => Ledger::BALANCE_NOTE]),
        ];
    }
}
