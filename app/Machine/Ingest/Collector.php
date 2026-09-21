<?php

/*
 * Collector.php
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

/**
 * The pipeline's read half — discover, read, de-duplicate (both layers), canonical rows — for
 * one statements tree (cli.mdx §10.2–§10.6). Deterministic: the same tree and the same staged
 * preferences always give the same rows and the same external ids, so a plan and its apply see
 * the same world. It writes nothing; /ingest/extract persists what it returns.
 *
 * Discovery:
 *   prepared  the manifest's account directories; importable files (.ofx .qfx camt .xml .csv).
 *             One source set per account: OFX/QFX over camt over CSV (the bank's FITID is the
 *             best key there is), and a combined "…_ALL" file over the monthly files of its
 *             format — a lesser file is still read when it is the only source of some month;
 *             the rest are `not_chosen`, reported, never parsed into rows twice.
 *   raw       {ENTITY}/{BANK}/{ACCOUNT}/{YYYY}/{MM}/: each PDF with its _claude/_brew/_ocr
 *             sidecars is one statement; an importable file dropped there is one too.
 */
final class Collector
{
    private const array FORMAT_RANK = ['ofx' => 0, 'qfx' => 0, 'xml' => 1, 'csv' => 2];

    /**
     * @param array<string, mixed>                                                                  $manifest Manifest::load()
     * @param list<array<string, mixed>>                                                            $accounts the manifest accounts to collect
     * @param array{mode?: null|string, year?: null|int, extract_pdf?: bool, force?: bool}          $opts
     *
     * @return array{mode: string, accounts: array<string, array<string, mixed>>}
     */
    public static function collect(string $root, array $manifest, array $accounts, array $opts = []): array
    {
        $mode        = (string) ($opts['mode'] ?? $manifest['mode']);
        $staging     = new Staging($root);
        $preferences = Preferences::load($staging);
        $out         = [];
        $phase       = true === ($opts['extract_pdf'] ?? false) ? 'extracting' : 'reading';
        $n           = count($accounts);
        $i           = 0;
        Progress::tick($phase, 0, $n);
        foreach ($accounts as $account) {
            $out[$account['key']] = self::account($root, $mode, $account, $staging, $preferences, $opts);
            Progress::tick($phase, ++$i, $n);
        }

        return ['mode' => $mode, 'accounts' => $out];
    }

    /**
     * @param array<string, mixed>  $account
     * @param array<string, string> $preferences
     * @param array<string, mixed>  $opts
     *
     * @return array<string, mixed>
     */
    public static function account(string $root, string $mode, array $account, ?Staging $staging, array $preferences, array $opts = []): array
    {
        $dir        = $account['abs_path'] ?? null;
        $statements = [];
        $notChosen  = [];
        if (is_string($dir) && is_dir($dir)) {
            [$statements, $notChosen] = 'raw' === $mode
                ? [self::rawStatements($root, $dir, $account, $staging, (bool) ($opts['extract_pdf'] ?? false), (bool) ($opts['force'] ?? false)), []]
                : self::preparedStatements($root, $dir, $account);
        }
        $year       = $opts['year'] ?? null;
        if (null !== $year) {
            $statements = array_values(array_filter($statements, static function (ParsedStatement $s) use ($year): bool {
                foreach ($s->months() as $m) {
                    if ((int) substr($m, 0, 4) === (int) $year) {
                        return true;
                    }
                }

                return [] === $s->months() && str_contains($s->relative, '/'.$year.'/');
            }));
        }
        // misfiled: the document names another account
        foreach ($statements as $s) {
            if (ParsedStatement::PRIMARY === $s->status && null !== $s->last4 && null !== ($account['last4'] ?? null) && $s->last4 !== $account['last4']) {
                $s->status = ParsedStatement::MISFILED;
                $s->rule   = sprintf('the document is for account ending %s, not %s', $s->last4, (string) $account['last4']);
            }
        }
        $layerOne   = StatementDedupe::run($statements, $account, $preferences);
        $layerTwo   = RowBuilder::build($account, $statements, $layerOne['blocked_months']);

        return [
            'account'        => $account,
            'statements'     => $statements,
            'not_chosen'     => $notChosen,
            'groups'         => $layerOne['groups'],
            'conflicts'      => $layerOne['conflicts'],
            'blocked_months' => $layerOne['blocked_months'],
            'rows'           => $layerTwo['rows'],
            'dupes'          => $layerTwo['dupes'],
            'bad'            => $layerTwo['bad'],
            'coverage'       => self::coverage($account, $statements),
        ];
    }

    /**
     * The months each account covers and misses. Present: every month a readable statement's
     * period (or rows) touches. Expected: the manifest's first..last when given, else the span
     * of what is present.
     *
     * @param array<string, mixed>  $account
     * @param list<ParsedStatement> $statements
     *
     * @return array{first: null|string, last: null|string, present: list<string>, missing: list<string>}
     */
    public static function coverage(array $account, array $statements): array
    {
        $present = [];
        foreach ($statements as $s) {
            if (in_array($s->status, [ParsedStatement::UNREADABLE, ParsedStatement::MISFILED, ParsedStatement::NOT_CHOSEN], true)) {
                continue;
            }
            foreach ($s->months() as $m) {
                $present[$m] = true;
            }
        }
        $present = array_keys($present);
        sort($present);
        $first   = self::month($account['first'] ?? null) ?? ($present[0] ?? null);
        $last    = self::month($account['last'] ?? null) ?? ($present[count($present) - 1] ?? null);
        $missing = [];
        if (null !== $first && null !== $last) {
            $have = array_flip($present);
            foreach (Values::months($first, $last) as $m) {
                if (!isset($have[$m])) {
                    $missing[] = $m;
                }
            }
        }

        return ['first' => $first, 'last' => $last, 'present' => $present, 'missing' => $missing];
    }

    /**
     * A prepared account's source set. Files are ranked by format — OFX/QFX (the bank's FITID is
     * the best key there is) over camt over CSV — and a combined "…_ALL" file over the monthly
     * files of its format. A file of a lesser format, or a monthly file beside a combined one, is
     * still read when it is the ONLY source of some month: a CSV-only August beside OFX
     * September–October is never dropped. Everything else is `not_chosen` with its rule, so no
     * month is ever parsed into rows twice.
     *
     * @param array<string, mixed> $account
     *
     * @return array{0: list<ParsedStatement>, 1: list<array<string, string>>}
     */
    private static function preparedStatements(string $root, string $dir, array $account): array
    {
        $candidates = [];
        foreach (self::walk($dir) as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, StatementReader::IMPORTABLE, true) || ('xml' === $ext && !self::isCamt($path))) {
                continue;
            }
            $candidates[] = [
                'path'     => $path,
                'rank'     => self::FORMAT_RANK[$ext],
                'combined' => 1 === preg_match('/(?:^|[_\-. ])(?:ALL|COMBINED|FULL)(?:[_\-. ]|$)/i', pathinfo($path, PATHINFO_FILENAME)),
            ];
        }
        if ([] === $candidates) {
            return [[], []];
        }
        // best format first; within a format the combined file(s) first; then the path
        usort($candidates, static fn (array $a, array $b): int => [$a['rank'], $a['combined'] ? 0 : 1, $a['path']] <=> [$b['rank'], $b['combined'] ? 0 : 1, $b['path']]);
        $bestRank    = $candidates[0]['rank'];
        $hasCombined = [];
        foreach ($candidates as $c) {
            if ($c['combined']) {
                $hasCombined[$c['rank']] = true;
            }
        }
        $covered = [];
        $out     = [];
        $skipped = [];
        foreach ($candidates as $c) {
            $statement = StatementReader::readFile($root, $c['path'], $account);
            if (null === $statement) {
                continue;
            }
            $preferred = $c['rank'] === $bestRank && ($c['combined'] || !isset($hasCombined[$c['rank']]));
            $months    = $statement->months();
            $uncovered = array_values(array_filter($months, static fn (string $m): bool => !isset($covered[$m])));
            if ($preferred || [] !== $uncovered) {
                foreach ($months as $m) {
                    $covered[$m] ??= $c['rank'];
                }
                if (!$preferred) {
                    $statement->warnings[] = sprintf('read although a better source exists for this account: it is the only source of %s', implode(', ', $uncovered));
                }
                $out[] = $statement;

                continue;
            }
            // every month it covers is already covered: by a combined file of its own format, or by a better format
            $sameFormat = [] === $months || count(array_filter($months, static fn (string $m): bool => $covered[$m] === $c['rank'])) === count($months);
            $skipped[]  = [
                'file' => StatementsRoot::relative($root, $c['path']),
                'rule' => $sameFormat ? 'combined_file_preferred' : 'better_format_present',
            ];
        }

        return [$out, $skipped];
    }

    /**
     * @param array<string, mixed> $account
     *
     * @return list<ParsedStatement>
     */
    private static function rawStatements(string $root, string $dir, array $account, ?Staging $staging, bool $extractPdf, bool $force): array
    {
        $units = [];
        foreach (self::walk($dir) as $path) {
            $base = basename($path);
            $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $key  = dirname($path).'/';
            if ('pdf' === $ext) {
                $units[$key.pathinfo($base, PATHINFO_FILENAME)]['pdf'] = $path;

                continue;
            }
            $kind = StatementReader::sidecarKind($path);
            if (null !== $kind) {
                $stem = (string) preg_replace('/_(claude|brew|ocr)\.txt$/i', '', $base);
                $units[$key.$stem]['sidecars'][$kind] = $path;

                continue;
            }
            if (in_array($ext, StatementReader::IMPORTABLE, true)) {
                $units[$key.$base]['file'] = $path;
            }
        }
        ksort($units, SORT_STRING);
        $out   = [];
        foreach ($units as $unit) {
            if (isset($unit['file'])) {
                $s = StatementReader::readFile($root, $unit['file'], $account);
                if (null !== $s) {
                    $out[] = $s;
                }

                continue;
            }
            $out[] = StatementReader::readUnit($root, $unit['pdf'] ?? null, $unit['sidecars'] ?? [], $staging, $extractPdf, $force, $account);
        }

        return $out;
    }

    /** @return list<string> every regular file under $dir (hidden entries and symlinks skipped), sorted */
    public static function walk(string $dir, int $depth = 0): array
    {
        if ($depth > 8) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $e) {
            if ('.' === $e[0]) {
                continue;
            }
            $p = $dir.'/'.$e;
            if (is_link($p)) {
                continue;
            }
            if (is_dir($p)) {
                foreach (self::walk($p, $depth + 1) as $f) {
                    $out[] = $f;
                }

                continue;
            }
            if (is_file($p)) {
                $out[] = $p;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }

    private static function isCamt(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if (false === $fh) {
            return false;
        }
        $head = (string) fread($fh, 4096);
        fclose($fh);

        return str_contains($head, 'camt.053') || str_contains($head, 'BkToCstmrStmt');
    }

    private static function month(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        return 1 === preg_match('/^(\d{4})-(\d{2})/', trim($value), $m) ? $m[1].'-'.$m[2] : null;
    }
}
