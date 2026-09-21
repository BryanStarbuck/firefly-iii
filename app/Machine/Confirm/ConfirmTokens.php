<?php

/*
 * ConfirmTokens.php
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

namespace FireflyIII\Machine\Confirm;

use Carbon\CarbonImmutable;
use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use Illuminate\Support\Facades\Cache;

/**
 * Gate 6 — the confirm token (apis.mdx §7.3).
 *
 * A token is `cf_` + 128 bits from random_bytes, stored in the Laravel cache (PHP workers share
 * no memory) under `machine:confirm:{keyId}:{token}` for ten minutes, holding the route it was
 * minted FOR, a hash of the arguments, and the change-set fingerprint. It is single-use,
 * bound to that route and those arguments, and namespaced by the machine key — so a key rotation
 * invalidates every outstanding token at once. A model cannot invent one: the write path
 * requires having read the preview.
 *
 * A plan route may mint a token for a DIFFERENT route (reconcile/plan → reconcile/apply,
 * GET /undo/last → POST /undo): pass the apply route's key and the args the apply will present.
 */
final class ConfirmTokens
{
    private const string PREFIX = 'cf_';

    /**
     * @param string               $routeKey    "POST /accounts/{id}/reconcile/apply" — the route that may redeem it
     * @param string               $argsHash    argsHash() of what that route will present
     * @param string               $fingerprint the change-set fingerprint the apply must reproduce
     * @param array<string, mixed> $payload     anything the redeeming route needs back (never secrets)
     *
     * @return array{confirm_token: string, expires_at: string}
     */
    public static function mint(string $routeKey, string $argsHash, string $fingerprint, array $payload = []): array
    {
        $ttl     = (int) config('machine.confirm_ttl', 600);
        $token   = self::PREFIX.bin2hex(random_bytes(16));
        $expires = CarbonImmutable::now('UTC')->addSeconds($ttl);
        Cache::put(self::cacheKey($token), [
            'route'       => $routeKey,
            'args'        => $argsHash,
            'fingerprint' => $fingerprint,
            'payload'     => $payload,
            'expires_at'  => $expires->getTimestamp(),
        ], $ttl);

        return ['confirm_token' => $token, 'expires_at' => $expires->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * Look at a token without redeeming it (a plan→apply route reading its payload).
     *
     * @return null|array{route: string, args: string, fingerprint: string, payload: array<string, mixed>, expires_at: int}
     */
    public static function peek(string $token): ?array
    {
        if (!self::wellFormed($token)) {
            return null;
        }
        $entry = Cache::get(self::cacheKey($token));

        return is_array($entry) ? $entry : null;
    }

    /**
     * Redeem (and so consume) a token for this route and these arguments.
     *
     * @return array{route: string, args: string, fingerprint: string, payload: array<string, mixed>, expires_at: int}
     *
     * @throws MachineException forbidden (malformed / for another route or other arguments), conflict (expired, used, rotated key)
     */
    public static function redeem(string $token, string $routeKey, string $argsHash): array
    {
        if (!self::wellFormed($token)) {
            throw MachineException::forbidden(
                'confirm_token is not a token this server minted.',
                'Run the call with dry_run: true (or its plan route) and echo back the confirm_token it returns',
            );
        }
        $entry = Cache::pull(self::cacheKey($token));
        if (!is_array($entry) || (int) ($entry['expires_at'] ?? 0) < time()) {
            throw MachineException::conflict(
                'The confirm token has expired, was already used, or was minted under a key that has since been rotated.',
                'Re-plan: run the call again with dry_run: true and use the new confirm_token (tokens last 10 minutes and work once)',
            );
        }
        if ($entry['route'] !== $routeKey) {
            throw MachineException::forbidden(
                sprintf('That confirm token was minted for %s, not %s.', $entry['route'], $routeKey),
                'Use the token with the route whose plan returned it',
                ['token_route' => $entry['route']],
            );
        }
        if (!hash_equals((string) $entry['args'], $argsHash)) {
            throw MachineException::forbidden(
                'That confirm token was minted for different arguments.',
                'Send exactly the arguments you planned with (apart from dry_run and confirm_token), or re-plan',
            );
        }

        return $entry;
    }

    /**
     * A stable hash of a call's arguments: keys sorted recursively, so argument order cannot
     * change it. The control fields (dry_run, confirm_token, max_changes, idempotency_key) must
     * be removed by the caller first.
     *
     * @param array<mixed> $args
     */
    public static function argsHash(array $args): string
    {
        return hash('sha256', Envelope::encode(self::canonical($args)));
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return is_int($value) ? (string) $value : $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    private static function wellFormed(string $token): bool
    {
        return 1 === preg_match('/^cf_[0-9a-f]{32}$/', $token);
    }

    private static function cacheKey(string $token): string
    {
        $key = CredentialsFile::resolve();

        return sprintf('machine:confirm:%s:%s', null === $key ? 'unarmed' : $key->id(), $token);
    }
}
