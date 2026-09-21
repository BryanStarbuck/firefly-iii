<?php

/*
 * LogListener.php
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

use Illuminate\Log\Events\MessageLogged;
use Throwable;

/**
 * The log net (N4) — pm/error_err.mdx §4.6. Upstream's dominant idiom is a catch that logs and
 * swallows; none of those loggers call report(), so the handler net never sees them.
 * Illuminate\Log\Logger::writeLog() fires MessageLogged for every record on every channel, and this
 * listener sorts each one into a tier, cheapest check first:
 *
 *  1. the level is not error/critical/alert/emergency → return (unless VERBOSE and warning/notice,
 *     T4); deprecations are always dropped;
 *  2. Reporter::$busy → return (R11: the library never reports itself);
 *  3. context['exception'] is a Throwable: already written → return; otherwise T2 (ERROR,
 *     net=log, `where` = the exception's origin);
 *  4. `#<n> ` trace continuations → dropped;
 *  5. MailError's `Exception is: ` request dump → dropped;
 *  6. an ExpectedMessages::PREFIXES match → dropped (EXPECTED under VERBOSE);
 *  7. the normalised message is in this request's written-set (the render duplicate at
 *     Handler.php:216, for a throwable handlerReport() saw) → dropped;
 *  8. otherwise T3 → WARN `Log::<level>: <message>`, `where` = the first backtrace frame outside
 *     vendor/ and outside this library; the message first passes withhold-after and PayloadGuard.
 *
 * It reads ONLY `level`, `message` and `context['exception']` — never any other context key: several
 * upstream sites pass a ledger array as context. debug_backtrace runs only at step 8, and for a
 * repeat of a line step 8 already admitted. Total.
 *
 * The storm fast path (§4.7 L1, §10 "Storms in one request"): a bare line this listener already
 * admitted is remembered by (level, message) → (where, request state) → (error path, fold key). A
 * repeat from the same call site in the same request state skips steps 3–7, the record, the
 * redaction and the context work, and goes straight to Folder::bump() (n++, zero I/O) while its L1
 * window is open; anything else takes the full path.
 *
 * The call site of a repeat is found cheaply: the first repeat of a remembered line records at which
 * backtrace depth (from repeat()) its `where` frame sits, and every later repeat asks debug_backtrace
 * for exactly that many frames and compares that one frame's raw file and line — no walk, no
 * path relativising. A mismatch falls back to the full walk, so a different site is never folded
 * into the wrong key (AC 5: 10,000 identical Log::error calls add under 50 ms).
 */
final class LogListener
{
    /** Step 1's one array lookup: the levels that make a record a fault candidate. */
    private const array FAULT_LEVELS = ['error' => true, 'critical' => true, 'alert' => true, 'emergency' => true];

    /** The levels VERBOSE adds (T4). */
    private const array VERBOSE_LEVELS = ['warning' => true, 'notice' => true];

    /** At most this many remembered lines; the memo is cleared when full. */
    private const int MEMO_MAX = 200;

    /** level␁message → where → [error path, fold key, request-state fingerprint]. @var array<string, array<string, array{0: string, 1: string, 2: string}>> */
    private array $memo = [];

    /** level␁message → [backtrace limit from repeat(), raw file, raw line, where] of its last repeat. @var array<string, array{0: int, 1: string, 2: int, 3: string}> */
    private array $sites = [];

    public function handle(MessageLogged $event): void
    {
        $level = $event->level;
        if (!isset(self::FAULT_LEVELS[$level]) && (!isset(self::VERBOSE_LEVELS[$level]) || !self::verbose())) {
            return;
        }
        if (Reporter::$busy) {
            return;
        }

        try {
            $message   = (string) $event->message;
            $exception = $event->context['exception'] ?? null;
            if (!$exception instanceof Throwable && $this->repeat((string) $level, $message)) {
                return;
            }
            $this->classify((string) $level, $message, $exception);
        } catch (Throwable) {
            // total: a failure here must never reach upstream's logger
        }
    }

    private function classify(string $level, string $message, mixed $exception): void
    {
        $verbose = self::verbose();
        $tier    = Classifier::forLogged($level, $message, $exception, $verbose);

        if (Classifier::TIER_T2 === $tier && $exception instanceof Throwable) {
            if (Reporter::isSeen($exception)) {
                return;   // T1 already wrote it: parent::report()'s own Log::error with the exception
            }
            Reporter::throwable(Level::Error, Describer::origin($exception), Context::doing($exception), $exception, [], 'log');

            return;
        }
        if (Classifier::TIER_EXPECTED === $tier) {
            if ($verbose) {
                $this->bare(Level::Expected, $level, $message);
            }

            return;
        }
        if (Classifier::TIER_T3 !== $tier && Classifier::TIER_T4 !== $tier) {
            return;   // ignored, a deprecation, a trace continuation or MailError's dump
        }
        $state = RequestState::current();
        if (null !== $state && $state->wasWritten(Normalizer::message($message))) {
            return;   // the render duplicate of a throwable the handler net already saw (§4.5 step 0)
        }
        $this->bare(Level::Warn, $level, $message, true);
    }

    /** A repeat of a line this listener admitted, from the same site and state, folded in L1. */
    private function repeat(string $level, string $message): bool
    {
        $memoKey = $level."\x01".$message;
        $sites   = $this->memo[$memoKey] ?? null;
        if (null === $sites) {
            return false;
        }
        $where = $this->siteOf($memoKey);
        $entry = $sites[$where] ?? null;

        return null !== $entry && $entry[2] === self::fingerprint() && Folder::bump($entry[0], $entry[1]);
    }

    /**
     * Describer::callerOrigin() as seen from repeat(), memoised by depth: when the frame at the
     * remembered depth has the remembered raw file and line, it is the same site. Otherwise the full
     * walk runs and the depth is remembered afresh.
     */
    private function siteOf(string $memoKey): string
    {
        $known = $this->sites[$memoKey] ?? null;
        if (null !== $known) {
            $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $known[0]);
            $frame  = $frames[$known[0] - 1] ?? null;
            if (null !== $frame && ($frame['file'] ?? '') === $known[1] && ($frame['line'] ?? 0) === $known[2]) {
                return $known[3];
            }
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 32) as $i => $frame) {
            $file = isset($frame['file']) && is_string($frame['file']) ? $frame['file'] : '';
            if ('' !== $file && Describer::isOwnFile($file)) {
                $line  = isset($frame['line']) && is_int($frame['line']) ? $frame['line'] : 0;
                $where = Describer::relative($file).':'.$line;
                if (count($this->sites) >= self::MEMO_MAX) {
                    $this->sites = [];
                }
                $this->sites[$memoKey] = [$i + 1, $file, $line, $where];

                return $where;
            }
        }

        return '';
    }

    /**
     * What a remembered line depends on besides its site: the request state, the command and job
     * stacks (they change `doing` and `app`) and the written-set (step 7).
     */
    private static function fingerprint(): string
    {
        $state = RequestState::current();

        return null === $state ? '-' : spl_object_id($state).':'.count($state->commands).':'.count($state->jobs).':'.count($state->written);
    }

    /**
     * A bare log line as a record: withheld payloads first, then the pipeline (which redacts). The
     * error field reads `Log::<level>: <message>` (§3.2); the facade name is passed as an argument
     * because the library source may not contain the facade call pattern (§4.1, SourceCanaryTest).
     */
    private function bare(Level $as, string $level, string $message, bool $remember = false): void
    {
        $where   = Describer::callerOrigin();
        $guarded = Redactor::payloadGuard(Redactor::withhold($message, ExpectedMessages::WITHHOLD_AFTER));
        $result  = Reporter::message($as, $where, Context::doing(), sprintf('%s::%s: %s', 'Log', $level, $guarded), [], 'log');
        $fold    = Reporter::lastAdmitted();
        if ($remember && null !== $fold && ('written' === $result || 'folded' === $result)) {
            if (count($this->memo) >= self::MEMO_MAX) {
                $this->memo = [];
            }
            $this->memo[$level."\x01".$message][$where] = [$fold[0], $fold[1], self::fingerprint()];
        }
    }

    private static function verbose(): bool
    {
        return true === (bool) Paths::config('errorfile.verbose', false);
    }
}
