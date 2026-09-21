<?php

/*
 * CanaryCommand.php
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

use Illuminate\Console\Command;
use RuntimeException;

/**
 * The artisan canaries C10–C12 — pm/error_err.mdx §13.4.
 *
 *   throw  a RuntimeException the console kernel reports → one [ERROR] [php-artisan]
 *   fatal  eval()s a redeclaration of an existing function: an uncatchable E_COMPILE_ERROR →
 *          one [FATAL] [php-artisan] `Symfony\Component\ErrorHandler\Error\FatalError`, net=shutdown.
 *          (An undefined-function call would not do: PHP 8 throws a catchable Error, which the console
 *          kernel reports as [ERROR].)
 *   queue  dispatch()es CanaryJob on the sync driver → one [ERROR] [php-queue] with during=
 *
 * Registered by ErrorFileServiceProvider::boot() ONLY when CanaryController::enabled() holds
 * (FIREFLY_ERROR_FILE_CANARY=1 and a non-empty FIREFLY_ERROR_FILE); it checks again and refuses
 * otherwise, so it can never write the real file.
 */
final class CanaryCommand extends Command
{
    protected $description = 'Error-file canary (pm/error_err.mdx §13.4): make one PHP net fire. Needs FIREFLY_ERROR_FILE_CANARY=1 and FIREFLY_ERROR_FILE.';

    protected $signature   = 'firefly-machine:error-file-canary {kind : throw|fatal|queue}';

    public function handle(): int
    {
        if (!CanaryController::enabled()) {
            $this->error('Refused: the error-file canary needs FIREFLY_ERROR_FILE_CANARY=1 and a non-empty FIREFLY_ERROR_FILE.');

            return 2;
        }
        $kind = (string) $this->argument('kind');

        switch ($kind) {
            case 'throw':
                throw new RuntimeException('Synthetic canary command failure');

            case 'fatal':
                // Redeclaring an existing function is a compile-time fatal (E_COMPILE_ERROR) that no
                // catch can stop; HandleExceptions::handleShutdown() turns it into a FatalError report.
                eval('function strlen() {}');   // @phpstan-ignore disallowed.eval

                return 1;

            case 'queue':
                dispatch((new CanaryJob())->onConnection('sync'));

                return 0;

            default:
                $this->error('Unknown canary kind; use throw, fatal or queue.');

                return 2;
        }
    }
}
