<?php

/*
 * Converter.php
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

use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Machine\MachineException;
use FireflyIII\Models\Account;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionType;
use FireflyIII\Models\UserGroup;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\User;
use FireflyIII\Validation\AccountValidator;

/**
 * The UI's "convert" (withdrawal ↔ deposit ↔ transfer) — the same steps as upstream's
 * Http\Controllers\Transaction\ConvertController::postIndex(): Firefly's AccountValidator judges
 * the new source and destination for the new type, JournalUpdateService applies it, and the
 * UpdatedSingleTransactionGroup event runs Firefly's listeners (running balances, webhooks).
 *
 * The one addition is defaults the UI shows pre-filled on its convert form: the side of the
 * transaction that stays an own account is kept, and a payee becomes the payer (and back) by name.
 * Whatever the caller does not name and cannot be defaulted is refused with the field to pass.
 */
final class Converter
{
    public const array TYPES = ['withdrawal', 'deposit', 'transfer'];

    /**
     * @param array{source_id?: null|int|string, source_name?: null|string, destination_id?: null|int|string, destination_name?: null|string} $given
     *
     * @return int the number of journals converted
     */
    public static function convert(TransactionGroup $group, string $toType, array $given, User $user, UserGroup $userGroup): int
    {
        /** @var null|TransactionType $destinationType */
        $destinationType = TransactionType::query()->where('type', ucfirst($toType))->first();
        if (null === $destinationType) {
            throw MachineException::notReady(sprintf('Firefly has no transaction type "%s".', $toType), 'php artisan firefly-iii:upgrade-database seeds the transaction types');
        }

        $count           = 0;

        /** @var TransactionJournal $journal */
        foreach ($group->transactionJournals()->with(['transactionType', 'transactions.account'])->get() as $journal) {
            self::convertJournal($journal, $destinationType, self::sides($journal, $toType, $given), $user, $userGroup);
            ++$count;
        }

        $group->refresh();
        Preferences::mark();
        $flags             = new TransactionGroupEventFlags();
        // a conversion is an edit: rules are not re-run on it (apis.mdx §8.3, apply_rules false on update)
        $flags->applyRules = false;
        event(new UpdatedSingleTransactionGroup($flags, TransactionGroupEventObjects::collectFromTransactionGroup($group)));
        event(new WebhookMessagesRequestSending());

        return $count;
    }

    /**
     * The type of a group, lower case ("withdrawal"); refused when the group mixes types.
     */
    public static function typeOf(TransactionGroup $group): string
    {
        $types = $group->transactionJournals()->with('transactionType')->get()->map(static fn (TransactionJournal $j): string => strtolower((string) $j->transactionType?->type))->unique()->values();
        if (1 !== $types->count()) {
            throw MachineException::conflict('This transaction group has splits of different types.', 'Fix it in Firefly III first (php artisan firefly-iii:correct-database)');
        }

        return (string) $types->first();
    }

    /**
     * @param array<string, mixed> $given
     *
     * @return array{source_id: null|int, source_name: null|string, destination_id: null|int, destination_name: null|string}
     */
    private static function sides(TransactionJournal $journal, string $toType, array $given): array
    {
        /** @var null|Transaction $out */
        $out     = $journal->transactions->first(static fn (Transaction $t): bool => -1 === bccomp((string) $t->amount, '0'));

        /** @var null|Transaction $in */
        $in      = $journal->transactions->first(static fn (Transaction $t): bool => 1 === bccomp((string) $t->amount, '0'));
        $source  = $out?->account;
        $dest    = $in?->account;
        $from    = strtolower((string) $journal->transactionType?->type);

        $sides   = [
            'source_id'        => self::id($given['source_id'] ?? null),
            'source_name'      => self::name($given['source_name'] ?? null),
            'destination_id'   => self::id($given['destination_id'] ?? null),
            'destination_name' => self::name($given['destination_name'] ?? null),
        ];
        $noSource = null === $sides['source_id'] && null === $sides['source_name'];
        $noDest   = null === $sides['destination_id'] && null === $sides['destination_name'];

        // what the UI pre-fills: the own account stays; the counterparty swaps role by name
        $defaults = match ($from.'>'.$toType) {
            'withdrawal>deposit' => ['source_name' => $dest?->name, 'destination_id' => $source?->id],
            'deposit>withdrawal' => ['source_id' => $dest?->id, 'destination_name' => $source?->name],
            'withdrawal>transfer', 'transfer>withdrawal' => ['source_id' => $source?->id],
            'deposit>transfer', 'transfer>deposit' => ['destination_id' => $dest?->id],
            default => [],
        };
        if ($noSource) {
            $sides['source_id']   = isset($defaults['source_id']) ? (int) $defaults['source_id'] : null;
            $sides['source_name'] = $defaults['source_name'] ?? null;
        }
        if ($noDest) {
            $sides['destination_id']   = isset($defaults['destination_id']) ? (int) $defaults['destination_id'] : null;
            $sides['destination_name'] = $defaults['destination_name'] ?? null;
        }
        if (null === $sides['source_id'] && null === $sides['source_name']) {
            throw MachineException::invalid(
                sprintf('Converting a %s to a %s needs a new source account.', $from, $toType),
                'transfer' === $toType ? 'Pass source_id (or source_name) — one of your asset accounts' : 'Pass source_id or source_name',
                ['field' => 'source_id', 'journal_id' => (string) $journal->id],
            );
        }
        if (null === $sides['destination_id'] && null === $sides['destination_name']) {
            throw MachineException::invalid(
                sprintf('Converting a %s to a %s needs a new destination account.', $from, $toType),
                'transfer' === $toType ? 'Pass destination_id (or destination_name) — one of your asset accounts' : 'Pass destination_id or destination_name',
                ['field' => 'destination_id', 'journal_id' => (string) $journal->id],
            );
        }

        return $sides;
    }

    /**
     * @param array{source_id: null|int, source_name: null|string, destination_id: null|int, destination_name: null|string} $sides
     */
    private static function convertJournal(TransactionJournal $journal, TransactionType $type, array $sides, User $user, UserGroup $userGroup): void
    {
        /** @var AccountValidator $validator */
        $validator = app(AccountValidator::class);
        $validator->setUser($user);
        $validator->setUserGroup($userGroup);
        $validator->setTransactionType($type->type);
        if (!$validator->validateSource(['id' => $sides['source_id'], 'name' => $sides['source_name']])) {
            throw MachineException::invalid(
                sprintf('Firefly III refused that source account for a %s: %s', strtolower($type->type), $validator->sourceError),
                'Pass a source_id / source_name that fits the new type (GET /machine/v1/accounts)',
                ['field' => 'source_id', 'journal_id' => (string) $journal->id],
            );
        }
        if (!$validator->validateDestination(['id' => $sides['destination_id'], 'name' => $sides['destination_name']])) {
            throw MachineException::invalid(
                sprintf('Firefly III refused that destination account for a %s: %s', strtolower($type->type), $validator->destError),
                'Pass a destination_id / destination_name that fits the new type (GET /machine/v1/accounts)',
                ['field' => 'destination_id', 'journal_id' => (string) $journal->id],
            );
        }

        $update    = [
            'source_id'        => $sides['source_id'],
            'source_name'      => $sides['source_name'],
            'destination_id'   => $sides['destination_id'],
            'destination_name' => $sides['destination_name'],
            'type'             => $type->type,
        ];

        // as upstream: a transfer between accounts of two currencies carries the second as foreign
        $from      = (string) $journal->transactionType?->type;
        if (TransactionTypeEnum::TRANSFER->value === $type->type && in_array($from, [TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::WITHDRAWAL->value], true)) {
            /** @var AccountRepositoryInterface $accounts */
            $accounts = app(AccountRepositoryInterface::class);
            $accounts->setUser($user);
            $source   = null === $sides['source_id'] ? null : $accounts->find($sides['source_id']);
            $dest     = null === $sides['destination_id'] ? null : $accounts->find($sides['destination_id']);
            $sc       = $source instanceof Account ? $accounts->getAccountCurrency($source) : null;
            $dc       = $dest instanceof Account ? $accounts->getAccountCurrency($dest) : null;
            if ($sc instanceof TransactionCurrency && $dc instanceof TransactionCurrency && $sc->code !== $dc->code) {
                /** @var null|Transaction $negative */
                $negative                      = $journal->transactions()->where('amount', '<', 0)->first();
                $update['currency_id']         = $sc->id;
                $update['foreign_currency_id'] = $dc->id;
                $update['foreign_amount']      = Steam::positive((string) ($negative->amount ?? '0'));
            }
        }

        /** @var JournalUpdateService $service */
        $service   = app(JournalUpdateService::class);
        $service->setTransactionJournal($journal);
        $service->setData($update);
        $service->update();
    }

    private static function id(mixed $v): ?int
    {
        if (null === $v || '' === $v) {
            return null;
        }
        if (!is_scalar($v) || 1 !== preg_match('/^\d{1,19}$/', (string) $v)) {
            throw MachineException::invalid('An account id must be a number.', 'Pass source_id / destination_id as a numeric id, or use source_name / destination_name');
        }

        return (int) $v;
    }

    private static function name(mixed $v): ?string
    {
        return is_string($v) && '' !== trim($v) ? trim($v) : null;
    }
}
