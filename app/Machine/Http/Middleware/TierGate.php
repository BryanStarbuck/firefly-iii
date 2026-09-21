<?php

/*
 * TierGate.php
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

namespace FireflyIII\Machine\Http\Middleware;

use Closure;
use FireflyIII\Machine\Audit;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 4 — the tier (apis.mdx §6.1). Read is always on. Write needs FIREFLY_MACHINE_ALLOW_WRITE.
 * Admin needs FIREFLY_MACHINE_ALLOW_ADMIN — a separate switch, not "write plus" — and is refused
 * outright when the caller says it is the MCP (X-Firefly-Client: mcp): the header is advisory
 * everywhere else, but a refusal keyed on it is safe, because lying about it can only take
 * privilege away. Every refusal is audited (§16.4).
 */
final class TierGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $action = $request->route()?->getAction() ?? [];
        $tier   = (string) ($action['machine_tier'] ?? 'read');
        $route  = (string) ($action['machine_key'] ?? $request->method().' '.$request->path());

        $refusal = match ($tier) {
            'write' => config('machine.allow_write') ? null : MachineException::writeDisabled($route),
            'admin' => self::adminRefusal($request, $route),
            default => null,
        };
        if (null !== $refusal) {
            Audit::line($request, ['route' => $route, 'tier' => $tier, 'ok' => false, 'code' => $refusal->errorCode]);

            return Envelope::error($refusal);
        }

        return $next($request);
    }

    private static function adminRefusal(Request $request, string $route): ?MachineException
    {
        if ('mcp' === Audit::caller($request)) {
            return MachineException::forbidden(
                sprintf('%s is an admin route, and the admin tier is never available to the MCP.', $route),
                'Do this yourself in a terminal (ffx, with FIREFLY_MACHINE_ALLOW_ADMIN=1) or in the Firefly III web UI',
                ['tier' => 'admin'],
            );
        }
        if (!config('machine.allow_admin')) {
            return MachineException::forbidden(
                sprintf('%s is an admin route and the admin tier is off on this server.', $route),
                'Set FIREFLY_MACHINE_ALLOW_ADMIN=1 in the app\'s .env and restart it (ffx stop && ffx up) — only while you are watching',
                ['tier' => 'admin', 'switch' => 'FIREFLY_MACHINE_ALLOW_ADMIN'],
            );
        }

        return null;
    }
}
