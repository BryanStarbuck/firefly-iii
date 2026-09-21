<?php

/*
 * Audit.php
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

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The audit trail — apis.mdx §16.4. Every write, and every refused write, appends ONE line to
 * {state_dir}/machine.audit (default ~/T/_firefly_iii/machine.audit):
 *
 *   2026-09-21T18:41:02.118Z  route=POST /transactions  tier=write  caller=mcp  op=417  admin=1  token=cf_01J8…  changed=117  ok=true  took_ms=84
 *
 * What happened, never what it was about: no amounts, no descriptions, no account names, never
 * the key. `caller` is the advisory X-Firefly-Client header. A failure to write the audit line is
 * logged and never fails the request.
 */
final class Audit
{
    /** The state directory, or null when none is configured (PHPUnit without an override). */
    public static function stateDir(): ?string
    {
        $configured = config('machine.state_dir');
        if (is_string($configured) && '' !== trim($configured)) {
            $dir = trim($configured);

            return str_starts_with($dir, '~/') ? self::home().substr($dir, 1) : rtrim($dir, '/');
        }
        if (app()->runningUnitTests()) {
            return null;
        }

        return self::home().'/T/_firefly_iii';
    }

    public static function path(): ?string
    {
        $dir = self::stateDir();

        return null === $dir ? null : $dir.'/machine.audit';
    }

    /**
     * @param array<string, null|bool|int|string> $fields route, tier, op, changed, ok, code, token…
     */
    public static function line(Request $request, array $fields): void
    {
        $path = self::path();
        if (null === $path) {
            return;
        }
        $group   = Operator::administration($request);
        $row     = [
            'route'   => $fields['route'] ?? null,
            'tier'    => $fields['tier'] ?? null,
            'caller'  => self::caller($request),
            'op'      => $fields['op'] ?? null,
            'admin'   => null === $group ? null : (int) $group->id,
            'token'   => self::shortToken($fields['token'] ?? null),
            'dry_run' => $fields['dry_run'] ?? null,
            'changed' => $fields['changed'] ?? null,
            'ok'      => $fields['ok'] ?? null,
            'code'    => $fields['code'] ?? null,
            'took_ms' => Clock::elapsedMs($request),
        ];
        $parts   = [Clock::nowIso()];
        foreach ($row as $k => $v) {
            if (null === $v) {
                continue;
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $parts[] = sprintf('%s=%s', $k, str_replace(["\n", "\r", '  '], ' ', (string) $v));
        }

        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                $old = umask(0o077);
                @mkdir($dir, 0o700, true);
                umask($old);
            }
            $old = umask(0o077);
            file_put_contents($path, implode('  ', $parts)."\n", FILE_APPEND | LOCK_EX);
            umask($old);
        } catch (Throwable $e) {
            Log::warning(sprintf('Machine plane: could not append to the audit log: %s', $e->getMessage()));
        }
    }

    /** The advisory client name: [a-z0-9_.-], at most 32 characters, or null. */
    public static function caller(Request $request): ?string
    {
        $raw = strtolower(trim((string) $request->headers->get('X-Firefly-Client', '')));
        if ('' === $raw) {
            return null;
        }
        $clean = (string) preg_replace('/[^a-z0-9_.-]/', '', $raw);

        return '' === $clean ? null : substr($clean, 0, 32);
    }

    private static function shortToken(mixed $token): ?string
    {
        if (!is_string($token) || '' === $token) {
            return null;
        }

        return substr($token, 0, 7).'…';
    }

    private static function home(): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return rtrim(is_string($home) && '' !== $home ? $home : sys_get_temp_dir(), '/');
    }
}
