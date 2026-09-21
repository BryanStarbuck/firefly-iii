<?php

/*
 * AccountPiggyBankRow.php
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

namespace FireflyIII\Machine\Subscriptions\Pivots;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of `account_piggy_bank` — the saved amount of a piggy bank on one of its accounts
 * (current_amount, native_current_amount). Firefly models it as a pivot with no class of its own;
 * the plane needs a class so its operation log (apis.mdx §7.5) can record the row as created,
 * updated or deleted and POST /undo can write the amount back. The table has no timestamps.
 * Never used to write outside undo.
 */
final class AccountPiggyBankRow extends Model
{
    public $timestamps = false;

    protected $table   = 'account_piggy_bank';

    protected $guarded = [];
}
