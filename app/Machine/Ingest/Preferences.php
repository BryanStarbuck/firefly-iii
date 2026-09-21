<?php

/*
 * Preferences.php
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

use Carbon\CarbonImmutable;

/**
 * The operator's answers to statement conflicts (/ingest/prefer, apis.mdx §11.4), kept in
 * {ROOT}/.firefly-staging/_conflicts.csv — never in the archive, never in the ledger.
 *
 * _conflicts.csv holds one row per conflict group (status open | resolved, with the preferred
 * file) and one row per unreadable transaction line (kind row). Rebuilding the staging directory
 * keeps the resolved rows: an answer is given once.
 */
final class Preferences
{
    public const string FILE   = '_conflicts.csv';
    public const array HEADER  = ['kind', 'group', 'account', 'period', 'status', 'preferred', 'files', 'line', 'detail', 'decided_at'];

    /** @return array<string, string> group key => preferred relative path */
    public static function load(?Staging $staging): array
    {
        if (null === $staging) {
            return [];
        }
        $out = [];
        foreach ($staging->readCsv(self::FILE) as $row) {
            if ('statement' === ($row['kind'] ?? '') && 'resolved' === ($row['status'] ?? '') && '' !== ($row['preferred'] ?? '')) {
                $out[(string) $row['group']] = (string) $row['preferred'];
            }
        }

        return $out;
    }

    /**
     * Rewrite _conflicts.csv from a collection, keeping every recorded answer.
     *
     * @param array<string, array<string, mixed>> $accounts Collector::collect()['accounts']
     * @param array<string, array<string, mixed>> $extra    group key => an answer to add now
     */
    public static function write(Staging $staging, array $accounts, array $extra = []): void
    {
        $existing = [];
        foreach ($staging->readCsv(self::FILE) as $row) {
            if ('statement' === ($row['kind'] ?? '') && 'resolved' === ($row['status'] ?? '')) {
                $existing[(string) $row['group']] = $row;
            }
        }
        foreach ($extra as $group => $row) {
            $existing[$group] = $row;
        }
        $rows     = [];
        $seen     = [];
        foreach ($accounts as $a) {
            foreach ($a['groups'] as $g) {
                if (isset($existing[$g['group']])) {
                    $rows[]           = $existing[$g['group']];
                    $seen[$g['group']] = true;

                    continue;
                }
                if ('conflict' === $g['verdict']) {
                    $rows[] = [
                        'kind'    => 'statement', 'group' => $g['group'], 'account' => $g['account'], 'period' => $g['period'],
                        'status'  => 'open', 'preferred' => '', 'files' => implode(' | ', array_column($g['members'], 'file')),
                        'line'    => '', 'detail' => 'the statements disagree about which transactions exist — choose one with /ingest/prefer', 'decided_at' => '',
                    ];
                }
            }
            foreach ($a['bad'] as $b) {
                $rows[] = [
                    'kind'   => 'row', 'group' => '', 'account' => $b['account'], 'period' => '', 'status' => 'unreadable',
                    'preferred' => '', 'files' => $b['file'], 'line' => (string) $b['line'], 'detail' => $b['reason'], 'decided_at' => '',
                ];
            }
        }
        foreach ($existing as $group => $row) {
            if (!isset($seen[$group])) {
                $rows[] = $row; // an answer for a group not collected this time (another account or year) is kept
            }
        }
        $staging->writeCsv(self::FILE, self::HEADER, $rows);
    }

    /** @return array<string, string> one resolved row */
    public static function answer(string $group, string $account, string $period, string $preferred, string $files): array
    {
        return [
            'kind'       => 'statement', 'group' => $group, 'account' => $account, 'period' => $period, 'status' => 'resolved',
            'preferred'  => $preferred, 'files' => $files, 'line' => '', 'detail' => 'operator preferred this file',
            'decided_at' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
