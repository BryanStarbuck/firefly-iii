<?php

/*
 * ParseResult.php
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

namespace FireflyIII\Machine\Ingest\Parsers;

/**
 * What every parser returns — a plain array so the parsers stay free of framework types:
 *
 *   rows          list<StatementRow>
 *   bad           list<array{line, text, reason}>   rows that could not be read (named, never dropped)
 *   last4, currency, institution, entity           as the DOCUMENT states them (null when it does not)
 *   period_start, period_end                       Y-m-d, as the document states them
 *   warnings      list<string>
 *   unreadable    bool — the document itself could not be understood
 */
final class ParseResult
{
    /** @return array<string, mixed> */
    public static function empty(): array
    {
        return [
            'rows'         => [],
            'bad'          => [],
            'last4'        => null,
            'currency'     => null,
            'institution'  => null,
            'entity'       => null,
            'period_start' => null,
            'period_end'   => null,
            'warnings'     => [],
            'unreadable'   => false,
        ];
    }
}
