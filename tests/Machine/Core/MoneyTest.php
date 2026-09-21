<?php

/*
 * MoneyTest.php
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

namespace Tests\Machine\Core;

use FireflyIII\Machine\MachineException;
use FireflyIII\Machine\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * pm/apis.mdx §14.1–§14.2 and §18 "Money": decimal strings with bcmath, never floats; positive
 * transaction amounts with the positive amount in the hint; the currency's places refused, never
 * rounded; the float/round/number_format canary over app/Machine.
 *
 * @internal
 *
 * @coversNothing
 */
final class MoneyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        bcscale(12);
    }

    #[DataProvider('normalizeCases')]
    public function testNormalize(mixed $in, int $places, string $out): void
    {
        $this->assertSame($out, Money::normalize($in, $places));
    }

    public static function normalizeCases(): array
    {
        return [
            'plain'           => ['12.5', 2, '12.50'],
            'integer string'  => ['12', 2, '12.00'],
            'exact'           => ['12.34', 2, '12.34'],
            'trailing zeros'  => ['12.500', 2, '12.50'],
            'leading zeros'   => ['0012.50', 2, '12.50'],
            'zero'            => ['0', 2, '0.00'],
            'negative'        => ['-3.1', 2, '-3.10'],
            'yen'             => ['1500', 0, '1500'],
            'dinar'           => ['1.234', 3, '1.234'],
            'whitespace'      => [' 7.00 ', 2, '7.00'],
        ];
    }

    #[DataProvider('refusedCases')]
    public function testNormalizeRefuses(mixed $in, int $places): void
    {
        try {
            Money::normalize($in, $places, 'amount', 'USD');
            $this->fail('expected invalid_input for '.var_export($in, true));
        } catch (MachineException $e) {
            $this->assertSame('invalid_input', $e->errorCode);
            $this->assertNotEmpty($e->hint);
        }
    }

    public static function refusedCases(): array
    {
        return [
            'a JSON float'        => [12.5, 2],
            'a JSON int'          => [12, 2],
            'thousands separator' => ['1,234.50', 2],
            'exponent'            => ['1e3', 2],
            'plus sign'           => ['+5', 2],
            'too many places'     => ['12.345', 2],
            'yen with decimals'   => ['1500.5', 0],
            'empty'               => ['', 2],
            'null'                => [null, 2],
            'text'                => ['twelve', 2],
            'hex'                 => ['0x1A', 2],
        ];
    }

    public function testAPositiveAmountIsRequiredAndTheHintShowsIt(): void
    {
        $this->assertSame('42.00', Money::positive('42', 2));
        try {
            Money::positive('-42.50', 2);
            $this->fail('a negative amount must be refused');
        } catch (MachineException $e) {
            $this->assertSame('invalid_input', $e->errorCode);
            $this->assertStringContainsString('"42.50"', (string) $e->hint);
            $this->assertStringContainsString('withdrawal', (string) $e->hint);
        }
        $this->expectException(MachineException::class);
        Money::positive('0.00', 2);
    }

    public function testFormatRoundsHalfAwayFromZeroWithBcmath(): void
    {
        $this->assertSame('12.35', Money::format('12.345000000000', 2));
        $this->assertSame('12.34', Money::format('12.344999999999', 2));
        $this->assertSame('-12.35', Money::format('-12.345', 2));
        $this->assertSame('0.00', Money::format('-0.004', 2));
        $this->assertSame('1501', Money::format('1500.5', 0));
        $this->assertSame('0.10', Money::format('0.1', 2));
        $this->assertSame('100000000000000000000.01', Money::format('100000000000000000000.01', 2), 'no float precision loss');
    }

    public function testArithmeticIsExactAndDivisionByZeroIsAbsent(): void
    {
        $this->assertSame('0.3', Money::add('0.1', '0.2'));
        $this->assertSame('10', Money::sum(['2.50', '2.50', '5']));
        $this->assertSame('-2.5', Money::sub('2.5', '5'));
        $this->assertSame('2.5', Money::abs('-2.50'));
        $this->assertSame('3', Money::negate('-3'));
        $this->assertNull(Money::div('10', '0'), 'a ratio that cannot be computed is absent, not "0"');
        $this->assertSame('3.333333333333', Money::div('10', '3'));
        $this->assertSame(1, Money::compare('10.00', '9.999'));
        $this->assertTrue(Money::isDecimal('-0.5'));
        $this->assertFalse(Money::isDecimal('.5'));
        $this->assertFalse(Money::isDecimal(0.5));
    }

    public function testNoFloatRoundOrNumberFormatOutsideMoney(): void
    {
        $offenders = [];
        foreach (CredentialsFileTest::planeFiles() as $file) {
            if (str_ends_with($file, '/app/Machine/Money.php')) {
                continue;
            }
            $code = CredentialsFileTest::codeOnly((string) file_get_contents($file));
            foreach (['/\(\s*float\s*\)/', '/\bfloatval\s*\(/', '/(?<![\w>:$])round\s*\(/', '/\bnumber_format\s*\(/'] as $pattern) {
                if (1 === preg_match($pattern, $code)) {
                    $offenders[] = sprintf('%s matches %s', basename($file), $pattern);
                }
            }
        }
        $this->assertSame([], $offenders, 'amounts are bcmath strings: no float casts, round() or number_format() in app/Machine outside Money.php');
    }

    public function testNoPrivatePathsOrSecretsInThePlaneSource(): void
    {
        foreach (CredentialsFileTest::planeFiles() as $file) {
            $source = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('~/Users/[a-z]~', CredentialsFileTest::codeOnly($source), $file.' holds a private absolute path');
            $this->assertDoesNotMatchRegularExpression('/(?<![0-9a-f])[0-9a-f]{64}(?![0-9a-f])/', $source, $file.' holds a 64-hex literal');
            $this->assertStringNotContainsString('bank_statements/import', CredentialsFileTest::codeOnly($source));
        }
    }
}
