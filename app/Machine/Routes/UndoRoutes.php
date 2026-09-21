<?php

/*
 * UndoRoutes.php
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

namespace FireflyIII\Machine\Routes;

use FireflyIII\Machine\Http\Controllers\BatchController;
use FireflyIII\Machine\Http\Controllers\UndoController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §7.5 — undo (the plane's own writes only) and §7.4 — batch.
 *
 * Every entry is live: UndoController (the core OperationLog) and BatchController (the
 * operations of a batch run through their own live write routes, in-process, in one transaction).
 */
final class UndoRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $u = UndoController::class;

        return [
            RouteDef::live('GET', '/undo/last', 'read', 'The most recent plane write, what undoing it would do, what blocks it — and the confirm token POST /undo needs.', [$u, 'last'], ['phase' => 'P3']),
            RouteDef::live('POST', '/undo', 'write', 'Reverse the most recent plane write; refuses when a touched row was changed since. No dry run: GET /undo/last is its plan, and its confirm_token is required.', [$u, 'undo'], ['phase' => 'P3', 'dryRun' => false]),
            RouteDef::live('POST', '/batch', 'write', 'Several write operations in one database transaction, all or nothing, with one confirm token. `op` names are listed in /capabilities batchOps.', [BatchController::class, 'batch'], ['phase' => 'P10', 'composed' => true]),
        ];
    }

    /**
     * The closed set of `op` names POST /batch accepts right now (§7.4): an op is offered only
     * while the write route it runs is live, so /capabilities never advertises a dead op.
     *
     * @return list<string>
     */
    public static function batchOps(): array
    {
        return BatchController::availableOps();
    }
}
