<?php

/*
 * 2026_09_21_000001_create_machine_operations_table.php
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

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The machine plane's undo log — pm/apis.mdx §7.5. One row per successful plane write: the
 * route, what it created, and a before-image of what it updated or deleted. Pruned after 30
 * days. It holds financial data, and so lives only in the database (outside the repo).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('machine_operations')) {
            return;
        }
        Schema::create('machine_operations', static function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->timestamps();
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->string('route', 255);
            $table->string('caller', 32)->nullable();
            $table->string('key_fingerprint', 64)->nullable();
            $table->text('changes');
            $table->longText('touched');
            $table->timestamp('reversed_at')->nullable();
            $table->index(['user_group_id', 'id'], 'machine_operations_group_id');
            $table->index('created_at', 'machine_operations_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_operations');
    }
};
