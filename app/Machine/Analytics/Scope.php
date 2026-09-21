<?php

/*
 * Scope.php
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
use FireflyIII\Models\Account;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use Illuminate\Support\Collection;

/**
 * The resolved arguments every analytics route shares (apis.mdx §10.2): the inclusive range,
 * the accounts actually counted, the optional filters, the currency, and whether transfers are
 * counted. It also writes the provenance block every answer carries (§10.3) — so a number can
 * always say what it counted.
 */
final class Scope
{
    /** The account types counted when the caller names none (upstream's insight default). */
    public const string DEFAULT_ACCOUNTS = 'asset and liability accounts (upstream insight default)';

    /** @var list<string> the currencies seen while answering, in first-seen order */
    private array $currenciesFound = [];

    private int $rowCount          = 0;

    /**
     * @param Collection<int, Account>  $accounts
     * @param Collection<int, Category> $categories
     * @param Collection<int, Budget>   $budgets
     * @param Collection<int, Tag>      $tags
     */
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly Collection $accounts,
        public readonly bool $accountsExplicit,
        public readonly Collection $categories,
        public readonly Collection $budgets,
        public readonly Collection $tags,
        public readonly ?string $currencyCode,
        public readonly bool $includeTransfers,
        public readonly string $interval = 'month',
        public readonly bool $rangeDefaulted = false,
    ) {}

    public function startDate(): string
    {
        return $this->start->format('Y-m-d');
    }

    public function endDate(): string
    {
        return $this->end->format('Y-m-d');
    }

    /** @var null|array<int, true> the account ids, memoised: hasAccount() runs once per journal */
    private ?array $idSet = null;

    /** @return list<int> */
    public function accountIds(): array
    {
        return array_keys($this->idSet());
    }

    public function hasAccount(int $id): bool
    {
        return isset($this->idSet()[$id]);
    }

    /** @return array<int, true> */
    private function idSet(): array
    {
        if (null === $this->idSet) {
            $this->idSet = [];
            foreach ($this->accounts as $account) {
                $this->idSet[(int) $account->id] = true;
            }
        }

        return $this->idSet;
    }

    /** A copy of this scope with another range (trailing windows, runway basis…). */
    public function withRange(Carbon $start, Carbon $end): self
    {
        return new self($start, $end, $this->accounts, $this->accountsExplicit, $this->categories, $this->budgets, $this->tags, $this->currencyCode, $this->includeTransfers, $this->interval);
    }

    /** A copy of this scope with another account set (cash flow counts asset accounts only). */
    public function withAccounts(Collection $accounts, bool $explicit): self
    {
        return new self($this->start, $this->end, $accounts, $explicit, $this->categories, $this->budgets, $this->tags, $this->currencyCode, $this->includeTransfers, $this->interval, $this->rangeDefaulted);
    }

    public function noteCurrency(string $code): void
    {
        if (!in_array($code, $this->currenciesFound, true)) {
            $this->currenciesFound[] = $code;
        }
    }

    public function countRows(int $n): void
    {
        $this->rowCount += $n;
    }

    /** @return list<string> */
    public function currenciesFound(): array
    {
        $found = $this->currenciesFound;
        sort($found);

        return $found;
    }

    /**
     * What was left out of every spending figure, and why (§10.3). Echoed as `excluded`.
     *
     * @return list<string>
     */
    public function excluded(): array
    {
        $out = [];
        if (!$this->includeTransfers) {
            $out[] = 'transfers (between your own accounts — pass include_transfers=true to count them)';
        }
        $out[] = 'opening balances';
        $out[] = 'reconciliations';

        return $out;
    }

    /**
     * The provenance block (§10.3): the resolved range, the accounts actually included, the
     * filters applied, the currencies found and the row count.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function provenance(array $extra = []): array
    {
        $filters = [];
        if ($this->categories->isNotEmpty()) {
            $filters['category_ids'] = array_values(array_map('intval', $this->categories->pluck('id')->all()));
        }
        if ($this->budgets->isNotEmpty()) {
            $filters['budget_ids'] = array_values(array_map('intval', $this->budgets->pluck('id')->all()));
        }
        if ($this->tags->isNotEmpty()) {
            $filters['tags'] = array_values(array_map('strval', $this->tags->pluck('tag')->all()));
        }
        if (null !== $this->currencyCode) {
            $filters['currency_code'] = $this->currencyCode;
        }

        return array_merge([
            'start'         => $this->startDate(),
            'end'           => $this->endDate(),
            'range'         => $this->rangeDefaulted ? 'defaulted' : 'as requested',
            'accounts'      => $this->accountIds(),
            'account_scope' => $this->accountsExplicit ? 'as requested' : self::DEFAULT_ACCOUNTS,
            'filters'       => (object) $filters,
            'transfers'     => $this->includeTransfers ? 'included' : 'excluded',
            'currencies'    => $this->currenciesFound(),
            'rows'          => $this->rowCount,
        ], $extra);
    }
}
