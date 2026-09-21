<?php

/*
 * SourceCanaryTest.php
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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * pm/error_err.mdx §4.1 and R12: the library never reads a request body, header, cookie or server
 * variable except through ONE named allowlist, exact per file and per call, and never calls Log::,
 * logger(), report(), echo, print or var_export (R11). Comments are stripped first (token_get_all),
 * so a docblock may name what the code must not do.
 *
 * @internal
 */
#[CoversNothing]
final class SourceCanaryTest extends TestCase
{
    private const string LIBRARY = __DIR__.'/../../../app/Machine/ErrorFile';

    /** Literal accessors (§4.1). */
    private const array FORBIDDEN_LITERALS = [
        'request()->all()', '->input(', '->all(', '->headers', '->header(', '->cookie', '->cookies', '$_COOKIE', '$_SERVER',
        'HTTP_', 'getallheaders', '->server(', '->server->', 'bearerToken', 'fullUrl', 'getQueryString', 'getContent(',
        'php://input', "file_get_contents('php://", "['args']", '["args"]', 'getTraceAsString', 'var_export', 'logger(',
    ];

    /** Word-boundary patterns (§4.1), so LineFormat's sprintf( does not trip them. */
    private const array FORBIDDEN_PATTERNS = ['/\bprint\b/', '/\becho\b/', '/\bLog::/', '/\breport\(/', '/\bprint_r\b/', '/\bvar_dump\b/'];

    /**
     * The one named allowlist (§4.1), exact per file and per call. An allowed call is removed from
     * the code before the forbidden list runs; anything left over fails.
     */
    private const array ALLOW = [
        'Context.php'                => ["\$request->header('X-Firefly-Request-Id')", "\$request->header('X-Firefly-Client')", "\$_SERVER['argv']"],
        'Paths.php'                  => ["\$_SERVER['HOME']"],
        'Http/ErrorReportGate.php'   => [
            '->getSchemeAndHttpHost()', "->server->get('REMOTE_ADDR')",
            "\$request->headers->get('Origin')", "\$request->headers->get('Sec-Fetch-Site')",
            "\$request->headers->get('Content-Type')", "\$request->headers->get('Content-Length')",
        ],
        'Ingest.php'                 => ["fopen('php://input', 'rb')"],
    ];

    public function testTheLibraryReadsNothingOutsideTheAllowlist(): void
    {
        $files = self::libraryFiles();
        self::assertNotEmpty($files);
        foreach (['Paths.php', 'Redactor.php', 'Normalizer.php', 'LineFormat.php', 'Appender.php', 'Folder.php', 'FoldState.php'] as $expected) {
            self::assertArrayHasKey($expected, $files, "the library file {$expected} is missing");
        }
        foreach ($files as $relative => $absolute) {
            self::assertSame([], self::violations($relative, (string) file_get_contents($absolute)), "app/Machine/ErrorFile/{$relative} breaks pm/error_err.mdx §4.1 / R12");
        }
    }

    public function testEveryFileCarriesTheHeaderAndStrictTypes(): void
    {
        foreach (self::libraryFiles() as $relative => $absolute) {
            $source = (string) file_get_contents($absolute);
            self::assertStringContainsString('GNU Affero General Public License', $source, $relative);
            self::assertStringContainsString('declare(strict_types=1);', $source, $relative);
            self::assertStringContainsString('namespace FireflyIII\Machine\ErrorFile', $source, $relative);
        }
    }

    /** The canary: the detector itself must catch a planted accessor, in code and not in comments. */
    public function testTheDetectorCatchesPlantedAccessors(): void
    {
        $planted = [
            '<?php $x = request()->all();',
            '<?php $x = $request->input("amount");',
            '<?php $x = $request->header("Authorization");',
            '<?php $x = $request->headers->get("Cookie");',
            '<?php $x = $_COOKIE["s"];',
            '<?php $x = $_SERVER["HTTP_AUTHORIZATION"];',
            '<?php $x = $request->getContent();',
            '<?php $x = file_get_contents(\'php://input\');',
            '<?php foreach ($e->getTrace() as $f) { $a = $f[\'args\']; }',
            '<?php $x = $e->getTraceAsString();',
            '<?php echo "x";',
            '<?php print "x";',
            '<?php \Log::error("x");',
            '<?php Log::error("x");',
            '<?php report($e);',
            '<?php logger("x");',
            '<?php $request->fullUrl();',
            '<?php $request->bearerToken();',
        ];
        foreach ($planted as $source) {
            self::assertNotSame([], self::violations('Redactor.php', $source), 'not caught: '.$source);
        }
        self::assertSame([], self::violations('Redactor.php', "<?php\n// echo print Log:: report( \$_SERVER ->header( getTraceAsString\n/** request()->all() */\n\$x = sprintf('%s', 1);"));
        self::assertSame([], self::violations('Paths.php', "<?php \$h = \$_SERVER['HOME'] ?? getenv('HOME');"));
        self::assertNotSame([], self::violations('Redactor.php', "<?php \$h = \$_SERVER['HOME'];"), 'the allowlist is per file');
        self::assertNotSame([], self::violations('Paths.php', "<?php \$h = \$_SERVER['argv'];"), 'the allowlist is per call');
        self::assertNotSame([], self::violations('Context.php', "<?php \$request->header('Authorization');"), 'only the named headers');
        self::assertSame([], self::violations('Context.php', "<?php \$request->header('X-Firefly-Request-Id'); \$request->header('X-Firefly-Client');"));
        self::assertSame([], self::violations('Ingest.php', "<?php \$h = fopen('php://input', 'rb');"));
    }

    /** @return list<string> */
    private static function violations(string $relative, string $source): array
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                    $code .= ' ';

                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }
        foreach (self::ALLOW[$relative] ?? [] as $allowed) {
            $code = str_replace($allowed, ' ', $code);
        }
        $found = [];
        foreach (self::FORBIDDEN_LITERALS as $literal) {
            if (str_contains($code, $literal)) {
                $found[] = $literal;
            }
        }
        foreach (self::FORBIDDEN_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $code)) {
                $found[] = $pattern;
            }
        }

        return $found;
    }

    /** @return array<string, string> relative path => absolute path */
    private static function libraryFiles(): array
    {
        $root  = (string) realpath(self::LIBRARY);
        $files = [];
        $walk  = static function (string $dir) use (&$walk, &$files, $root): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $dir.'/'.$entry;
                if (is_dir($path)) {
                    // Canary/ is not library code (R1, V9, §13.4): its controller must call Log:: and
                    // abort() to make the nets fire, and the coverage script enforces it like app/Machine.
                    if ($path === $root.'/Canary') {
                        continue;
                    }
                    $walk($path);
                } elseif (str_ends_with($entry, '.php')) {
                    $files[substr($path, strlen($root) + 1)] = $path;
                }
            }
        };
        $walk($root);
        ksort($files);

        return $files;
    }
}
