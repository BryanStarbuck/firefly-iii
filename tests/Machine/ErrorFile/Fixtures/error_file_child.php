<?php

/*
 * error_file_child.php
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
 * A child process for the error-file tests (pm/error_err.mdx §15, "Child processes"). It boots the
 * application exactly as an artisan command would (APP_ENV=testing, so runningUnitTests() is true)
 * and therefore finds its path ONLY through the explicit child env the parent passes:
 * FIREFLY_ERROR_FILE_TEST_PATH (and FIREFLY_ERROR_FILE, which Paths must ignore under tests).
 *
 *   php error_file_child.php path
 *       prints Paths::errorFile() (or NULL) — NullPathTest.
 *   php error_file_child.php distinct <tag> <count> <max_bytes>
 *       <count> distinct records through Folder::admit — ConcurrencyTest (AC 7).
 *   php error_file_child.php identical <processes> <repeats>
 *       one identical fault, <processes> simulated processes × <repeats> each, every simulated
 *       process ending with its shutdown flush — ConcurrencyTest (AC 5).
 *   php error_file_child.php oom [busy]
 *       exhausts a 64M memory limit; with `busy`, while Reporter::$busy is set — FatalChildTest.
 *
 * Every record is synthetic. The parent passes a sandbox DB, credentials file and state directory,
 * so nothing here can touch the operator's real ledger, key or error file.
 */

use FireflyIII\Machine\ErrorFile\Folder;
use FireflyIII\Machine\ErrorFile\Level;
use FireflyIII\Machine\ErrorFile\LineFormat;
use FireflyIII\Machine\ErrorFile\Paths;
use FireflyIII\Machine\ErrorFile\Record;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__, 4);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';
$path = Paths::errorFile();

if ('path' === $mode) {
    fwrite(STDOUT, (null === $path ? 'NULL' : $path)."\n");

    exit(0);
}
if (null === $path) {
    fwrite(STDERR, "no sandbox path: the parent must pass FIREFLY_ERROR_FILE_TEST_PATH\n");

    exit(3);
}

if ('distinct' === $mode) {
    // letters only, and none of a–f: digits and hex runs would normalise to one fold key (§3.5)
    $tag   = (string) ($argv[2] ?? 'cg');
    $count = (int) ($argv[3] ?? 200);
    config(['errorfile.max_bytes' => (int) ($argv[4] ?? 65_536), 'errorfile.file_budget_per_minute' => 1_000_000, 'errorfile.fold_max_keys' => 1000]);
    $stack = [];
    for ($i = 1; $i <= 6; ++$i) {
        $stack[] = sprintf('at FireflyIII\Support\Synthetic\Concurrent%d->run%s (app/Support/Synthetic/Concurrent%d.php:%d)', $i, str_repeat('Deep', 30), $i, 10 * $i);
    }
    for ($r = 0; $r < $count; ++$r) {
        Folder::admit($path, new Record(
            ts: LineFormat::iso(Folder::now()),
            level: Level::Error,
            app: 'php-artisan',
            where: 'app/Support/Synthetic/Concurrent1.php:10',
            doing: 'running artisan firefly-iii:synthetic',
            error: sprintf('RuntimeException: Synthetic concurrent fault %s-%s %s', $tag, str_repeat(chr(103 + $r % 20), 1 + intdiv($r, 20)), str_repeat('x', 200)),
            stack: $stack,
            data: ['tag' => $tag, 'end' => 'yes'],
        ));
    }

    exit(0);
}

if ('identical' === $mode) {
    $processes = (int) ($argv[2] ?? 10);
    $repeats   = (int) ($argv[3] ?? 10);
    // This mode measures FOLDING, not the lock timeout: on a loaded machine a 200 ms wait can
    // expire and write a record unfolded (duplicated by design, §4.7 step 2), which would make the
    // count nondeterministic. The bounded wait itself is covered by AppenderTest / FoldStateTest.
    config(['errorfile.lock_wait_ms' => 10_000]);
    for ($p = 0; $p < $processes; ++$p) {
        Folder::resetForTests();   // a new process: empty L1
        for ($r = 0; $r < $repeats; ++$r) {
            Folder::admit($path, new Record(
                ts: LineFormat::iso(Folder::now()),
                level: Level::Error,
                app: 'php-machine',
                where: 'app/Machine/Ingest/Importer.php',
                doing: 'importing a statement row',
                error: 'RuntimeException: Synthetic identical fault',
                data: ['pid' => getmypid()],
            ));
        }
        Folder::flushOwed();       // that process's shutdown flush
    }

    exit(0);
}

if ('oom' === $mode) {
    if ('busy' === ($argv[2] ?? '')) {
        // a stale busy flag, as if the fatal struck while a record was being written (R9, §4.7)
        FireflyIII\Machine\ErrorFile\Reporter::$busy = true;
    }
    ini_set('memory_limit', '64M');
    $hog = [];
    while (true) {   // @phpstan-ignore while.alwaysTrue
        $hog[] = str_repeat('x', 1_048_576);
    }
}

fwrite(STDERR, "unknown mode\n");

exit(2);
