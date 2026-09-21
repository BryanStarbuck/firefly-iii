<?php

/*
 * RequestGate.php
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
use FireflyIII\Machine\MachineException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request shape (apis.mdx §5.7), in front of each route's own validation (gate 5):
 * bodies are capped at 8 MiB, a body must be JSON (Content-Type: application/json), and it must
 * parse — Laravel otherwise treats malformed JSON as an empty body, which would silently turn a
 * write's arguments into "none".
 */
final class RequestGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $max    = (int) config('machine.limits.max_body_bytes', 8388608);
        $length = $request->headers->get('Content-Length');
        if (is_string($length) && ctype_digit($length) && strlen($length) < 19 && intval($length) > $max) {
            return Envelope::error(MachineException::invalid('The request body is larger than 8 MiB.', 'Split the request into smaller ones', ['max_body_bytes' => $max]));
        }
        $content = (string) $request->getContent();
        if (strlen($content) > $max) {
            return Envelope::error(MachineException::invalid('The request body is larger than 8 MiB.', 'Split the request into smaller ones', ['max_body_bytes' => $max]));
        }
        if ('' !== trim($content)) {
            if (!$request->isJson()) {
                return Envelope::error(MachineException::invalid('A request body must be JSON.', 'Send Content-Type: application/json with a JSON object body'));
            }
            json_decode($content);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return Envelope::error(MachineException::invalid('The request body is not valid JSON.', 'Send a JSON object body — the parser said: '.json_last_error_msg()));
            }
        }

        return $next($request);
    }
}
