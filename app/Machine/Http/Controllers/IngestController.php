<?php

/*
 * IngestController.php
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

namespace FireflyIII\Machine\Http\Controllers;

use Carbon\CarbonImmutable;
use Closure;
use FireflyIII\Machine\Confirm\ConfirmTokens;
use FireflyIII\Machine\DryRun;
use FireflyIII\Machine\Ingest\AccountProvisioner;
use FireflyIII\Machine\Ingest\Collector;
use FireflyIII\Machine\Ingest\Csv;
use FireflyIII\Machine\Ingest\Importer;
use FireflyIII\Machine\Ingest\LedgerAccounts;
use FireflyIII\Machine\Ingest\Manifest;
use FireflyIII\Machine\Ingest\MapFile;
use FireflyIII\Machine\Ingest\ParsedStatement;
use FireflyIII\Machine\Ingest\Preferences;
use FireflyIII\Machine\Ingest\RowBuilder;
use FireflyIII\Machine\Ingest\RunLog;
use FireflyIII\Machine\Ingest\StatementDedupe;
use FireflyIII\Machine\Ingest\StatementReader;
use FireflyIII\Machine\Ingest\StatementsRoot;
use FireflyIII\Machine\Ingest\Staging;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Machine\WriteResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The ingest plane — apis.mdx §11 (statements into the ledger) and §12 (account provisioning),
 * cli.mdx §10 (the pipeline the `ffx statements` verbs drive).
 *
 * Plan → apply pairs: /ingest/plan → /ingest/apply, /ingest/file/plan → /ingest/file/apply,
 * /ingest/accounts/plan → /ingest/accounts/apply. The plan route runs the SAME closure the apply
 * runs, inside the dry-run harness, and mints a confirm token for the apply route bound to the
 * plan's (normalised) arguments; the apply route takes only the token (+ dry_run, max_changes),
 * recovers those arguments, and hands them to the core write protocol — which re-runs the closure,
 * refuses `conflict` with the new counts if the fingerprint moved, enforces max_changes, and only
 * then applies for real. So plan == apply, by construction.
 *
 * The only read-tier routes that write are /ingest/extract and /ingest/prefer, and only into the
 * staging directory. Nothing is ever written under {ROOT} outside {ROOT}/.firefly-staging/.
 */
final class IngestController extends MachineController
{
    private const string ARGS_CACHE = 'machine:ingest:args:';
    private const array KINDS_RULE  = ['checking', 'savings', 'card', 'brokerage', 'loan', 'mortgage'];

    // ------------------------------------------------------------------ discover ---

    /** GET /ingest/roots */
    public function roots(Request $request): JsonResponse
    {
        $this->input($request, [], true);
        $out = [];
        foreach (StatementsRoot::configured() as $c) {
            $real     = realpath($c['root']);
            $readable = false !== $real && is_dir($real) && is_readable($real);
            $manifest = null;
            if ($readable) {
                foreach (Manifest::CANDIDATES as $candidate) {
                    if (is_file($real.'/'.$candidate)) {
                        $manifest = $candidate;

                        break;
                    }
                }
            }
            $staging  = $readable ? new Staging((string) $real) : null;
            $out[]    = [
                'root'         => $readable ? $real : $c['root'],
                'source'       => $c['source'],
                'readable'     => $readable,
                'mode'         => $readable ? (null === $manifest ? 'raw' : 'prepared') : null,
                'manifest'     => $manifest,
                'staging'      => null !== $staging && $staging->exists() ? Staging::DIR : null,
                'map_entries'  => null === $staging ? 0 : count(MapFile::read($staging)),
                'archive_import_dir' => $readable && is_dir($real.'/import'),
            ];
        }

        return $this->ok(['roots' => $out, 'count' => count($out)]);
    }

    /** GET /ingest/manifest */
    public function manifest(Request $request): JsonResponse
    {
        $args     = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'manifest_path' => ['sometimes', 'nullable', 'string', 'max:4096']], true);
        $root     = StatementsRoot::resolve($args['root'] ?? null);
        $manifest = Manifest::load($root, $args['manifest_path'] ?? null, (string) $this->primaryCurrency()->code);
        $accounts = [];
        foreach ($manifest['accounts'] as $a) {
            $accounts[] = self::publicAccount($a) + ['files' => self::files($a, $manifest['mode'])];
        }

        return $this->ok([
            'root'            => $root,
            'manifest_path'   => $manifest['manifest_path'],
            'mode'            => $manifest['mode'],
            'synthesized'     => $manifest['synthesized'],
            'accounts'        => $accounts,
            'missing_columns' => $manifest['missing_columns'],
            'unknown_columns' => $manifest['unknown_columns'],
            'warnings'        => $manifest['warnings'],
            'totals'          => $manifest['totals'],
            'staging'         => Staging::DIR,
        ]);
    }

    /** POST /ingest/scan — walks the tree, reads every statement, changes nothing. */
    public function scan(Request $request): JsonResponse
    {
        $args     = $this->input($request, [
            'root'        => ['sometimes', 'nullable', 'string', 'max:4096'],
            'mode'        => ['sometimes', 'nullable', 'in:prepared,raw'],
            'entity'      => ['sometimes', 'nullable', 'string', 'max:255'],
            'institution' => ['sometimes', 'nullable', 'string', 'max:255'],
            'account'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'year'        => ['sometimes', 'nullable', 'integer', 'min:1900', 'max:2200'],
        ]);
        [$root, $manifest, $accounts] = $this->scope($args);
        $year     = isset($args['year']) ? (int) $args['year'] : null;
        $collected = Collector::collect($root, $manifest, $accounts, ['mode' => $args['mode'] ?? null, 'year' => $year]);
        $groups   = [];
        $summary  = [];
        $missing  = [];
        $dupScans = [];
        $unread   = [];
        foreach ($collected['accounts'] as $c) {
            $a        = $c['account'];
            $byYear   = [];
            $counts   = ['statements' => 0, 'primary' => 0, 'duplicates' => 0, 'superseded' => 0, 'conflicts' => 0, 'unreadable' => 0, 'misfiled' => 0];
            foreach ($c['statements'] as $s) {
                $y                 = (string) substr((string) ($s->periodStart ?? ($s->months()[0] ?? '????')), 0, 4);
                $byYear[$y]      ??= ['statements' => 0, 'duplicates' => 0, 'unreadable' => 0];
                ++$byYear[$y]['statements'];
                ++$counts['statements'];
                match ($s->status) {
                    ParsedStatement::PRIMARY             => ++$counts['primary'],
                    ParsedStatement::DUPLICATE_IDENTICAL => ++$counts['duplicates'],
                    ParsedStatement::SUPERSEDED          => ++$counts['superseded'],
                    ParsedStatement::CONFLICT            => ++$counts['conflicts'],
                    ParsedStatement::MISFILED            => ++$counts['misfiled'],
                    default                              => ++$counts['unreadable'],
                };
                if (in_array($s->status, [ParsedStatement::DUPLICATE_IDENTICAL, ParsedStatement::SUPERSEDED, ParsedStatement::CONFLICT], true)) {
                    ++$byYear[$y]['duplicates'];
                }
                if (in_array($s->status, [ParsedStatement::UNREADABLE, ParsedStatement::MISFILED], true)) {
                    ++$byYear[$y]['unreadable'];
                    $unread[] = ['account' => $a['key'], 'file' => $s->relative, 'status' => $s->status, 'reason' => (string) $s->rule];
                }
            }
            foreach ($c['coverage']['missing'] as $m) {
                if (null !== $year && (int) substr($m, 0, 4) !== $year) {
                    continue;
                }
                $missing[] = ['entity' => $a['entity'], 'institution' => $a['institution'], 'account' => $a['label'], 'period' => $m];
                $y         = substr($m, 0, 4);
                $byYear[$y] ??= ['statements' => 0, 'duplicates' => 0, 'unreadable' => 0];
                $byYear[$y]['missing'] = ($byYear[$y]['missing'] ?? 0) + 1;
            }
            ksort($byYear, SORT_STRING);
            foreach ($byYear as $y => $n) {
                $groups[] = ['entity' => $a['entity'], 'institution' => $a['institution'], 'account' => $a['label'], 'year' => $y, 'statements' => $n['statements'], 'missing' => $n['missing'] ?? 0, 'duplicates' => $n['duplicates'], 'unreadable' => $n['unreadable']];
            }
            foreach ($c['groups'] as $g) {
                if ('single' !== $g['verdict']) {
                    $dupScans[] = ['account' => $a['key'], 'period' => $g['period'], 'verdict' => $g['verdict'], 'files' => array_column($g['members'], 'file')];
                }
            }
            $summary[] = ['key' => $a['key'], 'entity' => $a['entity'], 'institution' => $a['institution'], 'label' => $a['label'], 'last4' => $a['last4']]
                + $counts
                + ['not_chosen' => count($c['not_chosen']), 'rows' => count($c['rows']), 'first' => $c['coverage']['first'], 'last' => $c['coverage']['last'], 'missing' => count($c['coverage']['missing'])];
        }

        return $this->ok([
            'root'            => $root,
            'mode'            => $collected['mode'],
            'groups'          => $groups,
            'accounts'        => $summary,
            'missing_months'  => $missing,
            'duplicate_scans' => $dupScans,
            'unreadable'      => $unread,
            'warnings'        => $manifest['warnings'],
        ]);
    }

    /** GET /ingest/coverage */
    public function coverage(Request $request): JsonResponse
    {
        $args      = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'account' => ['sometimes', 'nullable', 'string', 'max:255']], true);
        [$root, $manifest, $accounts] = $this->scope($args);
        $collected = Collector::collect($root, $manifest, $accounts);
        $out       = [];
        $missing   = [];
        foreach ($collected['accounts'] as $c) {
            $a     = $c['account'];
            $out[] = ['key' => $a['key'], 'entity' => $a['entity'], 'institution' => $a['institution'], 'account' => $a['label']] + $c['coverage'];
            foreach ($c['coverage']['missing'] as $m) {
                $missing[] = ['entity' => $a['entity'], 'institution' => $a['institution'], 'account' => $a['label'], 'period' => $m];
            }
        }

        return $this->ok(['root' => $root, 'accounts' => $out, 'missing' => $missing]);
    }

    // ----------------------------------------------------------------------- map ---

    /** GET /ingest/map */
    public function map(Request $request): JsonResponse
    {
        $args     = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096']], true);
        $root     = StatementsRoot::resolve($args['root'] ?? null);
        $manifest = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
        $map      = MapFile::read(new Staging($root));
        $ledger   = $this->ledger();
        $rows     = [];
        $summary  = ['mapped' => 0, 'unmapped' => 0, 'stale' => 0, 'orphan' => 0];
        $keys     = [];
        foreach ($manifest['accounts'] as $a) {
            if (true === ($a['ignored'] ?? false)) {
                continue;
            }
            $keys[$a['key']] = true;
            $entry           = $map[$a['key']] ?? null;
            $target          = null === $entry ? null : $ledger->find((int) $entry['account_id']);
            $status          = null === $entry ? 'unmapped' : (null === $target ? 'stale' : 'mapped');
            ++$summary[$status];
            $rows[]          = ['key' => $a['key'], 'entity' => $a['entity'], 'institution' => $a['institution'], 'label' => $a['label'], 'last4' => $a['last4'], 'kind' => $a['kind'],
                'account_id'  => null === $entry ? null : (int) $entry['account_id'], 'account_name' => $target['name'] ?? ($entry['account_name'] ?? null), 'status' => $status];
        }
        foreach ($map as $key => $entry) {
            if (!isset($keys[$key])) {
                ++$summary['orphan'];
                $rows[] = ['key' => $key, 'entity' => $entry['entity'] ?? null, 'institution' => $entry['institution'] ?? null, 'label' => $entry['label'] ?? null, 'last4' => $entry['last4'] ?? null, 'kind' => null,
                    'account_id' => (int) $entry['account_id'], 'account_name' => $ledger->find((int) $entry['account_id'])['name'] ?? ($entry['account_name'] ?? null), 'status' => 'orphan'];
            }
        }

        return $this->ok(['root' => $root, 'map_path' => Staging::DIR.'/'.MapFile::FILE, 'accounts' => $rows, 'summary' => $summary]);
    }

    /**
     * PUT /ingest/map — merge entries into the map (an `account_id` of null removes one). Entries
     * are a list of {key | entity+institution+label | label | last4, account_id} or an object
     * {key: account_id}. Written beside the statements, never in the repo; a file, so it is not
     * in the operation log (undo it by writing the old id back).
     */
    public function mapPut(Request $request): JsonResponse
    {
        $args  = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'map' => ['required', 'array', 'max:5000']]);
        $root  = StatementsRoot::resolve($args['root'] ?? null);
        $clean = ['root' => $root, 'map' => $args['map']];

        return $this->write($request, $clean, function (bool $dryRun) use ($root, $args): WriteResult {
            $staging  = new Staging($root);
            $manifest = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
            $ledger   = $this->ledger();
            $map      = MapFile::read($staging);
            $before   = MapFile::digest($staging);
            $result   = new WriteResult();
            $changes  = [];
            foreach (self::mapItems($args['map']) as $i => [$selector, $accountId]) {
                $m   = Manifest::select($manifest['accounts'], [$selector], sprintf('map.%s', (string) $i))[0];
                $key = $m['key'];
                if (null === $accountId) {
                    if (isset($map[$key])) {
                        unset($map[$key]);
                        $result->count('unmapped');
                        $changes[] = ['key' => $key, 'account_id' => null, 'change' => 'unmapped'];
                    } else {
                        $result->count('unchanged');
                    }

                    continue;
                }
                $target = $ledger->find($accountId);
                if (null === $target) {
                    throw MachineException::notFound(sprintf('No asset or liability account #%d in this administration.', $accountId), 'GET /machine/v1/accounts lists them — map statements to asset or liability accounts only', ['field' => sprintf('map.%s', (string) $i), 'account_id' => $accountId]);
                }
                if (($map[$key]['account_id'] ?? null) === $accountId) {
                    $result->count('unchanged');

                    continue;
                }
                $map[$key]  = AccountProvisioner::mapEntry($m, $accountId, (string) $target['name'], 'map/put');
                $result->count('mapped');
                $changes[]  = ['key' => $key, 'account_id' => $accountId, 'account_name' => $target['name'], 'change' => 'mapped'];
            }
            if (!$dryRun && $result->changeCount() > 0) {
                MapFile::write($staging, $map);
            }
            $result->basis = ['before' => $before];

            return $result->with(['root' => $root, 'map_path' => Staging::DIR.'/'.MapFile::FILE, 'entries' => $changes]);
        }, null, ['record' => false]);
    }

    /** POST /ingest/map/infer — proposes, never saves. */
    public function mapInfer(Request $request): JsonResponse
    {
        $args     = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'strategy' => ['sometimes', 'nullable', 'in:last4,name,last4_then_name']]);
        $strategy = $args['strategy'] ?? 'last4_then_name';
        $root     = StatementsRoot::resolve($args['root'] ?? null);
        $manifest = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
        $map      = MapFile::read(new Staging($root));
        $ledger   = $this->ledger();
        $claimed  = [];
        foreach ($map as $e) {
            $claimed[(int) $e['account_id']] = true;
        }
        $rows     = [];
        foreach ($manifest['accounts'] as $a) {
            if (true === ($a['ignored'] ?? false)) {
                continue;
            }
            $base  = ['key' => $a['key'], 'entity' => $a['entity'], 'institution' => $a['institution'], 'label' => $a['label'], 'last4' => $a['last4']];
            if (isset($map[$a['key']]) && null !== ($t = $ledger->find((int) $map[$a['key']]['account_id']))) {
                $rows[] = $base + ['account_id' => $t['id'], 'account_name' => $t['name'], 'status' => 'mapped', 'via' => 'map'];

                continue;
            }
            $free  = static fn (array $list): array => array_values(array_filter($list, static fn (array $r): bool => !isset($claimed[$r['id']])));
            $hits  = [];
            $via   = null;
            if ('name' !== $strategy && null !== $a['last4']) {
                $hits = $free($ledger->byLast4((string) $a['last4']));
                $via  = 'last4';
            }
            if ([] === $hits && 'last4' !== $strategy) {
                $hits = $free($ledger->byName(AccountProvisioner::name($a, AccountProvisioner::DEFAULT_NAMING)));
                if ([] === $hits && '' !== (string) $a['label']) {
                    $label = mb_strtolower((string) $a['label']);
                    $hits  = $free(array_values(array_filter($ledger->all(), static fn (array $r): bool => str_contains(mb_strtolower($r['name']), $label))));
                }
                $via  = 'name';
            }
            $rows[] = match (count($hits)) {
                0       => $base + ['account_id' => null, 'account_name' => null, 'status' => 'none', 'via' => null],
                1       => $base + ['account_id' => $hits[0]['id'], 'account_name' => $hits[0]['name'], 'status' => 'proposed', 'via' => $via],
                default => $base + ['account_id' => null, 'account_name' => null, 'status' => 'ambiguous', 'via' => $via, 'candidates' => array_map(LedgerAccounts::brief(...), $hits)],
            };
        }

        return $this->ok(['root' => $root, 'strategy' => $strategy, 'accounts' => $rows, 'saved' => false, 'hint' => 'nothing was saved — PUT /machine/v1/ingest/map with the entries you accept, or POST /ingest/accounts/plan']);
    }

    // ------------------------------------------------------ account provisioning ---

    /** POST /ingest/accounts/plan (read) — §12.1. Creates nothing; runs the apply rolled back for the token. */
    public function accountsPlan(Request $request): JsonResponse
    {
        $args  = $this->input($request, [
            'root'              => ['sometimes', 'nullable', 'string', 'max:4096'],
            'naming'            => ['sometimes', 'nullable', 'string', 'min:1', 'max:255'],
            'liability_kinds'   => ['sometimes', 'nullable', 'array', 'max:6'],
            'liability_kinds.*' => ['string', 'in:'.implode(',', self::KINDS_RULE)],
        ]);
        $kinds = array_values(array_unique($args['liability_kinds'] ?? ['loan', 'mortgage']));
        sort($kinds);
        $clean = ['root' => StatementsRoot::resolve($args['root'] ?? null), 'naming' => $args['naming'] ?? AccountProvisioner::DEFAULT_NAMING, 'liability_kinds' => $kinds];
        $apply = $this->accountsClosure($clean);
        $held  = $this->locked(static fn () => DryRun::run(static fn () => $apply(true)));
        $res   = $held->value;
        $token = $this->mintFor($request, 'POST /ingest/accounts/apply', $clean, $res);
        $plan  = $res->data['plan'];

        return $this->ok([
            'root'            => $clean['root'],
            'naming'          => $clean['naming'],
            'liability_kinds' => $kinds,
            'plan'            => array_map(static fn (array $p): array => array_diff_key($p, ['account_id' => true]), $plan),
            'summary'         => self::planSummary($plan),
            'change_count'    => $res->changeCount(),
            'fingerprint'     => $res->fingerprint(),
        ] + $token);
    }

    /** POST /ingest/accounts/apply (write) — §12.4. */
    public function accountsApply(Request $request): JsonResponse
    {
        $this->input($request, []);
        $args = $this->argsFor($request, 'POST /ingest/accounts/apply', 'POST /machine/v1/ingest/accounts/plan');

        return $this->applyWith($request, 'POST /ingest/accounts/apply', 'accounts', $args, $this->accountsClosure($args), static fn (WriteResult $r): array => [
            'root'    => $args['root'],
            'plan'    => $r->data['plan'],
            'created' => $r->data['created'],
            'summary' => self::planSummary($r->data['plan']),
            'map_path' => $r->data['map_path'],
        ]);
    }

    // ------------------------------------------------------------- raw mode R* ---

    /** POST /ingest/extract (R*) — raw mode only; writes to the staging directory only. */
    public function extract(Request $request): JsonResponse
    {
        $args     = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'force' => ['sometimes', 'nullable', 'boolean']]);
        $root     = StatementsRoot::resolve($args['root'] ?? null);
        $manifest = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
        if ('raw' !== $manifest['mode']) {
            throw MachineException::invalid(
                sprintf('This tree is prepared (%s describes it): there is nothing to extract.', (string) $manifest['manifest_path']),
                'Prepared files are parsed directly — go straight to POST /machine/v1/ingest/plan',
                ['mode' => $manifest['mode']],
            );
        }
        $staging  = new Staging($root);
        $staging->ensure();
        $started  = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        StatementReader::$pdfBudget   = (int) config('machine.ingest.extract_max', 500);
        StatementReader::$pdfRuns     = 0;
        StatementReader::$pdfDeferred = 0;

        try {
            $collected = Collector::collect($root, $manifest, Manifest::select($manifest['accounts'], []), ['extract_pdf' => true, 'force' => (bool) ($args['force'] ?? false)]);
        } finally {
            StatementReader::$pdfBudget = PHP_INT_MAX;
        }
        $this->writeStaging($staging, $collected['accounts']);

        $statements = [];
        $empty      = [];
        $rows       = 0;
        $conflicts  = 0;
        $parsed     = 0;
        foreach ($collected['accounts'] as $c) {
            $rows      += count($c['rows']);
            $conflicts += count($c['conflicts']);
            foreach ($c['statements'] as $s) {
                if (ParsedStatement::UNREADABLE !== $s->status) {
                    ++$parsed;
                }
                $statements[] = ['account' => $c['account']['key'], 'file' => $s->relative, 'source' => $s->source(), 'source_kind' => $s->sourceKind, 'rows' => count($s->rows), 'bad_rows' => count($s->bad), 'period' => $s->period(), 'status' => $s->status, 'rule' => $s->rule];
                if ([] === $s->rows && !in_array($s->status, [ParsedStatement::DUPLICATE_IDENTICAL, ParsedStatement::MISFILED], true)) {
                    $empty[] = ['file' => $s->relative, 'account' => $c['account']['key'], 'reason' => $s->rule ?? 'no transaction lines'];
                }
            }
        }
        $id         = RunLog::newId();
        $line       = ['root' => $root, 'statements' => count($statements), 'extracted' => $parsed, 'rows' => $rows, 'empty' => count($empty), 'conflicts' => $conflicts, 'pdf_runs' => StatementReader::$pdfRuns, 'outcome' => [] === $empty ? 'extracted' : 'extracted_with_empty'];
        RunLog::record($staging, $id, 'extract', $started, $line, ['statements' => $statements, 'empty' => $empty]);

        return $this->ok([
            'root'             => $root,
            'staging'          => Staging::DIR,
            'run_id'           => $id,
            'extracted'        => $parsed,
            'rows'             => $rows,
            'statements'       => $statements,
            'empty'            => $empty,
            'conflicts'        => $conflicts,
            'pdf_runs'         => StatementReader::$pdfRuns,
            'ceiling_reached'  => StatementReader::$pdfDeferred > 0,
            'deferred'         => StatementReader::$pdfDeferred,
        ]);
    }

    /** GET /ingest/dupes — both layers, every verdict and the rule that decided it. */
    public function dupes(Request $request): JsonResponse
    {
        $args      = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'account' => ['sometimes', 'nullable', 'string', 'max:255']], true);
        [$root, $manifest, $accounts] = $this->scope($args);
        $collected = Collector::collect($root, $manifest, $accounts);
        $flat      = [];
        $groups    = [];
        $summary   = ['statement_groups' => 0, 'duplicate_identical' => 0, 'superseded' => 0, 'conflicts' => 0, 'not_chosen' => 0, 'rows_collapsed' => 0, 'tie_groups' => 0];
        foreach ($collected['accounts'] as $c) {
            foreach ($c['groups'] as $g) {
                if ('single' === $g['verdict']) {
                    continue;
                }
                ++$summary['statement_groups'];
                if ('conflict' === $g['verdict']) {
                    ++$summary['conflicts'];
                }
                $groups[] = $g;
                foreach ($g['members'] as $m) {
                    if (ParsedStatement::DUPLICATE_IDENTICAL === $m['status']) {
                        ++$summary['duplicate_identical'];
                    }
                    if (ParsedStatement::SUPERSEDED === $m['status']) {
                        ++$summary['superseded'];
                    }
                    $flat[] = ['layer' => 'statement', 'account' => $g['account'], 'period' => $g['period'], 'verdict' => ParsedStatement::PRIMARY === $m['status'] ? ('resolved' === $g['verdict'] ? 'preferred' : 'primary') : $m['status'],
                        'rule' => $m['rule'], 'file' => $m['file'], 'superseded_by' => $m['superseded_by'], 'ordinal' => null, 'tie_group' => null, 'group' => $g['group']];
                }
            }
            foreach ($c['not_chosen'] as $n) {
                ++$summary['not_chosen'];
                $flat[] = ['layer' => 'file', 'account' => $c['account']['key'], 'period' => null, 'verdict' => ParsedStatement::NOT_CHOSEN, 'rule' => $n['rule'], 'file' => $n['file'], 'superseded_by' => null, 'ordinal' => null, 'tie_group' => null];
            }
            foreach ($c['dupes'] as $d) {
                if ('collapsed' === $d['verdict']) {
                    ++$summary['rows_collapsed'];
                } else {
                    ++$summary['tie_groups'];
                }
                $flat[] = $d;
            }
        }

        return $this->ok(['root' => $root, 'groups' => $flat, 'statement_groups' => $groups, 'summary' => $summary]);
    }

    /** POST /ingest/prefer (R*) — the operator's answer to a conflict; recorded in staging only. */
    public function prefer(Request $request): JsonResponse
    {
        $args      = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096'], 'path' => ['required', 'string', 'max:4096']]);
        $root      = StatementsRoot::resolve($args['root'] ?? null);
        $file      = StatementsRoot::contain($root, (string) $args['path']);
        $relative  = StatementsRoot::relative($root, $file);
        $manifest  = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
        $staging   = new Staging($root);
        $staging->ensure();
        $collected = Collector::collect($root, $manifest, Manifest::select($manifest['accounts'], []));
        $found     = null;
        foreach ($collected['accounts'] as $c) {
            foreach ($c['groups'] as $g) {
                if (!in_array($g['verdict'], ['conflict', 'resolved'], true)) {
                    continue;
                }
                foreach ($g['members'] as $m) {
                    if ($m['file'] === $relative || $m['source'] === $relative) {
                        $found = [$g, $m['file']];
                    }
                }
            }
        }
        if (null === $found) {
            throw MachineException::invalid(
                sprintf('No statement conflict involves %s.', $relative),
                'GET /machine/v1/ingest/dupes lists the conflicts and their files — prefer one of those',
                ['path' => $relative],
            );
        }
        [$group, $winner] = $found;
        $answer    = Preferences::answer($group['group'], $group['account'], $group['period'], $winner, implode(' | ', array_column($group['members'], 'file')));
        // re-collect so the rebuilt _conflicts.csv shows the group as resolved
        Preferences::write($staging, Collector::collect($root, $manifest, Manifest::select($manifest['accounts'], []))['accounts'], [$group['group'] => $answer]);

        return $this->ok([
            'root'      => $root,
            'recorded'  => true,
            'preferred' => $winner,
            'group'     => $group['group'],
            'account'   => $group['account'],
            'period'    => $group['period'],
            'others'    => array_values(array_diff(array_column($group['members'], 'file'), [$winner])),
            'file'      => Staging::DIR.'/'.Preferences::FILE,
            'hint'      => 'the account-month is unblocked — re-run POST /machine/v1/ingest/plan',
        ]);
    }

    /** GET /ingest/rows — parsed, de-duplicated rows; never statement text. */
    public function rows(Request $request): JsonResponse
    {
        $args      = $this->input($request, [
            'root'    => ['sometimes', 'nullable', 'string', 'max:4096'],
            'account' => ['sometimes', 'nullable', 'string', 'max:255'],
            'start'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'     => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ] + self::LIST_RULES, true);
        $params    = $this->listParams($request, ['date', 'amount', 'external_id', 'account'], 'date');
        [$root, $manifest, $accounts] = $this->scope($args);
        $collected = Collector::collect($root, $manifest, $accounts);
        $rows      = [];
        foreach ($collected['accounts'] as $c) {
            foreach ($c['rows'] as $r) {
                if ((isset($args['start']) && $r['date'] < $args['start']) || (isset($args['end']) && $r['date'] > $args['end'])) {
                    continue;
                }
                $rows[] = [
                    'id'                 => $r['external_id'],
                    'account'            => $r['account'],
                    'date'               => $r['date'],
                    'type'               => $r['type'],
                    'amount'             => Money::abs((string) $r['amount']),
                    'signed_amount'      => $r['amount'],
                    'description'        => $r['description'],
                    'internal_reference' => $r['internal_reference'],
                    'external_id'        => $r['external_id'],
                    'ordinal'            => $r['ordinal'],
                    'tie_group'          => $r['tie_group'],
                    'source_file'        => $r['source_file'],
                    'source_kind'        => $r['source_kind'],
                    'source_line'        => $r['source_line'],
                    'status'             => $r['status'],
                ];
            }
        }
        $page      = $this->applyList($rows, $params);
        $this->addMeta(['untrusted' => ['rows[].description', 'rows[].internal_reference']]);

        return $this->ok(['root' => $root, 'rows' => $page, 'total' => count($rows)]);
    }

    // ------------------------------------------------------------ plan / apply ---

    /** POST /ingest/plan (read) — Firefly's store path, rolled back: new, already present, previously deleted. */
    public function plan(Request $request): JsonResponse
    {
        $args  = $this->input($request, [
            'root'        => ['sometimes', 'nullable', 'string', 'max:4096'],
            'accounts'    => ['sometimes', 'nullable', 'array', 'max:500'],
            'accounts.*'  => ['string', 'max:255'],
            'start'       => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end'         => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'apply_rules' => ['sometimes', 'nullable', 'boolean'],
        ]);
        $sel   = array_values(array_unique(array_map('strval', $args['accounts'] ?? [])));
        sort($sel, SORT_STRING);
        $clean = [
            'root'        => StatementsRoot::resolve($args['root'] ?? null),
            'accounts'    => $sel,
            'start'       => $args['start'] ?? null,
            'end'         => $args['end'] ?? null,
            'apply_rules' => (bool) ($args['apply_rules'] ?? true),
        ];

        return $this->planWith($request, 'POST /ingest/apply', 'plan', $clean, $this->importClosure($clean));
    }

    /** POST /ingest/apply (write) */
    public function apply(Request $request): JsonResponse
    {
        $this->input($request, []);
        $args = $this->argsFor($request, 'POST /ingest/apply', 'POST /machine/v1/ingest/plan');

        return $this->applyWith($request, 'POST /ingest/apply', 'apply', $args, $this->importClosure($args), self::importRender($args));
    }

    /** POST /ingest/file/plan (read) — one file into one account. */
    public function filePlan(Request $request): JsonResponse
    {
        $args      = $this->input($request, [
            'account_id'   => ['required_without:account_name', 'nullable', 'integer', 'min:1'],
            'account_name' => ['required_without:account_id', 'nullable', 'string', 'max:1024'],
            'path'         => ['required', 'string', 'max:4096'],
            'apply_rules'  => ['sometimes', 'nullable', 'boolean'],
        ]);
        $ledger    = $this->ledger();
        $accountId = isset($args['account_id']) ? (int) $args['account_id'] : null;
        if (null === $accountId) {
            $hits = $ledger->byName((string) $args['account_name']);
            if (1 !== count($hits)) {
                throw 0 === count($hits)
                    ? MachineException::notFound(sprintf('No asset or liability account named "%s".', (string) $args['account_name']), 'Pass account_id, or the exact name (GET /machine/v1/accounts)', ['name' => $args['account_name']])
                    : MachineException::invalid(sprintf('"%s" matches %d accounts.', (string) $args['account_name'], count($hits)), 'Pass account_id instead — the candidates are in details.candidates', ['candidates' => array_map(LedgerAccounts::brief(...), $hits)]);
            }
            $accountId = (int) $hits[0]['id'];
        }
        if (null === $ledger->find($accountId)) {
            throw MachineException::notFound(sprintf('No asset or liability account #%d in this administration.', $accountId), 'Statements import into asset or liability accounts only (GET /machine/v1/accounts)', ['account_id' => $accountId]);
        }
        $path      = (string) $args['path'];
        [$root, $file] = str_starts_with(StatementsRoot::expandHome($path), '/')
            ? StatementsRoot::containAnywhere($path)
            : (static function () use ($path): array { $r = StatementsRoot::resolve(null); return [$r, StatementsRoot::contain($r, $path)]; })();
        $clean     = ['path' => $file, 'root' => $root, 'account_id' => $accountId, 'apply_rules' => (bool) ($args['apply_rules'] ?? true)];

        return $this->planWith($request, 'POST /ingest/file/apply', 'file_plan', $clean, $this->fileClosure($clean));
    }

    /** POST /ingest/file/apply (write) */
    public function fileApply(Request $request): JsonResponse
    {
        $this->input($request, []);
        $args = $this->argsFor($request, 'POST /ingest/file/apply', 'POST /machine/v1/ingest/file/plan');

        return $this->applyWith($request, 'POST /ingest/file/apply', 'file_apply', $args, $this->fileClosure($args), self::importRender($args));
    }

    // ---------------------------------------------------------------------- runs ---

    /** GET /ingest/runs */
    public function runs(Request $request): JsonResponse
    {
        $args   = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096']] + self::LIST_RULES, true);
        $params = $this->listParams($request, ['started', 'kind', 'id'], '-started');
        $rows   = [];
        foreach ($this->runRoots($args['root'] ?? null) as $root) {
            foreach (RunLog::all(new Staging($root), $root) as $r) {
                $rows[] = $r;
            }
        }

        return $this->ok(['runs' => $this->applyList($rows, $params, 'id'), 'total' => count($rows)]);
    }

    /** GET /ingest/runs/{id} */
    public function run(Request $request, string $id): JsonResponse
    {
        $args = $this->input($request, ['root' => ['sometimes', 'nullable', 'string', 'max:4096']], true);
        foreach ($this->runRoots($args['root'] ?? null) as $root) {
            $doc = RunLog::find(new Staging($root), $id);
            if (null !== $doc) {
                $report = (array) ($doc['report'] ?? []);

                return $this->ok(['run' => $doc['run'] ?? null] + $report + ['root' => $root]);
            }
        }

        throw MachineException::notFound(sprintf('No ingest run %s.', $id), 'GET /machine/v1/ingest/runs lists them (pass root= for a sub-root)', ['id' => $id]);
    }

    // ================================================================= internals ===

    /**
     * The import closure: collect → map → Firefly's store path. The plan runs it rolled back, the
     * apply runs it for real; both from the same normalised arguments.
     *
     * @param array<string, mixed> $args
     *
     * @return Closure(bool): WriteResult
     */
    private function importClosure(array $args): Closure
    {
        return function (bool $dryRun) use ($args): WriteResult {
            $root      = (string) $args['root'];
            $manifest  = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
            $selected  = Manifest::select($manifest['accounts'], $args['accounts']);
            $collected = Collector::collect($root, $manifest, $selected);
            $map       = MapFile::read(new Staging($root));
            $ledger    = $this->ledger();
            $plans     = [];
            $blocked   = [];
            foreach ($collected['accounts'] as $c) {
                $a        = $c['account'];
                $entry    = $map[$a['key']] ?? null;
                $target   = null === $entry ? null : $ledger->find((int) $entry['account_id']);
                $rows     = array_values(array_filter($c['rows'], static fn (array $r): bool => (null === $args['start'] || $r['date'] >= $args['start']) && (null === $args['end'] || $r['date'] <= $args['end'])));
                $summary  = self::accountSummary($c, $rows, $target);
                if (null === $target) {
                    $summary['blocked']        = true;
                    $summary['blocked_reason'] = null === $entry ? 'unmapped — POST /ingest/accounts/plan, or PUT /ingest/map' : sprintf('mapped to #%d, which no longer exists — PUT /ingest/map', (int) $entry['account_id']);
                    $blocked[]                 = ['account' => $a['key'], 'reason' => $summary['blocked_reason']];
                }
                $plans[]  = ['summary' => $summary, 'account_id' => $target['id'] ?? null, 'rows' => $rows];
            }
            $result    = Importer::run($ledger, $plans, (bool) $args['apply_rules']);
            $dates     = [];
            foreach ($plans as $p) {
                foreach ($p['rows'] as $r) {
                    $dates[] = $r['date'];
                }
            }
            $blockedRows = array_sum(array_map(static fn (array $p): int => (int) $p['summary']['blocked_rows'], $plans));
            if ($blockedRows > 0) {
                $result->count('blocked', $blockedRows);
            }

            return $result->with([
                'root'       => $root,
                'mode'       => $collected['mode'],
                'date_range' => [] === $dates ? null : ['start' => min($dates), 'end' => max($dates)],
                'blocked'    => $blocked,
            ]);
        };
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return Closure(bool): WriteResult
     */
    private function fileClosure(array $args): Closure
    {
        return function (bool $dryRun) use ($args): WriteResult {
            $root      = (string) $args['root'];
            $file      = StatementsRoot::contain($root, (string) $args['path']);
            $ledger    = $this->ledger();
            $target    = $ledger->find((int) $args['account_id']);
            if (null === $target) {
                throw MachineException::notFound(sprintf('Account #%d no longer exists.', (int) $args['account_id']), 'Re-plan with another account');
            }
            $kind      = 'liability' === $target['type'] ? 'loan' : ('ccAsset' === $target['role'] ? 'card' : 'checking');
            $numLast4  = (string) preg_replace('/\D/', '', (string) ($target['account_number'] ?? ''));
            $account   = ['key' => 'file:'.StatementsRoot::relative($root, $file), 'entity' => null, 'institution' => null, 'label' => basename($file), 'kind' => $kind, 'last4' => strlen($numLast4) >= 4 ? substr($numLast4, -4) : null];
            $statement = StatementReader::readFile($root, $file, $account);
            if (null === $statement || ParsedStatement::UNREADABLE === $statement->status) {
                throw MachineException::invalid(
                    sprintf('%s is not a readable statement file.', StatementsRoot::relative($root, $file)),
                    'Pass an .ofx, .qfx, camt.053 .xml, .csv or statement-text .txt file',
                    ['path' => StatementsRoot::relative($root, $file), 'why' => null === $statement ? 'unsupported format' : $statement->rule],
                );
            }
            $account['last4'] ??= $statement->last4;
            $layerOne  = StatementDedupe::run([$statement], $account, []);
            $built     = RowBuilder::build($account, [$statement], $layerOne['blocked_months']);
            $c         = ['account' => $account, 'statements' => [$statement], 'conflicts' => [], 'rows' => $built['rows'], 'dupes' => $built['dupes'], 'bad' => $built['bad']];
            $summary   = self::accountSummary($c, $built['rows'], $target);
            $result    = Importer::run($ledger, [['summary' => $summary, 'account_id' => (int) $target['id'], 'rows' => $built['rows']]], (bool) $args['apply_rules']);
            $dates     = array_column($built['rows'], 'date');

            return $result->with([
                'root'       => $root,
                'path'       => StatementsRoot::relative($root, $file),
                'date_range' => [] === $dates ? null : ['start' => min($dates), 'end' => max($dates)],
                'bad_rows'   => $built['bad'],
            ]);
        };
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return Closure(bool): WriteResult
     */
    private function accountsClosure(array $args): Closure
    {
        return function (bool $dryRun) use ($args): WriteResult {
            $root     = (string) $args['root'];
            $manifest = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
            if ([] !== array_intersect(['entity', 'institution', 'label'], $manifest['missing_columns'])) {
                throw MachineException::invalid('The manifest lacks the columns accounts are named from.', 'Add the missing columns to the manifest (it is never rewritten by this plane)', ['missing_columns' => $manifest['missing_columns']]);
            }
            $staging  = new Staging($root);
            $map      = MapFile::read($staging);
            $ledger   = $this->ledger();
            $planned  = AccountProvisioner::plan($manifest['accounts'], $ledger, $map, (string) $args['naming'], (array) $args['liability_kinds']);
            if (!$dryRun) {
                $staging->ensure(); // refuse a foreign staging dir BEFORE creating anything
            }
            $basis    = hash('sha256', (string) json_encode(array_map(static fn (array $p): array => [$p['key'], $p['action'], $p['proposed']['name'] ?? null, $p['existing']['id'] ?? null], $planned['plan'])));

            return AccountProvisioner::apply($planned['plan'], $manifest['accounts'], $ledger, $map, $staging, $dryRun, $basis);
        };
    }

    /**
     * A plan route: run the apply's closure rolled back, mint the apply route's token, log the run.
     *
     * @param array<string, mixed>        $args
     * @param Closure(bool): WriteResult $apply
     */
    private function planWith(Request $request, string $applyKey, string $kind, array $args, Closure $apply): JsonResponse
    {
        $staging = new Staging((string) $args['root']);
        $staging->ensure(); // the plan and its run log live in staging; a foreign directory is refused first
        $started = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $held    = $this->locked(static fn () => DryRun::run(static fn () => $apply(true)));
        $result  = $held->value;
        if (!$result instanceof WriteResult) {
            throw MachineException::internal('The ingest plan did not return a result.');
        }
        $token   = $this->mintFor($request, $applyKey, $args, $result);
        $id      = RunLog::newId();
        $data    = array_merge($result->data, [
            'dry_run'             => true,
            'changes'             => $result->changes,
            'change_count'        => $result->changeCount(),
            'would_fire_webhooks' => $held->webhooks,
            'held'                => $held->held(),
            'fingerprint'         => $result->fingerprint(),
            'run_id'              => $id,
            'max_changes_default' => (int) config('machine.limits.max_changes_default', 200),
        ], $token);
        if ($result->changeCount() > (int) config('machine.limits.max_changes_default', 200)) {
            $data['hint'] = sprintf('%d rows would be stored — apply with max_changes: %d or more', $result->changeCount(), $result->changeCount());
        }
        RunLog::record($staging, $id, $kind, $started, self::runLine($args, $result, 'planned'), ['args' => self::publicArgs($args), 'accounts' => $result->data['accounts'] ?? $result->data['plan'] ?? [], 'rows' => $result->data['rows'] ?? [], 'changes' => $result->changes]);
        $this->addMeta(['untrusted' => ['rows[].description']]);

        return $this->ok($data, ['dryRun' => true]);
    }

    /**
     * An apply route: the core write protocol over the plan's arguments, then the run log.
     *
     * @param array<string, mixed>                               $args
     * @param Closure(bool): WriteResult                        $apply
     * @param Closure(WriteResult): array<string, mixed>        $render
     */
    private function applyWith(Request $request, string $applyKey, string $kind, array $args, Closure $apply, Closure $render): JsonResponse
    {
        $staging = new Staging((string) $args['root']);
        $started = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $last    = null;
        $wrapped = static function (bool $dryRun) use ($apply, &$last): WriteResult {
            $last = $apply($dryRun);

            return $last;
        };

        try {
            $response = $this->write($request, $args, $wrapped, static fn (WriteResult $r, bool $dry): array => $render($r));
        } catch (Throwable $e) {
            if (!$this->isDryRun($request)) {
                try {
                    $code = $e instanceof MachineException ? $e->errorCode : 'internal';
                    RunLog::record($staging, RunLog::newId(), $kind, $started, ['root' => $args['root'], 'outcome' => 'refused: '.$code, 'message' => $e instanceof MachineException ? $e->getMessage() : 'internal error'], ['args' => self::publicArgs($args), 'error' => $e instanceof MachineException ? ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details] : ['code' => 'internal']]);
                } catch (Throwable) {
                    // the refusal itself is what the caller needs to see
                }
            }

            throw $e;
        }
        $body    = $response->getData(true);
        $data    = (array) ($body['data'] ?? []);
        if (true === ($data['dry_run'] ?? null) && is_string($data['confirm_token'] ?? null)) {
            $this->rememberArgs((string) $data['confirm_token'], $applyKey, $args);

            return $response;
        }
        if ($last instanceof WriteResult) {
            $id = RunLog::newId();
            RunLog::record($staging, $id, $kind, $started, self::runLine($args, $last, 'applied') + ['operation_id' => $data['operation_id'] ?? null], ['args' => self::publicArgs($args), 'accounts' => $last->data['accounts'] ?? $last->data['plan'] ?? [], 'rows' => $last->data['rows'] ?? [], 'changes' => $last->changes, 'operation_id' => $data['operation_id'] ?? null]);
            $body['data']['run_id'] = $id;
            $response->setData($body);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array{confirm_token: string, expires_at: string}
     */
    private function mintFor(Request $request, string $applyKey, array $args, WriteResult $result): array
    {
        $token = $this->planToken($request, $applyKey, $args, $result->fingerprint(), ['change_count' => $result->changeCount()]);
        $this->rememberArgs($token['confirm_token'], $applyKey, $args);

        return $token;
    }

    /** @param array<string, mixed> $args */
    private function rememberArgs(string $token, string $applyKey, array $args): void
    {
        Cache::put(self::ARGS_CACHE.hash('sha256', $token), ['route' => $applyKey, 'args' => $args], (int) config('machine.confirm_ttl', 600));
    }

    /**
     * The plan arguments a confirm token was minted for — the apply route takes only the token.
     *
     * @return array<string, mixed>
     */
    private function argsFor(Request $request, string $applyKey, string $planRoute): array
    {
        $body  = $request->isJson() ? $request->json()->all() : $request->request->all();
        $token = $body['confirm_token'] ?? $request->query('confirm_token');
        if (!is_string($token) || '' === $token) {
            throw MachineException::invalid(
                'This apply route needs the confirm_token its plan returned.',
                sprintf('Call %s first, read the plan, then send {"confirm_token": "cf_…", "dry_run": false}', $planRoute),
                ['field' => 'confirm_token'],
            );
        }
        $entry = Cache::get(self::ARGS_CACHE.hash('sha256', $token));
        if (is_array($entry) && $entry['route'] === $applyKey) {
            return (array) $entry['args'];
        }
        if (1 !== preg_match('/^cf_[0-9a-f]{32}$/', $token)) {
            throw MachineException::forbidden('confirm_token is not a token this server minted.', sprintf('Run %s and echo back the confirm_token it returns', $planRoute));
        }
        $peek  = ConfirmTokens::peek($token);
        $other = is_array($entry) ? (string) $entry['route'] : ($peek['route'] ?? null);
        if (null !== $other && $other !== $applyKey) {
            throw MachineException::forbidden(sprintf('That confirm token was minted for %s, not %s.', $other, $applyKey), 'Use the token with the route whose plan returned it', ['token_route' => $other]);
        }

        throw MachineException::conflict(
            'The confirm token has expired, was already used, or was minted under a key that has since been rotated.',
            sprintf('Re-plan: call %s again and use the new confirm_token (tokens last 10 minutes and work once)', $planRoute),
        );
    }

    private function isDryRun(Request $request): bool
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        $v    = $body['dry_run'] ?? true;

        return !in_array($v, [false, 'false', 0, '0', 'no', 'off'], true);
    }

    /**
     * @param array<string, mixed> $args root/entity/institution/account
     *
     * @return array{0: string, 1: array<string, mixed>, 2: list<array<string, mixed>>}
     */
    private function scope(array $args): array
    {
        $root     = StatementsRoot::resolve($args['root'] ?? null);
        $manifest = Manifest::load($root, null, (string) $this->primaryCurrency()->code);
        $accounts = Manifest::select($manifest['accounts'], isset($args['account']) && '' !== (string) $args['account'] ? [(string) $args['account']] : [], 'account');
        foreach (['entity', 'institution'] as $f) {
            if (isset($args[$f]) && '' !== (string) $args[$f]) {
                $want     = mb_strtolower((string) $args[$f]);
                $accounts = array_values(array_filter($accounts, static fn (array $a): bool => mb_strtolower((string) $a[$f]) === $want));
            }
        }

        return [$root, $manifest, $accounts];
    }

    private function ledger(): LedgerAccounts
    {
        return new LedgerAccounts($this->operator(), $this->administration(), $this->primaryCurrency());
    }

    /** @return list<string> */
    private function runRoots(?string $root): array
    {
        if (null !== $root && '' !== $root) {
            return [StatementsRoot::resolve($root)];
        }
        $out = [];
        foreach (StatementsRoot::configured() as $c) {
            $real = realpath($c['root']);
            if (false !== $real && is_dir($real)) {
                $out[] = $real;
            }
        }
        if ([] === $out) {
            StatementsRoot::resolve(null); // throws the not_ready naming the setting
        }

        return array_values(array_unique($out));
    }

    /**
     * Write the raw-mode staging tree (extract): _manifest.csv, _rows.csv, _dupes.csv,
     * _conflicts.csv and one CSV per account-month.
     *
     * @param array<string, array<string, mixed>> $accounts
     */
    private function writeStaging(Staging $staging, array $accounts): void
    {
        $files = [];
        $rows  = [];
        $dupes = [];
        foreach ($accounts as $c) {
            $a = $c['account'];
            foreach ($c['statements'] as $s) {
                $files[] = ['file' => $s->relative, 'source' => $s->source(), 'account' => $a['key'], 'format' => $s->format, 'source_kind' => $s->sourceKind, 'sha256' => $s->sha256,
                    'period_start' => $s->periodStart ?? '', 'period_end' => $s->periodEnd ?? '', 'period_from_rows' => $s->periodFromRows, 'rows' => count($s->rows), 'bad_rows' => count($s->bad),
                    'status' => $s->status, 'rule' => $s->rule ?? '', 'superseded_by' => $s->supersededBy ?? ''];
            }
            $byMonth = [];
            foreach ($c['rows'] as $r) {
                $rows[]                  = $r;
                $byMonth[$r['month']][] = $r;
            }
            foreach ($byMonth as $month => $list) {
                $staging->writeCsv(sprintf('%s/%s/%s/%s.csv', Staging::segment((string) $a['entity']), Staging::segment((string) $a['institution']), Staging::segment((string) $a['label']), $month), self::ROW_HEADER, $list);
            }
            foreach ($c['groups'] as $g) {
                if ('single' === $g['verdict']) {
                    continue;
                }
                foreach ($g['members'] as $m) {
                    $dupes[] = ['layer' => 'statement', 'account' => $g['account'], 'period' => $g['period'], 'verdict' => $m['status'], 'rule' => $m['rule'] ?? '', 'file' => $m['file'], 'superseded_by' => $m['superseded_by'] ?? '', 'external_id' => '', 'ordinal' => '', 'tie_group' => ''];
                }
            }
            foreach ($c['dupes'] as $d) {
                $dupes[] = $d;
            }
        }
        $staging->writeCsv('_manifest.csv', ['file', 'source', 'account', 'format', 'source_kind', 'sha256', 'period_start', 'period_end', 'period_from_rows', 'rows', 'bad_rows', 'status', 'rule', 'superseded_by'], $files);
        $staging->writeCsv('_rows.csv', self::ROW_HEADER, $rows);
        $staging->writeCsv('_dupes.csv', ['layer', 'account', 'period', 'verdict', 'rule', 'file', 'superseded_by', 'external_id', 'ordinal', 'tie_group'], $dupes);
        Preferences::write($staging, $accounts);
    }

    private const array ROW_HEADER = ['account', 'date', 'type', 'amount', 'description', 'internal_reference', 'external_id', 'ordinal', 'tie_group', 'source_file', 'source_kind', 'source_line', 'status'];

    /**
     * @param array<string, mixed>        $c    a Collector account
     * @param list<array<string, mixed>>  $rows its rows inside the date range
     * @param null|array<string, mixed>   $target
     *
     * @return array<string, mixed>
     */
    private static function accountSummary(array $c, array $rows, ?array $target): array
    {
        $a        = $c['account'];
        $count    = static fn (string $status): int => count(array_filter($c['statements'], static fn (ParsedStatement $s): bool => $status === $s->status));
        $extracted = 0;
        foreach ($c['statements'] as $s) {
            if (ParsedStatement::PRIMARY === $s->status) {
                $extracted += count($s->rows);
            }
        }
        $dates    = array_column($rows, 'date');

        return [
            'key'                    => $a['key'],
            'entity'                 => $a['entity'],
            'institution'            => $a['institution'],
            'label'                  => $a['label'],
            'last4'                  => $a['last4'],
            'account_id'             => $target['id'] ?? null,
            'target'                 => null === $target ? null : sprintf('%s (#%d)', $target['name'], $target['id']),
            'currency_code'          => $target['currency_code'] ?? null,
            'statements_primary'     => $count(ParsedStatement::PRIMARY),
            'superseded'             => $count(ParsedStatement::SUPERSEDED) + $count(ParsedStatement::DUPLICATE_IDENTICAL),
            'conflicts'              => count($c['conflicts']),
            'unreadable'             => $count(ParsedStatement::UNREADABLE) + $count(ParsedStatement::MISFILED),
            'rows_extracted'         => $extracted,
            'rows_after_dedupe'      => count($c['rows']),
            'rows_in_range'          => count($rows),
            'blocked_rows'           => count(array_filter($rows, static fn (array $r): bool => 'blocked' === $r['status'])),
            'bad_rows'               => count($c['bad']),
            'blocked'                => count($c['conflicts']) > 0,
            'blocked_reason'         => count($c['conflicts']) > 0 ? 'statement conflicts block their account-months — GET /ingest/dupes, then POST /ingest/prefer' : null,
            'date_range'             => [] === $dates ? null : ['start' => min($dates), 'end' => max($dates)],
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return Closure(WriteResult): array<string, mixed>
     */
    private static function importRender(array $args): Closure
    {
        return static fn (WriteResult $r): array => $r->data;
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function runLine(array $args, WriteResult $r, string $outcome): array
    {
        return [
            'root'               => $args['root'],
            'created'            => $r->changes['created'] ?? 0,
            'linked'             => $r->changes['linked'] ?? null,
            'duplicates'         => $r->changes['duplicates'] ?? 0,
            'previously_deleted' => $r->changes['previously_deleted'] ?? 0,
            'errors'             => $r->changes['errors'] ?? 0,
            'blocked'            => $r->changes['blocked'] ?? 0,
            'outcome'            => $outcome,
        ];
    }

    /**
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    private static function publicArgs(array $args): array
    {
        return $args;
    }

    /**
     * @param list<array<string, mixed>> $plan
     *
     * @return array<string, int>
     */
    private static function planSummary(array $plan): array
    {
        $s = ['create' => 0, 'link' => 0, 'skip' => 0, 'ambiguous' => 0];
        foreach ($plan as $p) {
            ++$s[$p['action']];
        }

        return $s;
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function publicAccount(array $a): array
    {
        unset($a['abs_path']);

        return $a;
    }

    /**
     * @param array<string, mixed> $a
     *
     * @return array<string, mixed>
     */
    private static function files(array $a, string $mode): array
    {
        $dir = $a['abs_path'] ?? null;
        if (!is_string($dir) || !is_dir($dir)) {
            return ['combined' => null, 'monthly' => 0, 'formats' => []];
        }
        $combined = null;
        $monthly  = 0;
        $formats  = [];
        foreach (Collector::walk($dir) as $f) {
            $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
            if ('raw' === $mode) {
                $kind = StatementReader::sidecarKind($f) ?? ('pdf' === $ext ? 'pdf' : (in_array($ext, StatementReader::IMPORTABLE, true) ? $ext : null));
                if (null !== $kind) {
                    $formats[$kind] = true;
                    $monthly += 'pdf' === $kind ? 1 : 0;
                }

                continue;
            }
            if (!in_array($ext, StatementReader::IMPORTABLE, true)) {
                continue;
            }
            $formats['xml' === $ext ? 'camt' : $ext] = true;
            if (1 === preg_match('/(?:^|[_\-. ])(?:ALL|COMBINED|FULL)(?:[_\-. ]|$)/i', pathinfo($f, PATHINFO_FILENAME))) {
                $combined ??= basename($f);
            } else {
                ++$monthly;
            }
        }
        $formats  = array_keys($formats);
        sort($formats);

        return ['combined' => $combined, 'monthly' => $monthly, 'formats' => $formats];
    }

    /**
     * The PUT /ingest/map entries as [selector, account_id|null].
     *
     * @param array<int|string, mixed> $map
     *
     * @return array<int|string, array{0: string, 1: null|int}>
     */
    private static function mapItems(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            if (is_string($k)) {
                $out[$k] = [$k, self::accountIdOf($v, (string) $k)];

                continue;
            }
            if (!is_array($v)) {
                throw MachineException::invalid(sprintf('map.%d must be an object.', $k), 'Send {"key": "entity/institution/label", "account_id": 14} (or a label / last4 instead of key)', ['field' => sprintf('map.%d', $k)]);
            }
            $selector = $v['key'] ?? null;
            if (null === $selector && isset($v['entity'], $v['institution'], $v['label'])) {
                $selector = sprintf('%s/%s/%s', (string) $v['entity'], (string) $v['institution'], (string) $v['label']);
            }
            $selector ??= $v['label'] ?? $v['last4'] ?? null;
            if (!is_string($selector) || '' === $selector) {
                throw MachineException::invalid(sprintf('map.%d names no manifest account.', $k), 'Give key (entity/institution/label), label, or last4', ['field' => sprintf('map.%d', $k)]);
            }
            if (!array_key_exists('account_id', $v)) {
                throw MachineException::invalid(sprintf('map.%d has no account_id.', $k), 'Give account_id (null removes the entry)', ['field' => sprintf('map.%d.account_id', $k)]);
            }
            $out[$k] = [$selector, self::accountIdOf($v['account_id'], (string) $k)];
        }

        return $out;
    }

    private static function accountIdOf(mixed $v, string $field): ?int
    {
        if (null === $v) {
            return null;
        }
        if (is_int($v) && $v > 0) {
            return $v;
        }
        if (is_string($v) && 1 === preg_match('/^[1-9]\d{0,18}$/', $v)) {
            return (int) $v;
        }

        throw MachineException::invalid(sprintf('map.%s: account_id must be a positive id or null.', $field), 'Use GET /machine/v1/accounts to find the id', ['field' => 'map.'.$field]);
    }
}
