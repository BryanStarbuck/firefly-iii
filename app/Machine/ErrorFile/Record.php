<?php

/*
 * Record.php
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

namespace FireflyIII\Machine\ErrorFile;

/**
 * One fault, already described and redacted — pm/error_err.mdx §3.2, §4.2.
 *
 * Field conventions (shared with errorfile/src/format.ts through the golden fixture, R16):
 *  - `error`  is `FQCN: message (code=…)`, or '' for a record with no error;
 *  - `cause`  is the whole cause chain INCLUDING its separators, ` | cause: A | cause: B`, or '';
 *  - `stack`  is a list of frame texts WITHOUT the four-space indent (`at Class->method (path:line)`);
 *  - `data`   is a flat map of scalars; LineFormat::dataBlock() renders it.
 */
final readonly class Record
{
    /**
     * @param list<string>                               $stack
     * @param array<string, null|bool|float|int|string> $data
     */
    public function __construct(
        public string $ts,
        public Level $level,
        public string $app,
        public string $where,
        public string $doing,
        public string $error = '',
        public string $cause = '',
        public array $stack = [],
        public array $data = [],
    ) {}
}
