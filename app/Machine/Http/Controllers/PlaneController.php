<?php

/*
 * PlaneController.php
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

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\Credentials\ResolvedKey;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Operator;
use FireflyIII\Machine\RouteTable;
use FireflyIII\Models\UserGroup;
use FireflyIII\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The plane itself — apis.mdx §8.0: ping, whoami, capabilities, health. These four answer
 * WITHOUT a resolved operator, by design: their job is to report what is wrong, and a diagnostic
 * that needs the thing it diagnoses is no diagnostic. Also the plane's terminal 404 (fallback).
 */
final class PlaneController extends MachineController
{
    /** GET /ping — the plane is mounted and the key is right. */
    public function ping(Request $request): JsonResponse
    {
        $this->input($request, [], true);

        return $this->ok([
            'pong'             => true,
            'plane'            => 'v1',
            'keyFingerprint'   => self::key($request)?->fingerprint(),
            'tiers'            => self::tiers(),
            'serverVersion'    => (string) config('firefly.version'),
            'operatorResolved' => null !== Operator::user($request),
        ]);
    }

    /** GET /whoami — who the plane acts as, on which books, with which tiers. */
    public function whoami(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $user    = Operator::user($request);
        $group   = Operator::administration($request);
        $key     = self::key($request);
        $data    = [
            'operator'           => $user?->email,
            'administrationId'   => null === $group ? null : (int) $group->id,
            'administrationName' => $group?->title,
            'primaryCurrency'    => null === $group ? null : Operator::primaryCurrency($group)?->code,
            'operatorResolved'   => null !== $user,
            'tiers'              => self::tiers(),
            'keyFingerprint'     => $key?->fingerprint(),
            'keySource'          => $key?->source,
            'statementsRoot'     => CredentialsFile::fromConfig()->statementsRoot(),
        ];
        if (null === $user) {
            $data['problem'] = self::problem($request);
            $data['users']   = self::safeCount(static fn (): int => User::query()->count());
        }

        return $this->ok($data);
    }

    /** GET /capabilities — this build's route table, generated from RouteTable::all() (§6.3). */
    public function capabilities(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $routes = RouteTable::capabilities();
        $live   = 0;
        $total  = 0;
        foreach (RouteTable::all() as $def) {
            ++$total;
            $live += $def->isLive() ? 1 : 0;
        }

        return $this->ok([
            'apiVersion'       => 'v1',
            'planeVersion'     => 'v1',
            'serverVersion'    => (string) config('firefly.version'),
            'tiers'            => self::tiers(),
            'operatorResolved' => null !== Operator::user($request),
            'routes'           => $routes,
            'counts'           => ['routes' => $total, 'live' => $live, 'planned' => $total - $live],
            'limits'           => [
                'maxLimit'          => (int) config('machine.limits.max_limit', 5000),
                'defaultLimit'      => (int) config('machine.limits.default_limit', 200),
                'maxChangesDefault' => (int) config('machine.limits.max_changes_default', 200),
                'maxBodyBytes'      => (int) config('machine.limits.max_body_bytes', 8388608),
                'confirmTtlSeconds' => (int) config('machine.confirm_ttl', 600),
            ],
            'features'         => RouteTable::features(),
            'batchOps'         => RouteTable::batchOps(),
            'errorCodes'       => array_keys(MachineException::CODES),
        ]);
    }

    /**
     * GET /health — can the app answer a question about a ledger? `healthy` is deliberately
     * narrow: a reachable database with no operator resolved is NOT healthy (§8.0). A failed
     * probe is a 200 carrying the reason and the remediation, never a thrown error.
     */
    public function health(Request $request): JsonResponse
    {
        $args     = $this->input($request, ['probe' => ['sometimes', 'boolean']], true);
        $probe    = (bool) ($args['probe'] ?? false);
        $database = self::database($probe);
        $user     = Operator::user($request);
        $group    = Operator::administration($request);
        $operator = null === $user
            ? ['resolved' => false, 'problem' => self::problem($request)]
            : ['resolved' => true, 'email' => (string) $user->email, 'administrationId' => (int) $group?->id, 'administrationName' => (string) $group?->title];
        $healthy  = true === $database['reachable'] && true === $database['migrated'] && null !== $user;

        return $this->ok([
            'healthy'         => $healthy,
            'plane'           => 'armed',
            'database'        => $database,
            'operator'        => $operator,
            'users'           => $database['reachable'] ? self::safeCount(static fn (): int => User::query()->count()) : null,
            'administrations' => $database['reachable'] ? self::safeCount(static fn (): int => UserGroup::query()->count()) : null,
            'tiers'           => self::tiers(),
            'next'            => self::next($database, $operator),
        ]);
    }

    /** Every /machine/v1 URL no route matched: the envelope's not_found, naming what exists. */
    public function fallback(Request $request): never
    {
        $path    = '/'.ltrim(substr('/'.ltrim($request->path(), '/'), strlen('/machine/v1')), '/');
        $methods = [];
        foreach (RouteTable::all() as $def) {
            $regex = '#^'.preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($def->path, '#')).'$#';
            if ('/mirror/{path}' === $def->path) {
                $regex = '#^/mirror/.+$#';
            }
            if (1 === preg_match($regex, $path)) {
                $methods[] = $def->method;
            }
        }
        if (str_starts_with($path, '/mirror/') && 'GET' !== strtoupper($request->method())) {
            // §8.12: the mirror is reads only, forever — a write through it would bypass §7
            throw MachineException::forbidden(
                'The mirror is read-only: it answers GET and nothing else.',
                'Writes go through the typed routes, which dry-run first — GET /machine/v1/capabilities lists them',
                ['path' => $path, 'methods' => ['GET']],
            );
        }
        if ([] !== $methods) {
            $methods = array_values(array_unique($methods));

            throw MachineException::notFound(
                sprintf('%s is not a method of %s.', strtoupper($request->method()), $path),
                sprintf('%s accepts: %s', $path, implode(', ', $methods)),
                ['path' => $path, 'methods' => $methods],
            );
        }

        throw MachineException::notFound(
            sprintf('No route %s %s on the machine plane.', strtoupper($request->method()), $path),
            'GET /machine/v1/capabilities lists every route',
            ['path' => $path],
        );
    }

    // ------------------------------------------------------------------------

    /** @return array{read: bool, write: bool, admin: bool} */
    public static function tiers(): array
    {
        return ['read' => true, 'write' => (bool) config('machine.allow_write'), 'admin' => (bool) config('machine.allow_admin')];
    }

    private static function key(Request $request): ?ResolvedKey
    {
        $key = $request->attributes->get('machine.key');

        return $key instanceof ResolvedKey ? $key : CredentialsFile::resolve();
    }

    /** @return null|array<string, mixed> */
    private static function problem(Request $request): ?array
    {
        $refusal = $request->attributes->get(Operator::ATTR_REFUSE);
        if (!$refusal instanceof MachineException) {
            return null;
        }
        $out = ['code' => $refusal->errorCode, 'message' => $refusal->getMessage(), 'hint' => $refusal->hint];
        if ([] !== $refusal->details) {
            $out['details'] = $refusal->details;
        }

        return $out;
    }

    /**
     * @return array{reachable: bool, migrated: null|bool, driver: string, probed: bool, pending_migrations?: int, error?: string}
     */
    private static function database(bool $probe): array
    {
        $out = ['reachable' => false, 'migrated' => null, 'driver' => (string) config(sprintf('database.connections.%s.driver', config('database.default'))), 'probed' => $probe];

        try {
            DB::connection()->getPdo();
            DB::select('select 1');
            $out['reachable'] = true;
        } catch (Throwable $e) {
            $out['error'] = Envelope::scrub($e->getMessage());

            return $out;
        }

        try {
            $out['migrated'] = Schema::hasTable('migrations') && Schema::hasTable('users') && Schema::hasTable('transaction_journals') && Schema::hasTable('machine_operations');
            if ($probe) {
                $migrator = app('migrator');
                $ran      = $migrator->getRepository()->repositoryExists() ? $migrator->getRepository()->getRan() : [];
                $files    = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
                $pending  = count(array_diff($files, $ran));
                $out['pending_migrations'] = $pending;
                $out['migrated']           = $out['migrated'] && 0 === $pending;
            }
        } catch (Throwable $e) {
            $out['migrated'] = false;
            $out['error']    = Envelope::scrub($e->getMessage());
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $database
     * @param array<string, mixed> $operator
     */
    private static function next(array $database, array $operator): string
    {
        if (true !== $database['reachable']) {
            return 'The database is not reachable — check DB_CONNECTION / DB_DATABASE in the app\'s .env (ffx doctor)';
        }
        if (true !== $database['migrated']) {
            return 'The database is not migrated — php artisan migrate --seed --force && php artisan firefly-iii:upgrade-database';
        }
        if (true !== $operator['resolved']) {
            $hint = $operator['problem']['hint'] ?? null;

            return is_string($hint) && '' !== $hint ? $hint : 'Set FIREFLY_MACHINE_OPERATOR in the app\'s .env';
        }

        return 'Nothing — the plane can answer questions about this ledger.';
    }

    /** @param \Closure(): int $count */
    private static function safeCount(\Closure $count): ?int
    {
        try {
            return $count();
        } catch (Throwable) {
            return null;
        }
    }
}
