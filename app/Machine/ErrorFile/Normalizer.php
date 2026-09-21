<?php

/*
 * Normalizer.php
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
 * The fold-key normaliser — pm/error_err.mdx §3.5. `id 123` and `id 456` fold together; the stack
 * is never part of the key. Same rules, same order, as Actual Budget's `normalizeMessage()`.
 */
final class Normalizer
{
    public const string UUID     = '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i';
    public const string HEX_RUN  = '/\b[0-9a-f]{8,}\b/i';
    public const string ABS_PATH = '/(?:[a-z]:\\\\|\/)[^\s\'"():]+/i';
    public const string DIGITS   = '/\d+/';
    public const int MAX         = 300;

    public static function message(string $message): string
    {
        try {
            $out = (string) preg_replace(self::UUID, '<uuid>', $message);
            $out = (string) preg_replace(self::HEX_RUN, '<hex>', $out);
            $out = (string) preg_replace(self::ABS_PATH, '<path>', $out);
            $out = (string) preg_replace(self::DIGITS, '#', $out);

            return mb_substr($out, 0, self::MAX);
        } catch (Throwable) {
            return substr($message, 0, self::MAX);
        }
    }

    /**
     * The fold key: xxh128(app␁where␁doing␁FQCN␁normalised message) (§3.5).
     */
    public static function key(string $app, string $where, string $doing, string $class, string $message): string
    {
        return hash('xxh128', implode("\x01", [$app, $where, $doing, $class, self::message($message)]));
    }
}
