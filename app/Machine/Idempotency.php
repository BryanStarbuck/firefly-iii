<?php

/*
 * Idempotency.php
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

use FireflyIII\Machine\Credentials\CredentialsFile;
use Illuminate\Support\Facades\Cache;

/**
 * Idempotency keys — apis.mdx §5.6. A create that carries `idempotency_key` (≤ 128 chars) and
 * is repeated within 24 hours returns the ORIGINAL response with meta.replayed: true instead of
 * creating a second thing. Keys live in the Laravel cache, namespaced by the machine key and the
 * route; the same key with DIFFERENT arguments is a conflict, never a silent replay.
 */
final class Idempotency
{
    /**
     * @return null|array{data: array<string, mixed>, meta: array<string, mixed>}
     *
     * @throws MachineException conflict when the key was used with other arguments
     */
    public static function recall(string $routeKey, string $idempotencyKey, string $argsHash): ?array
    {
        $entry = Cache::get(self::cacheKey($routeKey, $idempotencyKey));
        if (!is_array($entry)) {
            return null;
        }
        if (!hash_equals((string) ($entry['args'] ?? ''), $argsHash)) {
            throw MachineException::conflict(
                'That idempotency_key was already used for a different request.',
                'Use a fresh idempotency_key for a different request (keys are remembered for 24 hours)',
            );
        }

        return ['data' => (array) ($entry['data'] ?? []), 'meta' => (array) ($entry['meta'] ?? [])];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public static function remember(string $routeKey, string $idempotencyKey, string $argsHash, array $data, array $meta = []): void
    {
        Cache::put(self::cacheKey($routeKey, $idempotencyKey), ['args' => $argsHash, 'data' => $data, 'meta' => $meta], (int) config('machine.idempotency_ttl', 86400));
    }

    private static function cacheKey(string $routeKey, string $idempotencyKey): string
    {
        $key = CredentialsFile::resolve();

        return sprintf('machine:idem:%s:%s', null === $key ? 'unarmed' : $key->id(), hash('sha256', $routeKey."\0".$idempotencyKey));
    }
}
