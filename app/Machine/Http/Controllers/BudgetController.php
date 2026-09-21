<?php

/*
 * BudgetController.php
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
use FireflyIII\Enums\AutoBudgetType;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Attachment;
use FireflyIII\Models\AutoBudget;
use FireflyIII\Models\AvailableBudget;
use FireflyIII\Models\Budget;
use FireflyIII\Models\BudgetLimit;
use FireflyIII\Models\Note;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Models\TransactionCurrency;
use FireflyIII\Repositories\Budget\AvailableBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetLimitRepositoryInterface;
use FireflyIII\Repositories\Budget\BudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\NoBudgetRepositoryInterface;
use FireflyIII\Repositories\Budget\OperationsRepositoryInterface;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\JsonApi\Enrichments\BudgetEnrichment;
use FireflyIII\Transformers\BudgetTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Budgets, budget limits, available budgets and the budget period — pm/apis.mdx §8.5.
 *
 * Firefly's budgeting is period-based: a budget is an envelope NAME; a budget limit is the amount
 * allotted to it for one period in one currency; an available budget is the income expected to be
 * handed out over a period. "No limit" is `null` and a limit of zero is "0.00" — they are
 * different facts and every route here keeps them apart (§14.2, R3).
 *
 * The numbers are Firefly's own, from the same repositories the UI's Budgets page uses
 * (Budget\IndexController::index): BudgetLimitRepository (limits, budgeted), OperationsRepository
 * (spent), AvailableBudgetRepository (available), NoBudgetRepository (spent without a budget).
 * `spent` is Firefly's sign convention — negative, money out — and `left` = limit + spent.
 */
final class BudgetController extends MachineController
{
    private const array AUTO_TYPES   = ['none', 'reset', 'rollover', 'adjusted'];
    private const array AUTO_PERIODS = ['daily', 'weekly', 'monthly', 'quarterly', 'half_year', 'yearly'];

    private const array PERIOD_RULES = [
        'start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'end'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
    ];

    private const array SELECTION_RULES = [
        'budget_ids'     => ['sometimes', 'nullable', 'array', 'max:1000'],
        'budget_ids.*'   => ['required'],
        'budget_names'   => ['sometimes', 'nullable', 'array', 'max:1000'],
        'budget_names.*' => ['required', 'string', 'max:255'],
    ];

    /** @var array<int, null|TransactionCurrency> */
    private array $currencyCache = [];

    // ================================================================= reads ===

    /** GET /budgets — budgets with their auto-budget settings. `active` omitted lists every budget. */
    public function index(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['active' => ['sometimes', 'nullable', 'boolean'], 'search' => ['sometimes', 'nullable', 'string', 'max:255']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['order' => 'budgets.order', 'name' => 'budgets.name', 'id' => 'budgets.id'], 'order');
        $query  = Budget::query()->where('budgets.user_group_id', $this->administration()->id);
        if (array_key_exists('active', $args) && null !== $args['active']) {
            $query->where('budgets.active', (bool) $args['active']);
        }
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query->whereRaw('LOWER(budgets.name) LIKE ?', ['%'.mb_strtolower(trim((string) $args['search'])).'%']);
        }
        $rows   = $this->applyList($query->select('budgets.*'), $params, 'budgets.id');

        return $this->ok(['budgets' => $this->renderBudgets($rows)]);
    }

    /** GET /budgets/{id} — one budget, its limits in the range, and what it spent. */
    public function show(Request $request, string $id): JsonResponse
    {
        $args            = $this->input($request, self::PERIOD_RULES, true);
        [$start, $end, $source] = $this->resolvePeriod($args, false);
        $budget          = $this->findBudget($id);
        $rendered        = $this->renderBudgets(new Collection([$budget]), $start, $end)[0];
        $limits          = [];
        foreach ($this->limitRepo()->getBudgetLimits($budget, $start, $end) as $limit) {
            $limits[] = $this->limitRow($limit, $budget);
        }
        usort($limits, static fn (array $a, array $b): int => [$a['start'], (int) $a['id']] <=> [$b['start'], (int) $b['id']]);

        return $this->ok(['budget' => $rendered, 'limits' => $limits, 'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'), 'period_source' => $source]);
    }

    /** GET /budgets/{id}/limits — the budget's limits overlapping the range (every limit when no range is given). */
    public function limits(Request $request, string $id): JsonResponse
    {
        $args   = $this->input($request, self::PERIOD_RULES + self::LIST_RULES, true);
        $params = $this->listParams($request, ['start', 'end', 'amount', 'id'], '-start');
        $budget = $this->findBudget($id);
        $start  = null;
        $end    = null;
        if (null !== ($args['start'] ?? null) || null !== ($args['end'] ?? null)) {
            [$start, $end] = $this->resolvePeriod($args, true);
        }
        // page first, then compute spent only for the returned rows — one sumExpenses per row, never per limit ever made
        $rows   = [];
        $byId   = [];
        foreach ($this->limitRepo()->getBudgetLimits($budget, $start, $end) as $limit) {
            $rows[]                   = $this->limitRow($limit, $budget, false);
            $byId[(string) $limit->id] = $limit;
        }
        $page   = [];
        foreach ($this->applyList($rows, $params) as $row) {
            $page[] = $this->limitRow($byId[$row['id']], $budget);
        }

        return $this->ok([
            'budget_id' => (string) $budget->id,
            'name'      => (string) $budget->name,
            'start'     => $start?->format('Y-m-d'),
            'end'       => $end?->format('Y-m-d'),
            'limits'    => $page,
        ]);
    }

    /**
     * GET /budget-period — COMPOSED (R1's named exception): the numbers on the UI's Budgets page.
     * Per active budget, per currency: limit (string, or null when there is no limit), spent,
     * left; plus per currency available, budgeted_total, spent_total, left_to_spend.
     */
    public function period(Request $request): JsonResponse
    {
        $args                   = $this->input($request, self::PERIOD_RULES, true);
        [$start, $end, $source] = $this->resolvePeriod($args, false);
        $budgets                = $this->budgetRepo()->getActiveBudgets();
        $computed               = $this->computePeriod($start, $end, $budgets);

        return $this->ok([
            'start'                 => $start->format('Y-m-d'),
            'end'                   => $end->format('Y-m-d'),
            'period_source'         => $source,
            'budgets'               => $computed['rows'],
            'totals'                => $computed['totals'],
            'budgets_with_limit'    => $computed['with_limit'],
            'budgets_without_limit' => $computed['without_limit'],
            'notes'                 => [
                'limit: null means NO limit was set for the period (unbudgeted) — that is not a limit of "0.00"',
                'spent is negative (money out); left = limit + spent; left_to_spend = available + spent_total; left_to_budget = available - budgeted_total',
            ],
        ]);
    }

    /**
     * GET /budget-period/gaps — COMPOSED: budgets with spending and NO limit in the period (per
     * currency), and withdrawals with no budget at all (R3 made into a route).
     */
    public function gaps(Request $request): JsonResponse
    {
        $args                   = $this->input($request, self::PERIOD_RULES + ['min_spent' => ['sometimes', 'nullable']], true);
        [$start, $end, $source] = $this->resolvePeriod($args, false);
        $minSpent               = null;
        if (null !== ($args['min_spent'] ?? null) && '' !== $args['min_spent']) {
            $minSpent = Money::abs(Money::normalize($args['min_spent'], Money::SCALE, 'min_spent'));
        }
        $endOfDay               = $end->copy()->endOfDay();
        $budgets                = $this->budgetRepo()->getActiveBudgets();
        $ops                    = $this->opsRepo();
        $withLimit              = [];
        foreach ($budgets as $budget) {
            foreach ($this->limitRepo()->getBudgetLimits($budget, $start, $end) as $limit) {
                $withLimit[$budget->id][(int) $limit->transaction_currency_id] = true;
            }
        }

        $gaps                   = [];
        if ($budgets->count() > 0) {
            $expenses = $ops->collectExpenses($start, $endOfDay, null, $budgets);
            $counts   = self::countJournals($expenses, 'budget_id');
            foreach ($budgets as $budget) {
                foreach ($ops->sumCollectedExpensesByBudget($expenses, $budget) as $currencyId => $entry) {
                    $currencyId = (int) $currencyId;
                    $raw        = (string) $entry['sum'];
                    if (isset($withLimit[$budget->id][$currencyId]) || Money::isZero($raw)) {
                        continue;
                    }
                    if (null !== $minSpent && Money::compare(Money::abs($raw), $minSpent) < 0) {
                        continue;
                    }
                    $places = (int) $entry['currency_decimal_places'];
                    $gaps[] = [
                        'budget_id'     => (string) $budget->id,
                        'name'          => (string) $budget->name,
                        'currency_id'   => (string) $currencyId,
                        'currency_code' => (string) $entry['currency_code'],
                        'spent'         => Money::format($raw, $places),
                        'count'         => $counts[$budget->id][$currencyId] ?? 0,
                        'limit'         => null,
                    ];
                }
            }
        }

        /** @var NoBudgetRepositoryInterface $noBudget */
        $noBudget               = app(NoBudgetRepositoryInterface::class);
        $noBudget->setUser($this->operator());
        $journals               = $noBudget->collectExpenses($start, $endOfDay);
        $noBudgetCounts         = self::countJournals($journals, null);
        $withoutBudget          = [];
        foreach ($noBudget->sumExpenses($start, $endOfDay) as $currencyId => $entry) {
            $raw = (string) $entry['sum'];
            if (Money::isZero($raw) || (null !== $minSpent && Money::compare(Money::abs($raw), $minSpent) < 0)) {
                continue;
            }
            $withoutBudget[] = [
                'name'          => null,
                'currency_id'   => (string) $currencyId,
                'currency_code' => (string) $entry['currency_code'],
                'spent'         => Money::format($raw, (int) $entry['currency_decimal_places']),
                'count'         => $noBudgetCounts[0][(int) $currencyId] ?? 0,
            ];
        }

        return $this->ok([
            'start'                 => $start->format('Y-m-d'),
            'end'                   => $end->format('Y-m-d'),
            'period_source'         => $source,
            'min_spent'             => $minSpent,
            'budgets_without_limit' => $gaps,
            'without_budget'        => $withoutBudget,
            'notes'                 => [
                'budgets_without_limit: spending in a currency the budget has NO limit for in the period (unbudgeted, not budgeted zero)',
                'without_budget: withdrawals with no budget at all — list them with GET /transactions?without_budget=true',
            ],
        ]);
    }

    /** GET /available-budgets — the income-to-budget per period, overlapping the range (all when no range). */
    public function availableIndex(Request $request): JsonResponse
    {
        $args   = $this->input($request, self::PERIOD_RULES + self::LIST_RULES, true);
        $params = $this->listParams($request, ['start' => 'start_date', 'end' => 'end_date', 'id' => 'id'], 'start');
        $query  = AvailableBudget::query()->where('user_id', $this->operator()->id)->with('transactionCurrency');
        $start  = null;
        $end    = null;
        if (null !== ($args['start'] ?? null) || null !== ($args['end'] ?? null)) {
            [$start, $end] = $this->resolvePeriod($args, true);
            $query->whereDate('start_date', '<=', $end->format('Y-m-d'))->whereDate('end_date', '>=', $start->format('Y-m-d'));
        }
        $rows   = [];
        foreach ($this->applyList($query, $params) as $available) {
            $rows[] = $this->availableRow($available);
        }

        return $this->ok(['available_budgets' => $rows, 'start' => $start?->format('Y-m-d'), 'end' => $end?->format('Y-m-d')]);
    }

    // ================================================================ writes ===

    /** POST /budgets — create a budget, with an optional auto-budget. */
    public function store(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'name'   => ['required', 'string', 'min:1', 'max:255'],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'notes'  => ['sometimes', 'nullable', 'string', 'max:32768'],
        ] + self::autoBudgetRules());
        $name = trim((string) $args['name']);
        if ('' === $name) {
            throw MachineException::invalid('name cannot be blank.', 'Pass "name": "Groceries"', ['field' => 'name']);
        }
        $data = ['name' => $name, 'active' => (bool) ($args['active'] ?? true)];
        if (isset($args['notes']) && '' !== trim((string) $args['notes'])) {
            $data['notes'] = (string) $args['notes'];
        }
        $data = array_merge($data, $this->autoBudgetData($args, null));

        return $this->write($request, $args, function (bool $dryRun) use ($name, $data): WriteResult {
            $this->refuseDuplicateName($name, null);
            $result = new WriteResult();
            $budget = $this->trackingAvailable($result, $dryRun, fn (): Budget => $this->budgetRepo()->store($data));
            $result->created($budget)->count('created');
            foreach (Note::query()->where('noteable_type', Budget::class)->where('noteable_id', $budget->id)->get() as $note) {
                $result->created($note);
            }
            foreach (AutoBudget::query()->where('budget_id', $budget->id)->get() as $auto) {
                $result->created($auto);
            }
            $limits = [];
            foreach (BudgetLimit::query()->where('budget_id', $budget->id)->get() as $limit) {
                $result->created($limit)->count('limits_created');
                $limits[] = $this->limitRow($limit, $budget, false);
            }
            $budget->refresh();

            return $result->with(['budget' => $this->renderBudgets(new Collection([$budget]))[0], 'limits' => $limits]);
        });
    }

    /** PUT /budgets/{id} — rename, (de)activate, notes, and the auto-budget. */
    public function update(Request $request, string $id): JsonResponse
    {
        $args   = $this->input($request, [
            'name'   => ['sometimes', 'string', 'min:1', 'max:255'],
            'active' => ['sometimes', 'nullable', 'boolean'],
            'notes'  => ['sometimes', 'nullable', 'string', 'max:32768'],
        ] + self::autoBudgetRules());
        $budget = $this->findBudget($id);
        $fields = array_diff(array_keys($args), array_keys(self::CONTROL_RULES));
        if ([] === $fields) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of name, active, notes, auto_budget_type, auto_budget_amount, auto_budget_period, auto_budget_currency_code');
        }
        $data   = [];
        if (array_key_exists('name', $args)) {
            $data['name'] = trim((string) $args['name']);
            if ('' === $data['name']) {
                throw MachineException::invalid('name cannot be blank.', 'Pass "name": "Groceries" — or leave name out to keep it', ['field' => 'name']);
            }
        }
        if (array_key_exists('active', $args) && null !== $args['active']) {
            $data['active'] = (bool) $args['active'];
        }
        if (array_key_exists('notes', $args)) {
            $data['notes'] = (string) ($args['notes'] ?? '');
        }
        $data   = array_merge($data, $this->autoBudgetData($args, $budget));
        $budgetId = (int) $budget->id;

        return $this->write($request, $args, function (bool $dryRun) use ($budgetId, $data): WriteResult {
            $budget  = Budget::query()->findOrFail($budgetId);
            $result  = new WriteResult();
            if (array_key_exists('name', $data)) {
                $this->refuseDuplicateName($data['name'], $budgetId);
            }
            $renamed = array_key_exists('name', $data) && $data['name'] !== $budget->name;
            $before  = [$budget->name, (bool) $budget->active, $this->noteText($budget)];
            $result->updating($budget);
            if ($renamed) {
                $this->recordRuleCascade($result, (string) $budget->name, 'set_budget', 'budget_is');
            }
            $noteAfter = $this->recordNoteChange($result, $budget, $data['notes'] ?? null);
            $auto    = AutoBudget::query()->where('budget_id', $budgetId)->first();
            $autoType = $data['auto_budget_type'] ?? null;
            if (null !== $auto && 0 === $autoType) {
                $result->deleting($auto);
            } elseif (null !== $auto && null !== $autoType) {
                $result->updating($auto);
            }

            $this->budgetRepo()->update($budget, $data);
            $noteAfter();
            if (null === $auto && null !== $autoType && 0 !== $autoType) {
                foreach (AutoBudget::query()->where('budget_id', $budgetId)->get() as $created) {
                    $result->created($created);
                }
            }
            $budget->refresh();
            $changed = $before !== [$budget->name, (bool) $budget->active, $this->noteText($budget)] || null !== $autoType;
            $result->count($changed ? 'updated' : 'unchanged');

            return $result->with(['budget' => $this->renderBudgets(new Collection([$budget]))[0]]);
        });
    }

    /**
     * PUT /budgets/{id}/limits — SET (not add): create or replace the limit for EXACTLY this
     * period and currency. "0.00" is a real limit of zero; to remove a limit use DELETE.
     */
    public function setLimit(Request $request, string $id): JsonResponse
    {
        $args     = $this->input($request, [
            'start'         => ['required', 'date_format:Y-m-d'],
            'end'           => ['required', 'date_format:Y-m-d'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:51'],
            'amount'        => ['required'],
            'notes'         => ['sometimes', 'nullable', 'string', 'max:32768'],
        ]);
        [$start, $end] = $this->resolvePeriod($args, true);
        $budget   = $this->findBudget($id);
        $currency = $this->currencyFor($args['currency_code'] ?? null);
        $amount   = $this->limitAmount($args['amount'], $currency);
        $notes    = array_key_exists('notes', $args) ? (string) ($args['notes'] ?? '') : null;
        $budgetId = (int) $budget->id;

        return $this->write($request, $args, function (bool $dryRun) use ($budgetId, $currency, $start, $end, $amount, $notes): WriteResult {
            $budget = Budget::query()->findOrFail($budgetId);
            $result = new WriteResult();
            $row    = $this->trackingAvailable($result, $dryRun, fn (): array => $this->applyLimit($result, $budget, $currency, $start, $end, $amount, $notes));
            $result->basis = [$row];

            return $result->with(['budget_id' => (string) $budget->id, 'name' => (string) $budget->name, 'limits' => [$row], 'action' => $row['action']]);
        });
    }

    /**
     * DELETE /budgets/{id}/limits/{limit_id} — remove one period's limit. The budget and its
     * transactions are untouched; the period becomes UNBUDGETED, which is not budgeted zero.
     */
    public function deleteLimit(Request $request, string $id, string $limit_id): JsonResponse
    {
        $args     = $this->input($request, []);
        $budget   = $this->findBudget($id);
        if (1 !== preg_match('/^\d{1,19}$/', $limit_id)) {
            throw MachineException::invalid('limit_id must be a numeric id.', sprintf('GET /machine/v1/budgets/%d/limits lists the limit ids', $budget->id), ['limit_id' => $limit_id]);
        }
        $budgetId = (int) $budget->id;
        $limitId  = (int) $limit_id;

        return $this->write($request, $args, function (bool $dryRun) use ($budgetId, $limitId): WriteResult {
            $budget = Budget::query()->findOrFail($budgetId);
            $result = new WriteResult();
            $limit  = BudgetLimit::query()->where('budget_id', $budgetId)->where('id', $limitId)->first();
            if (null === $limit) {
                return $result->with([
                    'budget_id' => (string) $budgetId,
                    'name'      => (string) $budget->name,
                    'limit_id'  => (string) $limitId,
                    'deleted'   => 0,
                    'message'   => sprintf('Budget "%s" has no limit #%d — nothing to delete (already gone?).', $budget->name, $limitId),
                ]);
            }
            $row       = $this->limitRow($limit, $budget);
            $result->deleting($limit)->count('deleted');
            $this->trackingAvailable($result, $dryRun, fn () => $this->limitRepo()->destroyBudgetLimit($limit));
            // "unbudgeted" only when no OTHER limit in that currency still covers any of those days
            $covering  = $this->limitRepo()->getBudgetLimits($budget, Carbon::parse($row['start']), Carbon::parse($row['end']))
                ->filter(static fn (BudgetLimit $l): bool => (int) $l->transaction_currency_id === (int) $row['currency_id'] && (int) $l->id !== $limitId)
                ->map(static fn (BudgetLimit $l): array => ['id' => (string) $l->id, 'start' => $l->start_date->format('Y-m-d'), 'end' => $l->end_date->format('Y-m-d')])
                ->values()->all();
            $unbudgeted = [] === $covering;
            $result->basis = [$row];

            return $result->with([
                'budget_id'        => (string) $budgetId,
                'name'             => (string) $budget->name,
                'limit_id'         => (string) $limitId,
                'deleted'          => 1,
                'limit'            => $row,
                'unbudgeted'       => $unbudgeted,
                'still_covered_by' => $covering,
                'message'          => $unbudgeted
                    ? sprintf('Budget "%s" is now UNBUDGETED for %s .. %s in %s (no limit — not a limit of zero).', $budget->name, $row['start'], $row['end'], $row['currency_code'])
                    : sprintf('Limit #%d removed; budget "%s" still has %d other %s limit(s) overlapping %s .. %s.', $limitId, $budget->name, count($covering), $row['currency_code'], $row['start'], $row['end']),
            ]);
        });
    }

    /** PUT /available-budgets — set the income-to-budget for exactly one period and currency. */
    public function setAvailable(Request $request): JsonResponse
    {
        $args     = $this->input($request, [
            'start'         => ['required', 'date_format:Y-m-d'],
            'end'           => ['required', 'date_format:Y-m-d'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'max:51'],
            'amount'        => ['required'],
        ]);
        [$start, $end] = $this->resolvePeriod($args, true);
        $currency = $this->currencyFor($args['currency_code'] ?? null);
        $amount   = $this->limitAmount($args['amount'], $currency);

        return $this->write($request, $args, function (bool $dryRun) use ($currency, $start, $end, $amount): WriteResult {
            $result   = new WriteResult();
            $repo     = $this->availableRepo();
            $existing = $this->exactAvailable($currency, $start, $end);
            $previous = null === $existing ? null : Money::forCurrency((string) $existing->amount, $currency);
            if (null !== $existing && null !== $previous && 0 === Money::compare($previous, $amount)) {
                $result->count('unchanged');
                $available = $existing;
            } elseif (null !== $existing) {
                $result->updating($existing)->count('updated');
                $available = $repo->update($existing, ['amount' => $amount]);
            } else {
                // store(), not setAvailableBudget(): the latter leaves user_group_id NULL, which hides
                // the row from administration-scoped code (primary-currency recalculation)
                $available = $repo->store(['start' => $start->copy(), 'end' => $end->copy(), 'currency_id' => $currency->id, 'amount' => $amount]);
                if (null === $available) {
                    throw MachineException::upstream('Firefly did not store the available budget.', 'Retry; if it persists, check the Firefly log');
                }
                if ((int) $available->user_group_id !== (int) $this->administration()->id) {
                    $available->user_group_id = $this->administration()->id;
                    $available->save();
                }
                $result->created($available)->count('created');
            }
            $row      = $this->availableRow($available->refresh()) + ['previous' => $previous];
            $result->basis = [$row['amount'], $previous];

            return $result->with(['available_budget' => $row]);
        });
    }

    /**
     * POST /budget-period/copy-previous — every limit from the previous period of the same length
     * (the previous calendar month(s) for a whole-month period) set into this one.
     */
    public function copyPrevious(Request $request): JsonResponse
    {
        $args          = $this->input($request, ['start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d']] + self::SELECTION_RULES);
        [$start, $end] = $this->resolvePeriod($args, true);
        $budgetIds     = $this->selectBudgets($args)->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        [$prevStart, $prevEnd] = self::previousPeriod($start, $end, 1);

        return $this->write($request, $args, function (bool $dryRun) use ($budgetIds, $start, $end, $prevStart, $prevEnd): WriteResult {
            $result  = new WriteResult();
            $rows    = [];
            $skipped = [];
            foreach ($this->budgetsById($budgetIds) as $budget) {
                $previous = $budget->budgetlimits()->whereDate('start_date', $prevStart->format('Y-m-d'))->whereDate('end_date', $prevEnd->format('Y-m-d'))->orderBy('id')->get();
                if (0 === $previous->count()) {
                    $skipped[] = ['budget_id' => (string) $budget->id, 'name' => (string) $budget->name, 'reason' => 'no limit in the previous period'];

                    continue;
                }
                foreach ($previous as $old) {
                    $currency = $this->currency((int) $old->transaction_currency_id) ?? $this->primaryCurrency();
                    $amount   = Money::forCurrency((string) $old->amount, $currency);
                    $rows[]   = $this->trackingAvailable($result, $dryRun, fn (): array => $this->applyLimit($result, $budget, $currency, $start, $end, $amount, null)) + ['copied_from' => (string) $old->id];
                }
            }
            $result->basis = $rows;

            return $result->with([
                'start'            => $start->format('Y-m-d'),
                'end'              => $end->format('Y-m-d'),
                'previous_start'   => $prevStart->format('Y-m-d'),
                'previous_end'     => $prevEnd->format('Y-m-d'),
                'limits'           => $rows,
                'skipped'          => $skipped,
                'left_unbudgeted'  => $this->unbudgeted($budgetIds, $start, $end),
            ]);
        });
    }

    /**
     * POST /budget-period/set-average — each budget's limit set to the average SPENT over the
     * previous N periods of the same length, per currency (bcmath; periods with no spending
     * count as zero). A budget that spent nothing in all N periods is left alone and reported.
     */
    public function setAverage(Request $request): JsonResponse
    {
        $args          = $this->input($request, ['start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d'], 'periods' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:60']] + self::SELECTION_RULES);
        [$start, $end] = $this->resolvePeriod($args, true);
        $n             = (int) ($args['periods'] ?? 6);
        $budgetIds     = $this->selectBudgets($args)->pluck('id')->map(static fn ($v): int => (int) $v)->all();
        $windows       = [];
        for ($i = 1; $i <= $n; ++$i) {
            $windows[] = self::previousPeriod($start, $end, $i);
        }

        return $this->write($request, $args, function (bool $dryRun) use ($budgetIds, $start, $end, $n, $windows): WriteResult {
            $result   = new WriteResult();
            $rows     = [];
            $skipped  = [];
            $budgets  = $this->budgetsById($budgetIds);
            $ops      = $this->opsRepo();
            $oldest   = $windows[count($windows) - 1][0];
            $newest   = $windows[0][1]->copy()->endOfDay();
            $expenses = $budgets->count() > 0 ? $ops->collectExpenses($oldest, $newest, null, $budgets) : [];
            foreach ($budgets as $budget) {
                $perCurrency = []; // currency id => [period index => raw spent]
                foreach ($windows as $index => [$ws, $we]) {
                    $weEnd  = $we->copy()->endOfDay();
                    $inside = array_filter($expenses, static fn (array $e): bool => $e['date']->between($ws, $weEnd));
                    foreach ($ops->sumCollectedExpensesByBudget($inside, $budget) as $currencyId => $entry) {
                        $perCurrency[(int) $currencyId][$index] = (string) $entry['sum'];
                    }
                }
                $any         = false;
                ksort($perCurrency);
                foreach ($perCurrency as $currencyId => $spentByPeriod) {
                    $total = Money::abs(Money::sum($spentByPeriod));
                    if (Money::isZero($total)) {
                        continue;
                    }
                    $currency = $this->currency($currencyId) ?? $this->primaryCurrency();
                    $average  = Money::forCurrency((string) Money::div($total, (string) $n), $currency);
                    $history  = [];
                    foreach ($windows as $index => [$ws, $we]) {
                        $history[] = ['start' => $ws->format('Y-m-d'), 'end' => $we->format('Y-m-d'), 'spent' => Money::forCurrency($spentByPeriod[$index] ?? '0', $currency)];
                    }
                    $rows[]   = $this->trackingAvailable($result, $dryRun, fn (): array => $this->applyLimit($result, $budget, $currency, $start, $end, $average, null)) + ['average_of' => $n, 'spent_total' => Money::forCurrency(Money::negate($total), $currency), 'history' => $history];
                    $any      = true;
                }
                if (!$any) {
                    $skipped[] = ['budget_id' => (string) $budget->id, 'name' => (string) $budget->name, 'reason' => sprintf('spent nothing in the previous %d period(s) — left as it was, not set to "0.00"', $n)];
                }
            }
            $result->basis = $rows;

            return $result->with([
                'start'           => $start->format('Y-m-d'),
                'end'             => $end->format('Y-m-d'),
                'periods'         => $n,
                'windows'         => array_map(static fn (array $w): array => ['start' => $w[0]->format('Y-m-d'), 'end' => $w[1]->format('Y-m-d')], $windows),
                'limits'          => $rows,
                'skipped'         => $skipped,
                'left_unbudgeted' => $this->unbudgeted($budgetIds, $start, $end),
            ]);
        });
    }

    /**
     * POST /budget-period/reset — remove every limit that lies inside the period (for the selected
     * budgets). The period becomes UNBUDGETED for them, and the response says so.
     */
    public function reset(Request $request): JsonResponse
    {
        $args          = $this->input($request, ['start' => ['required', 'date_format:Y-m-d'], 'end' => ['required', 'date_format:Y-m-d']] + self::SELECTION_RULES);
        [$start, $end] = $this->resolvePeriod($args, true);
        $budgetIds     = $this->selectBudgets($args)->pluck('id')->map(static fn ($v): int => (int) $v)->all();

        return $this->write($request, $args, function (bool $dryRun) use ($budgetIds, $start, $end): WriteResult {
            $result = new WriteResult();
            $rows   = [];
            foreach ($this->budgetsById($budgetIds) as $budget) {
                $limits = $budget->budgetlimits()->whereDate('start_date', '>=', $start->format('Y-m-d'))->whereDate('end_date', '<=', $end->format('Y-m-d'))->orderBy('id')->get();
                foreach ($limits as $limit) {
                    $row    = $this->limitRow($limit, $budget, false);
                    $rows[] = ['name' => $row['name'], 'budget_id' => $row['budget_id'], 'limit_id' => $row['id'], 'start' => $row['start'], 'end' => $row['end'], 'currency_code' => $row['currency_code'], 'previous' => $row['amount'], 'amount' => null, 'action' => 'deleted'];
                    $result->deleting($limit)->count('deleted');
                    $this->trackingAvailable($result, $dryRun, fn () => $this->limitRepo()->destroyBudgetLimit($limit));
                }
            }
            $result->basis = $rows;

            return $result->with([
                'start'           => $start->format('Y-m-d'),
                'end'             => $end->format('Y-m-d'),
                'limits'          => $rows,
                'left_unbudgeted' => $this->unbudgeted($budgetIds, $start, $end),
                'message'         => 'Removed limits leave their budgets UNBUDGETED for the period (no limit) — not budgeted zero.',
            ]);
        });
    }

    /** DELETE /budgets/{id} — ADMIN tier. The transactions keep their amounts and lose the budget. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args     = $this->input($request, []);
        $budget   = $this->findBudgetOrNull($id);
        $budgetId = null === $budget ? null : (int) $budget->id;

        return $this->write($request, $args, function (bool $dryRun) use ($budgetId, $id): WriteResult {
            $result = new WriteResult();
            $budget = null === $budgetId ? null : Budget::query()->find($budgetId);
            if (null === $budget) {
                return $result->with(['deleted' => 0, 'id' => $id, 'message' => 'No such budget — nothing to delete (already gone?).']);
            }
            $journals = DB::table('budget_transaction_journal')->where('budget_id', $budget->id)->count();
            $rendered = $this->renderBudgets(new Collection([$budget]))[0];
            foreach (BudgetLimit::query()->where('budget_id', $budget->id)->get() as $limit) {
                $result->deleting($limit)->count('limits_deleted');
            }
            foreach (AutoBudget::query()->where('budget_id', $budget->id)->get() as $auto) {
                $result->deleting($auto);
            }
            foreach (Note::query()->where('noteable_type', Budget::class)->where('noteable_id', $budget->id)->get() as $note) {
                $result->deleting($note);
            }
            if ($journals > 0) {
                // the journal links are removed by Firefly's destroy service and are not in the undo log
                $result->touched[] = ['class' => 'FireflyIII\Machine\Pivot\BudgetTransactionJournal', 'id' => (int) $budget->id, 'op' => 'deleted', 'before' => ['budget_id' => (int) $budget->id, 'journals' => $journals]];
            }
            // Firefly's BudgetDestroyService deletes the budget's attachment FILES from the upload
            // disk — outside the DB transaction, so a rollback cannot bring them back.
            $attachments = $budget->attachments()->count();
            if ($attachments > 0) {
                $result->count('attachments_deleted', $attachments);
                $result->touched[] = ['class' => Attachment::class, 'id' => (int) $budget->id, 'op' => 'irreversible', 'before' => [
                    'what'   => 'attachment',
                    'did'    => 'deleted with their files',
                    'reason' => sprintf('%d attachment file(s) of budget "%s" were removed from storage; undo cannot restore files', $attachments, $budget->name),
                ]];
            }
            $result->deleting($budget)->count('deleted');
            $this->withoutFileDeletes($dryRun, fn () => $this->trackingAvailable($result, $dryRun, fn (): bool => $this->budgetRepo()->destroy($budget)));

            return $result->with([
                'deleted'           => 1,
                'budget'            => $rendered,
                'journals_unbudgeted' => $journals,
                'attachments_deleted' => $attachments,
                'undoable'          => 0 === $journals && 0 === $attachments,
                'message'           => 0 === $journals
                    ? sprintf('Budget "%s" deleted.', $budget->name)
                    : sprintf('Budget "%s" deleted; %d transaction(s) keep their amounts and now have no budget. Undo cannot restore those links.', $budget->name, $journals),
            ]);
        });
    }

    // =============================================================== helpers ===

    /**
     * Run a limit write and record, on a REAL apply, the available budgets Firefly's own queued
     * listener (ProcessesBudgetLimits → AvailableBudgetCalculator) created, recalculated or removed
     * because a limit changed — so undo reverses them too. In a dry run the listener is held
     * (it shows in `held.jobs`), so there is nothing to record.
     *
     * @template T
     *
     * @param Closure(): T $fn
     *
     * @return T
     */
    private function trackingAvailable(WriteResult $result, bool $dryRun, Closure $fn): mixed
    {
        if ($dryRun) {
            return $fn();
        }
        $snapshot = fn (): array => DB::table('available_budgets')->where('user_id', $this->operator()->id)->orderBy('id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->id => (array) $row])->all();
        $before   = $snapshot();
        $out      = $fn();
        $after    = $snapshot();
        $n        = 0;
        foreach ($after as $id => $row) {
            if (!array_key_exists($id, $before)) {
                $result->touched[] = ['class' => AvailableBudget::class, 'id' => $id, 'op' => 'created', 'before' => null];
                ++$n;
            } elseif ($row !== $before[$id]) {
                $result->touched[] = ['class' => AvailableBudget::class, 'id' => $id, 'op' => 'updated', 'before' => $before[$id]];
                ++$n;
            }
        }
        foreach ($before as $id => $row) {
            if (!array_key_exists($id, $after)) {
                $result->touched[] = ['class' => AvailableBudget::class, 'id' => $id, 'op' => 'deleted', 'before' => $row];
                ++$n;
            }
        }
        if ($n > 0) {
            $result->with(['available_budgets_recalculated' => $n]);
        }

        return $out;
    }

    /**
     * In a dry run, point the `upload` disk at a throw-away directory while $fn runs, so a
     * destroy service that deletes attachment files (BudgetDestroyService) deletes nothing real —
     * the DB rollback cannot restore a file. On a real apply $fn runs untouched.
     *
     * @template T
     *
     * @param Closure(): T $fn
     *
     * @return T
     */
    private function withoutFileDeletes(bool $dryRun, Closure $fn): mixed
    {
        if (!$dryRun) {
            return $fn();
        }
        $original = Storage::disk('upload');
        $scratch  = sprintf('%s/ffmachine-dryrun-%s', sys_get_temp_dir(), bin2hex(random_bytes(6)));
        Storage::set('upload', Storage::build(['driver' => 'local', 'root' => $scratch]));

        try {
            return $fn();
        } finally {
            Storage::set('upload', $original);
            if (is_dir($scratch)) {
                @rmdir($scratch);
            }
        }
    }

    /**
     * The period of a call (§14.3): both start and end, inclusive; or neither, meaning the
     * operator's current viewRange period, resolved here and echoed.
     *
     * @param array<string, mixed> $args
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolvePeriod(array $args, bool $required): array
    {
        $s  = $args['start'] ?? null;
        $e  = $args['end'] ?? null;
        $tz = (string) config('app.timezone');
        if ((null === $s || '' === $s) && (null === $e || '' === $e)) {
            if ($required) {
                throw MachineException::invalid('start and end are required.', 'Pass start and end as YYYY-MM-DD, e.g. "start": "2026-10-01", "end": "2026-10-31" (both inclusive)', ['fields' => ['start', 'end']]);
            }
            $range = Navigation::getViewRange(true);
            $start = Navigation::startOfPeriod(Carbon::today($tz), $range)->startOfDay();
            $end   = Navigation::endOfPeriod($start->copy(), $range)->startOfDay();

            return [$start, $end, 'viewRange '.$range];
        }
        if (null === $s || '' === $s || null === $e || '' === $e) {
            throw MachineException::invalid('Give both start and end, or neither.', 'Pass start AND end as YYYY-MM-DD (inclusive) — or neither, for the current budget period', ['start' => $s, 'end' => $e]);
        }
        $start = Carbon::createFromFormat('!Y-m-d', (string) $s, $tz);
        $end   = Carbon::createFromFormat('!Y-m-d', (string) $e, $tz);
        if (!$start instanceof Carbon || !$end instanceof Carbon) {
            throw MachineException::invalid('start and end must be dates.', 'Pass YYYY-MM-DD');
        }
        if ($end->lt($start)) {
            throw MachineException::invalid('end is before start.', 'Pass an end on or after start — ranges are inclusive on both ends', ['start' => $s, 'end' => $e]);
        }

        return [$start, $end, 'given'];
    }

    /**
     * The k-th previous period of the same length: whole calendar months step back by months
     * (October → September, Q4 → Q3), anything else steps back by its length in days.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function previousPeriod(Carbon $start, Carbon $end, int $k): array
    {
        $wholeMonths = 1 === $start->day && $end->isSameDay($end->copy()->endOfMonth());
        if ($wholeMonths) {
            $months = ((int) $end->year * 12 + (int) $end->month) - ((int) $start->year * 12 + (int) $start->month) + 1;
            $ps     = $start->copy()->startOfMonth()->subMonthsNoOverflow($months * $k);
            $pe     = $ps->copy()->addMonthsNoOverflow($months - 1)->endOfMonth()->startOfDay();

            return [$ps, $pe];
        }
        $days = (int) $start->diffInDays($end, true) + 1;

        return [$start->copy()->subDays($days * $k), $end->copy()->subDays($days * $k)];
    }

    /**
     * The composed budget period: one row per active budget per currency.
     *
     * @return array{rows: list<array<string, mixed>>, totals: list<array<string, mixed>>, with_limit: int, without_limit: int}
     */
    private function computePeriod(Carbon $start, Carbon $end, Collection $budgets): array
    {
        $endOfDay     = $end->copy()->endOfDay();
        $ops          = $this->opsRepo();
        $limitRepo    = $this->limitRepo();
        $primary      = $this->primaryCurrency();
        $expenses     = $budgets->count() > 0 ? $ops->collectExpenses($start, $endOfDay, null, $budgets) : [];
        $autos        = AutoBudget::query()->whereIn('budget_id', $budgets->pluck('id')->all())->get()->keyBy('budget_id');
        $rows         = [];
        $sums         = []; // currency id => [budgeted, spent]
        $withLimit    = 0;
        $withoutLimit = 0;
        foreach ($budgets as $budget) {
            $limits      = $limitRepo->getBudgetLimits($budget, $start, $end);
            $spent       = [];
            foreach ($ops->sumCollectedExpensesByBudget($expenses, $budget) as $currencyId => $entry) {
                $spent[(int) $currencyId] = (string) $entry['sum'];
            }
            $currencyIds = array_unique(array_merge($limits->pluck('transaction_currency_id')->map(static fn ($v): int => (int) $v)->all(), array_keys($spent)));
            if ([] === $currencyIds) {
                $currencyIds = [(int) $primary->id];
            }
            sort($currencyIds);
            $auto        = $autos->get($budget->id);
            $hasLimit    = false;
            foreach ($currencyIds as $currencyId) {
                $currency = $this->currency($currencyId) ?? $primary;
                $places   = (int) $currency->decimal_places;
                $inCur    = $limits->filter(static fn (BudgetLimit $l): bool => (int) $l->transaction_currency_id === $currencyId)->values();
                $limitRaw = null;
                if ($inCur->count() > 0) {
                    $limitRaw = (string) $limitRepo->budgeted($start, $end, $currency, new Collection([$budget]));
                    $hasLimit = true;
                }
                $spentRaw = $spent[$currencyId] ?? '0';
                $sums[$currencyId] ??= ['budgeted' => '0', 'spent' => '0'];
                $sums[$currencyId]['spent'] = Money::add($sums[$currencyId]['spent'], $spentRaw);
                if (null !== $limitRaw) {
                    $sums[$currencyId]['budgeted'] = Money::add($sums[$currencyId]['budgeted'], $limitRaw);
                }
                $rows[]   = [
                    'budget_id'               => (string) $budget->id,
                    'name'                    => (string) $budget->name,
                    'currency_id'             => (string) $currency->id,
                    'currency_code'           => (string) $currency->code,
                    'currency_decimal_places' => $places,
                    'limit'                   => null === $limitRaw ? null : Money::format($limitRaw, $places),
                    'spent'                   => Money::format($spentRaw, $places),
                    'left'                    => null === $limitRaw ? null : Money::format(Money::add($limitRaw, $spentRaw), $places),
                    'auto_budget'             => null === $auto ? null : self::autoTypeName((int) $auto->auto_budget_type),
                    'limits'                  => $inCur->map(fn (BudgetLimit $l): array => [
                        'id'     => (string) $l->id,
                        'start'  => $l->start_date->format('Y-m-d'),
                        'end'    => $l->end_date->format('Y-m-d'),
                        'amount' => Money::format((string) $l->amount, $places),
                    ])->all(),
                ];
            }
            $hasLimit ? ++$withLimit : ++$withoutLimit;
        }

        // the available budgets of exactly this period join the totals, even for a currency with no budget rows
        $available    = [];
        foreach (AvailableBudget::query()->where('user_id', $this->operator()->id)->whereDate('start_date', $start->format('Y-m-d'))->whereDate('end_date', $end->format('Y-m-d'))->orderBy('id')->get() as $ab) {
            $available[(int) $ab->transaction_currency_id] ??= (string) $ab->amount;
            $sums[(int) $ab->transaction_currency_id] ??= ['budgeted' => '0', 'spent' => '0'];
        }
        ksort($sums);
        $totals       = [];
        foreach ($sums as $currencyId => $sum) {
            $currency = $this->currency($currencyId) ?? $primary;
            $places   = (int) $currency->decimal_places;
            $avail    = $available[$currencyId] ?? null;
            $totals[] = [
                'currency_id'             => (string) $currency->id,
                'currency_code'           => (string) $currency->code,
                'currency_decimal_places' => $places,
                'available'               => null === $avail ? null : Money::format($avail, $places),
                'budgeted_total'          => Money::format($sum['budgeted'], $places),
                'spent_total'             => Money::format($sum['spent'], $places),
                'left_in_budgets'         => Money::format(Money::add($sum['budgeted'], $sum['spent']), $places),
                'left_to_spend'           => null === $avail ? null : Money::format(Money::add($avail, $sum['spent']), $places),
                'left_to_budget'          => null === $avail ? null : Money::format(Money::sub($avail, $sum['budgeted']), $places),
            ];
        }

        return ['rows' => $rows, 'totals' => $totals, 'with_limit' => $withLimit, 'without_limit' => $withoutLimit];
    }

    /**
     * Set the limit for EXACTLY [start, end] in $currency (create, replace, or leave alone) and
     * record it on $result. Returns the row the response shows.
     *
     * @return array<string, mixed>
     */
    private function applyLimit(WriteResult $result, Budget $budget, TransactionCurrency $currency, Carbon $start, Carbon $end, string $amount, ?string $notes): array
    {
        $existing = $this->exactLimit($budget, (int) $currency->id, $start->format('Y-m-d'), $end->format('Y-m-d'));
        $previous = null === $existing ? null : Money::forCurrency((string) $existing->amount, $currency);
        $repo     = $this->limitRepo();
        if (null !== $existing && null !== $previous && 0 === Money::compare($previous, $amount) && null === $notes) {
            $action = 'unchanged';
            $result->count('unchanged');
            $limit  = $existing;
        } elseif (null !== $existing) {
            $action = 'updated';
            $result->updating($existing)->count('updated');
            $data   = ['amount' => $amount];
            if (null !== $notes) {
                $data['notes'] = $notes;
            }
            $limit  = $repo->update($existing, $data);
        } else {
            $action = 'created';
            $data   = ['budget_id' => $budget->id, 'currency_id' => $currency->id, 'start_date' => $start->copy(), 'end_date' => $end->copy(), 'amount' => $amount];
            if (null !== $notes && '' !== $notes) {
                $data['notes'] = $notes;
            }
            $limit  = $repo->store($data);
            $result->created($limit)->count('created');
        }

        return [
            'name'          => (string) $budget->name,
            'budget_id'     => (string) $budget->id,
            'limit_id'      => (string) $limit->id,
            'start'         => $start->format('Y-m-d'),
            'end'           => $end->format('Y-m-d'),
            'currency_code' => (string) $currency->code,
            'previous'      => $previous,
            'amount'        => $amount,
            'action'        => $action,
        ];
    }

    private function exactLimit(Budget $budget, int $currencyId, string $start, string $end): ?BudgetLimit
    {
        /** @var null|BudgetLimit */
        return $budget->budgetlimits()->where('transaction_currency_id', $currencyId)->whereDate('start_date', $start)->whereDate('end_date', $end)->orderBy('id')->first();
    }

    private function exactAvailable(TransactionCurrency $currency, Carbon $start, Carbon $end): ?AvailableBudget
    {
        /** @var null|AvailableBudget */
        return AvailableBudget::query()
            ->where('user_id', $this->operator()->id)
            ->where('transaction_currency_id', $currency->id)
            ->whereDate('start_date', $start->format('Y-m-d'))
            ->whereDate('end_date', $end->format('Y-m-d'))
            ->orderBy('id')
            ->first()
        ;
    }

    /**
     * The selected budgets (ids and names, resolved server-side) that have NO limit at all
     * overlapping the period — "unbudgeted", stated as such.
     *
     * @param list<int> $budgetIds
     *
     * @return list<array{budget_id: string, name: string}>
     */
    private function unbudgeted(array $budgetIds, Carbon $start, Carbon $end): array
    {
        $out = [];
        foreach ($this->budgetsById($budgetIds) as $budget) {
            if (0 === $this->limitRepo()->getBudgetLimits($budget, $start, $end)->count()) {
                $out[] = ['budget_id' => (string) $budget->id, 'name' => (string) $budget->name];
            }
        }

        return $out;
    }

    /**
     * budget_ids[] / budget_names[] (ids or names; the server resolves), or every active budget.
     *
     * @param array<string, mixed> $args
     */
    private function selectBudgets(array $args): Collection
    {
        $refs = array_merge((array) ($args['budget_ids'] ?? []), (array) ($args['budget_names'] ?? []));
        if ([] === $refs) {
            return $this->budgetRepo()->getActiveBudgets();
        }
        $out  = [];
        foreach ($refs as $ref) {
            if (!is_string($ref) && !is_int($ref)) {
                throw MachineException::invalid('budget_ids must be a list of ids or names.', 'Pass "budget_ids": ["3", "Groceries"]', ['field' => 'budget_ids']);
            }
            $budget           = $this->findBudget((string) $ref);
            $out[$budget->id] = $budget;
        }
        ksort($out);

        return new Collection(array_values($out));
    }

    /** @param list<int> $ids */
    private function budgetsById(array $ids): Collection
    {
        if ([] === $ids) {
            return new Collection();
        }

        return Budget::query()->whereIn('id', $ids)->orderBy('order')->orderBy('name')->orderBy('id')->get();
    }

    private function findBudget(string $idOrName): Budget
    {
        /** @var Budget */
        return $this->resolve(Budget::class, urldecode($idOrName));
    }

    private function findBudgetOrNull(string $idOrName): ?Budget
    {
        try {
            return $this->findBudget($idOrName);
        } catch (MachineException $e) {
            if ('not_found' === $e->code() && 1 === preg_match('/^\d{1,19}$/', trim($idOrName))) {
                return null; // a DELETE of something already gone is ok: true, deleted: 0 (§5.6)
            }

            throw $e;
        }
    }

    private function refuseDuplicateName(string $name, ?int $exceptId): void
    {
        $query = Budget::query()->where('user_group_id', $this->administration()->id)->where('name', $name);
        if (null !== $exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $other = $query->first();
        if (null !== $other) {
            throw MachineException::conflict(
                sprintf('A budget named "%s" already exists (#%d).', $name, $other->id),
                sprintf('Use GET /machine/v1/budgets/%d, or pick another name', $other->id),
                ['existing_id' => (string) $other->id, 'name' => $name],
            );
        }
    }

    private function currencyFor(?string $code): TransactionCurrency
    {
        if (null === $code || '' === trim($code)) {
            return $this->primaryCurrency();
        }
        $currency = TransactionCurrency::query()->where('code', strtoupper(trim($code)))->first();
        if (null === $currency) {
            throw MachineException::notFound(sprintf('No currency with code "%s".', $code), 'GET /machine/v1/currencies lists the currency codes this install knows — or omit currency_code for the primary currency', ['currency_code' => $code]);
        }

        return $currency;
    }

    private function currency(int $id): ?TransactionCurrency
    {
        if (!array_key_exists($id, $this->currencyCache)) {
            $this->currencyCache[$id] = TransactionCurrency::query()->find($id);
        }

        return $this->currencyCache[$id];
    }

    /** A limit or available amount: a decimal string ≥ 0 at the currency's places ("0.00" is a real limit). */
    private function limitAmount(mixed $amount, TransactionCurrency $currency): string
    {
        $value = Money::normalize($amount, (int) $currency->decimal_places, 'amount', (string) $currency->code);
        if (Money::compare($value, '0') < 0) {
            throw MachineException::invalid('amount cannot be negative.', sprintf('Send "amount": "%s" — to remove a limit, DELETE it instead (no limit is not a limit of zero)', Money::abs($value)), ['field' => 'amount']);
        }

        return $value;
    }

    /** @return array<string, list<string>> */
    private static function autoBudgetRules(): array
    {
        return [
            'auto_budget_type'          => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::AUTO_TYPES)],
            'auto_budget_amount'        => ['sometimes', 'nullable'],
            'auto_budget_period'        => ['sometimes', 'nullable', 'string', 'in:'.implode(',', self::AUTO_PERIODS)],
            'auto_budget_currency_code' => ['sometimes', 'nullable', 'string', 'max:51'],
        ];
    }

    /**
     * The auto-budget arguments as BudgetRepository::store()/update() take them.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private function autoBudgetData(array $args, ?Budget $budget): array
    {
        $type     = $args['auto_budget_type'] ?? null;
        $existing = null === $budget ? null : AutoBudget::query()->where('budget_id', $budget->id)->first();
        $touches  = null !== $type || null !== ($args['auto_budget_amount'] ?? null) || null !== ($args['auto_budget_period'] ?? null) || null !== ($args['auto_budget_currency_code'] ?? null);
        if (!$touches) {
            return [];
        }
        if (null === $type) {
            if (null === $existing) {
                throw MachineException::invalid('This budget has no auto-budget.', 'Pass auto_budget_type (reset, rollover or adjusted) with auto_budget_amount and auto_budget_period to create one');
            }
            $type = self::autoTypeName((int) $existing->auto_budget_type);
        }
        if ('none' === $type) {
            return null === $budget ? [] : ['auto_budget_type' => 0];
        }
        $currency = null !== ($args['auto_budget_currency_code'] ?? null)
            ? $this->currencyFor((string) $args['auto_budget_currency_code'])
            : (null !== $existing ? ($this->currency((int) $existing->transaction_currency_id) ?? $this->primaryCurrency()) : $this->primaryCurrency());
        $amount   = $args['auto_budget_amount'] ?? null;
        $period   = $args['auto_budget_period'] ?? null;
        if (null === $existing && (null === $amount || null === $period)) {
            throw MachineException::invalid(
                sprintf('An auto-budget of type "%s" needs auto_budget_amount and auto_budget_period.', $type),
                'Pass "auto_budget_amount": "450.00" and "auto_budget_period": "monthly" (daily, weekly, monthly, quarterly, half_year, yearly)',
                ['fields' => ['auto_budget_amount', 'auto_budget_period']],
            );
        }
        $data     = ['auto_budget_type' => null === $budget ? $type : self::autoTypeValue($type), 'currency_code' => (string) $currency->code];
        if (null !== $amount) {
            $value = Money::normalize($amount, (int) $currency->decimal_places, 'auto_budget_amount', (string) $currency->code);
            if (!Money::isPositive($value)) {
                throw MachineException::invalid('auto_budget_amount must be greater than zero.', 'Send "auto_budget_amount": "450.00" — or auto_budget_type "none" to remove the auto-budget', ['field' => 'auto_budget_amount']);
            }
            $data['auto_budget_amount'] = $value;
        }
        if (null !== $period) {
            $data['auto_budget_period'] = (string) $period;
        }
        if (null === $budget) {
            // store() needs all three keys to create the auto-budget
            $data += ['auto_budget_amount' => null, 'auto_budget_period' => null];
        }

        return $data;
    }

    private static function autoTypeName(int $value): ?string
    {
        return match ($value) {
            AutoBudgetType::AUTO_BUDGET_RESET->value    => 'reset',
            AutoBudgetType::AUTO_BUDGET_ROLLOVER->value => 'rollover',
            AutoBudgetType::AUTO_BUDGET_ADJUSTED->value => 'adjusted',
            default                                     => null,
        };
    }

    private static function autoTypeValue(string $name): int
    {
        return match ($name) {
            'reset'    => AutoBudgetType::AUTO_BUDGET_RESET->value,
            'rollover' => AutoBudgetType::AUTO_BUDGET_ROLLOVER->value,
            'adjusted' => AutoBudgetType::AUTO_BUDGET_ADJUSTED->value,
            default    => 0,
        };
    }

    /**
     * Record the before-images of the rule actions and triggers Firefly's rename cascade will
     * rewrite (BudgetRepository::updateRuleActions / updateRuleTriggers), so undo covers them.
     */
    private function recordRuleCascade(WriteResult $result, string $oldName, string $actionType, string $triggerType): void
    {
        $userId   = $this->operator()->id;
        $actions  = RuleAction::query()->leftJoin('rules', 'rules.id', '=', 'rule_actions.rule_id')->where('rules.user_id', $userId)->where('rule_actions.action_type', $actionType)->where('rule_actions.action_value', $oldName)->get(['rule_actions.*']);
        foreach ($actions as $action) {
            $result->updating($action)->count('rule_actions_renamed');
        }
        $triggers = RuleTrigger::query()->leftJoin('rules', 'rules.id', '=', 'rule_triggers.rule_id')->where('rules.user_id', $userId)->where('rule_triggers.trigger_type', $triggerType)->where('rule_triggers.trigger_value', $oldName)->get(['rule_triggers.*']);
        foreach ($triggers as $trigger) {
            $result->updating($trigger)->count('rule_triggers_renamed');
        }
    }

    /**
     * Record what a notes change will do to $owner's note (update, soft-delete, or create), and
     * return the callback that records a created note once the write has run.
     */
    private function recordNoteChange(WriteResult $result, Budget $owner, ?string $notes): Closure
    {
        if (null === $notes) {
            return static function (): void {};
        }
        $before = Note::query()->where('noteable_type', Budget::class)->where('noteable_id', $owner->id)->first();
        if (null !== $before) {
            '' === trim($notes) ? $result->deleting($before) : $result->updating($before);

            return static function (): void {};
        }

        return static function () use ($result, $owner, $notes): void {
            if ('' === trim($notes)) {
                return;
            }
            $created = Note::query()->where('noteable_type', Budget::class)->where('noteable_id', $owner->id)->first();
            if (null !== $created) {
                $result->created($created);
            }
        };
    }

    private function noteText(Budget $budget): ?string
    {
        $note = Note::query()->where('noteable_type', Budget::class)->where('noteable_id', $budget->id)->first();

        return $note?->text;
    }

    /**
     * Budgets through Firefly's own enrichment and BudgetTransformer (§5.1: the same transformer
     * upstream's /api/v1 uses), flattened, plus `auto_budget_currency_code`.
     *
     * @param iterable<Budget> $budgets
     *
     * @return list<array<string, mixed>>
     */
    private function renderBudgets(iterable $budgets, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $collection  = $budgets instanceof Collection ? $budgets : new Collection($budgets);
        if (0 === $collection->count()) {
            return [];
        }
        $enrichment  = new BudgetEnrichment();
        $enrichment->setUser($this->operator());
        $enrichment->setStart($start?->copy());
        $enrichment->setEnd($end?->copy()->endOfDay());
        $collection  = $enrichment->enrich($collection);

        /** @var BudgetTransformer $transformer */
        $transformer = app(BudgetTransformer::class);
        $out         = [];
        foreach ($collection as $budget) {
            $row = $transformer->transform($budget);
            unset($row['links']);
            $row['auto_budget_currency_code'] = null === $row['auto_budget_type'] ? null : $row['currency_code'];
            // Steam::bcround trims ("450"); the wire carries the currency's places ("450.00")
            if (null !== $row['auto_budget_amount'] && null !== $row['currency_decimal_places']) {
                $row['auto_budget_amount'] = Money::format((string) $row['auto_budget_amount'], (int) $row['currency_decimal_places']);
            }
            if (null !== $row['pc_auto_budget_amount']) {
                $row['pc_auto_budget_amount'] = Money::format((string) $row['pc_auto_budget_amount'], (int) $row['primary_currency_decimal_places']);
            }
            foreach (['spent', 'pc_spent'] as $field) {
                $row[$field] = self::placedSums($row[$field]);
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Firefly's per-currency sums ([{sum, currency_decimal_places, …}]) at each currency's places.
     *
     * @param null|array<int, array<string, mixed>> $sums
     *
     * @return null|list<array<string, mixed>>
     */
    private static function placedSums(?array $sums): ?array
    {
        if (null === $sums) {
            return null;
        }
        $out = [];
        foreach ($sums as $entry) {
            if (is_array($entry) && isset($entry['sum'])) {
                $entry['sum'] = Money::format((string) $entry['sum'], (int) ($entry['currency_decimal_places'] ?? 2));
            }
            $out[] = $entry;
        }

        return $out;
    }

    /** One limit, with what was spent against it (UI's Budgets page: sumExpenses over the limit's own dates). */
    private function limitRow(BudgetLimit $limit, Budget $budget, bool $withSpent = true): array
    {
        $currency = $this->currency((int) $limit->transaction_currency_id) ?? $this->primaryCurrency();
        $places   = (int) $currency->decimal_places;
        $row      = [
            'id'                      => (string) $limit->id,
            'budget_id'               => (string) $budget->id,
            'name'                    => (string) $budget->name,
            'start'                   => $limit->start_date->format('Y-m-d'),
            'end'                     => $limit->end_date->format('Y-m-d'),
            'currency_id'             => (string) $currency->id,
            'currency_code'           => (string) $currency->code,
            'currency_decimal_places' => $places,
            'amount'                  => Money::format((string) $limit->amount, $places),
            'period'                  => '' === (string) $limit->period ? null : (string) $limit->period,
            'generated'               => (bool) $limit->generated,
        ];
        if ($withSpent) {
            $spent        = $this->opsRepo()->sumExpenses($limit->start_date->copy()->startOfDay(), $limit->end_date->copy()->endOfDay(), null, new Collection([$budget]), $currency);
            $raw          = (string) ($spent[$currency->id]['sum'] ?? '0');
            $row['spent'] = Money::format($raw, $places);
            $row['left']  = Money::format(Money::add((string) $limit->amount, $raw), $places);
        }

        return $row;
    }

    /** @return array<string, mixed> */
    private function availableRow(AvailableBudget $available): array
    {
        $currency = $this->currency((int) $available->transaction_currency_id) ?? $this->primaryCurrency();
        $places   = (int) $currency->decimal_places;

        return [
            'id'                      => (string) $available->id,
            'start'                   => $available->start_date->format('Y-m-d'),
            'end'                     => $available->end_date->format('Y-m-d'),
            'currency_id'             => (string) $currency->id,
            'currency_code'           => (string) $currency->code,
            'currency_decimal_places' => $places,
            'amount'                  => Money::format((string) $available->amount, $places),
        ];
    }

    /**
     * Count journals per (group key, currency id). $key null groups everything under 0.
     *
     * @param array<int, array<string, mixed>> $journals
     *
     * @return array<int, array<int, int>>
     */
    private static function countJournals(array $journals, ?string $key): array
    {
        $out = [];
        foreach ($journals as $journal) {
            $group                    = null === $key ? 0 : (int) ($journal[$key] ?? 0);
            $currency                 = (int) ($journal['currency_id'] ?? 0);
            $out[$group][$currency] = ($out[$group][$currency] ?? 0) + 1;
        }

        return $out;
    }

    private function budgetRepo(): BudgetRepositoryInterface
    {
        /** @var BudgetRepositoryInterface $repo */
        $repo = app(BudgetRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function limitRepo(): BudgetLimitRepositoryInterface
    {
        /** @var BudgetLimitRepositoryInterface $repo */
        $repo = app(BudgetLimitRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function availableRepo(): AvailableBudgetRepositoryInterface
    {
        /** @var AvailableBudgetRepositoryInterface $repo */
        $repo = app(AvailableBudgetRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }

    private function opsRepo(): OperationsRepositoryInterface
    {
        /** @var OperationsRepositoryInterface $repo */
        $repo = app(OperationsRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }
}
