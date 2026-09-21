<?php

/*
 * Manifest.php
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

use FireflyIII\Machine\MachineException;
use JsonException;

/**
 * The manifest — apis.mdx §11.2. A prepared tree describes itself with a CSV (or JSON) holding
 * one row per account; the route reports every column it could not find rather than guessing,
 * and a tree without one is a RAW tree whose accounts are read off its
 * {ENTITY}/{BANK}/{ACCOUNT}/ directories (cli.mdx §10.1) — marked `synthesized`.
 *
 * The manifest is the operator's and is never written, moved or rewritten (§11.1).
 *
 * An account entry (every value a string or null; counts are ints):
 *   key            "{entity}/{institution}/{label}" — the map's key, stable across runs
 *   entity institution label last4 kind currency path
 *   currency_defaulted  true when the manifest gave none (the primary currency is used, and said)
 *   statements transactions first last reconciled recon_na   (optional coverage columns)
 *   name firefly_type opening_balance opening_balance_date payment_date   (optional per-row overrides, §12)
 *   warnings       list<string>
 */
final class Manifest
{
    public const array REQUIRED   = ['entity', 'institution', 'label', 'last4', 'kind', 'path'];
    public const array OPTIONAL   = ['currency', 'transactions', 'statements', 'first', 'last', 'reconciled', 'recon_na', 'name', 'firefly_type', 'opening_balance', 'opening_balance_date', 'payment_date'];
    public const array KINDS      = ['checking', 'savings', 'card', 'brokerage', 'loan', 'mortgage'];
    public const array CANDIDATES = [
        'import/accounts.csv', 'import/manifest.csv', 'import/accounts.json', 'import/manifest.json',
        'accounts.csv', 'manifest.csv', 'accounts.json', 'manifest.json',
    ];

    /** Column aliases seen in real manifests → our column. */
    private const array ALIASES   = [
        'bank' => 'institution', 'account' => 'label', 'account_label' => 'label', 'last_4' => 'last4', 'last four' => 'last4',
        'type' => 'kind', 'account_type' => 'kind', 'dir' => 'path', 'directory' => 'path', 'ccy' => 'currency',
        'txns' => 'transactions', 'transaction_count' => 'transactions', 'statement_count' => 'statements',
        'first_month' => 'first', 'last_month' => 'last', 'firefly_name' => 'name', 'opening_date' => 'opening_balance_date',
    ];

    /**
     * @return array{root: string, manifest_path: null|string, mode: string, synthesized: bool, accounts: list<array<string, mixed>>, missing_columns: list<string>, unknown_columns: list<string>, warnings: list<string>, totals: array<string, int>}
     */
    public static function load(string $root, ?string $manifestPath, string $primaryCurrency): array
    {
        $path = null;
        if (null !== $manifestPath && '' !== trim($manifestPath)) {
            $path = StatementsRoot::contain($root, $manifestPath);
        } else {
            foreach (self::CANDIDATES as $candidate) {
                if (is_file($root.'/'.$candidate) && !is_link($root.'/'.$candidate)) {
                    $path = $root.'/'.$candidate;

                    break;
                }
            }
        }
        if (null === $path) {
            return self::synthesize($root, $primaryCurrency);
        }

        $text     = @file_get_contents($path);
        if (false === $text) {
            throw MachineException::notFound('The manifest could not be read.', 'Check the file\'s permissions', ['manifest_path' => StatementsRoot::relative($root, $path)]);
        }
        $records  = str_ends_with(strtolower($path), '.json') ? self::fromJson($text, $root, $path) : Csv::decodeAssoc($text);
        $columns  = [];
        $rows     = [];
        foreach ($records as $record) {
            $row = [];
            foreach ($record as $k => $v) {
                $name       = self::column((string) $k);
                $columns[$name] = true;
                $row[$name] = is_scalar($v) ? trim((string) $v) : null;
            }
            $rows[] = $row;
        }
        $missing  = array_values(array_diff(self::REQUIRED, array_keys($columns)));
        $unknown  = array_values(array_diff(array_keys($columns), [...self::REQUIRED, ...self::OPTIONAL]));
        $warnings = [];
        if ([] !== $missing) {
            $warnings[] = sprintf('the manifest has no %s column%s — %s', implode(', ', $missing), 1 === count($missing) ? '' : 's', 'those values are reported missing per row, never guessed');
        }
        $accounts = [];
        $keys     = [];
        foreach ($rows as $i => $row) {
            $entry = self::entry($root, $row, $primaryCurrency, $i + 2);
            if (isset($keys[$entry['key']])) {
                $entry['warnings'][] = sprintf('duplicate manifest row for %s (row %d repeats row %d) — ignored', $entry['key'], $i + 2, $keys[$entry['key']]);
                $entry['ignored']    = true;
            }
            $keys[$entry['key']] ??= $i + 2;
            $accounts[] = $entry;
        }

        return [
            'root'            => $root,
            'manifest_path'   => StatementsRoot::relative($root, $path),
            'mode'            => 'prepared',
            'synthesized'     => false,
            'accounts'        => $accounts,
            'missing_columns' => $missing,
            'unknown_columns' => $unknown,
            'warnings'        => $warnings,
            'totals'          => self::totals($accounts),
        ];
    }

    /** "Checking_x4021" → "4021"; null when the label carries no last-4. */
    public static function last4FromLabel(string $label): ?string
    {
        if (1 === preg_match('/(?:^|[^0-9])(?:x|••|\*+|-|_|#|\s)?(\d{4})$/i', trim($label), $m)) {
            return $m[1];
        }

        return null;
    }

    /** A kind from a label, for a raw tree ("Visa_x7734" → card). */
    public static function kindFromLabel(string $label): string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'mortgage')                                                                  => 'mortgage',
            str_contains($l, 'loan') || str_contains($l, 'heloc')                                         => 'loan',
            1 === preg_match('/card|visa|mastercard|amex|credit/', $l)                                    => 'card',
            1 === preg_match('/brokerage|invest|ira\b|401k|stock|trading/', $l)                           => 'brokerage',
            1 === preg_match('/saving|money\s*market|mm\b|cd\b/', $l)                                     => 'savings',
            default                                                                                        => 'checking',
        };
    }

    /**
     * @param list<array<string, mixed>> $accounts
     *
     * @return array<string, int>
     */
    public static function totals(array $accounts): array
    {
        $t = ['accounts' => 0, 'statements' => 0, 'transactions' => 0, 'unreconciled' => 0];
        foreach ($accounts as $a) {
            if (true === ($a['ignored'] ?? false)) {
                continue;
            }
            ++$t['accounts'];
            $t['statements']   += (int) ($a['statements'] ?? 0);
            $t['transactions'] += (int) ($a['transactions'] ?? 0);
            if (null !== ($a['statements'] ?? null) && null !== ($a['reconciled'] ?? null)) {
                $t['unreconciled'] += max(0, (int) $a['statements'] - (int) $a['reconciled'] - (int) ($a['recon_na'] ?? 0));
            }
        }

        return $t;
    }

    /**
     * Select manifest accounts by key, label, last-4 or "entity/institution/label"; unknown
     * selectors are refused with the candidates.
     *
     * @param list<array<string, mixed>> $accounts
     * @param list<string>               $selectors
     *
     * @return list<array<string, mixed>>
     */
    public static function select(array $accounts, array $selectors, string $field = 'accounts'): array
    {
        $usable = array_values(array_filter($accounts, static fn (array $a): bool => true !== ($a['ignored'] ?? false)));
        if ([] === $selectors) {
            return $usable;
        }
        $out    = [];
        foreach ($selectors as $sel) {
            $s    = mb_strtolower(trim($sel));
            $hits = array_values(array_filter($usable, static fn (array $a): bool => in_array($s, array_map('mb_strtolower', array_filter([(string) $a['key'], (string) $a['label'], (string) ($a['last4'] ?? '')], static fn (string $v): bool => '' !== $v)), true)));
            if ([] === $hits) {
                throw MachineException::invalid(
                    sprintf('No manifest account matches "%s".', $sel),
                    'Pass an account label, its last-4, or entity/institution/label (GET /machine/v1/ingest/manifest lists them)',
                    ['field' => $field, 'value' => $sel, 'candidates' => array_slice(array_column($usable, 'key'), 0, 50)],
                );
            }
            if (count($hits) > 1 && $s !== mb_strtolower((string) $hits[0]['key'])) {
                throw MachineException::invalid(
                    sprintf('"%s" matches %d manifest accounts.', $sel, count($hits)),
                    'Pass entity/institution/label to name exactly one',
                    ['field' => $field, 'value' => $sel, 'candidates' => array_column($hits, 'key')],
                );
            }
            foreach ($hits as $h) {
                $out[$h['key']] = $h;
            }
        }

        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    private static function fromJson(string $text, string $root, string $path): array
    {
        try {
            $doc = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MachineException::invalid('The manifest is not valid JSON.', 'Fix the manifest by hand — this plane never rewrites it', ['manifest_path' => StatementsRoot::relative($root, $path)]);
        }
        if (is_array($doc) && isset($doc['accounts']) && is_array($doc['accounts'])) {
            $doc = $doc['accounts'];
        }
        if (!is_array($doc) || !array_is_list($doc)) {
            throw MachineException::invalid('The JSON manifest must be a list of account objects (or {"accounts": [...]}).', 'Fix the manifest by hand', ['manifest_path' => StatementsRoot::relative($root, $path)]);
        }

        return array_values(array_filter($doc, 'is_array'));
    }

    private static function column(string $name): string
    {
        $n = strtolower(trim(str_replace(['-', ' '], ['_', '_'], $name)));
        $n = str_replace('__', '_', $n);
        $a = self::ALIASES[$n] ?? self::ALIASES[str_replace('_', ' ', $n)] ?? null;

        return $a ?? $n;
    }

    /**
     * @param array<string, null|string> $row
     *
     * @return array<string, mixed>
     */
    private static function entry(string $root, array $row, string $primaryCurrency, int $line): array
    {
        $v        = static fn (string $k): ?string => isset($row[$k]) && '' !== (string) $row[$k] ? (string) $row[$k] : null;
        $warnings = [];
        foreach (self::REQUIRED as $col) {
            if (null === $v($col) && !('last4' === $col && in_array(strtolower((string) $v('kind')), ['loan', 'mortgage'], true))) {
                $warnings[] = sprintf('row %d has no %s', $line, $col);
            }
        }
        $kind     = null === $v('kind') ? null : strtolower((string) $v('kind'));
        if (null !== $kind && !in_array($kind, self::KINDS, true)) {
            $warnings[] = sprintf('row %d: unknown kind "%s" (one of %s)', $line, $kind, implode(', ', self::KINDS));
            $kind       = null;
        }
        $last4    = $v('last4');
        if (null !== $last4) {
            $digits = (string) preg_replace('/\D/', '', $last4);
            $last4  = strlen($digits) >= 4 ? substr($digits, -4) : null;
            if (null === $last4) {
                $warnings[] = sprintf('row %d: last4 "%s" is not four digits', $line, (string) $row['last4']);
            }
        }
        $currency = null === $v('currency') ? null : strtoupper((string) $v('currency'));
        $path     = $v('path');
        $abs      = null;
        if (null !== $path) {
            try {
                $abs = StatementsRoot::contain($root, $path, false);
                if (!is_dir($abs)) {
                    $warnings[] = sprintf('row %d: the account directory %s does not exist', $line, $path);
                }
            } catch (MachineException) {
                $warnings[] = sprintf('row %d: path %s is outside the statements root — refused', $line, $path);
                $abs        = null;
            }
        }
        $entity   = $v('entity') ?? '';
        $inst     = $v('institution') ?? '';
        $label    = $v('label') ?? (null === $path ? '' : basename($path));
        $int      = static fn (?string $x): ?int => null !== $x && 1 === preg_match('/^\d+$/', $x) ? (int) $x : null;

        return [
            'key'                  => sprintf('%s/%s/%s', $entity, $inst, $label),
            'entity'               => $entity,
            'institution'          => $inst,
            'label'                => $label,
            'last4'                => $last4,
            'kind'                 => $kind,
            'currency'             => $currency ?? $primaryCurrency,
            'currency_defaulted'   => null === $currency,
            'path'                 => $path,
            'abs_path'             => $abs,
            'statements'           => $int($v('statements')),
            'transactions'         => $int($v('transactions')),
            'first'                => $v('first'),
            'last'                 => $v('last'),
            'reconciled'           => $int($v('reconciled')),
            'recon_na'             => $int($v('recon_na')),
            'name'                 => $v('name'),
            'firefly_type'         => null === $v('firefly_type') ? null : strtolower((string) $v('firefly_type')),
            'opening_balance'      => $v('opening_balance'),
            'opening_balance_date' => $v('opening_balance_date'),
            'payment_date'         => $v('payment_date'),
            'warnings'             => array_values(array_merge($warnings, self::reconWarning($int($v('statements')), $int($v('reconciled')), $int($v('recon_na'))))),
        ];
    }

    /** @return list<string> */
    private static function reconWarning(?int $statements, ?int $reconciled, ?int $na): array
    {
        if (null === $statements || null === $reconciled) {
            return [];
        }
        $open = $statements - $reconciled - (int) $na;

        return $open > 0 ? [sprintf('%d statement%s did not reconcile in the archive', $open, 1 === $open ? '' : 's')] : [];
    }

    /**
     * A raw tree: accounts are the {ENTITY}/{BANK}/{ACCOUNT}/ directories (hidden directories and
     * the archive's own top-level import/ are skipped).
     *
     * @return array{root: string, manifest_path: null, mode: string, synthesized: bool, accounts: list<array<string, mixed>>, missing_columns: list<string>, unknown_columns: list<string>, warnings: list<string>, totals: array<string, int>}
     */
    private static function synthesize(string $root, string $primaryCurrency): array
    {
        $accounts = [];
        foreach (self::dirs($root) as $entity) {
            if ('import' === $entity) {
                continue;
            }
            foreach (self::dirs($root.'/'.$entity) as $bank) {
                foreach (self::dirs($root.'/'.$entity.'/'.$bank) as $label) {
                    $abs        = $root.'/'.$entity.'/'.$bank.'/'.$label;
                    $last4      = self::last4FromLabel($label);
                    $kind       = self::kindFromLabel($label);
                    $accounts[] = [
                        'key'                  => sprintf('%s/%s/%s', $entity, $bank, $label),
                        'entity'               => $entity,
                        'institution'          => $bank,
                        'label'                => $label,
                        'last4'                => $last4,
                        'kind'                 => $kind,
                        'currency'             => $primaryCurrency,
                        'currency_defaulted'   => true,
                        'path'                 => sprintf('%s/%s/%s', $entity, $bank, $label),
                        'abs_path'             => $abs,
                        'statements'           => null,
                        'transactions'         => null,
                        'first'                => null,
                        'last'                 => null,
                        'reconciled'           => null,
                        'recon_na'             => null,
                        'name'                 => null,
                        'firefly_type'         => null,
                        'opening_balance'      => null,
                        'opening_balance_date' => null,
                        'payment_date'         => null,
                        'warnings'             => null === $last4 ? [sprintf('no last-4 in the directory name "%s"', $label)] : [],
                    ];
                }
            }
        }

        return [
            'root'            => $root,
            'manifest_path'   => null,
            'mode'            => 'raw',
            'synthesized'     => true,
            'accounts'        => $accounts,
            'missing_columns' => [],
            'unknown_columns' => [],
            'warnings'        => [] === $accounts
                ? ['no manifest and no {ENTITY}/{BANK}/{ACCOUNT}/ directories were found under the root']
                : ['no manifest was found: the accounts were read from the {ENTITY}/{BANK}/{ACCOUNT}/ directories (raw mode); kinds were inferred from the labels — check them'],
            'totals'          => self::totals($accounts),
        ];
    }

    /** @return list<string> visible subdirectory names, sorted */
    private static function dirs(string $dir): array
    {
        $out = [];
        foreach (scandir($dir) ?: [] as $e) {
            if ('.' === $e[0] || !is_dir($dir.'/'.$e) || is_link($dir.'/'.$e)) {
                continue;
            }
            $out[] = $e;
        }
        sort($out, SORT_STRING);

        return $out;
    }
}
