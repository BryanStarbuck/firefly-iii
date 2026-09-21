<?php

/*
 * StatementRow.php
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
 * One transaction line as a parser read it — before normalisation and ids.
 *
 *   amount  SIGNED from the account holder's view: negative is money out (a card purchase is
 *           negative too), as a canonical decimal string, never a float
 *   text    the bank's text, byte for byte (it becomes internal_reference)
 *   fitid   the bank's own id (OFX/QFX FITID) — the best dedupe key there will ever be
 *   line    the source line (or record) number, so a wrong number is traceable to a file and a line
 */
final readonly class StatementRow
{
    public function __construct(
        public string $date,
        public string $amount,
        public string $text,
        public int $line,
        public ?string $fitid = null,
    ) {}
}
