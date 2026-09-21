<?php

/*
 * UpstreamAnchorsTest.php
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

use FireflyIII\Exceptions\Handler;
use FireflyIII\Machine\ErrorFile\ErrorFileServiceProvider;
use FireflyIII\Machine\MachinePlaneServiceProvider;
use FireflyIII\Machine\Http\MachineExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler as LaravelHandler;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\CoversNothing;
use ReflectionClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Machine\MachineTestCase;

/**
 * The upstream facts the error file leans on (pm/error_err.mdx §17.2, R15). One fact per method;
 * each failure message names the section of pm/error_err.mdx to revisit. After
 * `git merge upstream/develop`, a changed assumption shows up here as a named red test instead of
 * as a silent hole in the error file (§17.3 step 2).
 *
 * Read-only: every check is a reflection check or a fixed-string read of an upstream or vendor
 * source file. Nothing here writes anywhere.
 *
 * The boot-file marker lines landed with step 11; the error-file routes ahead of the plane's
 * catch-all (the ingest route, step 9, and the canary routes, step 12) are pinned since step 12.
 *
 * @internal
 */
#[CoversNothing]
final class UpstreamAnchorsTest extends MachineTestCase
{
    /** The marker every fork line in an upstream boot file carries (§17.1). */
    private const string BOOT_MARKER = '// fork: pm/error_err.mdx';

    public function testHandlerExtendsLaravelsHandler(): void
    {
        self::assertTrue(
            is_subclass_of(Handler::class, LaravelHandler::class),
            'FireflyIII\Exceptions\Handler no longer extends Illuminate\Foundation\Exceptions\Handler: '
            .'revisit pm/error_err.mdx §4.5 (the handler net) — MachineExceptionHandler::report() relies on it.'
        );
    }

    public function testDontReportContainsHttpException(): void
    {
        $defaults = new ReflectionClass(Handler::class)->getDefaultProperties();
        $list     = $defaults['dontReport'] ?? null;
        self::assertIsArray($list, 'Handler::$dontReport is gone: revisit pm/error_err.mdx §4.5 (the ≥ 500 override) and R7.');
        self::assertContains(
            HttpException::class,
            $list,
            'Handler::$dontReport no longer lists HttpException: revisit pm/error_err.mdx §4.5 (the ≥ 500 override) and R7.'
        );
    }

    public function testMailErrorDumpsTheRequestWithTheExpectedPrefix(): void
    {
        self::assertStringContainsString(
            "'Exception is: %s'",
            $this->source('app/Jobs/MailError.php'),
            "app/Jobs/MailError.php no longer logs 'Exception is: %s': revisit pm/error_err.mdx §4.6 step 5 "
            .'and ExpectedMessages::MAIL_ERROR (the request dump must still be dropped).'
        );
    }

    public function testQueryExceptionMessageCarriesTheConnectionCut(): void
    {
        self::assertStringContainsString(
            "' (Connection: '",
            $this->source('vendor/laravel/framework/src/Illuminate/Database/QueryException.php'),
            "Laravel's QueryException message no longer contains ' (Connection: ': revisit pm/error_err.mdx §12 "
            .'(the SQL-text cut is one of the two redaction layers).'
        );
    }

    public function testConnectionReadsMaskBindingsInExceptionMessages(): void
    {
        self::assertStringContainsString(
            "'mask_bindings_in_exception_messages'",
            $this->source('vendor/laravel/framework/src/Illuminate/Database/Connection.php'),
            "Laravel's Connection no longer reads mask_bindings_in_exception_messages: revisit pm/error_err.mdx "
            .'§4.9 step 1 and §12 (SQL bindings would reach the error file unmasked).'
        );
    }

    public function testMessageLoggedHasLevelMessageAndContext(): void
    {
        $class = new ReflectionClass(MessageLogged::class);
        foreach (['level', 'message', 'context'] as $name) {
            self::assertTrue(
                $class->hasProperty($name) && $class->getProperty($name)->isPublic(),
                sprintf('Illuminate\Log\Events\MessageLogged has no public $%s: revisit pm/error_err.mdx §4.6 (the log net).', $name)
            );
        }
    }

    public function testTheBoundExceptionHandlerIsTheMachineHandler(): void
    {
        self::assertInstanceOf(
            MachineExceptionHandler::class,
            app(ExceptionHandler::class),
            'app(ExceptionHandler::class) is not MachineExceptionHandler: revisit pm/error_err.mdx §4.5 and §4.9 '
            .'(the handler net is the bound singleton).'
        );
    }

    public function testAxiosBootModuleExportsApi(): void
    {
        self::assertMatchesRegularExpression(
            '/^export\s*\{[^}]*\bapi\b[^}]*\}/m',
            $this->source('resources/assets/v3/js/boot/axios.js'),
            'resources/assets/v3/js/boot/axios.js no longer exports { api }: revisit pm/error_err.mdx §6.1 '
            .'(the glue module patches that instance).'
        );
    }

    public function testBothBootFilesCarryTheMarkerLine(): void
    {
        foreach (['resources/assets/v3/js/boot/bootstrap.js', 'resources/assets/v3/js/boot/blank-bootstrap.js'] as $file) {
            self::assertSame(
                1,
                substr_count($this->source($file), self::BOOT_MARKER),
                sprintf('%s does not carry exactly one "%s" line: revisit pm/error_err.mdx §6.1 and §17.3 '
                    .'(take upstream, then re-add the one marker line).', $file, self::BOOT_MARKER)
            );
        }
    }

    public function testErrorFileRoutesAreRegisteredBeforeThePlaneCatchAll(): void
    {
        // 1. ErrorFileServiceProvider (which loads the ingest route and, under the canary switch, the
        //    canary routes) is registered — and so booted — before MachinePlaneServiceProvider.
        $providers = array_keys(app()->getLoadedProviders());
        $errorFile = array_search(ErrorFileServiceProvider::class, $providers, true);
        $plane     = array_search(MachinePlaneServiceProvider::class, $providers, true);
        self::assertIsInt($errorFile, 'ErrorFileServiceProvider is not loaded: revisit pm/error_err.mdx §4.9.');
        self::assertIsInt($plane, 'MachinePlaneServiceProvider is not loaded: revisit pm/error_err.mdx §4.9.');
        self::assertLessThan($plane, $errorFile, 'ErrorFileServiceProvider boots after the machine plane: revisit pm/error_err.mdx §4.9 '
            .'(it must be the FIRST statement of MachinePlaneServiceProvider::register()).');

        // 2. In the live route table, the ingest route sits ahead of the plane's catch-all.
        $uris     = array_map(static fn (Route $r): string => $r->uri(), array_values(app('router')->getRoutes()->getRoutes()));
        $ingest   = array_search('error-report', $uris, true);
        $catchAll = array_search('machine/v1/{machine_any?}', $uris, true);
        self::assertIsInt($ingest, 'the error-report route is not registered: revisit pm/error_err.mdx §4.10.');
        self::assertIsInt($catchAll, 'the plane\'s catch-all machine/v1/{machine_any?} moved: revisit pm/error_err.mdx §13.4.');
        self::assertLessThan($catchAll, $ingest, 'error-report is registered after the plane\'s catch-all: revisit pm/error_err.mdx §4.9.');

        // 3. The canary routes, registered the way boot() does it (before routes/machine.php), win
        //    against the catch-all for a /machine/v1 path. Done on a scratch router, so the app's own
        //    route table is untouched (the canary switch is off under PHPUnit).
        $original = app('router');
        $scratch  = new Router(app('events'), app());
        app()->instance('router', $scratch);
        RouteFacade::clearResolvedInstance('router');

        try {
            ErrorFileServiceProvider::registerCanaryRoutes();
            require base_path('routes/machine.php');
            $matched = $scratch->getRoutes()->match(Request::create('/machine/v1/__error-file-canary/internal', 'GET'));
            self::assertSame('fork.error-file-canary.machine-internal', $matched->getName(), 'a canary route under /machine/v1 lost to the plane\'s catch-all: '
                .'revisit pm/error_err.mdx §4.9 and §13.4 (register the canary routes before the plane\'s routes).');
        } finally {
            app()->instance('router', $original);
            RouteFacade::clearResolvedInstance('router');
        }
    }

    private function source(string $relative): string
    {
        $path = base_path($relative);
        self::assertFileExists($path, sprintf('%s is gone: revisit pm/error_err.mdx §17.2.', $relative));
        $text = file_get_contents($path);
        self::assertIsString($text);

        return $text;
    }
}
