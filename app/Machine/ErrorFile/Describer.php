<?php

/*
 * Describer.php
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

use Throwable;

/**
 * A throwable → headline, cause chain, stack and origin — pm/error_err.mdx §3.2, §4.8.
 *
 *  - headline: `FQCN: message (code=…)`, the FQCN without its leading backslash, the message
 *    middle-cut to 2,000 characters BEFORE anything is concatenated (an out-of-memory FATAL must
 *    not allocate a large string);
 *  - causes: ` | cause: FQCN: message (code=…)` for up to 5 previous throwables, cycle-safe;
 *  - stack: at most 12 frames `Class->method (repo/relative.php:line)` (`::` for a static call).
 *    Frames under vendor/ and app/Machine/ErrorFile/ (except its Canary/) are dropped; if nothing
 *    would remain, the first three are kept. Only getTrace()'s file, line, class, type and
 *    function keys are read — never the arguments, and getTraceAsString() is never called;
 *  - origin: `path:line` of the throw when that file is in the repo and not under vendor/,
 *    otherwise the first trace frame that is.
 *
 * Every method is total.
 */
final class Describer
{
    public const int MAX_CAUSES = 5;
    public const int MAX_FRAMES = 12;

    public static function headline(Throwable $e): string
    {
        try {
            $message = LineFormat::capMiddle($e->getMessage(), LineFormat::MESSAGE_CAP);
            $code    = $e->getCode();
            $suffix  = (is_int($code) && 0 !== $code) || (is_string($code) && '' !== $code && '0' !== $code) ? ' (code='.$code.')' : '';

            return ltrim($e::class, '\\').('' === $message ? '' : ': '.$message).$suffix;
        } catch (Throwable) {
            return 'Throwable';
        }
    }

    /** The cause chain with its leading separators, or ''. */
    public static function causes(Throwable $e): string
    {
        try {
            $out  = '';
            $seen = [spl_object_id($e) => true];
            $next = $e->getPrevious();
            for ($i = 0; null !== $next && $i < self::MAX_CAUSES; ++$i) {
                if (isset($seen[spl_object_id($next)])) {
                    break;
                }
                $seen[spl_object_id($next)] = true;
                $out .= ' | cause: '.self::headline($next);
                $next = $next->getPrevious();
            }

            return $out;
        } catch (Throwable) {
            return '';
        }
    }

    /** @return list<string> the frames, without the four-space indent and without "at " */
    public static function stack(Throwable $e): array
    {
        try {
            $frames = [];
            $file   = $e->getFile();
            $line   = $e->getLine();
            foreach ($e->getTrace() as $frame) {
                $frames[] = ['call' => self::call($frame), 'file' => $file, 'line' => $line];
                $file     = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
                $line     = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
                if (count($frames) >= 64) {
                    break;
                }
            }
            if ([] === $frames) {
                $frames[] = ['call' => '{main}', 'file' => $file, 'line' => $line];
            }
            $kept = [];
            foreach ($frames as $frame) {
                if ('' === $frame['file'] || !self::isOwnFile($frame['file'])) {
                    continue;
                }
                $kept[] = $frame;
                if (count($kept) >= self::MAX_FRAMES) {
                    break;
                }
            }
            if ([] === $kept) {
                $kept = array_slice(array_values(array_filter($frames, static fn (array $f): bool => '' !== $f['file'])), 0, 3);
            }
            $out = [];
            foreach ($kept as $frame) {
                $out[] = Redactor::text(sprintf('at %s (%s:%d)', $frame['call'], self::relative($frame['file']), $frame['line']));
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** `path:line` where the throwable came from (§4.8). */
    public static function origin(Throwable $e): string
    {
        try {
            $file = $e->getFile();
            if (self::isOwnFile($file)) {
                return self::relative($file).':'.$e->getLine();
            }
            foreach ($e->getTrace() as $frame) {
                $f = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
                if ('' !== $f && self::isOwnFile($f)) {
                    return self::relative($f).':'.(isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0);
                }
            }

            return Redactor::text(self::relative($file)).':'.$e->getLine();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * `path:line` of the first backtrace frame outside vendor/ and outside the library (except its
     * Canary/) — the `where` of a bare log line (§4.6 step 8). IGNORE_ARGS, depth 30.
     */
    public static function callerOrigin(): string
    {
        try {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
                $f = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
                if ('' !== $f && self::isOwnFile($f)) {
                    return self::relative($f).':'.(isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0);
                }
            }
        } catch (Throwable) {
            // total
        }

        return '';
    }

    /** An absolute path under the repo → repo-relative; anything else unchanged. */
    public static function relative(string $file): string
    {
        $base = Redactor::repoBase().'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /** In the repo, not under vendor/, and not the library itself (its Canary/ counts as own). */
    public static function isOwnFile(string $file): bool
    {
        $base = Redactor::repoBase().'/';
        if (!str_starts_with($file, $base)) {
            return false;
        }
        $rel = substr($file, strlen($base));
        if (str_starts_with($rel, 'vendor/')) {
            return false;
        }

        return !str_starts_with($rel, 'app/Machine/ErrorFile/') || str_starts_with($rel, 'app/Machine/ErrorFile/Canary/');
    }

    /** @param array<string, mixed> $frame */
    private static function call(array $frame): string
    {
        $function = isset($frame['function']) && is_string($frame['function']) ? $frame['function'] : '{main}';
        $class    = isset($frame['class']) && is_string($frame['class']) ? ltrim($frame['class'], '\\') : '';
        if ('' === $class) {
            return $function;
        }
        $type = isset($frame['type']) && '::' === $frame['type'] ? '::' : '->';

        return $class.$type.$function;
    }
}
