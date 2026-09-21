<?php

/*
 * Importer.php
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

namespace FireflyIII\Machine\Ingest;

use FireflyIII\Events\Model\TransactionGroup\CreatedSingleTransactionGroup;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventFlags;
use FireflyIII\Events\Model\TransactionGroup\TransactionGroupEventObjects;
use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Exceptions\FireflyException;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\Transactions\Snapshot;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Repositories\TransactionGroup\TransactionGroupRepositoryInterface;
use Illuminate\Support\Facades\DB;

/**
 * The store half — apis.mdx §11.6–§11.7. Every row goes through Firefly's own
 * TransactionGroupRepository::store() → TransactionGroupFactory → TransactionJournalFactory, with
 * the store options PINNED:
 *
 *   error_if_duplicate_hash  true    Firefly — not this plane — decides what already exists, with
 *                                    the check it runs for the Data Importer (withTrashed: a row the
 *                                    operator DELETED is refused on every future run)
 *   apply_rules              true    overridable per run
 *   fire_webhooks            false   replaying a decade must not announce 12,007 "new" transactions
 *   batch_submission         true    the per-journal listeners are deferred to ONE pass at the end
 *                                    (rules, credit, running balances), as /api/v1/batch/finish does
 *
 * `plan` and `apply` are this same code with one boolean different: the plan runs it inside the
 * dry-run harness (rolled back), rows stored in order in one transaction, so a within-batch
 * duplicate is caught exactly as on apply. Notes are never written; nothing is ever deleted.
 *
 * What the run created is recorded for the operation log the way the transactions family does
 * it (Snapshot): the groups, journals, transactions, meta rows, the category / budget / tag links
 * the rules added, and the expense / revenue accounts Firefly created on the side — so /undo
 * removes all of it, never leaving an orphan transaction row behind a deleted journal. Rows a
 * factory stamped with the operator's CURRENT administration are moved into the bound one first
 * (apis.mdx §4.9), as the accounts family does.
 */
final class Importer
{
    /**
     * @param list<array{summary: array<string, mixed>, account_id: null|int, rows: list<array<string, mixed>>}> $accounts
     *                                                                                                                  rows: RowBuilder rows (status ok are stored)
     */
    public static function run(LedgerAccounts $ledger, array $accounts, bool $applyRules, int $detailLimit = 1000, string $phase = 'storing'): WriteResult
    {
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($ledger->user());
        $repository->setUserGroup($ledger->group());

        $result     = new WriteResult();
        $created    = [];          // group id => [account index, row index]
        $dupOf      = [];          // existing group id => list of [account index, detail index]
        $watermark  = Snapshot::watermark();
        $detail     = [];
        $summaries  = [];
        $newIds     = [];
        $dupIds     = [];
        $detailed   = 0;
        $total      = 0;
        $done       = 0;
        foreach ($accounts as $plan) {
            if (null !== $plan['account_id']) {
                $total += count(array_filter($plan['rows'], static fn (array $r): bool => 'ok' === $r['status']));
            }
        }
        Progress::tick($phase, 0, $total);
        foreach ($accounts as $ai => $plan) {
            $summary              = $plan['summary'] + ['already_present' => 0, 'previously_deleted' => 0, 'new' => 0, 'errors' => 0, 'rules_would_categorise' => 0];
            $accountId            = $plan['account_id'];
            $target               = null === $accountId ? null : $ledger->find($accountId);
            if (null === $target) {
                $summaries[$ai] = $summary;

                continue;
            }
            foreach ($plan['rows'] as $ri => $row) {
                if ('ok' !== $row['status']) {
                    continue;
                }
                ++$done;
                Progress::tick($phase, $done, $total);
                $verdict = ['account' => $row['account'], 'date' => $row['date'], 'type' => $row['type'], 'amount' => Money::abs((string) $row['amount']), 'description' => $row['description'], 'external_id' => $row['external_id']];

                try {
                    $canonical = CanonicalRow::fromStatementRow($row, $accountId, (string) $target['currency_code'], (int) $target['currency_places']);
                } catch (MachineException $e) {
                    ++$summary['errors'];
                    $result->count('errors');
                    $verdict += ['verdict' => 'error', 'reason' => $e->getMessage()];
                    self::keep($detail, $detailed, $detailLimit, $verdict);

                    continue;
                }
                $verdict['amount'] = $canonical['amount'];
                $verdict['currency_code'] = $canonical['currency_code'];

                try {
                    $group = $repository->store([
                        'user'                    => $ledger->user(),
                        'user_group'              => $ledger->group(),
                        'group_title'             => null,
                        'error_if_duplicate_hash' => true,
                        'apply_rules'             => $applyRules,
                        'fire_webhooks'           => false,
                        'batch_submission'        => true,
                        'transactions'            => [CanonicalRow::forFactory($canonical)],
                    ]);
                    $created[(int) $group->id] = [$ai, count($detail)];
                    $result->count('created');
                    ++$summary['new'];
                    $newIds[]             = $row['external_id'];
                    $verdict += ['verdict' => 'new', 'group_id' => (int) $group->id];
                } catch (DuplicateTransactionException $e) {
                    $of      = 1 === preg_match('/#(\d+)/', $e->getMessage(), $m) ? (int) $m[1] : null;
                    $inBatch = null !== $of && isset($created[$of]);
                    ++$summary['already_present'];
                    $result->count('duplicates');
                    $dupIds[$row['external_id']] = $inBatch ? 'batch:'.$created[$of][1] : $of;
                    if (!$inBatch && null !== $of) {
                        // whether #of was deleted by the operator is decided once for the whole run, below
                        $dupOf[$of][] = [$ai, $detailed];
                    }
                    $verdict += match (true) {
                        $inBatch => ['verdict' => 'duplicate', 'duplicate_of' => $of, 'in_batch' => true, 'reason' => 'the same canonical row appears earlier in this run (same hash) — stored once'],
                        default  => ['verdict' => 'duplicate', 'duplicate_of' => $of, 'reason' => sprintf('Firefly\'s duplicate hash matches #%d', (int) $of)],
                    };
                } catch (FireflyException $e) {
                    ++$summary['errors'];
                    $result->count('errors');
                    $verdict += ['verdict' => 'error', 'reason' => $e->getMessage()];
                }
                self::keep($detail, $detailed, $detailLimit, $verdict);
            }
            $summaries[$ai] = $summary;
        }

        // ONE pass of the deferred listeners over everything this run stored (the batch finish)
        if ([] !== $created) {
            Progress::tick('finishing', 0, 1);
            $groups  = TransactionGroup::query()->whereIn('id', array_keys($created))->with('transactionJournals')->get();
            $objects = new TransactionGroupEventObjects();
            foreach ($groups as $g) {
                $objects->appendFromTransactionGroup($g);
            }
            $flags                    = new TransactionGroupEventFlags();
            $flags->applyRules        = $applyRules;
            $flags->fireWebhooks      = false;
            $flags->batchSubmission   = false;
            $flags->recalculateCredit = true;
            event(new CreatedSingleTransactionGroup($flags, $objects));
            self::rulesReport($created, $detail, $summaries, $accounts);
            self::claimSideAccounts($ledger, (int) $watermark['accounts']);
            Snapshot::record($result, Snapshot::capture([]), Snapshot::capture(array_keys($created)), $watermark, $ledger->group());
            Progress::tick('finishing', 1, 1);
        }
        self::markPreviouslyDeleted($dupOf, $detail, $summaries, $result);

        $out        = [];
        foreach ($accounts as $ai => $plan) {
            $out[] = $summaries[$ai] ?? $plan['summary'];
        }
        sort($newIds, SORT_STRING);
        ksort($dupIds, SORT_STRING);
        $result->changeCount = $result->changes['created'] ?? 0;
        $result->basis       = ['new' => hash('sha256', implode("\n", $newIds)), 'duplicates' => hash('sha256', (string) json_encode($dupIds))];

        return $result->with([
            'accounts'       => $out,
            'rows'           => $detail,
            'rows_truncated' => $detailed > count($detail),
        ]);
    }

    /**
     * Which of the groups Firefly's duplicate check named were deleted by the operator? The
     * check ran withTrashed(), so a match may be a soft-deleted group, or a group whose every
     * journal is soft-deleted (§11.6): either counts. Decided in two queries for the whole run,
     * never one per duplicate row, and the verdicts and counts are re-labelled from `duplicate`
     * to `previously_deleted`.
     *
     * @param array<int, list<array{0: int, 1: int}>> $dupOf     group id => [account index, detail index]
     * @param list<array<string, mixed>>               $detail
     * @param array<int, array<string, mixed>>         $summaries
     */
    private static function markPreviouslyDeleted(array $dupOf, array &$detail, array &$summaries, WriteResult $result): void
    {
        if ([] === $dupOf) {
            return;
        }
        $ids     = array_keys($dupOf);
        $deleted = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (TransactionGroup::withTrashed()->whereIn('id', $chunk)->get(['id', 'deleted_at']) as $group) {
                if (null !== $group->deleted_at) {
                    $deleted[(int) $group->id] = true;
                }
            }
            $alive = TransactionJournal::withTrashed()->whereIn('transaction_group_id', $chunk)->get(['id', 'transaction_group_id', 'deleted_at']);
            $seen  = [];
            foreach ($alive as $journal) {
                $gid          = (int) $journal->transaction_group_id;
                $seen[$gid] ??= ['any' => false, 'live' => false];
                $seen[$gid]['any'] = true;
                if (null === $journal->deleted_at) {
                    $seen[$gid]['live'] = true;
                }
            }
            foreach ($seen as $gid => $s) {
                if ($s['any'] && !$s['live']) {
                    $deleted[$gid] = true;
                }
            }
        }
        foreach ($dupOf as $of => $hits) {
            if (!isset($deleted[$of])) {
                continue;
            }
            foreach ($hits as [$ai, $di]) {
                --$summaries[$ai]['already_present'];
                ++$summaries[$ai]['previously_deleted'];
                $result->count('duplicates', -1);
                $result->count('previously_deleted');
                if (isset($detail[$di]) && ($detail[$di]['duplicate_of'] ?? null) === $of) {
                    $detail[$di]['verdict'] = 'previously_deleted';
                    $detail[$di]['reason']  = sprintf('you deleted #%d in Firefly — its duplicate hash still stands, so it stays deleted', $of);
                }
            }
        }
        if (0 === ($result->changes['duplicates'] ?? null)) {
            unset($result->changes['duplicates']);
        }
    }

    /**
     * Firefly's account factory stamps an expense / revenue account it creates on the way with
     * the operator's CURRENT administration (user.user_group_id); the plane may be bound to
     * another one (apis.mdx §4.9). What this run created belongs to the bound books — move it
     * there before the operation log reads it back. Only the operator's own new rows, never a
     * row the browser inserted meanwhile in another administration.
     */
    private static function claimSideAccounts(LedgerAccounts $ledger, int $watermark): void
    {
        DB::table((new Account())->getTable())
            ->where('id', '>', $watermark)
            ->where('user_id', $ledger->user()->id)
            ->where('user_group_id', '!=', $ledger->group()->id)
            ->update(['user_group_id' => $ledger->group()->id])
        ;
    }

    /**
     * What the rule engine did to each stored row: category, budget, tags — read back after the
     * one deferred pass (and rolled back with everything else in a plan).
     *
     * @param array<int, array{0: int, 1: int}>        $created group id => [account index, detail index]
     * @param list<array<string, mixed>>               $detail
     * @param array<int, array<string, mixed>>         $summaries
     * @param list<array<string, mixed>>               $accounts
     */
    private static function rulesReport(array $created, array &$detail, array &$summaries, array $accounts): void
    {
        $ids      = array_keys($created);
        $journals = DB::table('transaction_journals')->whereIn('transaction_group_id', $ids)->whereNull('deleted_at')->pluck('transaction_group_id', 'id')->all();
        $cats     = [];
        foreach (DB::table('category_transaction_journal')->join('categories', 'categories.id', '=', 'category_transaction_journal.category_id')->whereIn('transaction_journal_id', array_keys($journals))->get(['transaction_journal_id', 'categories.name']) as $r) {
            $cats[$journals[$r->transaction_journal_id]] = (string) $r->name;
        }
        $budgets  = [];
        foreach (DB::table('budget_transaction_journal')->join('budgets', 'budgets.id', '=', 'budget_transaction_journal.budget_id')->whereIn('transaction_journal_id', array_keys($journals))->get(['transaction_journal_id', 'budgets.name']) as $r) {
            $budgets[$journals[$r->transaction_journal_id]] = (string) $r->name;
        }
        $tags     = [];
        foreach (DB::table('tag_transaction_journal')->join('tags', 'tags.id', '=', 'tag_transaction_journal.tag_id')->whereIn('transaction_journal_id', array_keys($journals))->get(['transaction_journal_id', 'tags.tag']) as $r) {
            $tags[$journals[$r->transaction_journal_id]][] = (string) $r->tag;
        }
        $alive    = array_flip(array_values($journals));
        foreach ($created as $groupId => [$ai, $di]) {
            $category = $cats[$groupId] ?? null;
            $budget   = $budgets[$groupId] ?? null;
            if (null !== $category || null !== $budget) {
                ++$summaries[$ai]['rules_would_categorise'];
            }
            if (isset($detail[$di]) && ($detail[$di]['group_id'] ?? null) === $groupId) {
                $detail[$di]['rules'] = ['category' => $category, 'budget' => $budget, 'tags' => $tags[$groupId] ?? []];
                if (!isset($alive[$groupId])) {
                    $detail[$di]['rules']['deleted_by_rule'] = true;
                }
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $detail
     * @param array<string, mixed>       $verdict
     */
    private static function keep(array &$detail, int &$count, int $limit, array $verdict): void
    {
        ++$count;
        if (count($detail) < $limit) {
            $detail[] = $verdict;
        }
    }
}
