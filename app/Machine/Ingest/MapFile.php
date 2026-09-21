<?php

/*
 * MapFile.php
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
use JsonException;

/**
 * The statements → Firefly-account map (apis.mdx §11.4, §12.4): written BESIDE the statements —
 * {ROOT}/.firefly-staging/_map.json — never in the repo, and never in the archive proper.
 *
 *   {"version": 1, "updated": "…Z", "entries": {"household/Northbank/Checking_x4021": {"account_id": 14,
 *     "account_name": "Household · Northbank Checking ••4021", "entity": …, "institution": …, "label": …,
 *     "last4": "4021", "via": "accounts/apply"}}}
 *
 * Deleting it loses nothing but time: /ingest/accounts/plan re-links every account by its stable
 * name (§12.2), and /ingest/map/infer proposes the rest.
 */
final class MapFile
{
    public const string FILE = '_map.json';

    /** @return array<string, array<string, mixed>> key => entry */
    public static function read(Staging $staging): array
    {
        $text = $staging->read(self::FILE);
        if (null === $text) {
            return [];
        }

        try {
            $doc = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        $entries = is_array($doc) && is_array($doc['entries'] ?? null) ? $doc['entries'] : [];
        $out     = [];
        foreach ($entries as $key => $entry) {
            if (is_string($key) && is_array($entry) && is_int($entry['account_id'] ?? null)) {
                $out[$key] = $entry;
            }
        }
        ksort($out, SORT_STRING);

        return $out;
    }

    /** @param array<string, array<string, mixed>> $entries */
    public static function write(Staging $staging, array $entries): string
    {
        ksort($entries, SORT_STRING);

        return $staging->write(self::FILE, (string) json_encode([
            'version' => 1,
            'updated' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
            'entries' => (object) $entries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /** A stable digest of the map (for a write's fingerprint). */
    public static function digest(Staging $staging): string
    {
        $entries = self::read($staging);
        $ids     = [];
        foreach ($entries as $k => $e) {
            $ids[$k] = $e['account_id'];
        }

        return hash('sha256', (string) json_encode($ids));
    }
}
