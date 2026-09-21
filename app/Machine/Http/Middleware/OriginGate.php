<?php

/*
 * OriginGate.php
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
 * Gate 2 — origin and host (apis.mdx §4.7).
 *
 * A browser always sends Origin or Sec-Fetch-Site and cannot be talked out of it; ffx, the MCP
 * and curl send neither, so PRESENCE is the signal. Refused with the stealth 404:
 *   - any Origin header (even "null");
 *   - Sec-Fetch-Site of cross-site, same-site or none (same-origin cannot reach a JSON API
 *     without a page on this origin, and there is none; it is refused too — any value);
 *   - a key in the query string (the habit must not form: query strings land in logs, §4.6);
 *   - a Host whose hostname is not 127.0.0.1, localhost or [::1] — DNS rebinding has to lie
 *     about exactly this. Any port is accepted, so an ephemeral test port works.
 */
final class OriginGate
{
    /** Query parameter names that can only mean "the key in the URL". */
    private const array KEY_PARAMS = ['key', 'api_key', 'apikey', 'machine_key', 'x-firefly-machine-key', 'x_firefly_machine_key', 'firefly_machine_key', 'token', 'access_token'];

    public function handle(Request $request, Closure $next): Response
    {
        if (self::isBrowserRequest($request) || self::carriesKeyInQuery($request) || !self::isAllowedHost($request)) {
            return Envelope::stealth404();
        }

        return $next($request);
    }

    public static function isBrowserRequest(Request $request): bool
    {
        if ($request->headers->has('Origin')) {
            return true;
        }

        return $request->headers->has('Sec-Fetch-Site');
    }

    public static function carriesKeyInQuery(Request $request): bool
    {
        foreach ($request->query->all() as $name => $value) {
            if (in_array(strtolower((string) $name), self::KEY_PARAMS, true)) {
                return true;
            }
            foreach ((array) $value as $v) {
                if (is_string($v) && 1 === preg_match('/^[0-9a-f]{64}$/i', trim($v))) {
                    return true;
                }
            }
        }
        // a raw query string with no parsed keys can still carry a 64-hex run
        $raw = (string) $request->server->get('QUERY_STRING', '');

        return 1 === preg_match('/(?:^|[=&])[0-9a-fA-F]{64}(?:$|&)/', $raw);
    }

    public static function isAllowedHost(Request $request): bool
    {
        $host = $request->headers->get('Host');
        if (!is_string($host) || '' === $host) {
            return false;
        }
        $host = strtolower(trim($host));
        // strip the port: "[::1]:7373" → "[::1]", "127.0.0.1:7373" → "127.0.0.1"
        if (str_starts_with($host, '[')) {
            $end  = strpos($host, ']');
            $name = false === $end ? $host : substr($host, 0, $end + 1);
            $rest = false === $end ? '' : substr($host, $end + 1);
        } else {
            $parts = explode(':', $host);
            if (count($parts) > 2) {
                return false;
            }
            $name  = $parts[0];
            $rest  = isset($parts[1]) ? ':'.$parts[1] : '';
        }
        if ('' !== $rest && 1 !== preg_match('/^:\d{1,5}$/', $rest)) {
            return false;
        }
        $allowed = (array) config('machine.allowed_hosts', ['127.0.0.1', 'localhost', '[::1]']);

        return in_array($name, array_map('strtolower', $allowed), true);
    }
}
