<?php

/*
 * Converted.php
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

use FireflyIII\Machine\Money;

/**
 * The one cross-currency figure the plane allows (apis.mdx §10.3, §14.1): a total in the
 * administration's PRIMARY currency, built only from Firefly's own conversion of every
 * contributing row (Ledger::converted). The moment one row has no conversion the total is
 * absent (null) — never a partial sum quietly labelled as the whole — and the note says why.
 */
final class Converted
{
    /** @var array<string, string> field => sum in the primary currency */
    private array $sums   = [];

    private int $count    = 0;

    private int $missing  = 0;

    public function __construct(private readonly Ledger $ledger) {}

    /** Add one journal's amount under $field ("spent", "income"…). */
    public function add(array $journal, string $field): void
    {
        ++$this->count;
        $amount = $this->ledger->converted($journal);
        if (null === $amount) {
            ++$this->missing;

            return;
        }
        $this->sums[$field] = Money::add($this->sums[$field] ?? '0', $amount);
    }

    public function complete(): bool
    {
        return 0 === $this->missing;
    }

    /** The raw (unformatted) sum of a field, "0" when nothing was added under it. */
    public function raw(string $field): string
    {
        return $this->sums[$field] ?? '0';
    }

    /**
     * `{converted_to, <field>…, count, complete: true}` — or null when any row could not be
     * converted, or nothing was counted at all.
     *
     * @return null|array<string, mixed>
     */
    public function render(): ?array
    {
        if (!$this->complete() || 0 === $this->count) {
            return null;
        }
        $code = (string) $this->ledger->primary->code;
        $out  = ['converted_to' => $code];
        foreach ($this->sums as $field => $sum) {
            $out[$field] = $this->ledger->fmt($sum, $code);
        }
        $out['count']    = $this->count;
        $out['complete'] = true;

        return $out;
    }

    /** The note that explains the converted block (or its absence). */
    public function note(): string
    {
        $code = (string) $this->ledger->primary->code;
        if (0 === $this->count) {
            return sprintf('converted (a total in the primary currency %s) is null: nothing was counted', $code);
        }
        if (!$this->complete()) {
            return sprintf('converted is null: %d of %d rows have no primary-currency (%s) amount from Firefly, so no total across currencies is given', $this->missing, $this->count, $code);
        }

        return sprintf('converted is the total in the primary currency %s, from Firefly\'s own conversion of every row (per-currency figures are in totals)', $code);
    }
}
