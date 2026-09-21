<?php

/*
 * Commitments.php
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
use FireflyIII\Models\Bill;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * Subscriptions (Firefly "bills") and piggy banks — apis.mdx §10.2 /analytics/subscriptions and
 * /analytics/piggy-progress, and the two per-object charts (§10.5). Expected dates, paid
 * journals and saved amounts are Firefly's own (BillRepository, PiggyBankRepository).
 */
final class Commitments
{
    /** How many times a year each Firefly repeat frequency happens (before `skip`). */
    public const array PER_YEAR = ['daily' => '365', 'weekly' => '52', 'monthly' => '12', 'quarterly' => '4', 'half-year' => '2', 'yearly' => '1'];

    public function __construct(private readonly Ledger $ledger) {}

    /** @return Collection<int, Bill> */
    public function bills(): Collection
    {
        /** @var BillRepositoryInterface $repository */
        $repository = $this->ledger->repo(BillRepositoryInterface::class);

        return $repository->getBills()->sortBy('id')->values();
    }

    /**
     * Per subscription: expected, paid, missed, annualised cost.
     *
     * @return array<string, mixed>
     */
    public function subscriptions(Scope $scope, ?Carbon $today = null): array
    {
        /** @var BillRepositoryInterface $repository */
        $repository = $this->ledger->repo(BillRepositoryInterface::class);
        $today ??= today(config('app.timezone'));
        $rows       = [];
        $totals     = [];
        foreach ($this->bills() as $bill) {
            $currency = $bill->transactionCurrency;
            $code     = (string) $currency->code;
            if (null !== $scope->currencyCode && $code !== $scope->currencyCode) {
                continue;
            }
            $this->ledger->rememberCurrency($currency);
            $scope->noteCurrency($code);
            $start       = $scope->start->copy()->startOfDay();
            $end         = $scope->end->copy()->endOfDay();
            $expected    = $repository->getPayDatesInRange($bill, $start, $end);
            $dueSoFar    = $expected->filter(static fn (Carbon $d): bool => $d->lte($today))->count();
            $paid        = $repository->getPaidDatesInRange($bill, $start, $end);
            $scope->countRows($paid->count());
            $paidAmount  = '0';
            $paidOther   = [];
            $paidDates   = [];
            foreach ($paid as $row) {
                $rowCode     = (string) $row->transaction_currency_code;
                $amount      = Money::abs(Money::strip((string) $row->amount));
                $paidDates[] = Carbon::parse((string) $row->date)->format('Y-m-d');
                if ($rowCode === $code) {
                    $paidAmount = Money::add($paidAmount, $amount);

                    continue;
                }
                $paidOther[$rowCode] = Money::add($paidOther[$rowCode] ?? '0', $amount);
            }
            sort($paidDates);
            $average     = self::average($bill);
            $annualised  = self::annualised($bill);
            $rows[]      = [
                'id'                  => (int) $bill->id,
                'name'                => (string) $bill->name,
                'active'              => (bool) $bill->active,
                'currency_code'       => $code,
                'amount_min'          => $this->ledger->fmt(Money::strip((string) $bill->amount_min), $code),
                'amount_max'          => $this->ledger->fmt(Money::strip((string) $bill->amount_max), $code),
                'repeat_freq'         => (string) $bill->repeat_freq,
                'skip'                => (int) $bill->skip,
                'expected_count'      => $expected->count(),
                'expected_dates'      => $expected->map(static fn (Carbon $d): string => $d->format('Y-m-d'))->values()->all(),
                'expected_amount'     => $this->ledger->fmt(Money::mul($average, (string) $expected->count()), $code),
                'due_so_far'          => $dueSoFar,
                'paid_count'          => $paid->count(),
                'paid_dates'          => $paidDates,
                'paid_amount'         => $this->ledger->fmt($paidAmount, $code),
                'paid_other_currencies' => (object) $paidOther,
                'missed_count'        => max(0, $dueSoFar - $paid->count()),
                'annualised'          => null === $annualised ? null : $this->ledger->fmt($annualised, $code),
                'next_expected'       => $bill->active ? $repository->nextExpectedMatch($bill, $today)->format('Y-m-d') : null,
            ];
            if ($bill->active && null !== $annualised) {
                $totals[$code] = Money::add($totals[$code] ?? '0', $annualised);
            }
        }
        ksort($totals);
        $totalRows = [];
        foreach ($totals as $code => $sum) {
            $totalRows[] = ['currency_code' => $code, 'annualised' => $this->ledger->fmt($sum, $code)];
        }

        return [
            'subscriptions' => $rows,
            'totals'        => $totalRows,
            'notes'         => [
                'expected dates and paid journals are Firefly\'s (BillRepository); missed = expected dates up to today − payments in the range, never below zero',
                'annualised = (amount_min + amount_max) ÷ 2 × occurrences per year ÷ (skip + 1); totals count active subscriptions only',
            ],
            'excluded'      => [],
            'provenance'    => $scope->provenance(['today' => $today->format('Y-m-d')]),
        ];
    }

    /**
     * Per piggy bank: saved, target, left, and whether it is on track for its date.
     *
     * @return array<string, mixed>
     */
    public function piggyProgress(Scope $scope, ?Carbon $today = null): array
    {
        /** @var PiggyBankRepositoryInterface $repository */
        $repository = $this->ledger->repo(PiggyBankRepositoryInterface::class);
        $today ??= today(config('app.timezone'));
        $rows       = [];
        foreach ($repository->getPiggyBanks()->sortBy('id')->values() as $piggy) {
            /** @var PiggyBank $piggy */
            $currency = $piggy->transactionCurrency;
            $code     = (string) ($currency?->code ?? $this->ledger->primary->code);
            if (null !== $scope->currencyCode && $code !== $scope->currencyCode) {
                continue;
            }
            if (null !== $currency) {
                $this->ledger->rememberCurrency($currency);
            }
            $scope->noteCurrency($code);
            $scope->countRows(1);
            $saved    = Money::strip($repository->getCurrentAmount($piggy));
            $target   = self::target($piggy);
            $left     = null === $target ? null : Money::sub($target, $saved);
            [$onTrack, $expectedNow, $reason] = self::onTrack($piggy, $saved, $target, $today);
            $monthly  = null;
            if (null !== $left && null !== $piggy->target_date && Money::compare($left, '0') > 0 && $piggy->target_date->gt($today)) {
                $months  = max(1, (int) ceil($today->diffInMonths($piggy->target_date, true)));
                $monthly = Money::div($left, (string) $months);
            }
            $rows[]   = [
                'id'              => (int) $piggy->id,
                'name'            => (string) $piggy->name,
                'currency_code'   => $code,
                'saved'           => $this->ledger->fmt($saved, $code),
                'target'          => $this->ledger->fmt($target, $code),
                'left'            => $this->ledger->fmt($left, $code),
                'start_date'      => $piggy->start_date?->format('Y-m-d'),
                'target_date'     => $piggy->target_date?->format('Y-m-d'),
                'expected_by_now' => $this->ledger->fmt($expectedNow, $code),
                'on_track'        => $onTrack,
                'on_track_reason' => $reason,
                'monthly_needed'  => $this->ledger->fmt($monthly, $code),
                'accounts'        => $piggy->accounts->map(static fn ($a): array => ['account_id' => (int) $a->id, 'name' => (string) $a->name])->values()->all(),
            ];
        }

        return [
            'piggy_banks' => $rows,
            'notes'       => [
                'saved is Firefly\'s current amount; target null means the piggy bank has no target (not a target of zero)',
                'on track = saved ≥ target × (days elapsed ÷ days from start to target date); null when there is no target or no target date',
            ],
            'excluded'    => [],
            'provenance'  => $scope->provenance(['today' => $today->format('Y-m-d')]),
        ];
    }

    /**
     * One piggy bank's saved amount over time, from its events (cumulative).
     *
     * @return array{piggy: PiggyBank, points: list<array{date: string, saved: string}>, target: null|string, code: string}
     */
    public function piggyHistory(PiggyBank $piggy): array
    {
        /** @var PiggyBankRepositoryInterface $repository */
        $repository = $this->ledger->repo(PiggyBankRepositoryInterface::class);
        $events     = $repository->getEvents($piggy)->sortBy(static fn (PiggyBankEvent $e): string => sprintf('%s-%010d', $e->date->format('Y-m-d'), $e->id))->values();
        $running    = '0';
        $byDate     = [];
        foreach ($events as $event) {
            $running                                 = Money::add($running, Money::strip((string) $event->amount));
            $byDate[$event->date->format('Y-m-d')] = $running;
        }
        $points     = [];
        foreach ($byDate as $date => $saved) {
            $points[] = ['date' => (string) $date, 'saved' => $saved];
        }
        $code       = (string) ($piggy->transactionCurrency?->code ?? $this->ledger->primary->code);

        return ['piggy' => $piggy, 'points' => $points, 'target' => self::target($piggy), 'code' => $code];
    }

    /** The midpoint of a bill's amount range. */
    public static function average(Bill $bill): string
    {
        return (string) Money::div(Money::add(Money::strip((string) $bill->amount_min), Money::strip((string) $bill->amount_max)), '2');
    }

    /** Average × occurrences per year ÷ (skip + 1); null for an unknown frequency. */
    public static function annualised(Bill $bill): ?string
    {
        $perYear = self::PER_YEAR[(string) $bill->repeat_freq] ?? null;
        if (null === $perYear) {
            return null;
        }

        return Money::div(Money::mul(self::average($bill), $perYear), (string) (max(0, (int) $bill->skip) + 1));
    }

    private static function target(PiggyBank $piggy): ?string
    {
        $raw = $piggy->target_amount;
        if (null === $raw || '' === (string) $raw) {
            return null;
        }
        $value = Money::strip((string) $raw);

        return Money::isZero($value) ? null : $value;
    }

    /**
     * @return array{0: null|bool, 1: null|string, 2: string}
     */
    private static function onTrack(PiggyBank $piggy, string $saved, ?string $target, Carbon $today): array
    {
        if (null === $target) {
            return [null, null, 'no target amount'];
        }
        if (Money::compare($saved, $target) >= 0) {
            return [true, $target, 'target reached'];
        }
        if (null === $piggy->target_date) {
            return [null, null, 'no target date'];
        }
        $start = $piggy->start_date ?? $piggy->created_at;
        if (null === $start || $piggy->target_date->lte($start)) {
            return [null, null, 'no usable start date'];
        }
        if ($today->gte($piggy->target_date)) {
            return [false, $target, 'target date passed without reaching the target'];
        }
        $total    = (int) $start->copy()->startOfDay()->diffInDays($piggy->target_date->copy()->startOfDay(), true);
        $elapsed  = max(0, (int) $start->copy()->startOfDay()->diffInDays($today->copy()->startOfDay(), false));
        $expected = (string) Money::div(Money::mul($target, (string) min($elapsed, $total)), (string) $total);

        return [Money::compare($saved, $expected) >= 0, $expected, sprintf('%d of %d days elapsed', min($elapsed, $total), $total)];
    }
}
