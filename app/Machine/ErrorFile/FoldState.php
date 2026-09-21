<?php

/*
 * FoldState.php
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

use stdClass;
use Throwable;

/**
 * The cross-process fold state — pm/error_err.mdx §3.5 and §4.7 (L2, the file budget and the
 * ingest rate slot), kept in the `error.fold` sidecar next to error.err:
 *
 *   {"v":1, "k":{key:{f,s,n,l,a,W,d,h}}, "b":{w,n,x}, "r":{w,g,x,c:{sid:n}}}
 *
 * One transaction per fault: open() (ensureDir, fopen c+, a bounded LOCK_EX) → handOver() for any
 * rollover or shutdown-flush count → admit() and/or rate() → drainQueued() (summaries, recovery and
 * budget WARNs, already formatted) → the caller appends those lines, then saveAndClose() rewrites
 * the sidecar in place and unlocks. The lock order is always error.fold before error.err.
 *
 * The first call among admit()/rate()/drainQueued() runs the sweep: the budget and rate windows
 * roll (a closed window with drops queues its one WARN), a reset queues its one WARN, and every
 * closed fold window (or a clock that went backwards) queues its summary. Every line queued here
 * is charged to the file budget and never dropped; only a new-key record can be dropped by it.
 *
 * Every public method is total.
 */
final class FoldState
{
    public const string RESET_WARN = 'the fold state was unreadable and was reset';
    public const string SELF       = 'app/Machine/ErrorFile/FoldState.php';
    public const string INGEST     = 'app/Machine/ErrorFile/Ingest.php';

    /** A browser sid that passed guard 6, or the fixed bucket. */
    private const string SID = '/^(?:[a-z0-9]{8}|_)$/';

    /** @var array<string, array{f: int, s: int, n: int, l: string, a: string, W: string, d: string, h: string}> */
    private array $k = [];

    /** @var array{w: int, n: int, x: int} */
    private array $b;

    /** @var array{w: int, g: int, x: int, c: array<string, int>} */
    private array $r;

    /** @var list<string> */
    private array $queued = [];

    private bool $prepared  = false;
    private bool $wasReset  = false;
    private bool $closed    = false;
    private readonly int $window;

    /**
     * @param resource $handle
     */
    private function __construct(
        private readonly string $errorPath,
        private $handle,
        private readonly int $now,
        private readonly string $app,
    ) {
        $this->window = max(1, Paths::int('errorfile.fold_window_s', 60)) * 1000;
        $this->b      = $this->freshBudget();
        $this->r      = $this->freshRate();
    }

    /**
     * Open and lock the sidecar next to $errorPath. Null on ANY open or lock failure: the caller
     * then writes the record unfolded (§4.7 step 2) — it may be duplicated, never lost.
     *
     * @param string $app the writer's `app`, stamped on a reset or budget WARN
     */
    public static function open(string $errorPath, int $nowMs, string $app = 'php-artisan'): ?self
    {
        try {
            $fold = Paths::foldFile($errorPath);
            if (null === $fold || !Paths::ensureDir($fold)) {
                return null;
            }
            $old = umask(0o077);

            try {
                $handle = @fopen($fold, 'c+');
            } finally {
                umask($old);
            }
            if (false === $handle) {
                return null;
            }
            if (!Appender::lockBounded($handle, $fold)) {
                @fclose($handle);

                return null;
            }
            $state = new self($errorPath, $handle, $nowMs, '' === $app ? 'php-artisan' : $app);
            $state->load();

            return $state;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * An owed count from L1 — a rollover (§4.7 step 1/4) or the shutdown flush. If L2 still holds
     * the SAME window (same `f`), the count joins it, so its summary carries the full count;
     * otherwise the summary for that old window is queued directly. A count never opens a window.
     * Must run before admit()/rate()/drainQueued() (they sweep).
     *
     * @param array{l: string, a: string, W: string, d: string, h: string} $meta
     */
    public function handOver(string $key, int $f, int $count, array $meta): void
    {
        try {
            if ($count <= 0) {
                return;
            }
            $this->rollBudget();
            if (isset($this->k[$key]) && $this->k[$key]['f'] === $f) {
                $this->k[$key]['n'] += $count;
                $this->k[$key]['s'] = max($this->k[$key]['s'], $this->now);

                return;
            }
            $this->queueSummary($meta, $count, $f);
        } catch (Throwable) {
            // total
        }
    }

    /**
     * Admit one non-FATAL record under its fold key (§4.7 steps 5–6).
     *
     * @param array{l: string, a: string, W: string, d: string, h: string} $meta
     *
     * @return array{state: 'written'|'folded'|'dropped', f: int}
     *                                                            written = queue the record (charged to the budget); folded = an open window counted it;
     *                                                            dropped = the file budget refused it
     */
    public function admit(string $key, array $meta, int $nowMs): array
    {
        try {
            $this->prepare();
            if (isset($this->k[$key])) {
                ++$this->k[$key]['n'];
                $this->k[$key]['s'] = $nowMs;

                return ['state' => 'folded', 'f' => $this->k[$key]['f']];
            }
            if (!$this->budget(true)) {
                return ['state' => 'dropped', 'f' => $nowMs];
            }
            $cap           = max(1, Paths::int('errorfile.fold_field_max_chars', 200));
            $this->k[$key] = [
                'f' => $nowMs,
                's' => $nowMs,
                'n' => 0,
                'l' => mb_substr($meta['l'], 0, 16),
                'a' => mb_substr($meta['a'], 0, 32),
                'W' => mb_substr($meta['W'], 0, $cap),
                'd' => mb_substr($meta['d'], 0, $cap),
                'h' => mb_substr($meta['h'], 0, $cap),
            ];
            $max           = max(1, Paths::int('errorfile.fold_max_keys', 1000));
            while (count($this->k) > $max) {
                $this->evictOldest($key);
            }

            return ['state' => 'written', 'f' => $nowMs];
        } catch (Throwable) {
            return ['state' => 'written', 'f' => $nowMs];
        }
    }

    /**
     * Charge one line to the file budget (§4.7 step 6). A droppable line (a new-key record) is
     * refused at the cap and counted in `b.x`; anything else is always charged and allowed.
     */
    public function budget(bool $droppable = false): bool
    {
        $this->rollBudget();
        $cap = max(1, Paths::int('errorfile.file_budget_per_minute', 600));
        if ($droppable && $this->b['n'] >= $cap) {
            ++$this->b['x'];

            return false;
        }
        ++$this->b['n'];

        return true;
    }

    /**
     * The ingest rate slot (§4.10 guard 7): how many of $events from $sid are accepted this window.
     * Rate drops and the event-cap drops ($capDrops, guard 6) both go to `r.x`; a closed window with
     * `r.x > 0` produces exactly one WARN, written lazily by the first write after it closes.
     */
    public function rate(string $sid, int $events, int $capDrops = 0): int
    {
        try {
            $this->prepare();
            if (1 !== preg_match(self::SID, $sid)) {
                $sid = '_';
            }
            $events   = max(0, $events);
            $perSid   = max(0, Paths::int('errorfile.ingest.rate_client', 240));
            $global   = max(0, Paths::int('errorfile.ingest.rate_global', 1200));
            $clients  = max(1, Paths::int('errorfile.ingest.rate_max_clients', 200));
            if (!array_key_exists($sid, $this->r['c'])) {
                while (count($this->r['c']) >= $clients) {
                    unset($this->r['c'][array_key_first($this->r['c'])]);
                }
                $this->r['c'][$sid] = 0;
            }
            $accepted = max(0, min($events, $perSid - $this->r['c'][$sid], $global - $this->r['g']));
            $this->r['c'][$sid] += $accepted;
            $this->r['g'] += $accepted;
            $this->r['x'] += ($events - $accepted) + max(0, $capDrops);

            return $accepted;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Every queued line (summaries, recovery, budget and ingest WARNs), formatted, in order; the
     * queue is emptied. Also enforces the sidecar's byte cap (§3.5), queueing the owed summary of
     * every key it evicts, so those lines go out in the same append.
     *
     * @return list<string>
     */
    public function drainQueued(): array
    {
        try {
            $this->prepare();
            $this->enforceByteCap();
            $out          = $this->queued;
            $this->queued = [];

            return $out;
        } catch (Throwable) {
            $out          = $this->queued;
            $this->queued = [];

            return $out;
        }
    }

    /**
     * Rewrite the sidecar in place (ftruncate, rewind, one fwrite, fflush) and unlock (§4.7 step 8).
     * A summary queued after the caller's drain (only the byte cap can do that) is appended here.
     */
    public function saveAndClose(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            $late = $this->drainQueued();
            if ([] !== $late) {
                Appender::append($this->errorPath, implode('', $late));
            }
            $json = $this->encode();
            if (is_string($json)) {
                @ftruncate($this->handle, 0);
                @rewind($this->handle);
                @fwrite($this->handle, $json);
                @fflush($this->handle);
            }
        } catch (Throwable) {
            // total: a lost count at worst
        } finally {
            @flock($this->handle, LOCK_UN);
            @fclose($this->handle);
        }
    }

    /** Test seam: the decoded state as it would be saved. @return array<string, mixed> */
    public function snapshot(): array
    {
        return ['k' => $this->k, 'b' => $this->b, 'r' => $this->r];
    }

    private function load(): void
    {
        $max  = max(1, Paths::int('errorfile.fold_file_max_bytes', 1_048_576));
        $stat = @fstat($this->handle);
        $size = is_array($stat) ? (int) $stat['size'] : 0;
        if (0 === $size) {
            return;
        }
        if ($size >= $max) {
            $this->wasReset = true;

            return;
        }
        @rewind($this->handle);
        $raw = stream_get_contents($this->handle, $max);
        if (!is_string($raw) || '' === trim($raw)) {
            // an empty or whitespace-only sidecar is a fresh one
            if (!is_string($raw)) {
                $this->wasReset = true;
            }

            return;
        }

        try {
            $doc = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->wasReset = true;

            return;
        }
        if (!$this->accept($doc)) {
            $this->wasReset = true;
            $this->k        = [];
            $this->b        = $this->freshBudget();
            $this->r        = $this->freshRate();
        }
    }

    /** Validate a decoded sidecar and adopt it. False = corrupt (§3.5 recovery). */
    private function accept(mixed $doc): bool
    {
        if (!is_array($doc) || 1 !== ($doc['v'] ?? null) || !is_array($doc['k'] ?? null) || !is_array($doc['b'] ?? null) || !is_array($doc['r'] ?? null)) {
            return false;
        }
        $k = [];
        foreach ($doc['k'] as $key => $e) {
            if (!is_array($e)) {
                return false;
            }
            foreach (['f', 's', 'n'] as $int) {
                if (!is_int($e[$int] ?? null)) {
                    return false;
                }
            }
            foreach (['l', 'a', 'W', 'd', 'h'] as $str) {
                if (!is_string($e[$str] ?? null)) {
                    return false;
                }
            }
            $k[(string) $key] = ['f' => $e['f'], 's' => $e['s'], 'n' => max(0, $e['n']), 'l' => $e['l'], 'a' => $e['a'], 'W' => $e['W'], 'd' => $e['d'], 'h' => $e['h']];
        }
        $b = $doc['b'];
        $r = $doc['r'];
        foreach (['w', 'n', 'x'] as $int) {
            if (!is_int($b[$int] ?? null)) {
                return false;
            }
        }
        foreach (['w', 'g', 'x'] as $int) {
            if (!is_int($r[$int] ?? null)) {
                return false;
            }
        }
        if (!is_array($r['c'] ?? null)) {
            return false;
        }
        $c = [];
        foreach ($r['c'] as $sid => $n) {
            if (!is_int($n) || 1 !== preg_match(self::SID, (string) $sid)) {
                return false;
            }
            $c[(string) $sid] = $n;
        }
        $this->k = $k;
        $this->b = ['w' => $b['w'], 'n' => $b['n'], 'x' => $b['x']];
        $this->r = ['w' => $r['w'], 'g' => $r['g'], 'x' => $r['x'], 'c' => $c];

        return true;
    }

    /** Roll windows, queue the reset WARN, sweep closed fold windows — once per transaction. */
    private function prepare(): void
    {
        if ($this->prepared) {
            return;
        }
        $this->prepared = true;
        $this->rollBudget();
        if ($this->wasReset) {
            $this->queueWarn($this->app, self::SELF, 'reading the fold state', self::RESET_WARN, ['pid' => getmypid()]);
        }
        if ($this->isClosed($this->r['w'])) {
            if ($this->r['x'] > 0) {
                $this->queueWarn('php-web', self::INGEST, 'receiving browser error reports', sprintf('dropped %d browser reports over the ingest limits', $this->r['x']), ['via' => 'php-web', 'pid' => getmypid()]);
            }
            $this->r = $this->freshRate();
        }
        foreach ($this->k as $key => $entry) {
            if ($this->isClosed($entry['f'])) {
                if ($entry['n'] > 0) {
                    $this->queueSummary($entry, $entry['n'], $entry['f']);
                }
                unset($this->k[$key]);
            }
        }
    }

    private function rollBudget(): void
    {
        if (!$this->isClosed($this->b['w'])) {
            return;
        }
        $dropped = $this->b['x'];
        $this->b = $this->freshBudget();
        if ($dropped > 0) {
            $this->queueWarn(
                $this->app,
                self::SELF,
                'enforcing the file budget',
                sprintf('dropped %d records over the file budget of %d/min', $dropped, max(1, Paths::int('errorfile.file_budget_per_minute', 600))),
                ['pid' => getmypid()]
            );
        }
    }

    /** A window that started at $start is closed once it is a full window old, or when the clock went backwards. */
    private function isClosed(int $start): bool
    {
        return $this->now < $start || $this->now - $start >= $this->window;
    }

    /** @param array{l: string, a: string, W: string, d: string, h: string} $meta */
    private function queueSummary(array $meta, int $count, int $f): void
    {
        $this->budget();
        $this->queued[] = LineFormat::summary(
            Level::tryFrom($meta['l']) ?? Level::Error,
            $meta['a'],
            $meta['W'],
            $meta['d'],
            $count,
            $f,
            $meta['h'],
            $this->now,
            intdiv($this->window, 1000),
        );
    }

    /** @param array<string, null|bool|float|int|string> $data */
    private function queueWarn(string $app, string $where, string $doing, string $error, array $data): void
    {
        $this->budget();
        $this->queued[] = LineFormat::record(new Record(LineFormat::iso($this->now), Level::Warn, $app, $where, $doing, $error, data: $data));
    }

    /** LRU: the least-recently-seen key other than $keep; its owed count is summarised first. */
    private function evictOldest(?string $keep = null): void
    {
        $oldest = null;
        $seen   = PHP_INT_MAX;
        foreach ($this->k as $key => $entry) {
            if ($key !== $keep && $entry['s'] < $seen) {
                $seen   = $entry['s'];
                $oldest = $key;
            }
        }
        if (null === $oldest) {
            return;
        }
        $entry = $this->k[$oldest];
        unset($this->k[$oldest]);
        if ($entry['n'] > 0) {
            $this->queueSummary($entry, $entry['n'], $entry['f']);
        }
    }

    /** The writer enforces the byte cap, so a sidecar over 1 MiB on disk can only mean corruption. */
    private function enforceByteCap(): void
    {
        $max  = max(1, Paths::int('errorfile.fold_file_max_bytes', 1_048_576));
        $json = $this->encode();
        if (!is_string($json) || strlen($json) < $max) {
            return;
        }
        $size = strlen($json);
        while ($size >= $max && [] !== $this->k) {
            $before = count($this->k);
            // estimate the bytes an entry takes, then evict LRU until under the cap
            $key    = null;
            $seen   = PHP_INT_MAX;
            foreach ($this->k as $candidate => $entry) {
                if ($entry['s'] < $seen) {
                    $seen = $entry['s'];
                    $key  = $candidate;
                }
            }
            if (null === $key) {
                break;
            }
            $size -= strlen((string) json_encode([$key => $this->k[$key]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
            $this->evictOldest();
            if (count($this->k) === $before) {
                break;
            }
        }
    }

    private function encode(): ?string
    {
        $json = json_encode(
            [
                'v' => 1,
                'k' => [] === $this->k ? new stdClass() : $this->k,
                'b' => $this->b,
                'r' => ['w' => $this->r['w'], 'g' => $this->r['g'], 'x' => $this->r['x'], 'c' => (object) $this->r['c']],
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : null;
    }

    /** @return array{w: int, n: int, x: int} */
    private function freshBudget(): array
    {
        return ['w' => $this->now - ($this->now % $this->window), 'n' => 0, 'x' => 0];
    }

    /** @return array{w: int, g: int, x: int, c: array<string, int>} */
    private function freshRate(): array
    {
        return ['w' => $this->now - ($this->now % $this->window), 'g' => 0, 'x' => 0, 'c' => []];
    }
}
