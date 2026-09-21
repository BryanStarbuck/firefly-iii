<?php

/*
 * machine.php
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
 * The machine plane (/machine/v1) — pm/apis.mdx §3–§7.
 *
 * NOTHING in this file can hold the machine key (apis.mdx §4.3, §17). The key lives in
 * ~/.credentials/firefly_iii.json (or FIREFLY_MACHINE_KEY / FIREFLY_MACHINE_KEY_FILE, read
 * straight from the environment by CredentialsFile — never through config).
 */

return [
    // §4.9 — which user and which administration (set of books) the plane acts as.
    'operator'         => env('FIREFLY_MACHINE_OPERATOR'),
    'administration'   => env('FIREFLY_MACHINE_ADMINISTRATION'),

    // §6.1 — the server's two tier switches. Read is always on.
    'allow_write'      => (bool) env('FIREFLY_MACHINE_ALLOW_WRITE', false),
    'allow_admin'      => (bool) env('FIREFLY_MACHINE_ALLOW_ADMIN', false),

    // §4.5 — relocates ~/.credentials/firefly_iii.json. Null means the default in the home
    // directory — EXCEPT under PHPUnit, where null means "no credentials file at all", so a
    // test can never read or write the operator's real secret.
    'credentials_file' => env('FIREFLY_MACHINE_CREDENTIALS_FILE'),

    // §16.4 — where machine.audit (and the armed-line marker) live. Null means ~/T/_firefly_iii,
    // except under PHPUnit, where null means "write no audit file".
    'state_dir'        => env('FIREFLY_MACHINE_STATE_DIR'),

    // §4.7 gate 2 — the Host header's hostname must be one of these. Any port is accepted, so
    // an ephemeral test port works; a rebinding hostname does not.
    'allowed_hosts'    => ['127.0.0.1', 'localhost', '[::1]', '::1'],

    // §5.5, §7.1, §15 — the published limits.
    'limits'           => [
        'default_limit'       => 200,
        'max_limit'           => 5000,
        'max_changes_default' => 200,
        'max_body_bytes'      => 8388608,
    ],

    'confirm_ttl'      => 600,    // §7.3 — ten minutes.
    'idempotency_ttl'  => 86400,  // §5.6 — 24 hours.
    'undo_retention'   => 30,     // §7.5 — days a machine_operations row is kept.
    'write_lock_ttl'   => 120,    // §15 — Cache::lock('machine:write', 120).
    'write_lock_wait'  => 30,     // seconds a second writer waits before `conflict`.
];
