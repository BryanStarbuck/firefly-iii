<?php

/*
 * ListParams.php
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

/**
 * The universal list arguments — apis.mdx §5.5: `limit` (default 200, CLAMPED to 5,000, never
 * rejected), `offset`, and `order` ("field" or "-field", only fields the route documents).
 * Ordering is always total: a tie-break on `id` is appended by MachineController::applyList().
 */
final readonly class ListParams
{
    public function __construct(
        public int $limit,
        public int $offset,
        public string $orderField,
        public bool $descending,
        public int $requestedLimit,
        public bool $clamped,
        public string $orderColumn = '',
    ) {}

    /** The database column (or row key) to order by: the route's mapping of orderField. */
    public function column(): string
    {
        return '' === $this->orderColumn ? $this->orderField : $this->orderColumn;
    }

    /** "-date" / "name" — the order as the caller would write it. */
    public function order(): string
    {
        return ($this->descending ? '-' : '').$this->orderField;
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }
}
