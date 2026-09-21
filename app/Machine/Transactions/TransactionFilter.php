<?php

/*
 * TransactionFilter.php
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

namespace FireflyIII\Machine\Transactions;

use Carbon\Carbon;
use Closure;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Models\Bill;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\Tag;
use FireflyIII\Models\TransactionCurrency;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;

/**
 * THE transaction filter language — apis.mdx §8.3. One language for "show me these"
 * (GET /transactions, GET /transactions/export) and "change these" (the `filter{}` of bulk,
 * categorize and set-budget), so the two can never select different sets.
 *
 * It is translated onto Firefly's own GroupCollector (the engine upstream's API, its reports and
 * its rule engine use) — the plane adds no query of its own.
 */
final class TransactionFilter
{
    public const array TYPES = [
        'withdrawal'      => [TransactionTypeEnum::WITHDRAWAL->value],
        'deposit'         => [TransactionTypeEnum::DEPOSIT->value],
        'transfer'        => [TransactionTypeEnum::TRANSFER->value],
        'opening-balance' => [TransactionTypeEnum::OPENING_BALANCE->value],
        'opening_balance' => [TransactionTypeEnum::OPENING_BALANCE->value],
        'reconciliation'  => [TransactionTypeEnum::RECONCILIATION->value],
        'all'             => [
            TransactionTypeEnum::WITHDRAWAL->value,
            TransactionTypeEnum::DEPOSIT->value,
            TransactionTypeEnum::TRANSFER->value,
            TransactionTypeEnum::OPENING_BALANCE->value,
            TransactionTypeEnum::RECONCILIATION->value,
        ],
    ];

    /** Upstream's default: what the transaction list in the UI shows. */
    public const array DEFAULT_TYPES = [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value];

    private const string ID = 'regex:/^\d{1,19}$/';

    /** @return array<string, mixed> Laravel rules for the filter fields */
    public static function rules(): array
    {
        return [
            'start'            => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'              => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'type'             => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys(self::TYPES))],
            'account_id'       => ['sometimes', 'nullable', self::ID],
            'account_ids'      => ['sometimes', 'array', 'max:200'],
            'account_ids.*'    => [self::ID],
            'account_name'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_names'    => ['sometimes', 'array', 'max:200'],
            'account_names.*'  => ['string', 'max:255'],
            'category_id'      => ['sometimes', 'nullable', self::ID],
            'category_name'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'without_category' => ['sometimes', 'boolean'],
            'budget_id'        => ['sometimes', 'nullable', self::ID],
            'budget_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'without_budget'   => ['sometimes', 'boolean'],
            'tag'              => ['sometimes', 'nullable', 'string', 'max:1024'],
            'without_tag'      => ['sometimes', 'boolean'],
            'bill_id'          => ['sometimes', 'nullable', self::ID],
            'bill_name'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'min_amount'       => ['sometimes', 'nullable', 'string', 'max:64'],
            'max_amount'       => ['sometimes', 'nullable', 'string', 'max:64'],
            'currency_code'    => ['sometimes', 'nullable', 'string', 'min:3', 'max:51'],
            'reconciled'       => ['sometimes', 'boolean'],
            'search'           => ['sometimes', 'nullable', 'string', 'max:1024'],
        ];
    }

    /** @return list<string> the filter's top-level field names */
    public static function fields(): array
    {
        $out = [];
        foreach (array_keys(self::rules()) as $key) {
            $out[explode('.', $key, 2)[0]] = true;
        }

        return array_keys($out);
    }

    /**
     * Validate a `filter{}` object from a write body the way input() validates a query: unknown
     * fields refused (a typo must never widen a bulk write), then the rules.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function validateObject(array $raw, string $prefix = 'filter'): array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($raw)), self::fields()));
        if ([] !== $unknown) {
            $accepted = self::fields();
            sort($accepted);

            throw MachineException::invalid(
                sprintf('Unknown %s field%s: %s.', $prefix, 1 === count($unknown) ? '' : 's', implode(', ', $unknown)),
                sprintf('%s accepts the same fields as GET /machine/v1/transactions: %s', $prefix, implode(', ', $accepted)),
                ['unknown' => $unknown, 'accepted' => $accepted],
            );
        }
        foreach (self::FLAGS as $flag) {
            if (isset($raw[$flag]) && is_string($raw[$flag])) {
                $v = strtolower(trim($raw[$flag]));
                $raw[$flag] = in_array($v, ['true', '1', 'yes', 'on'], true) ? true : (in_array($v, ['false', '0', 'no', 'off'], true) ? false : $raw[$flag]);
            }
        }
        $validator = Validator::make($raw, self::rules());
        if ($validator->fails()) {
            $fields = [];
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $fields[$prefix.'.'.$field] = $messages;
            }

            throw MachineException::invalid(sprintf('Invalid %s.', $prefix), (string) $validator->errors()->first(), ['fields' => $fields]);
        }

        return $validator->validated();
    }

    /**
     * True when the filter selects something narrower than "everything".
     *
     * @param array<string, mixed> $f
     */
    public static function isEmpty(array $f): bool
    {
        foreach ($f as $field => $value) {
            if (in_array($field, self::FLAGS, true)) {
                if (self::flag($f, $field)) {
                    return false;
                }

                continue;
            }
            if (null !== $value && false !== $value && [] !== $value && '' !== $value) {
                return false;
            }
        }

        return true;
    }

    /** The boolean filter fields. */
    public const array FLAGS = ['without_category', 'without_budget', 'without_tag', 'reconciled'];

    /**
     * A flag as given — true/false, 1/0, "1"/"0", "true"/"false" (Laravel's boolean rule accepts
     * all of these, and a "1" the plan reads as "not true" is a filter silently dropped, which on a
     * bulk write is a selection silently WIDENED to everything).
     *
     * @param array<string, mixed> $f
     */
    public static function flag(array $f, string $field): bool
    {
        if (!array_key_exists($field, $f) || null === $f[$field]) {
            return false;
        }

        return true === filter_var($f[$field], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Put the filter on a collector. Names are resolved server-side through $resolve (the
     * controller's resolve(): exact, then case-insensitive, ambiguity refused with candidates).
     *
     * @param array<string, mixed>                                                 $f
     * @param Closure(class-string, string, string): \Illuminate\Database\Eloquent\Model $resolve
     *
     * @return array<string, mixed> the filter as resolved — echoed so the caller sees what was applied
     */
    public static function apply(GroupCollectorInterface $collector, array $f, Closure $resolve): array
    {
        $echo  = [];
        $start = self::date($f['start'] ?? null, 'start');
        $end   = self::date($f['end'] ?? null, 'end');
        if (null !== $start && null !== $end && $start->gt($end)) {
            throw MachineException::invalid('start is after end.', sprintf('Swap them: start=%s end=%s (both inclusive)', $end->format('Y-m-d'), $start->format('Y-m-d')), ['start' => $f['start'], 'end' => $f['end']]);
        }
        if (null !== $start && null !== $end) {
            $collector->setRange($start->copy()->startOfDay(), $end->copy()->endOfDay());
        }
        if (null !== $start && null === $end) {
            $collector->setStart($start->copy()->startOfDay());
        }
        if (null === $start && null !== $end) {
            $collector->setEnd($end->copy()->endOfDay());
        }
        $echo['start'] = $start?->format('Y-m-d');
        $echo['end']   = $end?->format('Y-m-d');

        // type — and "without a budget" means withdrawals unless a type says otherwise:
        // budgets only ever apply to withdrawals in Firefly.
        $type  = $f['type'] ?? null;
        if (null === $type && self::flag($f, 'without_budget')) {
            $type = 'withdrawal';
        }
        $types = null === $type ? self::DEFAULT_TYPES : self::TYPES[$type];
        $collector->setTypes($types);
        $echo['type']  = $type ?? 'default';
        $echo['types'] = $types;

        // accounts: id, ids[], name, names[] — all resolved, all combined (either side)
        $accounts = new Collection();
        $refs     = [];
        foreach (array_filter([$f['account_id'] ?? null, ...(array) ($f['account_ids'] ?? [])], static fn ($v): bool => null !== $v && '' !== $v) as $id) {
            $refs[] = (string) $id;
        }
        foreach (array_filter([$f['account_name'] ?? null, ...(array) ($f['account_names'] ?? [])], static fn ($v): bool => null !== $v && '' !== $v) as $name) {
            $refs[] = self::nameRef((string) $name);
        }
        foreach ($refs as $ref) {
            /** @var Account $account */
            $account  = $resolve(Account::class, $ref, 'name');
            $accounts->push($account);
        }
        if ($accounts->isNotEmpty()) {
            $collector->setAccounts($accounts->unique('id')->values());
            $echo['accounts'] = $accounts->unique('id')->map(static fn (Account $a): array => ['id' => (string) $a->id, 'name' => $a->name])->values()->all();
        }

        // category
        $category = self::one($f, 'category', Category::class, 'name', $resolve);
        if (null !== $category && self::flag($f, 'without_category')) {
            throw MachineException::invalid('category and without_category contradict each other.', 'Pass either a category or without_category=true');
        }
        if (null !== $category) {
            /** @var Category $category */
            $collector->setCategory($category);
            $echo['category'] = ['id' => (string) $category->id, 'name' => $category->name];
        }
        if (self::flag($f, 'without_category')) {
            $collector->withoutCategory();
            $echo['without_category'] = true;
        }

        // budget
        $budget   = self::one($f, 'budget', Budget::class, 'name', $resolve);
        if (null !== $budget && self::flag($f, 'without_budget')) {
            throw MachineException::invalid('budget and without_budget contradict each other.', 'Pass either a budget or without_budget=true');
        }
        if (null !== $budget) {
            /** @var Budget $budget */
            $collector->setBudget($budget);
            $echo['budget'] = ['id' => (string) $budget->id, 'name' => $budget->name];
        }
        if (self::flag($f, 'without_budget')) {
            $collector->withoutBudget();
            $echo['without_budget'] = true;
        }

        // tag
        $tagRef   = $f['tag'] ?? null;
        if (null !== $tagRef && '' !== $tagRef && self::flag($f, 'without_tag')) {
            throw MachineException::invalid('tag and without_tag contradict each other.', 'Pass either a tag or without_tag=true');
        }
        if (null !== $tagRef && '' !== $tagRef) {
            /** @var Tag $tag */
            $tag      = $resolve(Tag::class, self::nameRef((string) $tagRef), 'tag');
            $collector->setTag($tag);
            $echo['tag'] = ['id' => (string) $tag->id, 'tag' => $tag->tag];
        }
        if (self::flag($f, 'without_tag')) {
            $collector->withoutTags();
            $echo['without_tag'] = true;
        }

        // subscription (bill)
        $bill     = self::one($f, 'bill', Bill::class, 'name', $resolve);
        if (null !== $bill) {
            /** @var Bill $bill */
            $collector->setBill($bill);
            $echo['bill'] = ['id' => (string) $bill->id, 'name' => $bill->name];
        }

        // amounts: positive decimal strings, inclusive bounds (§14.1)
        $min      = self::amount($f['min_amount'] ?? null, 'min_amount');
        $max      = self::amount($f['max_amount'] ?? null, 'max_amount');
        if (null !== $min && null !== $max && 1 === Money::compare($min, $max)) {
            throw MachineException::invalid('min_amount is larger than max_amount.', sprintf('Swap them: min_amount=%s max_amount=%s', $max, $min));
        }
        if (null !== $min) {
            $collector->amountMore($min);
            $echo['min_amount'] = $min;
        }
        if (null !== $max) {
            $collector->amountLess($max);
            $echo['max_amount'] = $max;
        }

        // currency
        $code     = $f['currency_code'] ?? null;
        if (null !== $code && '' !== $code) {
            /** @var null|TransactionCurrency $currency */
            $currency = TransactionCurrency::query()->where('code', strtoupper((string) $code))->first();
            if (null === $currency) {
                throw MachineException::notFound(sprintf('No currency with code "%s".', $code), 'GET /machine/v1/currencies lists them', ['currency_code' => $code]);
            }
            $collector->setCurrency($currency);
            $echo['currency_code'] = $currency->code;
        }

        // reconciled
        if (array_key_exists('reconciled', $f) && null !== $f['reconciled']) {
            self::flag($f, 'reconciled') ? $collector->isReconciled() : $collector->isNotReconciled();
            $echo['reconciled'] = self::flag($f, 'reconciled');
        }

        // free text in the description (every word must appear)
        $search   = trim((string) ($f['search'] ?? ''));
        if ('' !== $search) {
            $words = array_values(array_filter(preg_split('/\s+/', $search) ?: [], static fn (string $w): bool => '' !== $w));
            $collector->setSearchWords($words);
            $echo['search'] = $search;
        }

        return $echo;
    }

    /**
     * `{base}_id` or `{base}_name` → the model, or null when neither was given. Both is refused.
     *
     * @param array<string, mixed> $f
     */
    private static function one(array $f, string $base, string $class, string $column, Closure $resolve): ?object
    {
        $id   = $f[$base.'_id'] ?? null;
        $name = $f[$base.'_name'] ?? null;
        $id   = null === $id || '' === $id ? null : (string) $id;
        $name = null === $name || '' === $name ? null : (string) $name;
        if (null !== $id && null !== $name) {
            throw MachineException::invalid(sprintf('Both %1$s_id and %1$s_name were given.', $base), sprintf('Pass one of %1$s_id or %1$s_name', $base));
        }
        if (null === $id && null === $name) {
            return null;
        }

        return $resolve($class, $id ?? self::nameRef((string) $name), $column);
    }

    /**
     * A NAME that happens to be all digits ("7734") must not be read as an id by resolve():
     * such names are passed with a marker the controller's resolver understands.
     */
    public static function nameRef(string $name): string
    {
        return 1 === preg_match('/^\d+$/', trim($name)) ? "\0name:".$name : $name;
    }

    private static function date(mixed $value, string $field): ?Carbon
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', (string) $value, config('app.timezone'))->startOfDay();
        } catch (\Throwable) {
            throw MachineException::invalid(sprintf('%s is not a date.', $field), sprintf('Pass %s as YYYY-MM-DD', $field), ['field' => $field]);
        }
    }

    private static function amount(mixed $value, string $field): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $amount = Money::normalize($value, Money::SCALE, $field);
        if (str_starts_with($amount, '-')) {
            throw MachineException::invalid(sprintf('%s must not be negative — transaction amounts are positive; direction is the type.', $field), sprintf('Pass %s=%s and a type (withdrawal, deposit, transfer)', $field, Money::abs($amount)), ['field' => $field]);
        }

        return Money::strip($amount);
    }
}
