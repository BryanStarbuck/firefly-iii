<?php

/*
 * RouteDef.php
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

use InvalidArgumentException;

/**
 * One route of the machine plane — apis.mdx §6.3.
 *
 * The SAME value feeds the router (routes/machine.php) and /capabilities, so a route that is
 * mounted but not published, or published but not mounted, cannot exist.
 *
 *   RouteDef::live('GET', '/accounts', 'read', 'Accounts, with balances.', [AccountController::class, 'index'], ['phase' => 'P2'])
 *   RouteDef::planned('POST', '/accounts/{id}/reconcile/apply', 'write', 'Write the reconciliation.', 'P8')
 *
 * Options (the `$opts` array of both constructors):
 *   phase     string  the build phase (live routes: the phase that built it)
 *   dryRun    bool    the route takes `dry_run` / `confirm_token` (default: true for a non-GET write/admin
 *                     route, false otherwise; say false for the writes that have no dry run, §7.2)
 *   composed  bool    R1's named exception — the route composes several service calls (meta.composed)
 *   operator  bool    the route needs a resolved operator (default true; only the four diagnostics say false)
 *   where     array   extra route-parameter patterns, e.g. ['path' => '.*']
 */
final readonly class RouteDef
{
    public const array METHODS  = ['GET', 'POST', 'PUT', 'DELETE'];
    public const array TIERS    = ['read', 'write', 'admin'];
    public const array STATUSES = ['live', 'planned'];

    /**
     * @param null|array{0: class-string, 1: string} $handler
     * @param array<string, string>                  $where
     */
    public function __construct(
        public string $method,
        public string $path,
        public string $tier,
        public string $status,
        public string $summary,
        public ?array $handler,
        public string $phase,
        public bool $dryRun,
        public bool $composed,
        public bool $needsOperator = true,
        public array $where = [],
    ) {
        if (!in_array($method, self::METHODS, true)) {
            throw new InvalidArgumentException(sprintf('RouteDef: method "%s" is not one of %s', $method, implode(', ', self::METHODS)));
        }
        if (!in_array($tier, self::TIERS, true)) {
            throw new InvalidArgumentException(sprintf('RouteDef: tier "%s" is not read, write or admin (%s %s)', $tier, $method, $path));
        }
        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('RouteDef: status "%s" is not live or planned', $status));
        }
        if (!str_starts_with($path, '/')) {
            throw new InvalidArgumentException(sprintf('RouteDef: path "%s" must start with "/" (it is relative to /machine/v1)', $path));
        }
        if ('live' === $status && null === $handler) {
            throw new InvalidArgumentException(sprintf('RouteDef: live route %s %s has no handler', $method, $path));
        }
    }

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param array<string, mixed>              $opts
     */
    public static function live(string $method, string $path, string $tier, string $summary, array $handler, array $opts = []): self
    {
        return self::make('live', $method, $path, $tier, $summary, $handler, (string) ($opts['phase'] ?? ''), $opts);
    }

    /**
     * @param array<string, mixed> $opts
     */
    public static function planned(string $method, string $path, string $tier, string $summary, string $phase, array $opts = []): self
    {
        return self::make('planned', $method, $path, $tier, $summary, null, $phase, $opts);
    }

    /**
     * @param null|array{0: class-string, 1: string} $handler
     * @param array<string, mixed>                   $opts
     */
    private static function make(string $status, string $method, string $path, string $tier, string $summary, ?array $handler, string $phase, array $opts): self
    {
        $method = strtoupper($method);

        return new self(
            method       : $method,
            path         : $path,
            tier         : $tier,
            status       : $status,
            summary      : $summary,
            handler      : $handler,
            phase        : $phase,
            dryRun       : (bool) ($opts['dryRun'] ?? ('read' !== $tier && 'GET' !== $method)),
            composed     : (bool) ($opts['composed'] ?? false),
            needsOperator: (bool) ($opts['operator'] ?? true),
            where        : (array) ($opts['where'] ?? []),
        );
    }

    /** "GET /accounts/{id}" — the route's identity in tokens, the audit log and the operation log. */
    public function key(): string
    {
        return $this->method.' '.$this->path;
    }

    public function isLive(): bool
    {
        return 'live' === $this->status;
    }

    /** The summary as /capabilities publishes it: planned routes say so, and name their phase. */
    public function publishedSummary(): string
    {
        if ($this->isLive()) {
            return $this->summary;
        }

        return rtrim($this->summary).sprintf(' NOT IMPLEMENTED YET (phase %s).', '' === $this->phase ? '?' : $this->phase);
    }

    /** @return list<string> the {placeholders} in the path, in order. */
    public function parameters(): array
    {
        preg_match_all('/\{([a-z_][a-z0-9_]*)\??\}/i', $this->path, $m);

        return $m[1];
    }
}
