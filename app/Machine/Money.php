<?php

/*
 * Money.php
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

use FireflyIII\Models\TransactionCurrency;

/**
 * THE one place a decimal amount is parsed or formatted — apis.mdx §14.1 (R2).
 *
 * Amounts are decimal STRINGS computed with bcmath. Nothing here (or anywhere in app/Machine)
 * casts an amount to float or int, calls round() or number_format(): a canary test greps for
 * exactly that. "No limit" is null, never "0" (§14.2) — that is the caller's rule, and nothing
 * here turns a null into a zero.
 */
final class Money
{
    /** Firefly stores decimal(32,12); bcscale(12) is set in bootstrap/app.php. */
    public const int SCALE = 12;

    private const string DECIMAL = '/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/';

    /** A canonical decimal string: optional '-', no leading zeros, optional fraction. */
    public static function isDecimal(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 64 && 1 === preg_match(self::DECIMAL, $value);
    }

    /** Strictly greater than zero. */
    public static function isPositive(mixed $value): bool
    {
        return self::isDecimal($value) && 1 === bccomp($value, '0', self::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return 0 === bccomp($value, '0', self::SCALE);
    }

    /** Decimal places written in the string ("12.50" → 2, "12" → 0). */
    public static function places(string $value): int
    {
        $dot = strpos($value, '.');

        return false === $dot ? 0 : strlen($value) - $dot - 1;
    }

    /**
     * Validate an INPUT amount and render it at the currency's places — never rounding.
     * Accepts "12", "12.5", "12.50", "0012.50" (leading zeros stripped), "-3.00". Refuses a
     * float or int (a JSON number), "1,234.50", "1e3", "+5", and anything with more decimal
     * places than the currency allows (§14.1: refused, never silently rounded).
     *
     * @throws MachineException invalid_input naming $field
     */
    public static function normalize(mixed $amount, int $places, string $field = 'amount', string $currencyCode = ''): string
    {
        if (is_int($amount) || is_float($amount)) {
            throw MachineException::invalid(
                sprintf('%s must be a decimal string, not a JSON number.', $field),
                sprintf('Send "%s": "12.34" — amounts are strings so no parser on either side turns them into floats', $field),
                ['field' => $field],
            );
        }
        if (!is_string($amount)) {
            throw MachineException::invalid(sprintf('%s must be a decimal string.', $field), sprintf('Send "%s": "12.34"', $field), ['field' => $field]);
        }
        $trimmed = trim($amount);
        // tolerate leading zeros on input ("007.50"), never on output
        $clean   = (string) preg_replace('/^(-?)0+(?=\d)/', '$1', $trimmed);
        if (!self::isDecimal($clean)) {
            throw MachineException::invalid(
                sprintf('%s is not a decimal amount.', $field),
                sprintf('Send "%s" as a plain decimal string like "1234.50" — no thousands separators, no exponent, no "+"', $field),
                ['field' => $field],
            );
        }
        // "12.500" is twelve and a half at two places; "12.505" is not representable in USD.
        if (self::places(self::strip($clean)) > $places) {
            throw MachineException::invalid(
                sprintf('%s has more decimal places than %s allows (%d).', $field, '' === $currencyCode ? 'the currency' : $currencyCode, $places),
                sprintf('Send %s with at most %d decimal place%s — amounts are never silently rounded', $field, $places, 1 === $places ? '' : 's'),
                ['field' => $field, 'places' => $places],
            );
        }

        return self::pad(self::strip($clean), $places);
    }

    /**
     * An input amount that must be POSITIVE (a transaction amount: direction is the type, §14.1).
     * A negative amount is refused with the positive amount in the hint.
     */
    public static function positive(mixed $amount, int $places, string $field = 'amount', string $currencyCode = ''): string
    {
        $value = self::normalize($amount, $places, $field, $currencyCode);
        if (1 !== bccomp($value, '0', self::SCALE)) {
            $abs = ltrim($value, '-'); // keeps the currency's places: "42.50", not "42.5"

            throw MachineException::invalid(
                sprintf('%s must be positive — direction is the transaction type, not the sign.', $field),
                self::isZero($value)
                    ? sprintf('%s cannot be zero', $field)
                    : sprintf('Send "%s": "%s" with type "withdrawal" (money out) or "deposit" (money in)', $field, $abs),
                ['field' => $field],
            );
        }

        return $value;
    }

    /**
     * Format a STORED amount (Firefly's 12 places) for display at $places, rounding half away
     * from zero with bcmath. Display fields only — a value the caller will send back must be
     * normalize()d, never format()ted.
     */
    public static function format(string $amount, int $places): string
    {
        if (!self::isDecimal($amount)) {
            $amount = (string) preg_replace('/^(-?)0+(?=\d)/', '$1', trim($amount));
            if (!self::isDecimal($amount)) {
                throw MachineException::internal('A stored amount is not a decimal string.');
            }
        }
        $places = max(0, $places);
        $half   = '0.'.str_repeat('0', $places).'5';
        $sign   = str_starts_with($amount, '-') ? '-' : '';
        $abs    = ltrim($amount, '-');
        $result = bcadd($abs, $half, $places); // bcadd truncates at $places, so +half == round half up
        $result = self::pad(self::strip($result), $places);
        if ('' !== $sign && !self::isZero($result)) {
            $result = '-'.$result;
        }

        return $result;
    }

    /** format() at a currency's own decimal places (USD 2, JPY 0, BHD 3). */
    public static function forCurrency(string $amount, TransactionCurrency $currency): string
    {
        return self::format($amount, (int) $currency->decimal_places);
    }

    public static function add(string $a, string $b): string
    {
        return self::strip(bcadd($a, $b, self::SCALE));
    }

    public static function sub(string $a, string $b): string
    {
        return self::strip(bcsub($a, $b, self::SCALE));
    }

    public static function mul(string $a, string $b): string
    {
        return self::strip(bcmul($a, $b, self::SCALE));
    }

    /** Division with a null for a zero divisor — a ratio that cannot be computed is absent, not "0". */
    public static function div(string $a, string $b, int $scale = self::SCALE): ?string
    {
        if (self::isZero($b)) {
            return null;
        }

        return self::strip(bcdiv($a, $b, $scale));
    }

    /** @param iterable<string> $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = bcadd($total, $amount, self::SCALE);
        }

        return self::strip($total);
    }

    public static function compare(string $a, string $b): int
    {
        return bccomp($a, $b, self::SCALE);
    }

    public static function negate(string $a): string
    {
        return self::strip(bcmul($a, '-1', self::SCALE));
    }

    public static function abs(string $a): string
    {
        return ltrim(self::strip($a), '-');
    }

    /** Remove trailing fractional zeros and a trailing dot; "-0" becomes "0". */
    public static function strip(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if ('-0' === $value || '' === $value || '-' === $value) {
            return '0';
        }

        return $value;
    }

    /** Right-pad the fraction to exactly $places ("12" → "12.00" at 2; "12.5" → "12.50"). */
    private static function pad(string $value, int $places): string
    {
        if ($places <= 0) {
            return $value;
        }
        $have = self::places($value);
        if (0 === $have) {
            return $value.'.'.str_repeat('0', $places);
        }

        return $value.str_repeat('0', $places - $have);
    }
}
