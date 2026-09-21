<?php

/*
 * BudgetJournalRow.php
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

namespace FireflyIII\Machine\Transactions\Pivots;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of `budget_transaction_journal` — the link between a journal and its budget. Firefly models this as a pivot
 * with no class of its own; the plane needs a class so its operation log (apis.mdx §7.5) can record
 * the row as created or deleted and undo can put it back. Never used to write outside undo.
 */
final class BudgetJournalRow extends Model
{
    public $timestamps = false;

    protected $table   = 'budget_transaction_journal';

    protected $guarded = [];
}
