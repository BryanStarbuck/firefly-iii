<?php

/*
 * LineFormatTest.php
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

use FireflyIII\Machine\ErrorFile\Level;
use FireflyIII\Machine\ErrorFile\LineFormat;
use FireflyIII\Machine\ErrorFile\Normalizer;
use FireflyIII\Machine\ErrorFile\Record;
use FireflyIII\Machine\ErrorFile\Redactor;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * pm/error_err.mdx §3.2 (the line, LOCKED), §3.4 (the goldens, R16), §3.5 (the fold-key
 * normaliser) and §12 (the redaction the goldens run through). The golden fixtures are the ones
 * errorfile/src/format.ts must also produce byte for byte.
 *
 * @internal
 */
#[CoversNothing]
final class LineFormatTest extends TestCase
{
    private const string RECORDS = __DIR__.'/../../../errorfile/fixtures/golden-records.json';
    private const string LINES   = __DIR__.'/../../../errorfile/fixtures/golden-lines.txt';

    /** Render the golden records through Redactor + LineFormat, exactly as the fixture's _doc says. */
    public static function renderGoldens(): string
    {
        $doc = json_decode((string) file_get_contents(self::RECORDS), true, 512, JSON_THROW_ON_ERROR);
        $out = '';
        foreach ($doc['records'] as $r) {
            if ('summary' === $r['kind']) {
                $out .= LineFormat::summary(Level::from($r['level']), $r['app'], $r['where'], $r['doing'], $r['count'], $r['first_ms'], $r['headline'], $r['now_ms'], $r['window_s']);

                continue;
            }
            $out .= LineFormat::record(new Record(
                $r['ts'],
                Level::from($r['level']),
                $r['app'],
                $r['where'],
                $r['doing'],
                Redactor::text($r['error']),
                Redactor::text($r['cause']),
                $r['stack'],
                Redactor::data($r['data']),
            ));
        }

        return $out;
    }

    public function testGoldenLinesAreByteIdentical(): void
    {
        self::assertSame((string) file_get_contents(self::LINES), self::renderGoldens(), 'errorfile/fixtures/golden-lines.txt drifted from LineFormat (R16)');
    }

    public function testGoldenRecordsCoverWhatSection34Lists(): void
    {
        $doc     = json_decode((string) file_get_contents(self::RECORDS), true, 512, JSON_THROW_ON_ERROR);
        $records = $doc['records'];
        self::assertCount(12, $records);
        $levels = array_unique(array_column($records, 'level'));
        sort($levels);
        self::assertSame(['ERROR', 'EXPECTED', 'FATAL', 'WARN'], $levels);
        self::assertContains('summary', array_column($records, 'kind'));
        self::assertContains('', array_column($records, 'error'), 'an empty error');
        $lines = (string) file_get_contents(self::LINES);
        foreach (['[iban]', '[email]', '[REDACTED-KEY]', '[amount]', '[ledger-field refused]', '[redacted]', 'during="running artisan firefly-iii:cron"', '×37 more in the 60s window from 18:41:02: '] as $needle) {
            self::assertStringContainsString($needle, $lines);
        }
        foreach (['GB82WEST12345698765432', 'someone@example.test', '4211.08', str_repeat('0', 32), 'abc123', '/home/someone', 'SQL: select', '134217728'] as $needle) {
            self::assertStringNotContainsString($needle, $lines, 'a redaction needle survived into the goldens');
        }
        self::assertStringContainsString('6.1.20', $lines, 'a version string is not an amount');
        foreach (explode("\n", rtrim($lines, "\n")) as $line) {
            self::assertTrue(str_starts_with($line, '[') || str_starts_with($line, '    '), 'every physical line is a header or a stack line: '.substr($line, 0, 80));
            self::assertStringNotContainsString('forged', str_starts_with($line, '[2026-09-21T00:00:00') ? 'forged' : '', 'a forged header line');
        }
        self::assertTrue(mb_check_encoding($lines, 'UTF-8'));
    }

    public function testTheSection32ExampleLine(): void
    {
        $line = LineFormat::record(new Record(
            ts: '2026-09-21T18:41:02.118Z',
            level: Level::Error,
            app: 'php-web',
            where: 'app/Repositories/Account/AccountRepository.php:212',
            doing: 'handling GET /accounts/show/{account}',
            error: 'Illuminate\Database\QueryException: SQLSTATE[HY000]: General error: 5 database is locked (code=HY000)',
            cause: ' | cause: PDOException: SQLSTATE[HY000]: General error: 5 database is locked (code=HY000)',
            stack: [
                'at FireflyIII\Repositories\Account\AccountRepository->find (app/Repositories/Account/AccountRepository.php:212)',
                'at FireflyIII\Http\Controllers\Account\ShowController->show (app/Http/Controllers/Account/ShowController.php:97)',
            ],
            data: ['net' => 'report', 'route' => 'accounts.show', 'rid' => '3c9d0a1e', 'took_ms' => 38, 'pid' => 4242],
        ));
        self::assertSame(
            '[2026-09-21T18:41:02.118Z] [ERROR] [php-web] [app/Repositories/Account/AccountRepository.php:212] handling GET /accounts/show/{account} — Illuminate\Database\QueryException: SQLSTATE[HY000]: General error: 5 database is locked (code=HY000) {net=report route=accounts.show rid=3c9d0a1e took_ms=38 pid=4242} | cause: PDOException: SQLSTATE[HY000]: General error: 5 database is locked (code=HY000)'."\n"
            .'    at FireflyIII\Repositories\Account\AccountRepository->find (app/Repositories/Account/AccountRepository.php:212)'."\n"
            .'    at FireflyIII\Http\Controllers\Account\ShowController->show (app/Http/Controllers/Account/ShowController.php:97)'."\n",
            $line
        );
    }

    public function testEmptyFieldsAreOmittedWithTheirSeparators(): void
    {
        $line = LineFormat::record(new Record('2026-09-21T00:00:00.000Z', Level::Warn, '', '', ''));
        self::assertSame("[2026-09-21T00:00:00.000Z] [WARN] [?]\n", $line);
    }

    public function testControlCharactersNeverForgeASecondHeader(): void
    {
        $evil = "x\n[2026-09-21T00:00:00.000Z] [ERROR] [php-web] forged\r\x00\x1b\x7f\u{2028}\u{2029}";
        $line = LineFormat::record(new Record('2026-09-21T00:00:00.000Z', Level::Error, "php\nweb", "a\nb", "doing\nit", $evil, " | cause: C\n", [], ["k\n" => "v\n"]));
        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringEndsWith("\n", $line);
        self::assertStringNotContainsString("\u{2028}", $line);
        self::assertStringNotContainsString("\x7f", $line);
        self::assertSame('x', LineFormat::stripControlChars('x'));
        self::assertTrue(mb_check_encoding(LineFormat::stripControlChars("bad \xC3\x28 utf8"), 'UTF-8'), 'mb_scrub runs first');
    }

    public function testRecordCapIsBytesAndCutsOnlyAtAFrameBoundary(): void
    {
        $frames = [];
        for ($i = 0; $i < 30; ++$i) {
            $frames[] = sprintf('at Synthetic\Frame%02d->go (app/Synthetic/Frame%02d.php:%d) %s', $i, $i, $i, str_repeat('é', 200));
        }
        $line = LineFormat::record(new Record('2026-09-21T00:00:00.000Z', Level::Error, 'php-web', 'app/X.php', 'doing it', 'RuntimeException: boom', '', $frames));
        self::assertLessThanOrEqual(8000, strlen($line));
        self::assertStringEndsWith("\n", $line);
        self::assertTrue(mb_check_encoding($line, 'UTF-8'));
        foreach (array_slice(explode("\n", rtrim($line, "\n")), 1) as $frame) {
            self::assertMatchesRegularExpression('/^    at Synthetic\\\Frame\d\d->go \(app\/Synthetic\/Frame\d\d\.php:\d+\) (é){200}$/u', $frame, 'a whole frame');
        }
    }

    public function testAHeaderThatAlonePassesTheCapIsMiddleCutUntilItFits(): void
    {
        $error = 'RuntimeException: START '.str_repeat('€', 3000).' END';
        $cause = str_repeat(' | cause: RuntimeException: '.str_repeat('𝄞', 1990), 5);
        $line  = LineFormat::record(new Record('2026-09-21T00:00:00.000Z', Level::Error, 'php-web', 'app/X.php', 'doing it', $error, $cause, ['at A->b (app/A.php:1)'], ['net' => 'report']));
        self::assertLessThanOrEqual(8000, strlen($line));
        self::assertLessThan(8000, strlen(explode("\n", $line)[0]), 'the header alone fits');
        self::assertTrue(mb_check_encoding($line, 'UTF-8'), 'never half a character');
        self::assertStringContainsString('RuntimeException: START', $line);
        self::assertStringContainsString(' … ', $line);
    }

    public function testTheDataBlock(): void
    {
        self::assertSame('', LineFormat::dataBlock([]));
        self::assertSame('{a=1 b=true c=false d=null e="x y" f="a=b" g="{x}" h="say \"hi\"" i=}', LineFormat::dataBlock(['a' => 1, 'b' => true, 'c' => false, 'd' => null, 'e' => 'x y', 'f' => 'a=b', 'g' => '{x}', 'h' => 'say "hi"', 'i' => '']));
        self::assertSame('{k_e_y_="[array not allowed]" o="[object not allowed]"}', LineFormat::dataBlock(["k e=y{" => [1], 'o' => new \stdClass()]));
        $many = [];
        for ($i = 0; $i < 60; ++$i) {
            $many['k'.$i] = $i;
        }
        self::assertSame(40, substr_count(LineFormat::dataBlock($many), '='));
        $long = LineFormat::dataBlock(['a' => str_repeat('x', 2000)]);
        self::assertSame(1002, mb_strlen($long), 'the inside is capped at 1,000 characters');
        self::assertSame(60, strlen(array_key_first(Redactor::data([str_repeat('k', 100) => 1]))));
    }

    public function testSummaryClockAndIso(): void
    {
        self::assertSame('×3 more in the 60s window from 18:41:02: E: m', LineFormat::summaryText(3, 1790016062118, 'E: m'));
        self::assertSame('×3 more in the 60s window from 18:41:02', LineFormat::summaryText(3, 1790016062118, ''));
        self::assertSame('18:41:02', LineFormat::clock(1790016062118));
        self::assertSame('2026-09-21T18:41:02.118Z', LineFormat::iso(1790016062118));
        self::assertSame('2026-09-21T18:41:02.007Z', LineFormat::iso(1790016062007));
        self::assertSame('ab … yz', LineFormat::capMiddle('abcdefghijklmnopqrstuvwxyz', 7));
        self::assertSame('short', LineFormat::capMiddle('short', 7));
    }

    public function testRedactorDataKeys(): void
    {
        $out = Redactor::data([
            'password' => 'hunter2', 'X-Session' => 'abc', 'csrf' => 'x', 'api_key' => 'k',
            'amount' => '4211.08', 'Opening_Balance' => null, 'payee' => 'Synthetic Payee', 'account_name' => 'n', 'sepa_ci' => 'x', 'iban' => 'x',
            'route' => 'accounts.show', 'count' => 3, 'ok' => true, 'nothing' => null, 'rid' => '3c9d0a1e',
        ]);
        foreach (['password', 'X-Session', 'csrf', 'api_key'] as $k) {
            self::assertSame(Redactor::REDACTED, $out[$k], $k);
        }
        foreach (['amount', 'Opening_Balance', 'payee', 'account_name', 'sepa_ci', 'iban'] as $k) {
            self::assertSame(Redactor::LEDGER_REFUSED, $out[$k], $k);
        }
        self::assertSame(['route' => 'accounts.show', 'count' => '3', 'ok' => 'true', 'nothing' => 'null', 'rid' => '3c9d0a1e'], array_slice($out, 10));
        self::assertSame([], Redactor::data('not an array'));
        self::assertSame(['v' => '[array not allowed]'], Redactor::data(['v' => ['nested' => 'Synthetic Payee']]));
        self::assertSame(['x' => '[amount]'], Redactor::data(['x' => 4211.08]));
    }

    public function testRedactorTextScrubs(): void
    {
        $key = str_repeat('0', 64);
        self::assertSame('k=[REDACTED-KEY] t=[REDACTED-KEY]', Redactor::text('k='.$key.' t='.str_repeat('a', 32)));
        self::assertSame('short hex deadbeef stays', Redactor::text('short hex deadbeef stays'));
        self::assertSame('to [iban] and [iban]', Redactor::text('to GB82WEST12345698765432 and GB82 WEST 1234 5698 7654 32'));
        self::assertSame('mail [email] now', Redactor::text('mail someone@example.test now'));
        self::assertSame('paid [amount] and [amount], version 6.1.20, id 42', Redactor::text('paid 4211.08 and -12.50, version 6.1.20, id 42'));
        self::assertSame('card [number] ok', Redactor::text('card 4111111111111111 ok'));
        self::assertSame('GET /x?token=[redacted]&page=2&API_KEY=[redacted]&access_token=[redacted]', Redactor::text('GET /x?token=abc&page=2&API_KEY=zz&access_token=q'));
        self::assertSame('open ~/T/x.json and ~/a', Redactor::text('open /Users/someone/T/x.json and /home/other/a'));
        self::assertSame('at app/Machine/Audit.php:12', Redactor::text('at '.Redactor::repoBase().'/app/Machine/Audit.php:12'));
        self::assertSame(
            'QueryException: SQLSTATE[HY000]: locked (code=HY000) | cause: PDOException: x (code=HY000)',
            Redactor::text('QueryException: SQLSTATE[HY000]: locked (Connection: sqlite, Database: /d.sqlite, SQL: select * from t where a = (?)) (code=HY000) | cause: PDOException: x (Connection: sqlite, SQL: y) (code=HY000)')
        );
        self::assertSame('', Redactor::text(''));
    }

    public function testRedactorWithholdAndPayloadGuard(): void
    {
        $prefixes = ['JSON value: ', 'The body of the error response is: '];
        self::assertSame('JSON value: [withheld]', Redactor::withhold('JSON value: {"amount":"4211.08"}', $prefixes));
        self::assertSame('Webhook failed. The body of the error response is: [withheld]', Redactor::withhold('Webhook failed. The body of the error response is: <html>Synthetic Payee</html>', $prefixes));
        self::assertSame('Could not find currency.', Redactor::withhold('Could not find currency.', $prefixes));
        self::assertSame(Redactor::PAYLOAD, Redactor::payloadGuard('bad: {"a":1,"b":2,"c":3}'));
        self::assertSame(Redactor::PAYLOAD, Redactor::payloadGuard('req {"body": "x"}'));
        self::assertSame(Redactor::PAYLOAD, Redactor::payloadGuard('{"Headers":{}}'));
        self::assertSame('two {"a":1,"b":2} is fine', Redactor::payloadGuard('two {"a":1,"b":2} is fine'));
    }

    public function testNormalizerFoldsVaryingIdsTogether(): void
    {
        self::assertSame('journal # by <uuid> hex <hex> at <path>', Normalizer::message('journal 1234 by 3f2504e0-4f89-11d3-9a0c-0305e82c3301 hex deadbeef01 at /home/x/y.php'));
        self::assertSame(
            Normalizer::key('php-web', 'app/X.php', 'doing', 'E', 'id 1 of /tmp/a'),
            Normalizer::key('php-web', 'app/X.php', 'doing', 'E', 'id 99 of /var/b'),
        );
        self::assertNotSame(Normalizer::key('php-web', 'app/X.php', 'doing', 'E', 'm'), Normalizer::key('php-api', 'app/X.php', 'doing', 'E', 'm'));
        self::assertNotSame(Normalizer::key('php-web', 'app/X.php', 'doing', 'E', 'm'), Normalizer::key('php-web', 'app/X.php', 'doing', 'F', 'm'));
        self::assertSame(300, mb_strlen(Normalizer::message(str_repeat('é', 400))));
        self::assertSame(32, strlen(Normalizer::key('a', 'b', 'c', 'd', 'e')));
    }
}
