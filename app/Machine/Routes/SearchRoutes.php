<?php

/*
 * SearchRoutes.php
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

use FireflyIII\Machine\Http\Controllers\MirrorController;
use FireflyIII\Machine\Http\Controllers\SearchController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §13 — Firefly's search language (read-only by construction), and §8.12 — the mirror: any GET under upstream's /api/v1, in-process, as the operator, wrapped in the envelope.
 *
 * Every entry is live: SearchController (OperatorQuerySearch, AccountSearch, config/search.php)
 * and MirrorController (an in-process sub-request to /api/v1, GET only, behind a denylist).
 */
final class SearchRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $s = SearchController::class;
        $m = MirrorController::class;

        return [
            RouteDef::live('GET', '/search', 'read', 'Transactions matching a query in Firefly\'s search language (a last resort — prefer a typed route). Echoes the operators Firefly parsed and the words it treated as free text.', [$s, 'search'], ['phase' => 'P2']),
            RouteDef::live('GET', '/search/count', 'read', 'Just the number of matches.', [$s, 'count'], ['phase' => 'P2']),
            RouteDef::live('GET', '/search/operators', 'read', 'Every search operator, its argument type, and an example (generated from config/search.php).', [$s, 'operators'], ['phase' => 'P2']),
            RouteDef::live('GET', '/search/accounts', 'read', 'Accounts matching a query by IBAN, name, number or id.', [$s, 'accounts'], ['phase' => 'P2']),
            RouteDef::live('GET', '/mirror/{path}', 'read', 'Any GET under upstream\'s /api/v1, read-only, as the operator (a coverage floor, not the interface).', [$m, 'mirror'], ['phase' => 'P2']),
        ];
    }
}
