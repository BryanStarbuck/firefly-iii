<?php

/*
 * Level.php
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
 * The four levels of a record — pm/error_err.mdx §3.2. EXPECTED is written only under
 * FIREFLY_ERROR_FILE_VERBOSE=1 (R6); FATAL bypasses every fold (§4.7).
 */
enum Level: string
{
    case Warn     = 'WARN';
    case Error    = 'ERROR';
    case Fatal    = 'FATAL';
    case Expected = 'EXPECTED';
}
