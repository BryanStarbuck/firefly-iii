<?php

/*
 * Paths.php
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

use Illuminate\Container\Container;
use Throwable;

/**
 * Where the error trail lives — pm/error_err.mdx §3.1 (LOCKED).
 *
 * `errorFile()` resolves at WRITE time, never at boot, in this order:
 *   1. under PHPUnit (`runningUnitTests()`): the path set by `usePath()` (ErrorFile::usePath()
 *      delegates here), else a non-empty `errorfile.test_path`, else null = write nothing (R13).
 *      The env-derived `errorfile.path` is NEVER consulted under tests;
 *   2. a non-empty `errorfile.path` (FIREFLY_ERROR_FILE), `~/` expanded;
 *   3. $HOME/T/firefly/error.err;
 *   4. no home: <sys temp dir>/firefly_<uid>/error.err.
 *
 * Every method is total: it never throws.
 */
final class Paths
{
    public const string DEFAULT_RELATIVE = 'T/firefly/error.err';

    private static ?string $testPath = null;

    /** The in-process test seam (R13). Null clears it. */
    public static function usePath(?string $path): void
    {
        self::$testPath = null === $path || '' === $path ? null : $path;
    }

    public static function errorFile(): ?string
    {
        try {
            if (self::runningUnitTests()) {
                if (null !== self::$testPath) {
                    return self::expand(self::$testPath);
                }
                $test = self::config('errorfile.test_path', null);

                return is_string($test) && '' !== trim($test) ? self::expand(trim($test)) : null;
            }
            $configured = self::config('errorfile.path', null);
            if (is_string($configured) && '' !== trim($configured)) {
                return self::expand(trim($configured));
            }
            $home = self::home();
            if (null !== $home) {
                return $home.'/'.self::DEFAULT_RELATIVE;
            }

            return sprintf('%s/firefly_%d/error.err', rtrim(sys_get_temp_dir(), '/'), self::uid());
        } catch (Throwable) {
            return null;
        }
    }

    /** `error.fold`, always next to the resolved file (§3.5). */
    public static function foldFile(?string $errorFile = null): ?string
    {
        $errorFile ??= self::errorFile();

        return null === $errorFile ? null : dirname($errorFile).'/error.fold';
    }

    /** `error_file_coverage.json`, next to the resolved file (§13). */
    public static function coverageFile(?string $errorFile = null): ?string
    {
        $errorFile ??= self::errorFile();

        return null === $errorFile ? null : dirname($errorFile).'/error_file_coverage.json';
    }

    /**
     * Create the file's directory lazily at 0700, with the umask saved and ALWAYS restored (§4.4
     * step 1), so upstream files created later in the same process keep their usual modes.
     */
    public static function ensureDir(string $file): bool
    {
        $dir = dirname($file);
        if (is_dir($dir)) {
            return true;
        }
        $old = umask(0o077);

        try {
            return @mkdir($dir, 0o700, true) || is_dir($dir);
        } catch (Throwable) {
            return false;
        } finally {
            umask($old);
        }
    }

    /** `~/x` → `$HOME/x`. */
    public static function expand(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            $home = self::home() ?? rtrim(sys_get_temp_dir(), '/');

            return $home.substr($path, 1);
        }

        return $path;
    }

    /** A config value, or $default when there is no application or the key is unset. Total. */
    public static function config(string $key, mixed $default): mixed
    {
        try {
            $app = Container::getInstance();
            if (!$app->bound('config')) {
                return $default;
            }

            return $app->make('config')->get($key, $default) ?? $default;
        } catch (Throwable) {
            return $default;
        }
    }

    /** An integer config value (the caps and windows of §4.11). */
    public static function int(string $key, int $default): int
    {
        $value = self::config($key, $default);

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : $default;
    }

    public static function resetForTests(): void
    {
        self::$testPath = null;
    }

    private static function runningUnitTests(): bool
    {
        try {
            $app = Container::getInstance();

            return method_exists($app, 'runningUnitTests') && true === $app->runningUnitTests();
        } catch (Throwable) {
            return false;
        }
    }

    /** $HOME, as Audit::home() reads it; null when there is none. */
    private static function home(): ?string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        return is_string($home) && '' !== $home ? rtrim($home, '/') : null;
    }

    private static function uid(): int
    {
        if (function_exists('posix_getuid')) {
            return posix_getuid();
        }
        $uid = getmyuid();

        return false === $uid ? 0 : $uid;
    }
}
