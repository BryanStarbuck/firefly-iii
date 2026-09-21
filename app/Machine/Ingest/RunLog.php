<?php

/*
 * RunLog.php
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
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\MachineException;
use JsonException;

/**
 * The ingest run log — apis.mdx §11.3: {ROOT}/.firefly-staging/_run.log, append-only, one JSON
 * line per run (id, timestamps, kind, counts, outcome), and the full report of each run in
 * _runs/{id}.json. Both live in staging: derived, rebuildable, git-ignored.
 */
final class RunLog
{
    private const string WHERE = 'app/Machine/Ingest/RunLog.php';

    public const string FILE = '_run.log';

    public static function newId(): string
    {
        return sprintf('run_%s_%s', CarbonImmutable::now('UTC')->format('Ymd\THis\Z'), bin2hex(random_bytes(3)));
    }

    /**
     * @param array<string, mixed> $line   the summary (counts, outcome)
     * @param array<string, mixed> $report the full report
     */
    public static function record(Staging $staging, string $id, string $kind, string $started, array $line, array $report): void
    {
        $entry = array_merge([
            'id'       => $id,
            'kind'     => $kind,
            'started'  => $started,
            'finished' => CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z'),
        ], $line);
        $staging->append(self::FILE, (string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $staging->write('_runs/'.$id.'.json', (string) json_encode(['run' => $entry, 'report' => $report], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /** @return list<array<string, mixed>> newest first */
    public static function all(Staging $staging, string $root): array
    {
        $text = $staging->read(self::FILE);
        if (null === $text) {
            return [];
        }
        $out  = [];
        foreach (explode("\n", $text) as $line) {
            if ('' === trim($line)) {
                continue;
            }

            try {
                $row = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                ErrorFile::for(self::WHERE)->expected('reading a run log line', $e);
                continue;
            }
            if (is_array($row) && isset($row['id'])) {
                $out[] = $row + ['root' => $root];
            }
        }

        return array_reverse($out);
    }

    /** @return array<string, mixed> */
    public static function find(Staging $staging, string $id): ?array
    {
        if (1 !== preg_match('/^run_\d{8}T\d{6}Z_[0-9a-f]{6}$/', $id)) {
            throw MachineException::invalid('That is not a run id.', 'Run ids look like run_20260921T101500Z_ab12cd (GET /machine/v1/ingest/runs lists them)', ['id' => $id]);
        }
        $text = $staging->read('_runs/'.$id.'.json');
        if (null === $text) {
            return null;
        }

        try {
            $doc = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            ErrorFile::for(self::WHERE)->expected('reading a run record', $e);
            return null;
        }

        return is_array($doc) ? $doc : null;
    }
}
