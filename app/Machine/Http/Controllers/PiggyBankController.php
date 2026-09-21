<?php

/*
 * PiggyBankController.php
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

namespace FireflyIII\Machine\Http\Controllers;

use Closure;
use FireflyIII\Api\V1\Requests\Models\PiggyBank\StoreRequest as PiggyStoreRequest;
use FireflyIII\Api\V1\Requests\Models\PiggyBank\UpdateRequest as PiggyUpdateRequest;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\Note;
use FireflyIII\Models\PiggyBank;
use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use FireflyIII\Support\JsonApi\Enrichments\PiggyBankEnrichment;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * pm/apis.mdx §8.6 — piggy banks: savings goals attached to asset (or liability) accounts.
 *
 * Reads: PiggyBankRepository + PiggyBankEnrichment (the pair upstream's /api/v1/piggy-banks uses).
 * Money: POST /piggy-banks/{id}/add runs Firefly's own canAddAmount() — the same check as the UI's
 * "add money" dialog — and refuses beyond what the account can spare WITH THE FIGURE
 * (details.max_amount, left_on_account, left_to_save); remove runs canRemoveAmount(). Both then
 * call addAmount()/removeAmount(), which also write the piggy bank event.
 * Create/edit: upstream's PiggyBank\StoreRequest / UpdateRequest validation, then the repository.
 */
final class PiggyBankController extends MachineController
{
    /**
     * The record class the undo log stores for rows of the account_piggy_bank pivot (where the
     * saved amount per account lives). Upstream has no Eloquent model for that pivot; until the
     * core ships one under this name, POST /undo reports these rows as "unknown record type" and
     * REFUSES — it never half-reverses a piggy bank move (see open issue in the family report).
     */
    public const string PIVOT_RECORD = 'FireflyIII\Machine\Undo\Rows\AccountPiggyBank';

    // ------------------------------------------------------------------ reads ---

    /** GET /piggy-banks */
    public function index(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params = $this->listParams($request, ['id', 'name', 'order', 'target_date', 'current_amount', 'left_to_save'], 'order');
        $rows   = $this->applyList($this->present($this->repository()->getPiggyBanks()), $params);

        return $this->ok(['piggy_banks' => $rows]);
    }

    /** GET /piggy-banks/{id} — with, per account, how much more it can take today (available_to_add). */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->input($request, [], true);
        $piggy = $this->findPiggy($id);

        return $this->ok(['piggy_bank' => $this->present(new Collection([$piggy]), true)[0]]);
    }

    /** GET /piggy-banks/{id}/events — every add and remove, newest first. */
    public function events(Request $request, string $id): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params   = $this->listParams($request, ['date', 'id', 'amount'], '-date');
        $piggy    = $this->findPiggy($id);
        $currency = $piggy->transactionCurrency;
        $places   = (int) $currency->decimal_places;
        $rows     = [];

        /** @var PiggyBankEvent $event */
        foreach ($this->repository()->getEvents($piggy) as $event) {
            $amount  = (string) $event->amount;
            $journal = $event->transactionJournal;
            $account = null;
            if (null !== $journal) {
                // money in: the account the transfer went to; money out: the account it came from
                $side    = $journal->transactions()->with('account')->where('amount', Money::compare($amount, '0') >= 0 ? '>' : '<', 0)->first();
                $account = $side?->account;
            }
            if (null === $account && 1 === $piggy->accounts->count()) {
                $account = $piggy->accounts->first();
            }
            $rows[]  = [
                'id'                     => (int) $event->id,
                'date'                   => $event->date?->format('Y-m-d'),
                'direction'              => Money::compare($amount, '0') >= 0 ? 'add' : 'remove',
                'amount'                 => Money::format(Money::abs($amount), $places), // positive; direction says which way (§14.1)
                'currency_code'          => (string) $currency->code,
                'account_id'             => null === $account ? null : (int) $account->id,
                'account_name'           => $account?->name,
                'transaction_journal_id' => null === $event->transaction_journal_id ? null : (int) $event->transaction_journal_id,
                'transaction_group_id'   => null === $journal ? null : (int) $journal->transaction_group_id,
            ];
        }

        return $this->ok([
            'piggy_bank' => ['id' => (int) $piggy->id, 'name' => (string) $piggy->name, 'currency_code' => (string) $currency->code],
            'events'     => $this->applyList($rows, $params),
        ]);
    }

    // ----------------------------------------------------------------- writes ---

    /** POST /piggy-banks */
    public function store(Request $request): JsonResponse
    {
        $args     = $this->input($request, [
            'name'          => ['required', 'string', 'min:1', 'max:255'],
            'accounts'      => ['required', 'array', 'min:1'],
            'accounts.*'    => ['required'],
            'target_amount' => ['sometimes', 'nullable'],
            'target_date'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_date'    => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'min:3', 'max:51'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:65000'],
        ]);
        $accounts = $this->accountList($args['accounts']);
        $currency = null !== ($args['currency_code'] ?? null) && '' !== $args['currency_code']
            ? SubscriptionController::currencyByCode((string) $args['currency_code'])
            : $this->accountCurrency($accounts[0]['account']);
        $data     = $this->piggyData($args, $currency, $accounts);
        $data['start_date'] ??= SubscriptionController::today()->format('Y-m-d');
        $data['target_amount'] ??= '0';

        return $this->write($request, $args, function (bool $dryRun) use ($data): WriteResult {
            $form   = SubscriptionController::upstreamForm(PiggyStoreRequest::class, $data);
            $specs  = $this->specs(null, array_map(static fn (array $a): int => (int) $a['account_id'], $data['accounts']));
            $before = SubscriptionController::snapshot($specs);
            $piggy  = $this->repository()->store($form->getAll());
            $result = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));

            return $result->count('created')->with(['piggy_bank' => $this->present(new Collection([$piggy->refresh()]))[0]]);
        });
    }

    /** PUT /piggy-banks/{id} — `accounts`, when given, is the complete new list of linked accounts. */
    public function update(Request $request, string $id): JsonResponse
    {
        $args     = $this->input($request, [
            'name'          => ['sometimes', 'string', 'min:1', 'max:255'],
            'accounts'      => ['sometimes', 'array', 'min:1'],
            'accounts.*'    => ['required'],
            'target_amount' => ['sometimes', 'nullable'],
            'target_date'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'start_date'    => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'currency_code' => ['sometimes', 'string', 'min:3', 'max:51'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:65000'],
        ]);
        if ([] === array_diff(array_keys($args), array_keys(self::CONTROL_RULES))) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of: name, accounts, target_amount, target_date, start_date, currency_code, notes');
        }
        $piggy    = $this->findPiggy($id);
        $currency = array_key_exists('currency_code', $args) ? SubscriptionController::currencyByCode((string) $args['currency_code']) : $piggy->transactionCurrency;
        $accounts = array_key_exists('accounts', $args) ? $this->accountList($args['accounts']) : null;
        $data     = $this->piggyData($args, $currency, $accounts);
        if (!array_key_exists('currency_code', $args)) {
            unset($data['transaction_currency_code']);
        }
        // no `accounts` = upstream's "leave the links and the saved amounts alone" (an empty list to linkToAccountIds)
        $piggyId  = (int) $piggy->id;

        return $this->write($request, $args, function (bool $dryRun) use ($data, $piggyId): WriteResult {
            /** @var PiggyBank $piggy */
            $piggy   = PiggyBank::query()->findOrFail($piggyId);
            $form    = SubscriptionController::upstreamForm(PiggyUpdateRequest::class, $data, ['piggyBank' => $piggy]);
            $ids     = array_merge($piggy->accounts->pluck('id')->map(static fn ($v): int => (int) $v)->all(), array_map(static fn (array $a): int => (int) $a['account_id'], $data['accounts'] ?? []));
            $specs   = $this->specs($piggy, $ids);
            $before  = SubscriptionController::snapshot($specs);
            self::stringPivots($piggy);
            $updated = $this->repository()->update($piggy, $form->getAll());
            $result  = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));
            $result->count([] === $result->touched ? 'unchanged' : 'updated');

            return $result->with(['piggy_bank' => $this->present(new Collection([$updated->refresh()]))[0]]);
        });
    }

    /** POST /piggy-banks/{id}/add — the UI's "add money". */
    public function add(Request $request, string $id): JsonResponse
    {
        return $this->move($request, $id, 'add');
    }

    /** POST /piggy-banks/{id}/remove */
    public function remove(Request $request, string $id): JsonResponse
    {
        return $this->move($request, $id, 'remove');
    }

    /** DELETE /piggy-banks/{id} — admin tier. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, []);
        $piggy = $this->findPiggyOrGone($id);
        if (null === $piggy) {
            return $this->write($request, $args, static fn (bool $dryRun): WriteResult => (new WriteResult(['deleted' => 0]))->with(['piggy_bank' => null, 'deleted' => 0]));
        }
        $attachments = $piggy->attachments()->count();
        if ($attachments > 0) {
            throw MachineException::conflict(
                sprintf('Piggy bank "%s" has %d attachment(s); deleting it would delete their files, which no dry run or undo can hold.', $piggy->name, $attachments),
                'Remove the attachments in the Firefly III web interface first (or delete the piggy bank there)',
                ['piggy_bank_id' => (int) $piggy->id, 'attachments' => $attachments],
            );
        }
        $piggyId = (int) $piggy->id;

        return $this->write($request, $args, function (bool $dryRun) use ($piggyId): WriteResult {
            /** @var PiggyBank $piggy */
            $piggy   = PiggyBank::query()->findOrFail($piggyId);
            $summary = ['id' => $piggyId, 'name' => (string) $piggy->name, 'current_amount' => Money::format($this->repository()->getCurrentAmount($piggy), (int) $piggy->transactionCurrency->decimal_places), 'currency_code' => (string) $piggy->transactionCurrency->code];
            $specs   = $this->specs($piggy, $piggy->accounts->pluck('id')->map(static fn ($v): int => (int) $v)->all());
            $before  = SubscriptionController::snapshot($specs);
            $this->repository()->destroy($piggy);
            $result  = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));

            return $result->count('deleted')->with(['piggy_bank' => $summary, 'deleted' => 1]);
        });
    }

    // ------------------------------------------------------------- internals ---

    private function move(Request $request, string $id, string $direction): JsonResponse
    {
        $args     = $this->input($request, [
            'amount'       => ['required'],
            'account_id'   => ['sometimes', 'nullable'],
            'account_name' => ['sometimes', 'nullable', 'string'],
        ]);
        $piggy    = $this->findPiggy($id);
        $currency = $piggy->transactionCurrency;
        $amount   = Money::positive($args['amount'], (int) $currency->decimal_places, 'amount', (string) $currency->code);
        $account  = $this->linkedAccount($piggy, $args);
        $piggyId  = (int) $piggy->id;
        $accountId = (int) $account->id;
        $this->assertMovable($piggy, $account, $amount, $direction);

        return $this->write($request, $args, function (bool $dryRun) use ($piggyId, $accountId, $amount, $direction): WriteResult {
            /** @var PiggyBank $piggy */
            $piggy   = PiggyBank::query()->findOrFail($piggyId);
            /** @var Account $account */
            $account = Account::query()->findOrFail($accountId);
            // re-checked under the write lock: the balance may have moved since the plan
            $this->assertMovable($piggy, $account, $amount, $direction);
            $specs   = $this->specs($piggy, [$accountId]);
            $before  = SubscriptionController::snapshot($specs);
            $repo    = $this->repository();
            'add' === $direction ? $repo->addAmount($piggy, $account, $amount) : $repo->removeAmount($piggy, $account, $amount);
            $result  = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));
            $piggy   = PiggyBank::query()->findOrFail($piggyId);

            return $result->count('add' === $direction ? 'added' : 'removed')->with([
                'direction'     => $direction,
                'amount'        => $amount,
                'currency_code' => (string) $piggy->transactionCurrency->code,
                'account_id'    => $accountId,
                'account_name'  => (string) $account->name,
                'piggy_bank'    => $this->present(new Collection([$piggy]))[0],
            ]);
        });
    }

    /**
     * SQLite hands a whole-number decimal back as an int, and upstream's PiggyBankFactory::linkToAccountIds()
     * passes the pivot's current_amount straight to bcsub() (a TypeError under strict types). Give the
     * loaded relation — which the factory reads — the decimal strings the column holds. Nothing is saved.
     */
    private static function stringPivots(PiggyBank $piggy): void
    {
        foreach ($piggy->accounts as $account) {
            $account->pivot->current_amount = (string) ($account->pivot->current_amount ?? '0');
            if (null !== $account->pivot->native_current_amount) {
                $account->pivot->native_current_amount = (string) $account->pivot->native_current_amount;
            }
        }
    }

    /** Firefly's own checks (the UI's): add ≤ min(left on account, left to save); remove ≤ saved on that account. */
    private function assertMovable(PiggyBank $piggy, Account $account, string $amount, string $direction): void
    {
        $repo     = $this->repository();
        $currency = $piggy->transactionCurrency;
        $places   = (int) $currency->decimal_places;
        if ('remove' === $direction) {
            if ($repo->canRemoveAmount($piggy, $account, $amount)) {
                return;
            }
            $saved = Money::format($repo->getCurrentAmount($piggy, $account), $places);

            throw MachineException::invalid(
                sprintf('Cannot remove %s %s: piggy bank "%s" holds only %s %s on account "%s".', $currency->code, $amount, $piggy->name, $currency->code, $saved, $account->name),
                sprintf('Remove at most "%s"', $saved),
                ['field' => 'amount', 'max_amount' => $saved, 'saved_on_account' => $saved, 'currency_code' => (string) $currency->code, 'account_id' => (int) $account->id],
            );
        }
        if ($repo->canAddAmount($piggy, $account, $amount)) {
            return;
        }
        $figures = $this->addFigures($piggy, $account);

        throw MachineException::invalid(
            sprintf('Cannot add %s %s to piggy bank "%s": account "%s" can spare %s %s.', $currency->code, $amount, $piggy->name, $account->name, $currency->code, $figures['max_amount']),
            Money::compare($figures['max_amount'], '0') > 0
                ? sprintf('Add at most "%s" (left on the account: %s, left to save: %s)', $figures['max_amount'], $figures['left_on_account'], $figures['left_to_save'] ?? 'no target')
                : 'Nothing can be added today — the account has no money that is not already set aside, or the goal is reached',
            ['field' => 'amount'] + $figures + ['currency_code' => (string) $currency->code, 'account_id' => (int) $account->id],
        );
    }

    /**
     * What the UI's add-money dialog shows for one account (AmountController::add()).
     *
     * @return array{left_on_account: string, left_to_save: null|string, max_amount: string}
     */
    private function addFigures(PiggyBank $piggy, Account $account): array
    {
        $repo       = $this->repository();
        $places     = (int) $piggy->transactionCurrency->decimal_places;
        $left       = $repo->leftOnAccount($piggy, $account, SubscriptionController::today()->endOfDay());
        $saved      = $repo->getCurrentAmount($piggy);
        $hasTarget  = !Money::isZero((string) $piggy->target_amount);
        $leftToSave = $hasTarget ? Money::sub((string) $piggy->target_amount, $saved) : null;
        $max        = null === $leftToSave ? $left : (1 === Money::compare($left, $leftToSave) ? $leftToSave : $left);
        if (Money::compare($max, '0') < 0) {
            $max = '0';
        }

        return [
            'left_on_account' => Money::format($left, $places),
            'left_to_save'    => null === $leftToSave ? null : Money::format($leftToSave, $places),
            'max_amount'      => self::floor($max, $places),
        ];
    }

    /** Round DOWN to the currency's places: a figure offered as "at most" must itself be addable. */
    private static function floor(string $amount, int $places): string
    {
        $truncated = bcadd($amount, '0', $places);

        return Money::format($truncated, $places);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function linkedAccount(PiggyBank $piggy, array $args): Account
    {
        $linked = $piggy->accounts;
        $ref    = $args['account_id'] ?? $args['account_name'] ?? null;
        if (null === $ref || '' === (string) $ref) {
            if (1 === $linked->count()) {
                return $linked->first();
            }

            throw MachineException::invalid(
                sprintf('Piggy bank "%s" is linked to %d accounts; say which one.', $piggy->name, $linked->count()),
                'Pass account_id (or account_name) — the candidates are in details.candidates',
                ['candidates' => $linked->map(static fn (Account $a): array => ['id' => (int) $a->id, 'name' => (string) $a->name])->values()->all()],
            );
        }
        $account = $this->resolveAccount((string) $ref);
        if (!$linked->contains(static fn (Account $a): bool => (int) $a->id === (int) $account->id)) {
            throw MachineException::invalid(
                sprintf('Account "%s" is not linked to piggy bank "%s".', $account->name, $piggy->name),
                'Use one of details.candidates, or link the account first with PUT /machine/v1/piggy-banks/'.$piggy->id,
                ['field' => 'account_id', 'candidates' => $linked->map(static fn (Account $a): array => ['id' => (int) $a->id, 'name' => (string) $a->name])->values()->all()],
            );
        }

        return $account;
    }

    private function resolveAccount(string $ref): Account
    {
        $groupId = (int) $this->administration()->id;

        /** @var Account */
        return $this->resolve(Account::class, $ref, 'name', static function (EloquentBuilder $q) use ($groupId): void {
            $q->where('accounts.user_group_id', $groupId)
                ->whereHas('accountType', static fn (EloquentBuilder $t) => $t->whereIn('type', (array) config('firefly.piggy_bank_account_types')));
        });
    }

    /**
     * accounts[] accepts ids, names, or objects {account_id|account_name, current_amount}.
     *
     * @param array<int, mixed> $raw
     *
     * @return list<array{account: Account, current_amount: null|string}>
     */
    private function accountList(array $raw): array
    {
        $out  = [];
        $seen = [];
        foreach (array_values($raw) as $i => $entry) {
            $amount = null;
            if (is_array($entry)) {
                $unknown = array_diff(array_keys($entry), ['account_id', 'account_name', 'current_amount']);
                if ([] !== $unknown) {
                    throw MachineException::invalid(sprintf('Unknown field%s in accounts.%d: %s.', 1 === count($unknown) ? '' : 's', $i, implode(', ', $unknown)), 'Each entry is an account id, a name, or {"account_id": "1", "current_amount": "0.00"}', ['field' => 'accounts.'.$i]);
                }
                $ref    = $entry['account_id'] ?? $entry['account_name'] ?? null;
                $amount = array_key_exists('current_amount', $entry) && null !== $entry['current_amount'] ? $entry['current_amount'] : null;
            } else {
                $ref = $entry;
            }
            if (!is_string($ref) && !is_int($ref)) {
                throw MachineException::invalid(sprintf('accounts.%d names no account.', $i), 'Pass an account id or name, or {"account_id": "1"}', ['field' => 'accounts.'.$i]);
            }
            $account = $this->resolveAccount((string) $ref);
            if (isset($seen[$account->id])) {
                throw MachineException::invalid(sprintf('Account "%s" is listed twice.', $account->name), 'List each account once', ['field' => 'accounts.'.$i]);
            }
            $seen[$account->id] = true;
            $out[]              = ['account' => $account, 'current_amount' => $amount];
        }

        return $out;
    }

    private function accountCurrency(Account $account): TransactionCurrency
    {
        /** @var AccountRepositoryInterface $repo */
        $repo = app(AccountRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo->getAccountCurrency($account) ?? $this->primaryCurrency();
    }

    /**
     * @param array<string, mixed>                                            $args
     * @param null|list<array{account: Account, current_amount: null|string}> $accounts
     *
     * @return array<string, mixed>
     */
    private function piggyData(array $args, TransactionCurrency $currency, ?array $accounts): array
    {
        $places = (int) $currency->decimal_places;
        $data   = ['transaction_currency_code' => (string) $currency->code];
        foreach (['name', 'target_date', 'start_date', 'notes'] as $field) {
            if (array_key_exists($field, $args)) {
                $data[$field] = $args[$field] ?? ('notes' === $field ? '' : null);
            }
        }
        foreach (['target_date', 'start_date'] as $field) {
            if (array_key_exists($field, $data) && null === $data[$field]) {
                unset($data[$field]);
                if ('target_date' === $field) {
                    $data['target_date'] = '';
                }
            }
        }
        if (array_key_exists('target_amount', $args)) {
            // null = no target (Firefly stores "0" for "no target"; the plane shows it as null, §14.2)
            $data['target_amount'] = null === $args['target_amount'] ? '0' : Money::normalize($args['target_amount'], $places, 'target_amount', (string) $currency->code);
            if (Money::compare($data['target_amount'], '0') < 0) {
                throw MachineException::invalid('target_amount cannot be negative.', 'Pass a positive target, or null for no target', ['field' => 'target_amount']);
            }
        }
        if (null !== $accounts) {
            $data['accounts'] = array_map(static function (array $a) use ($places, $currency): array {
                $row = ['account_id' => (int) $a['account']->id];
                if (null !== $a['current_amount']) {
                    $row['current_amount'] = Money::normalize($a['current_amount'], $places, 'current_amount', (string) $currency->code);
                }

                return $row;
            }, $accounts);
        }

        return $data;
    }

    /**
     * The rows a piggy-bank write can change: the piggy bank(s), their notes, events, and the
     * per-account saved amounts (the account_piggy_bank pivot).
     *
     * @param list<int> $accountIds
     *
     * @return list<array{0: string, 1: string, 2: Closure(QueryBuilder): void}>
     */
    private function specs(?PiggyBank $piggy, array $accountIds): array
    {
        // a new piggy bank is linked to $accountIds, so "every piggy bank on these accounts" finds it afterwards
        $piggyIds = static fn (QueryBuilder $q) => $q->from('account_piggy_bank')->whereIn('account_id', $accountIds)->select('piggy_bank_id');
        $scope    = static function (QueryBuilder $q, string $column) use ($piggy, $piggyIds): void {
            if (null !== $piggy) {
                $q->where($column, $piggy->id);

                return;
            }
            $q->whereIn($column, $piggyIds);
        };

        return [
            [PiggyBank::class, 'piggy_banks', static function (QueryBuilder $q) use ($scope): void { $scope($q, 'id'); }],
            [self::PIVOT_RECORD, 'account_piggy_bank', static function (QueryBuilder $q) use ($scope): void { $scope($q, 'piggy_bank_id'); }],
            [PiggyBankEvent::class, 'piggy_bank_events', static function (QueryBuilder $q) use ($scope): void { $scope($q, 'piggy_bank_id'); }],
            [Note::class, 'notes', static function (QueryBuilder $q) use ($scope): void {
                $q->where('noteable_type', PiggyBank::class);
                $scope($q, 'noteable_id');
            }],
        ];
    }

    private function repository(): PiggyBankRepositoryInterface
    {
        /** @var PiggyBankRepositoryInterface $repository */
        $repository = app(PiggyBankRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }

    /** Piggy banks have no owner column; they belong to the administration through their accounts. */
    private function scope(): Closure
    {
        $groupId = (int) $this->administration()->id;

        return static function (EloquentBuilder $q) use ($groupId): void {
            $q->whereHas('accounts', static fn (EloquentBuilder $a) => $a->where('accounts.user_group_id', $groupId));
        };
    }

    private function findPiggy(string $id): PiggyBank
    {
        /** @var PiggyBank */
        return $this->resolve(PiggyBank::class, urldecode($id), 'name', $this->scope());
    }

    private function findPiggyOrGone(string $id): ?PiggyBank
    {
        $value = trim(urldecode($id));
        if (1 === preg_match('/^\d{1,19}$/', $value)) {
            $query   = PiggyBank::withTrashed()->where('piggy_banks.id', $value);
            ($this->scope())($query);
            $trashed = $query->first();
            if (null !== $trashed && $trashed->trashed()) {
                return null;
            }
        }

        return $this->findPiggy($value);
    }

    /**
     * Firefly's enrichment, in the plane's shape. target_amount null = no target (§14.2).
     *
     * @param Collection<int, PiggyBank> $piggies
     *
     * @return list<array<string, mixed>>
     */
    private function present(Collection $piggies, bool $withAvailability = false): array
    {
        if (0 === $piggies->count()) {
            return [];
        }
        $enrichment = new PiggyBankEnrichment();
        $enrichment->setUser($this->operator());
        $enrichment->setDate(SubscriptionController::today());
        $piggies    = $enrichment->enrich($piggies);
        $primary    = $this->primaryCurrency();
        $rows       = [];

        /** @var PiggyBank $piggy */
        foreach ($piggies as $piggy) {
            $meta     = (array) $piggy->meta;
            $currency = $meta['currency'] instanceof TransactionCurrency ? $meta['currency'] : $piggy->transactionCurrency;
            $places   = (int) $currency->decimal_places;
            $fmt      = static fn (mixed $v): ?string => null === $v || '' === (string) $v ? null : Money::format((string) $v, $places);
            $pfmt     = static fn (mixed $v): ?string => null === $v || '' === (string) $v ? null : Money::format((string) $v, (int) $primary->decimal_places);
            $accounts = [];
            foreach ((array) $meta['accounts'] as $a) {
                $row = ['account_id' => (int) $a['account_id'], 'name' => (string) ($a['name'] ?? ''), 'current_amount' => $fmt($a['current_amount'] ?? '0')];
                if ($withAvailability) {
                    $account = $piggy->accounts->first(static fn (Account $acc): bool => (int) $acc->id === (int) $a['account_id']);
                    if (null !== $account) {
                        $figures                 = $this->addFigures($piggy, $account);
                        $row['left_on_account']  = $figures['left_on_account'];
                        $row['available_to_add'] = $figures['max_amount'];
                    }
                }
                $accounts[] = $row;
            }
            $target   = $fmt($meta['target_amount'] ?? null);
            $current  = $fmt($meta['current_amount'] ?? '0') ?? Money::format('0', $places);
            $rows[]   = [
                'id'                    => (int) $piggy->id,
                'name'                  => (string) $piggy->name,
                'currency_code'         => (string) $currency->code,
                'target_amount'         => $target,
                'current_amount'        => $current,
                'left_to_save'          => null === $target ? null : $fmt(Money::sub((string) ($meta['target_amount'] ?? '0'), (string) ($meta['current_amount'] ?? '0'))),
                'save_per_month'        => $fmt($meta['save_per_month'] ?? null),
                'percentage'            => null === $target || Money::isZero((string) $meta['target_amount']) ? null : Money::format((string) Money::div(Money::mul((string) ($meta['current_amount'] ?? '0'), '100'), (string) $meta['target_amount']), 0),
                'primary_currency_code' => (string) $primary->code,
                'pc_current_amount'     => $pfmt($meta['pc_current_amount'] ?? null),
                'pc_target_amount'      => $pfmt($meta['pc_target_amount'] ?? null),
                'start_date'            => $piggy->start_date?->format('Y-m-d'),
                'target_date'           => $piggy->target_date?->format('Y-m-d'),
                'order'                 => (int) $piggy->order,
                'notes'                 => $meta['notes'] ?? null,
                'object_group_title'    => $meta['object_group_title'] ?? null,
                'accounts'              => $accounts,
                'updated_at'            => $piggy->updated_at?->toIso8601String(),
            ];
        }

        return $rows;
    }
}
