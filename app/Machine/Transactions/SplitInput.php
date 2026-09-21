<?php

/*
 * SplitInput.php
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

use Closure;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Models\Account;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The plane's front door for a split (one journal of a transaction group) — before upstream's own
 * StoreRequest / UpdateRequest validates it (FireflyForm):
 *
 *   - unknown split fields are REFUSED (§5.7: a typo'd "category" must not silently mean nothing);
 *   - amounts must be decimal STRINGS, positive, with no more places than the currency has (§14.1);
 *   - budget_name is resolved server-side to budget_id — exact, then case-insensitive, ambiguity
 *     refused with the candidates (§14.4); Firefly would otherwise drop an unknown budget silently;
 *   - a withdrawal or deposit between two OWN accounts is refused with the hint to use a transfer
 *     (§8.3 "Transfers are one row, not two") — Firefly would otherwise invent an expense account
 *     named after the savings account;
 *   - dates are YYYY-MM-DD (or a full ISO 8601 instant) and REAL — Firefly reads an unparseable
 *     date as null and stores the row dated today (§7.6: never invent a value);
 *   - a budget on anything but a withdrawal is refused — Firefly drops it silently;
 *   - source_name, destination_name and category_name are resolved the way every other name on
 *     the plane is (§8, §14.4): an exact match is Firefly's own lookup and is left to it; otherwise
 *     a unique case-insensitive match becomes the id, an ambiguous one is refused with the
 *     candidates, and no match is left to Firefly, which creates the payee, payer or category the
 *     way the UI does (the dry run reports what it created). Accounts are matched among the types
 *     Firefly allows on that side of that transaction type, so a withdrawal's "amazon" is the
 *     expense account, never the revenue account of the same name.
 */
final class SplitInput
{
    /** Split fields upstream's StoreRequest accepts (its rules(), transactions.*). */
    public const array STORE_FIELDS = [
        'type', 'date', 'order',
        'currency_id', 'currency_code', 'foreign_currency_id', 'foreign_currency_code',
        'amount', 'foreign_amount', 'description',
        'source_id', 'source_name', 'source_iban', 'source_number', 'source_bic',
        'destination_id', 'destination_name', 'destination_iban', 'destination_number', 'destination_bic',
        'budget_id', 'budget_name', 'category_id', 'category_name', 'bill_id', 'bill_name', 'piggy_bank_id', 'piggy_bank_name',
        'reconciled', 'notes', 'tags',
        'internal_reference', 'external_id', 'external_url',
        'sepa_cc', 'sepa_ct_op', 'sepa_ct_id', 'sepa_db', 'sepa_country', 'sepa_ep', 'sepa_ci', 'sepa_batch_id',
        'interest_date', 'book_date', 'process_date', 'due_date', 'payment_date', 'invoice_date',
        'latitude', 'longitude', 'zoom_level',
    ];

    /** Split fields upstream's UpdateRequest accepts: the same, plus which journal it is. */
    public const array UPDATE_FIELDS = [...self::STORE_FIELDS, 'transaction_journal_id'];

    /**
     * Asset accounts. A withdrawal or deposit between two of these is a transfer. (Liabilities are
     * left out on purpose: paying a loan IS a withdrawal to a liability in Firefly.)
     */
    private const array OWN_TYPES = [AccountTypeEnum::ASSET->value];

    /**
     * @param list<mixed>                                                      $splits
     * @param list<string>                                                     $allowed
     * @param Closure(class-string, string, string): \Illuminate\Database\Eloquent\Model $resolve
     * @param null|array<int, TransactionJournal>                              $existing journal id => journal (update only)
     *
     * @return list<array<string, mixed>> the splits, cleaned, ready for upstream's request
     */
    public static function prepare(array $splits, array $allowed, User $user, UserGroup $group, Closure $resolve, ?array $existing = null): array
    {
        $out = [];
        foreach (array_values($splits) as $i => $split) {
            if (!is_array($split) || array_is_list($split) && [] !== $split) {
                throw MachineException::invalid(sprintf('transactions.%d must be an object (one split).', $i), 'Send transactions: [{"type": "withdrawal", "amount": "12.50", …}]', ['field' => sprintf('transactions.%d', $i)]);
            }
            $unknown = array_values(array_diff(array_map('strval', array_keys($split)), $allowed));
            if ([] !== $unknown) {
                $sorted = $allowed;
                sort($sorted);

                throw MachineException::invalid(
                    sprintf('Unknown field%s in transactions.%d: %s.', 1 === count($unknown) ? '' : 's', $i, implode(', ', $unknown)),
                    self::unknownHint($unknown, $sorted),
                    ['unknown' => $unknown, 'split' => $i, 'accepted' => $sorted],
                );
            }
            $journal = null;
            if (array_key_exists('transaction_journal_id', $split) && null !== $split['transaction_journal_id'] && null !== $existing) {
                $journal = $existing[(int) $split['transaction_journal_id']] ?? null;
                if (null === $journal) {
                    throw MachineException::invalid(
                        sprintf('transactions.%d.transaction_journal_id %s is not a split of this transaction group.', $i, (string) $split['transaction_journal_id']),
                        sprintf('Use one of the group\'s journal ids: %s — or omit it to add a new split', implode(', ', array_keys($existing))),
                        ['field' => sprintf('transactions.%d.transaction_journal_id', $i), 'journal_ids' => array_keys($existing)],
                    );
                }
            }
            if (null === $journal && null !== $existing && 1 === count($existing) && 1 === count($splits)) {
                $journal = reset($existing);
            }
            if (null === $journal && null !== $existing) {
                self::refuseIncompleteNewSplit($split, $i, $existing);
            }
            self::dates($split, $i);
            $split   = self::amounts($split, $i, $user, $journal);
            $split   = self::names($split, $i, $group, $journal);
            $split   = self::budget($split, $i, $resolve, $journal);
            self::refuseOwnToOwn($split, $i, $user, $journal);
            $out[]   = $split;
        }

        return $out;
    }

    /**
     * Firefly's own PUT is a REPLACE: a split of the group that transactions[] does not name is
     * deleted (GroupUpdateService diffs the ids). On this plane a write-tier route never deletes
     * a journal (apis.mdx §7.6 — every destructive delete is admin), so the splits the caller
     * did not name are appended as keep-as-is entries and survive; removing a split is
     * DELETE /transaction-journals/{journal_id}. (One split against a one-split group is that
     * split — Firefly updates it directly — so nothing is appended there.)
     *
     * @param list<array<string, mixed>>     $splits   the prepared splits
     * @param array<int, TransactionJournal> $existing journal id => journal
     *
     * @return array{0: list<array<string, mixed>>, 1: list<int>} the splits, and the ids kept
     */
    public static function keepUnnamed(array $splits, array $existing): array
    {
        if (1 === count($existing) && 1 === count($splits)) {
            return [$splits, []];
        }
        $named = [];
        foreach ($splits as $split) {
            if (isset($split['transaction_journal_id']) && '' !== (string) $split['transaction_journal_id']) {
                $named[(int) $split['transaction_journal_id']] = true;
            }
        }
        $kept  = [];
        foreach (array_keys($existing) as $id) {
            if (!isset($named[(int) $id])) {
                $kept[]   = (int) $id;
                $splits[] = ['transaction_journal_id' => (int) $id];
            }
        }

        return [$splits, $kept];
    }

    /**
     * On an update, a split that names no transaction_journal_id is — to Firefly's
     * GroupUpdateService — a NEW split, and a new split without an amount and both accounts is
     * dropped silently ("createTransactionJournal returned NULL"), so the edit the caller meant
     * never happens and the plan says "unchanged". Refuse it with the fix instead. (The one
     * exception, handled by the caller: one split against a one-split group edits that split.)
     *
     * @param array<string, mixed>           $split
     * @param array<int, TransactionJournal> $existing
     */
    private static function refuseIncompleteNewSplit(array $split, int $i, array $existing): void
    {
        $has     = static fn (string $field): bool => array_key_exists($field, $split) && null !== $split[$field] && '' !== $split[$field];
        $missing = [];
        if (!$has('amount')) {
            $missing[] = 'amount';
        }
        if (!$has('description')) {
            $missing[] = 'description';
        }
        if (!$has('source_id') && !$has('source_name')) {
            $missing[] = 'source_id or source_name';
        }
        if (!$has('destination_id') && !$has('destination_name')) {
            $missing[] = 'destination_id or destination_name';
        }
        if ([] === $missing) {
            return;
        }
        $ids = array_keys($existing);

        throw MachineException::invalid(
            sprintf('transactions.%d names no transaction_journal_id, so it would be a NEW split — but it has no %s.', $i, implode(', ', $missing)),
            sprintf(
                'To edit an existing split, add "transaction_journal_id" (this group\'s splits: %s). To add a split, give it type, date, amount, description, source and destination. Splits not named are kept as they are.',
                implode(', ', $ids),
            ),
            ['field' => sprintf('transactions.%d.transaction_journal_id', $i), 'missing' => $missing, 'journal_ids' => $ids],
        );
    }

    /** @param list<string> $unknown @param list<string> $accepted */
    private static function unknownHint(array $unknown, array $accepted): string
    {
        $near = [];
        foreach ($unknown as $u) {
            foreach (['category' => 'category_id or category_name', 'budget' => 'budget_id or budget_name', 'source' => 'source_id or source_name', 'destination' => 'destination_id or destination_name', 'from' => 'source_id or source_name', 'to' => 'destination_id or destination_name', 'tag' => 'tags (a list)'] as $bad => $good) {
                if ($u === $bad) {
                    $near[] = sprintf('"%s" → %s', $u, $good);
                }
            }
        }
        if ([] !== $near) {
            return 'Did you mean: '.implode('; ', $near);
        }

        return sprintf('A split accepts: %s', implode(', ', $accepted));
    }

    /**
     * @param array<string, mixed> $split
     *
     * @return array<string, mixed>
     */
    private static function amounts(array $split, int $i, User $user, ?TransactionJournal $journal): array
    {
        if (array_key_exists('amount', $split) && null !== $split['amount']) {
            [$places, $code] = self::places($split, 'currency', $user, $journal);
            $split['amount'] = Money::positive($split['amount'], $places, sprintf('transactions.%d.amount', $i), $code);
        }
        if (array_key_exists('foreign_amount', $split) && null !== $split['foreign_amount'] && '' !== $split['foreign_amount']) {
            [$places, $code]         = self::places($split, 'foreign_currency', $user, null);
            $value                   = Money::normalize($split['foreign_amount'], $places, sprintf('transactions.%d.foreign_amount', $i), $code);
            if (str_starts_with($value, '-')) {
                throw MachineException::invalid(sprintf('transactions.%d.foreign_amount must not be negative.', $i), sprintf('Send "foreign_amount": "%s" — direction is the type', Money::abs($value)), ['field' => sprintf('transactions.%d.foreign_amount', $i)]);
            }
            $split['foreign_amount'] = $value;
        }

        return $split;
    }

    /**
     * The decimal places the amount may carry: the named currency's, else the source account's,
     * else (unknown yet — Firefly decides the currency) the storage precision.
     *
     * @param array<string, mixed> $split
     *
     * @return array{0: int, 1: string}
     */
    private static function places(array $split, string $prefix, User $user, ?TransactionJournal $journal): array
    {
        $currency = null;
        if (isset($split[$prefix.'_code']) && is_string($split[$prefix.'_code']) && '' !== $split[$prefix.'_code']) {
            $currency = TransactionCurrency::query()->where('code', strtoupper($split[$prefix.'_code']))->first();
        }
        if (null === $currency && isset($split[$prefix.'_id']) && is_scalar($split[$prefix.'_id'])) {
            $currency = TransactionCurrency::query()->find((int) $split[$prefix.'_id']);
        }
        if (null === $currency && 'currency' === $prefix) {
            // the currency of the own account on either side, named by id or by exact name
            foreach (['source', 'destination'] as $side) {
                $account = self::sideAccount($split, $side, $user);
                if (null !== $account) {
                    /** @var AccountRepositoryInterface $repository */
                    $repository = app(AccountRepositoryInterface::class);
                    $repository->setUser($user);
                    $currency   = $repository->getAccountCurrency($account);
                    if (null !== $currency) {
                        break;
                    }
                }
            }
        }
        if (null === $currency && 'currency' === $prefix && null !== $journal) {
            $currency = $journal->transactionCurrency;
        }
        if (!$currency instanceof TransactionCurrency) {
            return [Money::SCALE, ''];
        }

        return [(int) $currency->decimal_places, (string) $currency->code];
    }

    /**
     * @param array<string, mixed> $split
     *
     * @return array<string, mixed>
     */
    private static function budget(array $split, int $i, Closure $resolve, ?TransactionJournal $journal): array
    {
        $name  = $split['budget_name'] ?? null;
        $name  = is_string($name) && '' !== trim($name) ? $name : null;
        $id    = $split['budget_id'] ?? null;
        $id    = null !== $id && '' !== $id && '0' !== (string) $id && 0 !== $id ? $id : null;
        if (null === $name && null === $id) {
            return $split;
        }
        // Firefly attaches budgets to withdrawals only, and drops the field silently on anything
        // else — a caller that asked for a budget on a deposit must hear that it did not happen
        $type  = self::typeOf($split, $journal);
        if (null !== $type && 'withdrawal' !== $type) {
            throw MachineException::invalid(
                sprintf('transactions.%d is a %s, and budgets apply to withdrawals only.', $i, $type),
                'Remove budget_id / budget_name from this split — Firefly III budgets spending, not income or transfers',
                ['field' => sprintf('transactions.%d.%s', $i, null !== $name ? 'budget_name' : 'budget_id'), 'type' => $type],
            );
        }
        if (null === $name) {
            return $split;
        }
        if (null !== $id) {
            throw MachineException::invalid(sprintf('transactions.%d has both budget_id and budget_name.', $i), 'Pass one of budget_id or budget_name');
        }

        /** @var Budget $budget */
        $budget             = $resolve(Budget::class, TransactionFilter::nameRef($name), 'name');
        unset($split['budget_name']);
        $split['budget_id'] = (int) $budget->id;

        return $split;
    }

    /**
     * @param array<string, mixed> $split
     */
    private static function refuseOwnToOwn(array $split, int $i, User $user, ?TransactionJournal $journal): void
    {
        $type = self::typeOf($split, $journal);
        if (!in_array($type, ['withdrawal', 'deposit'], true)) {
            return;
        }
        $source      = self::ownAccount($split, 'source', $user);
        $destination = self::ownAccount($split, 'destination', $user);
        if (null === $source || null === $destination) {
            return;
        }

        throw MachineException::invalid(
            sprintf('transactions.%d moves money between two of your own accounts (%s → %s), but its type is %s.', $i, $source->name, $destination->name, $type),
            'Use type "transfer" — a transfer is ONE row with an asset source and an asset destination; a withdrawal plus a deposit is two unrelated rows that double-count spending',
            ['field' => sprintf('transactions.%d.type', $i), 'source_id' => (string) $source->id, 'destination_id' => (string) $destination->id],
        );
    }

    /** The split's type as given, else the journal's (update): "withdrawal", "deposit", "transfer"… */
    private static function typeOf(array $split, ?TransactionJournal $journal): ?string
    {
        $type = $split['type'] ?? null;
        if (is_string($type) && '' !== trim($type)) {
            return strtolower(trim($type));
        }
        if (null !== $journal) {
            return strtolower((string) $journal->transactionType?->type);
        }

        return null;
    }

    /** Split fields that carry a date. */
    private const array DATE_FIELDS = ['date', 'interest_date', 'book_date', 'process_date', 'due_date', 'payment_date', 'invoice_date'];

    /**
     * Every date given must be a real date, as YYYY-MM-DD or a full ISO 8601 instant (§14.3).
     * Upstream's rule lets "2026-13-01" through and its converter then reads it as null, and the
     * factory dates the row TODAY — a value nobody typed (§7.6).
     *
     * @param array<string, mixed> $split
     */
    private static function dates(array $split, int $i): void
    {
        foreach (self::DATE_FIELDS as $field) {
            if (!array_key_exists($field, $split) || null === $split[$field] || '' === $split[$field]) {
                continue;
            }
            $value = $split[$field];
            $where = sprintf('transactions.%d.%s', $i, $field);
            $ok    = is_string($value) && 1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value, $m);
            if ($ok) {
                $ok = checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1970
                    && (!isset($m[4]) || ((int) $m[4] < 24 && (int) $m[5] < 60 && (int) ($m[6] ?? 0) < 60));
            }
            if (!$ok) {
                throw MachineException::invalid(
                    sprintf('%s is not a date.', $where),
                    sprintf('Send "%s": "YYYY-MM-DD" (a real calendar date, 1970 or later)', $field),
                    ['field' => $where, 'value' => is_scalar($value) ? (string) $value : null],
                );
            }
        }
    }

    /**
     * Resolve source_name, destination_name and category_name the way the plane resolves every
     * name (§8, §14.4). An exact match is left as it is — Firefly's own lookup finds exactly that
     * row; a unique case-insensitive match becomes the id; more than one is refused with the
     * candidates; none is left to Firefly, which creates the payee or category the way the UI does.
     *
     * @param array<string, mixed> $split
     *
     * @return array<string, mixed>
     */
    private static function names(array $split, int $i, UserGroup $group, ?TransactionJournal $journal): array
    {
        $type = self::typeOf($split, $journal);
        foreach (['source', 'destination'] as $side) {
            $name = $split[$side.'_name'] ?? null;
            if (!is_string($name) || '' === trim($name) || self::given($split[$side.'_id'] ?? null)) {
                continue;
            }
            $found = self::accountByName($side, $type, trim($name), $group, sprintf('transactions.%d.%s_name', $i, $side));
            if ($found instanceof Account) {
                $split[$side.'_id'] = (int) $found->id;
                unset($split[$side.'_name']);
            }
        }

        $name = $split['category_name'] ?? null;
        if (is_string($name) && '' !== trim($name) && !self::given($split['category_id'] ?? null)) {
            $found = self::match(Category::query()->where('user_group_id', $group->id), 'name', trim($name), ['*']);
            if ($found instanceof Category) {
                $split['category_id'] = (int) $found->id;
                unset($split['category_name']);
            } elseif (is_array($found)) {
                throw MachineException::invalid(
                    sprintf('transactions.%d.category_name "%s" matches %d categories.', $i, trim($name), count($found)),
                    'Pass category_id instead — the candidates are in details.candidates',
                    ['field' => sprintf('transactions.%d.category_name', $i), 'candidates' => array_map(static fn (Category $c): array => ['id' => (string) $c->id, 'name' => $c->name], $found)],
                );
            }
        }

        return $split;
    }

    /**
     * The account a `{side}_name` means, among the types Firefly accepts on that side of that
     * transaction type: null when an exact match exists (Firefly's own lookup finds exactly that
     * row) or nothing matches (Firefly creates it, or refuses); the Account when exactly one
     * case-insensitive match exists; invalid_input with the candidates when several do.
     *
     * @param string      $side  "source" | "destination"
     * @param null|string $type  "withdrawal", "deposit", "transfer"… (null: any type Firefly allows)
     * @param string      $field the argument's name, for the error
     */
    public static function accountByName(string $side, ?string $type, string $name, UserGroup $group, string $field): ?Account
    {
        $base  = Account::query()->where('accounts.user_group_id', $group->id)
            ->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->whereIn('account_types.type', self::sideTypes($side, $type))
        ;
        $found = self::match($base, 'accounts.name', $name, ['accounts.*', 'account_types.type as machine_type']);
        if (null === $found || $found instanceof Account) {
            return $found;
        }

        throw MachineException::invalid(
            sprintf('%s "%s" matches %d accounts.', $field, $name, count($found)),
            sprintf('Pass %s_id instead — the candidates are in details.candidates', $side),
            ['field' => $field, 'candidates' => array_map(static fn (Account $a): array => ['id' => (string) $a->id, 'name' => $a->name, 'type' => $a->getAttribute('machine_type')], $found)],
        );
    }

    /**
     * null: an exact match exists (leave it to Firefly) or nothing matches at all; a model: the
     * one case-insensitive match; a list: the ambiguous case-insensitive matches.
     *
     * @param EloquentBuilder<Model>|Relation<Model, Model, mixed> $base
     * @param list<string>                                         $columns
     *
     * @return null|list<Model>|Model
     */
    private static function match(EloquentBuilder|Relation $base, string $column, string $name, array $columns): null|array|Model
    {
        if ((clone $base)->where($column, $name)->exists()) {
            return null;
        }
        $rows = (clone $base)->whereRaw(sprintf('LOWER(%s) = ?', $column), [mb_strtolower($name)])->orderBy($column)->limit(11)->get($columns);
        if (0 === $rows->count()) {
            return null;
        }
        if (1 === $rows->count()) {
            return $rows->first();
        }

        return $rows->take(10)->values()->all();
    }

    /** The account types Firefly accepts on this side of this transaction type (its own table). */
    private static function sideTypes(string $side, ?string $type): array
    {
        $key   = null === $type ? 'none' : ucfirst(str_replace('-', ' ', $type));
        $types = config(sprintf('firefly.expected_source_types.%s.%s', $side, $key));
        if (!is_array($types) || [] === $types) {
            $types = (array) config(sprintf('firefly.expected_source_types.%s.none', $side));
        }

        return array_values(array_unique($types));
    }

    private static function given(mixed $id): bool
    {
        return null !== $id && '' !== $id && is_scalar($id) && '' !== trim((string) $id);
    }

    /** @param array<string, mixed> $split */
    private static function sideAccount(array $split, string $side, User $user): ?Account
    {
        if (isset($split[$side.'_id']) && is_scalar($split[$side.'_id']) && 1 === preg_match('/^\d+$/', (string) $split[$side.'_id'])) {
            /** @var null|Account */
            return $user->accounts()->find((int) $split[$side.'_id']);
        }
        if (isset($split[$side.'_name']) && is_string($split[$side.'_name']) && '' !== trim($split[$side.'_name'])) {
            $rows = self::ownQuery($user)->where('accounts.name', trim($split[$side.'_name']))->limit(2)->get(['accounts.*']);

            /** @var null|Account */
            return 1 === $rows->count() ? $rows->first() : null;
        }

        return null;
    }

    private static function ownQuery(User $user): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $user->accounts()->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')->whereIn('account_types.type', self::OWN_TYPES);
    }

    /** @param array<string, mixed> $split */
    private static function ownAccount(array $split, string $side, User $user): ?Account
    {
        $query = $user->accounts()->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')->whereIn('account_types.type', self::OWN_TYPES);
        if (isset($split[$side.'_id']) && is_scalar($split[$side.'_id']) && '' !== (string) $split[$side.'_id']) {
            /** @var null|Account */
            return $query->where('accounts.id', (int) $split[$side.'_id'])->first(['accounts.*']);
        }
        if (isset($split[$side.'_name']) && is_string($split[$side.'_name']) && '' !== trim($split[$side.'_name'])) {
            $rows = $query->where('accounts.name', trim($split[$side.'_name']))->limit(2)->get(['accounts.*']);

            /** @var null|Account */
            return 1 === $rows->count() ? $rows->first() : null;
        }

        return null;
    }
}
