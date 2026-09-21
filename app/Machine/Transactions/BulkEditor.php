<?php

/*
 * BulkEditor.php
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
use FireflyIII\Machine\Money;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Services\Internal\Update\JournalUpdateService;
use FireflyIII\Support\Facades\Preferences;
use Illuminate\Database\Eloquent\Collection;

/**
 * The UI's bulk edit (upstream's Http\Controllers\Transaction\BulkController::update()) over a
 * list of journals: category, budget and tags per journal through JournalUpdateService, then ONE
 * UpdatedSingleTransactionGroup event over every changed group so Firefly's listeners run once.
 *
 * Unlike the UI it skips journals that would not change (so the counts are true) and reports,
 * per journal, what it has now and what it would get. Rules are not re-run (an edit — apis.mdx
 * §8.3), exactly as the operator's hand edits in the UI's bulk form do not re-run them either.
 */
final class BulkEditor
{
    /** How many affected rows a response lists; the counts are always complete. */
    public const int AFFECTED_ROWS = 500;

    /**
     * @param Collection<int, TransactionJournal> $journals
     * @param array{category?: array{id: null|int, name: null|string}, budget?: array{id: null|int, name: null|string}, tags_add?: list<string>, tags_remove?: list<string>, tags_replace?: list<string>} $set
     *                                                       category/budget with id null and name null = clear it
     *
     * @param null|int $applyLimit the caller's max_changes ceiling: once more journals than this
     *                             would change, the rest are counted but not written — the count
     *                             is the real one either way, and a plan over the ceiling is refused
     *                             (apis.mdx §7.1), so writing rows past it only burns time in a
     *                             transaction that will be rolled back
     *
     * @return array{changes: array<string, int>, affected: list<array<string, mixed>>, affected_truncated: bool, changed_group_ids: list<int>, applied: int}
     */
    public static function apply(Collection $journals, array $set, ?int $applyLimit = null): array
    {
        $changes  = ['updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $affected = [];
        $groups   = [];
        $applied  = 0;

        // one query per relation for the whole selection, never one per journal
        $journals->load(['transactionType', 'categories', 'budgets', 'tags', 'transactions.account', 'transactions.transactionCurrency']);

        foreach ($journals as $journal) {
            $type          = (string) $journal->transactionType?->type;
            $category      = $journal->categories->first();
            $budget        = $journal->budgets->first();
            $tags          = $journal->tags->pluck('tag')->map(static fn ($t): string => (string) $t)->all();
            $data          = [];
            $row           = self::row($journal);
            $skipReason    = null;

            if (array_key_exists('category', $set)) {
                $wantId   = $set['category']['id'];
                $wantName = $set['category']['name'];
                $same     = null !== $wantId ? (int) $category?->id === $wantId : (null === $wantName ? null === $category : null !== $category && $category->name === $wantName);
                if (!$same) {
                    $data += null !== $wantId ? ['category_id' => $wantId] : (null === $wantName ? ['category_id' => 0, 'category_name' => ''] : ['category_name' => $wantName]);
                }
                $row['new_category_name'] = $wantName;
            }
            if (array_key_exists('budget', $set)) {
                $wantId = $set['budget']['id'];
                if (TransactionTypeEnum::WITHDRAWAL->value !== $type && null !== $wantId) {
                    $skipReason = 'budgets apply to withdrawals only';
                }
                if (null === $skipReason && (int) $budget?->id !== (int) $wantId) {
                    $data += ['budget_id' => $wantId ?? 0];
                }
                $row['new_budget_name'] = null === $wantId ? null : ($set['budget']['name'] ?? null);
            }
            $newTags       = $tags;
            if (array_key_exists('tags_replace', $set)) {
                $newTags = array_values(array_unique($set['tags_replace']));
            }
            if (array_key_exists('tags_add', $set)) {
                $newTags = array_values(array_unique([...$newTags, ...$set['tags_add']]));
            }
            if (array_key_exists('tags_remove', $set)) {
                $newTags = array_values(array_diff($newTags, $set['tags_remove']));
            }
            $sortedOld     = $tags;
            $sortedNew     = $newTags;
            sort($sortedOld);
            sort($sortedNew);
            if ($sortedOld !== $sortedNew) {
                $data['tags'] = $newTags;
            }
            if (array_key_exists('tags_replace', $set) || array_key_exists('tags_add', $set) || array_key_exists('tags_remove', $set)) {
                $row['new_tags'] = $newTags;
            }

            if (null !== $skipReason && [] === array_diff_key($data, ['budget_id' => true])) {
                ++$changes['skipped'];
                $row['outcome'] = 'skipped: '.$skipReason;
            } elseif ([] === $data) {
                ++$changes['unchanged'];
                $row['outcome'] = 'unchanged';
            } else {
                ++$changes['updated'];
                $row['outcome'] = 'updated';
                if (null === $applyLimit || $applied < $applyLimit) {
                    /** @var JournalUpdateService $service */
                    $service = app(JournalUpdateService::class);
                    $service->setTransactionJournal($journal);
                    $service->setData($data);
                    $service->update();
                    ++$applied;
                    $groups[(int) $journal->transaction_group_id] = true;
                }
            }
            if (count($affected) < self::AFFECTED_ROWS) {
                $affected[] = $row;
            }
        }

        $changedGroupIds = array_map('intval', array_keys($groups));
        if ([] !== $changedGroupIds) {
            $objects           = new TransactionGroupEventObjects();
            foreach (TransactionGroup::query()->whereIn('id', $changedGroupIds)->get() as $group) {
                $objects->appendFromTransactionGroup($group);
            }
            $flags             = new TransactionGroupEventFlags();
            $flags->applyRules = false;
            event(new UpdatedSingleTransactionGroup($flags, $objects));
            event(new WebhookMessagesRequestSending());
            Preferences::mark();
        }

        return [
            'changes'            => $changes,
            'affected'           => $affected,
            'affected_truncated' => $journals->count() > self::AFFECTED_ROWS,
            'changed_group_ids'  => $changedGroupIds,
            'applied'            => $applied,
        ];
    }

    /**
     * The journal as the CLI's plan view shows it: one flat row per split.
     *
     * @return array<string, mixed>
     */
    private static function row(TransactionJournal $journal): array
    {
        /** @var null|Transaction $positive */
        $positive = $journal->transactions->first(static fn (Transaction $t): bool => 1 === bccomp((string) $t->amount, '0'));

        /** @var null|Transaction $negative */
        $negative = $journal->transactions->first(static fn (Transaction $t): bool => -1 === bccomp((string) $t->amount, '0'));
        $currency = $positive?->transactionCurrency;

        return [
            'group_id'         => (string) $journal->transaction_group_id,
            'journal_id'       => (string) $journal->id,
            'date'             => $journal->date?->format('Y-m-d'),
            'type'             => strtolower((string) $journal->transactionType?->type),
            'description'      => $journal->description,
            'amount'           => null === $positive || null === $currency ? null : Money::forCurrency((string) $positive->amount, $currency),
            'currency_code'    => $currency?->code,
            'source_name'      => $negative?->account?->name,
            'destination_name' => $positive?->account?->name,
            'category_name'    => $journal->categories->first()?->name,
            'budget_name'      => $journal->budgets->first()?->name,
            'tags'             => $journal->tags->pluck('tag')->values()->all(),
        ];
    }
}
