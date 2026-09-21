<?php

/*
 * RuleRoutes.php
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

use FireflyIII\Machine\Http\Controllers\RuleController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §8.7 — rules and rule groups, the categorisation engine. /rules/preview (read) makes rule authorship safe: see what it would touch, then save it.
 *
 * Every route is live; the handler is RuleController.
 */
final class RuleRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = RuleController::class;

        return [
            RouteDef::live('GET', '/rule-groups', 'read', 'Rule groups with their rules, in execution order.', [$c, 'groups'], ['phase' => 'P2']),
            RouteDef::live('GET', '/rules', 'read', 'Rules (rule_group_id|rule_group_name, search, trigger), in execution order.', [$c, 'index'], ['phase' => 'P2']),
            RouteDef::live('GET', '/rules/{id}', 'read', 'One rule, with its triggers and actions.', [$c, 'show'], ['phase' => 'P2']),
            RouteDef::live('POST', '/rules/validate', 'read', 'Validate a rule\'s triggers, actions and expressions without saving it.', [$c, 'validateRule'], ['phase' => 'P8']),
            RouteDef::live('POST', '/rules/preview', 'read', 'Which existing journals a rule (saved or not) would match, and what each action would change.', [$c, 'preview'], ['phase' => 'P8']),
            RouteDef::live('POST', '/rule-groups/{id}/preview', 'read', 'The same preview for a whole rule group.', [$c, 'previewGroup'], ['phase' => 'P8']),
            RouteDef::live('POST', '/rules', 'write', 'Create a rule.', [$c, 'store'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/rules/{id}', 'write', 'Edit a rule.', [$c, 'update'], ['phase' => 'P8']),
            RouteDef::live('POST', '/rules/{id}/move', 'write', 'Reorder a rule, or move it to another group.', [$c, 'move'], ['phase' => 'P8']),
            RouteDef::live('POST', '/rules/run', 'write', 'Run rules over existing transactions (previewed through the dry-run harness).', [$c, 'run'], ['phase' => 'P8']),
            RouteDef::live('POST', '/rule-groups', 'write', 'Create a rule group.', [$c, 'storeGroup'], ['phase' => 'P8']),
            RouteDef::live('PUT', '/rule-groups/{id}', 'write', 'Edit a rule group.', [$c, 'updateGroup'], ['phase' => 'P8']),
            RouteDef::live('DELETE', '/rules/{id}', 'admin', 'Delete a rule.', [$c, 'destroy'], ['phase' => 'P10']),
            RouteDef::live('DELETE', '/rule-groups/{id}', 'admin', 'Delete a rule group, optionally moving its rules.', [$c, 'destroyGroup'], ['phase' => 'P10']),
        ];
    }
}
