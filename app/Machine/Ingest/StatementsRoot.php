<?php

/*
 * StatementsRoot.php
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

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\MachineException;

/**
 * The configured statement roots, and path containment — apis.mdx §11.4 ("every path is
 * contained"), cli.mdx §10.1 and §16.
 *
 * A root is CONFIGURATION, never a constant (§17). Sources, in order:
 *   FIREFLY_MACHINE_STATEMENTS_ROOT   (one or more, separated by ':'; ignored under PHPUnit)
 *   config('machine.statements_root') (a string or a list)
 *   firefly_iii.statements.root       in the credentials file
 *
 * Every `root` or `path` argument is resolved with realpath() — symlinks followed — and must lie
 * inside a configured root; `../../.ssh` is refused `forbidden`.
 */
final class StatementsRoot
{
    public const string ENV = 'FIREFLY_MACHINE_STATEMENTS_ROOT';

    /**
     * @return list<array{root: string, source: string}> the configured roots, ~ expanded, as written
     */
    public static function configured(): array
    {
        $out  = [];
        $seen = [];
        $add  = static function (mixed $value, string $source) use (&$out, &$seen): void {
            foreach (is_array($value) ? $value : explode(PATH_SEPARATOR, (string) $value) as $one) {
                $one = trim((string) $one);
                if ('' === $one) {
                    continue;
                }
                $one = self::expandHome($one);
                if (isset($seen[$one])) {
                    continue;
                }
                $seen[$one] = true;
                $out[]      = ['root' => $one, 'source' => $source];
            }
        };
        if (!app()->runningUnitTests()) {
            $env = getenv(self::ENV);
            $add(false === $env ? (env(self::ENV) ?? '') : $env, 'env '.self::ENV);
        }
        $add(config('machine.statements_root') ?? '', 'config machine.statements_root');
        $fromFile = CredentialsFile::fromConfig()->statementsRoot();
        if (null !== $fromFile) {
            $add($fromFile, 'credentials firefly_iii.statements.root');
        }

        return $out;
    }

    /**
     * A `root` argument, resolved and contained. Null means "the one configured root".
     *
     * @throws MachineException not_ready (nothing configured), invalid_input (several and none named),
     *                          not_found (missing), forbidden (outside every configured root)
     */
    public static function resolve(?string $root): string
    {
        $configured = self::configured();
        if ([] === $configured) {
            throw MachineException::notReady(
                'No statements root is configured.',
                'Set firefly_iii.statements.root in ~/.credentials/firefly_iii.json (or FIREFLY_MACHINE_STATEMENTS_ROOT) to the directory holding the statements',
                ['settings' => ['firefly_iii.statements.root', self::ENV]],
            );
        }
        $bases      = self::realBases($configured);
        if (null === $root || '' === trim($root)) {
            if (1 !== count($bases)) {
                throw MachineException::invalid(
                    sprintf('%d statement roots are configured and none was named.', count($configured)),
                    'Pass root: one of the configured roots (GET /machine/v1/ingest/roots lists them)',
                    ['roots' => array_column($configured, 'root')],
                );
            }

            return $bases[0];
        }
        $candidate  = self::expandHome(trim($root));
        if (!str_starts_with($candidate, '/')) {
            throw MachineException::invalid('root must be an absolute path.', 'Pass the statements root as an absolute path (GET /machine/v1/ingest/roots)', ['field' => 'root']);
        }
        $real       = realpath($candidate);
        if (false === $real) {
            // do not confirm or deny the existence of a path outside the roots
            self::assertInsideAny($bases, self::realOrSelf($candidate));

            throw MachineException::notFound('The statements root does not exist.', 'Check the path, or GET /machine/v1/ingest/roots for the configured roots', ['field' => 'root']);
        }
        self::assertInsideAny($bases, $real);
        if (!is_dir($real)) {
            throw MachineException::invalid('root is not a directory.', 'Pass the statements root directory, not a file', ['field' => 'root']);
        }

        return $real;
    }

    /**
     * A file (or directory) argument, contained inside $root. Relative paths are relative to
     * the root. Missing files are not_found only once they are known to be inside.
     */
    public static function contain(string $root, string $path, bool $mustExist = true): string
    {
        $path = self::expandHome(trim($path));
        if ('' === $path) {
            throw MachineException::invalid('An empty path was given.', 'Pass a file inside the statements root', ['field' => 'path']);
        }
        $abs  = str_starts_with($path, '/') ? $path : $root.'/'.$path;
        $real = realpath($abs);
        $test = false === $real ? self::realOrSelf($abs) : $real;
        if ($test === $root || !self::isInside($root, $test)) {
            throw MachineException::forbidden(
                'That path is outside the statements root.',
                'Every statements path must resolve (symlinks followed) to somewhere inside the configured root',
                ['field' => 'path'],
            );
        }
        if (false === $real && $mustExist) {
            throw MachineException::notFound(sprintf('No such file: %s.', self::relative($root, $test)), 'Check the path (it is relative to the statements root)', ['path' => self::relative($root, $test)]);
        }

        return $test;
    }

    /** A path contained in any configured root, and that root. @return array{0: string, 1: string} [root, path] */
    public static function containAnywhere(string $path): array
    {
        $configured = self::configured();
        if ([] === $configured) {
            self::resolve(null); // throws not_ready
        }
        $abs        = self::expandHome(trim($path));
        if (!str_starts_with($abs, '/')) {
            throw MachineException::invalid('path must be absolute here.', 'Pass the file\'s absolute path inside the statements root', ['field' => 'path']);
        }
        $real       = realpath($abs);
        $test       = false === $real ? self::realOrSelf($abs) : $real;
        foreach (self::realBases($configured) as $base) {
            if ($test !== $base && self::isInside($base, $test)) {
                return [$base, self::contain($base, $test)];
            }
        }

        throw MachineException::forbidden('That path is outside the statements root.', 'Every statements path must resolve (symlinks followed) to somewhere inside the configured root', ['field' => 'path']);
    }

    public static function isInside(string $outer, string $inner): bool
    {
        $outer = rtrim($outer, '/');

        return $inner === $outer || str_starts_with($inner, $outer.'/');
    }

    /** "bank/household/…/x.ofx" — a path as the caller should see it. */
    public static function relative(string $root, string $path): string
    {
        $root = rtrim($root, '/');
        if ($path === $root) {
            return '.';
        }

        return str_starts_with($path, $root.'/') ? substr($path, strlen($root) + 1) : basename($path);
    }

    /** realpath() of the deepest existing ancestor, with the missing tail appended. */
    public static function realOrSelf(string $path): string
    {
        $tail = [];
        $cur  = $path;
        while (true) {
            $real = realpath($cur);
            if (false !== $real) {
                return [] === $tail ? $real : rtrim($real, '/').'/'.implode('/', array_reverse($tail));
            }
            $parent = dirname($cur);
            if ($parent === $cur) {
                return $path;
            }
            $tail[] = basename($cur);
            $cur    = $parent;
        }
    }

    public static function expandHome(string $path): string
    {
        if ('~' === $path || str_starts_with($path, '~/')) {
            $home = (string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));

            return $home.substr($path, 1);
        }

        return $path;
    }

    /**
     * @param list<array{root: string, source: string}> $configured
     *
     * @return list<string> the configured roots that exist, resolved
     */
    private static function realBases(array $configured): array
    {
        $out = [];
        foreach ($configured as $c) {
            $real = realpath($c['root']);
            if (false !== $real && is_dir($real)) {
                $out[] = $real;
            }
        }
        if ([] === $out) {
            throw MachineException::notReady(
                'The configured statements root does not exist or is not a directory.',
                'Create it, or point firefly_iii.statements.root at the directory holding the statements',
                ['settings' => ['firefly_iii.statements.root', self::ENV]],
            );
        }

        return array_values(array_unique($out));
    }

    /** @param list<string> $bases */
    private static function assertInsideAny(array $bases, string $real): void
    {
        foreach ($bases as $base) {
            if (self::isInside($base, $real)) {
                return;
            }
        }

        throw MachineException::forbidden(
            'That root is outside the configured statements root.',
            'A root must be the configured root or a directory inside it (GET /machine/v1/ingest/roots)',
            ['field' => 'root'],
        );
    }
}
