<?php

/*
 * MachinePlaneServiceProvider.php
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

namespace FireflyIII\Machine;

use FireflyIII\Machine\Credentials\CredentialsFile;
use FireflyIII\Machine\ErrorFile\ErrorFile;
use FireflyIII\Machine\Http\MachineExceptionHandler;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * The machine plane's one entry point — apis.mdx §3.2, §4.4. Registered by ONE line in
 * bootstrap/providers.php; everything else of the plane is a directory upstream has never heard
 * of, so an upstream merge cannot conflict with it.
 *
 *   register()  binds the envelope exception renderer for machine/v1 (Firefly's Handler, extended)
 *   boot()      registers routes/machine.php (its OWN middleware list — never the web or api
 *               groups), and — on an HTTP boot only, never a console or test boot — resolves the
 *               machine key, minting it into ~/.credentials/firefly_iii.json when there is none,
 *               and logs the "Machine plane armed …" line.
 */
final class MachinePlaneServiceProvider extends ServiceProvider
{
    private const string WHERE = 'app/Machine/MachinePlaneServiceProvider.php';

    public function register(): void
    {
        $this->app->register(\FireflyIII\Machine\ErrorFile\ErrorFileServiceProvider::class);   // FIRST statement (pm/error_err.mdx §4.9)
        $this->app->singleton(ExceptionHandler::class, MachineExceptionHandler::class);
    }

    public function boot(): void
    {
        $this->registerRoutes();
        if (!$this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            $this->armPlane();
        }
    }

    public function registerRoutes(): void
    {
        $this->loadRoutesFrom(base_path('routes/machine.php'));
    }

    /**
     * Resolve-or-mint the key (§4.4 rule 1: HTTP boots only) and announce the plane once per
     * server process and configuration. Never throws: a failure here must not take down the
     * web UI, and an unarmed plane answers 404 to everything anyway (R9).
     */
    public function armPlane(): void
    {
        try {
            $key = CredentialsFile::resolveOrMint();
            $sig = hash('sha256', implode('|', [
                $key?->fingerprint() ?? 'unarmed:'.(CredentialsFile::problem() ?? ''),
                (string) config('machine.operator'),
                (string) config('machine.administration'),
                config('machine.allow_write') ? 'w' : '-',
                config('machine.allow_admin') ? 'a' : '-',
                function_exists('posix_getppid') ? (string) posix_getppid() : '',
            ]));
            if (!$this->shouldAnnounce($sig)) {
                return;
            }
            $line = null === $key ? self::unarmedLine() : self::armedLine($key->fingerprint());
            Log::info($line);
            if (null === $key) {
                // a plane that stays unarmed is a fault the operator must see: CredentialsRefused
                // promises "the reason goes to the error file with its fix" (pm/error_err.mdx §8.1)
                ErrorFile::for('app/Machine/MachinePlaneServiceProvider.php')->warn('arming the machine plane', null, ['problem' => CredentialsFile::problem()]);
            }
            if ('cli-server' === PHP_SAPI) {
                @file_put_contents('php://stderr', $line."\n");
            }
        } catch (Throwable $e) {
            ErrorFile::for('app/Machine/MachinePlaneServiceProvider.php')->caught('arming the machine plane', $e);
        }
    }

    private static function armedLine(string $fingerprint): string
    {
        try {
            [$user, $group] = Operator::resolve();
            $who            = sprintf('operator %s, administration %d', $user->email, $group->id);
        } catch (MachineException $e) {
            ErrorFile::for(self::WHERE)->expected('resolving the operator for the armed line', $e);
            $who = sprintf('operator UNRESOLVED — %s', $e->getMessage());
        } catch (Throwable $e) {
            ErrorFile::for(self::WHERE)->expected('resolving the operator for the armed line', $e);
            $who = 'operator UNRESOLVED — the database is not reachable';
        }

        return sprintf(
            'Machine plane armed on /machine/v1 (key %s, %s, writes %s%s)',
            $fingerprint,
            $who,
            config('machine.allow_write') ? 'ENABLED' : 'DISABLED',
            config('machine.allow_admin') ? ', admin ENABLED' : '',
        );
    }

    private static function unarmedLine(): string
    {
        return sprintf(
            'Machine plane NOT armed on /machine/v1 — it answers 404 to everything: %s (fix: %s)',
            CredentialsFile::problem() ?? 'no machine key',
            CredentialsFile::fix() ?? 'php artisan firefly-machine:key --init',
        );
    }

    /**
     * Once per (key, operator setting, switches, server process): a marker in the state
     * directory remembers the last announcement, because the built-in server boots the app on
     * every request.
     */
    private function shouldAnnounce(string $sig): bool
    {
        $dir = Audit::stateDir();
        if (null === $dir) {
            return true;
        }
        $marker = $dir.'/machine.armed';
        if (is_file($marker) && trim((string) @file_get_contents($marker)) === $sig) {
            return false;
        }
        if (!is_dir($dir)) {
            $old = umask(0o077);
            @mkdir($dir, 0o700, true);
            umask($old);
        }
        $old = umask(0o077);
        @file_put_contents($marker, $sig."\n", LOCK_EX);
        umask($old);

        return true;
    }
}
