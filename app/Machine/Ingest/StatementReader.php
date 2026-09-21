<?php

/*
 * StatementReader.php
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

namespace FireflyIII\Machine\Ingest;

use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\Ingest\Parsers\CamtParser;
use FireflyIII\Machine\Ingest\Parsers\CsvStatementParser;
use FireflyIII\Machine\Ingest\Parsers\OfxParser;
use FireflyIII\Machine\Ingest\Parsers\TextStatementParser;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Turns one statement unit into a ParsedStatement.
 *
 * A unit is either an importable file (.ofx .qfx .csv camt .xml) or, in a raw tree, a PDF with its
 * text sidecars. The extraction source is chosen highest fidelity first (cli.mdx §10.3):
 * `_claude.txt`, `_brew.txt`, `_ocr.txt`, then the PDF's own text (cached in staging by
 * /ingest/extract; produced with a local `pdftotext` when one is installed — never uploaded).
 *
 * Statement identity for "identical bytes" is the PDF's sha256 when there is a PDF, else the
 * parsed file's.
 */
final class StatementReader
{
    public const array IMPORTABLE = ['ofx', 'qfx', 'csv', 'xml'];
    public const array SIDECARS   = ['claude', 'brew', 'ocr'];

    /** Largest file read (a statement is small; a 50 MB "statement" is not one). */
    public const int MAX_BYTES    = 20_971_520;

    /**
     * /ingest/extract's own ceiling: how many PDFs one call may run through pdftotext. Set by the
     * route; each run decrements it; at zero the rest are reported, not silently skipped.
     */
    public static int $pdfBudget  = PHP_INT_MAX;
    public static int $pdfRuns    = 0;
    public static int $pdfDeferred = 0;

    /**
     * @param array{kind?: null|string, institution?: null|string} $account
     */
    public static function readFile(string $root, string $path, array $account = []): ?ParsedStatement
    {
        $ext     = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $content = self::load($path);
        if (null === $content) {
            return self::unreadable($root, $path, $ext, 'could not read the file (missing, too large, or not a regular file)');
        }
        $charges = self::chargesPositive($account['kind'] ?? null);
        [$format, $kind, $parsed] = match (true) {
            in_array($ext, ['ofx', 'qfx'], true)                 => [$ext, $ext, OfxParser::parse($content)],
            'xml' === $ext && CamtParser::looksLikeCamt($content) => ['camt', 'camt053', CamtParser::parse($content)],
            'csv' === $ext                                       => ['csv', 'csv', CsvStatementParser::parse($content, false)],
            'txt' === $ext                                       => ['text', self::sidecarKind($path) ?? 'pdf_text', TextStatementParser::parse($content, $charges)],
            default                                              => [$ext, $ext, null],
        };
        if (null === $parsed) {
            return null; // not a statement format at all (an unrelated .xml)
        }

        return self::build($root, $path, $path, $format, $kind, hash('sha256', $content), $parsed, $account);
    }

    /**
     * A raw-mode unit: a PDF and/or its sidecars.
     *
     * @param array<string, string>                                 $sidecars kind => path
     * @param array{kind?: null|string, institution?: null|string} $account
     */
    public static function readUnit(string $root, ?string $pdf, array $sidecars, ?Staging $staging, bool $extractPdf, bool $force, array $account = []): ParsedStatement
    {
        $identity = null === $pdf ? null : self::hashFile($pdf);
        $charges  = self::chargesPositive($account['kind'] ?? null);
        foreach (self::SIDECARS as $kind) {
            if (!isset($sidecars[$kind])) {
                continue;
            }
            $content = self::load($sidecars[$kind]);
            if (null === $content || '' === trim($content)) {
                continue;
            }
            $parsed  = TextStatementParser::parse($content, $charges);
            if (true === $parsed['unreadable'] && [] === $parsed['rows']) {
                continue; // try the next source
            }

            return self::build($root, $pdf ?? $sidecars[$kind], $sidecars[$kind], 'text', $kind, $identity ?? hash('sha256', $content), $parsed, $account);
        }
        if (null !== $pdf && null !== $identity) {
            $text = self::pdfText($pdf, $identity, $staging, $extractPdf, $force);
            if (null !== $text && '' !== trim($text)) {
                $parsed = TextStatementParser::parse($text, $charges);

                return self::build($root, $pdf, $pdf, 'text', 'pdf_text', $identity, $parsed, $account);
            }
        }

        return self::unreadable($root, $pdf ?? (string) reset($sidecars), 'pdf', null === $pdf ? 'the text sidecars could not be read' : 'no usable extraction: no _claude/_brew/_ocr sidecar, and no PDF text'.($extractPdf ? '' : ' yet (run /ingest/extract)'));
    }

    /** card / loan / mortgage statements print charges as positive numbers. */
    public static function chargesPositive(?string $kind): bool
    {
        return in_array($kind, ['card', 'loan', 'mortgage'], true);
    }

    /** "…-4021_claude.txt" → "claude". */
    public static function sidecarKind(string $path): ?string
    {
        if (1 === preg_match('/_(claude|brew|ocr)\.txt$/i', basename($path), $m)) {
            return strtolower($m[1]);
        }

        return null;
    }

    public static function hashFile(string $path): ?string
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $h = @hash_file('sha256', $path);

        return false === $h ? null : $h;
    }

    /**
     * @param array<string, mixed>                                  $parsed
     * @param array{kind?: null|string, institution?: null|string} $account
     */
    private static function build(string $root, string $unitPath, string $parsedPath, string $format, string $kind, string $sha, array $parsed, array $account): ParsedStatement
    {
        $rows      = $parsed['rows'];
        $start     = $parsed['period_start'];
        $end       = $parsed['period_end'];
        $fromRows  = false;
        if ((null === $start || null === $end) && [] !== $rows) {
            $dates    = array_map(static fn (StatementRow $r): string => $r->date, $rows);
            $start ??= min($dates);
            $end   ??= max($dates);
            $fromRows = true;
        }
        $statement = new ParsedStatement(
            file          : $unitPath,
            relative      : StatementsRoot::relative($root, $unitPath),
            format        : $format,
            sourceKind    : $kind,
            sha256        : $sha,
            mtime         : (int) @filemtime($unitPath),
            last4         : $parsed['last4'],
            entity        : $parsed['entity'],
            institution   : $parsed['institution'] ?? ($account['institution'] ?? null),
            periodStart   : $start,
            periodEnd     : $end,
            currency      : $parsed['currency'],
            rows          : $rows,
            bad           : $parsed['bad'],
            warnings      : $parsed['warnings'],
            periodFromRows: $fromRows,
            sourceFile    : $parsedPath === $unitPath ? null : StatementsRoot::relative($root, $parsedPath),
        );
        if (true === $parsed['unreadable'] && [] === $rows) {
            $statement->status = ParsedStatement::UNREADABLE;
            $statement->rule   = implode('; ', $parsed['warnings']) ?: 'unreadable';
        }

        return $statement;
    }

    private static function unreadable(string $root, string $path, string $format, string $why): ParsedStatement
    {
        $s         = new ParsedStatement($path, StatementsRoot::relative($root, $path), $format, $format, self::hashFile($path) ?? '', (int) @filemtime($path), null, null, null, null, null, null, [], [], [$why]);
        $s->status = ParsedStatement::UNREADABLE;
        $s->rule   = $why;

        return $s;
    }

    private static function load(string $path): ?string
    {
        if (!is_file($path) || is_link($path)) {
            return null;
        }
        $size = @filesize($path);
        if (false === $size || $size > self::MAX_BYTES) {
            return null;
        }
        $text = @file_get_contents($path);

        return false === $text ? null : $text;
    }

    /**
     * The PDF's own text: the staging cache first; with $extract, a local pdftotext run (no
     * network — the plane has no egress), cached as _text/{sha256}.txt.
     */
    private static function pdfText(string $pdf, string $sha, ?Staging $staging, bool $extract, bool $force): ?string
    {
        $cached = $staging?->read('_text/'.$sha.'.txt');
        if (null !== $cached && !$force) {
            return $cached;
        }
        if (!$extract || null === $staging) {
            return $cached;
        }
        $binary = self::pdftotext();
        if (null === $binary) {
            return $cached;
        }
        if (self::$pdfBudget <= 0) {
            ++self::$pdfDeferred;

            return $cached;
        }
        --self::$pdfBudget;
        ++self::$pdfRuns;

        try {
            $result = Process::timeout(60)->run([$binary, '-layout', '-q', $pdf, '-']);
            if (!$result->successful()) {
                return $cached;
            }
            $text   = $result->output();
        } catch (Throwable $e) {
            ErrorFile::for('app/Machine/Ingest/StatementReader.php')->expected('extracting text from a PDF statement', $e);
            return $cached;
        }
        $staging->write('_text/'.$sha.'.txt', $text);

        return $text;
    }

    private static function pdftotext(): ?string
    {
        $configured = config('machine.ingest.pdftotext');
        $candidates = is_string($configured) && '' !== $configured ? [$configured] : ['/opt/homebrew/bin/pdftotext', '/usr/local/bin/pdftotext', '/usr/bin/pdftotext'];
        foreach ($candidates as $c) {
            if (is_file($c) && is_executable($c)) {
                return $c;
            }
        }

        return null;
    }
}
