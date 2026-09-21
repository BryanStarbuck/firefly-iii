<?php

/*
 * MachineKeyGate.php
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
use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\Operator;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 3 — the machine key, then the operator binding (apis.mdx §4.6, §4.9).
 *
 *   - No key resolvable (the plane is unarmed): the stealth 404 for everything (R9, §4.4 rule 4).
 *   - The key travels ONLY in X-Firefly-Machine-Key (never Authorization: Bearer — that is a
 *     Passport token to the api guard, and is never accepted here).
 *   - hash_equals over two SHA-256 digests, so one equal-length comparison always runs.
 *   - A missing and a wrong key get byte-for-byte the same 401 body.
 *   - Then the request is bound to one operator user and one administration. ping, whoami,
 *     capabilities and health answer without one (their route says operator: false); every
 *     other route refuses not_ready / forbidden with the candidates.
 */
final class MachineKeyGate
{
    public const string HEADER = 'X-Firefly-Machine-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = CredentialsFile::resolve();
        if (null === $key) {
            return Envelope::stealth404();
        }
        $presented = $request->headers->get(self::HEADER);
        if (!$key->matches(is_string($presented) ? $presented : '') || !is_string($presented) || '' === $presented) {
            return Envelope::unauthorized();
        }
        $request->attributes->set('machine.key', $key);

        return $this->bindOperator($request, $next);
    }

    private function bindOperator(Request $request, Closure $next): Response
    {
        $needsOperator = false !== $request->route()?->getAction('machine_operator');
        if (!$needsOperator) {
            Operator::tryBind($request);

            return $next($request);
        }
        $refusal = Operator::tryBind($request);
        if (null !== $refusal) {
            return Envelope::error($refusal);
        }

        return $next($request);
    }
}
