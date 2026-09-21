<?php

/*
 * errorfile.php
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

/*
 * The error file — pm/error_err.mdx §4.11. A fork-owned file in upstream's config/ directory;
 * Laravel loads it with no code. Nothing here goes into config/machine.php, which stays the plane's.
 *
 * One variable moves the file for all three runtimes (PHP, ffx, mcp): FIREFLY_ERROR_FILE. Set it in
 * the shell, not only in .env, or ffx and PHP write to different files (§3.1).
 *
 * Under PHPUnit the env-derived `path` is IGNORED (R13): only ErrorFile::usePath() in process, or
 * `test_path` (FIREFLY_ERROR_FILE_TEST_PATH, which only a spawned child's explicit env sets), is
 * honoured. With neither, nothing is written.
 */
return [
    'path'                   => env('FIREFLY_ERROR_FILE'),                    // '' or null = default (§3.1)
    'test_path'              => env('FIREFLY_ERROR_FILE_TEST_PATH'),          // honoured ONLY under runningUnitTests() (R13)
    'verbose'                => (bool) env('FIREFLY_ERROR_FILE_VERBOSE', false),
    'echo'                   => (bool) env('FIREFLY_ERROR_FILE_ECHO', false),  // php://stderr only
    'canary'                 => (bool) env('FIREFLY_ERROR_FILE_CANARY', false),
    'max_bytes'              => 5_242_880,
    'backups'                => 5,
    'fold_window_s'          => 60,
    'fold_max_keys'          => 1000,
    'fold_file_max_bytes'    => 1_048_576,                                     // enforced by the writer, LRU (§3.5)
    'fold_field_max_chars'   => 200,                                           // h, W, d in each sidecar entry
    'l1_max_keys'            => 200,
    'file_budget_per_minute' => 600,
    'lock_wait_ms'           => 200,
    'ingest'                 => [
        'body_cap'         => 65_536,
        'events'           => 50,
        'rate_client'      => 240,
        'rate_global'      => 1200,
        'rate_max_clients' => 200,
    ],
];
