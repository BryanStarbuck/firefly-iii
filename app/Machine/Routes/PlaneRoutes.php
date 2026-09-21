<?php

/*
 * PlaneRoutes.php
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

use FireflyIII\Machine\Http\Controllers\PlaneController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.0 — the plane itself. All four answer WITHOUT a resolved operator (operator:
 * false): their job is to say what is wrong. /search/operators lives with SearchRoutes.
 */
final class PlaneRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $diagnostic = ['operator' => false];

        return [
            RouteDef::live('GET', '/ping', 'read', 'Liveness: the plane is mounted and the key is right — key fingerprint, tiers, server version.', [PlaneController::class, 'ping'], ['phase' => 'P0'] + $diagnostic),
            RouteDef::live('GET', '/whoami', 'read', 'The operator, the administration (id and name), primary currency, tiers, key fingerprint, statements root.', [PlaneController::class, 'whoami'], ['phase' => 'P1'] + $diagnostic),
            RouteDef::live('GET', '/capabilities', 'read', "This build's route table: every route, its tier, and whether it is live or planned.", [PlaneController::class, 'capabilities'], ['phase' => 'P1'] + $diagnostic),
            RouteDef::live('GET', '/health', 'read', 'Is the database reachable and migrated, is an operator resolved, and what to do next (probe=true opens the database).', [PlaneController::class, 'health'], ['phase' => 'P1'] + $diagnostic),
        ];
    }
}
