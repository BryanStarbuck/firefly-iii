<?php

/*
 * CanonicalRow.php
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

use Carbon\Carbon;
use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use FireflyIII\Support\NullArrayObject;

/**
 * The fixed canonical row — apis.mdx §11.6 (LOCKED, R7).
 *
 * Firefly's TransactionJournalFactory hashes the WHOLE submitted journal row (import_hash_v2), so
 * the same transaction submitted with a key in another order, a trailing space, or a field the
 * rule engine will set anyway, hashes differently and imports twice. So every ingest row is
 * exactly KEY_ORDER — nothing the rule engine decides, no notes (the operator's field), keys in
 * this order, values normalised — and the hash means something.
 *
 * `original_source` (the importing tool's name) is added AFTER, and Firefly removes it before
 * hashing (hashArray()), so it can never re-key a row.
 *
 * ANY CHANGE HERE RE-KEYS EVERY FUTURE IMPORT. The golden-vector test pins the hash; changing it
 * is a deliberate, reviewed change.
 */
final class CanonicalRow
{
    public const array KEY_ORDER = [
        'type', 'date', 'amount', 'currency_code', 'description',
        'source_id', 'source_name', 'destination_id', 'destination_name',
        'external_id', 'internal_reference',
    ];

    public const string ORIGINAL_SOURCE = 'ff-machine-ingest|v1';

    /** Longest description Firefly keeps; the counterparty name is an account name (≤ 255). */
    private const int DESCRIPTION_MAX = 1000;
    private const int NAME_MAX        = 255;

    /**
     * One staged row (RowBuilder) → the canonical row for one Firefly account.
     *
     *   money out (negative)  withdrawal: source_id = the account, destination_name = the counterparty
     *   money in  (positive)  deposit:    source_name = the counterparty, destination_id = the account
     *
     * The counterparty is the normalised description (as the Data Importer does when a statement
     * names no separate payee); Firefly finds or creates that expense/revenue account.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, int|string> keys in KEY_ORDER, absent keys omitted
     *
     * @throws MachineException invalid_input when the amount has more places than the currency
     */
    public static function fromStatementRow(array $row, int $accountId, string $currencyCode, int $places): array
    {
        $signed      = (string) $row['amount'];
        $amount      = Money::normalize(Money::abs($signed), $places, 'amount', $currencyCode);
        $description = mb_substr(Normaliser::squash((string) $row['description']), 0, self::DESCRIPTION_MAX);
        $counterpart = mb_substr($description, 0, self::NAME_MAX);
        $out         = str_starts_with($signed, '-');
        $values      = [
            'type'               => $out ? 'withdrawal' : 'deposit',
            'date'               => (string) $row['date'],
            'amount'             => $amount,
            'currency_code'      => $currencyCode,
            'description'        => $description,
            'source_id'          => $out ? $accountId : null,
            'source_name'        => $out ? null : $counterpart,
            'destination_id'     => $out ? null : $accountId,
            'destination_name'   => $out ? $counterpart : null,
            'external_id'        => (string) $row['external_id'],
            'internal_reference' => mb_substr((string) $row['internal_reference'], 0, self::DESCRIPTION_MAX),
        ];
        $canonical   = [];
        foreach (self::KEY_ORDER as $key) {
            if (null !== $values[$key] && '' !== $values[$key]) {
                $canonical[$key] = $values[$key];
            }
        }

        return $canonical;
    }

    /**
     * The transaction row exactly as submitted to Firefly's factory: the canonical row with its
     * date as a Carbon at the start of the day in the app's timezone (what the API's own
     * StoreRequest hands the factory), then original_source.
     *
     * @param array<string, int|string> $canonical
     *
     * @return array<string, mixed>
     */
    public static function forFactory(array $canonical): array
    {
        $row = [];
        foreach ($canonical as $key => $value) {
            $row[$key] = 'date' === $key ? Carbon::createFromFormat('!Y-m-d', (string) $value, (string) config('app.timezone')) : $value;
        }
        $row['original_source'] = self::ORIGINAL_SOURCE;

        return $row;
    }

    /**
     * import_hash_v2 exactly as TransactionJournalFactory::hashArray() computes it for this row:
     * sha256 of the JSON of the submitted row, minus import_hash_v2, original_source and
     * batch_submission.
     *
     * @param array<string, int|string> $canonical
     */
    public static function hash(array $canonical): string
    {
        $row = new NullArrayObject(self::forFactory($canonical) + ['batch_submission' => true]);
        unset($row['import_hash_v2'], $row['original_source'], $row['batch_submission']);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
}
