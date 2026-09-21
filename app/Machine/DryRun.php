<?php

/*
 * DryRun.php
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

use Closure;
use FireflyIII\Support\Singleton\PreferencesSingleton;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\BusFake;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Throwable;

/**
 * The rolled-back-transaction harness — apis.mdx §7.2 (R4): the preview IS the write.
 *
 * run($fn) opens a database transaction, calls $fn — the exact service code the real write
 * calls — and ALWAYS rolls back, even when $fn returns normally. Firefly's own event listeners
 * keep running inside it (rules, running balances, primary-currency amounts), with the queue
 * forced to `sync` so queued listeners run inline and roll back with everything else. The side
 * effects that do not live in the database are held:
 *
 *   webhooks                  counted (webhook_messages rows the write created) and rolled back;
 *                             their SendWebhookMessage jobs are held by the Bus recorder
 *   jobs, mail, notifications Bus, Mail and Notification are swapped for recorders and swapped
 *                             back afterwards; nothing is sent
 *
 * Firefly's caches are keyed on the operator's lastActivity marker; a dry run's mark() is rolled
 * back in the database, so the cached marker and the per-request singleton are reset too, so
 * nothing computed from rolled-back rows can be served from cache later.
 */
final class DryRun
{
    /**
     * @template T
     *
     * @param Closure(): T $fn
     */
    public static function run(Closure $fn): DryRunResult
    {
        $originals    = [
            'bus'          => Bus::getFacadeRoot(),
            'mail'         => Mail::getFacadeRoot(),
            'notification' => Notification::getFacadeRoot(),
        ];
        $queueDefault = config('queue.default');
        $level        = DB::transactionLevel();

        $bus          = Bus::fake();
        $mail         = Mail::fake();
        $notification = Notification::fake();
        config(['queue.default' => 'sync']);

        $webhooks     = 0;

        try {
            DB::beginTransaction();
            $before   = self::webhookMessages();
            $value    = $fn();
            $webhooks = max(0, self::webhookMessages() - $before);
        } finally {
            if (DB::transactionLevel() > $level) {
                DB::rollBack($level);
            }
            Bus::swap($originals['bus']);
            Mail::swap($originals['mail']);
            Notification::swap($originals['notification']);
            config(['queue.default' => $queueDefault]);
            self::forgetActivityMarker();
        }

        return new DryRunResult(
            value        : $value,
            webhooks     : $webhooks,
            jobs         : self::countBus($bus),
            mails        : self::countMail($mail),
            notifications: self::countNotifications($notification),
        );
    }

    private static function webhookMessages(): int
    {
        try {
            return DB::table('webhook_messages')->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private static function forgetActivityMarker(): void
    {
        PreferencesSingleton::getInstance()->resetPreferences();
        $user = Auth::user();
        if (null !== $user) {
            Cache::forget(sprintf('preference%slastActivity', $user->getAuthIdentifier()));
        }
    }

    private static function countBus(BusFake $fake): int
    {
        $read = function (): int {
            $n = 0;
            foreach ([$this->commands ?? [], $this->commandsSync ?? [], $this->commandsAfterResponse ?? []] as $bucket) {
                foreach ($bucket as $list) {
                    $n += count($list);
                }
            }

            return $n;
        };

        try {
            return $read->call($fake);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function countMail(MailFake $fake): int
    {
        $read = fn (): int => count($this->mailables ?? []) + count($this->queuedMailables ?? []);

        try {
            return $read->call($fake);
        } catch (Throwable) {
            return 0;
        }
    }

    private static function countNotifications(NotificationFake $fake): int
    {
        $read = function (): int {
            // [notifiable class][notifiable key][notification class] => list of sends
            $n = 0;
            foreach ($this->notifications ?? [] as $byKey) {
                foreach ((array) $byKey as $byClass) {
                    foreach ((array) $byClass as $sends) {
                        $n += count((array) $sends);
                    }
                }
            }

            return $n;
        };

        try {
            return $read->call($fake);
        } catch (Throwable) {
            return 0;
        }
    }
}
