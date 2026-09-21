<?php

/*
 * IngestRoutes.php
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

namespace FireflyIII\Machine\Routes;

use FireflyIII\Machine\Http\Controllers\IngestController;
use FireflyIII\Machine\RouteDef;

/**
 * pm/apis.mdx §11 and §12 — statements into the ledger (prepared mode first, raw mode later) and account provisioning from a manifest. Firefly's duplicate-hash check is the authority (§11.6). /ingest/extract and /ingest/prefer are the only read-tier routes that write, and only to the staging directory.
 *
 * Every route is live; the engine is app/Machine/Ingest/ and the handlers IngestController.
 */
final class IngestRoutes implements RouteFamily
{
    public static function routes(): array
    {
        $c = IngestController::class;

        return [
            RouteDef::live('GET', '/ingest/roots', 'read', 'The configured statement roots, and whether each is readable.', [$c, 'roots'], ['phase' => 'P4']),
            RouteDef::live('GET', '/ingest/manifest', 'read', 'The statements tree\'s manifest: accounts, files, coverage, warnings (a raw tree\'s accounts are read off its directories).', [$c, 'manifest'], ['phase' => 'P4']),
            RouteDef::live('POST', '/ingest/scan', 'read', 'Scan the statements tree: counts per entity/bank/account/year, missing months, duplicate scans, unreadable files (writes nothing).', [$c, 'scan'], ['phase' => 'P4']),
            RouteDef::live('GET', '/ingest/coverage', 'read', 'Which account-months are present and which are missing.', [$c, 'coverage'], ['phase' => 'P4']),
            RouteDef::live('GET', '/ingest/map', 'read', 'The current statements-to-Firefly-account map, with unmapped, stale and orphan entries.', [$c, 'map'], ['phase' => 'P4']),
            RouteDef::live('PUT', '/ingest/map', 'write', 'Merge entries into the map, written beside the statements in the staging directory (never in the repo); account_id null removes one.', [$c, 'mapPut'], ['phase' => 'P5']),
            RouteDef::live('POST', '/ingest/map/infer', 'read', 'Propose a mapping (IBAN/number last-4, then name); never saves it.', [$c, 'mapInfer'], ['phase' => 'P5']),
            RouteDef::live('POST', '/ingest/accounts/plan', 'read', 'Plan the accounts for a manifest: create, link, skip or ambiguous per row with its reason, and a confirm token for /ingest/accounts/apply. Creates nothing.', [$c, 'accountsPlan'], ['phase' => 'P5', 'composed' => true]),
            RouteDef::live('POST', '/ingest/accounts/apply', 'write', 'Create the planned accounts through Firefly\'s account factory and write the map (confirm_token from /ingest/accounts/plan; dry_run defaults true).', [$c, 'accountsApply'], ['phase' => 'P5', 'composed' => true]),
            RouteDef::live('POST', '/ingest/extract', 'read', 'Raw mode: statement sidecars (and PDF text via a local pdftotext) into rows in the staging directory. Writes to staging only.', [$c, 'extract'], ['phase' => 'P9', 'composed' => true]),
            RouteDef::live('GET', '/ingest/dupes', 'read', 'What both de-duplication layers collapsed, the verdict, and the rule that decided it.', [$c, 'dupes'], ['phase' => 'P9']),
            RouteDef::live('POST', '/ingest/prefer', 'read', 'Answer a statement conflict: this file wins its account-month (recorded in staging _conflicts.csv only).', [$c, 'prefer'], ['phase' => 'P9']),
            RouteDef::live('GET', '/ingest/rows', 'read', 'The canonical, de-duplicated rows (external_id, ordinal, source file and line) for an account and range.', [$c, 'rows'], ['phase' => 'P4']),
            RouteDef::live('POST', '/ingest/plan', 'read', 'Every row through Firefly\'s own store path inside the dry-run harness: new, duplicate_of, previously deleted, and what the rules did; a confirm token for /ingest/apply.', [$c, 'plan'], ['phase' => 'P4', 'composed' => true]),
            RouteDef::live('POST', '/ingest/apply', 'write', 'Store the planned rows (confirm_token from /ingest/plan): recomputed at apply time, refused with the new counts if the ledger moved; max_changes applies.', [$c, 'apply'], ['phase' => 'P4', 'composed' => true]),
            RouteDef::live('POST', '/ingest/file/plan', 'read', 'One file into one account: the plan, and a confirm token for /ingest/file/apply.', [$c, 'filePlan'], ['phase' => 'P4', 'composed' => true]),
            RouteDef::live('POST', '/ingest/file/apply', 'write', 'One file into one account: the apply (confirm_token from /ingest/file/plan).', [$c, 'fileApply'], ['phase' => 'P4', 'composed' => true]),
            RouteDef::live('GET', '/ingest/runs', 'read', 'The ingest run log (every configured root, or root=).', [$c, 'runs'], ['phase' => 'P4']),
            RouteDef::live('GET', '/ingest/runs/{id}', 'read', 'One run\'s full report.', [$c, 'run'], ['phase' => 'P4']),
        ];
    }
}
