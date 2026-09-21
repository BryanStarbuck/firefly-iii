<?php

/*
 * AdminRoutes.php
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

use FireflyIII\Machine\Http\Controllers\AdminController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.11 — the admin tier: the routes that can lose data or change the install, plus webhook authoring (a data-egress decision, §8.10). Needs FIREFLY_MACHINE_ALLOW_ADMIN, and is never available to the MCP.
 *
 * Every entry is live, in AdminController, each calling the Firefly service upstream's own
 * controller or command calls.
 */
final class AdminRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $a = AdminController::class;

        return [
            RouteDef::live('POST', '/admin/key/rotate', 'admin', 'Mint a new machine key; the response is the fingerprint only.', [$a, 'rotateKey'], ['phase' => 'P10', 'dryRun' => false]),
            RouteDef::live('POST', '/admin/cron', 'admin', 'Run Firefly\'s cron now (recurring transactions, auto-budgets, bill warnings, webhooks). The dry run reports what each job would create.', [$a, 'cron'], ['phase' => 'P10']),
            RouteDef::live('POST', '/admin/correct-database', 'admin', 'Run Firefly\'s integrity repairs; the response lists what each corrector changed.', [$a, 'correctDatabase'], ['phase' => 'P10']),
            RouteDef::live('POST', '/admin/data/destroy', 'admin', 'Firefly\'s data/destroy for the listed object types.', [$a, 'destroyData'], ['phase' => 'P10']),
            RouteDef::live('POST', '/admin/data/purge', 'admin', 'Permanently remove soft-deleted rows — after which undo of those rows is impossible.', [$a, 'purgeData'], ['phase' => 'P10']),
            RouteDef::live('GET', '/admin/users', 'admin', 'The install\'s users (for choosing FIREFLY_MACHINE_OPERATOR).', [$a, 'users'], ['phase' => 'P10']),
            RouteDef::live('GET', '/admin/configuration', 'admin', 'The install\'s dynamic configuration values.', [$a, 'configuration'], ['phase' => 'P10']),
            RouteDef::live('POST', '/webhooks', 'admin', 'Create a webhook.', [$a, 'createWebhook'], ['phase' => 'P10']),
            RouteDef::live('PUT', '/webhooks/{id}', 'admin', 'Edit a webhook.', [$a, 'updateWebhook'], ['phase' => 'P10']),
            RouteDef::live('POST', '/webhooks/{id}/submit', 'admin', 'Send a webhook\'s pending messages now. The dry run says how many would leave.', [$a, 'submitWebhook'], ['phase' => 'P10']),
            RouteDef::live('DELETE', '/webhooks/{id}', 'admin', 'Delete a webhook.', [$a, 'deleteWebhook'], ['phase' => 'P10']),
        ];
    }
}
