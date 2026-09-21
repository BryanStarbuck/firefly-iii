<?php

/*
 * Clock.php
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

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The plane's clock: `meta.asOf` (the server's clock, §5.1) and `meta.tookMs`. Kept apart from
 * Money so the float arithmetic of timing never sits next to an amount.
 */
final class Clock
{
    /** "2026-09-21T18:41:02.118Z" */
    public static function nowIso(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Milliseconds since the request started. */
    public static function elapsedMs(?Request $request = null): int
    {
        $start = $request?->server('REQUEST_TIME_FLOAT');
        if (!is_float($start) && !is_int($start)) {
            $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        }
        $ms = (microtime(true) - $start) * 1000;

        return max(0, intval(floor($ms)));
    }
}
