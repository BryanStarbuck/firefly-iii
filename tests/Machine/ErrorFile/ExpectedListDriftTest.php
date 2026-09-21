<?php

/*
 * ExpectedListDriftTest.php
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

namespace Tests\Machine\ErrorFile;

use FireflyIII\Machine\ErrorFile\ExpectedMessages;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * pm/error_err.mdx §4.6, §17.2 and R15: every needle of ExpectedMessages::PREFIXES and
 * WITHHOLD_AFTER still occurs in upstream's app/**.php (the fork's own app/Machine/ is excluded,
 * so the list cannot find itself). When upstream rewords a string this test fails and the line
 * becomes a WARN until the needle is updated — the failure is safe: it adds noise, never hides a
 * fault. Read-only.
 *
 * @internal
 */
#[CoversNothing]
final class ExpectedListDriftTest extends TestCase
{
    private const string APP = __DIR__.'/../../../app';

    private static ?string $corpus = null;

    public function testEveryPrefixNeedleStillOccursUpstream(): void
    {
        $corpus = self::corpus();
        foreach (ExpectedMessages::PREFIXES as $i => [$regex, $needles]) {
            self::assertNotSame([], $needles, sprintf('row %d has no needle', $i + 1));
            self::assertNotFalse(@preg_match($regex, ''), sprintf('row %d: the regex does not compile', $i + 1));
            self::assertStringStartsWith('/^', $regex, sprintf('row %d: the regex must be anchored', $i + 1));
            foreach ($needles as $needle) {
                self::assertStringContainsString(
                    $needle,
                    $corpus,
                    sprintf('upstream no longer contains the needle of expected-prefix row %d (%s): update ExpectedMessages::PREFIXES — revisit pm/error_err.mdx §4.6', $i + 1, $needle)
                );
            }
        }
    }

    public function testEveryWithholdNeedleStillOccursUpstream(): void
    {
        foreach (ExpectedMessages::WITHHOLD_AFTER as $needle) {
            self::assertStringContainsString($needle, self::corpus(), sprintf('upstream no longer contains the withhold-after prefix "%s" — revisit pm/error_err.mdx §4.6', $needle));
        }
    }

    public function testTheMailErrorDumpPrefixStillOccursUpstream(): void
    {
        self::assertStringContainsString("'".ExpectedMessages::MAIL_ERROR."%s'", (string) file_get_contents(self::APP.'/Jobs/MailError.php'), 'MailError no longer dumps with "Exception is: %s" — revisit pm/error_err.mdx §4.6 step 5');
    }

    /** Every upstream .php file under app/, except the fork's app/Machine/, concatenated. */
    private static function corpus(): string
    {
        if (null !== self::$corpus) {
            return self::$corpus;
        }
        $root  = (string) realpath(self::APP);
        $parts = [];
        $walk  = static function (string $dir) use (&$walk, &$parts, $root): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $dir.'/'.$entry;
                if (is_dir($path)) {
                    if ($root.'/Machine' !== $path) {
                        $walk($path);
                    }
                } elseif (str_ends_with($entry, '.php')) {
                    $parts[] = (string) file_get_contents($path);
                }
            }
        };
        $walk($root);
        self::assertGreaterThan(500, count($parts), 'the upstream app/ tree looks incomplete');

        return self::$corpus = implode("\n", $parts);
    }
}
