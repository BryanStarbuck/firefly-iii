<?php

/*
 * error-file-coverage.php
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
 * The PHP half of the error-file coverage check — pm/error_err.mdx §13.1. `just check-errors` runs it.
 *
 * ENFORCED scope: app/Machine/**.php minus the library app/Machine/ErrorFile/ (but WITH its Canary/,
 * R1, V9), plus the fork's commands in app/Console/Commands/Machine/**.php. Every `catch` there is
 * compliant when its body (not counting nested closures) holds any of:
 *   - a throw (statement or expression);
 *   - ErrorFile::for(<string literal | self::WHERE>)->{caught|warn|expected|rethrow|fatal|tryOr}(<string literal>, …);
 *   - a call to report(…).
 * `// expected:` comments and Audit::line() are NOT compliant forms. Violations:
 *   wrongWhere    the where literal (or the class's WHERE constant) is not the file's repo-relative path
 *   dynamicDoing  the doing argument is not a string literal
 *   badDoing      the doing literal fails /^[a-z][A-Za-z0-9 ,'-]*[A-Za-z0-9]$/ (R2)
 *   ledgerKey     a literal data key matches Redactor::LEDGER_KEY (§12)
 *   unreported    none of the compliant forms is present
 *
 * INFORMATIONAL scope: upstream app/ minus the fork-owned paths above. Every catch is classed
 * log_error, log_warning, rethrow, debug or silent; silent catches are listed by file:line, and the
 * change since the previous run (`php.previous.upstream`) is printed. Informational findings never
 * fail the run: upstream is covered by the nets and is never edited (R15).
 *
 * WIRED runtimes: php-report (MachineExceptionHandler.php calls ErrorFile::handlerReport() and
 * php-log (ErrorFileServiceProvider.php listens to MessageLogged). An unwired runtime is a hard failure.
 *
 * Output: `error_file_coverage.json` next to the error file (FIREFLY_ERROR_FILE's directory, else
 * ~/T/firefly/), keys `php` and `runtimes` merged into whatever the TypeScript half wrote (`js`).
 * It never writes error.err itself, and nothing inside the repo. Exit 1 on any enforced violation or
 * unwired runtime.
 *
 *   php scripts/error-file-coverage.php            the report + exit code
 *   php scripts/error-file-coverage.php --quiet    totals and violations only
 */

use FireflyIII\Machine\ErrorFile\Redactor;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';

const DOING_RE     = "/^[a-z][A-Za-z0-9 ,'-]*[A-Za-z0-9]$/";
const SITE_METHODS = ['caught', 'warn', 'expected', 'rethrow', 'fatal', 'tryOr'];
const DATA_ARG     = ['caught' => 2, 'warn' => 2, 'rethrow' => 2, 'fatal' => 2];   // the index of the data array
const LEVELS_ERROR    = ['error', 'critical', 'alert', 'emergency'];
const LEVELS_WARNING  = ['warning', 'notice'];
const LEVELS_DEBUG    = ['debug', 'info'];

$quiet = in_array('--quiet', $argv, true);

// -------------------------------------------------------------------------------------------------
// Scope
// -------------------------------------------------------------------------------------------------

/** @return list<string> repo-relative .php paths under $dir, sorted */
function phpFiles(string $root, string $dir): array
{
    $out  = [];
    $base = $root.'/'.$dir;
    if (!is_dir($base)) {
        return [];
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
            $out[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($out);

    return $out;
}

function isLibrary(string $rel): bool
{
    return str_starts_with($rel, 'app/Machine/ErrorFile/') && !str_starts_with($rel, 'app/Machine/ErrorFile/Canary/');
}

function isForkOwned(string $rel): bool
{
    return str_starts_with($rel, 'app/Machine/') || str_starts_with($rel, 'app/Console/Commands/Machine/');
}

$enforced = array_values(array_filter(
    array_merge(phpFiles($root, 'app/Machine'), phpFiles($root, 'app/Console/Commands/Machine')),
    static fn (string $rel): bool => !isLibrary($rel)
));
$upstream = array_values(array_filter(phpFiles($root, 'app'), static fn (string $rel): bool => !isForkOwned($rel)));

// -------------------------------------------------------------------------------------------------
// AST helpers
// -------------------------------------------------------------------------------------------------

/**
 * Visit $node and its descendants, NOT descending into nested functions, closures or classes (a
 * report inside a callback that may never run does not make the catch compliant).
 *
 * @param callable(Node): void $fn
 */
function walkBody(array|Node $node, callable $fn): void
{
    if (is_array($node)) {
        foreach ($node as $n) {
            if ($n instanceof Node) {
                walkBody($n, $fn);
            }
        }

        return;
    }
    $fn($node);
    if ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction || $node instanceof Stmt\Function_
        || $node instanceof Stmt\Class_ || $node instanceof Stmt\ClassMethod) {
        return;
    }
    foreach ($node->getSubNodeNames() as $name) {
        $sub = $node->{$name};
        if ($sub instanceof Node || is_array($sub)) {
            walkBody($sub, $fn);
        }
    }
}

/** Visit every node (descending everywhere), tracking the enclosing class-like node. */
function walkAll(array|Node $node, callable $fn, ?Stmt\ClassLike $class = null): void
{
    if (is_array($node)) {
        foreach ($node as $n) {
            if ($n instanceof Node) {
                walkAll($n, $fn, $class);
            }
        }

        return;
    }
    if ($node instanceof Stmt\ClassLike) {
        $class = $node;
    }
    $fn($node, $class);
    foreach ($node->getSubNodeNames() as $name) {
        $sub = $node->{$name};
        if ($sub instanceof Node || is_array($sub)) {
            walkAll($sub, $fn, $class);
        }
    }
}

function lastName(Node $name): ?string
{
    if ($name instanceof Node\Name) {
        $parts = explode('\\', $name->toString());

        return end($parts);
    }

    return null;
}

/** `ErrorFile::for(…)` → its StaticCall, else null. */
function errorFileFor(Node $expr): ?Expr\StaticCall
{
    if ($expr instanceof Expr\StaticCall && 'ErrorFile' === lastName($expr->class)
        && $expr->name instanceof Node\Identifier && 'for' === $expr->name->toString()) {
        return $expr;
    }

    return null;
}

/** A `ErrorFile::for(…)->m(…)` site: [for-call, method name, args], else null. */
function siteCall(Node $node): ?array
{
    if ($node instanceof Expr\MethodCall && $node->name instanceof Node\Identifier) {
        $for = errorFileFor($node->var);
        $m   = $node->name->toString();
        if (null !== $for && in_array($m, SITE_METHODS, true)) {
            return [$for, $m, $node->args];
        }
    }

    return null;
}

/** The literal string value of an argument/expression, or null when it is not a plain literal. */
function literal(?Node $expr): ?string
{
    if ($expr instanceof Node\Arg) {
        $expr = $expr->value;
    }

    return $expr instanceof Node\Scalar\String_ ? $expr->value : null;
}

/** The string value of the class's WHERE constant, or null. */
function classWhere(?Stmt\ClassLike $class): ?string
{
    if (null === $class) {
        return null;
    }
    foreach ($class->getConstants() as $group) {
        foreach ($group->consts as $const) {
            if ('WHERE' === $const->name->toString()) {
                return literal($const->value);
            }
        }
    }

    return null;
}

/** The where an `ErrorFile::for()` call names: [value|null, how] where how is literal|const|dynamic. */
function whereOf(Expr\StaticCall $for, ?Stmt\ClassLike $class): array
{
    $arg = $for->args[0] ?? null;
    $val = $arg instanceof Node\Arg ? $arg->value : null;
    if ($val instanceof Node\Scalar\String_) {
        return [$val->value, 'literal'];
    }
    if ($val instanceof Expr\ClassConstFetch && $val->name instanceof Node\Identifier && 'WHERE' === $val->name->toString()
        && in_array(lastName($val->class), ['self', 'static'], true)) {
        return [classWhere($class), 'const'];
    }

    return [null, 'dynamic'];
}

// -------------------------------------------------------------------------------------------------
// Scan
// -------------------------------------------------------------------------------------------------

$parser    = (new ParserFactory())->createForNewestSupportedVersion();
$ledgerKey = Redactor::LEDGER_KEY;

/** @return array{0: array<int, Node>, 1: ?string} the statements, or an error */
function parseFile(object $parser, string $root, string $rel): array
{
    try {
        return [$parser->parse((string) file_get_contents($root.'/'.$rel)) ?? [], null];
    } catch (Throwable $e) {
        return [[], $e->getMessage()];
    }
}

$violations = [];
$catchCount = 0;
$compliant  = 0;
$parseErrors = [];

foreach ($enforced as $rel) {
    [$ast, $error] = parseFile($parser, $root, $rel);
    if (null !== $error) {
        $parseErrors[] = $rel.': '.$error;

        continue;
    }
    walkAll($ast, static function (Node $node, ?Stmt\ClassLike $class) use ($rel, $ledgerKey, &$violations, &$catchCount, &$compliant): void {
        // the call-site checks, on every ErrorFile::for(…)->m(…) in the file
        $site = siteCall($node);
        if (null !== $site) {
            [$for, $method, $args] = $site;
            $line                  = $node->getStartLine();
            [$where, $how]         = whereOf($for, $class);
            if ('dynamic' === $how) {
                $violations[] = [$rel, $line, 'wrongWhere', 'the where passed to ErrorFile::for() is not a string literal or self::WHERE'];
            } elseif ($where !== $rel) {
                $violations[] = [$rel, $line, 'wrongWhere', sprintf('the where is "%s", not this file\'s repo-relative path "%s"', (string) $where, $rel)];
            }
            $doing = literal($args[0] ?? null);
            if (null === $doing) {
                $violations[] = [$rel, $line, 'dynamicDoing', sprintf('->%s(): the doing argument is not a string literal', $method)];
            } elseif (1 !== preg_match(DOING_RE, $doing)) {
                $violations[] = [$rel, $line, 'badDoing', sprintf('->%s(\'%s\'): doing must be a gerund phrase with no trailing period (R2)', $method, $doing)];
            }
            $dataArg = isset(DATA_ARG[$method]) ? ($args[DATA_ARG[$method]] ?? null) : null;
            $data    = $dataArg instanceof Node\Arg ? $dataArg->value : null;
            if ($data instanceof Expr\Array_) {
                foreach ($data->items as $item) {
                    $key = null === $item ? null : literal($item->key);
                    if (null !== $key && 1 === preg_match($ledgerKey, $key)) {
                        $violations[] = [$rel, $line, 'ledgerKey', sprintf('the data key "%s" is a ledger field (§12)', $key)];
                    }
                }
            }
        }

        if (!$node instanceof Stmt\Catch_) {
            return;
        }
        ++$catchCount;
        $ok = false;
        walkBody($node->stmts, static function (Node $n) use (&$ok): void {
            if ($ok) {
                return;
            }
            if ($n instanceof Expr\Throw_ || (class_exists(Stmt\Throw_::class) && $n instanceof Stmt\Throw_)) {
                $ok = true;
            } elseif (null !== siteCall($n) && null !== literal(siteCall($n)[2][0] ?? null)) {
                $ok = true;
            } elseif ($n instanceof Expr\FuncCall && 'report' === lastName($n->name)) {
                $ok = true;
            }
        });
        if ($ok) {
            ++$compliant;
        } else {
            $violations[] = [$rel, $node->getStartLine(), 'unreported', 'the catch neither rethrows, calls report() nor reports through ErrorFile::for(…) (R1)'];
        }
    });
}

/** @return string log_error|log_warning|rethrow|debug|silent */
function classify(Stmt\Catch_ $catch): string
{
    $seen = ['log_error' => false, 'log_warning' => false, 'rethrow' => false, 'debug' => false];
    walkBody($catch->stmts, static function (Node $n) use (&$seen): void {
        if ($n instanceof Expr\Throw_ || (class_exists(Stmt\Throw_::class) && $n instanceof Stmt\Throw_)) {
            $seen['rethrow'] = true;

            return;
        }
        if ($n instanceof Expr\FuncCall && 'report' === lastName($n->name)) {
            $seen['log_error'] = true;

            return;
        }
        $level = null;
        if ($n instanceof Expr\StaticCall && 'Log' === lastName($n->class) && $n->name instanceof Node\Identifier) {
            $level = strtolower($n->name->toString());
        } elseif ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier
            && $n->var instanceof Expr\FuncCall && in_array(lastName($n->var->name), ['logger', 'app'], true)) {
            $level = strtolower($n->name->toString());
        }
        if (null === $level) {
            return;
        }
        if (in_array($level, LEVELS_ERROR, true)) {
            $seen['log_error'] = true;
        } elseif (in_array($level, LEVELS_WARNING, true)) {
            $seen['log_warning'] = true;
        } elseif (in_array($level, LEVELS_DEBUG, true)) {
            $seen['debug'] = true;
        }
    });
    foreach (['log_error', 'log_warning', 'rethrow', 'debug'] as $class) {
        if ($seen[$class]) {
            return $class;
        }
    }

    return 'silent';
}

$counts = ['log_error' => 0, 'log_warning' => 0, 'rethrow' => 0, 'debug' => 0, 'silent' => 0];
$silent = [];
$upstreamParseErrors = [];
foreach ($upstream as $rel) {
    [$ast, $error] = parseFile($parser, $root, $rel);
    if (null !== $error) {
        $upstreamParseErrors[] = $rel.': '.$error;   // informational, like everything upstream

        continue;
    }
    walkAll($ast, static function (Node $node) use ($rel, &$counts, &$silent): void {
        if ($node instanceof Stmt\Catch_) {
            $class = classify($node);
            ++$counts[$class];
            if ('silent' === $class) {
                $silent[] = $rel.':'.$node->getStartLine();
            }
        }
    });
}

// -------------------------------------------------------------------------------------------------
// Wired runtimes (§13.2's table, the PHP rows)
// -------------------------------------------------------------------------------------------------

$runtimeDefs = [
    'php-report' => ['app/Machine/Http/MachineExceptionHandler.php', 'ErrorFile::handlerReport('],
    'php-log'    => ['app/Machine/ErrorFile/ErrorFileServiceProvider.php', 'MessageLogged'],
];
$runtimes = [];
foreach ($runtimeDefs as $name => [$file, $marker]) {
    $text             = @file_get_contents($root.'/'.$file);
    $runtimes[$name]  = ['file' => $file, 'marker' => $marker, 'wired' => is_string($text) && str_contains($text, $marker)];
}
$unwired = array_keys(array_filter($runtimes, static fn (array $r): bool => !$r['wired']));

// -------------------------------------------------------------------------------------------------
// The report file (§13): next to the error file, merged with the other half's keys
// -------------------------------------------------------------------------------------------------

function coverageFile(): string
{
    $home     = $_SERVER['HOME'] ?? getenv('HOME');
    $home     = is_string($home) && '' !== $home ? rtrim($home, '/') : null;
    $override = getenv('FIREFLY_ERROR_FILE');
    if (is_string($override) && '' !== trim($override)) {
        $path = trim($override);
        if (str_starts_with($path, '~/') && null !== $home) {
            $path = $home.substr($path, 1);
        }

        return dirname($path).'/error_file_coverage.json';
    }
    if (null !== $home) {
        return $home.'/T/firefly/error_file_coverage.json';
    }
    $uid = function_exists('posix_getuid') ? posix_getuid() : (int) getmyuid();

    return sprintf('%s/firefly_%d/error_file_coverage.json', rtrim(sys_get_temp_dir(), '/'), $uid);
}

$outFile  = coverageFile();
$existing = [];
if (is_file($outFile)) {
    $decoded  = json_decode((string) @file_get_contents($outFile), true);
    $existing = is_array($decoded) ? $decoded : [];
}
$previous = $existing['php']['informational'] ?? null;
$previous = is_array($previous) ? ['generatedAt' => $existing['php']['generatedAt'] ?? null, 'counts' => $previous['counts'] ?? [], 'silent' => $previous['silent'] ?? []] : null;

usort($violations, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
$byKind = [];
foreach ($violations as [, , $kind]) {
    $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
}

$report = $existing;
$report['php'] = [
    'generatedAt'   => gmdate('Y-m-d\TH:i:s\Z'),
    'enforced'      => [
        'scope'      => 'app/Machine/** (minus app/Machine/ErrorFile/, plus its Canary/), app/Console/Commands/Machine/**',
        'files'      => count($enforced),
        'catches'    => $catchCount,
        'compliant'  => $compliant,
        'violations' => count($violations),
        'byKind'     => $byKind,
        'sites'      => array_map(static fn (array $v): array => ['file' => $v[0], 'line' => $v[1], 'kind' => $v[2], 'message' => $v[3]], $violations),
    ],
    'informational' => [
        'scope'   => 'upstream app/ minus the fork-owned paths',
        'files'   => count($upstream),
        'catches' => array_sum($counts),
        'counts'  => $counts,
        'silent'  => $silent,
    ],
    'previous'      => ['upstream' => $previous],
    'parseErrors'   => ['enforced' => $parseErrors, 'upstream' => $upstreamParseErrors],
];
$report['runtimes'] = array_merge(is_array($existing['runtimes'] ?? null) ? $existing['runtimes'] : [], $runtimes);

$dir = dirname($outFile);
if (!is_dir($dir)) {
    $old = umask(0o077);
    @mkdir($dir, 0o700, true);
    umask($old);
}
$tmp = $outFile.'.'.getmypid().'.tmp';
$old = umask(0o077);
$ok  = false !== @file_put_contents($tmp, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n") && @rename($tmp, $outFile);
umask($old);
if (!$ok) {
    @unlink($tmp);
    fwrite(STDERR, "error-file coverage: could not write {$outFile}\n");
}

// -------------------------------------------------------------------------------------------------
// Output
// -------------------------------------------------------------------------------------------------

$out = static function (string $line): void { fwrite(STDOUT, $line."\n"); };

$out('error-file coverage, PHP (pm/error_err.mdx §13.1)');
$out(sprintf('  enforced       %d files, %d catches, %d compliant, %d violations%s', count($enforced), $catchCount, $compliant, count($violations),
    [] === $byKind ? '' : ' ('.implode(', ', array_map(static fn (string $k, int $n): string => "{$k} {$n}", array_keys($byKind), $byKind)).')'));
foreach ($violations as [$file, $line, $kind, $message]) {
    $out(sprintf('    VIOLATING  %s:%d  %s — %s', $file, $line, $kind, $message));
}
$delta = static function (string $key) use ($counts, $previous): string {
    if (null === $previous || !isset($previous['counts'][$key])) {
        return '';
    }
    $d = $counts[$key] - (int) $previous['counts'][$key];

    return 0 === $d ? ' (±0)' : sprintf(' (%+d)', $d);
};
$out(sprintf('  informational  upstream app/: %d files, %d catches — log_error %d%s, log_warning %d%s, rethrow %d%s, debug %d%s, silent %d%s',
    count($upstream), array_sum($counts),
    $counts['log_error'], $delta('log_error'), $counts['log_warning'], $delta('log_warning'), $counts['rethrow'], $delta('rethrow'),
    $counts['debug'], $delta('debug'), $counts['silent'], $delta('silent')));
if (null === $previous) {
    $out('    upstream delta: no previous run recorded');
} else {
    $added   = array_values(array_diff($silent, $previous['silent']));
    $removed = array_values(array_diff($previous['silent'], $silent));
    $out(sprintf('    upstream delta since %s: %d silent catch(es) added, %d gone', (string) ($previous['generatedAt'] ?? 'the previous run'), count($added), count($removed)));
    foreach ($added as $site) {
        $out('      + silent  '.$site);
    }
    foreach ($removed as $site) {
        $out('      - silent  '.$site);
    }
}
if (!$quiet) {
    foreach ($silent as $site) {
        $out('    silent (informational, covered by the nets or escape hatch E2)  '.$site);
    }
}
foreach ($runtimes as $name => $r) {
    $out(sprintf('  runtime %-11s %s  (%s contains %s)', $name, $r['wired'] ? 'wired' : 'UNWIRED', $r['file'], $r['marker']));
}
foreach ($parseErrors as $e) {
    $out('  PARSE ERROR (enforced)  '.$e);
}
foreach ($upstreamParseErrors as $e) {
    $out('  parse error (upstream, informational)  '.$e);
}
$out('  report: '.$outFile);

exit([] === $violations && [] === $unwired && [] === $parseErrors ? 0 : 1);
