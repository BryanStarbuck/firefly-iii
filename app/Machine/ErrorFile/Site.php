<?php

/*
 * Site.php
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

use Throwable;

/**
 * The per-`where` handle of the call-site API — pm/error_err.mdx §7.1. `$where` is a repo-relative
 * literal with no line number (R14); `$doing` is a literal gerund phrase with no trailing period
 * (R2). The call site never formats, redacts or touches the request: dynamic parts go in `$data`.
 *
 *   caught()   ERROR, and the caller carries on
 *   warn()     WARN — something odd, not broken; the throwable is optional
 *   expected() nothing, unless FIREFLY_ERROR_FILE_VERBOSE=1 (R6); the decision is still visible
 *   rethrow()  ERROR, then throws THE SAME object (R5)
 *   fatal()    FATAL, bypassing every fold (R9)
 *   tryOr()    ERROR on a throw, and returns $fallback
 *
 * Every method except rethrow() is total; rethrow() throws only the object it was given.
 */
final readonly class Site
{
    public function __construct(public string $where) {}

    /** @param array<string, null|bool|float|int|string> $data */
    public function caught(string $doing, Throwable $e, array $data = []): void
    {
        Reporter::throwable(Level::Error, $this->where, $doing, $e, $data, null);
    }

    /** @param array<string, null|bool|float|int|string> $data */
    public function warn(string $doing, ?Throwable $e = null, array $data = []): void
    {
        if (null === $e) {
            Reporter::message(Level::Warn, $this->where, $doing, '', $data, null);

            return;
        }
        Reporter::throwable(Level::Warn, $this->where, $doing, $e, $data, null);
    }

    public function expected(string $doing, Throwable $e): void
    {
        Reporter::throwable(Level::Expected, $this->where, $doing, $e, [], null);
    }

    /** @param array<string, null|bool|float|int|string> $data */
    public function rethrow(string $doing, Throwable $e, array $data = []): never
    {
        Reporter::throwable(Level::Error, $this->where, $doing, $e, $data, null);

        throw $e;
    }

    /** @param array<string, null|bool|float|int|string> $data */
    public function fatal(string $doing, Throwable $e, array $data = []): void
    {
        Reporter::throwable(Level::Fatal, $this->where, $doing, $e, $data, null);
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     * @param T             $fallback
     *
     * @return T
     */
    public function tryOr(string $doing, callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            Reporter::throwable(Level::Error, $this->where, $doing, $e, [], null);

            return $fallback;
        }
    }
}
