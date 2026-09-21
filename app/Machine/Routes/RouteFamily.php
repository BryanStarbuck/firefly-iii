<?php

/*
 * RouteFamily.php
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

use FireflyIII\Machine\RouteDef;

/**
 * One family of machine-plane routes (apis.mdx §8). Every family is listed in
 * RouteTable::FAMILIES; its routes() array is the ONLY declaration of its routes — the router
 * and /capabilities both read it (apis.mdx §6.3).
 *
 * A family that is not built yet declares its routes as RouteDef::planned(...); building a
 * route is flipping its entry to RouteDef::live(...) with a handler.
 */
interface RouteFamily
{
    /** @return list<RouteDef> */
    public static function routes(): array;
}
