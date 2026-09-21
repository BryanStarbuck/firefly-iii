<?php

/*
 * RedactionTest.php
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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Tests\Machine\Core\Fixtures\ProbeRoutes;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\ErrorFile\Fixtures\LogChannelSandbox;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §12 and the §15 RedactionTest row, end to end through every PHP entry: the
 * synthetic corpus — a 64-hex and a 32-hex key, an IBAN, an email, `4211.08`, a `?token=` URL, a
 * QueryException with bindings [4211.08, 'Synthetic Payee'], a POST `amount`, a trace arg with
 * zend.exception_ignore_args=0, a log context array and a `JSON value: {…}` message — none of it
 * may appear in error.err OR error.fold. Every value here is invented (§12, open-source safety).
 *
 * @internal
 */
#[CoversNothing]
final class RedactionTest extends MachineTestCase
{
    use ErrorFileSandbox;
    use LogChannelSandbox;

    private const string WHERE = 'tests/Machine/ErrorFile/RedactionTest.php';

    private const string IBAN   = 'GB82WEST12345698765432';
    private const string EMAIL  = 'someone@example.test';
    private const string AMOUNT = '4211.08';
    private const string PAYEE  = 'Synthetic Payee';
    private const string URL_TOKEN = 'sEcReTtOkEnVaLuE';

    protected array $machineFamilies = [ProbeRoutes::class];

    private string $path = '';

    private string $iniBefore = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path      = $this->resetErrorFile();
        $this->iniBefore = (string) ini_get('zend.exception_ignore_args');
        $this->useSandboxLogChannel('redaction');
    }

    protected function tearDown(): void
    {
        ini_set('zend.exception_ignore_args', $this->iniBefore);
        $this->clearErrorFile();
        parent::tearDown();
    }

    public function testNoNeedleReachesTheErrorFileOrTheFoldSidecar(): void
    {
        $key64 = str_repeat('0', 64);
        $key32 = str_repeat('0f', 16);

        // 1. an explicit site: keys, IBAN, email, amount and a ?token= URL in the message, ledger keys in data
        ErrorFile::for(self::WHERE)->caught('storing a synthetic row', new RuntimeException(sprintf(
            'Synthetic failure key=%s cli=%s iban %s mail %s amount %s at http://127.0.0.1/x?token=%s&page=2',
            $key64, $key32, self::IBAN, self::EMAIL, self::AMOUNT, self::URL_TOKEN,
        )), ['amount' => self::AMOUNT, 'payee' => self::PAYEE, 'api_key' => $key64, 'row' => 3]);

        // 2. a QueryException with bindings (masked at the source, and the SQL cut)
        try {
            DB::select('select * from synthetic_missing_table where amount = ? and payee = ?', [self::AMOUNT, self::PAYEE]);
            self::fail('the query should have failed');
        } catch (QueryException $e) {
            ErrorFile::for(self::WHERE)->caught('querying a synthetic table', $e);
        }

        // 3. a trace arg with zend.exception_ignore_args=0: the frame's args are never read
        ini_set('zend.exception_ignore_args', '0');
        try {
            self::throwWith(self::PAYEE, self::AMOUNT, self::IBAN);
        } catch (RuntimeException $e) {
            self::assertStringContainsString(self::PAYEE, var_export($e->getTrace()[0]['args'] ?? [], true), 'the precondition: the trace does carry the args');
            ErrorFile::for(self::WHERE)->caught('calling a function with ledger args', $e);
        }

        // 4. the log net: a context array (never read), a JSON value (withheld), a structured payload
        Log::error('Synthetic context line', ['amount' => self::AMOUNT, 'payee' => self::PAYEE, 'iban' => self::IBAN]);
        Log::error('JSON value: {"amount":"'.self::AMOUNT.'","payee":"'.self::PAYEE.'"}');
        Log::error('Synthetic request {"url":"/x","post":{"amount":"'.self::AMOUNT.'"},"payee":"'.self::PAYEE.'"}');
        Log::error('Synthetic T2 line', ['exception' => new RuntimeException('Synthetic T2 for '.self::EMAIL), 'payee' => self::PAYEE]);

        // 5. a routed request whose query carries ledger values and a token; the body is never read
        $this->operatorUser();
        // (a `token` in a plane query is refused before routing — stealth 404 — so it rides in the message above)
        $this->assertPlaneError($this->machine('GET', '/_probe/throw/boom', ['amount' => self::AMOUNT, 'payee' => self::PAYEE]), 500, 'internal');
        app('router')->post('/_redaction_probe/{id}', static function (): never {
            throw new RuntimeException('Synthetic routed failure');
        })->name('test.redaction-probe');
        $response = $this->call('POST', 'http://127.0.0.1:7373/_redaction_probe/12?amount='.self::AMOUNT, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1'], (string) json_encode(['amount' => self::AMOUNT, 'description' => self::PAYEE, 'iban' => self::IBAN]));
        self::assertSame(500, $response->getStatusCode());

        $raw  = (string) file_get_contents($this->path);
        $fold = (string) @file_get_contents(dirname($this->path).'/error.fold');
        self::assertGreaterThanOrEqual(9, count(self::headers($this->path)), $raw);
        self::assertNotSame('', $fold, 'the sidecar exists and is checked too');

        foreach (['error.err' => $raw, 'error.fold' => $fold] as $name => $text) {
            foreach ([$key64, $key32, self::IBAN, self::EMAIL, self::AMOUNT, self::PAYEE, self::URL_TOKEN, 'select * from synthetic_missing_table'] as $needle) {
                self::assertStringNotContainsString($needle, $text, sprintf('%s leaked into %s', $needle, $name));
            }
        }
        foreach (['[REDACTED-KEY]', '[iban]', '[email]', '[amount]', 'token=[redacted]', '[ledger-field refused]', 'amount="[ledger-field refused]"', 'api_key=[redacted]', 'JSON value: [withheld]', '[structured payload withheld]'] as $marker) {
            self::assertStringContainsString($marker, $raw, 'the scrub ran: '.$marker);
        }
        self::assertStringContainsString('Log::error: Synthetic context line {net=log', $raw, 'the context array is not read');
        self::assertStringContainsString('[ERROR] [php-machine] [tests/Machine/Core/Fixtures/ProbeController.php:', $raw);
        self::assertStringContainsString('handling POST /_redaction_probe/{id} — RuntimeException: Synthetic routed failure {net=report route=test.redaction-probe', $raw, 'the route template, never the raw path');
        self::assertSame(0o600, fileperms($this->path) & 0o777);
    }

    private static function throwWith(string $payee, string $amount, string $iban): never
    {
        throw new RuntimeException('Synthetic failure with ledger arguments');
    }
}
