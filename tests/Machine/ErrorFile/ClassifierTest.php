<?php

/*
 * ClassifierTest.php
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

use ErrorException;
use FireflyIII\Machine\ErrorFile\Classifier;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\ErrorFile\ExpectedMessages;
use FireflyIII\Machine\ErrorFile\Level;
use FireflyIII\Machine\ErrorFile\Reporter;
use FireflyIII\Machine\ErrorFile\RequestState;
use FireflyIII\Machine\Http\MachineExceptionHandler;
use FireflyIII\Machine\MachineException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Machine\ErrorFile\Fixtures\ErrorFileSandbox;
use Tests\Machine\ErrorFile\Fixtures\ThrowingJob;
use Tests\Machine\MachineTestCase;

/**
 * pm/error_err.mdx §4.5, §4.6, R7 and the §15 ClassifierTest row: every tier and every expected
 * prefix; the seven answer MachineException codes → nothing; internal/upstream_error → the
 * unwrapped previous; a cause-less internal marked by markEcho() → nothing; a T3 WARN and then an
 * unmarked cause-less internal in the same rid → 2 lines; an envelope echo whose sub-request fault
 * was folded or budget-dropped → still skipped, one whose fault was not admitted → written;
 * status() unchanged with a previous; gate refusals → nothing; deprecation shapes dropped.
 *
 * Every message is synthetic.
 *
 * @internal
 */
#[CoversNothing]
final class ClassifierTest extends MachineTestCase
{
    use ErrorFileSandbox;

    /** One synthetic message per row of ExpectedMessages::PREFIXES, in order (§4.6). */
    private const array SAMPLES = [
        1  => 'Duplicate of transaction #42.',
        2  => 'TransactionJournalFactory::create() caught a duplicate journal in createJournal()',
        3  => 'Could not validate source or destination.',
        4  => 'Both values are NULL, cant create OB destination.',
        5  => 'Source is NULL, always FALSE.',
        6  => 'Destination ID is null, but destination name is also NULL.',
        7  => 'Destination account is not a liability.',
        8  => 'Did not find a liability account, return false.',
        9  => 'Array must have a name, is not the case, return false.',
        10 => 'Could not parse search: "synthetic query".',
        11 => 'No such operator: synthetic_op',
        12 => 'Part "zz" does not match regular expression. Will be skipped.',
        13 => 'Request field "tags" contains a non-scalar value. Value set to NULL.',
        14 => 'Start: could not parse date string "nope" so ignore it.',
        15 => 'Could not parse start or end date in verifyInputDate().',
        16 => 'The cron endpoint has moved to GET /api/v1/cron/[token]',
        17 => 'Login for user "someone@example.test" was locked out.',
        18 => 'Cowardly refuse to send a password reset message to user #3',
        19 => 'Token given is "00000000000000000000000000000000"',
        20 => 'No user in header "REMOTE_USER".',
        21 => 'Cannot access api/v1/users?page=1.',
        22 => 'User 3 is not admin, but tried to store a currency.',
        23 => 'TagOrId: tag not found.',
        24 => 'User has no valid user group submitted or otherwise.',
        25 => 'No user during validate2faCode',
        26 => 'Could not find journal for group 12, so give big fat error.',
        27 => 'GracefulNotFoundHandler cannot handle route with name "index"',
        28 => 'Journal #7 is already a transfer (rule #2).',
        29 => 'Group #7 has more than one transaction in it, cannot convert to deposit.',
        30 => 'Journal #7 has already has "Synthetic Account" as a destination asset (rule #2).',
        31 => 'Cant change source account of journal #7 because no new asset account was found.',
        32 => 'No destination account found for name "Synthetic Payee".',
        33 => 'Transaction must be deposit.',
        34 => 'Private key in DB is unexpectedly an empty string.',
        35 => 'User is not set in repository FireflyIII\Repositories\Synthetic',
        36 => 'Sent 1 emails in 0.2 seconds, return true.',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = $this->resetErrorFile();
    }

    protected function tearDown(): void
    {
        $this->clearErrorFile();
        Reporter::resetForTests();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ the log net ---

    public function testEveryExpectedPrefixHasASampleAndMatchesIt(): void
    {
        self::assertCount(36, ExpectedMessages::PREFIXES, 'the §4.6 table has 36 rows');
        foreach (self::SAMPLES as $row => $message) {
            self::assertSame($row, ExpectedMessages::which($message), "row {$row} must be the first match for its sample");
            self::assertSame(Classifier::TIER_EXPECTED, Classifier::forLogged('error', $message), "row {$row}");
        }
    }

    public function testTheDeliberatelyUnlistedFaultsStayT3(): void
    {
        foreach ([
            'Could not find currency with code "ZZZ"',
            'Found no end balance for account #4',
            'Could not find role "owner"',
            'Could not set locale.',
            'Cannot find journal #9',
            'Journal #9 does not exist. Cannot convert to transfer.',
            'Cannot add [amount] to piggy bank.',
            'This is a test message at the ERROR level.',
        ] as $message) {
            self::assertSame(Classifier::TIER_T3, Classifier::forLogged('error', $message), $message);
        }
    }

    public function testEveryTier(): void
    {
        $e = new RuntimeException('synthetic');
        self::assertSame(Classifier::TIER_IGNORED, Classifier::forLogged('info', 'x'));
        self::assertSame(Classifier::TIER_IGNORED, Classifier::forLogged('debug', 'x'));
        self::assertSame(Classifier::TIER_IGNORED, Classifier::forLogged('warning', 'x'), 'T4 only under VERBOSE');
        self::assertSame(Classifier::TIER_T4, Classifier::forLogged('warning', 'x', null, true));
        self::assertSame(Classifier::TIER_T4, Classifier::forLogged('notice', 'x', null, true));
        self::assertSame(Classifier::TIER_IGNORED, Classifier::forLogged('info', 'x', null, true));
        foreach (Classifier::ERROR_LEVELS as $level) {
            self::assertSame(Classifier::TIER_T2, Classifier::forLogged($level, 'x', $e), $level);
            self::assertSame(Classifier::TIER_T3, Classifier::forLogged($level, 'x'), $level);
        }
        self::assertSame(Classifier::TIER_T3, Classifier::forLogged('ERROR', 'x'), 'the level is case-insensitive');
        self::assertSame(Classifier::TIER_T3, Classifier::forLogged('error', 'x', ['exception' => 'not a throwable']));
        self::assertSame(Classifier::TIER_TRACE, Classifier::forLogged('error', '#0 app/Support/Steam.php(12): x'));
        self::assertSame(Classifier::TIER_MAIL, Classifier::forLogged('error', 'Exception is: {"url":"x"}'));
        self::assertSame(Classifier::TIER_EXPECTED, Classifier::forLogged('warning', self::SAMPLES[34], null, true), 'VERBOSE hygiene rows');
        self::assertSame(Level::Error, Classifier::levelFor(Classifier::TIER_T2));
        self::assertSame(Level::Warn, Classifier::levelFor(Classifier::TIER_T3));
        self::assertSame(Level::Warn, Classifier::levelFor(Classifier::TIER_T4));
        self::assertSame(Level::Expected, Classifier::levelFor(Classifier::TIER_EXPECTED));
        self::assertNull(Classifier::levelFor(Classifier::TIER_TRACE));
    }

    public function testDeprecationShapesAreDropped(): void
    {
        $deprecated = new ErrorException('strlen(): Passing null is deprecated', 0, E_DEPRECATED);
        $user       = new ErrorException('synthetic user deprecation', 0, E_USER_DEPRECATED);
        self::assertSame(Classifier::TIER_DEPRECATION, Classifier::forLogged('error', 'x', $deprecated));
        self::assertSame(Classifier::TIER_DEPRECATION, Classifier::forLogged('warning', 'x', $user, true));
        self::assertSame(Classifier::TIER_DEPRECATION, Classifier::forLogged('warning', 'Synthetic() is deprecated in app/X.php on line 12', null, true));
        self::assertTrue(Classifier::isDeprecation('warning', 'x in y on line 3'));
        self::assertFalse(Classifier::isDeprecation('error', 'x in y on line 3'), 'only a warning-level message ends in " on line <n>"');
        self::assertFalse(Classifier::isDeprecation('error', 'x', new ErrorException('w', 0, E_WARNING)));
    }

    // -------------------------------------------------------------- the handler net ---

    public function testTheSevenAnswerCodesWriteNothing(): void
    {
        $handler = $this->handler();
        $answers = array_diff(array_keys(MachineException::CODES), Classifier::FAULT_CODES);
        self::assertCount(7, $answers);
        foreach ($answers as $code) {
            $e = new MachineException($code, 'Synthetic answer.', null, [], null, new RuntimeException('synthetic cause'));
            self::assertNull(Classifier::forHandler($e, $handler), $code);
            $handler->report($e);
        }
        self::assertSame([], self::lines($this->path));
    }

    public function testInternalAndUpstreamAreUnwrappedToTheirCause(): void
    {
        $cause    = new RuntimeException('Synthetic store failure');
        $internal = MachineException::internal('The operation failed.', null, [], $cause);
        $decision = Classifier::forHandler($internal, $this->handler());
        self::assertNotNull($decision);
        self::assertSame($cause, $decision['throwable']);
        self::assertSame(Level::Error, $decision['level']);
        self::assertSame(['status' => 500, 'code' => 'internal'], $decision['over']);

        // nested MachineExceptions are walked through; a chain of only MachineExceptions keeps the outer one
        $inner = MachineException::conflict('Inner answer.', null, [], );
        $outer = MachineException::internal('Outer.', null, [], MachineException::upstream('Middle.', null, [], $cause));
        self::assertSame($cause, Classifier::unwrap($outer));
        self::assertSame($inner, Classifier::unwrap($inner));
        $bare = MachineException::upstream('No cause.');
        self::assertSame($bare, Classifier::unwrap($bare));

        $this->handler()->report(MachineException::upstream('Firefly failed.', null, [], $cause));
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", $headers));
        self::assertStringContainsString('[ERROR]', $headers[0]);
        self::assertStringContainsString('— RuntimeException: Synthetic store failure', $headers[0]);
        self::assertStringContainsString('status=502 code=upstream_error', $headers[0]);
        self::assertStringContainsString('net=report', $headers[0]);
        self::assertStringContainsString('[tests/Machine/ErrorFile/ClassifierTest.php:', $headers[0], 'where is the origin of the unwrapped cause');
    }

    public function testStatusIsTheSameWithAndWithoutAPrevious(): void
    {
        $cause = new LogicException('synthetic');
        foreach (MachineException::CODES as $code => $status) {
            $without = new MachineException($code, 'x');
            $with    = new MachineException($code, 'x', null, [], null, $cause);
            self::assertSame($status, $without->status(), $code);
            self::assertSame($without->status(), $with->status(), $code);
            self::assertSame($cause, $with->getPrevious());
        }
        self::assertSame(500, MachineException::internal('x', null, [], $cause)->status());
        self::assertSame(502, MachineException::upstream('x', null, [], $cause)->status());
        self::assertSame(418, new MachineException('internal', 'x', null, [], 418, $cause)->status());
    }

    public function testACauseLessInternalMarkedAsAnEchoWritesNothing(): void
    {
        $echo = MachineException::internal('Operation 1 ("probe.boom") failed: The operation failed.');
        ErrorFile::markEcho($echo);
        $this->handler()->report($echo);
        self::assertSame([], self::lines($this->path));

        // a marked echo WITH a cause is still unwrapped and written (the skip is for cause-less echoes)
        $withCause = MachineException::internal('x', null, [], new RuntimeException('Synthetic real fault'));
        ErrorFile::markEcho($withCause);
        $this->handler()->report($withCause);
        self::assertCount(1, self::headers($this->path));
    }

    public function testAT3WarnDoesNotSuppressALaterUnmarkedInternalInTheSameRid(): void
    {
        RequestState::current()->rid = 'abcd1234';
        Reporter::message(Level::Warn, 'app/Support/Synthetic.php:1', 'running the app', 'Log::error: Synthetic bare line', ['net' => 'log'], 'log');
        $this->handler()->report(MachineException::internal('A write handler did not return a WriteResult.'));
        $headers = self::headers($this->path);
        self::assertCount(2, $headers, implode("\n", $headers));
        self::assertStringContainsString('[WARN]', $headers[0]);
        self::assertStringContainsString('[ERROR]', $headers[1]);
        self::assertStringContainsString('FireflyIII\Machine\MachineException: A write handler did not return a WriteResult. (code=500)', $headers[1]);
    }

    public function testAnEchoWhoseSubRequestFaultWasFoldedIsStillSkipped(): void
    {
        $handler = $this->handler();
        // the same fault in two earlier dispatches: the second one is L1-folded, still admitted
        foreach ([1, 2] as $n) {
            RequestState::enterNested();
            $handler->report(new RuntimeException('Synthetic op failure'));
            RequestState::leaveNested();
            self::assertTrue(RequestState::lastDispatchCovered(), "dispatch {$n}");
        }
        $echo = MachineException::internal('Operation 0 failed.');
        ErrorFile::markEcho($echo);
        $handler->report($echo);
        self::assertCount(1, self::headers($this->path), 'one line for the op fault, none for the folded repeat or the echo');
    }

    public function testAnEchoWhoseSubRequestFaultWasBudgetDroppedIsStillSkipped(): void
    {
        config(['errorfile.file_budget_per_minute' => 1]);
        $handler = $this->handler();
        $handler->report(new RuntimeException('Synthetic first fault fills the budget'));
        RequestState::enterNested();
        $handler->report(new RuntimeException('Synthetic second fault is dropped'));
        RequestState::leaveNested();
        self::assertTrue(RequestState::lastDispatchCovered(), 'a budget-dropped record is admitted');
        $echo = MachineException::internal('Operation 0 failed.');
        ErrorFile::markEcho($echo);
        $handler->report($echo);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", $headers));
        self::assertStringContainsString('Synthetic first fault', $headers[0]);
    }

    public function testAnEchoWhoseSubRequestFaultWasNotAdmittedIsWritten(): void
    {
        $handler = $this->handler();
        RequestState::enterNested();
        $handler->report(new AuthenticationException('The user is not logged in but must be.'));   // declined by dontReport
        RequestState::leaveNested();
        self::assertFalse(RequestState::lastDispatchCovered());
        $echo = MachineException::internal('Operation 0 failed.');
        if (RequestState::lastDispatchCovered()) {
            ErrorFile::markEcho($echo);
        }
        $handler->report($echo);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, 'the echo is the only record, so it is written');
        self::assertStringContainsString('Operation 0 failed.', $headers[0]);
    }

    public function testUpstreamsDontReportAndTheHttpOverride(): void
    {
        $handler = $this->handler();
        self::assertNull(Classifier::forHandler(new AuthenticationException(), $handler));
        self::assertNull(Classifier::forHandler(ValidationException::withMessages(['name' => ['x']]), $handler));
        self::assertNull(Classifier::forHandler(new NotFoundHttpException(), $handler));
        self::assertNull(Classifier::forHandler(new HttpException(503, 'maintenance'), $handler), '503 is the maintenance answer');
        self::assertNull(Classifier::forHandler(new HttpException(422), $handler));
        self::assertNotNull(Classifier::forHandler(new HttpException(500, 'x'), $handler), 'abort(500) is visible despite dontReport');
        self::assertNotNull(Classifier::forHandler(new HttpException(502, 'x'), $handler));
        self::assertSame('report', Classifier::forHandler(new RuntimeException('x'), $handler)['net'] ?? null);
        $fatal = Classifier::forHandler(new FatalError('Synthetic fatal', 0, ['type' => E_ERROR, 'message' => 'Synthetic fatal', 'file' => __FILE__, 'line' => 1]), $handler);
        self::assertSame(Level::Fatal, $fatal['level'] ?? null);
        self::assertSame('shutdown', $fatal['net'] ?? null);
    }

    public function testTheWrittenSetIsFilledForEveryThrowableTheHandlerSees(): void
    {
        $handler = $this->handler();
        $handler->report(new AuthenticationException('The user is not logged in but must be.'));
        $handler->report(MachineException::conflict('Synthetic conflict 12.'));
        $state = RequestState::current();
        self::assertNotNull($state);
        self::assertTrue($state->wasWritten('The user is not logged in but must be.'));
        self::assertTrue($state->wasWritten('Synthetic conflict #.'), 'normalised');
        self::assertSame([], self::lines($this->path));
    }

    public function testGateRefusalsWriteNothing(): void
    {
        $this->machine('GET', '/whoami', [], ['X-Firefly-Machine-Key' => str_repeat('0', 64)]);
        $this->machine('GET', '/whoami', [], [], ['REMOTE_ADDR' => '10.0.0.9']);
        $this->machine('GET', '/whoami', [], [], [], 'evil.example.test');
        $this->machine('POST', '/categories', ['name' => 'x']);
        self::assertSame([], self::lines($this->path));
        self::assertFileDoesNotExist($this->path);
    }

    public function testASyncJobFaultIsPhpQueueAfterTheFrameIsPopped(): void
    {
        try {
            dispatch(new ThrowingJob());
            self::fail('the sync driver rethrows to the dispatcher');
        } catch (RuntimeException $e) {
            self::assertSame('Synthetic job failure', $e->getMessage());
        }
        $state = RequestState::current();
        self::assertNotNull($state);
        self::assertNull($state->openJob(), 'JobAttempted popped the frame');
        self::assertSame('ThrowingJob', $state->jobFailure($e)['job'] ?? null, 'JobExceptionOccurred recorded the failure');
        $this->handler()->report($e);
        $headers = self::headers($this->path);
        self::assertCount(1, $headers, implode("\n", $headers));
        self::assertStringContainsString('[ERROR] [php-queue] [tests/Machine/ErrorFile/Fixtures/ThrowingJob.php:', $headers[0]);
        self::assertStringContainsString('] running job ThrowingJob — RuntimeException: Synthetic job failure {net=report during=', $headers[0]);

        // a later, unrelated fault in the same process is not stamped php-queue
        $this->handler()->report(new RuntimeException('Synthetic later fault'));
        self::assertStringContainsString('[ERROR] [php-artisan]', self::headers($this->path)[1] ?? '');
    }

    private function handler(): MachineExceptionHandler
    {
        $handler = app(ExceptionHandler::class);
        self::assertInstanceOf(MachineExceptionHandler::class, $handler);

        return $handler;
    }
}
