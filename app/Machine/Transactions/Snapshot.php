<?php

/*
 * Snapshot.php
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

use FireflyIII\Machine\Transactions\Pivots\BudgetJournalRow;
use FireflyIII\Machine\Transactions\Pivots\CategoryJournalRow;
use FireflyIII\Machine\Transactions\Pivots\TagJournalRow;
use FireflyIII\Machine\WriteResult;
use FireflyIII\Models\Account;
use FireflyIII\Models\Category;
use FireflyIII\Models\Note;
use FireflyIII\Models\PiggyBankEvent;
use FireflyIII\Models\Tag;
use FireflyIII\Models\Transaction;
use FireflyIII\Models\TransactionGroup;
use FireflyIII\Models\TransactionJournal;
use FireflyIII\Models\TransactionJournalLink;
use FireflyIII\Models\TransactionJournalMeta;
use FireflyIII\Models\UserGroup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The before/after picture of every row a transaction write can touch — so the operation log
 * (apis.mdx §7.5) records exactly what Firefly's services did, and undo can reverse it:
 *
 *   the groups, their journals (trashed ones too), the journals' transactions, meta, notes,
 *   category / budget / tag links, journal links and piggy-bank events — plus the accounts,
 *   categories and tags Firefly CREATED on the way (a withdrawal to a new payee creates its
 *   expense account; a new category name creates the category).
 *
 * A row in `after` and not `before` was created; in both but different was updated (a soft
 * delete is an update of deleted_at, and undo writes the old row back); in `before` and not
 * `after` was hard-deleted (undo re-inserts it). Nothing here predicts anything: it diffs what the
 * real service call did (R4) — inside the dry run as well as on apply.
 */
final class Snapshot
{
    /** Parent → child: created rows are recorded in this order, deleted rows in the reverse. */
    private const array JOURNAL_TABLES = [
        TransactionGroup::class,
        TransactionJournal::class,
        Transaction::class,
        TransactionJournalMeta::class,
        CategoryJournalRow::class,
        BudgetJournalRow::class,
        TagJournalRow::class,
        Note::class,
        TransactionJournalLink::class,
        PiggyBankEvent::class,
    ];

    /** Things Firefly may create as a side effect of storing or editing a transaction. */
    private const array SIDE_CREATIONS = [
        'accounts'   => Account::class,
        'categories' => Category::class,
        'tags'       => Tag::class,
    ];

    /**
     * The highest ids before the write — rows above them afterwards were created by it.
     *
     * @return array<string, int>
     */
    public static function watermark(): array
    {
        $marks = ['groups' => (int) DB::table((new TransactionGroup())->getTable())->max('id')];
        foreach (self::SIDE_CREATIONS as $key => $class) {
            $marks[$key] = (int) DB::table((new $class())->getTable())->max('id');
        }

        return $marks;
    }

    /**
     * Group ids created since the watermark, in this administration.
     *
     * @param array<string, int> $watermark
     *
     * @return list<int>
     */
    public static function newGroupIds(array $watermark, UserGroup $group): array
    {
        return DB::table((new TransactionGroup())->getTable())
            ->where('id', '>', $watermark['groups'] ?? 0)
            ->where('user_group_id', $group->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all()
        ;
    }

    /**
     * Every row belonging to these groups, trashed rows included.
     *
     * @param list<int> $groupIds
     *
     * @return array<class-string<Model>, array<string, array<string, mixed>>> class => id => raw row
     */
    public static function capture(array $groupIds): array
    {
        $groupIds   = array_values(array_unique(array_map('intval', $groupIds)));
        $out        = array_fill_keys(self::JOURNAL_TABLES, []);
        if ([] === $groupIds) {
            return $out;
        }
        $out[TransactionGroup::class]   = self::rowsIn(TransactionGroup::class, 'id', $groupIds);
        $out[TransactionJournal::class] = self::rowsIn(TransactionJournal::class, 'transaction_group_id', $groupIds);
        $journalIds = array_map('intval', array_keys($out[TransactionJournal::class]));
        if ([] === $journalIds) {
            return $out;
        }
        foreach ([Transaction::class, TransactionJournalMeta::class, CategoryJournalRow::class, BudgetJournalRow::class, TagJournalRow::class, PiggyBankEvent::class] as $class) {
            $out[$class] = self::rowsIn($class, 'transaction_journal_id', $journalIds);
        }
        $morph      = (new TransactionJournal())->getMorphClass();
        $out[Note::class]                   = self::rowsIn(Note::class, 'noteable_id', $journalIds, static fn ($q) => $q->where('noteable_type', $morph));
        $out[TransactionJournalLink::class] = self::rowsIn(TransactionJournalLink::class, 'source_id', $journalIds)
            + self::rowsIn(TransactionJournalLink::class, 'destination_id', $journalIds);
        ksort($out[TransactionJournalLink::class], SORT_NUMERIC);

        return $out;
    }

    /** A filter can select more journals than a database binds in one statement, so every id list is chunked. */
    private const int CHUNK = 500;

    /**
     * @param class-string<Model> $class
     * @param list<int>           $ids
     *
     * @return array<string, array<string, mixed>> id => raw row, ordered by id
     */
    private static function rowsIn(string $class, string $column, array $ids, ?\Closure $also = null): array
    {
        $out = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $out += self::rows($class, static function ($q) use ($column, $chunk, $also): void {
                $q->whereIn($column, $chunk);
                if (null !== $also) {
                    $also($q);
                }
            });
        }
        ksort($out, SORT_NUMERIC);

        return $out;
    }

    /**
     * Record the difference as the write's touched rows (for the operation log and the
     * fingerprint), and return what Firefly created on the side, by kind.
     *
     * @param array<class-string<Model>, array<string, array<string, mixed>>> $before
     * @param array<class-string<Model>, array<string, array<string, mixed>>> $after
     * @param array<string, int>                                               $watermark
     *
     * @return array{accounts: list<array<string, mixed>>, categories: list<array<string, mixed>>, tags: list<array<string, mixed>>}
     */
    public static function record(WriteResult $result, array $before, array $after, array $watermark, UserGroup $group): array
    {
        $created = [];
        $updated = [];
        $deleted = [];
        $side    = ['accounts' => [], 'categories' => [], 'tags' => []];

        // side creations first: undo walks the log backwards, so they are removed last
        foreach (self::SIDE_CREATIONS as $key => $class) {
            $table = (new $class())->getTable();
            $rows  = DB::table($table)->where('id', '>', $watermark[$key] ?? 0)->where('user_group_id', $group->id)->orderBy('id')->get();
            foreach ($rows as $row) {
                $result->touched[] = ['class' => $class, 'id' => (int) $row->id, 'op' => 'created', 'before' => null];
                $side[$key][]      = 'tags' === $key ? ['id' => (int) $row->id, 'tag' => (string) $row->tag] : ['id' => (int) $row->id, 'name' => (string) $row->name];
            }
        }

        foreach (self::JOURNAL_TABLES as $class) {
            $old = $before[$class] ?? [];
            $new = $after[$class] ?? [];
            foreach ($new as $id => $row) {
                if (!array_key_exists($id, $old)) {
                    $created[] = ['class' => $class, 'id' => (int) $id, 'op' => 'created', 'before' => null];

                    continue;
                }
                if (self::differs($old[$id], $row)) {
                    $updated[] = ['class' => $class, 'id' => (int) $id, 'op' => 'updated', 'before' => $old[$id]];
                }
            }
            foreach ($old as $id => $row) {
                if (!array_key_exists($id, $new)) {
                    $deleted[] = ['class' => $class, 'id' => (int) $id, 'op' => 'deleted', 'before' => $row];
                }
            }
        }

        foreach ([...$created, ...$updated, ...array_reverse($deleted)] as $entry) {
            $result->touched[] = $entry;
        }

        return $side;
    }

    /**
     * A real change — not merely a touched updated_at. A touch alone would make the change set
     * depend on the wall clock (a plan in the same second as the last edit sees no change, the
     * apply one second later does), and the fingerprint must not.
     *
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     */
    private static function differs(array $old, array $new): bool
    {
        unset($old['updated_at'], $new['updated_at']);

        return $old != $new;
    }

    /**
     * @param class-string<Model> $class
     *
     * @return array<string, array<string, mixed>> id => raw row, ordered by id
     */
    private static function rows(string $class, \Closure $where): array
    {
        $model = new $class();
        $query = DB::table($model->getTable());
        $where($query);
        $out   = [];
        foreach ($query->orderBy($model->getKeyName())->get() as $row) {
            $array                          = (array) $row;
            $out[(string) $array[$model->getKeyName()]] = $array;
        }

        return $out;
    }
}
