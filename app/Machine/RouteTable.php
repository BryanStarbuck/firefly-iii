<?php

/*
 * RouteTable.php
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

use FireflyIII\Machine\Routes\AccountRoutes;
use FireflyIII\Machine\Routes\AdministrationRoutes;
use FireflyIII\Machine\Routes\AdminRoutes;
use FireflyIII\Machine\Routes\AnalyticsRoutes;
use FireflyIII\Machine\Routes\BudgetRoutes;
use FireflyIII\Machine\Routes\CategoryRoutes;
use FireflyIII\Machine\Routes\IngestRoutes;
use FireflyIII\Machine\Routes\PlaneRoutes;
use FireflyIII\Machine\Routes\ReferenceRoutes;
use FireflyIII\Machine\Routes\ReportRoutes;
use FireflyIII\Machine\Routes\RouteFamily;
use FireflyIII\Machine\Routes\RuleRoutes;
use FireflyIII\Machine\Routes\SearchRoutes;
use FireflyIII\Machine\Routes\SubscriptionRoutes;
use FireflyIII\Machine\Routes\TransactionRoutes;
use FireflyIII\Machine\Routes\UndoRoutes;
use LogicException;

/**
 * THE one array (apis.mdx §6.3). routes/machine.php walks all() to mount routes;
 * GET /capabilities walks the same all() to describe them. There is no second list.
 */
final class RouteTable
{
    /** @var list<class-string<RouteFamily>> */
    public const array FAMILIES = [
        PlaneRoutes::class,
        AdministrationRoutes::class,
        AccountRoutes::class,
        TransactionRoutes::class,
        CategoryRoutes::class,
        BudgetRoutes::class,
        SubscriptionRoutes::class,
        RuleRoutes::class,
        ReferenceRoutes::class,
        ReportRoutes::class,
        AnalyticsRoutes::class,
        IngestRoutes::class,
        SearchRoutes::class,
        UndoRoutes::class,
        AdminRoutes::class,
    ];

    /**
     * Test seam: extra families mounted in addition to FAMILIES. Only PHPUnit sets this (see
     * tests/Machine), and only before the application boots, so production can never grow a
     * route that is not in FAMILIES.
     *
     * @var list<class-string<RouteFamily>>
     */
    private static array $extraFamilies = [];

    /** @var null|list<RouteDef> */
    private static ?array $memo = null;

    /** @return list<RouteDef> every route, in family order, duplicates refused. */
    public static function all(): array
    {
        if (null !== self::$memo) {
            return self::$memo;
        }
        $seen = [];
        $all  = [];
        foreach ([...self::FAMILIES, ...self::$extraFamilies] as $family) {
            foreach ($family::routes() as $def) {
                if (!$def instanceof RouteDef) {
                    throw new LogicException(sprintf('%s::routes() returned something that is not a RouteDef', $family));
                }
                $key = $def->method.' '.self::shape($def->path);
                if (isset($seen[$key])) {
                    throw new LogicException(sprintf('Machine route %s is declared twice (%s and %s)', $def->key(), $seen[$key], $family));
                }
                $seen[$key] = $family;
                $all[]      = $def;
            }
        }

        return self::$memo = $all;
    }

    /** The route whose method and path template match, or null. */
    public static function find(string $method, string $path): ?RouteDef
    {
        foreach (self::all() as $def) {
            if ($def->method === strtoupper($method) && $def->path === $path) {
                return $def;
            }
        }

        return null;
    }

    /**
     * The routes in the order the router must register them: for each method, a static path
     * segment wins over a {parameter} at the same position (so /transactions/export is matched
     * before /transactions/{group_id}); otherwise family order is kept.
     *
     * @return list<RouteDef>
     */
    public static function mountOrder(): array
    {
        // A total order: segment by segment, a static segment sorts before a {parameter}; ties
        // keep declaration order. Two paths in different static branches can never match the
        // same URL, so reordering them is harmless; a static-vs-parameter tie at the same
        // position is exactly the case that must put the static one first.
        $keyed = [];
        foreach (self::all() as $i => $def) {
            $segments = explode('/', trim($def->path, '/'));
            $key      = implode("\x00", array_map(static fn (string $s): string => str_starts_with($s, '{') ? "\xff" : "\x01".$s, $segments));
            $keyed[]  = [$key, $i, $def];
        }
        usort($keyed, static fn (array $a, array $b): int => 0 !== ($c = strcmp($a[0], $b[0])) ? $c : $a[1] <=> $b[1]);

        return array_map(static fn (array $row): RouteDef => $row[2], $keyed);
    }

    /**
     * The /capabilities table (apis.mdx §6.3): one entry per path, with per-method tier,
     * status, summary and phase.
     *
     * @return list<array<string, mixed>>
     */
    public static function capabilities(): array
    {
        $byPath = [];
        foreach (self::all() as $def) {
            $p = $def->path;
            if (!isset($byPath[$p])) {
                $byPath[$p] = ['path' => $p, 'methods' => [], 'tier' => [], 'status' => [], 'summary' => [], 'phase' => [], 'dryRun' => []];
            }
            $m                          = $def->method;
            $byPath[$p]['methods'][]    = $m;
            $byPath[$p]['tier'][$m]     = $def->tier;
            $byPath[$p]['status'][$m]   = $def->status;
            $byPath[$p]['summary'][$m]  = $def->publishedSummary();
            $byPath[$p]['phase'][$m]    = $def->phase;
            $byPath[$p]['dryRun'][$m]   = $def->dryRun;
            if ($def->composed) {
                $byPath[$p]['composed'][$m] = true;
            }
        }

        return array_values($byPath);
    }

    /**
     * The feature flags /capabilities publishes: a feature is present when the route that
     * carries it is live, so a client can say "this build has no analytics plane" instead of
     * decoding a not_ready.
     *
     * @return list<string>
     */
    public static function features(): array
    {
        $probes   = [
            'analytics'       => ['GET', '/analytics/summary'],
            'charts'          => ['GET', '/charts/series'],
            'ingest.prepared' => ['POST', '/ingest/plan'],
            'ingest.raw'      => ['POST', '/ingest/extract'],
            'provisioning'    => ['POST', '/ingest/accounts/plan'],
            'undo'            => ['POST', '/undo'],
            'batch'           => ['POST', '/batch'],
            'mirror'          => ['GET', '/mirror/{path}'],
            'search'          => ['GET', '/search'],
            'reconcile'       => ['POST', '/accounts/{id}/reconcile/plan'],
            'reports'         => ['GET', '/reports/default'],
        ];
        $features = [];
        foreach ($probes as $feature => [$method, $path]) {
            $def = self::find($method, $path);
            if (null !== $def && $def->isLive()) {
                $features[] = $feature;
            }
        }

        return $features;
    }

    /**
     * The closed set of `op` names POST /batch accepts (apis.mdx §7.4), published in
     * /capabilities. A family that supports batching declares a static batchOps(): list<string>.
     *
     * @return list<string>
     */
    public static function batchOps(): array
    {
        $ops = [];
        foreach ([...self::FAMILIES, ...self::$extraFamilies] as $family) {
            if (method_exists($family, 'batchOps')) {
                foreach ($family::batchOps() as $op) {
                    $ops[] = (string) $op;
                }
            }
        }
        sort($ops);

        return array_values(array_unique($ops));
    }

    /**
     * TEST SEAM ONLY — mount an extra family (e.g. the write-protocol probe in tests/Machine).
     * Refuses outside PHPUnit.
     *
     * @param class-string<RouteFamily> $family
     */
    public static function registerTestFamily(string $family): void
    {
        // Called before the test's application exists (routes are mounted at boot), so the
        // environment is read directly: phpunit.xml sets APP_ENV=testing.
        $testing = 'testing' === ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV'));
        if (!$testing) {
            throw new LogicException('RouteTable::registerTestFamily() is a PHPUnit seam and refuses outside tests.');
        }
        if (!in_array($family, self::$extraFamilies, true)) {
            self::$extraFamilies[] = $family;
        }
        self::$memo = null;
    }

    /** TEST SEAM ONLY — forget extra families. */
    public static function resetTestFamilies(): void
    {
        self::$extraFamilies = [];
        self::$memo          = null;
    }

    /** "/accounts/{id}" and "/accounts/{name}" are the same route shape. */
    private static function shape(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }
}
