<?php

/*
 * OperationLog.php
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

namespace FireflyIII\Machine\Undo;

use Carbon\Carbon;
use FireflyIII\Machine\Audit;
use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Operator;
use FireflyIII\Machine\WriteResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The plane's own undo log — apis.mdx §7.5. Firefly III has no undo stack; the plane records
 * enough about ITS OWN writes to reverse them.
 *
 *   record()   one machine_operations row per successful plane write, inside the SAME database
 *              transaction as the write: the route, what it created, and a before-image of
 *              every row it updated or deleted.
 *   last()     the most recent un-reversed plane operation of the bound administration, with
 *              what undoing it would do — and which rows block it.
 *   reverse()  deletes what it created, writes back the before-images of what it updated, and
 *              restores what it deleted. It REFUSES (conflict, naming the rows) when any touched
 *              row has been changed since the operation — undoing a program's write must never
 *              destroy a human's — and when the operation was already reversed.
 *
 * Rows older than 30 days are pruned; they hold financial data and live in the database, which
 * lives outside the repo.
 */
final class OperationLog
{
    public const string TABLE = 'machine_operations';

    /** Record a successful write. Call inside the write's DB transaction. Returns the operation id. */
    public static function record(string $route, WriteResult $result): int
    {
        $request = request();
        $user    = Operator::user($request) ?? auth()->user();
        $group   = Operator::administration($request);
        $key     = CredentialsFile::resolve();
        $now     = Carbon::now();

        $id      = (int) DB::table(self::TABLE)->insertGetId([
            'created_at'      => $now,
            'updated_at'      => $now,
            'user_id'         => $user?->getAuthIdentifier(),
            'user_group_id'   => $group?->id ?? $user?->user_group_id,
            'route'           => substr($route, 0, 255),
            'caller'          => Audit::caller($request),
            'key_fingerprint' => $key?->fingerprint(),
            'changes'         => Envelope::encode($result->changes),
            'touched'         => Envelope::encode($result->touched),
            'reversed_at'     => null,
        ]);
        self::prune();

        return $id;
    }

    /**
     * The most recent un-reversed plane operation of the bound administration, previewed.
     *
     * @return null|array<string, mixed>
     */
    public static function last(): ?array
    {
        $row = self::query()->whereNull('reversed_at')->orderByDesc('id')->first();

        return null === $row ? null : self::preview($row);
    }

    /**
     * One operation, previewed: what it did, what undoing it would do, what blocks it.
     *
     * @return array<string, mixed>
     */
    public static function describe(int $id): array
    {
        $row = self::query()->where('id', $id)->first();
        if (null === $row) {
            throw MachineException::notFound(sprintf('No plane operation #%d in this administration.', $id), 'GET /machine/v1/undo/last shows the most recent one', ['operation_id' => $id]);
        }

        return self::preview($row);
    }

    /**
     * Reverse one operation. Call inside a DB transaction (and under the write lock).
     *
     * @return array<string, mixed> the operation id and what was done
     *
     * @throws MachineException conflict (already reversed / a touched row changed since), not_found
     */
    public static function reverse(int $id): array
    {
        $row = self::query()->where('id', $id)->lockForUpdate()->first();
        if (null === $row) {
            throw MachineException::notFound(sprintf('No plane operation #%d in this administration.', $id), 'GET /machine/v1/undo/last shows the most recent one', ['operation_id' => $id]);
        }
        if (null !== $row->reversed_at) {
            throw MachineException::conflict(sprintf('Plane operation #%d was already undone.', $id), 'GET /machine/v1/undo/last shows the most recent operation that can still be undone', ['operation_id' => $id]);
        }
        $preview = self::preview($row);
        if ([] !== $preview['blocked_by']) {
            throw MachineException::conflict(
                sprintf('Plane operation #%d cannot be undone: %d row(s) it touched were changed since.', $id, count($preview['blocked_by'])),
                'Undo refuses rather than destroy a later change (probably made in the browser) — fix those rows by hand instead',
                ['operation_id' => $id, 'blocked_by' => $preview['blocked_by']],
            );
        }

        $done    = ['deleted' => 0, 'restored' => 0, 'reinserted' => 0, 'already_gone' => 0];
        $touched = self::touched($row);
        foreach (array_reverse($touched) as $t) {
            $model = self::model($t['class']);
            if (null === $model) {
                continue;
            }
            $table = $model->getTable();
            $pk    = $model->getKeyName();
            $query = DB::table($table)->where($pk, $t['id']);
            switch ($t['op']) {
                case 'created':
                    $done[$query->exists() ? 'deleted' : 'already_gone'] += 1;
                    $query->delete();

                    break;

                case 'updated':
                    $before = self::columnsOnly($table, (array) ($t['before'] ?? []));
                    unset($before[$pk]);
                    if ($query->exists()) {
                        $query->update($before);
                        ++$done['restored'];
                    } else {
                        ++$done['already_gone'];
                    }

                    break;

                case 'deleted':
                    $before = self::columnsOnly($table, (array) ($t['before'] ?? []));
                    if ($query->exists()) {
                        unset($before[$pk]);
                        $query->update($before); // a soft delete: writing back the row clears deleted_at
                        ++$done['restored'];
                    } else {
                        DB::table($table)->insert($before);
                        ++$done['reinserted'];
                    }

                    break;
            }
        }
        DB::table(self::TABLE)->where('id', $id)->update(['reversed_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        return ['operation_id' => $id, 'route' => (string) $row->route, 'undone' => $done];
    }

    /** Delete operation rows older than the retention (30 days). */
    public static function prune(): int
    {
        $days = (int) config('machine.undo_retention', 30);

        try {
            return DB::table(self::TABLE)->where('created_at', '<', Carbon::now()->subDays($days))->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    // ------------------------------------------------------------------------

    /** Scoped to the bound administration (and user, as a belt to the braces). */
    private static function query(): \Illuminate\Database\Query\Builder
    {
        $request = request();
        $group   = Operator::administration($request);
        $user    = Operator::user($request) ?? auth()->user();
        $query   = DB::table(self::TABLE);
        if (null !== $group) {
            $query->where('user_group_id', $group->id);
        }
        if (null !== $user) {
            $query->where('user_id', $user->getAuthIdentifier());
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private static function preview(object $row): array
    {
        $touched   = self::touched($row);
        $at        = Carbon::parse((string) $row->created_at);
        $blockedBy = [];
        $effects   = [];
        foreach ($touched as $t) {
            // An operation that cannot be reversed row by row (cron, data/destroy, purge…) records
            // one 'irreversible' marker: undo then refuses, naming why, instead of half-undoing it.
            if ('irreversible' === $t['op']) {
                $what        = (string) ($t['before']['what'] ?? self::entity($t['class']));
                $effects[]   = ['entity' => $what, 'id' => $t['id'], 'did' => (string) ($t['before']['did'] ?? 'changed'), 'undo' => 'impossible'];
                $blockedBy[] = ['entity' => $what, 'id' => $t['id'], 'reason' => (string) ($t['before']['reason'] ?? 'this operation cannot be undone')];

                continue;
            }
            $model  = self::model($t['class']);
            $entity = self::entity($t['class']);
            if (null === $model) {
                $blockedBy[] = ['entity' => $entity, 'id' => $t['id'], 'reason' => 'unknown record type'];

                continue;
            }
            $current = DB::table($model->getTable())->where($model->getKeyName(), $t['id'])->first();
            $effects[] = [
                'entity' => $entity,
                'id'     => $t['id'],
                'did'    => $t['op'],
                'undo'   => match ($t['op']) {
                    'created' => null === $current ? 'nothing (already gone)' : 'delete',
                    'updated' => null === $current ? 'nothing (already gone)' : 'restore the previous values',
                    default   => null === $current ? 're-insert' : 'restore',
                },
            ];
            $reason  = self::blockReason($t, $current, $at, $model);
            if (null !== $reason) {
                $blockedBy[] = ['entity' => $entity, 'id' => $t['id'], 'reason' => $reason];
            }
        }

        return [
            'operation_id' => (int) $row->id,
            'route'        => (string) $row->route,
            'at'           => $at->toIso8601String(),
            'caller'       => $row->caller,
            'changes'      => json_decode((string) $row->changes, true) ?: new \stdClass(),
            'reversed'     => null !== $row->reversed_at,
            'effects'      => $effects,
            'blocked_by'   => $blockedBy,
            'reversible'   => null === $row->reversed_at && [] === $blockedBy,
        ];
    }

    /**
     * @param array{class: string, id: int|string, op: string, before: null|array<string, mixed>} $t
     */
    private static function blockReason(array $t, ?object $current, Carbon $at, Model $model): ?string
    {
        if (null === $current) {
            return null; // gone already: nothing to clobber
        }
        $column  = $model->usesTimestamps() ? $model->getUpdatedAtColumn() : null;
        $updated = null === $column ? null : ($current->{$column} ?? null);
        if (null !== $updated && Carbon::parse((string) $updated)->gt($at)) {
            return 'changed since the operation';
        }
        if ('deleted' === $t['op'] && in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $deletedAt = $current->{$model->getDeletedAtColumn()} ?? null;
            if (null === $deletedAt) {
                return 'restored since the operation';
            }
        }

        return null;
    }

    /**
     * @return list<array{class: string, id: int|string, op: string, before: null|array<string, mixed>}>
     */
    private static function touched(object $row): array
    {
        $decoded = json_decode((string) $row->touched, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    private static function model(string $class): ?Model
    {
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return null;
        }

        return new $class();
    }

    private static function entity(string $class): string
    {
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', class_basename($class)));
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function columnsOnly(string $table, array $values): array
    {
        $columns = Schema::getColumnListing($table);

        return array_intersect_key($values, array_flip($columns));
    }
}
