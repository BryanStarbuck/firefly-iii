<?php

/*
 * TransactionController.php
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
use FireflyIII\Api\V1\Requests\Models\Transaction\StoreRequest;
use FireflyIII\Api\V1\Requests\Models\Transaction\UpdateRequest;
use FireflyIII\Api\V1\Requests\Models\TransactionLink\StoreRequest as LinkStoreRequest;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Events\Model\TransactionGroup\UpdatedSingleTransactionGroup;
use FireflyIII\Events\Model\Webhook\WebhookMessagesRequestSending;
use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Transactions\BulkEditor;
use FireflyIII\Machine\Transactions\Converter;
use FireflyIII\Machine\Transactions\CsvExport;
use FireflyIII\Machine\Transactions\FireflyForm;
use FireflyIII\Machine\Transactions\GroupRenderer;
use FireflyIII\Machine\Transactions\Snapshot;
use FireflyIII\Machine\Transactions\SplitInput;
use FireflyIII\Machine\Transactions\TransactionFilter;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\Budget;
use FireflyIII\Models\Category;
use FireflyIII\Models\LinkType;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalLink;
use FireflyIII\Repositories\Journal\JournalRepositoryInterface;
use FireflyIII\Repositories\LinkType\LinkTypeRepositoryInterface;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use FireflyIII\Services\Internal\Update\GroupCloneService;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Transactions — apis.mdx §8.3. Firefly's unit of entry is the transaction GROUP (one or more
 * journals, i.e. splits). Every route calls the same Firefly service the UI or upstream's
 * /api/v1 calls (R1), renders with upstream's transformer (§5.1), and every write goes through
 * the plane's write protocol (§7): dry run by default, confirm token, fingerprint, ceiling, undo.
 *
 * Handles: listing, showing, exporting, storing, editing, converting, cloning, bulk-editing,
 * linking and deleting transactions.
 */
final class TransactionController extends MachineController
{
    private const string GROUP_HINT = 'GET /machine/v1/transactions lists them — group ids are the "id" of each entry';

    // ================================================================ reads ===

    /** GET /transactions — groups, filtered by the one filter language, newest first. */
    public function index(Request $request): JsonResponse
    {
        $args      = $this->input($request, TransactionFilter::rules() + self::LIST_RULES, true);
        $params    = $this->listParams($request, ['date' => 'date', 'id' => 'id', 'description' => 'description'], '-date');
        $collector = GroupRenderer::collector($this->operator(), $this->administration());
        $applied   = TransactionFilter::apply($collector, $args, $this->finder());

        // one row per GROUP (a split group is one transaction), ordered and paged here so the
        // order is total and the page never cuts a group in half
        $rows      = [];
        foreach ($collector->getExtractedJournals() as $journal) {
            $id = (int) $journal['transaction_group_id'];
            if (array_key_exists($id, $rows)) {
                continue;
            }
            $title     = (string) ($journal['transaction_group_title'] ?? '');
            $rows[$id] = [
                'id'          => $id,
                'date'        => $journal['date'] instanceof Carbon ? $journal['date']->format('Y-m-d H:i:s') : (string) $journal['date'],
                'description' => '' !== $title ? $title : (string) ($journal['description'] ?? ''),
            ];
        }
        $page      = $this->applyList(array_values($rows), $params);
        $groups    = GroupRenderer::groups(array_map(static fn (array $r): int => $r['id'], $page), $this->operator(), $this->administration());
        $groups    = $this->underByteCap($groups, $params->offset);

        return $this->ok(['transactions' => $groups, 'total' => count($rows), 'filters' => $applied]);
    }

    /**
     * The response byte cap (apis.mdx §15): a page of 5,000 split groups can pass 8 MiB, so the
     * page is cut at the last whole group that fits, and meta says so with the narrowing hint.
     *
     * @param list<array<string, mixed>> $groups
     *
     * @return list<array<string, mixed>>
     */
    private function underByteCap(array $groups, int $offset): array
    {
        $cap   = (int) config('machine.limits.max_body_bytes', 8388608);
        $bytes = strlen(Envelope::encode($groups));
        if ($bytes <= $cap || [] === $groups) {
            return $groups;
        }
        $keep  = count($groups);
        while ($keep > 1 && $bytes > $cap) {
            $keep  = max(1, min($keep - 1, intdiv($keep * $cap * 95, $bytes * 100))); // counts, never amounts
            $bytes = strlen(Envelope::encode(array_slice($groups, 0, $keep)));
        }
        $this->addMeta([
            'truncated'     => true,
            'limit_applied' => $keep,
            'count'         => $keep,
            'next_offset'   => $offset + $keep,
            'hint'          => sprintf('The response was cut at %d transaction groups to stay under %d bytes — narrow it with start/end, an account or a lower limit, and page with offset=%d', $keep, $cap, $offset + $keep),
        ]);

        return array_slice($groups, 0, $keep);
    }

    /** GET /transactions/export — the same filters, as Firefly's own CSV. */
    public function export(Request $request): JsonResponse
    {
        $args      = $this->input($request, TransactionFilter::rules() + ['format' => ['sometimes', 'nullable', 'string', 'in:csv']], true);
        unset($args['format']);
        $collector = GroupRenderer::collector($this->operator(), $this->administration());
        $applied   = TransactionFilter::apply($collector, $args, $this->finder());
        $journals  = array_map(static fn (array $j): int => (int) $j['transaction_journal_id'], $collector->getExtractedJournals());

        $accounts  = new Collection();
        foreach ((array) ($applied['accounts'] ?? []) as $account) {
            $accounts->push(Account::query()->find((int) $account['id']));
        }
        $start     = null === $applied['start'] ? null : Carbon::createFromFormat('Y-m-d', $applied['start'], config('app.timezone'));
        $end       = null === $applied['end'] ? null : Carbon::createFromFormat('Y-m-d', $applied['end'], config('app.timezone'));
        $maxBytes  = (int) config('machine.limits.max_body_bytes', 8388608);
        $result    = CsvExport::export($this->operator(), $this->administration(), $start, $end, $accounts->filter()->values(), $journals, $maxBytes);

        $meta      = ['truncated' => $result['truncated']];
        if ($result['truncated']) {
            $meta['hint'] = sprintf('The CSV was cut at %d rows to stay under %d bytes — narrow it with start/end or an account', $result['rows'], $maxBytes);
        }

        return $this->ok([
            'format'   => 'csv',
            'filename' => sprintf('firefly-transactions-%s.csv', Carbon::now()->format('Y-m-d')),
            'rows'     => $result['rows'],
            'filters'  => $applied,
            'csv'      => $result['csv'],
        ], $meta);
    }

    /** GET /transactions/{group_id} — one group with every split, and its links. */
    public function show(Request $request, string $group_id): JsonResponse
    {
        $this->input($request, [], true);
        $group    = $this->findGroup($group_id);
        $rendered = GroupRenderer::one((int) $group->id, $this->operator(), $this->administration());
        if (null === $rendered) {
            throw MachineException::notFound(sprintf('Transaction group #%d has no visible splits.', $group->id), self::GROUP_HINT, ['group_id' => (string) $group->id]);
        }

        return $this->ok(['transaction' => $rendered, 'links' => GroupRenderer::links($this->journalIds($group))]);
    }

    /** GET /transactions/{group_id}/links — the group's journal links. */
    public function links(Request $request, string $group_id): JsonResponse
    {
        $this->input($request, [], true);
        $group = $this->findGroup($group_id);

        return $this->ok(['group_id' => (string) $group->id, 'links' => GroupRenderer::links($this->journalIds($group))]);
    }

    /** GET /transaction-journals/{journal_id} — one split, with its group. */
    public function showJournal(Request $request, string $journal_id): JsonResponse
    {
        $this->input($request, [], true);
        $journal  = $this->findJournal($journal_id);
        $rendered = GroupRenderer::one((int) $journal->transaction_group_id, $this->operator(), $this->administration());
        $split    = null;
        foreach ((array) ($rendered['transactions'] ?? []) as $candidate) {
            if ((string) ($candidate['transaction_journal_id'] ?? '') === (string) $journal->id) {
                $split = $candidate;
            }
        }
        if (null === $split) {
            throw MachineException::notFound(sprintf('Transaction journal #%d could not be rendered.', $journal->id), self::GROUP_HINT, ['journal_id' => (string) $journal->id]);
        }

        return $this->ok([
            'transaction_journal' => $split,
            'group_id'            => (string) $journal->transaction_group_id,
            'group_title'         => $rendered['group_title'] ?? null,
            'splits_in_group'     => count((array) ($rendered['transactions'] ?? [])),
        ]);
    }

    // =============================================================== writes ===

    /**
     * POST /transactions — store a group through Firefly's own validation, factory, rules and
     * duplicate check; error_if_duplicate_hash is pinned ON (§11.7). A duplicate is `conflict`
     * naming the original group.
     */
    public function store(Request $request): JsonResponse
    {
        $args       = $this->input($request, [
            'transactions'            => ['required', 'array', 'min:1', 'max:500'],
            'group_title'             => ['sometimes', 'nullable', 'string', 'max:1000'],
            'apply_rules'             => ['sometimes', 'boolean'],
            'error_if_duplicate_hash' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('error_if_duplicate_hash', $args) && false === filter_var($args['error_if_duplicate_hash'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)) {
            throw MachineException::invalid(
                'error_if_duplicate_hash cannot be turned off on this plane.',
                'Omit it — Firefly\'s duplicate check is always on here (apis.mdx §11.7); a real duplicate is refused as a conflict naming the original',
                ['field' => 'error_if_duplicate_hash'],
            );
        }
        $user       = $this->operator();
        $admin      = $this->administration();
        $splits     = SplitInput::prepare((array) $args['transactions'], SplitInput::STORE_FIELDS, $user, $admin, $this->finder());
        $applyRules = !array_key_exists('apply_rules', $args) || false !== filter_var($args['apply_rules'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $payload    = array_filter([
            'transactions'            => $splits,
            'group_title'             => $args['group_title'] ?? null,
            'error_if_duplicate_hash' => true,
            'apply_rules'             => $applyRules,
            'fire_webhooks'           => true,
        ], static fn ($v): bool => null !== $v);
        FireflyForm::validated(StoreRequest::class, $payload);

        try {
            return $this->storeWrite($request, $args, $payload, $applyRules);
        } catch (DuplicateTransactionException $e) {
            // Firefly's verdict, rendered as the plane's conflict naming the original (§5.2) —
            // converted here so a routine duplicate is not reported to the admin as an app error
            throw Envelope::fromThrowable($e);
        }
    }

    /**
     * @param array<string, mixed> $args
     * @param array<string, mixed> $payload
     */
    private function storeWrite(Request $request, array $args, array $payload, bool $applyRules): JsonResponse
    {
        $user  = $this->operator();
        $admin = $this->administration();

        return $this->write($request, $args, function (bool $dryRun) use ($payload, $user, $admin, $applyRules): WriteResult {
            $data                            = FireflyForm::validated(StoreRequest::class, $payload)->getAll();
            $data['user']                    = $user;
            $data['user_group']              = $admin;
            $data['error_if_duplicate_hash'] = true;
            $data['apply_rules']             = $applyRules;

            $watermark                       = Snapshot::watermark();
            $before                          = Snapshot::capture([]);

            /** @var TransactionGroupRepositoryInterface $repository */
            $repository                      = app(TransactionGroupRepositoryInterface::class);
            $repository->setUser($user);
            $repository->setUserGroup($admin);
            $group                           = $repository->store($data);

            $result                          = new WriteResult();
            $side                            = Snapshot::record($result, $before, Snapshot::capture(Snapshot::newGroupIds($watermark, $admin)), $watermark, $admin);
            $result->count('created');
            $this->countSide($result, $side);
            $rendered                        = GroupRenderer::one((int) $group->id, $user, $admin);

            return $result->with([
                'group_id'          => (string) $group->id,
                'transactions'      => null === $rendered ? [] : [$rendered],
                'rules_applied'     => $applyRules,
                'created_alongside' => $side,
                'note'              => null === $rendered ? 'Firefly stored the transaction, but a rule removed it again.' : null,
            ] + ($dryRun ? ['ids_are_provisional' => true] : []));
        });
    }

    /**
     * PUT /transactions/{group_id} — edit a group, including re-splitting; rules are NOT re-run
     * unless apply_rules is true (§8.3). Same steps as upstream's UpdateController.
     */
    public function update(Request $request, string $group_id): JsonResponse
    {
        $group    = $this->findGroup($group_id);
        $args     = $this->input($request, [
            'transactions' => ['sometimes', 'array', 'min:1', 'max:500'],
            'group_title'  => ['sometimes', 'nullable', 'string', 'max:1000'],
            'apply_rules'  => ['sometimes', 'boolean'],
        ]);
        if (!array_key_exists('transactions', $args) && !array_key_exists('group_title', $args)) {
            throw MachineException::invalid('Nothing to change.', 'Pass transactions: [{…the fields to change…}] and/or group_title');
        }
        $user     = $this->operator();
        $admin    = $this->administration();
        $existing = [];
        foreach ($group->transactionJournals()->with(['transactionType', 'transactionCurrency'])->get() as $journal) {
            $existing[(int) $journal->id] = $journal;
        }
        $payload  = ['apply_rules' => true === filter_var($args['apply_rules'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE), 'fire_webhooks' => true];
        $kept     = [];
        if (array_key_exists('transactions', $args)) {
            $splits                  = SplitInput::prepare((array) $args['transactions'], SplitInput::UPDATE_FIELDS, $user, $admin, $this->finder(), $existing);
            [$splits, $kept]         = SplitInput::keepUnnamed($splits, $existing);
            $payload['transactions'] = $splits;
        }
        if (array_key_exists('group_title', $args)) {
            $payload['group_title'] = $args['group_title'];
        }
        // Firefly demands a group title whenever the payload has several splits; the kept ones
        // make it so, and the group's own title (unchanged) is the answer
        if (!array_key_exists('group_title', $payload) && count($payload['transactions'] ?? []) > 1 && '' !== (string) $group->title) {
            $payload['group_title'] = (string) $group->title;
        }
        FireflyForm::validated(UpdateRequest::class, $payload, ['transactionGroup' => $group]);
        $groupId  = (int) $group->id;

        return $this->write($request, $args, function (bool $dryRun) use ($payload, $groupId, $user, $admin, $kept): WriteResult {
            $group        = $this->findGroup((string) $groupId);
            $data         = FireflyForm::validated(UpdateRequest::class, $payload, ['transactionGroup' => $group])->getAll();
            $watermark    = Snapshot::watermark();
            $before       = Snapshot::capture([$groupId]);

            /** @var TransactionGroupRepositoryInterface $repository */
            $repository   = app(TransactionGroupRepositoryInterface::class);
            $repository->setUser($user);
            $repository->setUserGroup($admin);
            $oldHash      = $repository->getCompareHash($group);
            $objects      = TransactionGroupEventObjects::collectFromTransactionGroup($group);
            $group        = $repository->update($group, $data);
            $objects->appendFromTransactionGroup($group);
            $newHash      = $repository->getCompareHash($group);
            Preferences::mark();
            $flags                    = new TransactionGroupEventFlags();
            $flags->applyRules        = (bool) ($data['apply_rules'] ?? false);
            $flags->fireWebhooks      = true;
            $flags->recalculateCredit = $oldHash !== $newHash;
            event(new UpdatedSingleTransactionGroup($flags, $objects));
            event(new WebhookMessagesRequestSending());

            $result       = new WriteResult();
            $after        = Snapshot::capture([$groupId]);
            $side         = Snapshot::record($result, $before, $after, $watermark, $admin);
            $result->count([] === $result->touched ? 'unchanged' : 'updated');
            $added        = count(array_diff_key($after[TransactionJournal::class], $before[TransactionJournal::class]));
            $removed      = 0;
            foreach ($after[TransactionJournal::class] as $id => $row) {
                if (null === ($before[TransactionJournal::class][$id]['deleted_at'] ?? null) && null !== ($row['deleted_at'] ?? null)) {
                    ++$removed;
                }
            }
            if ($added > 0) {
                $result->count('splits_added', $added);
            }
            if ($removed > 0) {
                $result->count('splits_removed', $removed);
            }
            $this->countSide($result, $side);
            $result->changeCount = [] === $result->touched ? 0 : 1 + $added + $removed + count($side['accounts']) + count($side['categories']) + count($side['tags']);
            $rendered     = GroupRenderer::one($groupId, $user, $admin);

            return $result->with([
                'group_id'          => (string) $groupId,
                'transactions'      => null === $rendered ? [] : [$rendered],
                'rules_applied'     => $flags->applyRules,
                'created_alongside' => $side,
                'splits_kept'       => array_map('strval', $kept),
                'note'              => [] === $kept
                    ? null
                    : sprintf('Split%s %s not named in transactions[] and kept as %s — a write-tier edit never removes a split; DELETE /machine/v1/transaction-journals/{journal_id} (admin) does.', 1 === count($kept) ? '' : 's', implode(', ', $kept), 1 === count($kept) ? 'it is' : 'they are'),
            ]);
        });
    }

    /** POST /transactions/{group_id}/convert — the UI's "convert" between withdrawal, deposit and transfer. */
    public function convert(Request $request, string $group_id): JsonResponse
    {
        $group   = $this->findGroup($group_id);
        $args    = $this->input($request, [
            'to_type'          => ['required', 'string', 'in:'.implode(',', Converter::TYPES)],
            'source_id'        => ['sometimes', 'nullable', 'regex:/^\d{1,19}$/'],
            'source_name'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_id'   => ['sometimes', 'nullable', 'regex:/^\d{1,19}$/'],
            'destination_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        foreach (['source', 'destination'] as $side) {
            if (null !== ($args[$side.'_id'] ?? null) && null !== ($args[$side.'_name'] ?? null)) {
                throw MachineException::invalid(sprintf('Both %1$s_id and %1$s_name were given.', $side), sprintf('Pass one of %1$s_id or %1$s_name', $side));
            }
        }
        $from    = Converter::typeOf($group);
        if (!in_array($from, Converter::TYPES, true)) {
            throw MachineException::invalid(sprintf('A %s cannot be converted.', $from), 'Only withdrawals, deposits and transfers convert; opening balances and reconciliations are made by their own screens');
        }
        if ($from === $args['to_type']) {
            throw MachineException::invalid(sprintf('Transaction group #%d is already a %s.', $group->id, $from), 'Pick another to_type: '.implode(', ', array_diff(Converter::TYPES, [$from])), ['type' => $from]);
        }
        $user    = $this->operator();
        $admin   = $this->administration();
        $groupId = (int) $group->id;
        $given   = array_intersect_key($args, array_flip(['source_id', 'source_name', 'destination_id', 'destination_name']));

        return $this->write($request, $args, function (bool $dryRun) use ($groupId, $args, $given, $from, $user, $admin): WriteResult {
            $group     = $this->findGroup((string) $groupId);
            $watermark = Snapshot::watermark();
            $before    = Snapshot::capture([$groupId]);
            $converted = Converter::convert($group, (string) $args['to_type'], $given, $user, $admin);
            $result    = new WriteResult();
            $side      = Snapshot::record($result, $before, Snapshot::capture([$groupId]), $watermark, $admin);
            $result->count('converted', $converted);
            $this->countSide($result, $side);
            $rendered  = GroupRenderer::one($groupId, $user, $admin);

            return $result->with([
                'group_id'          => (string) $groupId,
                'from_type'         => $from,
                'to_type'           => $args['to_type'],
                'transactions'      => null === $rendered ? [] : [$rendered],
                'created_alongside' => $side,
            ]);
        });
    }

    /** POST /transactions/{group_id}/clone — the UI's "clone", dated `date` (default today). */
    public function cloneGroup(Request $request, string $group_id): JsonResponse
    {
        $group   = $this->findGroup($group_id);
        $args    = $this->input($request, ['date' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $user    = $this->operator();
        $admin   = $this->administration();
        $groupId = (int) $group->id;
        $date    = null === ($args['date'] ?? null) ? Carbon::today(config('app.timezone')) : Carbon::createFromFormat('Y-m-d', (string) $args['date'], config('app.timezone'))->startOfDay();
        $args['date'] ??= $date->format('Y-m-d'); // pin "today" into the token, so a plan at 23:59 cannot apply tomorrow

        return $this->write($request, $args, function (bool $dryRun) use ($groupId, $date, $user, $admin): WriteResult {
            $original  = $this->findGroup((string) $groupId);
            $watermark = Snapshot::watermark();
            $before    = Snapshot::capture([]);

            /** @var GroupCloneService $service */
            $service   = app(GroupCloneService::class);
            $clone     = $service->cloneGroup($original);
            foreach ($clone->transactionJournals()->get() as $journal) {
                /** @var JournalUpdateService $update */
                $update = app(JournalUpdateService::class);
                $update->setTransactionJournal($journal);
                $update->setData(['date' => $date->copy()]);
                $update->update();
            }
            $clone->refresh();
            $flags             = new TransactionGroupEventFlags();
            $flags->applyRules = false;
            event(new UpdatedSingleTransactionGroup($flags, TransactionGroupEventObjects::collectFromTransactionGroup($clone)));
            Preferences::mark();

            $result    = new WriteResult();
            $side      = Snapshot::record($result, $before, Snapshot::capture(Snapshot::newGroupIds($watermark, $admin)), $watermark, $admin);
            $result->count('created');
            $this->countSide($result, $side);
            $rendered  = GroupRenderer::one((int) $clone->id, $user, $admin);

            return $result->with([
                'group_id'        => (string) $clone->id,
                'cloned_from'     => (string) $groupId,
                'date'            => $date->format('Y-m-d'),
                'transactions'    => null === $rendered ? [] : [$rendered],
            ] + ($dryRun ? ['ids_are_provisional' => true] : []));
        });
    }

    /**
     * POST /transactions/bulk — the UI's bulk edit over journal ids or a filter:
     * set{ category_id|category_name, budget_id|budget_name (null clears), tags_add[], tags_remove[], tags_replace[] }.
     */
    public function bulk(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'journal_ids'   => ['sometimes', 'array', 'min:1', 'max:5000'],
            'journal_ids.*' => ['regex:/^\d{1,19}$/'],
            'filter'        => ['sometimes', 'array'],
            'set'           => ['required', 'array'],
        ]);
        $set  = (array) $args['set'];
        $allowed = ['category_id', 'category_name', 'budget_id', 'budget_name', 'tags_add', 'tags_remove', 'tags_replace'];
        $unknown = array_values(array_diff(array_map('strval', array_keys($set)), $allowed));
        if ([] !== $unknown || [] === $set) {
            throw MachineException::invalid(
                [] === $set ? 'set is empty — nothing to change.' : sprintf('Unknown field%s in set: %s.', 1 === count($unknown) ? '' : 's', implode(', ', $unknown)),
                sprintf('set accepts: %s', implode(', ', $allowed)),
                ['unknown' => $unknown, 'accepted' => $allowed],
            );
        }
        foreach (['tags_add', 'tags_remove', 'tags_replace'] as $field) {
            if (array_key_exists($field, $set) && (!is_array($set[$field]) || !array_is_list($set[$field]) || [] !== array_filter($set[$field], static fn ($t): bool => !is_string($t) || '' === trim($t) || strlen($t) > 1024))) {
                throw MachineException::invalid(sprintf('set.%s must be a list of tag names.', $field), sprintf('Send "%s": ["tax-2026", "reimbursable"]', $field), ['field' => 'set.'.$field]);
            }
        }
        if (array_key_exists('tags_replace', $set) && (array_key_exists('tags_add', $set) || array_key_exists('tags_remove', $set))) {
            throw MachineException::invalid('tags_replace cannot be combined with tags_add or tags_remove.', 'Replace the tags, or add/remove some — not both in one call');
        }
        $plan = [];
        if (array_key_exists('category_id', $set) || array_key_exists('category_name', $set)) {
            $plan['category'] = $this->categoryTarget($set, 'set.');
        }
        if (array_key_exists('budget_id', $set) || array_key_exists('budget_name', $set)) {
            $plan['budget'] = $this->budgetTarget($set, 'set.');
        }
        foreach (['tags_add', 'tags_remove', 'tags_replace'] as $field) {
            if (array_key_exists($field, $set)) {
                $plan[$field] = array_values(array_unique(array_map(static fn (string $t): string => trim($t), $set[$field])));
            }
        }

        return $this->bulkWrite($request, $args, $plan);
    }

    /** POST /transactions/categorize — one category on many journals (ids or a filter). */
    public function categorize(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'journal_ids'   => ['sometimes', 'array', 'min:1', 'max:5000'],
            'journal_ids.*' => ['regex:/^\d{1,19}$/'],
            'filter'        => ['sometimes', 'array'],
            'category_id'   => ['sometimes', 'nullable', 'regex:/^\d{1,19}$/'],
            'category_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (!array_key_exists('category_id', $args) && !array_key_exists('category_name', $args)) {
            throw MachineException::invalid('Which category?', 'Pass category_id or category_name (category_id: null removes the category; so does an empty category_name)', ['field' => 'category_id']);
        }

        return $this->bulkWrite($request, $args, ['category' => $this->categoryTarget($args, '')]);
    }

    /** POST /transactions/set-budget — one budget (or none) on many journals (ids or a filter). */
    public function setBudget(Request $request): JsonResponse
    {
        $args = $this->input($request, [
            'journal_ids'   => ['sometimes', 'array', 'min:1', 'max:5000'],
            'journal_ids.*' => ['regex:/^\d{1,19}$/'],
            'filter'        => ['sometimes', 'array'],
            'budget_id'     => ['sometimes', 'nullable', 'regex:/^\d{1,19}$/'],
            'budget_name'   => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if (!array_key_exists('budget_id', $args) && !array_key_exists('budget_name', $args)) {
            throw MachineException::invalid('Which budget?', 'Pass budget_id or budget_name — or budget_id: null (or an empty budget_name) to remove the budget', ['field' => 'budget_id']);
        }

        return $this->bulkWrite($request, $args, ['budget' => $this->budgetTarget($args, '')]);
    }

    /** POST /transaction-links — link two journals ("relates to", "refunds", "reimburses"…). */
    public function storeLink(Request $request): JsonResponse
    {
        $args     = $this->input($request, [
            'link_type_id'   => ['sometimes', 'nullable', 'regex:/^\d{1,19}$/'],
            'link_type_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'inward_id'      => ['required', 'regex:/^\d{1,19}$/'],
            'outward_id'     => ['required', 'regex:/^\d{1,19}$/'],
            'notes'          => ['sometimes', 'nullable', 'string', 'max:32768'],
        ]);
        $typeId   = $args['link_type_id'] ?? null;
        $typeName = $args['link_type_name'] ?? null;
        if (null === $typeId && null === $typeName) {
            throw MachineException::invalid('Which kind of link?', 'Pass link_type_id or link_type_name (e.g. "Related", "Refund", "Reimbursement") — GET /machine/v1/link-types lists them', ['field' => 'link_type_id']);
        }
        if (null !== $typeId && null !== $typeName) {
            throw MachineException::invalid('Both link_type_id and link_type_name were given.', 'Pass one of link_type_id or link_type_name');
        }

        /** @var LinkType $linkType */
        $linkType = $this->resolve(LinkType::class, (string) ($typeId ?? $typeName), 'name', static fn ($q) => $q);
        $inward   = $this->findJournal((string) $args['inward_id']);
        $outward  = $this->findJournal((string) $args['outward_id']);
        $payload  = array_filter([
            'link_type_id' => (int) $linkType->id,
            'inward_id'    => (int) $inward->id,
            'outward_id'   => (int) $outward->id,
            'notes'        => $args['notes'] ?? null,
        ], static fn ($v): bool => null !== $v);
        FireflyForm::validated(LinkStoreRequest::class, $payload);
        $user     = $this->operator();
        $admin    = $this->administration();

        return $this->write($request, $args, function (bool $dryRun) use ($payload, $user, $admin, $linkType): WriteResult {
            $data              = FireflyForm::validated(LinkStoreRequest::class, $payload)->getAll();
            $data['direction'] = 'inward';

            /** @var LinkTypeRepositoryInterface $repository */
            $repository        = app(LinkTypeRepositoryInterface::class);
            $repository->setUser($user);
            $repository->setUserGroup($admin);
            $inward            = $this->findJournal((string) $payload['inward_id']);
            $outward           = $this->findJournal((string) $payload['outward_id']);
            $link              = $repository->storeLink($data, $inward, $outward);
            if (null === $link) {
                throw MachineException::upstream('Firefly III did not store the link.', 'Check the link type (GET /machine/v1/link-types) and the two journal ids');
            }
            $result            = new WriteResult();
            $result->created($link);
            foreach ($link->notes()->get() as $note) {
                $result->created($note);
            }
            $result->count('created');
            $links             = GroupRenderer::links([(int) $inward->id]);
            $rendered          = null;
            foreach ($links as $candidate) {
                if ((string) $candidate['id'] === (string) $link->id) {
                    $rendered = $candidate;
                }
            }

            return $result->with([
                'link'      => $rendered,
                'link_type' => ['id' => (string) $linkType->id, 'name' => $linkType->name, 'inward' => $linkType->inward, 'outward' => $linkType->outward],
                'reads_as'  => sprintf('#%d %s #%d', $inward->id, $linkType->outward, $outward->id),
            ] + ($dryRun ? ['ids_are_provisional' => true] : []));
        });
    }

    /** DELETE /transaction-links/{id} — remove a link; both transactions survive. */
    public function destroyLink(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, []);
        $link = $this->findLink($id);
        if (null === $link) {
            return $this->gone($request, 'link', $id);
        }
        $linkId = (int) $link->id;

        return $this->write($request, $args, function (bool $dryRun) use ($linkId): WriteResult {
            $link   = $this->findLink((string) $linkId);
            if (null === $link) {
                throw MachineException::conflict('The link was removed while this plan was open.', 'Nothing to do — it is already gone');
            }
            $result = new WriteResult();
            $result->deleting($link);

            /** @var LinkTypeRepositoryInterface $repository */
            $repository = app(LinkTypeRepositoryInterface::class);
            $repository->setUser($this->operator());
            $repository->destroyLink($link);
            $result->count('deleted');

            return $result->with(['deleted' => 1, 'link_id' => (string) $linkId, 'note' => 'The two transactions are untouched; only the link between them is removed.']);
        });
    }

    /** DELETE /transactions/{group_id} (admin) — delete a group; undo restores it. */
    public function destroy(Request $request, string $group_id): JsonResponse
    {
        $args  = $this->input($request, []);
        $group = $this->findGroup($group_id, true);
        if (null !== $group->deleted_at) {
            return $this->gone($request, 'transaction group', (string) $group->id);
        }

        return $this->deleteGroups($request, $args, [(int) $group->id], 0);
    }

    /** DELETE /transaction-journals/{journal_id} (admin) — delete one split. */
    public function destroyJournal(Request $request, string $journal_id): JsonResponse
    {
        $args    = $this->input($request, []);
        $journal = $this->findJournal($journal_id, true);
        if (null !== $journal->deleted_at) {
            return $this->gone($request, 'transaction journal', (string) $journal->id);
        }
        $journalId = (int) $journal->id;
        $groupId   = (int) $journal->transaction_group_id;
        $admin     = $this->administration();

        return $this->write($request, $args, function (bool $dryRun) use ($journalId, $groupId, $admin): WriteResult {
            $journal   = $this->findJournal((string) $journalId);
            $watermark = Snapshot::watermark();
            $before    = Snapshot::capture([$groupId]);

            $siblings  = TransactionJournal::query()->where('transaction_group_id', $groupId)->where('id', '!=', $journalId)->count();
            if (0 === $siblings) {
                // the group's only split: delete the group, as the UI does — never leave an empty group
                /** @var TransactionGroupRepositoryInterface $groups */
                $groups = app(TransactionGroupRepositoryInterface::class);
                $groups->setUser($this->operator());
                $groups->setUserGroup($admin);
                $groups->destroy($journal->transactionGroup);
            }
            if ($siblings > 0) {
                /** @var JournalRepositoryInterface $repository */
                $repository = app(JournalRepositoryInterface::class);
                $repository->setUser($this->operator());
                $repository->destroyJournal($journal);
            }
            Preferences::mark();

            $result    = new WriteResult();
            Snapshot::record($result, $before, Snapshot::capture([$groupId]), $watermark, $admin);
            $result->count('deleted');
            $groupGone = null !== TransactionGroup::withTrashed()->find($groupId)?->deleted_at;

            return $result->with(['deleted' => 1, 'journal_id' => (string) $journalId, 'group_id' => (string) $groupId, 'group_deleted' => $groupGone]);
        });
    }

    /** POST /transactions/mass-delete (admin) — delete many groups in one confirmed write. */
    public function massDelete(Request $request): JsonResponse
    {
        $args    = $this->input($request, [
            'group_ids'   => ['required', 'array', 'min:1', 'max:5000'],
            'group_ids.*' => ['regex:/^\d{1,19}$/'],
        ]);
        $ids     = array_values(array_unique(array_map('intval', $args['group_ids'])));
        $admin   = $this->administration();
        $found   = TransactionGroup::withTrashed()->where('user_group_id', $admin->id)->whereIn('id', $ids)->get(['id', 'deleted_at'])->keyBy('id');
        $missing = array_values(array_diff($ids, $found->keys()->map(static fn ($k): int => (int) $k)->all()));
        if ([] !== $missing) {
            throw MachineException::notFound(sprintf('%d of those transaction groups do not exist in this administration.', count($missing)), self::GROUP_HINT, ['missing' => array_map('strval', array_slice($missing, 0, 100))]);
        }
        $live    = $found->filter(static fn (TransactionGroup $g): bool => null === $g->deleted_at)->keys()->map(static fn ($k): int => (int) $k)->sort()->values()->all();

        return $this->deleteGroups($request, $args, $live, count($ids) - count($live));
    }

    // ============================================================== helpers ===

    /**
     * The shared engine of bulk, categorize and set-budget: select the journals (ids or the
     * filter), then BulkEditor through the write protocol.
     *
     * @param array<string, mixed> $args
     * @param array<string, mixed> $plan
     */
    private function bulkWrite(Request $request, array $args, array $plan): JsonResponse
    {
        $journalIds = $this->selectJournals($args);
        $admin      = $this->administration();
        $ceiling    = max(0, intval($args['max_changes'] ?? config('machine.limits.max_changes_default', 200)));

        return $this->write($request, $args, function (bool $dryRun) use ($journalIds, $plan, $admin, $ceiling): WriteResult {
            // a filter can select tens of thousands of journals: load them in chunks, so no
            // single whereIn overruns the database's bound-parameter limit
            $journals  = new EloquentCollection();
            foreach (array_chunk($journalIds, 1000) as $chunk) {
                $journals = $journals->merge(TransactionJournal::query()->where('user_group_id', $admin->id)->whereIn('id', $chunk)->get());
            }
            $journals  = $journals->sortBy([['date', 'asc'], ['id', 'asc']])->values();
            $groupIds  = array_values(array_unique($journals->map(static fn (TransactionJournal $j): int => (int) $j->transaction_group_id)->all()));
            $watermark = Snapshot::watermark();
            $before    = Snapshot::capture($groupIds);
            // past the ceiling the rest is counted, not written: the plan is refused with the
            // real count either way (§7.1), and the real apply only runs once the count is under it
            $outcome   = BulkEditor::apply($journals, $plan, $dryRun ? $ceiling : null);
            $result    = new WriteResult();
            $side      = Snapshot::record($result, $before, Snapshot::capture($groupIds), $watermark, $admin);
            foreach ($outcome['changes'] as $kind => $n) {
                $result->count($kind, $n);
            }
            $this->countSide($result, $side);
            $result->basis = ['journal_ids' => $journalIds];

            return $result->with([
                'selected'           => count($journalIds),
                'affected'           => $outcome['affected'],
                'affected_truncated' => $outcome['affected_truncated'],
                'created_alongside'  => $side,
            ]);
        });
    }

    /**
     * The journal ids a bulk write targets: journal_ids[] (every one must be the operator's) or
     * filter{} (the GET /transactions language). Never both, never neither, never an empty filter.
     *
     * @param array<string, mixed> $args
     *
     * @return list<int>
     */
    private function selectJournals(array $args): array
    {
        $hasIds    = array_key_exists('journal_ids', $args);
        $hasFilter = array_key_exists('filter', $args);
        if ($hasIds === $hasFilter) {
            throw MachineException::invalid(
                $hasIds ? 'Give journal_ids or filter, not both.' : 'Which transactions? Give journal_ids or filter.',
                'journal_ids: [812, 813] — or filter: {…the same fields as GET /machine/v1/transactions…}; check the selection first with GET /machine/v1/transactions',
            );
        }
        $admin = $this->administration();
        if ($hasIds) {
            $ids     = array_values(array_unique(array_map('intval', (array) $args['journal_ids'])));
            $found   = TransactionJournal::query()->where('user_group_id', $admin->id)->whereIn('id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $missing = array_values(array_diff($ids, $found));
            if ([] !== $missing) {
                throw MachineException::notFound(
                    sprintf('%d of those journal ids are not transactions of this administration.', count($missing)),
                    'journal_ids are split ids ("transaction_journal_id"), not group ids — GET /machine/v1/transactions shows both',
                    ['missing' => array_map('strval', array_slice($missing, 0, 100))],
                );
            }
            sort($ids);

            return $ids;
        }
        $filter    = TransactionFilter::validateObject((array) $args['filter']);
        if (TransactionFilter::isEmpty($filter)) {
            throw MachineException::invalid('An empty filter would select every transaction.', 'Narrow it — start/end, an account, without_category, search… — or pass journal_ids');
        }
        $collector = GroupRenderer::collector($this->operator(), $admin);
        TransactionFilter::apply($collector, $filter, $this->finder());
        $ids       = array_values(array_unique(array_map(static fn (array $j): int => (int) $j['transaction_journal_id'], $collector->getExtractedJournals())));
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $args
     * @param list<int>            $groupIds
     */
    private function deleteGroups(Request $request, array $args, array $groupIds, int $alreadyDeleted): JsonResponse
    {
        $admin = $this->administration();

        return $this->write($request, $args, function (bool $dryRun) use ($groupIds, $alreadyDeleted, $admin): WriteResult {
            $watermark  = Snapshot::watermark();
            $before     = Snapshot::capture($groupIds);

            /** @var TransactionGroupRepositoryInterface $repository */
            $repository = app(TransactionGroupRepositoryInterface::class);
            $repository->setUser($this->operator());
            $repository->setUserGroup($admin);
            $deleted    = 0;
            foreach (TransactionGroup::query()->where('user_group_id', $admin->id)->whereIn('id', $groupIds)->orderBy('id')->get() as $group) {
                $repository->destroy($group);
                ++$deleted;
            }
            Preferences::mark();

            $result     = new WriteResult();
            Snapshot::record($result, $before, Snapshot::capture($groupIds), $watermark, $admin);
            $result->count('deleted', $deleted);
            if ($alreadyDeleted > 0) {
                $result->count('skipped', $alreadyDeleted);
            }

            return $result->with([
                'deleted'         => $deleted,
                'already_deleted' => $alreadyDeleted,
                'group_ids'       => array_map('strval', $groupIds),
                'note'            => 'Deleted transactions keep their duplicate hash: re-importing them is refused as a duplicate. POST /machine/v1/undo restores them.',
            ]);
        });
    }

    /**
     * A DELETE of something already gone: ok, deleted 0 — so a retry loop terminates (§5.6).
     */
    private function gone(Request $request, string $what, string $id): JsonResponse
    {
        return $this->ok(['dry_run' => (bool) ($this->requestBody($request)['dry_run'] ?? true), 'deleted' => 0, 'changes' => ['deleted' => 0], 'change_count' => 0, 'note' => sprintf('The %s #%s is already deleted.', $what, $id)]);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{id: null|int, name: null|string}
     */
    private function categoryTarget(array $source, string $prefix): array
    {
        $id   = $source['category_id'] ?? null;
        $name = $source['category_name'] ?? null;
        $name = is_string($name) && '' !== trim($name) ? trim($name) : null;
        if (null !== $id && null !== $name) {
            throw MachineException::invalid(sprintf('Both %1$scategory_id and %1$scategory_name were given.', $prefix), 'Pass one of category_id or category_name');
        }
        if (null !== $id) {
            /** @var Category $category */
            $category = $this->resolve(Category::class, (string) $id);

            return ['id' => (int) $category->id, 'name' => $category->name];
        }
        if (null === $name) {
            return ['id' => null, 'name' => null]; // clear
        }

        try {
            /** @var Category $category */
            $category = $this->finder()(Category::class, TransactionFilter::nameRef($name), 'name');

            return ['id' => (int) $category->id, 'name' => $category->name];
        } catch (MachineException $e) {
            if ('not_found' !== $e->code()) {
                throw $e;
            }

            // a new category, created the way the UI's bulk edit creates it; the plan reports it
            return ['id' => null, 'name' => $name];
        }
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{id: null|int, name: null|string}
     */
    private function budgetTarget(array $source, string $prefix): array
    {
        $id   = $source['budget_id'] ?? null;
        $name = $source['budget_name'] ?? null;
        $name = is_string($name) && '' !== trim($name) ? trim($name) : null;
        if (null !== $id && null !== $name) {
            throw MachineException::invalid(sprintf('Both %1$sbudget_id and %1$sbudget_name were given.', $prefix), 'Pass one of budget_id or budget_name');
        }
        if (null === $id && null === $name) {
            return ['id' => null, 'name' => null]; // clear
        }

        /** @var Budget $budget */
        $budget = $this->finder()(Budget::class, null !== $id ? (string) $id : TransactionFilter::nameRef($name), 'name');
        if (!$budget->active) {
            throw MachineException::invalid(sprintf('Budget "%s" is inactive.', $budget->name), 'Activate it first, or pick another budget (GET /machine/v1/budgets)', ['budget_id' => (string) $budget->id]);
        }

        return ['id' => (int) $budget->id, 'name' => $budget->name];
    }

    /** @param array{accounts: list<mixed>, categories: list<mixed>, tags: list<mixed>} $side */
    private function countSide(WriteResult $result, array $side): void
    {
        foreach (['accounts' => 'accounts_created', 'categories' => 'categories_created', 'tags' => 'tags_created'] as $key => $kind) {
            if ([] !== $side[$key]) {
                $result->count($kind, count($side[$key]));
            }
        }
    }

    /**
     * The name resolver handed to the filter and the split front door: resolve() for ids and
     * names, plus the rule that a name made only of digits ("4021") is still a NAME.
     *
     * @return Closure(class-string, string, string): Model
     */
    private function finder(): Closure
    {
        return function (string $class, string $ref, string $column = 'name'): Model {
            if (!str_starts_with($ref, "\0name:")) {
                return $this->resolve($class, $ref, $column);
            }
            $name    = substr($ref, 6);
            $admin   = $this->administration();
            $model   = new $class();
            $base    = $class::query()->where($model->qualifyColumn('user_group_id'), $admin->id);
            $matches = (clone $base)->where($model->qualifyColumn($column), $name)->limit(11)->get();
            if (0 === $matches->count()) {
                $matches = (clone $base)->whereRaw(sprintf('LOWER(%s) = ?', $model->qualifyColumn($column)), [mb_strtolower($name)])->limit(11)->get();
            }
            if (1 === $matches->count()) {
                return $matches->first();
            }
            $entity  = strtolower(class_basename($class));
            if (0 === $matches->count()) {
                throw MachineException::notFound(sprintf('No %s named "%s".', $entity, $name), sprintf('List them with the matching GET route, or pass the %s\'s id', $entity), ['name' => $name]);
            }

            throw MachineException::invalid(sprintf('"%s" matches %d %ss.', $name, $matches->count(), $entity), sprintf('Pass the %s\'s id instead — the candidates are in details.candidates', $entity), ['name' => $name, 'candidates' => $matches->take(10)->map(static fn (Model $m): array => ['id' => $m->getKey(), 'name' => (string) $m->getAttribute($column)])->values()->all()]);
        };
    }

    private function findGroup(string $id, bool $withTrashed = false): TransactionGroup
    {
        if (1 !== preg_match('/^\d{1,19}$/', trim($id))) {
            throw MachineException::invalid('A transaction group id is a number.', self::GROUP_HINT, ['group_id' => $id]);
        }
        $query = TransactionGroup::query()->where('user_group_id', $this->administration()->id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        /** @var null|TransactionGroup $group */
        $group = $query->find((int) $id);
        if (null === $group) {
            throw MachineException::notFound(sprintf('No transaction group #%s.', $id), self::GROUP_HINT, ['group_id' => $id]);
        }

        return $group;
    }

    private function findJournal(string $id, bool $withTrashed = false): TransactionJournal
    {
        if (1 !== preg_match('/^\d{1,19}$/', trim($id))) {
            throw MachineException::invalid('A transaction journal id is a number.', 'Journal ids are the "transaction_journal_id" of each split in GET /machine/v1/transactions', ['journal_id' => $id]);
        }
        $query   = TransactionJournal::query()->where('user_group_id', $this->administration()->id);
        if ($withTrashed) {
            $query->withTrashed();
        }

        /** @var null|TransactionJournal $journal */
        $journal = $query->find((int) $id);
        if (null === $journal) {
            throw MachineException::notFound(sprintf('No transaction journal #%s.', $id), 'Journal ids are the "transaction_journal_id" of each split in GET /machine/v1/transactions', ['journal_id' => $id]);
        }

        return $journal;
    }

    private function findLink(string $id): ?TransactionJournalLink
    {
        if (1 !== preg_match('/^\d{1,19}$/', trim($id))) {
            throw MachineException::invalid('A link id is a number.', 'GET /machine/v1/transactions/{group_id}/links lists a transaction\'s links and their ids', ['id' => $id]);
        }

        /** @var null|TransactionJournalLink */
        return TransactionJournalLink::query()
            ->leftJoin('transaction_journals', 'transaction_journals.id', '=', 'journal_links.source_id')
            ->where('transaction_journals.user_group_id', $this->administration()->id)
            ->where('journal_links.id', (int) $id)
            ->first(['journal_links.*'])
        ;
    }

    /** @return list<int> */
    private function journalIds(TransactionGroup $group): array
    {
        return $group->transactionJournals()->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }
}
