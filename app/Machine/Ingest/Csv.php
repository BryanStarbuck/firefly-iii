<?php

/*
 * Csv.php
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
 * RFC 4180 CSV, in memory — the manifest, prepared CSV statements and the staging files.
 * Values are always strings; nothing here ever turns an amount into a number.
 */
final class Csv
{
    /**
     * @param list<string>                   $header
     * @param iterable<array<string, mixed>> $rows
     */
    public static function encode(array $header, iterable $rows): string
    {
        $out = self::line($header);
        foreach ($rows as $row) {
            $values = [];
            foreach ($header as $column) {
                $v        = $row[$column] ?? '';
                $values[] = is_bool($v) ? ($v ? 'true' : 'false') : (is_array($v) ? implode(' | ', array_map('strval', $v)) : (string) $v);
            }
            $out .= self::line($values);
        }

        return $out;
    }

    /** @param list<string> $values */
    public static function line(array $values): string
    {
        $cells = [];
        foreach ($values as $v) {
            $v       = (string) $v;
            $cells[] = 1 === preg_match('/[",\r\n]/', $v) || ($v !== trim($v)) ? '"'.str_replace('"', '""', $v).'"' : $v;
        }

        return implode(',', $cells)."\n";
    }

    /**
     * @return list<array{line: int, cells: list<string>}> every non-empty record with its 1-based starting line
     */
    public static function decode(string $text, ?string $delimiter = null): array
    {
        $text      = self::stripBom($text);
        $delimiter ??= self::sniff($text);
        $records   = [];
        $cells     = [];
        $cell      = '';
        $quoted    = false;
        $line      = 1;
        $start     = 1;
        $len       = strlen($text);
        for ($i = 0; $i < $len; ++$i) {
            $c = $text[$i];
            if ($quoted) {
                if ('"' === $c) {
                    if ($i + 1 < $len && '"' === $text[$i + 1]) {
                        $cell .= '"';
                        ++$i;

                        continue;
                    }
                    $quoted = false;

                    continue;
                }
                if ("\n" === $c) {
                    ++$line;
                }
                $cell .= $c;

                continue;
            }
            if ('"' === $c && '' === trim($cell)) {
                $quoted = true;
                $cell   = '';

                continue;
            }
            if ($c === $delimiter) {
                $cells[] = $cell;
                $cell    = '';

                continue;
            }
            if ("\r" === $c) {
                continue;
            }
            if ("\n" === $c) {
                $cells[] = $cell;
                if ([''] !== array_map('trim', $cells) || count($cells) > 1) {
                    $records[] = ['line' => $start, 'cells' => $cells];
                }
                $cells = [];
                $cell  = '';
                ++$line;
                $start = $line;

                continue;
            }
            $cell .= $c;
        }
        if ('' !== $cell || [] !== $cells) {
            $cells[] = $cell;
            if ([''] !== array_map('trim', $cells) || count($cells) > 1) {
                $records[] = ['line' => $start, 'cells' => $cells];
            }
        }

        return $records;
    }

    /** @return list<array<string, string>> records keyed by the (trimmed) header */
    public static function decodeAssoc(string $text): array
    {
        $records = self::decode($text);
        if ([] === $records) {
            return [];
        }
        $header  = array_map(static fn (string $h): string => trim($h), array_shift($records)['cells']);
        $out     = [];
        foreach ($records as $r) {
            $row = [];
            foreach ($header as $i => $name) {
                $row[$name] = $r['cells'][$i] ?? '';
            }
            $out[] = $row;
        }

        return $out;
    }

    public static function stripBom(string $text): string
    {
        return str_starts_with($text, "\xEF\xBB\xBF") ? substr($text, 3) : $text;
    }

    /** The delimiter of the first line: the most frequent of , ; and TAB outside quotes. */
    public static function sniff(string $text): string
    {
        $first = strtok(self::stripBom($text), "\n");
        $first = false === $first ? '' : (string) preg_replace('/"[^"]*"/', '', $first);
        $best  = ',';
        $count = substr_count($first, ',');
        foreach ([';', "\t"] as $d) {
            if (substr_count($first, $d) > $count) {
                $best  = $d;
                $count = substr_count($first, $d);
            }
        }

        return $best;
    }
}
