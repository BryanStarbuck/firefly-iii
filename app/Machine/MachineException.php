<?php

/*
 * MachineException.php
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

use InvalidArgumentException;
use RuntimeException;

/**
 * The one error type of the plane — apis.mdx §5.2. Anything thrown inside /machine/v1 is
 * rendered as the envelope; a MachineException is rendered exactly as constructed.
 *
 * Every refusal names a fix (R6): `hint` is a command or a decision in the caller's vocabulary,
 * never a restatement of the message. `details` is structured and bounded — ids, counts,
 * field names, candidates — never a stack, a SQL string or a path outside the statements root.
 */
final class MachineException extends RuntimeException
{
    /** The nine codes and their HTTP statuses. A tenth code is a spec change, not a commit. */
    public const array CODES = [
        'unauthorized'   => 401,
        'forbidden'      => 403,
        'not_found'      => 404,
        'invalid_input'  => 400,
        'conflict'       => 409,
        'write_disabled' => 403,
        'not_ready'      => 503,
        'upstream_error' => 502,
        'internal'       => 500,
    ];

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $hint = null,
        public readonly array $details = [],
        ?int $status = null,
    ) {
        if (!array_key_exists($errorCode, self::CODES)) {
            throw new InvalidArgumentException(sprintf('"%s" is not one of the nine machine-plane error codes', $errorCode));
        }
        parent::__construct($message, $status ?? self::CODES[$errorCode]);
    }

    /** The HTTP status (the Exception code). */
    public function status(): int
    {
        return $this->getCode();
    }

    /** The envelope's error.code — one of the nine. */
    public function code(): string
    {
        return $this->errorCode;
    }

    /** @param array<string, mixed> $details */
    public static function notFound(string $message, ?string $hint = null, array $details = []): self
    {
        return new self('not_found', $message, $hint, $details);
    }

    /** @param array<string, mixed> $details */
    public static function invalid(string $message, ?string $hint = null, array $details = []): self
    {
        return new self('invalid_input', $message, $hint, $details);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $message, ?string $hint = null, array $details = []): self
    {
        return new self('conflict', $message, $hint, $details);
    }

    /** @param array<string, mixed> $details */
    public static function forbidden(string $message, ?string $hint = null, array $details = []): self
    {
        return new self('forbidden', $message, $hint, $details);
    }

    /** @param array<string, mixed> $details */
    public static function notReady(string $message, ?string $hint = null, array $details = []): self
    {
        return new self('not_ready', $message, $hint, $details);
    }

    public static function writeDisabled(string $route = ''): self
    {
        return new self(
            'write_disabled',
            '' === $route ? 'The write tier is off on this server.' : sprintf('%s is a write route and the write tier is off on this server.', $route),
            'Set FIREFLY_MACHINE_ALLOW_WRITE=1 in the app\'s .env and restart it (ffx stop && ffx up)',
            ['switch' => 'FIREFLY_MACHINE_ALLOW_WRITE'],
        );
    }

    /** @param array<string, mixed> $details */
    public static function upstream(string $message, ?string $hint = null, array $details = []): self
    {
        return new self('upstream_error', $message, $hint, $details);
    }

    /** @param array<string, mixed> $details */
    public static function internal(string $message = 'Internal error in the machine plane.', ?string $hint = null, array $details = []): self
    {
        return new self('internal', $message, $hint ?? 'The detail is in the app\'s own log (storage/logs/laravel.log) — ffx logs', $details);
    }
}
