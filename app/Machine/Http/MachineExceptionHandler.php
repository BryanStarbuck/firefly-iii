<?php

/*
 * MachineExceptionHandler.php
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

namespace FireflyIII\Machine\Http;

use FireflyIII\Exceptions\Handler;
use FireflyIII\Machine\Envelope;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\MachineException;
use Illuminate\Http\Request;
use Override;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The envelope renderer for /machine/v1 (apis.mdx §5.1). Firefly III's own Handler renders
 * everything else exactly as upstream does; for a plane request, every Throwable — including
 * the ones raised before the plane's middleware ran (a body too large for PHP, maintenance
 * mode) — becomes the JSON envelope. Never HTML, never a stack, never a class name.
 *
 * A MachineException is a refusal the plane meant to give, so it is not reported (no error mail,
 * no stack in the log); anything else is written to ~/T/firefly/error.err first (pm/error_err.mdx)
 * and then reported through Firefly's handler as usual. An `internal` or `upstream_error`
 * MachineException is written there too, with its original cause.
 *
 * report() is also the error file's handler net (pm/error_err.mdx §4.5, N1–N3): every reported
 * throwable in every PHP runtime passes through it, and ErrorFile::handlerReport() writes the record
 * BEFORE parent::report(), so it survives a MailError job that throws and an APP_LOG_LEVEL above
 * error. An out-of-memory FatalError first raises the memory limit — the first statement, before
 * the error-file library is even autoloaded (R9, §4.7).
 */
final class MachineExceptionHandler extends Handler
{
    #[Override]
    public function render($request, Throwable $e): Response
    {
        if ($request instanceof Request && self::isPlaneRequest($request)) {
            return Envelope::error(Envelope::fromThrowable($e));
        }

        return parent::render($request, $e);
    }

    #[Override]
    public function report(Throwable $e): void
    {
        if ($e instanceof FatalError && str_starts_with($e->getMessage(), 'Allowed memory size')) {
            @ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1048576));   // FIRST (pm/error_err.mdx §4.7)
        }
        ErrorFile::handlerReport($e, $this);   // total; writes BEFORE parent::report()
        if ($e instanceof MachineException) {
            return;
        }
        parent::report($e);
    }

    #[Override]
    public function shouldReport(Throwable $e)
    {
        if ($e instanceof MachineException) {
            return false;
        }

        return parent::shouldReport($e);
    }

    public static function isPlaneRequest(Request $request): bool
    {
        $path = ltrim($request->path(), '/');

        return 'machine/v1' === $path || str_starts_with($path, 'machine/v1/');
    }
}
