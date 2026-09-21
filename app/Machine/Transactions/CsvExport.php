<?php

/*
 * CsvExport.php
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

namespace FireflyIII\Machine\Transactions;

use Carbon\Carbon;
use FireflyIII\Models\UserGroup;
use FireflyIII\Support\Export\ExportDataGenerator;
use FireflyIII\User;
use Illuminate\Support\Collection;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * GET /transactions/export — Firefly's own exporter (ExportDataGenerator, the one behind the UI's
 * Export page and upstream's /api/v1/data/export/transactions), so the columns and the signs are
 * Firefly's. The exporter only knows a date range and accounts; the rest of the plane's filter is
 * applied by keeping the exporter's rows whose journal_id the SAME filter selected — one filter
 * language, one set (apis.mdx §8.3).
 */
final class CsvExport
{
    /**
     * @param null|list<int> $journalIds the journals the filter selected (null: keep every row)
     *
     * @return array{csv: string, rows: int, truncated: bool}
     */
    public static function export(User $user, UserGroup $group, ?Carbon $start, ?Carbon $end, Collection $accounts, ?array $journalIds, int $maxBytes): array
    {
        $generator = new ExportDataGenerator();
        $generator->setUser($user);
        $generator->setUserGroup($group);
        $generator->setStart(($start ?? Carbon::create(1900, 1, 1))->copy()->startOfDay());
        $generator->setEnd(($end ?? Carbon::create(2999, 12, 31))->copy()->endOfDay());
        $generator->setAccounts($accounts);
        $generator->setExportTransactions(true);
        $csv       = (string) ($generator->export()['transactions'] ?? '');

        $reader    = Reader::fromString($csv);
        $records   = iterator_to_array($reader->getRecords(), false);
        $header    = array_shift($records);
        if (!is_array($header)) {
            return ['csv' => $csv, 'rows' => 0, 'truncated' => false];
        }
        $column    = array_search('journal_id', $header, true);
        $keep      = null === $journalIds ? null : array_flip(array_map('strval', $journalIds));

        $writer    = Writer::fromString();
        $writer->insertOne($header);
        $bytes     = strlen($writer->toString());
        $rows      = 0;
        $truncated = false;
        foreach ($records as $record) {
            if (null !== $keep && false !== $column && !isset($keep[(string) ($record[$column] ?? '')])) {
                continue;
            }
            $line = Writer::fromString();
            $line->insertOne($record);
            $size = strlen($line->toString());
            if ($bytes + $size > $maxBytes) {
                $truncated = true;

                break;
            }
            $writer->insertOne($record);
            $bytes += $size;
            ++$rows;
        }

        return ['csv' => $writer->toString(), 'rows' => $rows, 'truncated' => $truncated];
    }
}
