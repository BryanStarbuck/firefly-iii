<?php

/*
 * error-file-replay.php
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

/*
 * The replay gate — pm/error_err.mdx §11 and §16.2 step 4.
 *
 * Feeds upstream's daily log files (storage/logs/ff3-*.log by default) through the error file's
 * Classifier OFFLINE and prints COUNTS PER TIER ONLY — never a line, a message, a path from inside
 * a log, or any other content. The daily logs hold private data (payees, amounts, account names);
 * this script reads them at runtime and lets nothing but numbers out, which the private-data rules
 * allow. It writes nothing and needs no running app, database or network.
 *
 *   php scripts/error-file-replay.php                     every storage/logs/ff3-*.log
 *   php scripts/error-file-replay.php <file.log> …        explicit files
 *   php scripts/error-file-replay.php --date=2026-09-21   only files whose name carries that date
 *   php scripts/error-file-replay.php --limit=10          the "normal day" budget (default 10)
 *
 * How a log record is sorted (the same order as LogListener, §4.6):
 *   - the header `[datetime] channel.LEVEL: message …` starts a record; the lines after it belong
 *     to it (a stack trace, a multi-line message);
 *   - a record whose context carries `"exception":"[object] (` is an exception record: in the live
 *     app it is T1 (the handler net wrote it first) or T2 — the file cannot tell them apart, so
 *     they are counted together as "T1/T2";
 *   - a bare error-or-above record goes through Classifier::forLogged(): trace continuation,
 *     MailError dump, expected prefix, or T3;
 *   - a bare error record whose normalised message equals an exception record's message in the
 *     same second or the one before is Handler::render()'s echo (Handler.php:216) and is dropped,
 *     as the written-set would (§4.5 step 0);
 *   - "written" applies the 60 s fold (R10): the first sighting of a key per window is a line, and
 *     a window with repeats adds one summary line.
 *
 * The verdict line says whether each file stays at or under the budget. Exit code 0 always: the
 * gate informs a human decision (switch T2/T3 on), it does not fail a build.
 */

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

use FireflyIII\Machine\ErrorFile\Classifier;
use FireflyIII\Machine\ErrorFile\Normalizer;

const HEADER = '/^\[(\d{4}-\d{2}-\d{2})[ T](\d{2}):(\d{2}):(\d{2})[^\]]*\] [\w.-]+\.([A-Z]+): /';
const EXCEPTION_MARK = '{"exception":"[object] (';

$files = [];
$date  = null;
$limit = 10;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $date = substr($arg, 7);

        continue;
    }
    if (str_starts_with($arg, '--limit=')) {
        $limit = max(0, (int) substr($arg, 8));

        continue;
    }
    if ('--help' === $arg || '-h' === $arg) {
        fwrite(STDOUT, "usage: php scripts/error-file-replay.php [--date=YYYY-MM-DD] [--limit=N] [file.log …]\n");

        exit(0);
    }
    $files[] = $arg;
}
if ([] === $files) {
    foreach (scandir($root.'/storage/logs') ?: [] as $entry) {
        if (1 === preg_match('/^ff3-.*\.log$/', $entry)) {
            $files[] = $root.'/storage/logs/'.$entry;
        }
    }
}
if (null !== $date) {
    $files = array_values(array_filter($files, static fn (string $f): bool => str_contains(basename($f), $date)));
}
sort($files);
if ([] === $files) {
    fwrite(STDOUT, "error-file replay: no daily log files found (nothing to count)\n");

    exit(0);
}

fwrite(STDOUT, "error-file replay (pm/error_err.mdx §11) — counts only, never lines\n");
foreach ($files as $file) {
    $counts = replay($file);
    if (null === $counts) {
        fwrite(STDOUT, sprintf("\n%s: unreadable, skipped\n", label($file)));

        continue;
    }
    report(label($file), $counts, $limit);
}

exit(0);

/** A file's label: its basename when it is a daily file (the name carries only the date), else a placeholder. */
function label(string $file): string
{
    $base = basename($file);

    return 1 === preg_match('/^ff3-[a-z-]*\d{4}-\d{2}-\d{2}\.log$/', $base) ? $base : 'file #'.substr(hash('xxh3', $file), 0, 6);
}

/**
 * @return null|array<string, int>
 */
function replay(string $file): ?array
{
    $handle = @fopen($file, 'rb');
    if (false === $handle) {
        return null;
    }
    $counts = [
        'records'     => 0,
        'error+'      => 0,
        'warning'     => 0,
        'below'       => 0,
        'T1/T2'       => 0,
        'T3'          => 0,
        'render-echo' => 0,
        'trace'       => 0,
        'mail'        => 0,
        'expected'    => 0,
        'T4 (verbose)' => 0,
        'expected (verbose)' => 0,
        'lines'       => 0,
        'summaries'   => 0,
        'distinct T1/T2' => 0,
        'distinct T3' => 0,
    ];
    $folds      = [];   // key => [window start, repeats]
    $recent     = [];   // normalised exception message => second
    $current    = null; // [second, level, text]
    $flush      = static function (?array $record) use (&$counts, &$folds, &$recent): void {
        if (null === $record) {
            return;
        }
        [$second, $level, $text] = $record;
        ++$counts['records'];
        $level = strtolower($level);
        $at    = strpos($text, EXCEPTION_MARK);
        $isExc = false !== $at;
        $message = $isExc ? substr($text, 0, (int) $at) : stripTrailingContext($text);
        $message = rtrim($message);
        if (in_array($level, Classifier::ERROR_LEVELS, true)) {
            ++$counts['error+'];
        } elseif (in_array($level, Classifier::WARNING_LEVELS, true)) {
            ++$counts['warning'];
        } else {
            ++$counts['below'];
        }
        $norm = Normalizer::message($message);
        if ($isExc && in_array($level, Classifier::ERROR_LEVELS, true)) {
            ++$counts['T1/T2'];
            $recent[$norm] = $second;
            fold($folds, $counts, 'x'.$norm, $second);

            return;
        }
        $tier = Classifier::forLogged($level, $message, null, false);
        if (Classifier::TIER_T3 === $tier && isset($recent[$norm]) && $second - $recent[$norm] <= 1) {
            ++$counts['render-echo'];

            return;
        }
        switch ($tier) {
            case Classifier::TIER_T3:
                ++$counts['T3'];
                fold($folds, $counts, 'b'.$norm, $second);

                return;

            case Classifier::TIER_TRACE:
            case Classifier::TIER_MAIL:
            case Classifier::TIER_EXPECTED:
                ++$counts[$tier];

                return;

            case Classifier::TIER_IGNORED:
                $verbose = Classifier::forLogged($level, $message, null, true);
                if (Classifier::TIER_T4 === $verbose) {
                    ++$counts['T4 (verbose)'];
                } elseif (Classifier::TIER_EXPECTED === $verbose) {
                    ++$counts['expected (verbose)'];
                }

                return;

            default:
                return;
        }
    };
    while (false !== ($line = fgets($handle))) {
        if (1 === preg_match(HEADER, $line, $m)) {
            $flush($current);
            $second  = (int) (strtotime($m[1].' 00:00:00 UTC') ?: 0) + 3600 * (int) $m[2] + 60 * (int) $m[3] + (int) $m[4];
            $current = [$second, $m[5], rtrim(substr($line, strlen($m[0])), "\r\n")];

            continue;
        }
        if (null !== $current && strlen($current[2]) < 65_536) {
            $current[2] .= "\n".rtrim($line, "\r\n");
        }
    }
    $flush($current);
    fclose($handle);
    foreach ($folds as $key => [, $repeats]) {
        if ($repeats > 0) {
            ++$counts['summaries'];
        }
        ++$counts[str_starts_with((string) $key, 'x') ? 'distinct T1/T2' : 'distinct T3'];
    }

    return $counts;
}

/** Monolog appends ` {context} [extra]`; a bare record usually ends ` [] []`. */
function stripTrailingContext(string $text): string
{
    $first = strtok($text, "\n");
    $first = false === $first ? $text : $first;

    return (string) preg_replace('/(?: \[\]| \{.*\})+ *$/', '', $first);
}

/**
 * The 60 s fold of R10: the first sighting of a key in a window is a line; repeats inside it are
 * counted into one summary line when the window closes.
 *
 * @param array<string, array{0: int, 1: int}> $folds
 * @param array<string, int>                   $counts
 */
function fold(array &$folds, array &$counts, string $key, int $second): void
{
    if (isset($folds[$key]) && $second - $folds[$key][0] < 60 && $second >= $folds[$key][0]) {
        ++$folds[$key][1];

        return;
    }
    if (isset($folds[$key]) && $folds[$key][1] > 0) {
        ++$counts['summaries'];
    }
    $folds[$key] = [$second, 0];
    ++$counts['lines'];
}

/** @param array<string, int> $counts */
function report(string $label, array $counts, int $limit): void
{
    $written = $counts['lines'] + $counts['summaries'];
    fwrite(STDOUT, sprintf("\n%s\n", $label));
    fwrite(STDOUT, sprintf("  records %d  (error+ %d, warning/notice %d, info/debug %d)\n", $counts['records'], $counts['error+'], $counts['warning'], $counts['below']));
    fwrite(STDOUT, sprintf("  T1/T2 exception records   %6d  → ERROR  (%d distinct fold keys)\n", $counts['T1/T2'], $counts['distinct T1/T2']));
    fwrite(STDOUT, sprintf("  T3 bare error lines       %6d  → WARN   (%d distinct fold keys)\n", $counts['T3'], $counts['distinct T3']));
    fwrite(STDOUT, sprintf("  render echoes (dropped)   %6d\n", $counts['render-echo']));
    fwrite(STDOUT, sprintf("  trace continuations       %6d  dropped\n", $counts['trace']));
    fwrite(STDOUT, sprintf("  MailError dumps           %6d  dropped\n", $counts['mail']));
    fwrite(STDOUT, sprintf("  expected prefixes         %6d  dropped\n", $counts['expected']));
    fwrite(STDOUT, sprintf("  T4 under VERBOSE          %6d  (expected under VERBOSE %d)\n", $counts['T4 (verbose)'], $counts['expected (verbose)']));
    fwrite(STDOUT, sprintf("  written after the 60 s fold: %d lines + %d summaries = %d\n", $counts['lines'], $counts['summaries'], $written));
    fwrite(STDOUT, sprintf("  verdict: %s (budget %d)\n", $written <= $limit ? 'WITHIN the normal-day budget' : 'OVER the normal-day budget', $limit));
}
