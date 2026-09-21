<?php

/*
 * DryRunResult.php
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

namespace FireflyIII\Machine;

/**
 * What a dry run returned, and what its recorders held back (apis.mdx §7.2).
 */
final readonly class DryRunResult
{
    public function __construct(
        public mixed $value,
        public int $webhooks = 0,
        public int $jobs = 0,
        public int $mails = 0,
        public int $notifications = 0,
    ) {}

    /** @return array{jobs: int, mail: int, notifications: int} */
    public function held(): array
    {
        return ['jobs' => $this->jobs, 'mail' => $this->mails, 'notifications' => $this->notifications];
    }
}
