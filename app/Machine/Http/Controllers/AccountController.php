<?php

/*
 * AccountController.php
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

use Carbon\Carbon;
use Closure;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Enums\TransactionTypeEnum;
use FireflyIII\Factory\TransactionGroupFactory;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Machine\Confirm\ConfirmTokens;
use FireflyIII\Machine\DryRun;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\AccountType;
use FireflyIII\Models\Location;
use FireflyIII\Models\Note;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Support\Facades\Steam;
use FireflyIII\Support\JsonApi\Enrichments\AccountEnrichment;
use FireflyIII\Support\JsonApi\Enrichments\TransactionGroupEnrichment;
use FireflyIII\Transformers\AccountTransformer;
use FireflyIII\Transformers\TransactionGroupTransformer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * pm/apis.mdx §8.2 — accounts, and reconciliation.
 *
 * Reads render every account through Firefly's own AccountEnrichment + AccountTransformer (the
 * transformer /api/v1 uses), flattened. Writes go through AccountRepository (AccountFactory,
 * AccountUpdateService, AccountDestroyService) inside the write protocol (§7): dry run by default,
 * confirm token, fingerprint, ceiling, operation log.
 *
 * Reconciliation is composed (R1's named exception): plan() computes the figures the UI's
 * Reconcile screen shows (Account\ReconcileController::reconcile() for the start/end balance,
 * Json\ReconcileController::overview() for the difference arithmetic), and apply() does what the
 * UI's submit() does — reconcileById()'s update per journal (scoped to the bound administration,
 * see setReconciled()) and, when the difference is
 * non-zero and create_reconciliation is true, Firefly's own reconciliation transaction through
 * TransactionGroupFactory. Never a plug hidden inside another row (§7.6).
 */
final class AccountController extends MachineController
{
    /** The public `type` filter → Firefly's account types. */
    private const array TYPES = [
        'asset'     => [AccountTypeEnum::ASSET->value, AccountTypeEnum::DEFAULT->value],
        'expense'   => [AccountTypeEnum::EXPENSE->value, AccountTypeEnum::BENEFICIARY->value],
        'revenue'   => [AccountTypeEnum::REVENUE->value],
        'liability' => [AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value, AccountTypeEnum::CREDITCARD->value],
        'cash'      => [AccountTypeEnum::CASH->value],
    ];

    /** Account types a caller may edit, deactivate, reorder or delete (not Firefly's internal ones). */
    private const array EDITABLE = [
        AccountTypeEnum::ASSET->value, AccountTypeEnum::DEFAULT->value, AccountTypeEnum::EXPENSE->value, AccountTypeEnum::BENEFICIARY->value,
        AccountTypeEnum::REVENUE->value, AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::MORTGAGE->value, AccountTypeEnum::CREDITCARD->value,
    ];

    private const array ORDERABLE_FIELDS = ['name', 'id', 'type', 'order', 'current_balance', 'active', 'created_at', 'updated_at', 'last_activity', 'account_role'];

    /** Order fields the account row itself carries — the list is paged before it is enriched. */
    private const array MODEL_ORDERABLE = ['name', 'id', 'order', 'active', 'created_at', 'updated_at'];

    /** The transaction `type` filter on /accounts/{id}/transactions. */
    private const array TRANSACTION_TYPES = [
        'all'             => [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value, TransactionTypeEnum::OPENING_BALANCE->value, TransactionTypeEnum::RECONCILIATION->value],
        'default'         => [TransactionTypeEnum::WITHDRAWAL->value, TransactionTypeEnum::DEPOSIT->value, TransactionTypeEnum::TRANSFER->value],
        'withdrawal'      => [TransactionTypeEnum::WITHDRAWAL->value],
        'deposit'         => [TransactionTypeEnum::DEPOSIT->value],
        'transfer'        => [TransactionTypeEnum::TRANSFER->value],
        'opening_balance' => [TransactionTypeEnum::OPENING_BALANCE->value],
        'reconciliation'  => [TransactionTypeEnum::RECONCILIATION->value],
    ];

    private const string APPLY_ROUTE    = 'POST /accounts/{id}/reconcile/apply';
    private const string RECONCILE_KEY  = 'machine:reconcile:%s';

    /** The fields POST /accounts and each /accounts/batch row accept. */
    private const array CREATE_RULES = [
        'name'                 => ['required', 'string', 'min:1', 'max:1024'],
        'type'                 => ['required', 'string', 'in:asset,expense,revenue,liability,loan,debt,mortgage'],
        'account_role'         => ['sometimes', 'nullable', 'string', 'in:defaultAsset,sharedAsset,savingAsset,ccAsset,cashWalletAsset'],
        'currency_code'        => ['sometimes', 'nullable', 'string', 'size:3'],
        'opening_balance'      => ['sometimes', 'nullable'],
        'opening_balance_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'iban'                 => ['sometimes', 'nullable', 'string', 'max:255'],
        'bic'                  => ['sometimes', 'nullable', 'string', 'max:255'],
        'account_number'       => ['sometimes', 'nullable', 'string', 'max:255'],
        'include_net_worth'    => ['sometimes', 'boolean'],
        'active'               => ['sometimes', 'boolean'],
        'virtual_balance'      => ['sometimes', 'nullable'],
        'notes'                => ['sometimes', 'nullable', 'string', 'max:32768'],
        'liability_type'       => ['sometimes', 'nullable', 'string', 'in:loan,debt,mortgage'],
        'liability_direction'  => ['sometimes', 'nullable', 'string', 'in:credit,debit'],
        'interest'             => ['sometimes', 'nullable', 'string', 'max:32'],
        'interest_period'      => ['sometimes', 'nullable', 'string', 'in:daily,weekly,monthly,quarterly,half-year,yearly'],
        'credit_card_type'     => ['sometimes', 'nullable', 'string', 'in:monthlyFull'],
        'monthly_payment_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
    ];

    // ================================================================== reads ===

    /** GET /accounts — by type, with balances (Firefly's enrichment), filtered, ordered, capped. */
    public function index(Request $request): JsonResponse
    {
        $args     = $this->input($request, [
            'type'          => ['sometimes', 'string', 'in:asset,expense,revenue,liability,cash,all'],
            'active'        => ['sometimes', 'string', 'in:true,false,all,1,0'],
            'with_balances' => ['sometimes', 'boolean'],
            'as_of'         => ['sometimes', 'date_format:Y-m-d'],
            'search'        => ['sometimes', 'nullable', 'string', 'max:255'],
        ] + self::LIST_RULES, true);
        $params   = $this->listParams($request, self::ORDERABLE_FIELDS, 'name');
        $type     = (string) ($args['type'] ?? 'all');
        $active   = strtolower((string) ($args['active'] ?? 'true'));
        $balances = (bool) ($args['with_balances'] ?? true);
        $asOf     = isset($args['as_of']) ? $this->date((string) $args['as_of'], 'as_of') : null;

        $query    = Account::query()
            ->where('accounts.user_group_id', $this->administration()->id)
            ->whereIn('accounts.account_type_id', $this->typeIds($this->typesFor($type)))
        ;
        if (in_array($active, ['true', '1'], true)) {
            $query->where('accounts.active', true);
        }
        if (in_array($active, ['false', '0'], true)) {
            $query->where('accounts.active', false);
        }
        $search   = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            // "name contains" — the operator's % and _ are literal characters, never wildcards
            $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search));
            $query->whereRaw("LOWER(accounts.name) LIKE ? ESCAPE '!'", ['%'.$needle.'%']);
        }
        $accounts = $query->get();
        if (in_array($params->orderField, self::MODEL_ORDERABLE, true)) {
            // ordered by a column the model already has: page first, then enrich only the page —
            // Firefly's balance enrichment of every expense account is the expensive part
            $keys = $accounts->map(static fn (Account $a): array => [
                'id'         => (string) $a->id,
                'name'       => (string) $a->name,
                'order'      => null === $a->order ? null : (int) $a->order,
                'active'     => (bool) $a->active,
                'created_at' => $a->created_at?->toAtomString(),
                'updated_at' => $a->updated_at?->toAtomString(),
                '__model'    => $a,
            ])->all();
            $page = $this->applyList($keys, $params);
            $rows = $this->renderAccounts(new Collection(array_column($page, '__model')), $asOf, $balances);
        }
        if (!in_array($params->orderField, self::MODEL_ORDERABLE, true)) {
            $rows = $this->applyList($this->renderAccounts($accounts, $asOf, $balances), $params);
        }

        return $this->ok(['accounts' => $rows], ['filters' => ['type' => $type, 'active' => $active, 'as_of' => $asOf?->format('Y-m-d'), 'search' => '' === $search ? null : $search]]);
    }

    /** GET /accounts/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, ['as_of' => ['sometimes', 'date_format:Y-m-d']], true);
        $account = $this->account($id);
        $asOf    = isset($args['as_of']) ? $this->date((string) $args['as_of'], 'as_of') : null;

        return $this->ok(['account' => $this->renderAccounts(new Collection([$account]), $asOf, true)[0]]);
    }

    /** GET /accounts/{id}/balance — Firefly's balance at the END of `as_of` (default today), per currency. */
    public function balance(Request $request, string $id): JsonResponse
    {
        $args     = $this->input($request, ['as_of' => ['sometimes', 'date_format:Y-m-d']], true);
        $account  = $this->account($id);
        $date     = isset($args['as_of']) ? $this->date((string) $args['as_of'], 'as_of') : Carbon::today(config('app.timezone'));
        $date->endOfDay();
        $currency = $this->accountCurrency($account);
        $primary  = $this->primaryCurrency();
        $raw      = Steam::accountsBalancesOptimized(new Collection([$account]), $date, $primary, false)[$account->id] ?? ['balance' => '0'];
        $asOf     = $date->format('Y-m-d');

        $perCurrency = [];
        foreach ($raw as $code => $amount) {
            if (in_array($code, ['balance', 'pc_balance'], true)) {
                continue;
            }
            $cur                  = $code === $currency->code ? $currency : TransactionCurrency::query()->where('code', (string) $code)->first();
            $perCurrency[(string) $code] = [
                'currency_code' => (string) $code,
                'balance'       => null === $cur ? Money::strip(Money::add((string) $amount, '0')) : Money::forCurrency((string) $amount, $cur),
                'as_of'         => $asOf,
            ];
        }
        if (!array_key_exists($currency->code, $perCurrency)) {
            $perCurrency = [$currency->code => ['currency_code' => $currency->code, 'balance' => Money::forCurrency((string) ($raw['balance'] ?? '0'), $currency), 'as_of' => $asOf]] + $perCurrency;
        }
        ksort($perCurrency);

        $data = [
            'account_id'    => (string) $account->id,
            'account_name'  => (string) $account->name,
            'as_of'         => $asOf,
            'currency_code' => $currency->code,
            'balance'       => $perCurrency[$currency->code]['balance'],
            'balances'      => array_values($perCurrency),
            'signed'        => true,
        ];

        return $this->ok($data);
    }

    /** GET /accounts/{id}/properties — the facts a caller needs before acting on an account. */
    public function properties(Request $request, string $id): JsonResponse
    {
        $this->input($request, [], true);
        $account    = $this->account($id);
        $repository = $this->accounts();
        $currency   = $this->accountCurrency($account);
        $type       = (string) $account->accountType->type;

        $journals   = DB::table('transactions')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'transactions.transaction_journal_id')
            ->where('transactions.account_id', $account->id)
            ->whereNull('transactions.deleted_at')
            ->whereNull('transaction_journals.deleted_at')
        ;
        $stats      = (clone $journals)->selectRaw('COUNT(DISTINCT transaction_journals.id) AS journal_count, MIN(transaction_journals.date) AS first_date, MAX(transaction_journals.date) AS last_date')->first();
        $uncleared  = (clone $journals)->where('transactions.reconciled', false)->distinct()->count('transaction_journals.id');
        $lastRecon  = (clone $journals)->where('transactions.reconciled', true)->max('transaction_journals.date');

        $opening    = $repository->getOpeningBalanceAmount($account, false);
        $obDate     = $repository->getOpeningBalanceDate($account);
        $isLiab     = in_array($type, config('firefly.valid_liabilities'), true);
        $iban       = (string) $account->iban;
        $number     = (string) $repository->getMetaValue($account, 'account_number');

        $data       = [
            'account_id'           => (string) $account->id,
            'name'                 => (string) $account->name,
            'type'                 => (string) config(sprintf('firefly.shortNamesByFullName.%s', $type)),
            'firefly_type'         => $type,
            'active'               => (bool) $account->active,
            'account_role'         => $repository->getMetaValue($account, 'account_role'),
            'currency_code'        => $currency->code,
            'journal_count'        => (int) ($stats->journal_count ?? 0),
            'uncleared_count'      => $uncleared,
            'first_transaction'    => null === ($stats->first_date ?? null) ? null : substr((string) $stats->first_date, 0, 10),
            'last_transaction'     => null === ($stats->last_date ?? null) ? null : substr((string) $stats->last_date, 0, 10),
            'last_reconciled_date' => null === $lastRecon ? null : substr((string) $lastRecon, 0, 10),
            'iban_last4'           => '' === $iban ? null : substr($iban, -4),
            'account_number_last4' => '' === $number ? null : substr($number, -4),
            'opening_balance'      => null === $opening || null === $obDate ? null : Money::forCurrency((string) $opening, $currency),
            'opening_balance_date' => null === $obDate ? null : substr((string) $obDate, 0, 10),
            'virtual_balance'      => null === $account->virtual_balance || '' === (string) $account->virtual_balance ? null : Money::forCurrency((string) $account->virtual_balance, $currency),
            'include_net_worth'    => '0' !== (string) ($repository->getMetaValue($account, 'include_net_worth') ?? '1'),
            'liability'            => $isLiab ? [
                'liability_type'      => strtolower($type),
                'liability_direction' => $repository->getMetaValue($account, 'liability_direction'),
                'interest'            => $repository->getMetaValue($account, 'interest'),
                'interest_period'     => $repository->getMetaValue($account, 'interest_period'),
                'current_debt'        => $repository->getMetaValue($account, 'current_debt'),
            ] : null,
        ];

        return $this->ok($data);
    }

    /** GET /accounts/{id}/transactions — the account's transaction groups in a period, newest first. */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, [
            'start' => ['sometimes', 'date_format:Y-m-d'],
            'end'   => ['sometimes', 'date_format:Y-m-d'],
            'type'  => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::TRANSACTION_TYPES))],
        ] + self::LIST_RULES, true);
        $params  = $this->listParams($request, ['date', 'id'], '-date');
        $account = $this->account($id);
        [$start, $end] = $this->range($args);

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->operator())->setUserGroup($this->administration()); // the bound books, not the operator's own rows only
        $collector->setAccounts(new Collection([$account]))->withAPIInformation()->setTypes(self::TRANSACTION_TYPES[$args['type'] ?? 'all']);
        if ($start instanceof Carbon) {
            $collector->setStart($start);
        }
        if ($end instanceof Carbon) {
            $collector->setEnd($end);
        }
        $groups  = [];
        foreach ($collector->getGroups() as $group) {
            $first            = is_array($group['transactions'] ?? null) ? reset($group['transactions']) : false;
            $group['date']    = is_array($first) && $first['date'] instanceof Carbon ? $first['date']->format('Y-m-d H:i:s') : null;
            $groups[]         = $group;
        }
        $page    = $this->applyList($groups, $params);
        $rows    = $this->renderGroups($page);

        return $this->ok(['transactions' => $rows], ['filters' => ['account_id' => (string) $account->id, 'start' => $start?->format('Y-m-d'), 'end' => $end?->format('Y-m-d'), 'type' => $args['type'] ?? 'all']]);
    }

    // ================================================================= writes ===

    /** POST /accounts — create one account through AccountFactory (dry run by default). */
    public function store(Request $request): JsonResponse
    {
        $args = $this->input($request, self::CREATE_RULES);
        $data = $this->createData($args, 'the account');

        return $this->write($request, $args, function (bool $dryRun) use ($data): WriteResult {
            $result = new WriteResult();
            $this->assertNameFree($data, null, 409);
            $account = $this->trackCreated($result, fn (): Account => $this->accounts()->store($data));
            $result->count('created');

            return $result->with(['account' => $this->renderAccounts(new Collection([$account->refresh()]), null, true)[0]]);
        });
    }

    /** POST /accounts/batch — many accounts, one transaction, one token; all or nothing (§12 uses this). */
    public function storeBatch(Request $request): JsonResponse
    {
        $rules = ['accounts' => ['required', 'array', 'min:1', 'max:500']];
        foreach (self::CREATE_RULES as $field => $rule) {
            $rules['accounts.*.'.$field] = $rule;
        }
        $raw   = $this->requestBody($request)['accounts'] ?? null;
        if (is_array($raw)) {
            foreach (array_values($raw) as $i => $row) {
                // a typo'd field inside a row is refused like one at the top level (§5.7)
                $unknown = is_array($row) ? array_values(array_diff(array_map('strval', array_keys($row)), array_keys(self::CREATE_RULES))) : [];
                if ([] !== $unknown) {
                    throw MachineException::invalid(sprintf('accounts[%d] has unknown field(s): %s.', $i, implode(', ', $unknown)), sprintf('Each row accepts: %s', implode(', ', array_keys(self::CREATE_RULES))), ['index' => $i, 'unknown' => $unknown]);
                }
            }
        }
        $args  = $this->input($request, $rules);
        $rows  = [];
        $seen  = [];
        foreach (array_values((array) $args['accounts']) as $i => $row) {
            $data     = $this->createData((array) $row, sprintf('accounts[%d]', $i));
            $key      = $data['__group'].'|'.mb_strtolower((string) $data['name']);
            if (array_key_exists($key, $seen)) {
                throw MachineException::invalid(sprintf('accounts[%d] repeats the name "%s" of accounts[%d].', $i, $data['name'], $seen[$key]), 'Firefly refuses two accounts of one type with the same name — give each row its own name', ['index' => $i, 'duplicate_of' => $seen[$key]]);
            }
            $seen[$key] = $i;
            $rows[]     = $data;
        }

        return $this->write($request, $args, function (bool $dryRun) use ($rows): WriteResult {
            $result  = new WriteResult();
            $created = [];
            foreach ($rows as $i => $data) {
                try {
                    $this->assertNameFree($data, null, 409);
                    $account = $this->trackCreated($result, fn (): Account => $this->accounts()->store($data));
                } catch (MachineException $e) {
                    throw new MachineException($e->errorCode, sprintf('accounts[%d]: %s', $i, $e->getMessage()), $e->hint, ['index' => $i] + $e->details, $e->status());
                }
                $result->count('created');
                $created[] = ['index' => $i] + $this->renderAccounts(new Collection([$account->refresh()]), null, true)[0];
            }

            return $result->with(['accounts' => $created]);
        });
    }

    /** PUT /accounts/{id} — edit through AccountUpdateService. */
    public function update(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, [
            'name'                 => ['sometimes', 'string', 'min:1', 'max:1024'],
            'account_role'         => ['sometimes', 'string', 'in:defaultAsset,sharedAsset,savingAsset,ccAsset,cashWalletAsset'],
            'iban'                 => ['sometimes', 'nullable', 'string', 'max:255'],
            'bic'                  => ['sometimes', 'nullable', 'string', 'max:255'],
            'account_number'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes'                => ['sometimes', 'nullable', 'string', 'max:32768'],
            'include_net_worth'    => ['sometimes', 'boolean'],
            'order'                => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'interest'             => ['sometimes', 'nullable', 'string', 'max:32'],
            'interest_period'      => ['sometimes', 'nullable', 'string', 'in:daily,weekly,monthly,quarterly,half-year,yearly'],
            'liability_direction'  => ['sometimes', 'string', 'in:credit,debit'],
            'credit_card_type'     => ['sometimes', 'nullable', 'string', 'in:monthlyFull'],
            'monthly_payment_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $account = $this->editable($this->routeAccount($request, $id));
        $fields  = array_diff_key($args, self::CONTROL_RULES);
        if ([] === $fields) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of: name, account_role, iban, bic, account_number, notes, include_net_worth, order, interest, interest_period, liability_direction, credit_card_type, monthly_payment_date');
        }
        $type    = (string) $account->accountType->type;
        if (array_key_exists('account_role', $fields) && AccountTypeEnum::ASSET->value !== $type) {
            throw MachineException::invalid('Only an asset account has an account_role.', 'Leave account_role out for this account', ['field' => 'account_role']);
        }
        if (!in_array($type, config('firefly.valid_liabilities'), true)) {
            foreach (['liability_direction', 'interest', 'interest_period'] as $field) {
                if (array_key_exists($field, $fields)) {
                    throw MachineException::invalid(sprintf('%s is only for a liability; "%s" is a %s account.', $field, $account->name, strtolower($type)), sprintf('Leave %s out for this account', $field), ['field' => $field]);
                }
            }
        }
        $role    = array_key_exists('account_role', $fields) ? $fields['account_role'] : $this->accounts()->getMetaValue($account, 'account_role');
        if ('ccAsset' !== $role) {
            foreach (['credit_card_type', 'monthly_payment_date'] as $field) {
                if (array_key_exists($field, $fields) && null !== $fields[$field]) {
                    throw MachineException::invalid(sprintf('%s is only for a credit card (account_role ccAsset).', $field), sprintf('Leave %s out, or also send "account_role": "ccAsset"', $field), ['field' => $field]);
                }
            }
        }
        $data    = [];
        foreach (['name', 'account_role', 'account_number', 'interest', 'interest_period', 'liability_direction', 'include_net_worth', 'order'] as $f) {
            if (array_key_exists($f, $fields)) {
                $data[$f] = is_string($fields[$f]) ? trim($fields[$f]) : $fields[$f];
            }
        }
        if (array_key_exists('interest', $fields) && null !== $fields['interest'] && !Money::isDecimal(trim((string) $fields['interest']))) {
            throw MachineException::invalid('interest must be a decimal string like "4.25".', 'Send "interest": "4.25" (a percentage)', ['field' => 'interest']);
        }
        if (array_key_exists('iban', $fields)) {
            $data['iban'] = $this->iban($fields['iban']);
        }
        if (array_key_exists('bic', $fields)) {
            $data['BIC'] = null === $fields['bic'] ? '' : trim((string) $fields['bic']);
        }
        if (array_key_exists('notes', $fields)) {
            $data['notes'] = (string) ($fields['notes'] ?? '');
        }
        if (array_key_exists('credit_card_type', $fields)) {
            $data['cc_type'] = (string) ($fields['credit_card_type'] ?? '');
        }
        if (array_key_exists('monthly_payment_date', $fields)) {
            $data['cc_monthly_payment_date'] = null === $fields['monthly_payment_date'] ? '' : $this->date((string) $fields['monthly_payment_date'], 'monthly_payment_date')->startOfDay();
        }
        if (array_key_exists('order', $data) && !$this->isOrderable($account)) {
            throw MachineException::invalid('Only asset and liability accounts have an order.', 'Leave order out for this account', ['field' => 'order']);
        }
        if (array_key_exists('name', $data)) {
            $probe = ['name' => $data['name'], '__types' => $this->sameGroupTypes($type)];
            $this->assertNameFree($probe, (int) $account->id, 409);
        }
        $accountId = (int) $account->id;

        return $this->write($request, $args, function (bool $dryRun) use ($accountId, $data): WriteResult {
            $account = $this->freshAccount($accountId);
            $result  = new WriteResult();
            $before  = $this->comparable($account);
            $this->recordAccountRows($result, $account, array_key_exists('order', $data));
            $this->accounts()->update($account, $data);
            $account = $this->freshAccount($accountId);
            $changed = $before !== $this->comparable($account);
            $result->count($changed ? 'updated' : 'unchanged');
            if (!$changed) {
                $result->touched = [];
            }

            return $result->with(['account' => $this->renderAccounts(new Collection([$account]), null, true)[0]]);
        });
    }

    /** POST /accounts/{id}/deactivate — Firefly's active: false (hidden in the UI, history intact). */
    public function deactivate(Request $request, string $id): JsonResponse
    {
        return $this->setActive($request, $id, false);
    }

    /** POST /accounts/{id}/activate */
    public function activate(Request $request, string $id): JsonResponse
    {
        return $this->setActive($request, $id, true);
    }

    /** POST /accounts/{id}/move — reorder (Firefly's AccountUpdateService::updateAccountOrder()). */
    public function move(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, ['order' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $account = $this->editable($this->routeAccount($request, $id));
        if (!$this->isOrderable($account)) {
            throw MachineException::invalid('Only asset and liability accounts have an order.', 'Reorder an asset or liability account', ['type' => $account->accountType->type]);
        }
        $accountId = (int) $account->id;
        $order     = (int) $args['order'];

        return $this->write($request, $args, function (bool $dryRun) use ($accountId, $order): WriteResult {
            $account = $this->freshAccount($accountId);
            $result  = new WriteResult();
            $this->recordAccountRows($result, $account, true, true);
            $this->accounts()->resetAccountOrder(); // what upstream's list and store do first: orders 1..n
            $account = $this->freshAccount($accountId);
            $from    = (int) $account->order;
            if ($from === $order) {
                $result->touched = [];

                return $result->count('unchanged')->with(['account' => $this->renderAccounts(new Collection([$account]), null, true)[0], 'order_before' => $from]);
            }
            $snapshot = $this->orderSnapshot($account);
            $this->accounts()->update($account, ['order' => $order]);
            $moved    = 0;
            foreach ($this->orderSnapshot($account) as $aid => $newOrder) {
                if (($snapshot[$aid] ?? null) !== $newOrder) {
                    ++$moved;
                }
            }
            $result->count('updated', max(1, $moved));

            return $result->with(['account' => $this->renderAccounts(new Collection([$this->freshAccount($accountId)]), null, true)[0], 'order_before' => $from]);
        });
    }

    /** DELETE /accounts/{id} — admin tier; move_transactions_to keeps the history on another account. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, ['move_transactions_to' => ['sometimes', 'nullable', 'string', 'max:1024']]);
        $gone    = $this->alreadyDeleted($id);
        if ($gone instanceof Account) {
            // §5.6: a DELETE of something already gone is ok with deleted: 0, so a retry loop ends
            return $this->ok([
                'account'      => null,
                'deleted'      => 0,
                'moved_to'     => null,
                'dry_run'      => (bool) ($args['dry_run'] ?? true),
                'changes'      => ['deleted' => 0],
                'change_count' => 0,
                'note'         => sprintf('Account #%d is already deleted — nothing to do.', $gone->id),
            ]);
        }
        $account = $this->editable($this->routeAccount($request, $id));
        $moveTo  = null;
        if (null !== ($args['move_transactions_to'] ?? null) && '' !== trim((string) $args['move_transactions_to'])) {
            $moveTo = $this->account(trim((string) $args['move_transactions_to']));
            if ((int) $moveTo->id === (int) $account->id) {
                throw MachineException::invalid('move_transactions_to is the account being deleted.', 'Name a different account of the same type', ['field' => 'move_transactions_to']);
            }
            if ($this->sameGroupTypes((string) $moveTo->accountType->type) !== $this->sameGroupTypes((string) $account->accountType->type)) {
                throw MachineException::invalid(
                    sprintf('Cannot move the history of a %s account to a %s account.', $account->accountType->type, $moveTo->accountType->type),
                    'Name an account of the same type (GET /machine/v1/accounts?type=…)',
                    ['field' => 'move_transactions_to'],
                );
            }
        }
        $accountId = (int) $account->id;
        $moveId    = $moveTo?->id;

        return $this->write($request, $args, function (bool $dryRun) use ($accountId, $moveId): WriteResult {
            $account  = $this->freshAccount($accountId);
            $moveTo   = null === $moveId ? null : $this->freshAccount((int) $moveId);
            $result   = new WriteResult();
            $journals = $this->journalIdsOf($account);
            $rendered = $this->renderAccounts(new Collection([$account]), null, true)[0];
            $this->recordDeletion($result, $account, $journals);
            $this->accounts()->destroy($account, $moveTo);
            $this->keepOnlyWhatWasDeleted($result);
            $result->count('deleted');
            if (null === $moveTo) {
                $result->count('journals_deleted', count($journals));
            }
            if (null !== $moveTo) {
                $gone = TransactionJournal::query()->whereIn('id', [] === $journals ? [0] : $journals)->count();
                $result->count('journals_moved', $gone);
                $result->count('journals_deleted', count($journals) - $gone);
            }

            return $result->with(['account' => $rendered, 'deleted' => 1, 'moved_to' => null === $moveTo ? null : ['id' => (string) $moveTo->id, 'name' => (string) $moveTo->name]]);
        });
    }

    // ========================================================== reconciliation ===

    /**
     * POST /accounts/{id}/reconcile/plan — read tier. The figures the UI's Reconcile screen shows,
     * the change set apply would make (produced by running apply's own code in a dry run), and a
     * confirm token for /reconcile/apply.
     */
    public function reconcilePlan(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, [
            'start'                 => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'                   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'target_balance'        => ['required'],
            'journal_ids'           => ['sometimes', 'nullable', 'array', 'max:5000'],
            'journal_ids.*'         => ['required'],
        ]);
        $account = $this->routeAccount($request, $id);
        $this->assertReconcilable($account);
        $currency = $this->accountCurrency($account);
        $target   = Money::normalize($args['target_balance'], (int) $currency->decimal_places, 'target_balance', $currency->code);
        $end      = null === ($args['end'] ?? null) ? Carbon::today(config('app.timezone')) : $this->date((string) $args['end'], 'end');
        $start    = null;
        if (null !== ($args['start'] ?? null)) {
            $start = $this->date((string) $args['start'], 'start');
        }
        if (null === $start) {
            $oldest = $this->accounts()->oldestJournalDate($account);
            $start  = null === $oldest ? $end->copy() : Carbon::parse($oldest->format('Y-m-d'), config('app.timezone'));
        }
        if ($end->lt($start)) {
            throw MachineException::invalid('end is before start.', 'Pass start ≤ end (YYYY-MM-DD)', ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')]);
        }
        $ids      = null;
        if (null !== ($args['journal_ids'] ?? null)) {
            $ids = [];
            foreach ((array) $args['journal_ids'] as $i => $jid) {
                if (!is_int($jid) && !(is_string($jid) && 1 === preg_match('/^\d{1,19}$/', $jid))) {
                    throw MachineException::invalid(sprintf('journal_ids[%d] is not a journal id.', $i), 'Pass journal ids as numbers or numeric strings (GET /machine/v1/accounts/{id}/transactions shows them)', ['index' => $i]);
                }
                $ids[] = (int) $jid;
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
        }

        $figures  = $this->reconcileFigures($account, $start, $end, $target, $ids, false);
        $accountId = (int) $account->id;
        $spec     = ['account_id' => $accountId, 'start' => $figures['start'], 'end' => $figures['end'], 'target_balance' => $target, 'journal_ids' => $figures['journal_ids']];
        $prints   = [];
        $previews = [];
        foreach ([true, false] as $create) {
            $key                         = $create ? 'create' : 'no_create';
            if (!$create && Money::isZero($figures['difference_raw'])) {
                $prints[$key]   = $prints['create'];
                $previews[$key] = $previews['create'];

                continue;
            }
            $held                        = $this->locked(fn () => DryRun::run(fn (): WriteResult => $this->reconcileWrite($spec, $create)));
            /** @var WriteResult $preview */
            $preview                     = $held->value;
            $prints[$key]                = $preview->fingerprint();
            $previews[$key]              = ['changes' => $preview->changes, 'change_count' => $preview->changeCount(), 'would_fire_webhooks' => $held->webhooks];
        }
        $payload  = ['reconcile' => $spec, 'fingerprints' => $prints];
        $token    = $this->planToken($request, self::APPLY_ROUTE, [], $prints['create'], ['change_count' => $previews['create']['change_count']]);
        Cache::put(sprintf(self::RECONCILE_KEY, $token['confirm_token']), $payload, (int) config('machine.confirm_ttl', 600));
        unset($figures['difference_raw']);

        return $this->ok($figures + [
            'would_create_reconciliation' => !Money::isZero($figures['difference']),
            'changes'                     => $previews['create']['changes'],
            'change_count'                => $previews['create']['change_count'],
            'changes_without_reconciliation' => $previews['no_create']['changes'],
            'would_fire_webhooks'         => $previews['create']['would_fire_webhooks'],
            'fingerprint'                 => $prints['create'],
            'confirm_token'               => $token['confirm_token'],
            'expires_at'                  => $token['expires_at'],
            'apply'                       => sprintf('POST /machine/v1/accounts/%d/reconcile/apply {"confirm_token": "…", "dry_run": false, "create_reconciliation": true}', $accountId),
        ]);
    }

    /**
     * POST /accounts/{id}/reconcile/apply — write tier. Needs the plan's confirm_token (also for a
     * dry run: the token carries the plan). Recomputes the change set; a moved ledger is refused
     * `conflict` with the new counts.
     */
    public function reconcileApply(Request $request, string $id): JsonResponse
    {
        $args    = $this->input($request, ['create_reconciliation' => ['sometimes', 'boolean']]);
        $create  = (bool) ($args['create_reconciliation'] ?? true);
        $dry     = (bool) ($args['dry_run'] ?? true);
        $token   = (string) ($args['confirm_token'] ?? '');
        if ('' === $token) {
            throw MachineException::forbidden(
                'reconcile/apply needs the confirm_token its plan returned.',
                'POST /machine/v1/accounts/{id}/reconcile/plan with the statement balance first, then send its confirm_token here',
            );
        }
        $account = $this->routeAccount($request, $id);
        $payload = Cache::get(sprintf(self::RECONCILE_KEY, $token));
        if (!is_array($payload) || !is_array($payload['reconcile'] ?? null)) {
            $entry = ConfirmTokens::peek($token);
            if (null !== $entry) {
                throw MachineException::forbidden('That confirm token is not from a reconciliation plan.', 'POST /machine/v1/accounts/{id}/reconcile/plan and use the token it returns');
            }

            throw MachineException::conflict('The reconciliation plan has expired, was already applied, or the token is unknown.', 'Re-plan: POST /machine/v1/accounts/{id}/reconcile/plan (plans last 10 minutes and apply once)');
        }
        $spec    = $payload['reconcile'];
        if ((int) $spec['account_id'] !== (int) $account->id) {
            throw MachineException::forbidden(sprintf('That plan was made for account #%d, not #%d.', $spec['account_id'], $account->id), 'Apply the plan on the account it was made for, or re-plan');
        }
        $needed  = (string) ($payload['fingerprints'][$create ? 'create' : 'no_create'] ?? '');
        $writeArgs = [];
        if (!$dry) {
            $entry = ConfirmTokens::peek($token);
            if (null !== $entry && '' !== $needed && !hash_equals((string) $entry['fingerprint'], $needed)) {
                // the plan's token carries the create_reconciliation=true change set; the caller
                // chose the other one. Redeem it (single use, bound to this route) and mint the
                // equivalent token for the chosen change set — the recheck below still runs.
                $params    = array_map(static fn ($v) => is_scalar($v) ? (string) $v : null, $request->route()?->parameters() ?? []);
                $argsHash  = ConfirmTokens::argsHash(['args' => [], 'params' => $params]);
                $redeemed  = ConfirmTokens::redeem($token, self::APPLY_ROUTE, $argsHash);
                $swap      = ConfirmTokens::mint(self::APPLY_ROUTE, $argsHash, $needed, $redeemed['payload']);
                $writeArgs = ['confirm_token' => $swap['confirm_token']];
            }
        }

        $response = $this->write($request, $writeArgs, fn (bool $dryRun): WriteResult => $this->reconcileWrite($spec, $create));

        if ($dry) {
            $minted = $response->getData(true)['data']['confirm_token'] ?? null;
            if (is_string($minted)) {
                Cache::put(sprintf(self::RECONCILE_KEY, $minted), $payload, (int) config('machine.confirm_ttl', 600));
            }
        }
        if (!$dry) {
            Cache::forget(sprintf(self::RECONCILE_KEY, $token));
        }

        return $response;
    }

    /** POST /accounts/{id}/unreconcile/{journal_id} — the UI's "unreconcile" on one journal. */
    public function unreconcile(Request $request, string $id, string $journalId): JsonResponse
    {
        $args    = $this->input($request, []);
        $account = $this->routeAccount($request, $id);
        if (1 !== preg_match('/^\d{1,19}$/', $journalId)) {
            throw MachineException::invalid('journal_id must be a number.', 'GET /machine/v1/accounts/{id}/transactions shows each split\'s transaction_journal_id', ['journal_id' => $journalId]);
        }
        $journal = $this->journalOn($account, (int) $journalId);
        $jid     = (int) $journal->id;

        return $this->write($request, $args, function (bool $dryRun) use ($jid): WriteResult {
            $result  = new WriteResult();
            $rows    = Transaction::query()->where('transaction_journal_id', $jid)->get();
            $already = $rows->every(static fn (Transaction $t): bool => !$t->reconciled);
            if ($already) {
                return $result->count('unchanged')->with(['journal_id' => (string) $jid, 'reconciled' => false]);
            }
            foreach ($rows as $row) {
                $result->updating($row);
            }

            $this->setReconciled([$jid], false);
            $result->count('unreconciled');

            return $result->with(['journal_id' => (string) $jid, 'reconciled' => false]);
        });
    }

    // ============================================================== internals ===

    /**
     * The reconciliation write — the SAME code the plan previews (in a dry run) and apply runs.
     * $spec is the frozen plan: account, dates, the operator's figure, the journal ids.
     *
     * @param array{account_id: int, start: string, end: string, target_balance: string, journal_ids: list<int>} $spec
     */
    private function reconcileWrite(array $spec, bool $create): WriteResult
    {
        $account = $this->freshAccount((int) $spec['account_id']);
        $start   = $this->date($spec['start'], 'start');
        $end     = $this->date($spec['end'], 'end');
        $figures = $this->reconcileFigures($account, $start, $end, (string) $spec['target_balance'], $spec['journal_ids'], true);
        $result  = new WriteResult();
        $result->basis = [
            'start_balance' => $figures['start_balance'], 'end_balance' => $figures['end_balance'], 'selected_sum' => $figures['selected_sum'],
            'cleared_sum'   => $figures['cleared_sum'], 'difference' => $figures['difference'], 'journal_ids' => $figures['journal_ids'],
        ];

        foreach (array_chunk($figures['journal_ids'], 500) as $chunk) {
            foreach (Transaction::query()->whereIn('transaction_journal_id', $chunk)->orderBy('id')->get() as $row) {
                $result->updating($row);
            }
            $this->setReconciled($chunk, true);
        }
        $result->count('reconciled', count($figures['journal_ids']));

        $groupId = null;
        if ($create && !Money::isZero($figures['difference_raw'])) {
            $group   = $this->trackCreated($result, fn (): TransactionGroup => $this->createReconciliation($account, $start, $end, $figures['difference_raw']));
            $groupId = (string) $group->id;
            $result->count('reconciliation_created');
        }
        unset($figures['difference_raw']);

        return $result->with($figures + [
            'create_reconciliation'      => $create,
            'reconciliation_group_id'    => $groupId,
        ]);
    }

    /**
     * The figures of Json\ReconcileController::overview(), for an asset account:
     *   start_balance  Firefly's balance before `start` (Account\ReconcileController::reconcile())
     *   end_balance    Firefly's balance at the end of `end`
     *   selected_sum   the signed effect of the selected (uncleared) journals
     *   cleared_sum    the signed effect of the journals in range already reconciled
     *   difference     start + cleared + selected − target: positive means Firefly holds more
     *                  than the statement (the reconciliation transaction takes it out).
     *
     * @param null|list<int> $ids the selected journals; null = every uncleared journal in range
     *
     * @return array<string, mixed>
     */
    private function reconcileFigures(Account $account, Carbon $start, Carbon $end, string $target, ?array $ids, bool $applying): array
    {
        $currency = $this->accountCurrency($account);
        $start    = $start->copy()->startOfDay();
        $end      = $end->copy()->endOfDay();
        $single   = new Collection([$account]);
        $startBal = (string) (Steam::accountsBalancesOptimized($single, $start, null, false, false)[$account->id]['balance'] ?? '0');
        $endBal   = (string) (Steam::accountsBalancesOptimized($single, $end, null, false)[$account->id]['balance'] ?? '0');

        /** @var GroupCollectorInterface $collector */
        $collector = app(GroupCollectorInterface::class);
        $collector->setUser($this->operator())->setUserGroup($this->administration()); // the bound books, not the operator's own rows only
        $collector->setAccounts($single)->setRange($start, $end);
        $inRange   = $collector->getExtractedJournals();

        $selectedSum = '0';
        $clearedSum  = '0';
        $uncleared   = [];
        $cleared     = 0;
        foreach ($inRange as $journal) {
            $jid = (int) $journal['transaction_journal_id'];
            if (true === $journal['reconciled']) {
                $clearedSum = Money::add($clearedSum, $this->journalEffect($account, $currency, $journal));
                ++$cleared;

                continue;
            }
            $uncleared[$jid] = $journal;
        }
        $selected = null === $ids ? array_keys($uncleared) : $ids;
        sort($selected);
        $problems = [];
        foreach ($selected as $jid) {
            if (!array_key_exists($jid, $uncleared)) {
                $problems[] = $jid;

                continue;
            }
            $selectedSum = Money::add($selectedSum, $this->journalEffect($account, $currency, $uncleared[$jid]));
        }
        if ([] !== $problems) {
            $message = sprintf('%d selected journal(s) are not uncleared journals of this account between %s and %s.', count($problems), $start->format('Y-m-d'), $end->format('Y-m-d'));
            if ($applying) {
                throw MachineException::conflict($message.' The ledger changed since the plan.', 'Re-plan: POST /machine/v1/accounts/{id}/reconcile/plan', ['journal_ids' => $problems]);
            }

            throw MachineException::invalid($message, 'Pick journal ids from GET /machine/v1/accounts/{id}/transactions in that range that are not reconciled yet — or leave journal_ids out to select every uncleared row', ['journal_ids' => $problems]);
        }
        $difference = Money::sub(Money::add(Money::add($startBal, $clearedSum), $selectedSum), $target);
        $places     = (int) $currency->decimal_places;

        return [
            'account_id'      => (string) $account->id,
            'account_name'    => (string) $account->name,
            'currency_code'   => $currency->code,
            'start'           => $start->format('Y-m-d'),
            'end'             => $end->format('Y-m-d'),
            'start_balance'   => Money::format($startBal, $places),
            'end_balance'     => Money::format($endBal, $places),
            'selected_sum'    => Money::format($selectedSum, $places),
            'cleared_sum'     => Money::format($clearedSum, $places),
            'target_balance'  => Money::format($target, $places),
            'difference'      => Money::format($difference, $places),
            'difference_raw'  => $difference,
            'journal_ids'     => array_values($selected),
            'selected_count'  => count($selected),
            'uncleared_count' => count($uncleared),
            'cleared_count'   => $cleared,
            'signed'          => ['start_balance', 'end_balance', 'selected_sum', 'cleared_sum', 'target_balance', 'difference'],
        ];
    }

    /**
     * Json\ReconcileController::processJournal(): the signed effect of one journal on $account in
     * the account's currency (the collector's amount is the source side, negative).
     *
     * @param array<string, mixed> $journal
     */
    private function journalEffect(Account $account, TransactionCurrency $currency, array $journal): string
    {
        $toAdd = '0';
        if ((int) $account->id === (int) $journal['source_account_id']) {
            if ((int) $currency->id === (int) $journal['currency_id']) {
                $toAdd = (string) $journal['amount'];
            }
            if (null !== $journal['foreign_currency_id'] && (int) $journal['foreign_currency_id'] === (int) $currency->id) {
                $toAdd = (string) $journal['foreign_amount'];
            }
        }
        if ((int) $account->id === (int) $journal['destination_account_id']) {
            if ((int) $currency->id === (int) $journal['currency_id']) {
                $toAdd = Money::negate((string) $journal['amount']);
            }
            if (null !== $journal['foreign_currency_id'] && (int) $journal['foreign_currency_id'] === (int) $currency->id) {
                $toAdd = Money::negate((string) $journal['foreign_amount']);
            }
        }

        return $toAdd;
    }

    /**
     * Account\ReconcileController::createReconciliation(): Firefly's own reconciliation
     * transaction — one visible row of type `reconciliation` between the account and its
     * reconciliation account, dated `end`, for the absolute difference.
     */
    private function createReconciliation(Account $account, Carbon $start, Carbon $end, string $difference): TransactionGroup
    {
        $user           = $this->operator();
        $repository     = $this->accounts();
        $reconciliation = $repository->getReconciliation($account);
        $currency       = $this->accountCurrency($account);
        $source         = $reconciliation;
        $destination    = $account;
        if (1 === Money::compare($difference, '0')) {
            $source      = $account;
            $destination = $reconciliation;
        }
        $format         = (string) trans('config.month_and_day_js');
        $description    = (string) trans('firefly.reconciliation_transaction_title', [
            'from' => $start->isoFormat($format),
            'to'   => $end->isoFormat($format),
        ]);
        $submission     = [
            'user'         => $user,
            'user_group'   => $this->administration(),
            'group_title'  => null,
            'transactions' => [[
                'user'                => $user,
                'user_group'          => $this->administration(),
                'type'                => strtolower(TransactionTypeEnum::RECONCILIATION->value),
                'date'                => $end->copy()->startOfDay(),
                'order'               => 0,
                'currency_id'         => $currency->id,
                'foreign_currency_id' => null,
                'amount'              => Money::abs($difference),
                'foreign_amount'      => null,
                'description'         => $description,
                'source_id'           => $source->id,
                'destination_id'      => $destination->id,
                'reconciled'          => true,
            ]],
        ];

        /** @var TransactionGroupFactory $factory */
        $factory        = app(TransactionGroupFactory::class);
        $factory->setUser($user);

        return $factory->create($submission);
    }

    private function assertReconcilable(Account $account): void
    {
        if (AccountTypeEnum::ASSET->value !== $account->accountType->type) {
            throw MachineException::invalid(
                sprintf('Only an asset account can be reconciled; "%s" is a %s.', $account->name, $account->accountType->type),
                'Reconcile the asset account the statement is for (GET /machine/v1/accounts?type=asset)',
                ['account_id' => (string) $account->id],
            );
        }
        if (!$account->active) {
            throw MachineException::invalid(sprintf('"%s" is inactive.', $account->name), 'POST /machine/v1/accounts/{id}/activate first, or reconcile an active account', ['account_id' => (string) $account->id]);
        }
    }

    /** A journal that touches $account, or not_found. */
    private function journalOn(Account $account, int $journalId): TransactionJournal
    {
        /** @var null|TransactionJournal $journal */
        $journal = TransactionJournal::query()
            ->where('transaction_journals.id', $journalId)
            ->where('transaction_journals.user_group_id', $this->administration()->id)
            ->whereHas('transactions', static function ($q) use ($account): void {
                $q->where('account_id', $account->id);
            })
            ->first()
        ;
        if (null === $journal) {
            throw MachineException::notFound(sprintf('No journal #%d on account "%s".', $journalId, $account->name), 'GET /machine/v1/accounts/{id}/transactions shows each split\'s transaction_journal_id', ['journal_id' => (string) $journalId]);
        }

        return $journal;
    }

    private function setActive(Request $request, string $id, bool $active): JsonResponse
    {
        $args      = $this->input($request, []);
        $accountId = (int) $this->editable($this->routeAccount($request, $id))->id;

        return $this->write($request, $args, function (bool $dryRun) use ($accountId, $active): WriteResult {
            $account = $this->freshAccount($accountId);
            $result  = new WriteResult();
            if ((bool) $account->active === $active) {
                return $result->count('unchanged')->with(['account' => $this->renderAccounts(new Collection([$account]), null, true)[0]]);
            }
            $result->updating($account);
            $this->accounts()->update($account, ['active' => $active]);
            $result->count('updated');

            return $result->with(['account' => $this->renderAccounts(new Collection([$this->freshAccount($accountId)]), null, true)[0]]);
        });
    }

    /**
     * The account-store array AccountRepository::store() takes (the shape upstream's
     * Account\StoreRequest::getAllAccountData() builds), validated and with amounts normalised
     * at the account currency's places — never rounded.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function createData(array $row, string $label): array
    {
        $type   = (string) $row['type'];
        $liab   = in_array($type, ['liability', 'loan', 'debt', 'mortgage'], true);
        $ltype  = $liab ? ('liability' === $type ? (string) ($row['liability_type'] ?? '') : $type) : null;
        if ($liab && '' === (string) $ltype) {
            throw MachineException::invalid(sprintf('%s: a liability needs liability_type (loan, debt or mortgage).', $label), 'Pass "liability_type": "loan" — or use "type": "loan" / "debt" / "mortgage" directly', ['field' => 'liability_type']);
        }
        if ($liab && null === ($row['liability_direction'] ?? null)) {
            throw MachineException::invalid(sprintf('%s: a liability needs liability_direction.', $label), 'Pass "liability_direction": "credit" (you owe it) or "debit" (it owes you)', ['field' => 'liability_direction']);
        }
        if ('asset' === $type && null === ($row['account_role'] ?? null)) {
            throw MachineException::invalid(sprintf('%s: an asset account needs account_role.', $label), 'Pass account_role: defaultAsset (checking), savingAsset, sharedAsset, ccAsset (credit card) or cashWalletAsset', ['field' => 'account_role']);
        }
        if ('asset' !== $type && null !== ($row['account_role'] ?? null)) {
            throw MachineException::invalid(sprintf('%s: only an asset account has an account_role.', $label), 'Leave account_role out for this type', ['field' => 'account_role']);
        }
        if (!$liab) {
            // Firefly would store these as meta on a non-liability and show them nowhere
            foreach (['liability_type', 'liability_direction', 'interest', 'interest_period'] as $field) {
                if (null !== ($row[$field] ?? null)) {
                    throw MachineException::invalid(sprintf('%s: %s is only for a liability (loan, debt, mortgage).', $label, $field), sprintf('Leave %s out, or use "type": "liability"', $field), ['field' => $field]);
                }
            }
        }
        if (in_array($type, ['expense', 'revenue'], true) && (null !== ($row['opening_balance'] ?? null) || null !== ($row['opening_balance_date'] ?? null))) {
            // Firefly's factory silently drops an opening balance on these types (can_have_opening_balance)
            throw MachineException::invalid(sprintf('%s: an %s account has no opening balance.', $label, $type), 'Leave opening_balance and opening_balance_date out — only asset and liability accounts have one', ['field' => 'opening_balance']);
        }
        if ('ccAsset' !== ($row['account_role'] ?? null)) {
            foreach (['credit_card_type', 'monthly_payment_date'] as $field) {
                if (null !== ($row[$field] ?? null)) {
                    throw MachineException::invalid(sprintf('%s: %s is only for a credit card (account_role ccAsset).', $label, $field), sprintf('Leave %s out, or pass "account_role": "ccAsset"', $field), ['field' => $field]);
                }
            }
        }
        if ('ccAsset' === ($row['account_role'] ?? null) && null === ($row['monthly_payment_date'] ?? null)) {
            throw MachineException::invalid(sprintf('%s: a credit card (ccAsset) needs monthly_payment_date.', $label), 'Pass "monthly_payment_date": "YYYY-MM-DD" (the day of the month the card is paid) and "credit_card_type": "monthlyFull"', ['field' => 'monthly_payment_date']);
        }
        $currency = $this->primaryCurrency();
        if (null !== ($row['currency_code'] ?? null)) {
            $code = strtoupper(trim((string) $row['currency_code']));

            /** @var null|TransactionCurrency $currency */
            $currency = TransactionCurrency::query()->where('code', $code)->first();
            if (null === $currency) {
                throw MachineException::notFound(sprintf('%s: no currency with code "%s".', $label, $code), 'GET /machine/v1/currencies lists the codes', ['field' => 'currency_code', 'currency_code' => $code]);
            }
        }
        $places  = (int) $currency->decimal_places;
        $opening = null;
        $obDate  = null;
        if (null !== ($row['opening_balance'] ?? null)) {
            $opening = Money::normalize($row['opening_balance'], $places, 'opening_balance', $currency->code);
            if (null === ($row['opening_balance_date'] ?? null)) {
                throw MachineException::invalid(sprintf('%s: opening_balance needs opening_balance_date.', $label), 'Pass "opening_balance_date": "YYYY-MM-DD" (the day the balance was true)', ['field' => 'opening_balance_date']);
            }
            $obDate  = $this->date((string) $row['opening_balance_date'], 'opening_balance_date')->startOfDay();
        }
        if (null === $opening && null !== ($row['opening_balance_date'] ?? null)) {
            throw MachineException::invalid(sprintf('%s: opening_balance_date needs opening_balance.', $label), 'Pass "opening_balance" as a decimal string, or leave both out', ['field' => 'opening_balance']);
        }
        if ($liab && null !== $opening && -1 === Money::compare($opening, '0')) {
            throw MachineException::invalid(sprintf('%s: a liability\'s opening_balance is the positive amount owed.', $label), sprintf('Send "opening_balance": "%s" — liability_direction carries the direction', ltrim($opening, '-')), ['field' => 'opening_balance']);
        }
        $virtual = null;
        if (null !== ($row['virtual_balance'] ?? null)) {
            $virtual = Money::normalize($row['virtual_balance'], $places, 'virtual_balance', $currency->code);
        }
        if (null !== ($row['interest'] ?? null) && !Money::isDecimal(trim((string) $row['interest']))) {
            throw MachineException::invalid(sprintf('%s: interest must be a decimal string like "4.25".', $label), 'Send "interest": "4.25" (a percentage)', ['field' => 'interest']);
        }
        $fireflyType = $liab ? (string) $ltype : $type;

        return [
            'name'                    => trim((string) $row['name']),
            'active'                  => (bool) ($row['active'] ?? true),
            'include_net_worth'       => (bool) ($row['include_net_worth'] ?? true),
            'account_type_name'       => $fireflyType,
            'account_type_id'         => null,
            'currency_id'             => (int) $currency->id,
            'currency_code'           => $currency->code,
            'virtual_balance'         => $virtual,
            'iban'                    => $this->iban($row['iban'] ?? null),
            'BIC'                     => null === ($row['bic'] ?? null) ? null : trim((string) $row['bic']),
            'account_number'          => null === ($row['account_number'] ?? null) ? null : trim((string) $row['account_number']),
            'account_role'            => $row['account_role'] ?? null,
            'opening_balance'         => $opening,
            'opening_balance_date'    => $obDate,
            'cc_type'                 => $row['credit_card_type'] ?? ('ccAsset' === ($row['account_role'] ?? null) ? 'monthlyFull' : null),
            'cc_monthly_payment_date' => null === ($row['monthly_payment_date'] ?? null) ? null : (string) $row['monthly_payment_date'],
            'notes'                   => (string) ($row['notes'] ?? ''),
            'interest'                => null === ($row['interest'] ?? null) ? null : trim((string) $row['interest']),
            'interest_period'         => $row['interest_period'] ?? null,
            'liability_direction'     => $liab ? (string) $row['liability_direction'] : null,
            '__types'                 => $this->typesFor($liab ? 'liability' : $type),
            '__group'                 => $liab ? 'liability' : $type,
        ];
    }

    /**
     * Firefly refuses two accounts of one type with the same name, and its factory silently
     * RETURNS the existing one — so the plane checks first: a collision is a refusal (§12.2).
     *
     * @param array<string, mixed> $data
     */
    private function assertNameFree(array &$data, ?int $except, int $status): void
    {
        $types = (array) ($data['__types'] ?? []);
        unset($data['__types'], $data['__group']);
        $query = Account::query()
            ->where('user_group_id', $this->administration()->id)
            ->whereIn('account_type_id', $this->typeIds($types))
            ->whereRaw('LOWER(name) = ?', [mb_strtolower((string) $data['name'])])
        ;
        if (null !== $except) {
            $query->where('id', '!=', $except);
        }

        /** @var null|Account $existing */
        $existing = $query->first();
        if (null !== $existing) {
            throw MachineException::conflict(
                sprintf('An account named "%s" already exists (#%d).', $existing->name, $existing->id),
                sprintf('GET /machine/v1/accounts/%d — use it, or choose another name', $existing->id),
                ['existing' => ['id' => (string) $existing->id, 'name' => (string) $existing->name]],
            );
        }
    }

    /** A validated, space-free IBAN (Firefly's own `iban` rule), or null. */
    private function iban(mixed $value): ?string
    {
        if (null === $value || '' === trim((string) $value)) {
            return null;
        }
        $iban      = strtoupper((string) preg_replace('/\s+/', '', (string) $value));
        $validator = \Illuminate\Support\Facades\Validator::make(['iban' => $iban], ['iban' => ['iban']]);
        if ($validator->fails()) {
            throw MachineException::invalid('iban is not a valid IBAN.', 'Check the IBAN (country code, check digits, length) or leave it out', ['field' => 'iban']);
        }

        return $iban;
    }

    /**
     * Run $fn and record every row it inserted (undo deletes them, children first).
     *
     * @template T
     *
     * @param Closure(): T $fn
     *
     * @return T
     */
    private function trackCreated(WriteResult $result, Closure $fn): mixed
    {
        $classes = [Account::class, AccountMeta::class, Location::class, TransactionGroup::class, TransactionJournal::class, TransactionJournalMeta::class, Transaction::class, Note::class];
        $before  = [];
        foreach ($classes as $class) {
            $table          = (new $class())->getTable();
            $before[$class] = (int) (DB::table($table)->max('id') ?? 0);
        }
        $value   = $fn();
        foreach ($classes as $class) {
            /** @var Model $model */
            $model = new $class();
            foreach (DB::table($model->getTable())->where('id', '>', $before[$class])->orderBy('id')->pluck('id') as $newId) {
                $row = new $class();
                $row->setAttribute($row->getKeyName(), $newId);
                $row->exists = true;
                $result->created($row);
            }
        }

        return $value;
    }

    /** Before-images of an account, its meta, its notes — and, when order may shift, of its orderable siblings. */
    private function recordAccountRows(WriteResult $result, Account $account, bool $siblings, bool $afterReset = false): void
    {
        if ($siblings) {
            // resetAccountOrder() renumbers default+asset and loan/debt/credit-card/mortgage accounts,
            // a wider set than updateAccountOrder() shifts — record every row it can rewrite
            $query = $afterReset
                ? Account::query()->where('user_id', $account->user_id)->whereIn('account_type_id', $this->typeIds([
                    AccountTypeEnum::DEFAULT->value, AccountTypeEnum::ASSET->value,
                    AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value, AccountTypeEnum::CREDITCARD->value, AccountTypeEnum::MORTGAGE->value,
                ]))
                : $this->orderSiblingsQuery($account);
            $ids   = $query->where('id', '!=', $account->id)->orderBy('id')->get();
            foreach ($ids as $sibling) {
                $result->updating($sibling);
            }
        }
        foreach (AccountMeta::query()->where('account_id', $account->id)->orderBy('id')->get() as $meta) {
            $result->updating($meta);
        }
        foreach ($account->notes()->orderBy('id')->get() as $note) {
            $result->updating($note);
        }
        $result->updating($account);
    }

    /**
     * Before-images of everything AccountDestroyService (and the DeletedAccountObserver) removes
     * or rewrites: the account's journals, their groups and transactions, the opening-balance
     * counter-account, the meta rows, the notes, and the account itself — so /undo can restore it.
     *
     * @param list<int> $journalIds
     */
    private function recordDeletion(WriteResult $result, Account $account, array $journalIds): void
    {
        $ids      = [] === $journalIds ? [0] : $journalIds;
        $journals = TransactionJournal::query()->whereIn('id', $ids)->orderBy('id')->get();
        $groupIds = $journals->pluck('transaction_group_id')->unique()->filter()->values()->all();
        foreach (TransactionGroup::query()->whereIn('id', [] === $groupIds ? [0] : $groupIds)->orderBy('id')->get() as $group) {
            $result->deleting($group);
        }
        foreach ($journals as $journal) {
            $result->deleting($journal);
        }
        $counterAccounts = [];
        foreach (Transaction::query()->whereIn('transaction_journal_id', $ids)->orderBy('id')->get() as $tx) {
            $result->updating($tx);
            if ((int) $tx->account_id !== (int) $account->id) {
                $counterAccounts[(int) $tx->account_id] = true;
            }
        }
        $initialType = AccountType::query()->where('type', AccountTypeEnum::INITIAL_BALANCE->value)->value('id');
        foreach (Account::query()->whereIn('id', array_keys($counterAccounts) ?: [0])->where('account_type_id', $initialType)->orderBy('id')->get() as $ib) {
            foreach (AccountMeta::query()->where('account_id', $ib->id)->orderBy('id')->get() as $meta) {
                $result->deleting($meta);
            }
            $result->deleting($ib);
        }
        foreach (AccountMeta::query()->where('account_id', $account->id)->orderBy('id')->get() as $meta) {
            $result->deleting($meta);
        }
        foreach ($account->notes()->orderBy('id')->get() as $note) {
            $result->deleting($note);
        }
        $result->deleting($account);
    }

    /**
     * Firefly's destroy does not always remove every row recorded beforehand (a group whose
     * journal JournalDestroyService already removed is left alone): drop the "deleted" entries of
     * rows that are still live, so undo restores exactly what went.
     */
    private function keepOnlyWhatWasDeleted(WriteResult $result): void
    {
        $kept = [];
        foreach ($result->touched as $t) {
            if ('deleted' === $t['op']) {
                /** @var Model $model */
                $model = new $t['class']();
                $row   = DB::table($model->getTable())->where($model->getKeyName(), $t['id'])->first();
                $soft  = in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true);
                if (null !== $row && (!$soft || null === ($row->deleted_at ?? null))) {
                    continue;
                }
            }
            $kept[] = $t;
        }
        $result->touched = $kept;
    }

    /** @return list<int> the ids of every live journal that touches $account */
    private function journalIdsOf(Account $account): array
    {
        return array_values(array_map('intval', Transaction::query()
            ->where('account_id', $account->id)
            ->distinct()
            ->orderBy('transaction_journal_id')
            ->pluck('transaction_journal_id')
            ->all()));
    }

    /** @return array<int, int> account id → order, for the accounts that share $account's ordering */
    private function orderSnapshot(Account $account): array
    {
        $out = [];
        foreach ($this->orderSiblingsQuery($account)->orderBy('id')->get(['id', 'order']) as $row) {
            $out[(int) $row->id] = (int) $row->order;
        }

        return $out;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Account> */
    private function orderSiblingsQuery(Account $account): \Illuminate\Database\Eloquent\Builder
    {
        $types = AccountTypeEnum::ASSET->value === $account->accountType->type
            ? [AccountTypeEnum::ASSET->value]
            : [AccountTypeEnum::MORTGAGE->value, AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value];

        return Account::query()->where('user_id', $account->user_id)->whereIn('account_type_id', $this->typeIds($types));
    }

    private function isOrderable(Account $account): bool
    {
        return in_array($account->accountType->type, [AccountTypeEnum::ASSET->value, AccountTypeEnum::MORTGAGE->value, AccountTypeEnum::LOAN->value, AccountTypeEnum::DEBT->value], true);
    }

    /** @return array<string, mixed> the account's editable facts, to tell "updated" from "unchanged" */
    private function comparable(Account $account): array
    {
        $meta  = AccountMeta::query()->where('account_id', $account->id)->orderBy('name')->pluck('data', 'name')->all();
        $notes = $account->notes()->pluck('text')->all();

        return ['name' => $account->name, 'iban' => $account->iban, 'active' => (bool) $account->active, 'order' => (int) $account->order, 'meta' => $meta, 'notes' => $notes];
    }

    /** Refuse Firefly's internal accounts (initial balance, reconciliation, cash…) for edits. */
    private function editable(Account $account): Account
    {
        if (!in_array($account->accountType->type, self::EDITABLE, true)) {
            throw MachineException::invalid(
                sprintf('"%s" is one of Firefly\'s internal %s accounts and cannot be changed here.', $account->name, strtolower((string) $account->accountType->type)),
                'Edit an asset, liability, expense or revenue account',
                ['account_id' => (string) $account->id, 'type' => $account->accountType->type],
            );
        }

        return $account;
    }

    /** The account types that share a name space (and a move target) with $type. @return list<string> */
    private function sameGroupTypes(string $type): array
    {
        foreach (self::TYPES as $types) {
            if (in_array($type, $types, true)) {
                return $types;
            }
        }

        return [$type];
    }

    private function account(string $idOrName): Account
    {
        /** @var Account $account */
        $account = $this->resolve(Account::class, rawurldecode($idOrName));

        return $account;
    }

    /**
     * The route's {id} account, with the route parameter pinned to its numeric id: a confirm token
     * is bound to the route parameters, so a plan made by name ("Checking") must confirm by id
     * and vice versa — both name the same account.
     */
    private function routeAccount(Request $request, string $id): Account
    {
        $account = $this->account($id);
        $request->route()?->setParameter('id', (string) $account->id);

        return $account;
    }

    /**
     * JournalRepository::reconcileById() / unreconcileById() — the one-column update the UI's
     * submit() and "unreconcile" make on every transaction of the journal — scoped to the BOUND
     * administration. The repository scopes by $user->transactionJournals(), which in a shared
     * administration misses the rows another member entered: it would report them reconciled and
     * change nothing.
     *
     * @param list<int> $journalIds
     */
    private function setReconciled(array $journalIds, bool $reconciled): void
    {
        $ids = TransactionJournal::query()
            ->where('user_group_id', $this->administration()->id)
            ->whereIn('id', [] === $journalIds ? [0] : $journalIds)
            ->pluck('id')
            ->all()
        ;
        if ([] === $ids) {
            return;
        }
        Transaction::query()->whereIn('transaction_journal_id', $ids)->update(['reconciled' => $reconciled]);
    }

    /** A numeric {id} naming an account of the bound administration that is already soft-deleted. */
    private function alreadyDeleted(string $id): ?Account
    {
        $value = trim($id);
        if (1 !== preg_match('/^\d{1,19}$/', $value)) {
            return null;
        }

        /** @var null|Account $account */
        $account = Account::onlyTrashed()->where('user_group_id', $this->administration()->id)->find((int) $value);

        return $account;
    }

    private function freshAccount(int $id): Account
    {
        /** @var null|Account $account */
        $account = Account::query()->where('user_group_id', $this->administration()->id)->find($id);
        if (null === $account) {
            throw MachineException::conflict(sprintf('Account #%d is gone.', $id), 'GET /machine/v1/accounts — it was deleted since the plan');
        }

        return $account;
    }

    private function accounts(): AccountRepositoryInterface
    {
        /** @var AccountRepositoryInterface $repository */
        $repository = app(AccountRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }

    private function accountCurrency(Account $account): TransactionCurrency
    {
        return $this->accounts()->getAccountCurrency($account) ?? $this->primaryCurrency();
    }

    /** @return list<string> Firefly account-type names for the public `type` filter */
    private function typesFor(string $type): array
    {
        if ('all' === $type) {
            return array_values(array_merge(...array_values(self::TYPES)));
        }

        return self::TYPES[$type] ?? [];
    }

    /** @param list<string> $types @return list<int> */
    private function typeIds(array $types): array
    {
        $ids = AccountType::query()->whereIn('type', [] === $types ? ['-'] : $types)->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return [] === $ids ? [0] : $ids;
    }

    /**
     * Firefly's AccountEnrichment + AccountTransformer (the /api/v1 shapes), flattened.
     *
     * @param Collection<int, Account> $accounts
     *
     * @return list<array<string, mixed>>
     */
    private function renderAccounts(Collection $accounts, ?Carbon $asOf, bool $balances): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }
        $enrichment  = new AccountEnrichment();
        $enrichment->setUser($this->operator());
        $enrichment->setUserGroup($this->administration());
        $enrichment->setDate(null === $asOf ? null : $asOf->copy());
        $enriched    = $enrichment->enrich($accounts);

        /** @var AccountTransformer $transformer */
        $transformer = app(AccountTransformer::class);
        $rows        = [];
        foreach ($enriched as $account) {
            $row = $transformer->transform($account);
            unset($row['links']);
            if (!$balances) {
                foreach (['current_balance', 'pc_current_balance', 'balance_difference', 'pc_balance_difference', 'current_balance_date'] as $k) {
                    unset($row[$k]);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * TransactionGroupEnrichment + TransactionGroupTransformer (the /api/v1 shapes), flattened.
     *
     * @param array<int, array<string, mixed>>|Collection<int, mixed> $groups
     *
     * @return list<array<string, mixed>>
     */
    private function renderGroups(array|Collection $groups): array
    {
        $groups = $groups instanceof Collection ? $groups : new Collection($groups);
        if ($groups->isEmpty()) {
            return [];
        }
        $enrichment  = new TransactionGroupEnrichment();
        $enrichment->setUser($this->operator());
        $groups      = $enrichment->enrich($groups->map(static function (array $g): array {
            unset($g['date']);

            return $g;
        }));

        /** @var TransactionGroupTransformer $transformer */
        $transformer = app(TransactionGroupTransformer::class);
        $rows        = [];
        foreach ($groups as $group) {
            $row = $transformer->transform($group);
            unset($row['links']);
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $args @return array{0: null|Carbon, 1: null|Carbon} */
    private function range(array $args): array
    {
        $start = isset($args['start']) ? $this->date((string) $args['start'], 'start')->startOfDay() : null;
        $end   = isset($args['end']) ? $this->date((string) $args['end'], 'end')->endOfDay() : null;
        if (null !== $start && null !== $end && $end->lt($start)) {
            throw MachineException::invalid('end is before start.', 'Pass start ≤ end (YYYY-MM-DD)', ['start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d')]);
        }

        return [$start, $end];
    }

    private function date(string $value, string $field): Carbon
    {
        $date = Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'));
        if (!$date instanceof Carbon || $date->format('Y-m-d') !== $value) {
            throw MachineException::invalid(sprintf('%s is not a date.', $field), sprintf('Pass %s as YYYY-MM-DD', $field), ['field' => $field]);
        }

        return $date;
    }
}
