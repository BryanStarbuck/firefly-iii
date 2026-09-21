<?php

/*
 * LoopbackGate.php
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
use FireflyIII\Machine\Envelope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate 1 — the loopback socket (apis.mdx §4.7).
 *
 * TrustProxies is in the global middleware stack, so $request->ip() / ips() may be derived from
 * X-Forwarded-For, which any client can send. This gate reads REMOTE_ADDR — the socket peer as
 * the kernel saw it — and NEVER ip(), ips(), X-Forwarded-For or X-Real-IP. Anything but
 * 127.0.0.1, ::1 or ::ffff:127.0.0.1 gets the stealth 404. No flag turns this off (R10).
 */
final class LoopbackGate
{
    public const array LOOPBACK = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::isLoopbackPeer($request)) {
            return Envelope::stealth404();
        }

        return $next($request);
    }

    public static function isLoopbackPeer(Request $request): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');

        return is_string($peer) && in_array(strtolower($peer), self::LOOPBACK, true);
    }
}
