<?php

/*
 * Envelope.php
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

namespace FireflyIII\Machine;

use FireflyIII\Exceptions\DuplicateTransactionException;
use FireflyIII\Exceptions\FireflyException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The one response shape — apis.mdx §5.1.
 *
 *   { "ok": true,  "data": {...}, "meta": {...} }
 *   { "ok": false, "error": { "code", "message", "hint", "details" } }
 *
 * Never HTML, never a stack, never a Laravel exception class name (§5.1, §16.2).
 */
final class Envelope
{
    public const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE;

    /** §4.6 — byte for byte the same for a missing key and a wrong one. */
    public const string UNAUTHORIZED_BODY = '{"ok":false,"error":{"code":"unauthorized"}}';

    /** Gates 1–2 and an unarmed plane: the same answer a stranger gets, so probing teaches nothing. */
    public const string STEALTH_404_BODY = '{"ok":false,"error":{"code":"not_found"}}';

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function ok(array $data, array $meta = [], int $status = 200): JsonResponse
    {
        $body = ['ok' => true, 'data' => self::object($data), 'meta' => self::object($meta)];

        return self::headers(new JsonResponse($body, $status, [], self::JSON_FLAGS));
    }

    public static function error(MachineException $e): JsonResponse
    {
        $error = ['code' => $e->errorCode, 'message' => self::scrub($e->getMessage())];
        if (null !== $e->hint && '' !== $e->hint) {
            $error['hint'] = self::scrub($e->hint);
        }
        if ([] !== $e->details) {
            $error['details'] = $e->details;
        }

        return self::headers(new JsonResponse(['ok' => false, 'error' => $error], $e->status(), [], self::JSON_FLAGS));
    }

    public static function unauthorized(): Response
    {
        return self::headers(new Response(self::UNAUTHORIZED_BODY, 401, ['Content-Type' => 'application/json']));
    }

    public static function stealth404(): Response
    {
        return self::headers(new Response(self::STEALTH_404_BODY, 404, ['Content-Type' => 'application/json']));
    }

    /**
     * Every plane response: no caching, varies on Origin, and never a CORS grant (§4.7).
     *
     * @template T of Response
     *
     * @param T $response
     *
     * @return T
     */
    public static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Vary', 'Origin');
        foreach (['Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods', 'Access-Control-Allow-Headers', 'Access-Control-Expose-Headers', 'Access-Control-Max-Age'] as $h) {
            $response->headers->remove($h);
        }

        return $response;
    }

    /**
     * Map anything thrown inside /machine/v1 onto one of the nine codes (§5.1–5.2).
     */
    public static function fromThrowable(Throwable $e): MachineException
    {
        if ($e instanceof MachineException) {
            return $e;
        }
        if ($e instanceof ValidationException) {
            $fields = $e->errors();
            $first  = self::firstMessage($fields);

            return MachineException::invalid(
                'Invalid input.',
                null === $first ? 'Check the arguments against GET /machine/v1/capabilities' : $first,
                ['fields' => $fields],
            );
        }
        if ($e instanceof ModelNotFoundException) {
            $model = class_basename((string) $e->getModel());

            return MachineException::notFound(
                sprintf('No %s with that id.', '' === $model ? 'record' : strtolower($model)),
                'List them with the matching GET route, or pass a name instead of an id',
                ['ids' => array_values(array_map('strval', $e->getIds()))],
            );
        }
        if ($e instanceof DuplicateTransactionException) {
            $details = [];
            if (1 === preg_match('/#(\d+)/', $e->getMessage(), $m)) {
                $details['duplicate_of'] = (int) $m[1];
            }

            return MachineException::conflict(
                isset($details['duplicate_of']) ? sprintf('This is a duplicate of transaction group #%d.', $details['duplicate_of']) : 'Firefly III refused this as a duplicate.',
                isset($details['duplicate_of']) ? sprintf('GET /machine/v1/transactions/%d', $details['duplicate_of']) : 'Search for the existing transaction: GET /machine/v1/transactions',
                $details,
            );
        }
        if ($e instanceof AuthorizationException) {
            return MachineException::forbidden('Firefly III refused this for the operator.', 'Check that the operator may act in this administration (GET /machine/v1/whoami)');
        }
        if ($e instanceof AuthenticationException) {
            return MachineException::forbidden('The operator is not allowed to use the API (blocked).', 'Unblock the user in Firefly III, or set FIREFLY_MACHINE_OPERATOR to another user');
        }
        if ($e instanceof LockTimeoutException) {
            return MachineException::conflict('Another plane write is in progress.', 'Retry in a few seconds — writes are serialised (one at a time)');
        }
        if ($e instanceof PostTooLargeException) {
            return MachineException::invalid('The request body is too large.', 'Bodies are capped at 8 MiB — split the request');
        }
        if ($e instanceof JsonException) {
            return MachineException::invalid('The request body is not valid JSON.', 'Send Content-Type: application/json with a JSON object body');
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            return MachineException::notFound('No such route for this method.', 'GET /machine/v1/capabilities lists every route and its methods');
        }
        if ($e instanceof NotFoundHttpException) {
            return MachineException::notFound('Not found.', 'GET /machine/v1/capabilities lists every route');
        }
        if ($e instanceof FireflyException) {
            return MachineException::upstream(self::scrub($e->getMessage()), 'Firefly III refused the operation — the detail is in storage/logs/laravel.log (ffx logs)');
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return match (true) {
                404 === $status              => MachineException::notFound('Not found.', 'GET /machine/v1/capabilities lists every route'),
                403 === $status              => MachineException::forbidden('Forbidden.', 'GET /machine/v1/whoami shows the tiers this server allows'),
                409 === $status              => MachineException::conflict('Conflict.', 'Re-read, then retry'),
                in_array($status, [400, 413, 415, 422], true) => MachineException::invalid('Invalid request.', 'Send a JSON object body with Content-Type: application/json'),
                503 === $status              => MachineException::notReady('The app is not ready (maintenance mode?).', 'php artisan up'),
                default                      => MachineException::internal(),
            };
        }

        return MachineException::internal();
    }

    /**
     * Remove what must never reach a caller from a message: absolute paths outside the
     * statements root, PHP file names, framework class names (§5.1, §16.2).
     */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace('~(?:/Users|/home|/private|/var|/tmp|/opt|/usr|/srv|[A-Z]:\\\\)[^\s"\'),;]*~', '[path]', $text);
        $text = (string) preg_replace('~\b[\w/\\\\.-]+\.php\b(?::\d+)?~', '[file]', $text);
        $text = (string) preg_replace('~\b(?:Illuminate|Symfony|Doctrine|PDO|FireflyIII|Laravel)\\\\[\w\\\\]+~', '[class]', $text);
        $text = (string) preg_replace('~\bSQLSTATE\[[^\]]*\].*~s', '[database error]', $text);

        return $text;
    }

    /** Encode a value exactly as the plane does. */
    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, self::JSON_FLAGS);
    }

    /**
     * `data` and `meta` are always JSON objects, never bare arrays (§5.1).
     *
     * @param array<string, mixed> $value
     */
    private static function object(array $value): array|stdClass
    {
        return [] === $value ? new stdClass() : $value;
    }

    /** @param array<string, array<int, string>> $fields */
    private static function firstMessage(array $fields): ?string
    {
        foreach ($fields as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return null;
    }
}
