<?php

/*
 * machine.php
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

use FireflyIII\Machine\Http\Controllers\PlaneController;
use FireflyIII\Machine\Http\Controllers\PlannedController;
use FireflyIII\Machine\Http\Middleware\LoopbackGate;
use FireflyIII\Machine\Http\Middleware\MachineKeyGate;
use FireflyIII\Machine\Http\Middleware\OriginGate;
use FireflyIII\Machine\Http\Middleware\PlaneResponse;
use FireflyIII\Machine\Http\Middleware\RequestGate;
use FireflyIII\Machine\Http\Middleware\TierGate;
use FireflyIII\Machine\RouteTable;
use Illuminate\Support\Facades\Route;

/*
 * The machine plane — pm/apis.mdx. Built from RouteTable and NOTHING else (§6.3): the same array
 * /capabilities publishes. Its own middleware list — the gates in order (§4.7) — and never the
 * `web` group (sessions, CSRF) or the `api` group (Passport).
 *
 *   PlaneResponse   no-store, Vary: Origin, no CORS grant, JSON only
 *   LoopbackGate    gate 1: REMOTE_ADDR is loopback
 *   OriginGate      gate 2: no Origin / Sec-Fetch-Site, no key in the URL, a loopback Host
 *   MachineKeyGate  gate 3: the key (constant-time), then the operator binding
 *   TierGate        gate 4: write / admin switches; admin never from the MCP
 *   RequestGate     the body cap and JSON shape; gate 5 proper is each route's input()
 */
Route::prefix('machine/v1')
    ->middleware([PlaneResponse::class, LoopbackGate::class, OriginGate::class, MachineKeyGate::class, TierGate::class, RequestGate::class])
    ->group(static function (): void {
        foreach (RouteTable::mountOrder() as $def) {
            [$class, $method] = $def->handler ?? [PlannedController::class, 'planned'];
            $route            = Route::match([$def->method], ltrim($def->path, '/'), [
                'uses'             => $class.'@'.$method,
                'machine_key'      => $def->key(),
                'machine_tier'     => $def->tier,
                'machine_status'   => $def->status,
                'machine_phase'    => $def->phase,
                'machine_dry_run'  => $def->dryRun,
                'machine_operator' => $def->needsOperator,
                'machine_composed' => $def->composed,
            ]);
            foreach ($def->parameters() as $param) {
                $route->where($param, 'path' === $param ? '.+' : '[^/]+');
            }
            foreach ($def->where as $param => $pattern) {
                $route->where($param, $pattern);
            }
        }

        // The terminal 404, as the envelope — after every real route (§6.3: every published
        // entry answers rather than falling through to here).
        Route::any('{machine_any?}', [
            'uses'             => PlaneController::class.'@fallback',
            'machine_key'      => 'ANY *',
            'machine_tier'     => 'read',
            'machine_status'   => 'live',
            'machine_phase'    => '',
            'machine_dry_run'  => false,
            'machine_operator' => false,
            'machine_composed' => false,
        ])->where('machine_any', '.*');
    })
;
