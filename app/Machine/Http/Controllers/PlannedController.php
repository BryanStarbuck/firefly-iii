<?php

/*
 * PlannedController.php
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

namespace FireflyIII\Machine\Http\Controllers;

use FireflyIII\Machine\MachineException;
use Illuminate\Http\Request;

/**
 * The handler of every declared-but-unbuilt route — apis.mdx §6.3a. A planned route is mounted,
 * tiered and gated like a live one (so gates 4 and 6 are exercised end to end from day one), and
 * then refuses `not_ready` naming the phase that will build it.
 */
final class PlannedController extends MachineController
{
    public function planned(Request $request): never
    {
        $action = $request->route()?->getAction() ?? [];
        $route  = (string) ($action['machine_key'] ?? $request->method().' '.$request->path());
        $phase  = (string) ($action['machine_phase'] ?? '');
        $phase  = '' === $phase ? '?' : $phase;

        throw MachineException::notReady(
            sprintf('%s is declared but not built yet (phase %s).', $route, $phase),
            sprintf('GET /machine/v1/capabilities lists what this build has live; this route arrives in phase %s', $phase),
            ['route' => $route, 'status' => 'planned', 'phase' => $phase],
        );
    }
}
