<?php

/*
 * AdministrationRoutes.php
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

use FireflyIII\Machine\Http\Controllers\AdministrationController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.1 — administrations (the sets of books). There is deliberately no route that switches the bound administration: that is configuration (§4.9).
 *
 * Every entry is live.
 */
final class AdministrationRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = AdministrationController::class;

        return [
            RouteDef::live('GET', '/administrations', 'read', 'The operator\'s administrations, marking the one the plane is bound to.', [$c, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/administrations/{id}', 'read', 'One administration, with member count and primary currency.', [$c, 'show'], ['phase' => 'P2']),
            RouteDef::live('PUT', '/administrations/{id}', 'write', 'Rename it, or change its primary currency (recalculates converted amounts; the response says how many).', [$c, 'update'], ['phase' => 'P8']),
        ];
    }
}
