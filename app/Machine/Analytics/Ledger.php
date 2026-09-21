<?php

/*
 * Ledger.php
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
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Helpers\Report\NetWorthInterface;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Support\Collection;

/**
 * The analytics plane's one door into Firefly's own read code (apis.mdx §10.3, R1, R7):
 *
 *   journals()   Firefly's GroupCollector — the same collector every insight/summary route uses
 *   balances()   Firefly's NetWorth helper (Steam balances, virtual balance excluded) — the same
 *                figure the net-worth box and chart show
 *   repo()       a Firefly repository bound to the operator and the administration
 *
 * Nothing here re-implements Firefly math; it fetches, and the calculators group and add with
 * bcmath, per currency.
 */
final class Ledger
{
    public const array ASSET_TYPES     = [AccountTypeEnum::ASSET->value];
    public const array LIABILITY_TYPES = [AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value];

    /** @var array<string, int> currency code → decimal places */
    private array $places = [];

    /** @var null|NetWorthInterface */
    private ?NetWorthInterface $netWorth = null;

    public function __construct(
        public readonly User $user,
        public readonly UserGroup $group,
        public readonly TransactionCurrency $primary,
    ) {
        $this->places[(string) $primary->code] = (int) $primary->decimal_places;
    }

    /**
     * A Firefly repository bound to the operator and then narrowed to the bound administration.
     *
     * @template T of object
     *
     * @param class-string<T> $interface
     *
     * @return T
     */
    public function repo(string $interface): object
    {
        $repository = app($interface);
        if (method_exists($repository, 'setUser')) {
            $repository->setUser($this->user);
        }
        if (method_exists($repository, 'setUserGroup')) {
            $repository->setUserGroup($this->group);
        }

        return $repository;
    }

    public function collector(): GroupCollectorInterface
    {
        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUserGroup($this->group);

        return $collector;
    }

    /**
     * The administration's accounts of these types, ordered by id (a total order).
     *
     * @param list<string> $types
     *
     * @return Collection<int, Account>
     */
    public function accountsOfTypes(array $types, bool $activeOnly = false): Collection
    {
        $query = Account::query()
            ->where('accounts.user_group_id', $this->group->id)
            ->accountTypeIn($types)
            ->with(['accountType'])
            ->orderBy('accounts.id')
        ;
        if ($activeOnly) {
            $query->where('accounts.active', true);
        }

        return $query->get(['accounts.*']);
    }

    /**
     * Firefly's journals for this scope and these types (the GroupCollector), filtered to the
     * scope's currency. Opening balances and reconciliations are excluded by type — callers pass
     * only the types they count.
     *
     * @param list<string> $types TransactionTypeEnum values
     *
     * @return list<array<string, mixed>>
     */
    public function journals(Scope $scope, array $types, bool $withTags = false, bool $withBills = false, bool $applyFilters = true): array
    {
        $collector = $this->collector()
            ->setRange($scope->start->copy()->startOfDay(), $scope->end->copy()->endOfDay())
            ->setTypes($types)
            ->withAccountInformation()
            ->withCategoryInformation()
            ->withBudgetInformation()
        ;
        if ($scope->accounts->isNotEmpty()) {
            $collector->setAccounts($scope->accounts);
        }
        if ($applyFilters && $scope->categories->isNotEmpty()) {
            $collector->setCategories($scope->categories);
        }
        if ($applyFilters && $scope->budgets->isNotEmpty()) {
            $collector->setBudgets($scope->budgets);
        }
        if ($applyFilters && $scope->tags->isNotEmpty()) {
            $collector->setTags($scope->tags);
        }
        if ($withTags || ($applyFilters && $scope->tags->isNotEmpty())) {
            $collector->withTagInformation();
        }
        if ($withBills) {
            $collector->withBillInformation();
        }
        $out = [];
        foreach ($collector->getExtractedJournals() as $journal) {
            $code = (string) $journal['currency_code'];
            if (null !== $scope->currencyCode && $code !== $scope->currencyCode) {
                continue;
            }
            $this->places[$code] ??= (int) $journal['currency_decimal_places'];
            $scope->noteCurrency($code);
            $out[] = $journal;
        }
        $scope->countRows(count($out));

        return $out;
    }

    /** @return list<string> the types counted as money OUT (spending) */
    public static function outTypes(Scope $scope): array
    {
        return $scope->includeTransfers
            ? [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::TRANSFER->value]
            : [TransactionTypeEnum::WITHDRAWAL->value];
    }

    /** @return list<string> the types counted as money IN (earning) */
    public static function inTypes(Scope $scope): array
    {
        return $scope->includeTransfers
            ? [TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value]
            : [TransactionTypeEnum::DEPOSIT->value];
    }

    /** @return list<string> withdrawals, deposits and (when asked) transfers */
    public static function flowTypes(Scope $scope): array
    {
        return array_values(array_unique([...self::outTypes($scope), ...self::inTypes($scope)]));
    }

    /**
     * Which way a journal moved money, seen from the scope's accounts: withdrawals are out,
     * deposits are in, a transfer is out when it leaves a scoped account and in when it arrives
     * in one (a transfer between two scoped accounts is both, and nets to zero).
     *
     * @param array<string, mixed> $journal
     *
     * @return array{out: bool, in: bool}
     */
    public static function direction(Scope $scope, array $journal): array
    {
        $type = (string) $journal['transaction_type_type'];
        if (TransactionTypeEnum::WITHDRAWAL->value === $type) {
            return ['out' => true, 'in' => false];
        }
        if (TransactionTypeEnum::DEPOSIT->value === $type) {
            return ['out' => false, 'in' => true];
        }
        if (TransactionTypeEnum::TRANSFER->value === $type) {
            return [
                'out' => $scope->hasAccount((int) $journal['source_account_id']),
                'in'  => $scope->hasAccount((int) $journal['destination_account_id']),
            ];
        }

        return ['out' => false, 'in' => false];
    }

    /** The journal's amount as a positive decimal string (Firefly stores the source side negative). */
    public static function amount(array $journal): string
    {
        return Money::abs(Money::strip((string) $journal['amount']));
    }

    /** The journal's date in the administration's timezone, YYYY-MM-DD. */
    public static function date(array $journal): string
    {
        $date = $journal['date'];

        return $date instanceof Carbon ? $date->format('Y-m-d') : substr((string) $date, 0, 10);
    }

    /**
     * Balances per currency for these accounts at $at (inclusive, end of that day), from
     * Firefly's NetWorth helper: the real balance, without the virtual balance. Returns
     * [] for no accounts.
     *
     * @param Collection<int, Account> $accounts
     *
     * @return array<string, string> currency code → balance (signed)
     */
    public function balances(Collection $accounts, Carbon $at): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        if (null === $this->netWorth) {
            $this->netWorth = app(NetWorthInterface::class);
            $this->netWorth->setUserGroup($this->group);
        }
        $out = [];
        foreach ($this->netWorth->byAccounts($accounts, $at->copy()->endOfDay()) as $row) {
            if (!is_array($row) || !array_key_exists('currency_code', $row)) {
                continue;
            }
            $code                = (string) $row['currency_code'];
            $this->places[$code] ??= (int) $row['currency_decimal_places'];
            $out[$code]          = Money::add($out[$code] ?? '0', Money::strip((string) $row['balance']));
        }
        ksort($out);

        return $out;
    }

    /** Remember a currency's decimal places (from a model that is not a journal). */
    public function rememberCurrency(TransactionCurrency $currency): void
    {
        $this->places[(string) $currency->code] ??= (int) $currency->decimal_places;
    }

    public function placesFor(string $code): int
    {
        if (!array_key_exists($code, $this->places)) {
            $currency            = TransactionCurrency::query()->where('code', $code)->first();
            $this->places[$code] = null === $currency ? 2 : (int) $currency->decimal_places;
        }

        return $this->places[$code];
    }

    /** Render an amount at its currency's decimal places (display fields only). */
    public function fmt(?string $amount, string $code): ?string
    {
        return null === $amount ? null : Money::format($amount, $this->placesFor($code));
    }
}
