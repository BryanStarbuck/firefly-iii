<?php

/*
 * Series.php
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

namespace FireflyIII\Machine\Analytics;

/**
 * The chart-ready series shape (apis.mdx §10.5), built from analytics rows:
 *
 *   { chart, x: {kind, labels}, series: [{key, label, currency_code, values}], provenance }
 *
 * `values` line up with `x.labels`; each value is a decimal string or null — null is "no data",
 * never "0" (R3), so a chart can draw a gap. Keys are stable, lowercase, [a-z0-9_-] identifiers.
 */
final class Series
{
    /** @var array<string, array{key: string, label: string, currency_code: null|string, values: list<null|string>}> */
    private array $series = [];

    /** @var array<string, int> label → index */
    private array $index  = [];

    /** @var array<string, true> */
    private array $usedKeys = [];

    /**
     * @param list<string> $labels
     */
    public function __construct(private readonly string $chart, private readonly string $kind, private array $labels)
    {
        foreach ($labels as $i => $label) {
            $this->index[$label] = $i;
        }
    }

    /** Add an x label (categorical charts grow their axis as rows arrive). */
    public function label(string $label): void
    {
        if (array_key_exists($label, $this->index)) {
            return;
        }
        $this->index[$label] = count($this->labels);
        $this->labels[]      = $label;
        foreach ($this->series as $id => $s) {
            $this->series[$id]['values'][] = null;
        }
    }

    /**
     * Set one point. $id identifies the series (stable across calls); $key is its preferred
     * public key (made unique here).
     */
    public function set(string $id, string $key, string $label, ?string $currencyCode, string $x, ?string $value): void
    {
        $this->label($x);
        if (!array_key_exists($id, $this->series)) {
            $this->series[$id] = ['key' => $this->uniqueKey($key), 'label' => $label, 'currency_code' => $currencyCode, 'values' => array_fill(0, count($this->labels), null)];
        }
        $this->series[$id]['values'][$this->index[$x]] = $value;
    }

    /**
     * @param array<string, mixed> $provenance
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function render(array $provenance, array $extra = []): array
    {
        return array_merge([
            'chart'      => $this->chart,
            'x'          => ['kind' => $this->kind, 'labels' => $this->labels],
            'series'     => array_values($this->series),
            'provenance' => $provenance,
        ], $extra);
    }

    /** "Dining out" + "USD" → "dining-out-usd" (the currency only when asked). */
    public static function slug(string $text, ?string $suffix = null): string
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $text), '-'));
        if ('' === $slug) {
            $slug = 'series';
        }
        if (null !== $suffix && '' !== $suffix) {
            $slug .= '-'.strtolower($suffix);
        }

        return $slug;
    }

    private function uniqueKey(string $key): string
    {
        $candidate = $key;
        $n         = 2;
        while (array_key_exists($candidate, $this->usedKeys)) {
            $candidate = sprintf('%s-%d', $key, $n);
            ++$n;
        }
        $this->usedKeys[$candidate] = true;

        return $candidate;
    }
}
