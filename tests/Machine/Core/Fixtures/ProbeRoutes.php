<?php

/*
 * ProbeRoutes.php
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

namespace Tests\Machine\Core\Fixtures;

use FireflyIII\Machine\RouteDef;
use FireflyIII\Machine\Routes\RouteFamily;

/**
 * A TEST-ONLY route family, mounted through RouteTable::registerTestFamily() — the seam that
 * lets the write protocol, the envelope and the helpers be exercised end to end (router, gates,
 * renderer) before any real family is built. Never mounted outside PHPUnit.
 */
final class ProbeRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = ProbeController::class;

        return [
            RouteDef::live('GET', '/_probe/throw/{kind}', 'read', 'Throw an exception of a kind.', [$c, 'throw'], ['phase' => 'test']),
            RouteDef::live('GET', '/_probe/list', 'read', 'A list through input/listParams/applyList.', [$c, 'list'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/body', 'read', 'A body through input().', [$c, 'body'], ['phase' => 'test']),
            RouteDef::live('GET', '/_probe/auth', 'read', 'Who auth() says the user is.', [$c, 'auth'], ['phase' => 'test']),
            RouteDef::live('GET', '/_probe/resolve/{name}', 'read', 'resolve() a category.', [$c, 'resolveCategory'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/categories', 'write', 'Create categories (write protocol).', [$c, 'createCategories'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/categories/suffix', 'write', 'Rename every category (write protocol).', [$c, 'suffixCategories'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/categories/delete', 'write', 'Soft-delete every category (write protocol).', [$c, 'deleteCategories'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/boom-write', 'write', 'A write that fails half way.', [$c, 'boomWrite'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/direct', 'write', 'A write with no dry run.', [$c, 'direct'], ['phase' => 'test', 'dryRun' => false]),
            RouteDef::live('GET', '/_probe/undo/last', 'read', 'OperationLog::last().', [$c, 'undoLast'], ['phase' => 'test']),
            RouteDef::live('POST', '/_probe/undo', 'write', 'OperationLog::reverse() of the last operation.', [$c, 'undo'], ['phase' => 'test', 'dryRun' => false]),
            RouteDef::live('POST', '/_probe/admin-thing', 'admin', 'An admin write.', [$c, 'createCategories'], ['phase' => 'test']),
        ];
    }
}
