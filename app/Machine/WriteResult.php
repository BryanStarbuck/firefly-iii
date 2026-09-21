<?php

/*
 * WriteResult.php
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

namespace FireflyIII\Machine;

use Illuminate\Database\Eloquent\Model;

/**
 * What one write did (or, inside a dry run, would do) — apis.mdx §7.
 *
 *   changes  counts by kind: {"created": 3, "updated": 12, "unchanged": 40, "duplicates": 1}
 *   data     the route's own response data (Firefly's snake_case shapes)
 *   touched  every row the write created, updated or deleted, with a before-image for updates
 *            and deletes — what /undo reverses (§7.5). Record a row with created(), and call
 *            updating() / deleting() BEFORE mutating the row, so the before-image is the row
 *            as it was in the database.
 *
 * The change COUNT — what max_changes is measured against — is the sum of `changes` minus the
 * keys that are not changes (unchanged, duplicates, skipped…), unless the route sets
 * $changeCount itself.
 */
final class WriteResult
{
    /** Keys in `changes` that describe rows the write did NOT change. */
    public const array NON_CHANGE_KEYS = ['unchanged', 'duplicates', 'duplicate', 'skipped', 'ignored', 'matched', 'unmatched', 'errors', 'blocked', 'total', 'would_fire_webhooks'];

    /**
     * @param array<string, int>                                                                              $changes
     * @param array<string, mixed>                                                                            $data
     * @param list<array{class: class-string<Model>, id: int|string, op: string, before: null|array<string, mixed>}> $touched
     * @param array<mixed>                                                                                     $basis   extra stable facts the fingerprint must cover
     */
    public function __construct(
        public array $changes = [],
        public array $data = [],
        public array $touched = [],
        public ?int $changeCount = null,
        public array $basis = [],
    ) {}

    /** Count one (or $n) of a kind of change: $result->count('created'). */
    public function count(string $kind, int $n = 1): self
    {
        $this->changes[$kind] = ($this->changes[$kind] ?? 0) + $n;

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function with(array $data): self
    {
        $this->data = array_merge($this->data, $data);

        return $this;
    }

    /** Record a row this write created (undo deletes it). */
    public function created(Model $model): self
    {
        $this->touched[] = ['class' => $model::class, 'id' => $model->getKey(), 'op' => 'created', 'before' => null];

        return $this;
    }

    /** Call BEFORE changing $model: records its row as stored (undo writes it back). */
    public function updating(Model $model): self
    {
        $this->touched[] = ['class' => $model::class, 'id' => $model->getKey(), 'op' => 'updated', 'before' => self::beforeImage($model)];

        return $this;
    }

    /** Call BEFORE deleting $model: records its row as stored (undo restores it). */
    public function deleting(Model $model): self
    {
        $this->touched[] = ['class' => $model::class, 'id' => $model->getKey(), 'op' => 'deleted', 'before' => self::beforeImage($model)];

        return $this;
    }

    /** The number max_changes is measured against. */
    public function changeCount(): int
    {
        if (null !== $this->changeCount) {
            return $this->changeCount;
        }
        $n = 0;
        foreach ($this->changes as $kind => $count) {
            if (!in_array($kind, self::NON_CHANGE_KEYS, true)) {
                $n += (int) $count;
            }
        }

        return $n;
    }

    /**
     * The fingerprint of the change set (§7.3): it moves when the world moves under a plan.
     * Created ids are deliberately NOT in it (a rolled-back insert may not reuse its id on
     * MySQL/Postgres); the before-images of updated and deleted rows ARE (a human edit since the
     * plan changes the before-image, and so the fingerprint).
     */
    public function fingerprint(): string
    {
        $changes = $this->changes;
        ksort($changes);
        $touched = [];
        foreach ($this->touched as $t) {
            $touched[] = 'created' === $t['op']
                ? [$t['class'], 'created']
                : [$t['class'], (string) $t['id'], $t['op'], hash('sha256', Envelope::encode($t['before'] ?? []))];
        }
        $basis   = ['changes' => $changes, 'count' => $this->changeCount(), 'touched' => $touched, 'basis' => $this->basis];

        return 'sha256:'.substr(hash('sha256', Envelope::encode($basis)), 0, 32);
    }

    /**
     * The row exactly as the database holds it — raw attributes, before casts and mutators,
     * so undo can write it back byte for byte.
     *
     * @return array<string, mixed>
     */
    private static function beforeImage(Model $model): array
    {
        $raw = $model->getRawOriginal();

        return is_array($raw) && [] !== $raw ? $raw : $model->getAttributes();
    }
}
