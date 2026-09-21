<?php

/*
 * Context.php
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

namespace FireflyIII\Machine\ErrorFile;

use FireflyIII\Machine\Clock;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Throwable;

/**
 * `app`, `doing`, the context data keys and the correlation id of a record — pm/error_err.mdx §3.3,
 * §4.8. Computed at WRITE time only, never on a happy path (R3).
 *
 * `app`: a throwable in the job-failure map, or an open job frame → php-queue; the `cli-server` SAPI
 * or a current route → by request path (machine/v1 → php-machine, api/v1 or oauth/ → php-api,
 * else php-web); otherwise php-artisan.
 *
 * `doing` for a net record: `running job {Basename}`; `handling {METHOD} /{route template}` (the raw
 * path is NEVER used — a template never carries a ledger value) or `handling {METHOD} (no route)`;
 * `running artisan {command}` from the command stack, then lastFailedCommand, then argv[1] when it
 * matches ^[a-z0-9:_-]+$; else `running the app`.
 *
 * Header reads (R12) — exactly two, both here: X-Firefly-Request-Id (adopted as the rid when it
 * matches ^[0-9a-f]{8,16}$, else a rid is minted lazily) and X-Firefly-Client (kept only when it
 * matches [a-z0-9_.-]{1,32}; Audit::caller() is deliberately not called). The HTTP method is
 * getRealMethod(), which never reads a `_method` override from the body.
 *
 * Every method is total.
 */
final class Context
{
    /** The context keys, in their fixed order (§3.2). */
    public const array KEYS = ['net', 'route', 'status', 'code', 'caller', 'rid', 'took_ms', 'during', 'via', 'pid'];

    /** The value of the `app` column for a record about $candidates (the first is the one written). */
    public static function app(?Throwable ...$candidates): string
    {
        try {
            $state = RequestState::current();
            if (null !== $state) {
                foreach ($candidates as $candidate) {
                    if (null !== $candidate && null !== $state->jobFailure($candidate)) {
                        return 'php-queue';
                    }
                }
                if (null !== $state->openJob()) {
                    return 'php-queue';
                }
            }
            $request = self::request();
            if (null !== $request && self::isHttp($request)) {
                return self::appForPath($request->path());
            }

            return 'php-artisan';
        } catch (Throwable) {
            return 'php-artisan';
        }
    }

    /** `doing` for a net record (§4.8). */
    public static function doing(?Throwable ...$candidates): string
    {
        try {
            $job = self::job(...$candidates);
            if (null !== $job) {
                return 'running job '.$job['job'];
            }

            return self::doingHere();
        } catch (Throwable) {
            return 'running the app';
        }
    }

    /**
     * `doing` for the current moment, ignoring jobs — what a job's dispatcher was doing
     * (data.during of a job record).
     */
    public static function doingHere(): string
    {
        try {
            $request = self::request();
            if (null !== $request && self::isHttp($request)) {
                $route  = self::route($request);
                $method = strtoupper($request->getRealMethod());

                return null === $route ? sprintf('handling %s (no route)', $method) : sprintf('handling %s /%s', $method, ltrim($route->uri(), '/'));
            }
            $state = RequestState::current();
            $top   = null === $state || [] === $state->commands ? '' : $state->commands[count($state->commands) - 1];
            if ('' !== $top) {
                return 'running artisan '.$top;
            }
            if (null !== $state && null !== $state->lastFailedCommand) {
                return 'running artisan '.$state->lastFailedCommand;
            }
            $argv = $_SERVER['argv'] ?? null;
            if (is_array($argv) && isset($argv[1]) && is_string($argv[1]) && 1 === preg_match('/^[a-z0-9:_-]+$/', $argv[1])) {
                return 'running artisan '.$argv[1];
            }
        } catch (Throwable) {
            // total
        }

        return 'running the app';
    }

    /**
     * The context keys (§3.2 order), for a record about $e. $net is the net that caught it (null for
     * an explicit site); $over supplies status/code for an unwrapped MachineException. A FATAL gets
     * no took_ms (the cheap path).
     *
     * @param array<string, null|int|string> $over
     *
     * @return array<string, int|string>
     */
    public static function base(?Throwable $e, ?string $net, array $over = [], bool $fatal = false, ?Throwable $original = null): array
    {
        $out = [];

        try {
            $request = self::request();
            $http    = null !== $request && self::isHttp($request);
            $route   = $http ? self::route($request) : null;
            $job     = self::job($e, $original);
            $status  = $over['status'] ?? ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : null);
            $values  = [
                'net'     => $net,
                'route'   => null === $route ? null : $route->getName(),
                'status'  => $status,
                'code'    => $over['code'] ?? null,
                'caller'  => $http ? self::caller($request) : null,
                'rid'     => $http ? self::rid() : null,
                'took_ms' => $http && !$fatal ? Clock::elapsedMs($request) : null,
                'during'  => $job['during'] ?? null,
                'via'     => $over['via'] ?? null,
                'pid'     => getmypid(),
            ];
            foreach (self::KEYS as $key) {
                $value = $values[$key];
                if (null !== $value && '' !== $value && false !== $value) {
                    $out[$key] = is_int($value) ? $value : (string) $value;
                }
            }
        } catch (Throwable) {
            $pid = getmypid();
            if (false !== $pid) {
                $out['pid'] = $pid;
            }
        }

        return $out;
    }

    /** The correlation id: adopted from X-Firefly-Request-Id, else minted once. Only called when writing. */
    public static function rid(): ?string
    {
        try {
            $state = RequestState::current();
            if (null !== $state && null !== $state->rid) {
                return $state->rid;
            }
            $rid     = null;
            $request = self::request();
            if (null !== $request) {
                $raw = $request->header('X-Firefly-Request-Id');
                if (is_string($raw) && 1 === preg_match('/^[0-9a-f]{8,16}$/', $raw)) {
                    $rid = $raw;
                }
            }
            $rid ??= bin2hex(random_bytes(4));
            if (null !== $state) {
                $state->rid = $rid;
            }

            return $rid;
        } catch (Throwable) {
            return null;
        }
    }

    /** X-Firefly-Client, kept only when it matches [a-z0-9_.-]{1,32}. */
    public static function caller(Request $request): ?string
    {
        try {
            $raw = $request->header('X-Firefly-Client');
            if (!is_string($raw)) {
                return null;
            }
            $raw = strtolower(trim($raw));

            return 1 === preg_match('/^[a-z0-9_.-]{1,32}$/', $raw) ? $raw : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The app for a request path (the path decides the value; it is never written). */
    public static function appForPath(string $path): string
    {
        $path = ltrim($path, '/');
        if ('machine/v1' === $path || str_starts_with($path, 'machine/v1/')) {
            return 'php-machine';
        }
        if (str_starts_with($path, 'api/v1') || str_starts_with($path, 'oauth/')) {
            return 'php-api';
        }

        return 'php-web';
    }

    /** @return null|array{job: string, during: string} */
    private static function job(?Throwable ...$candidates): ?array
    {
        $state = RequestState::current();
        if (null === $state) {
            return null;
        }
        foreach ($candidates as $candidate) {
            if (null !== $candidate) {
                $hit = $state->jobFailure($candidate);
                if (null !== $hit) {
                    return $hit;
                }
            }
        }

        return $state->openJob();
    }

    private static function request(): ?Request
    {
        try {
            $app = Container::getInstance();
            if (!$app->bound('request')) {
                return null;
            }
            $request = $app->make('request');

            return $request instanceof Request ? $request : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function isHttp(Request $request): bool
    {
        return 'cli-server' === PHP_SAPI || null !== self::route($request);
    }

    private static function route(Request $request): ?Route
    {
        try {
            $route = $request->route();

            return $route instanceof Route ? $route : null;
        } catch (Throwable) {
            return null;
        }
    }
}
