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
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\User;

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
 *     named after the savings account.
 *
 * category_name, source_name and destination_name are left to Firefly (R7): it finds them, and
 * creates a payee or category the way the UI does. The dry run reports what it created.
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
            $split   = self::amounts($split, $i, $user, $journal);
            $split   = self::budget($split, $i, $resolve);
            self::refuseOwnToOwn($split, $i, $user, $journal);
            $out[]   = $split;
        }

        return $out;
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
    private static function budget(array $split, int $i, Closure $resolve): array
    {
        $name = $split['budget_name'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            return $split;
        }
        if (isset($split['budget_id']) && null !== $split['budget_id'] && '' !== $split['budget_id']) {
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
        $type = $split['type'] ?? (null === $journal ? null : strtolower((string) $journal->transactionType?->type));
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
