<?php

/*
 * Progress.php
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

namespace FireflyIII\Machine\Ingest;

use Closure;

/**
 * Progress for the long ingest routes — apis.mdx §15 ("long routes stream progress"), cli.mdx
 * §15.1 (the CLI must never look hung).
 *
 * When a caller asked for `Accept: application/x-ndjson`, the controller installs a sink and the
 * engine reports phases as it goes; each report becomes one NDJSON line
 * `{"progress": {"phase": "storing", "done": 148, "total": 412}}` ahead of the final envelope.
 * Without a sink every call here is a no-op, so the engine never knows whether it is streaming.
 *
 * Reports are throttled (at most one line per ~200 ms per phase, plus the first and the last of a
 * phase) so a 12,000-row apply does not write 12,000 lines. Counts only, never a payee or an
 * amount (§16.2).
 */
final class Progress
{
    /** @var null|Closure(string, null|int, null|int): void */
    private static ?Closure $sink = null;

    /** @var array<string, float> phase => last emit time */
    private static array $lastAt = [];

    /** Install (or, with null, remove) the sink. Returns the previous one so a caller can restore it. */
    public static function sink(?Closure $sink): ?Closure
    {
        $previous     = self::$sink;
        self::$sink   = $sink;
        self::$lastAt = [];

        return $previous;
    }

    public static function active(): bool
    {
        return null !== self::$sink;
    }

    /**
     * Report a phase: "reading", "planning", "storing", "finishing", "extracting"… with an optional
     * done/total. The first report of a phase, a report where done == total, and a report with no
     * count always go out; the rest are throttled.
     */
    public static function tick(string $phase, ?int $done = null, ?int $total = null): void
    {
        if (null === self::$sink) {
            return;
        }
        $now   = microtime(true);
        $first = !isset(self::$lastAt[$phase]) || 0 === $done; // a phase's first report, or a new pass of it
        $last  = null !== $done && null !== $total && $done >= $total;
        if (!$first && !$last && null !== $done && ($now - self::$lastAt[$phase]) < 0.2) {
            return;
        }
        self::$lastAt[$phase] = $now;
        (self::$sink)($phase, $done, $total);
    }
}
