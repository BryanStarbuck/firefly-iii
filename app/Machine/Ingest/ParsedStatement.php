<?php

/*
 * ParsedStatement.php
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
 * One statement (one file, or one PDF with its chosen sidecar) as parsed, with the verdict the
 * statement-level de-duplication (layer one, cli.mdx §10.4) gives it.
 *
 * `last4`, `entity`, `institution` and the period are read from INSIDE the document where it
 * says them; `periodFromRows` records when the period had to be taken from the rows instead.
 */
final class ParsedStatement
{
    public const string PRIMARY             = 'primary';
    public const string DUPLICATE_IDENTICAL = 'duplicate_identical';
    public const string SUPERSEDED          = 'superseded';
    public const string CONFLICT            = 'conflict';
    public const string NOT_CHOSEN          = 'not_chosen';
    public const string MISFILED            = 'misfiled';
    public const string UNREADABLE          = 'unreadable';

    /** Extraction sources, best first (cli.mdx §10.3); prepared formats rank with the best. */
    public const array SOURCE_RANK = ['ofx' => 0, 'qfx' => 0, 'camt053' => 0, 'csv' => 1, 'claude' => 1, 'brew' => 2, 'ocr' => 3, 'pdf_text' => 4];

    public string $status       = self::PRIMARY;
    public ?string $rule        = null;
    public ?string $supersededBy = null;
    public ?string $groupKey    = null;

    /**
     * @param list<StatementRow>                                   $rows
     * @param list<array{line: int, text: string, reason: string}> $bad   rows that could not be read — named, never dropped
     * @param list<string>                                         $warnings
     */
    public function __construct(
        public string $file,
        public string $relative,
        public string $format,
        public string $sourceKind,
        public string $sha256,
        public int $mtime,
        public ?string $last4,
        public ?string $entity,
        public ?string $institution,
        public ?string $periodStart,
        public ?string $periodEnd,
        public ?string $currency,
        public array $rows,
        public array $bad = [],
        public array $warnings = [],
        public bool $periodFromRows = false,
        public ?string $sourceFile = null,
    ) {}

    /** @return null|array{0: string, 1: string} the earliest and latest row date */
    public function rowRange(): ?array
    {
        if ([] === $this->rows) {
            return null;
        }
        $dates = array_map(static fn (StatementRow $r): string => $r->date, $this->rows);

        return [min($dates), max($dates)];
    }

    /** The calendar months this statement covers (its period, else its rows). @return list<string> */
    public function months(): array
    {
        if (null !== $this->periodStart && null !== $this->periodEnd) {
            return Values::months($this->periodStart, $this->periodEnd);
        }
        $range = $this->rowRange();

        return null === $range ? [] : Values::months($range[0], $range[1]);
    }

    public function period(): string
    {
        return sprintf('%s..%s', $this->periodStart ?? '?', $this->periodEnd ?? '?');
    }

    /** Where the rows came from: the sidecar or file actually parsed (relative). */
    public function source(): string
    {
        return $this->sourceFile ?? $this->relative;
    }

    /**
     * The multiset of (date, amount, description) the statement asserts — how two scans of one
     * month are compared ("do they disagree about which transactions exist?").
     *
     * @return array<string, int>
     */
    public function fingerprintBag(): array
    {
        $bag = [];
        foreach ($this->rows as $r) {
            $k       = sprintf('%s|%s|%s', $r->date, $r->amount, Normaliser::tieKey(Normaliser::description($r->text, $this->institution)));
            $bag[$k] = ($bag[$k] ?? 0) + 1;
        }

        return $bag;
    }
}
