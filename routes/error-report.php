<?php

/*
/*
 * error-report.php
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

use FireflyIII\Machine\ErrorFile\Http\ErrorReportController;
use FireflyIII\Machine\ErrorFile\Http\ErrorReportGate;
use Illuminate\Support\Facades\Route;

/*
 * The browser's error-report ingest route — pm/error_err.mdx §4.10. A fork-owned file in upstream's
 * routes/ directory, loaded by ErrorFileServiceProvider::boot() (never by bootstrap/app.php).
 *
 * It sits OUTSIDE /machine/v1 and outside the `web` and `api` groups: no session, no cookies, no
 * CSRF, no auth — the login page and the error pages have no session, and the machine key must
 * never reach a browser. Route::any lets the gate's method guard answer 204 instead of a 405.
 * Every answer is an empty 204; the guards are in ErrorReportGate (1–5) and Ingest (6–10).
 */
Route::any('error-report', ErrorReportController::class)
    ->middleware([ErrorReportGate::class])
    ->name('fork.error-report');
