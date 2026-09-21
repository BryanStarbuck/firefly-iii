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
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\TransactionGroup;
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
 */
final class Importer
{
    /**
     * @param list<array{summary: array<string, mixed>, account_id: null|int, rows: list<array<string, mixed>>}> $accounts
     *                                                                                                                  rows: RowBuilder rows (status ok are stored)
     */
    public static function run(LedgerAccounts $ledger, array $accounts, bool $applyRules, int $detailLimit = 1000): WriteResult
    {
        $repository = app(TransactionGroupRepositoryInterface::class);
        $repository->setUser($ledger->user());
        $repository->setUserGroup($ledger->group());

        $result     = new WriteResult();
        $created    = [];          // group id => [account index, row index]
        $detail     = [];
        $summaries  = [];
        $newIds     = [];
        $dupIds     = [];
        $detailed   = 0;
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
                    $result->created($group);
                    foreach ($group->transactionJournals as $journal) {
                        $result->created($journal);
                    }
                    $result->count('created');
                    ++$summary['new'];
                    $newIds[]             = $row['external_id'];
                    $verdict += ['verdict' => 'new', 'group_id' => (int) $group->id];
                } catch (DuplicateTransactionException $e) {
                    $of      = 1 === preg_match('/#(\d+)/', $e->getMessage(), $m) ? (int) $m[1] : null;
                    $deleted = null !== $of && true === TransactionGroup::withTrashed()->find($of)?->trashed();
                    if ($deleted) {
                        ++$summary['previously_deleted'];
                        $result->count('previously_deleted');
                    } else {
                        ++$summary['already_present'];
                        $result->count('duplicates');
                    }
                    $dupIds[$row['external_id']] = $of;
                    $verdict += ['verdict' => $deleted ? 'previously_deleted' : 'duplicate', 'duplicate_of' => $of, 'reason' => $deleted
                        ? sprintf('you deleted #%d in Firefly — its duplicate hash still stands, so it stays deleted', (int) $of)
                        : sprintf('Firefly\'s duplicate hash matches #%d', (int) $of)];
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
        }

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
