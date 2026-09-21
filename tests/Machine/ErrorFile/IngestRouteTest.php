<?php

/*
/*
 * IngestRouteTest.php
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

use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\ErrorFile\Http\ErrorReportGate;
use FireflyIII\Machine\ErrorFile\Ingest;
use FireflyIII\Machine\ErrorFile\Paths;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.10 and the §15 IngestRouteTest row, end to end through the HTTP kernel:
 * each guard in order → 204 and no write; the keepalive fetch (real Origin) and the beacon
 * (`Origin: null` + `Sec-Fetch-Site: same-origin`) → one line each; a cross-site beacon and another
 * localhost port → nothing; a bad or missing sid counts against `_`; 60 events → 50 lines and, after
 * the window, exactly one `dropped 10 browser reports over the ingest limits`; the closed
 * where/doing rules; errorfile/fixtures/browser-batch.json survives byte for byte; forged newlines
 * give one header; no Set-Cookie. All data is synthetic.
 *
 * A PHPUnit request has no php://input, so the body reaches Ingest through its useInput() seam; the
 * same bytes are the request's content and its Content-Length.
 *
 * @internal
 */
#[CoversNothing]
final class IngestRouteTest extends MachineTestCase
{
    use ErrorFileSandbox;

    /** A window start (ms), so +61 s is always a closed window. */
    private const int T0 = 1_790_016_060_000;

    private const string GOLDEN_EVENT = '{"ts":"2026-09-21T18:46:13.270Z","level":"ERROR","where":"/transactions/create","doing":"calling POST api/v1/transactions","error":"AxiosError: Request failed with status code 500 (code=ERR_BAD_RESPONSE)","cause":"","stack":"at build/assets/create-Bq3xT9aa.js:1:2044","data":{"status":500,"net":"axios"}}';

    private const string GOLDEN_LINES = "[2026-09-21T18:46:13.270Z] [ERROR] [web] [/transactions/create] calling POST api/v1/transactions — AxiosError: Request failed with status code 500 (code=ERR_BAD_RESPONSE) {status=500 net=axios via=php-web}\n    at build/assets/create-Bq3xT9aa.js:1:2044\n";

    private string $path = '';
    private int $now     = self::T0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->resetErrorFile();
        $this->now  = self::T0;
        ErrorFile::useClock(fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        Ingest::useInput(null);
        ErrorFile::useClock(null);
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testTheKeepaliveFetchWritesOneLine(): void
    {
        $response = $this->report(self::batch([self::GOLDEN_EVENT]));
        $this->assertNothingAnswered($response);
        self::assertSame(self::GOLDEN_LINES, (string) file_get_contents($this->path), 'the golden browser line of §3.4');
        self::assertSame(0o600, fileperms($this->path) & 0o777);
    }

    public function testTheBeaconWritesOneLine(): void
    {
        $response = $this->report(self::batch([self::GOLDEN_EVENT]), ['Origin' => 'null']);
        $this->assertNothingAnswered($response);
        self::assertCount(1, self::headers($this->path));
    }

    public function testAnApplicationJsonBodyIsStillAccepted(): void
    {
        $this->assertNothingAnswered($this->report(self::batch([self::GOLDEN_EVENT]), ['Content-Type' => 'application/json']));
        self::assertCount(1, self::headers($this->path));
    }

    public function testNoSecFetchSiteAndNoOriginIsAccepted(): void
    {
        $this->report(self::batch([self::GOLDEN_EVENT]), ['Origin' => null, 'Sec-Fetch-Site' => null]);
        self::assertCount(1, self::headers($this->path));
    }

    public function testEachGuardInOrderAnswers204AndWritesNothing(): void
    {
        $good  = self::batch([self::GOLDEN_EVENT]);
        $cases = [
            'guard 1: not loopback'                     => fn (): TestResponse => $this->report($good, [], ['REMOTE_ADDR' => '10.1.2.3']),
            'guard 1: a forwarded loopback is ignored'  => fn (): TestResponse => $this->report($good, ['X-Forwarded-For' => '127.0.0.1'], ['REMOTE_ADDR' => '192.0.2.7']),
            'guard 2: a foreign Host'                   => fn (): TestResponse => $this->report($good, ['Origin' => null], [], 'POST', 'evil.test:7373'),
            'guard 2: a cross-site fetch'               => fn (): TestResponse => $this->report($good, ['Sec-Fetch-Site' => 'cross-site']),
            'guard 2: a cross-site beacon'              => fn (): TestResponse => $this->report($good, ['Origin' => 'null', 'Sec-Fetch-Site' => 'cross-site']),
            'guard 2: a same-site beacon'               => fn (): TestResponse => $this->report($good, ['Origin' => 'null', 'Sec-Fetch-Site' => 'same-site']),
            'guard 2: Origin null alone'                => fn (): TestResponse => $this->report($good, ['Origin' => 'null', 'Sec-Fetch-Site' => null]),
            'guard 2: another localhost app'            => fn (): TestResponse => $this->report($good, ['Origin' => 'http://localhost:8000', 'Sec-Fetch-Site' => null]),
            'guard 2: the same host on another port'    => fn (): TestResponse => $this->report($good, ['Origin' => 'http://127.0.0.1:8000']),
            'guard 2: a foreign origin'                 => fn (): TestResponse => $this->report($good, ['Origin' => 'http://evil.test:7373']),
            'guard 2: https'                            => fn (): TestResponse => $this->report($good, ['Origin' => 'https://127.0.0.1:7373']),
            'guard 2: an origin with a path'            => fn (): TestResponse => $this->report($good, ['Origin' => 'http://127.0.0.1:7373/x']),
            'guard 3: GET'                              => fn (): TestResponse => $this->report($good, [], [], 'GET'),
            'guard 3: PUT'                              => fn (): TestResponse => $this->report($good, [], [], 'PUT'),
            'guard 4: text/html'                        => fn (): TestResponse => $this->report($good, ['Content-Type' => 'text/html']),
            'guard 4: a form post'                      => fn (): TestResponse => $this->report($good, ['Content-Type' => 'application/x-www-form-urlencoded']),
            'guard 5: over the cap'                     => fn (): TestResponse => $this->report($good, [], ['CONTENT_LENGTH' => '65537']),
            'guard 5: no length'                        => fn (): TestResponse => $this->report($good, [], ['CONTENT_LENGTH' => '']),
            'guard 5: a body longer than it said'       => fn (): TestResponse => $this->report(str_pad($good, 65_537, ' '), [], ['CONTENT_LENGTH' => '100']),
            'guard 6: not JSON'                         => fn (): TestResponse => $this->report('{"sid":"abcdefgh","events":[{'),
            'guard 6: no events'                        => fn (): TestResponse => $this->report('{"sid":"abcdefgh"}'),
            'guard 6: events not a list'                => fn (): TestResponse => $this->report('{"sid":"abcdefgh","events":{"a":'.self::GOLDEN_EVENT.'}}'),
            'guard 6: too deep'                         => fn (): TestResponse => $this->report(self::batch(['{"level":"ERROR","data":{"a":{"b":{"c":{"d":{"e":{"f":1}}}}}}}'])),
            'guard 8: an EXPECTED event is dropped'     => fn (): TestResponse => $this->report(self::batch(['{"level":"EXPECTED","doing":"settling a promise","error":"Error: x"}'])),
        ];
        foreach ($cases as $name => $send) {
            $response = $send();
            $this->assertNothingAnswered($response, $name);
            self::assertFileDoesNotExist($this->path, $name);
        }
    }

    public function testIsSameOriginComparesTheWholeOrigin(): void
    {
        $request = Request::create('http://127.0.0.1:7373/error-report', 'POST');
        self::assertTrue(ErrorReportGate::isSameOrigin('http://127.0.0.1:7373', $request));
        self::assertFalse(ErrorReportGate::isSameOrigin('http://127.0.0.1:7374', $request));
        self::assertFalse(ErrorReportGate::isSameOrigin('http://localhost:7373', $request), 'an allowed host name is not the same origin');
        self::assertFalse(ErrorReportGate::isSameOrigin('https://127.0.0.1:7373', $request));
        self::assertFalse(ErrorReportGate::isSameOrigin('null', $request));
        self::assertFalse(ErrorReportGate::isSameOrigin('', $request));
        $bare = Request::create('http://localhost/error-report', 'POST');
        self::assertTrue(ErrorReportGate::isSameOrigin('http://localhost:80', $bare), 'the default port is filled in');
        self::assertTrue(ErrorReportGate::isSameOrigin('http://localhost', $bare));
        $v6 = Request::create('http://[::1]:7373/error-report', 'POST');
        self::assertTrue(ErrorReportGate::isSameOrigin('http://[::1]:7373', $v6));
    }

    public function testABadOrMissingSidCountsAgainstTheFixedBucket(): void
    {
        $this->report('{"sid":"NOT-A-SID!","events":['.self::event('a').']}');
        $this->report('{"events":['.self::event('b').']}');
        $this->report('{"sid":12345678,"events":['.self::event('c').']}');
        $this->report('{"sid":"abcdefghi","events":['.self::event('d').']}');
        self::assertCount(4, self::headers($this->path));
        $fold = (string) file_get_contents((string) Paths::foldFile($this->path));
        $doc  = json_decode($fold, true);
        self::assertSame(['_' => 4], $doc['r']['c'], 'every bad sid is one bucket');
        self::assertStringNotContainsString('NOT-A-SID', $fold);
        self::assertStringNotContainsString('abcdefghi', $fold);
    }

    public function testSixtyEventsGiveFiftyLinesAndOneDropWarn(): void
    {
        $events = [];
        for ($i = 0; $i < 60; ++$i) {
            $events[] = self::event(self::letters($i));
        }
        $this->assertNothingAnswered($this->report(self::batch($events)));
        self::assertCount(50, self::headers($this->path));

        $this->now = self::T0 + 61_000;
        $this->report(self::batch([self::event('after')]));
        $headers = self::headers($this->path);
        self::assertCount(52, $headers);
        $drops = array_values(array_filter($headers, static fn (string $l): bool => str_contains($l, 'browser reports over the ingest limits')));
        self::assertCount(1, $drops);
        self::assertMatchesRegularExpression('/^\[2026-[^\]]+Z\] \[WARN\] \[php-web\] \[app\/Machine\/ErrorFile\/Ingest\.php\] receiving browser error reports — dropped 10 browser reports over the ingest limits \{via=php-web pid=\d+\}$/u', $drops[0]);

        $this->now = self::T0 + 125_000;
        $this->report(self::batch([self::event('later')]));
        self::assertCount(1, array_filter(self::headers($this->path), static fn (string $l): bool => str_contains($l, 'over the ingest limits')), 'one WARN per window with drops');
    }

    public function testThePerSidAndGlobalRatesAreEnforced(): void
    {
        config(['errorfile.ingest.rate_client' => 15, 'errorfile.ingest.rate_global' => 25]);
        $a = [];
        $b = [];
        for ($i = 0; $i < 20; ++$i) {
            $a[] = self::event('a'.self::letters($i));
            $b[] = self::event('b'.self::letters($i));
        }
        $this->report(self::batch($a, 'aaaaaaaa'));
        $this->report(self::batch($b, 'bbbbbbbb'));
        self::assertCount(25, self::headers($this->path), '15 from the first sid, then 10 up to the global cap');

        $this->now = self::T0 + 61_000;
        $this->report(self::batch([self::event('after')], 'cccccccc'));
        self::assertCount(1, array_filter(self::headers($this->path), static fn (string $l): bool => str_contains($l, 'dropped 15 browser reports over the ingest limits')));
    }

    public function testWhereAndDoingFollowTheClosedRules(): void
    {
        $cases = [
            ['/tags/show/Synthetic Tag', 'running page script', '/tags/show/{x}', 'running page script'],
            ['/transactions/show/12?x=1#y', 'settling a promise', '/transactions/show/{x}', 'settling a promise'],
            ['app/Http/Controllers/HomeController.php', 'stealing the ledger', '{x}', '{x}'],
            ['resources/assets/v3/js/support/error-file-app.js', 'attaching the jQuery net', 'resources/assets/v3/js/support/error-file-app.js', 'attaching the jQuery net'],
            ['/', 'calling GET api/v1/tags/groceries', '/', 'calling GET api/v1/tags/{x}'],
            ['/budgets', 'calling FETCH api/v1/tags', '/budgets', '{x}'],
            ['/budgets', 'calling GET /accounts/show/Synthetic Payee?page=2', '/budgets', 'calling GET /accounts/show/{x}'],
            ['', 'Running page script', '{x}', '{x}'],
        ];
        $events = [];
        foreach ($cases as $i => [$where, $doing]) {
            $events[] = json_encode(['level' => 'ERROR', 'where' => $where, 'doing' => $doing, 'error' => 'Error: synthetic case '.self::letters($i)]);
        }
        $this->report(self::batch($events));
        $got = self::whereAndDoing($this->path);
        self::assertCount(count($cases), $got);
        foreach ($cases as $i => [$where, $doing, $wantWhere, $wantDoing]) {
            self::assertSame([$wantWhere, $wantDoing], $got[$i], sprintf('%s / %s', $where, $doing));
        }
        self::assertStringNotContainsString('Synthetic Tag', (string) file_get_contents($this->path));
        self::assertStringNotContainsString('Synthetic Payee', (string) file_get_contents($this->path));
    }

    public function testTheBrowserBatchFixtureSurvivesByteForByte(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__, 3).'/errorfile/fixtures/browser-batch.json');
        $events  = json_decode($fixture, true)['events'];
        $this->assertNothingAnswered($this->report($fixture));
        $want = array_map(static fn (array $e): array => [$e['where'], $e['doing']], $events);
        self::assertSame($want, self::whereAndDoing($this->path));
        foreach (self::headers($this->path) as $line) {
            self::assertStringStartsWith('[2026-09-21T18:46:28.270Z] [', $line, 'the client ts is kept');
            self::assertStringContainsString('] [web] [', $line);
            self::assertStringContainsString('via=php-web}', $line);
        }
    }

    public function testForgedNewlinesGiveOneHeader(): void
    {
        $this->report(self::batch([json_encode([
            'level' => 'ERROR', 'where' => "/budgets\n[2026-09-21T00:00:00.000Z] [ERROR] [php-web] [x] forged", 'doing' => 'running page script',
            'error' => "Error: x\n[2026-09-21T00:00:00.000Z] [ERROR] [php-web] [x] forged\u{2028}[x]", 'cause' => "\r\n[2026-09-21T00:00:00.000Z] [FATAL] forged",
            'stack' => "at a.js:1:1\n\n[2026-09-21T00:00:00.000Z] [ERROR] [php-web] forged", 'data' => ["k\ney" => "v\n[2026-09-21T00:00:00.000Z] [ERROR]"],
        ])]));
        $lines = self::lines($this->path);
        self::assertCount(1, self::headers($this->path), implode("\n", $lines));
        self::assertCount(3, $lines);
        foreach ($lines as $line) {
            self::assertTrue(str_starts_with($line, '[2026-09-21T18:4') || str_starts_with($line, '    '), $line);
        }
        self::assertStringContainsString('] [web] [/{x}] running page script — Error: x [2026', $lines[0]);
        self::assertStringContainsString(' | cause: [2026-09-21T00:00:00.000Z] [FATAL] forged', $lines[0]);
    }

    public function testTheServerReRedactsAndStampsEveryEvent(): void
    {
        $this->report(self::batch([json_encode([
            'ts' => 'yesterday', 'level' => 'FATAL', 'where' => '/budgets', 'doing' => 'running page script', 'app' => 'php-machine',
            'error' => 'Error: failed for someone@example.test paying 4211.08 with key '.str_repeat('0', 64).' at GB82WEST12345698765432',
            'data'  => ['amount' => '4211.08', 'token' => 'synthetic', 'status' => 500, 'via' => 'forged', 'nested' => ['a' => 1]],
        ])]));
        $text = (string) file_get_contents($this->path);
        foreach (['someone@example.test', '4211.08', str_repeat('0', 32), 'GB82WEST12345698765432', 'synthetic', 'forged', 'php-machine'] as $needle) {
            self::assertStringNotContainsString($needle, $text);
        }
        self::assertStringStartsWith('[2026-09-21T18:41:00.000Z] [ERROR] [web] [/budgets] running page script — Error: failed for [email] paying [amount] with key [REDACTED-KEY] at [iban]', $text, 'ts → now, FATAL → ERROR, app stamped');
        self::assertStringContainsString('{amount="[ledger-field refused]" token=[redacted] status=500 nested="[array not allowed]" via=php-web}', $text);
    }

    public function testTheFieldCapsAreReapplied(): void
    {
        $this->report(self::batch([json_encode([
            'level' => 'ERROR', 'where' => '/budgets', 'doing' => 'running page script',
            'error' => 'Error: '.str_repeat('a', 5_000), 'cause' => 'Error: '.str_repeat('b', 5_000),
            'stack' => implode("\n", array_map(static fn (int $i): string => 'at f'.$i.' ('.str_repeat('c', 900).')', range(1, 40))),
        ])]));
        $lines = self::lines($this->path);
        self::assertCount(1 + 24, $lines, 'at most 24 stack lines');
        foreach (array_slice($lines, 1) as $frame) {
            self::assertLessThanOrEqual(4 + 400, mb_strlen($frame));
        }
        self::assertLessThanOrEqual(8_000, strlen(implode("\n", $lines)) + 1);
    }

    public function testNoPathMeansNothingIsWritten(): void
    {
        Paths::usePath(null);
        $this->assertNothingAnswered($this->report(self::batch([self::GOLDEN_EVENT])));
        self::assertFileDoesNotExist($this->path);
        self::assertDirectoryDoesNotExist(dirname($this->path));
    }

    public function testAFaultInsideTheRouteIsWrittenAndStillAnswers204(): void
    {
        $response = $this->report(self::batch([self::GOLDEN_EVENT]));
        Ingest::useInput(static function (): string {
            throw new RuntimeException('Synthetic ingest failure');
        });
        $content  = self::batch([self::GOLDEN_EVENT]);
        $server   = ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_ORIGIN' => 'http://127.0.0.1:7373', 'HTTP_SEC_FETCH_SITE' => 'same-origin', 'CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => (string) strlen($content)];
        $response = $this->call('POST', 'http://127.0.0.1:7373/error-report', [], [], [], $server, $content);
        $this->assertNothingAnswered($response);
        $headers = self::headers($this->path);
        self::assertCount(2, $headers);
        self::assertStringContainsString('[ERROR] [php-web] [app/Machine/ErrorFile/Http/ErrorReportController.php] receiving browser error reports — RuntimeException: Synthetic ingest failure', $headers[1]);
    }

    public function testTheRouteIsOutsideTheWebAndApiGroups(): void
    {
        $route = app('router')->getRoutes()->getByName('fork.error-report');
        self::assertNotNull($route);
        self::assertSame('error-report', $route->uri());
        self::assertSame([ErrorReportGate::class], $route->gatherMiddleware());
    }

    private function assertNothingAnswered(TestResponse $response, string $label = ''): void
    {
        self::assertSame(204, $response->getStatusCode(), $label);
        self::assertSame('', (string) $response->getContent(), $label);
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), $label);
        self::assertFalse($response->headers->has('Set-Cookie'), $label.': no cookie, no session');
        self::assertSame([], $response->headers->getCookies(), $label);
    }

    /**
     * One report. Defaults: a loopback peer, Host 127.0.0.1:7373, the keepalive fetch's headers
     * (`Origin: http://127.0.0.1:7373`, `Sec-Fetch-Site: same-origin`, text/plain), and a
     * Content-Length that matches the body. A null header value removes that default.
     *
     * @param array<string, null|string> $headers
     * @param array<string, string>      $server
     */
    private function report(string $content, array $headers = [], array $server = [], string $method = 'POST', string $host = '127.0.0.1:7373'): TestResponse
    {
        Ingest::useInput(static fn (): string => $content);
        $headers = array_filter(array_merge([
            'Origin'         => 'http://127.0.0.1:7373',
            'Sec-Fetch-Site' => 'same-origin',
            'Content-Type'   => 'text/plain;charset=UTF-8',
        ], $headers), static fn (?string $v): bool => null !== $v);
        $server  = array_merge(['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_LENGTH' => (string) strlen($content)], $this->transformHeadersToServerVars($headers), $server);

        return $this->call($method, sprintf('http://%s/error-report', $host), [], [], [], $server, $content);
    }

    /** @param list<string> $events JSON objects */
    private static function batch(array $events, string $sid = 'k3x9q2ab'): string
    {
        return sprintf('{"sid":"%s","events":[%s]}', $sid, implode(',', $events));
    }

    /** A distinct synthetic event (distinct letters, so nothing folds). */
    private static function event(string $tag): string
    {
        return (string) json_encode(['level' => 'ERROR', 'where' => '/budgets', 'doing' => 'running page script', 'error' => 'Error: synthetic failure '.$tag, 'data' => ['net' => 'window']]);
    }

    /** 0 → "a", 25 → "z", 26 → "ba"…: digits would be normalised away by the fold key. */
    private static function letters(int $n): string
    {
        $out = '';
        do {
            $out = chr(97 + $n % 26).$out;
            $n   = intdiv($n, 26);
        } while ($n > 0);

        return $out;
    }

    /** [where, doing] of every header line, in order. @return list<array{0: string, 1: string}> */
    private static function whereAndDoing(string $path): array
    {
        $out = [];
        foreach (self::headers($path) as $line) {
            self::assertSame(1, preg_match('/^\[[^\]]+\] \[(?:WARN|ERROR)\] \[web\] \[(.*?)\] (.*?) — /u', $line, $m), $line);
            $out[] = [$m[1], $m[2]];
        }

        return $out;
    }
}
