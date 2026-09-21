<?php

/*
 * RuleController.php
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
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Helpers\Collector\GroupCollectorInterface;
use FireflyIII\Machine\DryRun;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\Rule;
use FireflyIII\Models\RuleAction;
use FireflyIII\Models\RuleGroup;
use FireflyIII\Models\RuleTrigger;
use FireflyIII\Repositories\Rule\RuleRepositoryInterface;
use FireflyIII\Repositories\RuleGroup\RuleGroupRepositoryInterface;
use FireflyIII\Rules\IsValidActionExpression;
use FireflyIII\Support\Facades\AppConfiguration;
use FireflyIII\Support\Facades\Preferences;
use FireflyIII\TransactionRules\Engine\RuleEngineInterface;
use FireflyIII\Transformers\RuleGroupTransformer;
use FireflyIII\Transformers\RuleTransformer;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * pm/apis.mdx §8.7 — rules and rule groups, the categorisation engine.
 *
 * Reads render with Firefly's own RuleTransformer / RuleGroupTransformer. Writes call
 * RuleRepository / RuleGroupRepository (the same methods upstream's /api/v1 controllers call)
 * through the plane's write protocol. The preview and the run use Firefly's SearchRuleEngine:
 * find() lists the journals a rule matches, fire() applies it — for a preview, inside the
 * rolled-back DryRun harness, so "what each action would change" is what the engine actually did
 * to each journal before the rollback, never a prediction (R4, R7).
 */
final class RuleController extends MachineController
{
    private const string WHERE = 'app/Machine/Http/Controllers/RuleController.php';

    public const array MOMENTS = ['store-journal', 'update-journal', 'manual-activation'];

    /** The fields of a rule a caller may send (Firefly's own rule shape, apis.mdx §8.7). */
    private const array RULE_FIELDS    = ['title', 'description', 'rule_group_id', 'rule_group_title', 'rule_group_name', 'order', 'trigger', 'strict', 'stop_processing', 'active', 'triggers', 'actions'];
    private const array TRIGGER_FIELDS = ['type', 'value', 'prohibited', 'active', 'stop_processing'];
    private const array ACTION_FIELDS  = ['type', 'value', 'active', 'stop_processing'];

    /** The journal fields a rule action can change, compared before and after a (dry) fire. */
    private const array DIFF_FIELDS    = ['type', 'description', 'date', 'amount', 'currency_code', 'foreign_amount', 'foreign_currency_code', 'source_name', 'destination_name', 'category_name', 'budget_name', 'bill_name', 'tags', 'notes'];

    private const int PREVIEW_DEFAULT = 200;

    // ------------------------------------------------------------------ reads ---

    /** GET /rule-groups — rule groups with their rules, in execution order. */
    public function groups(Request $request): JsonResponse
    {
        $this->input($request, self::LIST_RULES, true);
        $params      = $this->listParams($request, ['order', 'title', 'id'], 'order');
        $groups      = RuleGroup::query()->where('user_group_id', $this->administration()->id)->with(['rules' => static fn ($q) => $q->orderBy('order')->orderBy('id')]);
        $rows        = $this->applyList($groups, $params);

        /** @var RuleGroupTransformer $transformer */
        $transformer = app(RuleGroupTransformer::class);
        $out         = [];

        /** @var RuleGroup $group */
        foreach ($rows as $group) {
            $row              = $transformer->transform($group);
            unset($row['links']);
            $row['rule_count'] = $group->rules->count();
            $row['rules']      = $group->rules->map(static fn (Rule $rule): array => [
                'id'              => (string) $rule->id,
                'title'           => $rule->title,
                'order'           => $rule->order,
                'active'          => $rule->active,
                'strict'          => $rule->strict,
                'stop_processing' => $rule->stop_processing,
            ])->values()->all();
            $out[]            = $row;
        }
        $this->addMeta(['untrusted' => ['title', 'description', 'rules.title']]);

        return $this->ok(['rule_groups' => $out]);
    }

    /** GET /rules — rule_group_id|rule_group_name, search, trigger. */
    public function index(Request $request): JsonResponse
    {
        $args   = $this->input($request, [
            'rule_group_id'   => ['sometimes', 'nullable', 'string', 'max:64'],
            'rule_group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'search'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'trigger'         => ['sometimes', 'nullable', 'string', 'in:store-journal,update-journal,manual-activation,manual'],
        ] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['execution' => '_execution', 'title' => 'title', 'id' => 'id', 'order' => 'order'], 'execution');

        $query  = Rule::query()->where('rules.user_group_id', $this->administration()->id)->with(['ruleGroup', 'ruleTriggers', 'ruleActions']);
        $group  = $this->optionalGroup($args['rule_group_id'] ?? null, $args['rule_group_name'] ?? null);
        if (null !== $group) {
            $query->where('rules.rule_group_id', $group->id);
        }
        $search = trim((string) ($args['search'] ?? ''));
        if ('' !== $search) {
            $query->whereRaw("LOWER(rules.title) LIKE ? ESCAPE '\\'", ['%'.self::likeEscape(mb_strtolower($search)).'%']);
        }
        $moment = $args['trigger'] ?? null;
        $moment = 'manual' === $moment ? 'manual-activation' : $moment;

        $rows   = [];
        foreach ($query->get() as $rule) {
            $row = $this->renderRule($rule);
            if (null !== $moment && $row['trigger'] !== $moment) {
                continue;
            }
            $row['_execution'] = sprintf('%09d-%09d-%09d', (int) ($rule->ruleGroup?->order ?? 0), (int) $rule->order, (int) $rule->id);
            $rows[]            = $row;
        }
        $page   = $this->applyList($rows, $params);
        foreach ($page as $i => $row) {
            unset($page[$i]['_execution']);
        }
        $this->addMeta(['untrusted' => ['title', 'description', 'triggers.value', 'actions.value']]);

        return $this->ok(['rules' => array_values($page), 'filter' => ['rule_group_id' => null === $group ? null : (string) $group->id, 'search' => '' === $search ? null : $search, 'trigger' => $moment]]);
    }

    /** GET /rules/{id} — one rule, by id or exact title. */
    public function show(Request $request, string $id): JsonResponse
    {
        $this->input($request, [], true);
        $rule = $this->findRule($id);
        $this->addMeta(['untrusted' => ['title', 'description', 'triggers.value', 'actions.value']]);

        return $this->ok(['rule' => $this->renderRule($rule)]);
    }

    /**
     * POST /rules/validate — validate a rule's triggers, actions and any action expression with
     * Firefly's own validators (the StoreRequest rules + IsValidActionExpression), without saving.
     * An invalid rule is a successful answer: {valid: false, errors: {...}}.
     */
    public function validateRule(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['rule' => ['required', 'array'], 'rule_id' => ['sometimes', 'nullable', 'string', 'max:64']]);
        $target = isset($args['rule_id']) && '' !== (string) $args['rule_id'] ? $this->findRule((string) $args['rule_id']) : null;
        // Firefly's validators read AppConfiguration::get('enable_expression_engine', false), which
        // STORES the default when the row is absent; this is a read (R8), so validate inside the
        // rolled-back harness and nothing is left behind.
        $errors = self::shieldingCache(true, fn (): array => DryRun::run(fn (): array => $this->ruleErrors($args['rule'], null === $target ? 'create' : 'update', $target))->value);

        return $this->ok([
            'valid'   => [] === $errors,
            'errors'  => [] === $errors ? new \stdClass() : $errors,
            'mode'    => null === $target ? 'create' : 'update',
            'rule_id' => null === $target ? null : (string) $target->id,
            'expression_engine' => self::expressionEngineEnabled(),
        ]);
    }

    /**
     * POST /rules/preview — which existing journals a rule (saved: rule_id, or unsaved: rule)
     * would match, and what each action would change. Built on TriggerController::testRule():
     * the same engine, the same date/account operators; the actions are fired for real inside
     * the rolled-back DryRun harness and each journal is compared before and after.
     */
    public function preview(Request $request): JsonResponse
    {
        $args  = $this->input($request, [
            'rule'            => ['sometimes', 'array'],
            'rule_id'         => ['sometimes', 'nullable', 'string', 'max:64'],
        ] + $this->scopeRules() + ['limit' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $hasRule = array_key_exists('rule', $args) && null !== $args['rule'];
        $hasId   = isset($args['rule_id']) && '' !== (string) $args['rule_id'];
        if ($hasRule === $hasId) {
            throw MachineException::invalid('Pass exactly one of rule (an unsaved rule) or rule_id (a saved one).', 'Send {"rule": {...}} to preview a rule before saving it, or {"rule_id": "12"} for an existing one');
        }
        $scope = $this->scope($args);
        $limit = $this->sampleLimit($args['limit'] ?? null);
        $saved = $hasId ? $this->findRule((string) $args['rule_id']) : null;
        $data  = $hasRule ? $this->validRuleData($args['rule'], 'preview', null) : null;

        $held  = self::shieldingCache(true, fn (): \FireflyIII\Machine\DryRunResult => DryRun::run(function () use ($saved, $data, $scope): array {
            $rule = $saved ?? $this->storeRule($data ?? [], true);

            return $this->runEngine(new Collection([$rule]), null, $scope);
        }));

        return $this->ok($this->renderPreview($held->value, $limit, [
            'rule_id'   => null === $saved ? null : (string) $saved->id,
            'saved'     => null !== $saved,
            'active'    => null === $saved ? ($data['active'] ?? true) : (bool) $saved->active,
        ], $held->webhooks));
    }

    /** POST /rule-groups/{id}/preview — the same preview for a whole rule group. */
    public function previewGroup(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, $this->scopeRules() + ['limit' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $group = $this->findGroup($id);
        $scope = $this->scope($args);
        $limit = $this->sampleLimit($args['limit'] ?? null);
        $held  = self::shieldingCache(true, fn (): \FireflyIII\Machine\DryRunResult => DryRun::run(fn (): array => $this->runEngine($this->activeRules($group), $group, $scope)));

        return $this->ok($this->renderPreview($held->value, $limit, ['rule_group_id' => (string) $group->id, 'rule_group_title' => $group->title, 'active' => (bool) $group->active], $held->webhooks));
    }

    // ----------------------------------------------------------------- writes ---

    /** POST /rules — create a rule (write protocol). */
    public function store(Request $request): JsonResponse
    {
        $args = $this->input($request, ['rule' => ['required', 'array']]);
        $data = $this->validRuleData($args['rule'], 'create', null);

        return $this->write($request, $args, function (bool $dryRun) use ($data): WriteResult {
            $result = new WriteResult();
            $specs  = $this->ruleSpecs();
            $before = self::snapshotRows($specs);
            $rule   = $this->storeRule($data, false);
            self::recordRowChanges($result, $specs, $before);
            $result->count('created');
            $this->countReordered($result, $rule->id);

            return $result->with(['rule' => $this->renderRule($rule->refresh())]);
        });
    }

    /** PUT /rules/{id} — edit a rule; triggers/actions, when given, replace the old ones. */
    public function update(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, ['rule' => ['required', 'array']]);
        $rule = $this->findRule($id);
        $data = $this->validRuleData($args['rule'], 'update', $rule);

        return $this->write($request, $args, function (bool $dryRun) use ($rule, $data): WriteResult {
            $result = new WriteResult();
            $specs  = $this->ruleSpecs();
            $before = self::snapshotRows($specs);
            $fresh  = Rule::query()->findOrFail($rule->id);
            $oldGroupId = (int) $fresh->rule_group_id;
            $repo   = $this->ruleRepository();
            $repo->update($fresh, $data);
            if (isset($data['rule_group_id']) && (int) $data['rule_group_id'] !== $oldGroupId) {
                // Firefly renumbers the group the rule moved INTO; close the gap it left behind too
                $repo->resetRuleOrder(RuleGroup::query()->findOrFail($oldGroupId));
            }
            self::recordRowChanges($result, $specs, $before);
            $result->count('updated');
            $this->countReordered($result, $rule->id);

            return $result->with(['rule' => $this->renderRule($fresh->refresh())]);
        });
    }

    /** POST /rules/{id}/move — order, rule_group_id|rule_group_name. */
    public function move(Request $request, string $id): JsonResponse
    {
        $args  = $this->input($request, [
            'order'           => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'rule_group_id'   => ['sometimes', 'nullable', 'string', 'max:64'],
            'rule_group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        $rule  = $this->findRule($id);
        $group = $this->optionalGroup($args['rule_group_id'] ?? null, $args['rule_group_name'] ?? null);
        if (null === $group && !isset($args['order'])) {
            throw MachineException::invalid('Nothing to move: pass order, a rule group, or both.', 'Send {"order": 1} to reorder within the group, or {"rule_group_id": "3"} to move it to another group');
        }
        if (null !== $group && $group->id === $rule->rule_group_id && !isset($args['order'])) {
            throw MachineException::invalid(
                sprintf('Rule #%d is already in rule group "%s".', $rule->id, $group->title),
                'Name a different rule group, or pass order to reorder it within this one',
                ['rule_id' => (string) $rule->id, 'rule_group_id' => (string) $group->id],
            );
        }
        $group ??= $rule->ruleGroup;

        return $this->write($request, $args, function (bool $dryRun) use ($rule, $group, $args): WriteResult {
            $result = new WriteResult();
            $specs  = $this->ruleSpecs(); // resetRuleOrder also renumbers trigger and action rows
            $before = self::snapshotRows($specs);
            $fresh  = Rule::query()->findOrFail($rule->id);
            $target = RuleGroup::query()->findOrFail($group->id);
            $repo   = $this->ruleRepository();
            if ($fresh->rule_group_id !== $target->id) {
                $repo->moveRule($fresh, $target, (int) ($args['order'] ?? ($repo->maxOrder($target) + 1)));
                $repo->resetRuleOrder($target);
                $repo->resetRuleOrder(RuleGroup::query()->findOrFail($rule->rule_group_id));
            }
            if ($fresh->rule_group_id === $target->id && isset($args['order'])) {
                $repo->resetRuleOrder($target);
                $fresh->refresh();
                $repo->setOrder($fresh, (int) $args['order']);
            }
            self::recordRowChanges($result, $specs, $before);
            $moved  = 0;
            $others = 0;
            foreach ($result->touched as $t) {
                if (Rule::class !== $t['class']) {
                    continue;
                }
                if ((int) $t['id'] === $rule->id) {
                    $moved = 1;

                    continue;
                }
                ++$others;
            }
            $result->count('moved', $moved);
            $result->count('reordered', $others);

            return $result->with(['rule' => $this->renderRule($fresh->refresh())]);
        });
    }

    /**
     * POST /rules/run — run rules (rule_ids[]) or a rule group over existing transactions:
     * Firefly's trigger endpoint (TriggerController::triggerRule), previewed through the harness.
     */
    public function run(Request $request): JsonResponse
    {
        $args  = $this->input($request, [
            'rule_ids'        => ['sometimes', 'array', 'max:500'],
            'rule_ids.*'      => ['required', 'string', 'max:64'],
            'rule_names'      => ['sometimes', 'array', 'max:500'],
            'rule_names.*'    => ['required', 'string', 'max:255'],
            'rule_group_id'   => ['sometimes', 'nullable', 'string', 'max:64'],
            'rule_group_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ] + $this->scopeRules());
        $rules = new Collection();
        foreach (array_merge($args['rule_ids'] ?? [], $args['rule_names'] ?? []) as $ref) {
            $rules->push($this->findRule((string) $ref));
        }
        $group = $this->optionalGroup($args['rule_group_id'] ?? null, $args['rule_group_name'] ?? null);
        if ((0 === $rules->count()) === (null === $group)) {
            throw MachineException::invalid('Pass rule_ids (or rule_names) OR a rule group — exactly one.', 'Send {"rule_ids": ["12"]} or {"rule_group_id": "3"}');
        }
        $scope = $this->scope($args);
        $rules = $rules->unique('id')->values();

        return $this->write($request, $args, function (bool $dryRun) use ($rules, $group, $scope): WriteResult {
            $outcome = null === $group
                ? $this->runEngine($rules->map(static fn (Rule $r): Rule => Rule::query()->findOrFail($r->id)), null, $scope)
                : $this->runEngine($this->activeRules(RuleGroup::query()->findOrFail($group->id)), RuleGroup::query()->findOrFail($group->id), $scope);
            $result  = new WriteResult();
            $result->count('updated', $outcome['changed']);
            $result->count('deleted', $outcome['deleted']);
            // Firefly's engine counts every journal an action reported changing (R7). A rule fired
            // later in the run can match a journal an earlier rule just changed, and that journal
            // is not in the matched set listed here — the difference is counted, and it counts
            // against the ceiling, rather than being silently dropped.
            $unlisted = max(0, $outcome['acted'] - $outcome['changed'] - $outcome['deleted']);
            $result->count('unlisted', $unlisted);
            $result->count('matched', $outcome['match_count']);
            $basis   = [['acted', $outcome['acted']]];
            foreach ($outcome['rows'] as $row) {
                if ([] !== $row['changes']) {
                    $basis[] = [$row['journal_id'], $row['changes']];
                }
            }
            $result->basis = $basis;
            $data          = $this->renderPreview($outcome, self::PREVIEW_DEFAULT, [
                'rule_ids'      => null === $group ? $rules->map(static fn (Rule $r): string => (string) $r->id)->all() : null,
                'rule_group_id' => null === $group ? null : (string) $group->id,
            ], null);
            unset($data['preview']);
            $data['engine_changed_count'] = $outcome['acted'];
            $data['unlisted_changed_count'] = $unlisted;
            if ($unlisted > 0) {
                $data['unlisted_note'] = sprintf('Firefly reports %d changed journal(s) that are not in the matches list: a later rule matched a journal an earlier rule had just changed, or an action changed something outside the compared fields (a piggy bank, for instance).', $unlisted);
            }
            $data['reversible'] = false;
            $data['undo_note']  = 'A rule run edits journals, tags and categories through Firefly\'s rule engine; POST /undo cannot reverse it. The matches list is the record of what changed.';
            if ($result->changeCount() > 0) {
                // the operation log refuses an undo of this row instead of "undoing" nothing
                $result->touched[] = ['class' => self::class, 'id' => 0, 'op' => 'irreversible', 'before' => [
                    'what'   => 'rule_run',
                    'did'    => sprintf('changed %d journal(s) through the rule engine', $result->changeCount()),
                    'reason' => 'a rule run edits journals, categories, budgets, tags and notes through Firefly\'s engine, which the operation log does not trace row by row — re-categorise them by hand or with another rule',
                ]];
            }
            if (!$dryRun) {
                Preferences::mark();
            }

            return $result->with($data);
        });
    }

    /** POST /rule-groups — title, description, order, active. */
    public function storeGroup(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'title'       => ['required', 'string', 'min:1', 'max:255', 'uniqueObjectForUser:rule_groups,title'],
            'description' => ['sometimes', 'nullable', 'string', 'max:32768'],
            'order'       => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'active'      => ['sometimes', 'boolean'],
        ]);

        return $this->write($request, $args, function (bool $dryRun) use ($args): WriteResult {
            $result = new WriteResult();
            $specs  = $this->groupSpecs();
            $before = self::snapshotRows($specs);
            $data   = ['title' => $args['title'], 'description' => $args['description'] ?? null];
            if (array_key_exists('active', $args)) {
                $data['active'] = (bool) $args['active'];
            }
            if (isset($args['order'])) {
                $data['order'] = (int) $args['order'];
            }
            $group  = $this->groupRepository()->store($data);
            self::recordRowChanges($result, $specs, $before);
            $result->count('created');
            $result->count('reordered', max(0, count($result->touched) - 1));

            return $result->with(['rule_group' => $this->renderGroup($group->refresh())]);
        });
    }

    /** PUT /rule-groups/{id} — title, description, order, active. */
    public function updateGroup(Request $request, string $id): JsonResponse
    {
        $group = $this->findGroup($id);
        $args  = $this->input($request, [
            'title'       => ['sometimes', 'string', 'min:1', 'max:255', 'uniqueObjectForUser:rule_groups,title,'.$group->id],
            'description' => ['sometimes', 'nullable', 'string', 'max:32768'],
            'order'       => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'active'      => ['sometimes', 'boolean'],
        ]);
        if ([] === array_diff_key($args, self::CONTROL_RULES)) {
            throw MachineException::invalid('Nothing to change.', 'Pass at least one of title, description, order, active');
        }

        return $this->write($request, $args, function (bool $dryRun) use ($group, $args): WriteResult {
            $result = new WriteResult();
            $specs  = $this->groupSpecs();
            $before = self::snapshotRows($specs);
            $fresh  = RuleGroup::query()->findOrFail($group->id);
            $data   = array_intersect_key($args, array_flip(['title', 'description', 'order', 'active']));
            if (array_key_exists('active', $data)) {
                $data['active'] = (bool) $data['active'];
            }
            if (array_key_exists('order', $data)) {
                $data['order'] = (int) $data['order'];
            }
            $this->groupRepository()->update($fresh, $data);
            self::recordRowChanges($result, $specs, $before);
            $self   = 0;
            foreach ($result->touched as $t) {
                $self += (int) ($t['id'] === $group->id || (string) $t['id'] === (string) $group->id);
            }
            $result->count('updated', $self);
            $result->count('reordered', max(0, count($result->touched) - $self));

            return $result->with(['rule_group' => $this->renderGroup($fresh->refresh())]);
        });
    }

    /** DELETE /rules/{id} — admin. A rule already gone is ok with deleted: 0 (§5.6). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, []);
        $rule = $this->findRuleOrGone($id);

        return $this->write($request, $args, function (bool $dryRun) use ($rule, $id): WriteResult {
            $result = new WriteResult();
            $fresh  = null === $rule ? null : Rule::query()->find($rule->id);
            if (null === $fresh) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'rule_id' => $id]);
            }
            $specs  = $this->ruleSpecs();
            $before = self::snapshotRows($specs);
            $this->ruleRepository()->destroy($fresh);
            self::recordRowChanges($result, $specs, $before);

            return $result->count('deleted')->with(['deleted' => 1, 'rule_id' => (string) $fresh->id, 'title' => $fresh->title]);
        });
    }

    /** DELETE /rule-groups/{id} — admin; move_rules_to (id or title) keeps the rules. */
    public function destroyGroup(Request $request, string $id): JsonResponse
    {
        $args   = $this->input($request, ['move_rules_to' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $group  = $this->findGroupOrGone($id);
        $moveTo = isset($args['move_rules_to']) && '' !== (string) $args['move_rules_to'] ? $this->findGroup((string) $args['move_rules_to']) : null;
        if (null !== $group && null !== $moveTo && $moveTo->id === $group->id) {
            throw MachineException::invalid('A rule group cannot move its rules to itself.', 'Pass move_rules_to naming a different rule group, or omit it to delete the rules too');
        }

        return $this->write($request, $args, function (bool $dryRun) use ($group, $moveTo, $id): WriteResult {
            $result = new WriteResult();
            $fresh  = null === $group ? null : RuleGroup::query()->find($group->id);
            if (null === $fresh) {
                return $result->count('deleted', 0)->with(['deleted' => 0, 'rule_group_id' => $id]);
            }
            $rules  = $fresh->rules()->count();
            $specs  = $this->ruleSpecs();
            $before = self::snapshotRows($specs);
            $this->groupRepository()->destroy($fresh, null === $moveTo ? null : RuleGroup::query()->findOrFail($moveTo->id));
            self::recordRowChanges($result, $specs, $before);
            $result->count('deleted');
            if (null === $moveTo) {
                $result->count('rules_deleted', $rules);
            }
            if (null !== $moveTo) {
                $result->count('rules_moved', $rules);
            }

            return $result->with(['deleted' => 1, 'rule_group_id' => (string) $fresh->id, 'title' => $fresh->title, 'rules' => $rules, 'rules_moved_to' => null === $moveTo ? null : (string) $moveTo->id]);
        });
    }

    // ------------------------------------------------------ row-diff recorder ---

    /**
     * Snapshot rows (raw, as the database holds them) for the operation log. Each spec is
     * [ModelClass, fn (): query] in PARENT → CHILD order; soft-deleted rows are included.
     *
     * @param list<array{0: class-string<Model>, 1: Closure(): EloquentBuilder<Model>}> $specs
     *
     * @return array<class-string, array<string, array<string, mixed>>>
     */
    public static function snapshotRows(array $specs): array
    {
        $out = [];
        foreach ($specs as [$class, $query]) {
            /** @var EloquentBuilder<Model> $builder */
            $builder = $query();
            if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
                $builder->withTrashed(); // @phpstan-ignore-line
            }
            $model   = new $class();
            $rows    = [];
            foreach ($builder->toBase()->get() as $row) {
                $row                                         = (array) $row;
                $rows[(string) $row[$model->getKeyName()]] = $row;
            }
            $out[$class] = $rows;
        }

        return $out;
    }

    /**
     * Diff a snapshot taken before a write against the rows now, and record what changed on the
     * WriteResult so POST /undo can reverse it (created → deleted, updated/deleted → the
     * before-image written back). The order makes undo safe for foreign keys: children created
     * are removed before their parents, parents deleted are restored before their children.
     *
     * @param list<array{0: class-string<Model>, 1: Closure(): EloquentBuilder<Model>}> $specs
     * @param array<class-string, array<string, array<string, mixed>>>                 $before
     */
    public static function recordRowChanges(WriteResult $result, array $specs, array $before): void
    {
        $after   = self::snapshotRows($specs);
        $created = [];
        $updated = [];
        $deleted = [];
        foreach ($specs as [$class]) {
            $old = $before[$class] ?? [];
            $new = $after[$class] ?? [];
            foreach ($new as $id => $row) {
                if (!array_key_exists($id, $old)) {
                    $created[] = ['class' => $class, 'id' => self::key($id), 'op' => 'created', 'before' => null];

                    continue;
                }
                if (self::differs($old[$id], $row)) {
                    $updated[] = ['class' => $class, 'id' => self::key($id), 'op' => 'updated', 'before' => $old[$id]];
                }
            }
            foreach ($old as $id => $row) {
                if (!array_key_exists($id, $new)) {
                    array_unshift($deleted, ['class' => $class, 'id' => self::key($id), 'op' => 'deleted', 'before' => $row]);
                }
            }
        }
        foreach (array_merge($deleted, $updated, $created) as $t) {
            $result->touched[] = $t;
        }
    }

    private static function key(int|string $id): int|string
    {
        return is_int($id) || 1 === preg_match('/^\d{1,18}$/', $id) ? (int) $id : $id;
    }

    /**
     * Run $fn with the application cache swapped for a throwaway store during a DRY RUN (or a
     * read that runs the write path rolled back). Firefly's exchange-rate listener calls
     * Cache::clear(): let loose inside a preview it would wipe every outstanding confirm token,
     * the idempotency keys and the write lock — side effects that must not escape a rollback
     * (§7.2). A real apply lets it run, as upstream's /api/v1 does, so Firefly's own cached
     * figures are refreshed.
     *
     * @template T
     *
     * @param Closure(): T $fn
     *
     * @return T
     */
    public static function shieldingCache(bool $dryRun, Closure $fn): mixed
    {
        if (!$dryRun) {
            return $fn();
        }
        $default = (string) config('cache.default');
        $name    = 'machine_dry_run_'.bin2hex(random_bytes(4));
        config([sprintf('cache.stores.%s', $name) => ['driver' => 'array', 'serialize' => false], 'cache.default' => $name]);

        try {
            return $fn();
        } finally {
            config(['cache.default' => $default]);
            Cache::forgetDriver($name);
        }
    }

    /** A search term as LIKE text: "%" and "_" mean themselves (use with `LIKE ? ESCAPE '\'`). */
    public static function likeEscape(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    /**
     * Firefly's expression-engine switch, read WITHOUT storing the default: AppConfiguration::get()
     * with a default writes a configuration row when none exists, which a read route must not (R8).
     */
    public static function expressionEngineEnabled(): bool
    {
        $row = AppConfiguration::get('enable_expression_engine');

        return null !== $row && true === filter_var($row->data, FILTER_VALIDATE_BOOLEAN);
    }

    /** @param array<string, mixed> $a @param array<string, mixed> $b */
    private static function differs(array $a, array $b): bool
    {
        foreach ($a + $b as $k => $_) {
            $x = $a[$k] ?? null;
            $y = $b[$k] ?? null;
            if ((null === $x) !== (null === $y) || (string) (is_scalar($x) ? $x : json_encode($x)) !== (string) (is_scalar($y) ? $y : json_encode($y))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{0: class-string<Model>, 1: Closure(): EloquentBuilder<Model>}> */
    private function ruleSpecs(): array
    {
        $group = $this->administration()->id;
        $ruleIds = static fn () => Rule::withTrashed()->where('user_group_id', $group)->select('id');

        return [
            [RuleGroup::class, static fn (): EloquentBuilder => RuleGroup::query()->where('user_group_id', $group)],
            [Rule::class, static fn (): EloquentBuilder => Rule::query()->where('user_group_id', $group)],
            [RuleTrigger::class, static fn (): EloquentBuilder => RuleTrigger::query()->whereIn('rule_id', $ruleIds())],
            [RuleAction::class, static fn (): EloquentBuilder => RuleAction::query()->whereIn('rule_id', $ruleIds())],
        ];
    }

    /** @return list<array{0: class-string<Model>, 1: Closure(): EloquentBuilder<Model>}> */
    private function groupSpecs(): array
    {
        $group = $this->administration()->id;

        return [[RuleGroup::class, static fn (): EloquentBuilder => RuleGroup::query()->where('user_group_id', $group)]];
    }

    private function countReordered(WriteResult $result, int $ruleId): void
    {
        $n = 0;
        foreach ($result->touched as $t) {
            if (Rule::class === $t['class'] && 'updated' === $t['op'] && (int) $t['id'] !== $ruleId) {
                ++$n;
            }
        }
        $result->count('reordered', $n);
    }

    // ---------------------------------------------------------------- engine ---

    /**
     * Run Firefly's rule engine: find() the matches, fire() the rules (or the group, which honours
     * stop_processing between rules), and compare every matched journal before and after. Called
     * inside DryRun for a preview, for real on an apply.
     *
     * @param Collection<int, Rule>                                          $rules
     * @param array{start: ?Carbon, end: ?Carbon, account_ids: list<int>}     $scope
     *
     * @return array{rows: list<array<string, mixed>>, match_count: int, changed: int, deleted: int, acted: int, inactive_rules: list<string>}
     */
    private function runEngine(Collection $rules, ?RuleGroup $group, array $scope): array
    {
        $finder  = $this->engine($scope);
        $finder->setRules($rules);
        $groups  = $finder->find();

        $journalIds = [];
        foreach ($groups as $g) {
            foreach ($g['transactions'] ?? [] as $journal) {
                $journalIds[(int) $journal['transaction_journal_id']] = (int) ($g['id'] ?? $journal['transaction_group_id'] ?? 0);
            }
        }
        $before  = $this->journalSnapshot(array_keys($journalIds));

        $firer   = $this->engine($scope);
        if (null !== $group) {
            $firer->setRuleGroups(new Collection([$group]));
        }
        if (null === $group) {
            $firer->setRules($rules);
        }
        $firer->fire();
        $after   = $this->journalSnapshot(array_keys($journalIds));

        $rows    = [];
        $changed = 0;
        $deleted = 0;
        foreach ($before as $journalId => $snap) {
            $now     = $after[$journalId] ?? null;
            $changes = [];
            if (null === $now) {
                $changes[] = ['field' => 'journal', 'from' => 'exists', 'to' => 'deleted'];
                ++$deleted;
            }
            if (null !== $now) {
                foreach (self::DIFF_FIELDS as $field) {
                    if ($snap[$field] !== $now[$field]) {
                        $changes[] = ['field' => $field, 'from' => $snap[$field], 'to' => $now[$field]];
                    }
                }
                if ([] !== $changes) {
                    ++$changed;
                }
            }
            $rows[]  = $snap + ['changes' => $changes, 'would_change' => self::describeChanges($changes)];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['date'], $b['journal_id']] <=> [$a['date'], $a['journal_id']]);

        return [
            'rows'           => $rows,
            'match_count'    => count($rows),
            'changed'        => $changed,
            'deleted'        => $deleted,
            'acted'          => $firer->getResults(),
            'inactive_rules' => $rules->filter(static fn (Rule $r): bool => !$r->active)->map(static fn (Rule $r): string => (string) $r->id)->values()->all(),
        ];
    }

    /** @param array{start: ?Carbon, end: ?Carbon, account_ids: list<int>} $scope */
    private function engine(array $scope): RuleEngineInterface
    {
        /** @var RuleEngineInterface $engine */
        $engine = app(RuleEngineInterface::class);
        $engine->setUser($this->operator()); // resets the operators, so it comes first
        if (null !== $scope['start']) {
            $engine->addOperator(['type' => 'date_after', 'value' => $scope['start']->format('Y-m-d')]);
        }
        if (null !== $scope['end']) {
            $engine->addOperator(['type' => 'date_before', 'value' => $scope['end']->format('Y-m-d')]);
        }
        if ([] !== $scope['account_ids']) {
            $engine->addOperator(['type' => 'account_id', 'value' => implode(',', $scope['account_ids'])]);
        }

        return $engine;
    }

    /**
     * The journals as Firefly's GroupCollector renders them, reduced to the fields a rule action
     * can change. Amounts stay decimal strings (the currency's places; positive — direction is
     * the type, §14.1).
     *
     * @param list<int> $journalIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function journalSnapshot(array $journalIds): array
    {
        if ([] === $journalIds) {
            return [];
        }
        $out = [];
        foreach (array_chunk($journalIds, 500) as $chunk) {
            /** @var GroupCollectorInterface $collector */
            $collector = app(GroupCollectorInterface::class);
            $collector->setUser($this->operator())->setJournalIds($chunk)
                ->withAccountInformation()->withCategoryInformation()->withBudgetInformation()
                ->withTagInformation()->withNotes()->withBillInformation()
            ;
            foreach ($collector->getExtractedJournals() as $j) {
                $places = (int) ($j['currency_decimal_places'] ?? 2);
                $tags   = [];
                foreach ($j['tags'] ?? [] as $tag) {
                    $tags[] = (string) ($tag['name'] ?? '');
                }
                sort($tags);
                $foreign = $j['foreign_amount'] ?? null;
                $date    = $j['date'] ?? null;
                $out[(int) $j['transaction_journal_id']] = [
                    'group_id'              => (int) $j['transaction_group_id'],
                    'journal_id'            => (int) $j['transaction_journal_id'],
                    'date'                  => $date instanceof Carbon ? $date->format('Y-m-d') : substr((string) $date, 0, 10),
                    'type'                  => strtolower((string) ($j['transaction_type_type'] ?? '')),
                    'description'           => (string) ($j['description'] ?? ''),
                    'amount'                => Money::format(Money::abs((string) $j['amount']), $places),
                    'currency_code'         => (string) ($j['currency_code'] ?? ''),
                    'foreign_amount'        => null === $foreign || '' === (string) $foreign ? null : Money::format(Money::abs((string) $foreign), (int) ($j['foreign_currency_decimal_places'] ?? 2)),
                    'foreign_currency_code' => $j['foreign_currency_code'] ?? null,
                    'source_name'           => $j['source_account_name'] ?? null,
                    'destination_name'      => $j['destination_account_name'] ?? null,
                    'category_name'         => $j['category_name'] ?? null,
                    'budget_name'           => $j['budget_name'] ?? null,
                    'bill_name'             => $j['bill_name'] ?? null,
                    'tags'                  => $tags,
                    'notes'                 => null === ($j['notes'] ?? null) || '' === (string) $j['notes'] ? null : (string) $j['notes'],
                ];
            }
        }

        return $out;
    }

    /** @param list<array{field: string, from: mixed, to: mixed}> $changes */
    private static function describeChanges(array $changes): string
    {
        $parts = [];
        foreach ($changes as $c) {
            if ('tags' === $c['field']) {
                $from  = (array) $c['from'];
                $to    = (array) $c['to'];
                $delta = array_merge(
                    array_map(static fn (string $t): string => '+'.$t, array_values(array_diff($to, $from))),
                    array_map(static fn (string $t): string => '-'.$t, array_values(array_diff($from, $to))),
                );
                $parts[] = 'tags: '.implode(' ', $delta);

                continue;
            }
            if ('journal' === $c['field']) {
                $parts[] = 'journal deleted';

                continue;
            }
            $parts[] = sprintf('%s: %s → %s', str_replace('_name', '', $c['field']), self::showValue($c['from']), self::showValue($c['to']));
        }

        return implode('; ', $parts);
    }

    private static function showValue(mixed $v): string
    {
        if (null === $v || '' === $v) {
            return '(none)';
        }
        $s = is_scalar($v) ? (string) $v : (string) json_encode($v);

        return mb_strlen($s) > 60 ? mb_substr($s, 0, 57).'…' : $s;
    }

    /**
     * @param array{rows: list<array<string, mixed>>, match_count: int, changed: int, deleted: int, acted: int, inactive_rules: list<string>} $outcome
     * @param array<string, mixed>                                                                                                           $subject
     *
     * @return array<string, mixed>
     */
    private function renderPreview(array $outcome, int $limit, array $subject, ?int $webhooks): array
    {
        $rows      = $outcome['rows'];
        $truncated = count($rows) > $limit;
        if ($truncated) {
            $this->addMeta(['truncated' => true, 'limit_applied' => $limit]);
        }
        $this->addMeta(['untrusted' => ['description', 'notes', 'tags', 'source_name', 'destination_name', 'category_name', 'budget_name', 'would_change']]);
        $data      = [
            'preview'          => true,
            'subject'          => $subject,
            'match_count'      => $outcome['match_count'],
            'would_change_count' => $outcome['changed'] + $outcome['deleted'],
            'unchanged_count'  => $outcome['match_count'] - $outcome['changed'] - $outcome['deleted'],
            'matches'          => array_slice($rows, 0, $limit),
            'truncated_sample' => $truncated,
        ];
        if ([] !== $outcome['inactive_rules']) {
            $data['inactive_rules'] = $outcome['inactive_rules'];
            $data['note']           = 'Inactive rules match but do not act — Firefly skips them when firing; activate the rule to see its actions.';
        }
        if (null !== $webhooks) {
            $data['would_fire_webhooks'] = $webhooks;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function scopeRules(): array
    {
        return [
            'start'           => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'             => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'account_ids'     => ['sometimes', 'array', 'max:500'],
            'account_ids.*'   => ['required', 'string', 'max:64'],
            'account_names'   => ['sometimes', 'array', 'max:500'],
            'account_names.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{start: ?Carbon, end: ?Carbon, account_ids: list<int>}
     */
    private function scope(array $args): array
    {
        $start = isset($args['start']) ? Carbon::createFromFormat('Y-m-d', (string) $args['start'])->startOfDay() : null;
        $end   = isset($args['end']) ? Carbon::createFromFormat('Y-m-d', (string) $args['end'])->startOfDay() : null;
        if (null !== $start && null !== $end && $end->lt($start)) {
            throw MachineException::invalid('end is before start.', sprintf('Swap them: start %s, end %s', $end->format('Y-m-d'), $start->format('Y-m-d')), ['start' => $args['start'], 'end' => $args['end']]);
        }
        $ids   = [];
        foreach (array_merge($args['account_ids'] ?? [], $args['account_names'] ?? []) as $ref) {
            $ids[] = (int) $this->resolve(Account::class, (string) $ref)->id;
        }

        return ['start' => $start, 'end' => $end, 'account_ids' => array_values(array_unique($ids))];
    }

    private function sampleLimit(mixed $limit): int
    {
        $max = (int) config('machine.limits.max_limit', 5000);

        return null === $limit ? self::PREVIEW_DEFAULT : max(1, min($max, (int) $limit));
    }

    /** @return Collection<int, Rule> */
    private function activeRules(RuleGroup $group): Collection
    {
        return $group->rules()->orderBy('rules.order')->orderBy('rules.id')->where('rules.active', true)->get(['rules.*']);
    }

    // ---------------------------------------------------------------- rule data ---

    /**
     * Firefly's rule validation (the /api/v1 StoreRequest / UpdateRequest rules, the
     * ruleTriggerValue / ruleActionValue validators and IsValidActionExpression) applied to a
     * rule object. Returns field => messages, prefixed "rule.".
     *
     * @param array<string, mixed> $rule
     *
     * @return array<string, list<string>>
     */
    private function ruleErrors(array $rule, string $mode, ?Rule $existing): array
    {
        $errors  = [];
        $unknown = array_values(array_diff(array_map('strval', array_keys($rule)), self::RULE_FIELDS));
        if ([] !== $unknown) {
            $errors['rule'] = [sprintf('Unknown rule field%s: %s. A rule takes: %s.', 1 === count($unknown) ? '' : 's', implode(', ', $unknown), implode(', ', self::RULE_FIELDS))];
        }
        foreach (['triggers' => self::TRIGGER_FIELDS, 'actions' => self::ACTION_FIELDS] as $list => $fields) {
            if (!isset($rule[$list]) || !is_array($rule[$list])) {
                continue;
            }
            foreach ($rule[$list] as $i => $item) {
                if (!is_array($item)) {
                    $errors[sprintf('rule.%s.%s', $list, $i)] = ['Each entry must be an object like {"type": …, "value": …}.'];

                    continue;
                }
                $extra = array_values(array_diff(array_map('strval', array_keys($item)), $fields));
                if ([] !== $extra) {
                    $errors[sprintf('rule.%s.%s', $list, $i)] = [sprintf('Unknown field%s: %s. Accepted: %s.', 1 === count($extra) ? '' : 's', implode(', ', $extra), implode(', ', $fields))];
                }
            }
        }
        if ([] !== $errors) {
            return $errors;
        }

        $create   = 'create' === $mode;
        $preview  = 'preview' === $mode;
        $need     = $create || $preview ? 'required' : 'sometimes';
        $titleRules = [$create ? 'required' : 'sometimes', 'bail', 'string', 'min:1', 'max:100'];
        if (!$preview) {
            $titleRules[] = 'uniqueObjectForUser:rules,title'.(null === $existing ? '' : ','.$existing->id);
        }
        $rules    = [
            'title'                      => $titleRules,
            'description'                => ['sometimes', 'nullable', 'string', 'max:32768'],
            'rule_group_id'              => ['sometimes', 'nullable', 'bail', 'integer', 'min:1', 'belongsToUser:rule_groups'],
            'rule_group_title'           => ['sometimes', 'nullable', 'bail', 'string', 'min:1', 'max:255'],
            'rule_group_name'            => ['sometimes', 'nullable', 'bail', 'string', 'min:1', 'max:255'],
            'order'                      => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'trigger'                    => [$create ? 'required' : 'sometimes', 'string', 'in:store-journal,update-journal,manual-activation,manual'],
            'strict'                     => ['sometimes', 'boolean'],
            'stop_processing'            => ['sometimes', 'boolean'],
            'active'                     => ['sometimes', 'boolean'],
            'triggers'                   => [$need, 'array', 'min:1', 'max:100'],
            'triggers.*.type'            => ['required', 'string', 'in:'.implode(',', array_keys((array) config('search.operators')))],
            'triggers.*.value'           => ['sometimes', 'nullable', 'bail', 'string', 'max:1024', 'ruleTriggerValue'],
            'triggers.*.prohibited'      => ['sometimes', 'boolean'],
            'triggers.*.active'          => ['sometimes', 'boolean'],
            'triggers.*.stop_processing' => ['sometimes', 'boolean'],
            'actions'                    => [$need, 'array', 'min:1', 'max:100'],
            'actions.*.type'             => ['required', 'string', 'in:'.implode(',', array_keys((array) config('firefly.rule-actions')))],
            'actions.*.value'            => ['sometimes', 'nullable', 'bail', 'string', 'max:1024', new IsValidActionExpression(), 'ruleActionValue'],
            'actions.*.active'           => ['sometimes', 'boolean'],
            'actions.*.stop_processing'  => ['sometimes', 'boolean'],
        ];
        $validator = Validator::make($rule, $rules);
        $validator->after(function ($v) use ($rule, $create): void {
            if ($create && empty($rule['rule_group_id']) && empty($rule['rule_group_title']) && empty($rule['rule_group_name'])) {
                $v->errors()->add('rule_group_id', 'A rule belongs to a rule group: pass rule_group_id or rule_group_title (GET /machine/v1/rule-groups lists them).');
            }
            foreach (['rule_group_title', 'rule_group_name'] as $field) {
                $title = $rule[$field] ?? null;
                if (!is_string($title) || '' === $title) {
                    continue;
                }

                try {
                    $this->findGroup($title); // exact title, then case-insensitive; ambiguity is an error
                } catch (MachineException $e) {
                    ErrorFile::for(self::WHERE)->expected('resolving the rule group title', $e);
                    $v->errors()->add($field, trim(sprintf('%s %s', $e->getMessage(), (string) $e->hint)));
                }
            }
            foreach (['triggers' => 'trigger', 'actions' => 'action'] as $list => $noun) {
                if (!isset($rule[$list]) || !is_array($rule[$list]) || [] === $rule[$list]) {
                    continue;
                }
                $active = false;
                foreach ($rule[$list] as $i => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    if (false !== filter_var($item['active'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
                        $active = true;
                    }
                    $type   = (string) ($item['type'] ?? '');
                    $needs  = 'triggers' === $list
                        ? (bool) (config(sprintf('search.operators.%s.needs_context', $type)) ?? true)
                        : in_array($type, (array) config('firefly.context-rule-actions'), true);
                    if ($needs && '' === trim((string) ($item['value'] ?? '')) && '' !== $type) {
                        $v->errors()->add(sprintf('%s.%d.value', $list, $i), sprintf('The %s "%s" needs a value.', $noun, $type));
                    }
                }
                if (!$active) {
                    $v->errors()->add(sprintf('%s.0.active', $list), sprintf('At least one %s must be active.', $noun));
                }
            }
        });
        if ($validator->fails()) {
            foreach ($validator->errors()->toArray() as $field => $messages) {
                $errors['rule.'.$field] = array_values(array_map('strval', $messages));
            }
        }

        return $errors;
    }

    /**
     * Validate, then shape the rule the way RuleRepository::store()/update() take it.
     *
     * @param array<string, mixed> $rule
     *
     * @return array<string, mixed>
     */
    private function validRuleData(array $rule, string $mode, ?Rule $existing): array
    {
        $errors = $this->ruleErrors($rule, $mode, $existing);
        if ([] !== $errors) {
            $first = (string) (array_values($errors)[0][0] ?? 'Check the rule');

            throw MachineException::invalid('The rule is not valid.', sprintf('%s — POST /machine/v1/rules/validate explains every field', rtrim($first, '.')), ['fields' => $errors]);
        }
        $bool = static fn (mixed $v): bool => (bool) filter_var($v, FILTER_VALIDATE_BOOLEAN);
        $data = [];
        foreach (['title', 'description'] as $f) {
            if (array_key_exists($f, $rule)) {
                $data[$f] = null === $rule[$f] ? '' : (string) $rule[$f];
            }
        }
        if (!empty($rule['rule_group_id'])) {
            $data['rule_group_id'] = (int) $rule['rule_group_id'];
        }
        $groupTitle = $rule['rule_group_title'] ?? $rule['rule_group_name'] ?? null;
        if (!isset($data['rule_group_id']) && null !== $groupTitle && '' !== (string) $groupTitle) {
            // resolved here, to an id: RuleRepository::update() ignores rule_group_title, and
            // store() would take it — one path for both (exact title first, then case-insensitive)
            $data['rule_group_id'] = (int) $this->findGroup((string) $groupTitle)->id;
        }
        if (isset($rule['order'])) {
            $data['order'] = (int) $rule['order'];
        }
        if (isset($rule['trigger'])) {
            $data['trigger'] = 'manual' === $rule['trigger'] ? 'manual-activation' : (string) $rule['trigger'];
        }
        foreach (['strict', 'stop_processing', 'active'] as $f) {
            if (array_key_exists($f, $rule)) {
                $data[$f] = $bool($rule[$f]);
            }
        }
        if (array_key_exists('triggers', $rule)) {
            $data['triggers'] = array_map(static fn (array $t): array => [
                'type'            => (string) $t['type'],
                'value'           => (string) ($t['value'] ?? ''),
                'prohibited'      => $bool($t['prohibited'] ?? false),
                'active'          => $bool($t['active'] ?? true),
                'stop_processing' => $bool($t['stop_processing'] ?? false),
            ], array_values($rule['triggers']));
        }
        if (array_key_exists('actions', $rule)) {
            $data['actions'] = array_map(static fn (array $a): array => [
                'type'            => (string) $a['type'],
                'value'           => (string) ($a['value'] ?? ''),
                'active'          => $bool($a['active'] ?? true),
                'stop_processing' => $bool($a['stop_processing'] ?? false),
            ], array_values($rule['actions']));
        }

        return $data;
    }

    /**
     * RuleRepository::store(). A preview of an unsaved rule stores it inside the dry run (it is
     * rolled back); with no group named, a throwaway group is made there too.
     *
     * @param array<string, mixed> $data
     */
    private function storeRule(array $data, bool $preview): Rule
    {
        if ($preview) {
            $data['title'] ??= 'Preview (not saved)';
            $data['trigger'] ??= 'manual-activation';
            if (!isset($data['rule_group_id']) && !isset($data['rule_group_title'])) {
                $data['rule_group_id'] = $this->groupRepository()->store(['title' => 'Preview group (not saved) '.bin2hex(random_bytes(4)), 'description' => null, 'active' => true])->id;
            }
        }

        try {
            return $this->ruleRepository()->store($data);
        } catch (FireflyException $e) {
            throw MachineException::invalid('Firefly could not store the rule: '.$e->getMessage(), 'Check rule_group_id / rule_group_title with GET /machine/v1/rule-groups');
        }
    }

    /** @return array<string, mixed> */
    private function renderRule(Rule $rule): array
    {
        /** @var RuleTransformer $transformer */
        $transformer = app(RuleTransformer::class);

        try {
            $row = $transformer->transform($rule);
        } catch (FireflyException $e) {
            ErrorFile::for(self::WHERE)->expected('rendering a rule', $e);
            // a rule without a trigger moment: Firefly refuses to render it; say so rather than fail the list
            $row = ['id' => (string) $rule->id, 'rule_group_id' => (string) $rule->rule_group_id, 'rule_group_title' => (string) $rule->ruleGroup?->title, 'title' => $rule->title, 'description' => $rule->description, 'order' => $rule->order, 'active' => $rule->active, 'strict' => $rule->strict, 'stop_processing' => $rule->stop_processing, 'trigger' => null, 'triggers' => [], 'actions' => [], 'problem' => 'This rule has no trigger moment; edit it in Firefly III to fix it'];
        }
        unset($row['links']);

        return $row;
    }

    /** @return array<string, mixed> */
    private function renderGroup(RuleGroup $group): array
    {
        /** @var RuleGroupTransformer $transformer */
        $transformer = app(RuleGroupTransformer::class);
        $row         = $transformer->transform($group);
        unset($row['links']);
        $row['rule_count'] = $group->rules()->count();

        return $row;
    }

    // ---------------------------------------------------------------- lookups ---

    // Route segments arrive already URL-decoded (Laravel matches the decoded path), so they are
    // never decoded again here: a second urldecode() turns "Coffee + tea" into "Coffee   tea".

    private function findRule(string $idOrTitle): Rule
    {
        /** @var Rule */
        return $this->resolve(Rule::class, $idOrTitle, 'title');
    }

    private function findRuleOrGone(string $idOrTitle): ?Rule
    {
        try {
            return $this->findRule($idOrTitle);
        } catch (MachineException $e) {
            if ('not_found' === $e->code() && 1 === preg_match('/^\d{1,19}$/', trim($idOrTitle))) {
                return null; // a DELETE of something already gone is ok, deleted: 0 (§5.6)
            }

            throw $e;
        }
    }

    private function findGroup(string $idOrTitle): RuleGroup
    {
        /** @var RuleGroup */
        return $this->resolve(RuleGroup::class, $idOrTitle, 'title');
    }

    private function findGroupOrGone(string $idOrTitle): ?RuleGroup
    {
        try {
            return $this->findGroup($idOrTitle);
        } catch (MachineException $e) {
            if ('not_found' === $e->code() && 1 === preg_match('/^\d{1,19}$/', trim($idOrTitle))) {
                return null;
            }

            throw $e;
        }
    }

    private function optionalGroup(?string $id, ?string $name): ?RuleGroup
    {
        if (null !== $id && '' !== $id && null !== $name && '' !== $name) {
            throw MachineException::invalid('Pass rule_group_id or rule_group_name, not both.', 'Drop one of them');
        }
        $ref = null !== $id && '' !== $id ? $id : $name;

        return null === $ref || '' === $ref ? null : $this->findGroup($ref);
    }

    private function ruleRepository(): RuleRepositoryInterface
    {
        /** @var RuleRepositoryInterface $repository */
        $repository = app(RuleRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }

    private function groupRepository(): RuleGroupRepositoryInterface
    {
        /** @var RuleGroupRepositoryInterface $repository */
        $repository = app(RuleGroupRepositoryInterface::class);
        $repository->setUser($this->operator());

        return $repository;
    }
}
