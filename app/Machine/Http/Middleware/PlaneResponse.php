<?php

/*
 * PlaneResponse.php
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

namespace FireflyIII\Machine\Http\Middleware;

use Closure;
use FireflyIII\Machine\Envelope;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The outermost plane middleware: whatever the gates or the handler answered, it leaves with
 * Cache-Control: no-store, Vary: Origin and no CORS grant (apis.mdx §4.7), and it is JSON — a
 * plane response is never HTML (§5.1).
 */
final class PlaneResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        // The plane never content-negotiates into HTML (§5.7): treat every caller as a JSON client,
        // so any framework code that asks "expectsJson?" answers yes.
        $request->headers->set('Accept', 'application/json');

        return Envelope::headers($next($request));
    }
}
