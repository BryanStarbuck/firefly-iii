<?php

/*
/*
 * ErrorReportController.php
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

namespace FireflyIII\Machine\ErrorFile\Http;

use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\ErrorFile\Ingest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `POST /error-report` — pm/error_err.mdx §4.10. The gate ran guards 1–5; Ingest runs 6–10. The
 * answer is ALWAYS an empty 204 with `Cache-Control: no-store`: the route produces no output, so
 * nothing about it is probe-able. A fault in here is written as `[php-web]` and still answers 204;
 * it is never re-reported through the browser.
 */
final class ErrorReportController
{
    public function __invoke(): Response
    {
        try {
            Ingest::accept();
        } catch (Throwable $e) {
            ErrorFile::for('app/Machine/ErrorFile/Http/ErrorReportController.php')->caught('receiving browser error reports', $e);
        }

        return ErrorReportGate::nothing();
    }
}
