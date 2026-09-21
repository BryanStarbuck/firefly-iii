<?php

/*
 * MirrorController.php
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

use Closure;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * pm/apis.mdx §8.12 — the mirror: any GET under upstream's /api/v1, read-only, as the operator.
 *
 * In-process, not a loopback HTTP call: an internal sub-request for /api/v1/{path} is matched
 * against upstream's own route table and dispatched through the router, so upstream's own
 * middleware, validators and transformers answer it, with the operator already bound on the
 * `api` guard (Operator::bind) — no Passport token ever needs to exist.
 *
 *   - GET only. Any other method never reaches here (the plane mounts GET only) and upstream
 *     routes that are not GET are refused by matching.
 *   - A denylist BY UPSTREAM ROUTE TEMPLATE (never by the raw string, so encoding cannot slip past
 *     it): users/*, configuration/*, cron/*, data/export/*, and the binary attachment download —
 *     each refused `forbidden` with the typed route that replaces it.
 *   - Secrets never pass (§16.2): preferences that hold tokens or 2FA state are dropped, and any
 *     field named like a secret (a webhook's signing secret…) is redacted.
 *   - The answer is upstream's JSON:API flattened one level (data[].attributes → the row), under
 *     `result`, with upstream's pagination under `pagination`; over 8 MiB a list is cut and
 *     meta.truncated says so.
 */
final class MirrorController extends MachineController
{
    /**
     * Upstream route templates (relative to api/v1/) the mirror refuses, with the hint that names
     * the typed route. A template matches itself and everything under it.
     */
    public const array DENIED_PREFIXES = [
        'users'                             => 'GET /machine/v1/admin/users (admin tier) lists the install\'s users',
        'configuration'                     => 'GET /machine/v1/admin/configuration (admin tier) shows the install\'s configuration',
        'cron'                              => 'POST /machine/v1/admin/cron (admin tier) runs Firefly\'s cron',
        'data/export'                       => 'GET /machine/v1/transactions/export exports transactions',
        'attachments/{attachment}/download' => 'GET /machine/v1/attachments/{id}/download returns the file',
    ];

    /** Preference names that hold secrets or 2FA state (§16.2) — never mirrored. */
    public const array SECRET_PREFERENCES = ['access_token', 'mfa_recovery', 'mfa_history', 'mfa_failure_count', 'temp-mfa-secret', 'ntfy_auth', 'pushover_app_token', 'pushover_user_token', 'slack_webhook_url', 'ntfy_pass'];

    /** Keys whose values are redacted wherever they appear in a mirrored answer. */
    private const string SECRET_KEY_PATTERN = '/^(secret|password|password_confirmation|api_key|access_token|refresh_token|token|mfa_secret|client_secret|.*_secret|.*_password)$/i';

    public function mirror(Request $request, string $path): JsonResponse
    {
        $path     = self::normalisePath($path);
        $query    = $request->query->all();
        $upstream = self::matchUpstream($request, $path);
        $template = self::relativeTemplate($upstream);
        self::refuseDenied($template, $path);

        $response = self::dispatchInternal($request, 'GET', '/api/v1/'.$path, $query, null, ['Accept' => 'application/json']);
        $status   = $response->getStatusCode();
        $content  = (string) $response->getContent();
        $type     = (string) $response->headers->get('Content-Type');

        if (204 === $status || '' === trim($content)) {
            return $this->ok(['path' => 'api/v1/'.$path, 'route' => 'GET /api/v1/'.$template, 'status' => $status, 'result' => null, 'pagination' => null]);
        }
        $decoded  = str_contains($type, 'json') || in_array(ltrim($content)[0] ?? '', ['{', '['], true) ? json_decode($content, true) : null;
        if (!is_array($decoded)) {
            throw MachineException::invalid('That upstream route does not answer JSON.', 'Use a typed /machine/v1 route for files and downloads', ['path' => 'api/v1/'.$path, 'content_type' => $type]);
        }
        if ($status >= 400) {
            throw self::upstreamError($status, $decoded, $path);
        }

        $result     = self::flatten($decoded);
        $result     = self::scrubSecrets($result, $template);
        $pagination = is_array($decoded['meta']['pagination'] ?? null) ? $decoded['meta']['pagination'] : null;
        [$result, $cut] = self::capBytes($result);

        return $this->ok(
            ['path' => 'api/v1/'.$path, 'route' => 'GET /api/v1/'.$template, 'status' => $status, 'result' => $result, 'pagination' => $pagination],
            [
                'truncated' => $cut > 0 || (is_array($pagination) && (int) ($pagination['current_page'] ?? 1) < (int) ($pagination['total_pages'] ?? 1)),
                'mirrored'  => true,
                'untrusted' => ['description', 'notes', 'name', 'title', 'account_name', 'tags', 'internal_reference'],
            ] + ($cut > 0 ? ['dropped_rows' => $cut, 'hint' => 'The answer was over 8 MiB — narrow it with the route\'s own filters (start, end, limit, page)'] : []),
        );
    }

    // ------------------------------------------------------------------------

    /**
     * Dispatch an internal sub-request through the router — in-process, as the bound operator —
     * and return the response. The container's request and the router's current route are
     * swapped for the call and restored afterwards, so the caller's request is untouched. The
     * sub-request inherits the caller's peer, host and headers (so the plane's own gates pass for
     * a /machine/v1 sub-request, POST /batch) and gets its own JSON body.
     *
     * @param array<string, mixed>        $query
     * @param null|array<string, mixed>   $body
     * @param array<string, string>       $headers
     */
    public static function dispatchInternal(Request $parent, string $method, string $uri, array $query = [], ?array $body = null, array $headers = []): Response
    {
        $server = $parent->server->all();
        unset($server['CONTENT_LENGTH'], $server['HTTP_CONTENT_LENGTH'], $server['QUERY_STRING'], $server['REQUEST_URI']);
        // the query always travels in the URI: Request::create() would put it in the BODY of a non-GET
        $uri    = [] === $query ? $uri : $uri.'?'.http_build_query($query);
        $sub    = Request::create($uri, $method, [], [], [], $server, null === $body ? null : ([] === $body ? '{}' : Envelope::encode($body)));
        foreach ($parent->headers->all() as $name => $values) {
            if (in_array(strtolower((string) $name), ['content-length', 'content-type', 'accept'], true)) {
                continue;
            }
            $sub->headers->set((string) $name, $values);
        }
        $sub->headers->set('Accept', 'application/json');
        if (null !== $body) {
            $sub->headers->set('Content-Type', 'application/json');
        }
        foreach ($headers as $name => $value) {
            $sub->headers->set($name, $value);
        }
        $sub->setUserResolver($parent->getUserResolver());

        /** @var Router $router */
        $router      = app('router');
        $container   = app();
        $outerRoute  = $container->bound(Route::class) ? $container->make(Route::class) : null;
        $outerRouter = self::routerState($router);

        $container->instance('request', $sub);

        try {
            return $router->dispatch($sub);
        } finally {
            $container->instance('request', $parent);
            if (null !== $outerRoute) {
                $container->instance(Route::class, $outerRoute);
            }
            self::restoreRouterState($router, $outerRouter);
        }
    }

    /** The upstream GET route this path would reach, or not_found. */
    private static function matchUpstream(Request $request, string $path): Route
    {
        $probe = Request::create('/api/v1/'.$path, 'GET');

        try {
            $route = app('router')->getRoutes()->match($probe);
        } catch (HttpExceptionInterface) {
            throw MachineException::notFound(sprintf('Upstream has no GET route at api/v1/%s.', $path), 'Upstream\'s read routes are listed at https://api-docs.firefly-iii.org — or prefer a typed route from GET /machine/v1/capabilities', ['path' => 'api/v1/'.$path]);
        }
        if (!str_starts_with($route->uri(), 'api/v1/') || !in_array('GET', $route->methods(), true)) {
            throw MachineException::notFound(sprintf('Upstream has no GET route at api/v1/%s.', $path), 'GET /machine/v1/capabilities lists the typed routes', ['path' => 'api/v1/'.$path]);
        }

        return $route;
    }

    /** "accounts/{account}" — the route's template relative to api/v1/. */
    public static function relativeTemplate(Route $route): string
    {
        return substr($route->uri(), strlen('api/v1/'));
    }

    /** The denylist, applied to the TEMPLATE of the matched upstream route. */
    public static function deniedBy(string $template): ?string
    {
        foreach (array_keys(self::DENIED_PREFIXES) as $prefix) {
            if ($template === $prefix || str_starts_with($template, $prefix.'/')) {
                return $prefix;
            }
        }

        return null;
    }

    private static function refuseDenied(string $template, string $path): void
    {
        $prefix = self::deniedBy($template);
        if (null !== $prefix) {
            throw MachineException::forbidden(
                sprintf('The mirror does not serve api/v1/%s.', $prefix),
                self::DENIED_PREFIXES[$prefix],
                ['path' => 'api/v1/'.$path, 'denied' => $prefix],
            );
        }
        if (1 === preg_match('#^preferences(-list)?/(.+)$#', $path, $m)) {
            foreach (explode(',', $m[2]) as $name) {
                if (self::isSecretPreference($name)) {
                    throw MachineException::forbidden('That preference holds a secret and is never mirrored.', 'GET /machine/v1/mirror/preferences lists the preferences that can be read', ['path' => 'api/v1/'.$path]);
                }
            }
        }
    }

    private static function normalisePath(string $path): string
    {
        $path     = trim(str_replace('\\', '/', $path), '/');
        $path     = (string) preg_replace('#^api/v1/#', '', $path);
        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ('' === $segment || '.' === $segment || '..' === $segment || 1 === preg_match('/[\x00-\x1f]/', $segment)) {
                throw MachineException::invalid('That is not a clean upstream path.', 'Pass the path under /api/v1 without "." or ".." segments, e.g. insight/expense/category?start=2026-01-01&end=2026-06-30', ['path' => $path]);
            }
        }

        return $path;
    }

    /**
     * Upstream's JSON:API, flattened one level: {data: {id, type, attributes}} → {id, resource, …attributes};
     * a list the same per row. Anything that is not JSON:API (insight, chart, summary) passes as is.
     *
     * @param array<mixed> $decoded
     */
    public static function flatten(array $decoded): mixed
    {
        if (!array_key_exists('data', $decoded)) {
            return $decoded;
        }
        $data = $decoded['data'];
        if (is_array($data) && array_is_list($data)) {
            return array_map(static fn ($row) => is_array($row) ? self::flattenOne($row) : $row, $data);
        }

        return is_array($data) ? self::flattenOne($data) : $data;
    }

    /** @param array<mixed> $row */
    private static function flattenOne(array $row): array
    {
        if (!isset($row['attributes']) || !is_array($row['attributes'])) {
            return $row;
        }
        // JSON:API's resource `type` moves to `resource`, because many attributes are called `type`
        $flat = ['id' => $row['id'] ?? null, 'resource' => $row['type'] ?? null] + $row['attributes'];
        if (isset($row['relationships'])) {
            $flat['relationships'] = $row['relationships'];
        }

        return $flat;
    }

    /** Drop secret preferences; redact secret-named fields everywhere (§16.2). */
    public static function scrubSecrets(mixed $value, string $template = ''): mixed
    {
        if (str_starts_with($template, 'preferences') && is_array($value)) {
            if (array_is_list($value)) {
                $value = array_values(array_filter($value, static fn ($row): bool => !is_array($row) || !self::isSecretPreference((string) ($row['name'] ?? ''))));
            } elseif (self::isSecretPreference((string) ($value['name'] ?? ''))) {
                return null;
            }
        }

        return self::redact($value);
    }

    private static function redact(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        foreach ($value as $k => $v) {
            if (is_string($k) && 1 === preg_match(self::SECRET_KEY_PATTERN, $k)) {
                $value[$k] = null === $v ? null : '[redacted]';

                continue;
            }
            $value[$k] = self::redact($v);
        }

        return $value;
    }

    public static function isSecretPreference(string $name): bool
    {
        $name = strtolower(trim($name));

        return in_array($name, self::SECRET_PREFERENCES, true) || str_contains($name, 'secret') || str_contains($name, 'token') || str_contains($name, 'password') || str_starts_with($name, 'mfa');
    }

    /**
     * Cut a list until it fits the 8 MiB response cap (§15).
     *
     * @return array{0: mixed, 1: int} the (possibly cut) value and how many rows were dropped
     */
    private static function capBytes(mixed $result): array
    {
        $max = (int) config('machine.limits.max_response_bytes', 8388608) - 65536;
        if (strlen(Envelope::encode($result)) <= $max) {
            return [$result, 0];
        }
        if (!is_array($result) || !array_is_list($result)) {
            throw MachineException::invalid('The upstream answer is larger than 8 MiB.', 'Narrow it with the route\'s own filters (start, end, limit, page)');
        }
        $lo = 0;
        $hi = count($result);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, 2);
            if (strlen(Envelope::encode(array_slice($result, 0, $mid))) <= $max) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }

        return [array_slice($result, 0, $lo), count($result) - $lo];
    }

    /** @param array<mixed> $decoded */
    private static function upstreamError(int $status, array $decoded, string $path): MachineException
    {
        $message = Envelope::scrub(is_string($decoded['message'] ?? null) ? $decoded['message'] : 'Upstream refused the request.');
        $details = ['path' => 'api/v1/'.$path, 'upstream_status' => $status];
        if (is_array($decoded['errors'] ?? null)) {
            $details['fields'] = $decoded['errors'];
        }

        return match (true) {
            404 === $status               => MachineException::notFound('Upstream found nothing there: '.$message, 'Check the id in the path — a typed route may resolve names for you', $details),
            401 === $status, 403 === $status => MachineException::forbidden('Upstream refused the operator: '.$message, 'This read needs a role the operator does not have in this administration', $details),
            422 === $status, 400 === $status, 415 === $status => MachineException::invalid('Upstream rejected the arguments: '.$message, 'Fix the query-string arguments named in details.fields', $details),
            default                       => MachineException::upstream('Upstream failed: '.$message, 'Retry, or use a typed route for the same question', $details),
        };
    }

    /** @return array{current: mixed, currentRequest: mixed} */
    private static function routerState(Router $router): array
    {
        $read = Closure::bind(static fn (Router $r): array => ['current' => $r->current, 'currentRequest' => $r->currentRequest], null, Router::class);

        try {
            return $read($router);
        } catch (Throwable) {
            return ['current' => null, 'currentRequest' => null];
        }
    }

    /** @param array{current: mixed, currentRequest: mixed} $state */
    private static function restoreRouterState(Router $router, array $state): void
    {
        $write = Closure::bind(static function (Router $r, array $s): void {
            $r->current        = $s['current'];
            $r->currentRequest = $s['currentRequest'];
        }, null, Router::class);

        try {
            $write($router, $state);
        } catch (Throwable) {
            // best effort: the caller's own request object is what the plane reads
        }
    }
}
