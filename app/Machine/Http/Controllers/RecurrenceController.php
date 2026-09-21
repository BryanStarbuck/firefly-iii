<?php

/*
 * RecurrenceController.php
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
use FireflyIII\Api\V1\Requests\Models\Recurrence\StoreRequest as RecurrenceStoreRequest;
use FireflyIII\Api\V1\Requests\Models\Recurrence\UpdateRequest as RecurrenceUpdateRequest;
use FireflyIII\Enums\AccountTypeEnum;
use FireflyIII\Factory\AccountFactory;
use FireflyIII\Factory\CategoryFactory;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Jobs\CreateRecurringTransactions;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\Subscriptions\Pivots\AccountPiggyBankRow;
use FireflyIII\Machine\Transactions\Pivots\BudgetJournalRow;
use FireflyIII\Machine\Transactions\Pivots\CategoryJournalRow;
use FireflyIII\Machine\Transactions\Pivots\TagJournalRow;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\AccountMeta;
use FireflyIII\Models\Category;
use FireflyIII\Models\Note;
use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Models\Recurrence;
use FireflyIII\Models\RecurrenceMeta;
use FireflyIII\Models\RecurrenceRepetition;
use FireflyIII\Models\RecurrenceTransaction;
use FireflyIII\Models\RecurrenceTransactionMeta;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Repositories\Account\AccountRepositoryInterface;
use FireflyIII\Repositories\Bill\BillRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\PiggyBank\PiggyBankRepositoryInterface;
use FireflyIII\Repositories\Recurring\RecurringRepositoryInterface;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\Support\JsonApi\Enrichments\RecurringEnrichment;
use FireflyIII\Transformers\RecurrenceTransformer;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * pm/apis.mdx §8.6 — recurring transactions.
 *
 * Reads: RecurringRepository + RecurringEnrichment + RecurrenceTransformer (upstream's
 * /api/v1/recurrences), with the next N dates from RecurringRepository::getXOccurrencesSince().
 * Create/edit: `recurrence` is Firefly's own recurrence shape, validated by upstream's
 * Recurrence\StoreRequest / UpdateRequest and stored by RecurringRepository::store() / update().
 * Trigger: exactly upstream's Recurrence\TriggerController::trigger() — the CreateRecurringTransactions
 * job for one recurrence and one date, then markGroupsAsNow() and the latest date restored. It has
 * NO dry run (§7.2); its preview is GET /recurrences/{id} (the next dates). It refuses up front,
 * with the reason, the cases where upstream's job would silently create nothing.
 */
final class RecurrenceController extends MachineController
{
    /** The top-level fields of Firefly's recurrence shape (/api/v1/recurrences). */
    private const array SHAPE = ['type', 'title', 'description', 'first_date', 'repeat_until', 'nr_of_repetitions', 'apply_rules', 'active', 'notes', 'repetitions', 'transactions'];

    /** The fields of one repetition and one split (upstream's Recurrence\StoreRequest / UpdateRequest rules + GetRecurrenceData). */
    private const array REPETITION_KEYS = ['id', 'type', 'moment', 'skip', 'weekend'];
    private const array SPLIT_KEYS      = [
        'id', 'description', 'amount', 'currency_id', 'currency_code', 'foreign_amount', 'foreign_currency_id', 'foreign_currency_code',
        'source_id', 'source_name', 'destination_id', 'destination_name', 'budget_id', 'budget_name', 'category_id', 'category_name',
        'bill_id', 'bill_name', 'piggy_bank_id', 'piggy_bank_name', 'tags',
    ];

    // ------------------------------------------------------------------ reads ---

    /** GET /recurrences — each with its next occurrences. */
    public function index(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['active' => ['sometimes', 'boolean'], 'type' => ['sometimes', 'string', 'in:withdrawal,deposit,transfer']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['id', 'title', 'type', 'first_date', 'next_date', 'active'], 'title');
        // the administration's recurrences (§4.9) — RecurringRepository::get() would return the operator's own only
        $all    = Recurrence::query()
            ->where('user_group_id', (int) $this->administration()->id)
            ->with(['transactionCurrency', 'transactionType', 'recurrenceRepetitions', 'recurrenceTransactions'])
            ->orderBy('active', 'DESC')
            ->orderBy('transaction_type_id')
            ->orderBy('title')
            ->get();
        $rows   = $this->present($all, 5);
        if (array_key_exists('active', $args)) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['active'] === (bool) $args['active']));
        }
        if (array_key_exists('type', $args)) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['type'] === $args['type']));
        }

        return $this->ok(['recurrences' => $this->applyList($rows, $params)]);
    }

    /** GET /recurrences/{id} — with its next `count` dates (default 5). */
    public function show(Request $request, string $id): JsonResponse
    {
        $args       = $this->input($request, ['count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100']], true);
        $count      = (int) ($args['count'] ?? 5);
        $recurrence = $this->findRecurrence($id);

        return $this->ok(['recurrence' => $this->present(new Collection([$recurrence]), $count)[0]]);
    }

    /** GET /recurrences/{id}/transactions — what the recurrence created, newest first. */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $args       = $this->input($request, ['start' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'end' => ['sometimes', 'nullable', 'date_format:Y-m-d']] + self::LIST_RULES, true);
        $params     = $this->listParams($request, ['date', 'id'], '-date');
        [$start, $end] = SubscriptionController::optionalRange($args);
        $recurrence = $this->findRecurrence($id);
        $journalIds = $this->createdJournalIds($recurrence);
        $groups     = [];
        if ([] !== $journalIds) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector->setUser($this->operator())->setUserGroup($this->administration())->setJournalIds($journalIds)->withAPIInformation(); // the bound books
            if (null !== $start && null !== $end) {
                $collector->setRange($start, $end);
            }
            $groups = SubscriptionController::presentGroups($collector->getGroups());
        }

        return $this->ok([
            'recurrence'   => ['id' => (int) $recurrence->id, 'title' => (string) $recurrence->title],
            'range'        => null === $start ? null : ['start' => $start->format('Y-m-d'), 'end' => $end?->format('Y-m-d')],
            'transactions' => $this->applyList($groups, $params),
        ]);
    }

    // ----------------------------------------------------------------- writes ---

    /** POST /recurrences — `recurrence` in Firefly's shape (type, title, first_date, repetitions[], transactions[], …). */
    public function store(Request $request): JsonResponse
    {
        $args  = $this->input($request, ['recurrence' => ['required', 'array']]);
        $shape = $this->shape($args['recurrence']);

        return $this->write($request, $args, function (bool $dryRun) use ($shape): WriteResult {
            $specs      = $this->specs(null);
            $before     = SubscriptionController::snapshot($specs);
            $shape      = $this->resolveAccounts($shape, (string) ($shape['type'] ?? ''), true);
            $form       = SubscriptionController::upstreamForm(RecurrenceStoreRequest::class, $shape);
            $recurrence = $this->repository()->store($form->getAll());
            $result     = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));

            return $result->count('created')->with(['recurrence' => $this->present(new Collection([$recurrence->refresh()]), 5)[0]]);
        });
    }

    /** PUT /recurrences/{id} — `recurrence` holds only the fields to change (Firefly's update semantics). */
    public function update(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, ['recurrence' => ['present', 'array']]);
        $shape = $this->shape((array) $args['recurrence']);
        if ([] === $shape) {
            throw MachineException::invalid('Nothing to change.', 'Pass recurrence: {…} with at least one of: '.implode(', ', self::SHAPE));
        }
        $recurrenceId = (int) $this->findRecurrence($id)->id;

        return $this->write($request, $args, function (bool $dryRun) use ($shape, $recurrenceId): WriteResult {
            /** @var Recurrence $recurrence */
            $recurrence = Recurrence::query()->findOrFail($recurrenceId);
            $specs      = $this->specs($recurrence);
            $before     = SubscriptionController::snapshot($specs);
            $shape      = $this->resolveAccounts($shape, strtolower((string) $recurrence->transactionType->type), false);
            if (!array_key_exists('transactions', $shape)) {
                // upstream's update insists on the transactions; "leave them as they are" is each one by id alone
                $shape['transactions'] = $recurrence->recurrenceTransactions()->orderBy('id')->pluck('id')->map(static fn ($v): array => ['id' => (string) $v])->all();
            }
            $form       = SubscriptionController::upstreamForm(RecurrenceUpdateRequest::class, $shape, ['recurrence' => $recurrence]);
            $updated    = $this->repository()->update($recurrence, $form->getAll());
            $result     = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));
            $result->count([] === $result->touched ? 'unchanged' : 'updated');

            return $result->with(['recurrence' => $this->present(new Collection([$updated->refresh()]), 5)[0]]);
        });
    }

    /**
     * POST /recurrences/{id}/trigger — create the occurrence for `date` (default: the next one) now.
     * No dry run: the route is declared dryRun:false, so write() applies directly (under the lock,
     * in one transaction, logged for undo). Like upstream, the created transactions are dated today.
     */
    public function trigger(Request $request, string $id): JsonResponse
    {
        // no dry_run / confirm_token here (§7.2); idempotency_key is accepted like on every creating POST (§5.6)
        $args       = $this->input($request, ['date' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'idempotency_key' => self::CONTROL_RULES['idempotency_key']]);
        $recurrence = $this->findRecurrence($id);
        $date       = null === ($args['date'] ?? null)
            ? $this->nextUncreatedDate($recurrence)
            : Carbon::createFromFormat('!Y-m-d', (string) $args['date'], (string) config('app.timezone'));
        if (null === $date) {
            throw MachineException::invalid(
                sprintf('Recurring transaction "%s" has no upcoming occurrence.', $recurrence->title),
                'Pass date: YYYY-MM-DD for the occurrence to create, or check repeat_until / nr_of_repetitions with GET /machine/v1/recurrences/'.$recurrence->id,
            );
        }
        $recurrenceId = (int) $recurrence->id;

        return $this->write($request, $args, function (bool $dryRun) use ($recurrenceId, $date): WriteResult {
            /** @var Recurrence $recurrence */
            $recurrence = Recurrence::query()->findOrFail($recurrenceId);
            // checked under the write lock (and after an idempotent replay has had its chance), so a
            // repeat with the same idempotency_key returns the original instead of "already created"
            $this->assertTriggerable($recurrence, $date);
            $specs      = $this->createdSpecs($recurrenceId);
            $before     = SubscriptionController::snapshot($specs);

            // upstream's TriggerController::trigger(), step for step
            $backupDate = $recurrence->latest_date;
            $job        = app(CreateRecurringTransactions::class);
            $job->setRecurrences(new Collection([$recurrence]));
            $job->setDate(clone $date);
            $job->setForce(false);
            $job->handle();
            $groups     = $job->getGroups();
            $repository = $this->repository();
            $repository->markGroupsAsNow($groups);
            $repository->setLatestDate($recurrence, $backupDate);
            Preferences::mark();

            if (0 === $groups->count()) {
                throw MachineException::conflict(
                    sprintf('Firefly III created nothing for "%s" on %s.', $recurrence->title, $date->format('Y-m-d')),
                    'GET /machine/v1/recurrences/'.$recurrenceId.' shows the next dates; the occurrence may already exist (GET /machine/v1/recurrences/'.$recurrenceId.'/transactions)',
                    ['recurrence_id' => $recurrenceId, 'date' => $date->format('Y-m-d')],
                );
            }
            $result     = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));
            $result->count('created', $groups->count());

            /** @var GroupCollectorInterface $collector */
            $collector  = app(GroupCollectorInterface::class);
            $collector->setUser($this->operator())->setUserGroup($this->administration())->setIds($groups->pluck('id')->map(static fn ($v): int => (int) $v)->all())->withAPIInformation();

            return $result->with([
                'recurrence'      => ['id' => $recurrenceId, 'title' => (string) $recurrence->title],
                'occurrence_date' => $date->format('Y-m-d'),
                'transactions'    => SubscriptionController::presentGroups($collector->getGroups()),
            ]);
        });
    }

    /** DELETE /recurrences/{id} — admin tier. Transactions it already created stay. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args       = $this->input($request, []);
        $recurrence = $this->findRecurrenceOrGone($id);
        if (null === $recurrence) {
            return SubscriptionController::alreadyGone($args, ['recurrence' => null], sprintf('Recurring transaction #%s is already deleted — nothing to do.', trim(urldecode($id))));
        }
        $recurrenceId = (int) $recurrence->id;

        return $this->write($request, $args, function (bool $dryRun) use ($recurrenceId): WriteResult {
            /** @var Recurrence $recurrence */
            $recurrence = Recurrence::query()->findOrFail($recurrenceId);
            $summary    = ['id' => $recurrenceId, 'title' => (string) $recurrence->title];
            $specs      = $this->specs($recurrence);
            $before     = SubscriptionController::snapshot($specs);
            $this->repository()->destroy($recurrence);
            $result     = new WriteResult();
            SubscriptionController::diffInto($result, $before, SubscriptionController::snapshot($specs));

            return $result->count('deleted')->with(['recurrence' => $summary, 'deleted' => 1]);
        });
    }

    // ------------------------------------------------------------- internals ---

    /**
     * Upstream's RecurringTransactionTrait::createTransactions() reads source_id / destination_id
     * and ignores the names its own validator accepts. Resolve *_name to *_id here the way
     * findAccount() would (an existing account of an expected type; an expense or revenue account
     * is created, as upstream does), and refuse — rather than crash — a split that names neither.
     * Called inside the write, so a created counterparty account is part of the preview and undo.
     *
     * @param array<string, mixed> $shape
     *
     * @return array<string, mixed>
     */
    private function resolveAccounts(array $shape, string $type, bool $creating): array
    {
        if (!array_key_exists('transactions', $shape)) {
            return $shape;
        }
        $journalType = ucfirst(strtolower($type));
        $user        = $this->operator();
        /** @var AccountRepositoryInterface $accounts */
        $accounts    = app(AccountRepositoryInterface::class);
        $accounts->setUser($user);
        foreach ($shape['transactions'] as $i => $split) {
            if (!is_array($split)) {
                continue;
            }
            foreach (['source', 'destination'] as $side) {
                $idKey   = $side.'_id';
                $nameKey = $side.'_name';
                if (null !== ($split[$idKey] ?? null) && '' !== (string) $split[$idKey]) {
                    continue;
                }
                $name  = trim((string) ($split[$nameKey] ?? ''));
                if ('' === $name) {
                    if ($creating || array_key_exists($nameKey, $split)) {
                        throw MachineException::invalid(
                            sprintf('recurrence.transactions.%d names no %s account.', $i, $side),
                            sprintf('Pass %s (an account id, GET /machine/v1/accounts) or %s', $idKey, $nameKey),
                            ['field' => sprintf('recurrence.transactions.%d.%s', $i, $idKey)],
                        );
                    }

                    continue;
                }
                $types = (array) config(sprintf('firefly.expected_source_types.%s.%s', $side, $journalType), []);
                if ([] === $types) {
                    throw MachineException::invalid('recurrence.type must be withdrawal, deposit or transfer.', 'Pass type: "withdrawal" | "deposit" | "transfer"', ['field' => 'recurrence.type']);
                }
                $found = $accounts->findByName($name, $types);
                if (null === $found) {
                    $creatable = array_values(array_intersect($types, [AccountTypeEnum::EXPENSE->value, AccountTypeEnum::REVENUE->value]));
                    if ([] === $creatable) {
                        throw MachineException::notFound(
                            sprintf('No %s account named "%s" for a %s.', $side, $name, strtolower($journalType)),
                            'GET /machine/v1/accounts lists them — pass '.$idKey.' instead',
                            ['field' => sprintf('recurrence.transactions.%d.%s', $i, $nameKey), 'name' => $name],
                        );
                    }
                    /** @var AccountFactory $factory */
                    $factory = app(AccountFactory::class);
                    $factory->setUser($user);
                    $found   = $factory->findOrCreate($name, $creatable[0]);
                }
                $shape['transactions'][$i][$idKey] = (string) $found->id;
            }
            $shape['transactions'][$i] = $this->resolveLinks($shape['transactions'][$i], $i);
        }

        return $shape;
    }

    /**
     * The same upstream gap for the split's links: category_name, budget_name, bill_name and
     * piggy_bank_name pass validation and are then dropped (GetRecurrenceData keeps only the ids).
     * Resolve each name to its id: a category is created by name as a transaction's would be; a
     * budget, subscription or piggy bank must exist. An id already given wins.
     *
     * @param array<string, mixed> $split
     *
     * @return array<string, mixed>
     */
    private function resolveLinks(array $split, int $i): array
    {
        $user = $this->operator();
        foreach (['category', 'budget', 'bill', 'piggy_bank'] as $kind) {
            $idKey   = $kind.'_id';
            $nameKey = $kind.'_name';
            if (!array_key_exists($nameKey, $split)) {
                continue;
            }
            $name = trim((string) ($split[$nameKey] ?? ''));
            unset($split[$nameKey]);
            if ('' === $name || (null !== ($split[$idKey] ?? null) && '' !== (string) $split[$idKey])) {
                continue;
            }
            $id = null;
            switch ($kind) {
                case 'category':
                    /** @var CategoryFactory $factory */
                    $factory = app(CategoryFactory::class);
                    $factory->setUser($user);
                    $id      = $factory->findOrCreate(null, $name)?->id;

                    break;

                case 'budget':
                    /** @var BudgetRepositoryInterface $budgets */
                    $budgets = app(BudgetRepositoryInterface::class);
                    $budgets->setUser($user);
                    $id      = $budgets->findByName($name)?->id;

                    break;

                case 'bill':
                    /** @var BillRepositoryInterface $bills */
                    $bills = app(BillRepositoryInterface::class);
                    $bills->setUser($user);
                    $id    = $bills->findByName($name)?->id;

                    break;

                case 'piggy_bank':
                    /** @var PiggyBankRepositoryInterface $piggies */
                    $piggies = app(PiggyBankRepositoryInterface::class);
                    $piggies->setUser($user);
                    $id      = $piggies->findByName($name)?->id;

                    break;
            }
            if (null === $id) {
                $list = ['category' => 'categories', 'budget' => 'budgets', 'bill' => 'subscriptions', 'piggy_bank' => 'piggy-banks'][$kind];

                throw MachineException::notFound(
                    sprintf('No %s named "%s".', 'bill' === $kind ? 'subscription' : str_replace('_', ' ', $kind), $name),
                    sprintf('GET /machine/v1/%s lists them — pass %s instead', $list, $idKey),
                    ['field' => sprintf('recurrence.transactions.%d.%s', $i, $nameKey), 'name' => $name],
                );
            }
            $split[$idKey] = (string) $id;
        }

        return $split;
    }

    /**
     * Refuse, with the reason, what upstream's job would silently skip.
     */
    private function assertTriggerable(Recurrence $recurrence, Carbon $date): void
    {
        $hint = 'GET /machine/v1/recurrences/'.$recurrence->id.' shows its next dates';
        if (!$recurrence->active) {
            throw MachineException::invalid(sprintf('Recurring transaction "%s" is inactive.', $recurrence->title), 'Activate it first: PUT /machine/v1/recurrences/'.$recurrence->id.' with recurrence: {"active": true}');
        }
        if ($recurrence->first_date->gt($date)) {
            throw MachineException::invalid(sprintf('%s is before the first date of "%s" (%s).', $date->format('Y-m-d'), $recurrence->title, $recurrence->first_date->format('Y-m-d')), $hint, ['field' => 'date']);
        }
        if (null !== $recurrence->repeat_until && $recurrence->repeat_until->lt($date)) {
            throw MachineException::invalid(sprintf('%s is after "%s" stops repeating (%s).', $date->format('Y-m-d'), $recurrence->title, $recurrence->repeat_until->format('Y-m-d')), $hint, ['field' => 'date']);
        }
        $repository = $this->repository();
        if (0 !== (int) $recurrence->repetitions && $repository->getJournalCount($recurrence) >= (int) $recurrence->repetitions) {
            throw MachineException::invalid(sprintf('"%s" has already run its %d times.', $recurrence->title, $recurrence->repetitions), 'Raise nr_of_repetitions with PUT /machine/v1/recurrences/'.$recurrence->id.' first');
        }
        if (null !== $recurrence->latest_date && $recurrence->latest_date->isSameDay($date)) {
            throw MachineException::conflict(sprintf('"%s" already fired for %s.', $recurrence->title, $date->format('Y-m-d')), 'GET /machine/v1/recurrences/'.$recurrence->id.'/transactions shows what it created', ['date' => $date->format('Y-m-d')]);
        }
        $matches = false;
        foreach ($recurrence->recurrenceRepetitions as $repetition) {
            $window = (clone $date)->addDays(2);
            foreach ($repository->getOccurrencesInRange($repetition, $recurrence->first_date, $window) as $occurrence) {
                if ($occurrence->isSameDay($date)) {
                    $matches = true;

                    break 2;
                }
            }
        }
        if (!$matches) {
            $next = array_map(static fn (Carbon $d): string => $d->format('Y-m-d'), $this->nextDates($recurrence, 3));

            throw MachineException::invalid(
                sprintf('%s is not an occurrence of "%s".', $date->format('Y-m-d'), $recurrence->title),
                [] === $next ? $hint : sprintf('Its next dates are %s — pass one of them as date', implode(', ', $next)),
                ['field' => 'date', 'next_dates' => $next],
            );
        }
        if ($repository->getJournalCount($recurrence, $date, $date) > 0 || $repository->createdPreviously($recurrence, $date)) {
            throw MachineException::conflict(sprintf('"%s" already created a transaction for %s.', $recurrence->title, $date->format('Y-m-d')), 'GET /machine/v1/recurrences/'.$recurrence->id.'/transactions shows it', ['date' => $date->format('Y-m-d')]);
        }
    }

    /**
     * Validate Firefly's recurrence shape at the top level (nested fields are upstream's rules).
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private function shape(array $raw): array
    {
        $unknown = array_values(array_diff(array_map('strval', array_keys($raw)), self::SHAPE));
        if ([] !== $unknown) {
            throw MachineException::invalid(
                sprintf('Unknown recurrence field%s: %s.', 1 === count($unknown) ? '' : 's', implode(', ', $unknown)),
                'recurrence accepts: '.implode(', ', self::SHAPE),
                ['unknown' => $unknown, 'accepted' => self::SHAPE],
            );
        }
        foreach (['repetitions' => self::REPETITION_KEYS, 'transactions' => self::SPLIT_KEYS] as $list => $keys) {
            if (!array_key_exists($list, $raw)) {
                continue;
            }
            if (!is_array($raw[$list]) || !array_is_list($raw[$list])) {
                throw MachineException::invalid(sprintf('recurrence.%s must be a list.', $list), sprintf('Send "%s": [{…}, …]', $list), ['field' => 'recurrence.'.$list]);
            }
            // §5.7 one level down: upstream validates the keys it knows and silently drops the rest,
            // so a typo'd "categroy_name" would become "no category" without a word
            foreach ($raw[$list] as $i => $entry) {
                if (!is_array($entry)) {
                    throw MachineException::invalid(sprintf('recurrence.%s.%d must be an object.', $list, $i), sprintf('Send "%s": [{…}, …]', $list), ['field' => sprintf('recurrence.%s.%d', $list, $i)]);
                }
                $unknown = array_values(array_diff(array_map('strval', array_keys($entry)), $keys));
                if ([] !== $unknown) {
                    throw MachineException::invalid(
                        sprintf('Unknown field%s in recurrence.%s.%d: %s.', 1 === count($unknown) ? '' : 's', $list, $i, implode(', ', $unknown)),
                        sprintf('Each %s accepts: %s', 'transactions' === $list ? 'split' : 'repetition', implode(', ', $keys)),
                        ['field' => sprintf('recurrence.%s.%d', $list, $i), 'unknown' => $unknown, 'accepted' => $keys],
                    );
                }
            }
        }
        foreach ((array) ($raw['transactions'] ?? []) as $i => $t) {
            foreach (['amount', 'foreign_amount'] as $field) {
                if (is_array($t) && array_key_exists($field, $t) && null !== $t[$field] && (is_int($t[$field]) || is_float($t[$field]))) {
                    throw MachineException::invalid(sprintf('recurrence.transactions.%d.%s must be a decimal string, not a JSON number.', $i, $field), sprintf('Send "%s": "12.34"', $field), ['field' => sprintf('recurrence.transactions.%d.%s', $i, $field)]);
                }
            }
        }

        return $raw;
    }

    /**
     * The journals this recurrence created, in the bound administration (upstream's getJournalIds()
     * is scoped to the operator's own rows; a cron run creates them as the recurrence's owner).
     *
     * @return list<int>
     */
    private function createdJournalIds(Recurrence $recurrence): array
    {
        return DB::table('journal_meta')
            ->join('transaction_journals', 'transaction_journals.id', '=', 'journal_meta.transaction_journal_id')
            ->where('transaction_journals.user_group_id', (int) $this->administration()->id)
            ->whereNull('transaction_journals.deleted_at')
            ->where('journal_meta.name', 'recurrence_id')
            ->where('journal_meta.data', json_encode((string) $recurrence->id))
            ->pluck('journal_meta.transaction_journal_id')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * The occurrence dates this recurrence has already created (the recurrence_date journal meta —
     * what upstream's createdPreviously() reads, one query instead of one per journal).
     *
     * @return array<string, true> "YYYY-MM-DD" => true
     */
    private function createdDates(Recurrence $recurrence): array
    {
        $rows = DB::table('journal_meta as d')
            ->join('journal_meta as r', 'r.transaction_journal_id', '=', 'd.transaction_journal_id')
            ->where('r.name', 'recurrence_id')
            ->where('r.data', json_encode((string) $recurrence->id))
            ->where('d.name', 'recurrence_date')
            ->pluck('d.data');
        $out  = [];
        foreach ($rows as $raw) {
            $value = json_decode((string) $raw, true);
            if (is_string($value) && strlen($value) >= 10) {
                $out[substr($value, 0, 10)] = true;
            }
        }

        return $out;
    }

    /**
     * For a bare trigger: the first upcoming occurrence that has not been created yet — a trigger
     * does not move latest_date (upstream restores it), so "the next date" alone would name the
     * occurrence just created, and every bare trigger after the first would be a conflict.
     */
    private function nextUncreatedDate(Recurrence $recurrence): ?Carbon
    {
        $next = $this->nextDates($recurrence, 12);
        if ([] === $next) {
            return null;
        }
        $created = $this->createdDates($recurrence);
        foreach ($next as $date) {
            if (!array_key_exists($date->format('Y-m-d'), $created)) {
                return $date;
            }
        }

        return $next[0]; // every upcoming date exists already: assertTriggerable() refuses it, naming the date
    }

    /**
     * The next $count dates across all repetitions (upstream's getXOccurrencesSince), after today.
     *
     * @return list<Carbon>
     */
    private function nextDates(Recurrence $recurrence, int $count): array
    {
        $repository = $this->repository();
        $dates      = [];
        foreach ($recurrence->recurrenceRepetitions as $repetition) {
            $from = clone ($recurrence->latest_date ?? $recurrence->first_date);
            foreach ($repository->getXOccurrencesSince($repetition, $from, now(config('app.timezone')), $count) as $d) {
                $dates[$d->format('Y-m-d')] = $d;
            }
        }
        ksort($dates);

        return array_slice(array_values($dates), 0, $count);
    }

    /**
     * @param Collection<int, Recurrence> $recurrences
     *
     * @return list<array<string, mixed>>
     */
    private function present(Collection $recurrences, int $count): array
    {
        if (0 === $recurrences->count()) {
            return [];
        }
        $enrichment  = new RecurringEnrichment();
        $enrichment->setUser($this->operator());
        $enriched    = $enrichment->enrich($recurrences);
        /** @var RecurrenceTransformer $transformer */
        $transformer = app(RecurrenceTransformer::class);
        $rows        = [];

        /** @var Recurrence $recurrence */
        foreach ($enriched as $recurrence) {
            $t       = $transformer->transform($recurrence);
            $next    = array_map(static fn (Carbon $d): string => $d->format('Y-m-d'), $this->nextDates($recurrence, $count));
            $created = $this->createdDates($recurrence);
            $done    = array_values(array_filter($next, static fn (string $d): bool => array_key_exists($d, $created)));
            $pending = array_values(array_filter($next, static fn (string $d): bool => !array_key_exists($d, $created)));
            $reps   = [];
            foreach ((array) $t['repetitions'] as $rep) {
                $reps[] = [
                    'id'          => (int) $rep['id'],
                    'type'        => (string) $rep['type'],
                    'moment'      => (string) $rep['moment'],
                    'skip'        => (int) $rep['skip'],
                    'weekend'     => (int) $rep['weekend'],
                    'description' => (string) $rep['description'],
                ];
            }
            $splits = [];
            foreach ((array) $t['transactions'] as $tr) {
                $places   = (int) ($tr['currency_decimal_places'] ?? 2);
                $splits[] = [
                    'id'                    => (int) $tr['id'],
                    'description'           => (string) ($tr['description'] ?? ''),
                    'amount'                => Money::format(Money::abs((string) $tr['amount']), $places),
                    'currency_code'         => $tr['currency_code'] ?? null,
                    // upstream stores "0" for "no foreign amount"; without a foreign currency there is none (§14.2)
                    'foreign_amount'        => null === ($tr['foreign_amount'] ?? null) || null === ($tr['foreign_currency_code'] ?? null) ? null : Money::format(Money::abs((string) $tr['foreign_amount']), (int) ($tr['foreign_currency_decimal_places'] ?? 2)),
                    'foreign_currency_code' => $tr['foreign_currency_code'] ?? null,
                    'source_id'             => isset($tr['source_id']) ? (int) $tr['source_id'] : null,
                    'source_name'           => $tr['source_name'] ?? null,
                    'destination_id'        => isset($tr['destination_id']) ? (int) $tr['destination_id'] : null,
                    'destination_name'      => $tr['destination_name'] ?? null,
                    'category_name'         => $tr['category_name'] ?? null,
                    'budget_name'           => $tr['budget_name'] ?? null,
                    'subscription_id'       => isset($tr['subscription_id']) && null !== $tr['subscription_id'] ? (int) $tr['subscription_id'] : null,
                    'piggy_bank_id'         => isset($tr['piggy_bank_id']) && null !== $tr['piggy_bank_id'] ? (int) $tr['piggy_bank_id'] : null,
                    'tags'                  => array_values((array) ($tr['tags'] ?? [])),
                ];
            }
            $rows[] = [
                'id'                  => (int) $recurrence->id,
                'title'               => (string) $recurrence->title,
                'type'                => (string) $t['type'],
                'description'         => $t['description'],
                'active'              => (bool) $recurrence->active,
                'apply_rules'         => (bool) $recurrence->apply_rules,
                'first_date'          => $recurrence->first_date?->format('Y-m-d'),
                'latest_date'         => $recurrence->latest_date?->format('Y-m-d'),
                'repeat_until'        => $recurrence->repeat_until?->format('Y-m-d'),
                'nr_of_repetitions'   => $t['nr_of_repetitions'],
                'next_date'           => $next[0] ?? null,
                'next_dates'          => $next,
                // of next_dates: the occurrences already created (a bare trigger skips them) and the first that is not
                'created_dates'       => $done,
                'next_uncreated_date' => $pending[0] ?? null,
                'repetitions'         => $reps,
                'transactions'        => $splits,
                'notes'               => $t['notes'] ?? null,
                'updated_at'          => $recurrence->updated_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * The rows a recurrence create/edit/delete can change.
     *
     * @return list<array{0: string, 1: string, 2: Closure(QueryBuilder): void}>
     */
    private function specs(?Recurrence $recurrence): array
    {
        $groupId = (int) $this->administration()->id;
        $ids     = null === $recurrence
            ? static fn (QueryBuilder $q) => $q->from('recurrences')->where('user_group_id', $groupId)->select('id')
            : null;
        $by      = static function (QueryBuilder $q, string $column) use ($recurrence, $ids): void {
            null === $recurrence ? $q->whereIn($column, $ids) : $q->where($column, $recurrence->id);
        };

        $maxAccount  = (int) DB::table('accounts')->max('id');
        $maxMeta     = (int) DB::table('account_meta')->max('id');
        $maxCategory = (int) DB::table('categories')->max('id');

        return [
            // a counterparty account or a category resolveAccounts() creates (parents first: undo deletes it last)
            [Account::class, 'accounts', static function (QueryBuilder $q) use ($maxAccount): void { $q->where('id', '>', $maxAccount); }],
            [AccountMeta::class, 'account_meta', static function (QueryBuilder $q) use ($maxMeta): void { $q->where('id', '>', $maxMeta); }],
            [Category::class, 'categories', static function (QueryBuilder $q) use ($maxCategory): void { $q->where('id', '>', $maxCategory); }],
            [Recurrence::class, 'recurrences', static function (QueryBuilder $q) use ($by): void { $by($q, 'id'); }],
            [RecurrenceMeta::class, 'recurrences_meta', static function (QueryBuilder $q) use ($by): void { $by($q, 'recurrence_id'); }],
            [RecurrenceRepetition::class, 'recurrences_repetitions', static function (QueryBuilder $q) use ($by): void { $by($q, 'recurrence_id'); }],
            [RecurrenceTransaction::class, 'recurrences_transactions', static function (QueryBuilder $q) use ($by): void { $by($q, 'recurrence_id'); }],
            [RecurrenceTransactionMeta::class, 'rt_meta', static function (QueryBuilder $q) use ($by): void {
                $q->whereIn('rt_id', static function (QueryBuilder $sub) use ($by): void {
                    $sub->from('recurrences_transactions')->select('id');
                    $by($sub, 'recurrence_id');
                });
            }],
            [Note::class, 'notes', static function (QueryBuilder $q) use ($by): void {
                $q->where('noteable_type', Recurrence::class);
                $by($q, 'noteable_id');
            }],
        ];
    }

    /**
     * For trigger: the recurrence row, every row the created transaction brings with it from now
     * on (ids above today's maximum: group, journal, transactions, meta, the category / budget /
     * tag links, notes, a piggy-bank event) and — a recurrence can point at a piggy bank — the
     * saved amounts, which its event moves. Parents first, so undo deletes children first.
     *
     * @return list<array{0: string, 1: string, 2: Closure(QueryBuilder): void}>
     */
    private function createdSpecs(int $recurrenceId): array
    {
        $specs   = [
            [Recurrence::class, 'recurrences', static function (QueryBuilder $q) use ($recurrenceId): void { $q->where('id', $recurrenceId); }],
            [AccountPiggyBankRow::class, 'account_piggy_bank', static function (QueryBuilder $q): void {}],
        ];
        $created = [
            TransactionGroup::class       => 'transaction_groups',
            TransactionJournal::class     => 'transaction_journals',
            Transaction::class            => 'transactions',
            TransactionJournalMeta::class => 'journal_meta',
            CategoryJournalRow::class     => 'category_transaction_journal',
            BudgetJournalRow::class       => 'budget_transaction_journal',
            TagJournalRow::class          => 'tag_transaction_journal',
            Note::class                   => 'notes',
            PiggyBankEvent::class         => 'piggy_bank_events',
        ];
        foreach ($created as $class => $table) {
            $max     = (int) DB::table($table)->max('id');
            $specs[] = [$class, $table, static function (QueryBuilder $q) use ($max): void { $q->where('id', '>', $max); }];
        }

        return $specs;
    }

    private function repository(): RecurringRepositoryInterface
    {
        /** @var RecurringRepositoryInterface $repository */
        $repository = app(RecurringRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }

    private function findRecurrence(string $id): Recurrence
    {
        /** @var Recurrence */
        return $this->resolve(Recurrence::class, urldecode($id), 'title');
    }

    private function findRecurrenceOrGone(string $id): ?Recurrence
    {
        $value = trim(urldecode($id));
        if (1 === preg_match('/^\d{1,19}$/', $value)) {
            $trashed = Recurrence::withTrashed()->where('id', $value)->where('user_group_id', $this->administration()->id)->first();
            if (null !== $trashed && $trashed->trashed()) {
                return null;
            }
        }

        return $this->findRecurrence($value);
    }
}
