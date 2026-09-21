<?php

/*
 * CanaryJob.php
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

namespace FireflyIII\Machine\ErrorFile\Canary;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * The `php-queue` canary's job — pm/error_err.mdx §13.4 C12. Dispatched by
 * `firefly-machine:error-file-canary queue` on the sync driver, so JobProcessing,
 * JobExceptionOccurred and JobAttempted fire; its failure must be written as
 * `[ERROR] [php-queue] … running job CanaryJob` with the dispatcher's `doing` in `during=`, found
 * through the job-failure map after the sync driver popped the frame (§3.3).
 */
final class CanaryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('Synthetic canary job failure');
    }
}
