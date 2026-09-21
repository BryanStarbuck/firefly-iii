<?php

/*
 * LogChannelSandbox.php
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

namespace Tests\Machine\ErrorFile\Fixtures;

use FireflyIII\Machine\ErrorFile\ErrorFileServiceProvider;
use Illuminate\Log\LogManager;
use Monolog\Logger as Monolog;

/**
 * Points Laravel's default log channel at a file inside the per-test sandbox, so the log-net tests
 * (pm/error_err.mdx §4.6, §4.9) never write upstream's daily file under storage/logs and never
 * print to the PHPUnit output. Re-attaches the KeepAliveHandler the way the provider's boot() does.
 */
trait LogChannelSandbox
{
    /** Make `$name` the default channel: a `single` file at `$file`, at `$level`. Returns the file. */
    protected function useSandboxLogChannel(string $name, string $level = 'debug', bool $keepAlive = true): string
    {
        $file = $this->sandbox.'/logs/'.$name.'.log';
        config([
            'logging.channels.'.$name => ['driver' => 'single', 'path' => $file, 'level' => $level, 'replace_placeholders' => true],
            'logging.default'         => $name,
        ]);
        if ($keepAlive) {
            ErrorFileServiceProvider::keepLogNetAlive();
        }

        return $file;
    }

    /** The Monolog logger behind a channel. */
    protected function monolog(?string $channel = null): Monolog
    {
        /** @var LogManager $log */
        $log    = app('log');
        $logger = (null === $channel ? $log->driver() : $log->channel($channel))->getLogger();
        self::assertInstanceOf(Monolog::class, $logger);

        return $logger;
    }
}
