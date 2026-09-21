<?php

/*
 * ErrorFileServiceProvider.php
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

use FireflyIII\Machine\ErrorFile\Canary\CanaryCommand;
use FireflyIII\Machine\ErrorFile\Canary\CanaryController;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Container\Container;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;
use Monolog\Logger as Monolog;
use Throwable;

/**
 * The wiring of the error file — pm/error_err.mdx §4.9. Registered as the FIRST statement of
 * MachinePlaneServiceProvider::register(); bootstrap/providers.php keeps its one fork line.
 *
 * register():
 *  1. masks SQL bindings in every connection's exception messages (§12) — connections are built
 *     lazily, so this lands before any QueryException;
 *  2. binds RequestState as a scoped singleton;
 *  3. listens to MessageLogged with LogListener (N4) — in register(), not boot(), so the net also
 *     sees faults thrown from every later provider's boot();
 *  4. CommandStarting/CommandFinished and JobProcessing/JobAttempted push and pop the command and
 *     job stacks (N6, N7); a non-zero CommandFinished keeps the popped name as lastFailedCommand;
 *     JobExceptionOccurred/JobFailed record the event's exception in the job-failure WeakMap, so
 *     the fault is still `php-queue` after the sync driver popped the frame and rethrew (§3.3).
 *
 * boot(): pushes the KeepAliveHandler onto the default channel's Monolog logger (N5), so the log
 * net stays alive above APP_LOG_LEVEL, loads the browser's ingest route, routes/error-report.php
 * (§4.10), and — ONLY when CanaryController::enabled() holds (FIREFLY_ERROR_FILE_CANARY=1 and a
 * non-empty FIREFLY_ERROR_FILE) — registers the canary command and the canary routes (§13.4). This
 * provider boots before MachinePlaneServiceProvider, so all of these routes sit ahead of the plane's
 * catch-all `{machine_any?}` (UpstreamAnchorsTest pins the order).
 *
 * Nothing here opens a file (R3): the listeners touch only RequestState. Every listener is total.
 */
final class ErrorFileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->maskBindings();
        $this->app->scoped(RequestState::class, static fn (): RequestState => new RequestState());
        $this->listenForContext();
        $this->listenForLogs();
    }

    public function boot(): void
    {
        self::keepLogNetAlive();
        // the browser's ingest route, outside /machine/v1 and outside the web and api groups (§4.10)
        $this->loadRoutesFrom(base_path('routes/error-report.php'));
        if (CanaryController::enabled()) {
            $this->commands([CanaryCommand::class]);
            self::registerCanaryRoutes();
        }
    }

    /**
     * The canary routes C1–C8 (§13.4): no middleware at all — the surface comes from the path prefix —
     * and registered before the plane's catch-all. Only ever called under CanaryController::enabled().
     */
    public static function registerCanaryRoutes(): void
    {
        try {
            $router  = Container::getInstance()->make('router');
            $segment = CanaryController::SEGMENT;
            $routes  = [
                [$segment.'/throw', 'throw', 'throw'],
                ['api/v1/'.$segment.'/throw', 'throw', 'api-throw'],
                ['machine/v1/'.$segment.'/internal', 'internal', 'machine-internal'],
                ['machine/v1/'.$segment.'/conflict', 'conflict', 'machine-conflict'],
                [$segment.'/abort500', 'abort500', 'abort500'],
                [$segment.'/log-bare', 'logBare', 'log-bare'],
                [$segment.'/log-duplicate', 'logDuplicate', 'log-duplicate'],
                [$segment.'/oom', 'oom', 'oom'],
            ];
            foreach ($routes as [$uri, $action, $name]) {
                $router->get($uri, [CanaryController::class, $action])->name('fork.error-file-canary.'.$name);
            }
        } catch (Throwable) {
            // total: a canary that did not register fails its own run, never the app
        }
    }

    /**
     * Push one KeepAliveHandler onto the default channel's Monolog logger, only when that logger IS
     * a Monolog logger and does not carry one already. Returns whether the default logger now carries
     * it. Total. Public for KeepAliveTest, which swaps the default channel and re-attaches.
     */
    public static function keepLogNetAlive(): bool
    {
        try {
            $logger = Container::getInstance()->make('log')->driver()->getLogger();
            if (!$logger instanceof Monolog) {
                return false;
            }
            foreach ($logger->getHandlers() as $handler) {
                if ($handler instanceof KeepAliveHandler) {
                    return true;
                }
            }
            $logger->pushHandler(new KeepAliveHandler());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function maskBindings(): void
    {
        try {
            $config      = $this->app->make('config');
            $connections = $config->get('database.connections');
            if (!is_array($connections)) {
                return;
            }
            foreach (array_keys($connections) as $name) {
                $config->set(sprintf('database.connections.%s.mask_bindings_in_exception_messages', $name), true);
            }
        } catch (Throwable) {
            // total: an unmasked binding is scrubbed again by the Redactor
        }
    }

    private function listenForLogs(): void
    {
        try {
            /** @var Dispatcher $events */
            $events   = $this->app->make('events');
            $listener = new LogListener();
            $events->listen(MessageLogged::class, static function (MessageLogged $event) use ($listener): void {
                $listener->handle($event);
            });
        } catch (Throwable) {
            // total: without the listener the handler net still writes every reported throwable
        }
    }

    private function listenForContext(): void
    {
        try {
            /** @var Dispatcher $events */
            $events = $this->app->make('events');
        } catch (Throwable) {
            return;
        }
        $events->listen(CommandStarting::class, static function (CommandStarting $event): void {
            try {
                RequestState::current()?->pushCommand((string) ($event->command ?? ''));
            } catch (Throwable) {
                // total
            }
        });
        $events->listen(CommandFinished::class, static function (CommandFinished $event): void {
            try {
                RequestState::current()?->popCommand($event->command, $event->exitCode);
            } catch (Throwable) {
                // total
            }
        });
        $events->listen(JobProcessing::class, static function (JobProcessing $event): void {
            try {
                RequestState::current()?->pushJob(self::jobName($event->job), Context::doingHere());
            } catch (Throwable) {
                // total
            }
        });
        $events->listen(JobAttempted::class, static function (JobAttempted $event): void {
            try {
                RequestState::current()?->popJob();
            } catch (Throwable) {
                // total
            }
        });
        $events->listen([JobExceptionOccurred::class, JobFailed::class], static function (JobExceptionOccurred|JobFailed $event): void {
            try {
                $state = RequestState::current();
                if (null === $state || !$event->exception instanceof Throwable) {
                    return;
                }
                $open = $state->openJob();
                $state->recordJobFailure($event->exception, $open['job'] ?? self::jobName($event->job), $open['during'] ?? Context::doingHere());
            } catch (Throwable) {
                // total
            }
        });
    }

    /** The job's class basename (`CanaryJob`), from the queued payload's display name. */
    private static function jobName(mixed $job): string
    {
        try {
            $name = is_object($job) && method_exists($job, 'resolveName') ? (string) $job->resolveName() : '';
        } catch (Throwable) {
            $name = '';
        }
        if ('' === $name) {
            return 'job';
        }
        $at = strrpos($name, '\\');

        return false === $at ? $name : substr($name, $at + 1);
    }
}
