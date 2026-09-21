<?php

/*
/*
 * ErrorReportGate.php
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

namespace FireflyIII\Machine\ErrorFile\Http;

use Closure;
use FireflyIII\Machine\Http\Middleware\LoopbackGate;
use FireflyIII\Machine\Http\Middleware\OriginGate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ingest guards 1–5 of pm/error_err.mdx §4.10, before any body is read:
 *
 *  1. loopback — REMOTE_ADDR ∈ LoopbackGate::LOOPBACK, TrustProxies ignored, exactly as the plane;
 *  2. same origin — Host passes OriginGate::isAllowedHost(); Sec-Fetch-Site, when present, is
 *     `same-origin`; Origin is absent, or the literal `null` together with Sec-Fetch-Site
 *     `same-origin` (the sendBeacon), or a serialised origin that isSameOrigin() (the keepalive fetch);
 *  3. method — POST (the real method, never a `_method` override);
 *  4. content type — starts with `text/plain` or `application/json`;
 *  5. size — Content-Length present and at most `errorfile.ingest.body_cap` (65,536).
 *
 * Every refusal is an empty 204 with `Cache-Control: no-store`, and nothing is written: nothing
 * about the route is probe-able. The only request reads are the named ones of §4.1 (R12).
 */
final class ErrorReportGate
{
    public const int BODY_CAP = 65_536;

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::passes($request)) {
            return self::nothing();
        }

        return $next($request);
    }

    /** The empty answer every refusal and every accepted report gets. */
    public static function nothing(): Response
    {
        return new Response('', 204, ['Cache-Control' => 'no-store']);
    }

    /** Guards 1–5, in order. Total: anything unexpected refuses. */
    public static function passes(Request $request): bool
    {
        try {
            return self::isLoopback($request)
                && self::isSameSite($request)
                && 'POST' === strtoupper($request->getRealMethod())
                && self::isAcceptedType($request)
                && self::isSmallEnough($request);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Guard 2's helper: $origin is a serialised origin `http://host[:port]` whose host is in
     * `machine.allowed_hosts` and whose whole scheme://host:port (default port filled in) EQUALS the
     * request's own — same origin, not merely an allowed host name on another port.
     */
    public static function isSameOrigin(string $origin, Request $request): bool
    {
        try {
            $mine = self::normalise($origin);
            if (null === $mine || 'http' !== $mine['scheme']) {
                return false;
            }
            $allowed = array_map(static fn (mixed $h): string => strtolower((string) $h), (array) config('machine.allowed_hosts', ['127.0.0.1', 'localhost', '[::1]']));
            if (!in_array($mine['host'], $allowed, true)) {
                return false;
            }
            $own = self::normalise($request->getSchemeAndHttpHost());

            return null !== $own && $own === $mine;
        } catch (Throwable) {
            return false;
        }
    }

    private static function isLoopback(Request $request): bool
    {
        $peer = $request->server->get('REMOTE_ADDR');

        return is_string($peer) && in_array(strtolower($peer), LoopbackGate::LOOPBACK, true);
    }

    private static function isSameSite(Request $request): bool
    {
        if (!OriginGate::isAllowedHost($request)) {
            return false;
        }
        $site = $request->headers->get('Sec-Fetch-Site');
        if (null !== $site && 'same-origin' !== strtolower(trim($site))) {
            return false;
        }
        $origin = $request->headers->get('Origin');
        if (null === $origin) {
            return true;
        }
        if ('null' === trim($origin)) {
            return null !== $site;   // only together with Sec-Fetch-Site: same-origin (checked above)
        }

        return self::isSameOrigin(trim($origin), $request);
    }

    private static function isAcceptedType(Request $request): bool
    {
        $type = strtolower(trim((string) $request->headers->get('Content-Type')));

        return str_starts_with($type, 'text/plain') || str_starts_with($type, 'application/json');
    }

    private static function isSmallEnough(Request $request): bool
    {
        $length = trim((string) $request->headers->get('Content-Length'));
        if (1 !== preg_match('/^\d{1,9}$/', $length)) {
            return false;
        }
        $cap = (int) config('errorfile.ingest.body_cap', self::BODY_CAP);

        return (int) $length <= ($cap > 0 ? $cap : self::BODY_CAP);
    }

    /**
     * `scheme://host[:port]` → [scheme, host, port] with the default port filled in, or null when it
     * is not a bare serialised origin (a path, query, fragment or user part refuses).
     *
     * @return null|array{scheme: string, host: string, port: int}
     */
    private static function normalise(string $origin): ?array
    {
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (isset($parts['path']) && '' !== $parts['path']) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port   = $parts['port'] ?? ('https' === $scheme ? 443 : 80);

        return ['scheme' => $scheme, 'host' => strtolower($parts['host']), 'port' => (int) $port];
    }
}
