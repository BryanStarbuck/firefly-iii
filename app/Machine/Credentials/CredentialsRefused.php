<?php

/*
 * CredentialsRefused.php
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

namespace FireflyIII\Machine\Credentials;

use RuntimeException;

/**
 * The credentials file (or key file) was refused: a symlink, not a regular file, readable by
 * others, owned by someone else, not valid JSON, or holding a malformed key (apis.mdx §4.3).
 * The plane stays unarmed (404 for everything) and the reason goes to the log with its fix.
 */
final class CredentialsRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $fix = '')
    {
        parent::__construct($message);
    }
}
