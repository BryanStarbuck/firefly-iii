<?php

/*
 * CategoryController.php
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
use FireflyIII\Machine\Categories\CategoryTree;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\Transactions\GroupRenderer;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Category;
use FireflyIII\Models\Note;
use FireflyIII\Models\RecurrenceTransactionMeta;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Repositories\Category\CategoryRepositoryInterface;
use FireflyIII\Support\Facades\Navigation;
use FireflyIII\Support\JsonApi\Enrichments\CategoryEnrichment;
use FireflyIII\Transformers\CategoryTransformer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Categories — pm/apis.mdx §8.4.
 *
 * Reads render through Firefly's own CategoryEnrichment + CategoryTransformer (the same shape
 * upstream's /api/v1 returns, flattened): spent / earned / transferred per currency in a range,
 * never summed across currencies. Writes go through CategoryRepository (store, update — which
 * cascades a rename into rule triggers, rule actions and recurrences — and destroy).
 *
 * `merge` is a machine addition: every journal (and transaction) of the merged categories is
 * re-pointed to the kept one, rules and recurrences that name them are re-pointed too, then the
 * merged categories are destroyed through Firefly's own destroy service.
 */
final class CategoryController extends MachineController
{
    private const array PERIOD_RULES = [
        'start' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        'end'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
    ];

    /**
     * A pseudo record type for the journal↔category links (a pivot table with no model): they are
     * not in the undo log row by row, so the operation carries one 'irreversible' marker and undo
     * refuses, naming why, rather than half-restoring (§7.5).
     */
    private const string PIVOT_MARKER = 'FireflyIII\Machine\Pivot\CategoryTransactionJournal';

    // ================================================================= reads ===

    /** GET /categories — `search` matches part of the name, case-insensitively. */
    public function index(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['search' => ['sometimes', 'nullable', 'string', 'max:255']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['name' => 'categories.name', 'id' => 'categories.id', 'created_at' => 'categories.created_at'], 'name');
        $query  = Category::query()->where('categories.user_group_id', $this->administration()->id);
        if (isset($args['search']) && '' !== trim((string) $args['search'])) {
            $query->whereRaw('LOWER(categories.name) LIKE ?', ['%'.mb_strtolower(trim((string) $args['search'])).'%']);
        }
        $rows   = $this->applyList($query->select('categories.*'), $params, 'categories.id');

        return $this->ok(['categories' => $this->render($rows)]);
    }

    /**
     * GET /categories/tree — every category as the two-level "Group > Sub" tree (§8.4a), both as
     * structure and as the YAML document the sister apps share. format=yaml keeps the envelope
     * (a plane response is always JSON, §5.1) and carries only the document: data.yaml.
     */
    public function tree(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['format' => ['sometimes', 'nullable', 'string', 'in:json,yaml']], true);
        $format = (string) ($args['format'] ?? 'json');
        $rows   = Category::query()->where('user_group_id', $this->administration()->id)->orderBy('id')->get(['id', 'name'])
            ->map(static fn (Category $c): array => ['id' => (int) $c->id, 'name' => (string) $c->name]);
        $tree   = CategoryTree::build($rows);
        $yaml   = CategoryTree::yaml($tree);
        $this->addMeta(['untrusted' => ['groups[].name', 'groups[].subcategories[].name', 'groups[].subcategories[].full_name', 'yaml']]);

        if ('yaml' === $format) {
            return $this->ok(['app' => CategoryTree::APP, 'format' => 'yaml', 'counts' => $tree['counts'], 'yaml' => $yaml]);
        }

        return $this->ok(['app' => CategoryTree::APP, 'counts' => $tree['counts'], 'groups' => $tree['groups'], 'yaml' => $yaml]);
    }

    /** GET /categories/{id} — one category with spent, earned and transferred in the range, per currency. */
    public function show(Request $request, string $id): JsonResponse
    {
        $args                   = $this->input($request, self::PERIOD_RULES, true);
        [$start, $end, $source] = $this->resolvePeriod($args, false);
        $category               = $this->findCategory($id);

        return $this->ok([
            'category'      => $this->render(new Collection([$category]), $start, $end)[0],
            'start'         => $start->format('Y-m-d'),
            'end'           => $end->format('Y-m-d'),
            'period_source' => $source,
        ]);
    }

    /**
     * GET /categories/{id}/transactions — the category's transaction groups (all time when no range
     * is given), one row per GROUP, in the same shape GET /transactions renders (amounts at the
     * currency's places, §14.1). Only the page is rendered — the collector's light journal rows are
     * paged first, then the page's groups go through Firefly's enrichment and transformer.
     */
    public function transactions(Request $request, string $id): JsonResponse
    {
        $args      = $this->input($request, self::PERIOD_RULES + self::LIST_RULES, true);
        $params    = $this->listParams($request, ['date', 'id'], '-date');
        $category  = $this->findCategory($id);
        $start     = null;
        $end       = null;

        $collector = GroupRenderer::collector($this->operator(), $this->administration());
        $collector->setCategory($category);
        if (null !== ($args['start'] ?? null) || null !== ($args['end'] ?? null)) {
            [$start, $end] = $this->resolvePeriod($args, true);
            $collector->setRange($start->copy(), $end->copy()->endOfDay());
        }
        $rows      = [];
        foreach ($collector->getExtractedJournals() as $journal) {
            $groupId = (int) $journal['transaction_group_id'];
            if (array_key_exists($groupId, $rows)) {
                continue;
            }
            $rows[$groupId] = [
                'id'   => $groupId,
                'date' => ($journal['date'] ?? null) instanceof Carbon ? $journal['date']->format('Y-m-d H:i:s') : (string) ($journal['date'] ?? ''),
            ];
        }
        $page      = $this->applyList(array_values($rows), $params);
        $groups    = GroupRenderer::groups(array_map(static fn (array $row): int => $row['id'], $page), $this->operator(), $this->administration());

        return $this->ok([
            'category_id'   => (string) $category->id,
            'category_name' => (string) $category->name,
            'start'         => $start?->format('Y-m-d'),
            'end'           => $end?->format('Y-m-d'),
            'transactions'  => $groups,
            'total'         => count($rows),
        ]);
    }

    // ================================================================ writes ===

    /** POST /categories — create a category (a name that already exists is a conflict naming it). */
    public function store(Request $request): JsonResponse
    {
        $args  = $this->input($request, ['name' => ['required', 'string', 'min:1', 'max:255'], 'notes' => ['sometimes', 'nullable', 'string', 'max:32768']]);
        $name  = trim((string) $args['name']);
        $notes = isset($args['notes']) ? (string) $args['notes'] : null;
        if ('' === $name) {
            throw MachineException::invalid('name cannot be blank.', 'Pass "name": "Groceries"', ['field' => 'name']);
        }

        return $this->write($request, $args, function (bool $dryRun) use ($name, $notes): WriteResult {
            $this->refuseDuplicateName($name, null);
            $result   = new WriteResult();
            $data     = ['name' => $name];
            if (null !== $notes && '' !== trim($notes)) {
                $data['notes'] = $notes;
            }
            $category = $this->repo()->store($data);
            $result->created($category)->count('created');
            foreach (Note::query()->where('noteable_type', Category::class)->where('noteable_id', $category->id)->get() as $note) {
                $result->created($note);
            }

            return $result->with(['category' => $this->render(new Collection([$category->refresh()]))[0]]);
        });
    }

    /** PUT /categories/{id} — rename (Firefly cascades the new name into rules and recurrences) and/or notes. */
    public function update(Request $request, string $id): JsonResponse
    {
        $args       = $this->input($request, ['name' => ['sometimes', 'string', 'min:1', 'max:255'], 'notes' => ['sometimes', 'nullable', 'string', 'max:32768']]);
        $category   = $this->findCategory($id);
        if (!array_key_exists('name', $args) && !array_key_exists('notes', $args)) {
            throw MachineException::invalid('Nothing to change.', 'Pass "name" and/or "notes"');
        }
        $name       = array_key_exists('name', $args) ? trim((string) $args['name']) : null;
        if ('' === $name) {
            throw MachineException::invalid('name cannot be blank.', 'Pass "name": "Groceries"', ['field' => 'name']);
        }
        $notes      = array_key_exists('notes', $args) ? (string) ($args['notes'] ?? '') : null;
        $categoryId = (int) $category->id;

        return $this->write($request, $args, function (bool $dryRun) use ($categoryId, $name, $notes): WriteResult {
            $category = Category::query()->findOrFail($categoryId);
            $result   = new WriteResult();
            $renamed  = null !== $name && $name !== $category->name;
            if ($renamed) {
                $this->refuseDuplicateName($name, $categoryId);
            }
            $noteBefore = $this->noteText($category);
            $noteChange = null !== $notes && trim($notes) !== (string) $noteBefore;
            if (!$renamed && !$noteChange) {
                $result->count('unchanged');

                return $result->with(['category' => $this->render(new Collection([$category]))[0]]);
            }
            $result->updating($category)->count('updated');
            $data     = [];
            if ($renamed) {
                $this->recordRenameCascade($result, (string) $category->name);
                $data['name'] = $name;
            }
            $after    = static function (): void {};
            if ($noteChange) {
                $after         = $this->recordNoteChange($result, $category, (string) $notes);
                $data['notes'] = $notes;
            }
            $this->repo()->update($category, $data);
            $after();

            return $result->with(['category' => $this->render(new Collection([$category->refresh()]))[0]]);
        });
    }

    /**
     * POST /categories/merge — keep `keep_id`; re-point every journal and transaction of the
     * `merge_ids` categories (and the rules and recurrences that name them) to it; then remove
     * the merged categories through Firefly's destroy service.
     */
    public function merge(Request $request): JsonResponse
    {
        $args     = $this->input($request, [
            'keep_id'   => ['required'],
            'merge_ids' => ['required', 'array', 'min:1', 'max:500'],
            'merge_ids.*' => ['required'],
        ]);
        $keep     = $this->findCategory(self::ref($args['keep_id'], 'keep_id'));
        $mergeIds = [];
        foreach ($args['merge_ids'] as $ref) {
            $category = $this->findCategory(self::ref($ref, 'merge_ids'));
            if ((int) $category->id === (int) $keep->id) {
                throw MachineException::invalid(
                    sprintf('"%s" is both the category to keep and one to merge.', $keep->name),
                    'Remove the kept category from merge_ids',
                    ['keep_id' => (string) $keep->id],
                );
            }
            $mergeIds[(int) $category->id] = (int) $category->id;
        }
        ksort($mergeIds);
        $keepId   = (int) $keep->id;

        return $this->write($request, $args, function (bool $dryRun) use ($keepId, $mergeIds): WriteResult {
            $keep         = Category::query()->findOrFail($keepId);
            $result       = new WriteResult();
            $userId       = $this->operator()->id;
            $merged       = [];
            $journalsMoved = 0;
            $pivotBefore  = [];
            foreach (Category::query()->whereIn('id', array_values($mergeIds))->orderBy('id')->get() as $category) {
                $journalIds = DB::table('category_transaction_journal')->where('category_id', $category->id)->orderBy('transaction_journal_id')->pluck('transaction_journal_id')->map(static fn ($v): int => (int) $v)->all();
                $txIds      = DB::table('category_transaction')->where('category_id', $category->id)->orderBy('transaction_id')->pluck('transaction_id')->map(static fn ($v): int => (int) $v)->all();
                $pivotBefore[] = ['category_id' => (int) $category->id, 'journals' => $journalIds, 'transactions' => $txIds];

                // journals: re-point, dropping the link where the journal already has the kept category
                $already    = DB::table('category_transaction_journal')->where('category_id', $keepId)->whereIn('transaction_journal_id', $journalIds)->pluck('transaction_journal_id')->all();
                DB::table('category_transaction_journal')->where('category_id', $category->id)->whereIn('transaction_journal_id', $already)->delete();
                DB::table('category_transaction_journal')->where('category_id', $category->id)->update(['category_id' => $keepId]);
                $alreadyTx  = DB::table('category_transaction')->where('category_id', $keepId)->whereIn('transaction_id', $txIds)->pluck('transaction_id')->all();
                DB::table('category_transaction')->where('category_id', $category->id)->whereIn('transaction_id', $alreadyTx)->delete();
                DB::table('category_transaction')->where('category_id', $category->id)->update(['category_id' => $keepId]);
                $journalsMoved += count($journalIds);
                if ([] !== $journalIds) {
                    $result->count('journals_repointed', count($journalIds));
                }
                if ([] !== $txIds) {
                    $result->count('transactions_repointed', count($txIds));
                }

                // rules and recurrences that name the merged category now name the kept one
                foreach ($this->ruleActions($userId, (string) $category->name) as $action) {
                    $result->updating($action)->count('rule_actions_repointed');
                    $action->action_value = (string) $keep->name;
                    $action->save();
                }
                foreach ($this->ruleTriggers($userId, (string) $category->name) as $trigger) {
                    $result->updating($trigger)->count('rule_triggers_repointed');
                    $trigger->trigger_value = (string) $keep->name;
                    $trigger->save();
                }
                foreach ($this->recurrenceMeta($userId, $category) as $meta) {
                    $result->updating($meta)->count('recurrences_repointed');
                    $meta->value = 'category_id' === $meta->name ? (string) $keep->id : (string) $keep->name;
                    $meta->save();
                }

                $merged[] = ['id' => (string) $category->id, 'name' => (string) $category->name, 'journals' => count($journalIds)];
                foreach (Note::query()->where('noteable_type', Category::class)->where('noteable_id', $category->id)->get() as $note) {
                    $result->deleting($note);
                }
                $result->deleting($category)->count('deleted');
                $this->repo()->destroy($category);
            }
            if ($journalsMoved > 0) {
                $result->touched[] = ['class' => self::PIVOT_MARKER, 'id' => $keepId, 'op' => 'irreversible', 'before' => [
                    'what'   => 'category links of transaction journals',
                    'did'    => sprintf('re-pointed %d journal(s) to "%s"', $journalsMoved, $keep->name),
                    'reason' => sprintf('%d journal(s) were moved from %d merged categor%s to "%s" (#%d); undo cannot move them back — the re-pointing is final', $journalsMoved, count($merged), 1 === count($merged) ? 'y' : 'ies', $keep->name, $keepId),
                    'links'  => $pivotBefore,
                ]];
            }

            return $result->with([
                'keep'               => ['id' => (string) $keep->id, 'name' => (string) $keep->name],
                'merged'             => $merged,
                'journals_repointed' => $journalsMoved,
                'undoable'           => 0 === $journalsMoved,
                'message'            => 0 === $journalsMoved
                    ? sprintf('%d categor%s merged into "%s".', count($merged), 1 === count($merged) ? 'y' : 'ies', $keep->name)
                    : sprintf('%d categor%s merged into "%s"; %d transaction journal(s) now carry "%s". Undo cannot move journals back — the re-pointing is final.', count($merged), 1 === count($merged) ? 'y' : 'ies', $keep->name, $journalsMoved, $keep->name),
            ]);
        });
    }

    /** DELETE /categories/{id} — ADMIN tier. Journals keep their amounts and lose the category. */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args       = $this->input($request, []);
        $category   = $this->findCategoryOrNull($id);
        $categoryId = null === $category ? null : (int) $category->id;

        return $this->write($request, $args, function (bool $dryRun) use ($categoryId, $id): WriteResult {
            $result   = new WriteResult();
            $category = null === $categoryId ? null : Category::query()->find($categoryId);
            if (null === $category) {
                return $result->with(['deleted' => 0, 'id' => $id, 'message' => 'No such category — nothing to delete (already gone?).']);
            }
            $journals = DB::table('category_transaction_journal')->where('category_id', $category->id)->count();
            $rendered = $this->render(new Collection([$category]))[0];
            foreach (Note::query()->where('noteable_type', Category::class)->where('noteable_id', $category->id)->get() as $note) {
                $result->deleting($note);
            }
            if ($journals > 0) {
                $result->touched[] = ['class' => self::PIVOT_MARKER, 'id' => (int) $category->id, 'op' => 'irreversible', 'before' => [
                    'what'   => 'category links of transaction journals',
                    'did'    => sprintf('removed the category from %d journal(s)', $journals),
                    'reason' => sprintf('%d journal(s) lost category "%s" (#%d); undo cannot restore those links', $journals, $category->name, $category->id),
                ]];
            }
            // Firefly's CategoryDestroyService hard-deletes the recurring transactions' `category_id`
            // meta rows (every user's — it does not scope them); record them so undo re-inserts them
            $recurrences = 0;
            foreach (RecurrenceTransactionMeta::query()->where('name', 'category_id')->where('value', (string) $category->id)->orderBy('id')->get() as $meta) {
                $result->deleting($meta)->count('recurrences_unlinked');
                ++$recurrences;
            }
            $result->deleting($category)->count('deleted');
            $this->repo()->destroy($category);

            return $result->with([
                'deleted'               => 1,
                'category'              => $rendered,
                'journals_uncategorized' => $journals,
                'recurrences_unlinked'  => $recurrences,
                'undoable'              => 0 === $journals,
                'message'               => 0 === $journals
                    ? sprintf('Category "%s" deleted.', $category->name)
                    : sprintf('Category "%s" deleted; %d transaction(s) keep their amounts and now have no category. Undo cannot restore those links.', $category->name, $journals),
            ]);
        });
    }

    // =============================================================== helpers ===

    /**
     * Both start and end (inclusive), or neither — the operator's current viewRange period.
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
                throw MachineException::invalid('start and end are required.', 'Pass start and end as YYYY-MM-DD (both inclusive)', ['fields' => ['start', 'end']]);
            }
            $range = Navigation::getViewRange(true);
            $start = Navigation::startOfPeriod(Carbon::today($tz), $range)->startOfDay();
            $end   = Navigation::endOfPeriod($start->copy(), $range)->startOfDay();

            return [$start, $end, 'viewRange '.$range];
        }
        if (null === $s || '' === $s || null === $e || '' === $e) {
            throw MachineException::invalid('Give both start and end, or neither.', 'Pass start AND end as YYYY-MM-DD (inclusive) — or neither, for the current period', ['start' => $s, 'end' => $e]);
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

    /** An id-or-name argument: a string or an integer, never a list or an object. */
    private static function ref(mixed $value, string $field): string
    {
        if (!is_string($value) && !is_int($value)) {
            throw MachineException::invalid(sprintf('%s must be a category id or name.', $field), sprintf('Pass "%s" as "12" or "Groceries"', $field), ['field' => $field]);
        }

        return (string) $value;
    }

    /** Laravel hands route parameters already URL-decoded; decoding again would turn "A+B" into "A B". */
    private function findCategory(string $idOrName): Category
    {
        /** @var Category */
        return $this->resolve(Category::class, $idOrName);
    }

    private function findCategoryOrNull(string $idOrName): ?Category
    {
        try {
            return $this->findCategory($idOrName);
        } catch (MachineException $e) {
            if ('not_found' === $e->code() && 1 === preg_match('/^\d{1,19}$/', trim($idOrName))) {
                return null; // a DELETE of something already gone is ok: true, deleted: 0 (§5.6)
            }

            throw $e;
        }
    }

    private function refuseDuplicateName(string $name, ?int $exceptId): void
    {
        $query = Category::query()->where('user_group_id', $this->administration()->id)->where('name', $name);
        if (null !== $exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        $other = $query->first();
        if (null !== $other) {
            throw MachineException::conflict(
                sprintf('A category named "%s" already exists (#%d).', $name, $other->id),
                sprintf('Use GET /machine/v1/categories/%d — or POST /machine/v1/categories/merge to fold one category into another', $other->id),
                ['existing_id' => (string) $other->id, 'name' => $name],
            );
        }
    }

    /** Record the rows Firefly's rename cascade (CategoryUpdateService) will rewrite, so undo covers them. */
    private function recordRenameCascade(WriteResult $result, string $oldName): void
    {
        $userId = $this->operator()->id;
        foreach ($this->ruleActions($userId, $oldName) as $action) {
            $result->updating($action)->count('rule_actions_renamed');
        }
        foreach ($this->ruleTriggers($userId, $oldName) as $trigger) {
            $result->updating($trigger)->count('rule_triggers_renamed');
        }
        $metas = RecurrenceTransactionMeta::query()
            ->leftJoin('recurrences_transactions', 'rt_meta.rt_id', '=', 'recurrences_transactions.id')
            ->leftJoin('recurrences', 'recurrences.id', '=', 'recurrences_transactions.recurrence_id')
            ->where('recurrences.user_id', $userId)->where('rt_meta.name', 'category_name')->where('rt_meta.value', $oldName)
            ->get(['rt_meta.*'])
        ;
        foreach ($metas as $meta) {
            $result->updating($meta)->count('recurrences_renamed');
        }
    }

    /** @return Collection<int, RuleAction> */
    private function ruleActions(int $userId, string $name): Collection
    {
        return RuleAction::query()->leftJoin('rules', 'rules.id', '=', 'rule_actions.rule_id')
            ->where('rules.user_id', $userId)->where('rule_actions.action_type', 'set_category')->where('rule_actions.action_value', $name)
            ->orderBy('rule_actions.id')->get(['rule_actions.*'])
        ;
    }

    /** @return Collection<int, RuleTrigger> */
    private function ruleTriggers(int $userId, string $name): Collection
    {
        return RuleTrigger::query()->leftJoin('rules', 'rules.id', '=', 'rule_triggers.rule_id')
            ->where('rules.user_id', $userId)->where('rule_triggers.trigger_type', 'category_is')->where('rule_triggers.trigger_value', $name)
            ->orderBy('rule_triggers.id')->get(['rule_triggers.*'])
        ;
    }

    /** Recurring-transaction meta rows that name $category by id or by name. @return Collection<int, RecurrenceTransactionMeta> */
    private function recurrenceMeta(int $userId, Category $category): Collection
    {
        return RecurrenceTransactionMeta::query()
            ->leftJoin('recurrences_transactions', 'rt_meta.rt_id', '=', 'recurrences_transactions.id')
            ->leftJoin('recurrences', 'recurrences.id', '=', 'recurrences_transactions.recurrence_id')
            ->where('recurrences.user_id', $userId)
            ->where(static function ($q) use ($category): void {
                $q->where(static fn ($a) => $a->where('rt_meta.name', 'category_id')->where('rt_meta.value', (string) $category->id))
                    ->orWhere(static fn ($b) => $b->where('rt_meta.name', 'category_name')->where('rt_meta.value', (string) $category->name))
                ;
            })
            ->orderBy('rt_meta.id')->get(['rt_meta.*'])
        ;
    }

    /** Record what a notes change will do to the category's note; returns the after-write callback for a created note. */
    private function recordNoteChange(WriteResult $result, Category $owner, string $notes): Closure
    {
        $before = Note::query()->where('noteable_type', Category::class)->where('noteable_id', $owner->id)->first();
        if (null !== $before) {
            '' === trim($notes) ? $result->deleting($before) : $result->updating($before);

            return static function (): void {};
        }

        return static function () use ($result, $owner, $notes): void {
            if ('' === trim($notes)) {
                return;
            }
            $created = Note::query()->where('noteable_type', Category::class)->where('noteable_id', $owner->id)->first();
            if (null !== $created) {
                $result->created($created);
            }
        };
    }

    private function noteText(Category $category): ?string
    {
        return Note::query()->where('noteable_type', Category::class)->where('noteable_id', $category->id)->value('text');
    }

    /**
     * Categories through Firefly's CategoryEnrichment + CategoryTransformer, flattened (§5.1).
     *
     * @param iterable<Category> $categories
     *
     * @return list<array<string, mixed>>
     */
    private function render(iterable $categories, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $collection  = $categories instanceof Collection ? $categories : new Collection($categories);
        if (0 === $collection->count()) {
            return [];
        }
        $enrichment  = new CategoryEnrichment();
        $enrichment->setUser($this->operator());
        $enrichment->setStart($start?->copy());
        $enrichment->setEnd($end?->copy()->endOfDay());
        $collection  = $enrichment->enrich($collection);

        /** @var CategoryTransformer $transformer */
        $transformer = app(CategoryTransformer::class);
        $out         = [];
        foreach ($collection as $category) {
            $row = $transformer->transform($category);
            unset($row['links']);
            $row['id'] = (string) $row['id'];
            // Steam::bcround trims ("12.5"); the wire carries each currency's places ("12.50")
            foreach (['spent', 'pc_spent', 'earned', 'pc_earned', 'transferred', 'pc_transferred'] as $field) {
                if (is_array($row[$field] ?? null)) {
                    $row[$field] = array_map(static function (mixed $entry): mixed {
                        if (is_array($entry) && isset($entry['sum'])) {
                            $entry['sum'] = Money::format((string) $entry['sum'], (int) ($entry['currency_decimal_places'] ?? 2));
                        }

                        return $entry;
                    }, array_values($row[$field]));
                }
            }
            $out[]     = $row;
        }

        return $out;
    }

    private function repo(): CategoryRepositoryInterface
    {
        /** @var CategoryRepositoryInterface $repo */
        $repo = app(CategoryRepositoryInterface::class);
        $repo->setUser($this->operator());

        return $repo;
    }
}
