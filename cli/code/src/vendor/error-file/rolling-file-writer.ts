// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs
// NODE ONLY — pm/error_err.mdx §5.5. Ported from Actual Budget's packages/error-file (itself from the
// Internal Web App's packages/logging). Changes from the original:
//
//   • files are created at mode 0600 and the directory at 0700 (§3.1, §12);
//   • the first-use prepare (mkdir + stat) is ASYNC on the drain path, so no synchronous fs call is
//     made anywhere but the overflow drain, flush(), and a roll;
//   • several processes append to one file (PHP, ffx, mcp), so before a roll the REAL size is
//     checked: a process that finds the file already rolled by someone else resets its cache
//     instead of rolling a second time;
//   • fs is reached through the module object (`fs.appendFile`), so a test can observe it.
//
// WRITES ARE BUFFERED AND ASYNCHRONOUS. `write()` pushes onto an in-memory buffer and returns
// without touching the filesystem. One unref'd 5 ms timer drains the buffer with async
// `fs.appendFile`, one call per batch, so a batch is one O_APPEND write and lines from different
// processes never interleave mid-line. The buffer is drained SYNCHRONOUSLY when it reaches 256 KiB
// (a runaway loop must not grow memory) and by `flush()` — what fatal, exit and the signal
// handlers call. A line is at risk for at most 5 ms, and only against a death that runs no
// JavaScript at all (SIGKILL, power cut).
//
// This is the ONE vendored module that calls fs write APIs; the MCP no-fs-write canary allowlists
// exactly `vendor/error-file/rolling-file-writer.js` (§5.6). node.ts calls none.

import fs from 'node:fs';
import { dirname } from 'node:path';

import { unrefTimer } from './fold.js';

export type RollingFileWriterOptions = {
  /** Absolute path to the active file. */
  filePath: string;
  /** Roll the file once it reaches this many bytes. Default 5 MiB. */
  maxBytes?: number;
  /** How many rotated files (`<file>.1` … `<file>.N`) to keep. Default 5. */
  maxBackups?: number;
  /** Where a failed write goes instead. Default process.stderr. Test seam. */
  fallback?: (data: string) => void;
};

const DEFAULT_MAX_BYTES = 5 * 1024 * 1024;
const DEFAULT_MAX_BACKUPS = 5;
const FLUSH_INTERVAL_MS = 5;
const MAX_BUFFERED_BYTES = 256 * 1024;
const FILE_MODE = 0o600;
const DIR_MODE = 0o700;

/** One process-wide `exit` hook drains every live writer (registered lazily, once). */
const liveWriters = new Set<RollingFileWriter>();
let exitHookInstalled = false;

function ensureExitHook(): void {
  if (exitHookInstalled) return;
  exitHookInstalled = true;
  try {
    process.on('exit', () => {
      for (const w of liveWriters) w.flush();
    });
  } catch {
    // no usable process object; explicit flush() calls still cover us
  }
}

function defaultFallback(data: string): void {
  try {
    process.stderr.write(data);
  } catch {
    // nothing else we can safely do
  }
}

export class RollingFileWriter {
  readonly filePath: string;
  private readonly maxBytes: number;
  private readonly maxBackups: number;
  private readonly bufferCap: number;
  private readonly fallbackWrite: (data: string) => void;
  /** Cached size of the active file, so there is no stat per write. */
  private size = 0;
  private ready = false;
  private preparing = false;
  private pending: string[] = [];
  private pendingBytes = 0;
  private inFlight = false;
  private timer: ReturnType<typeof setTimeout> | null = null;

  constructor(opts: RollingFileWriterOptions) {
    this.filePath = opts.filePath;
    this.maxBytes = Math.max(1024, opts.maxBytes ?? DEFAULT_MAX_BYTES);
    this.maxBackups = Math.max(0, opts.maxBackups ?? DEFAULT_MAX_BACKUPS);
    this.bufferCap = Math.min(MAX_BUFFERED_BYTES, this.maxBytes);
    this.fallbackWrite = opts.fallback ?? defaultFallback;
  }

  /** Buffer one formatted line. No filesystem work, except the overflow drain. Never throws. */
  write(line: string): void {
    try {
      const data = line.endsWith('\n') ? line : `${line}\n`;
      this.pending.push(data);
      this.pendingBytes += Buffer.byteLength(data);
      liveWriters.add(this);
      ensureExitHook();
      if (this.pendingBytes >= this.bufferCap) {
        this.flush();
        return;
      }
      this.schedule();
    } catch {
      // a writer must never crash the process it instruments
    }
  }

  /** Synchronous barrier: everything buffered is on disk (or on stderr) when this returns. */
  flush(): void {
    if (this.timer !== null) {
      clearTimeout(this.timer);
      this.timer = null;
    }
    if (this.pendingBytes === 0) return;
    const data = this.pending.join('');
    const bytes = this.pendingBytes;
    this.pending = [];
    this.pendingBytes = 0;
    this.prepareSync();
    this.emitBatch(data, bytes, true);
  }

  private schedule(): void {
    if (this.timer !== null || this.inFlight || this.preparing) return;
    const t = setTimeout(() => {
      this.timer = null;
      this.drainAsync();
    }, FLUSH_INTERVAL_MS);
    unrefTimer(t);
    this.timer = t;
  }

  private drainAsync(): void {
    if (this.inFlight || this.preparing || this.pendingBytes === 0) return;
    if (!this.ready) {
      this.prepareAsync();
      return;
    }
    const data = this.pending.join('');
    const bytes = this.pendingBytes;
    this.pending = [];
    this.pendingBytes = 0;
    this.emitBatch(data, bytes, false);
  }

  /** First use (or after a failure): create the directory and seed the size, off the loop. */
  private prepareAsync(): void {
    this.preparing = true;
    fs.mkdir(
      dirname(this.filePath),
      { recursive: true, mode: DIR_MODE },
      mkdirErr => {
        if (mkdirErr) {
          this.preparing = false;
          this.failPending();
          return;
        }
        fs.stat(this.filePath, (statErr, stats) => {
          this.preparing = false;
          this.size = statErr ? 0 : stats.size;
          this.ready = true;
          if (this.pendingBytes > 0) this.drainAsync();
        });
      },
    );
  }

  private prepareSync(): void {
    if (this.ready) return;
    try {
      fs.mkdirSync(dirname(this.filePath), { recursive: true, mode: DIR_MODE });
      this.size = fs.existsSync(this.filePath) ? fs.statSync(this.filePath).size : 0;
      this.ready = true;
    } catch {
      this.size = 0;
    }
  }

  private failPending(): void {
    const data = this.pending.join('');
    this.pending = [];
    this.pendingBytes = 0;
    this.fallback(data);
  }

  private emitBatch(data: string, bytes: number, sync: boolean): void {
    try {
      if (!this.ready) throw new Error('error file directory is not ready');
      // Never roll while an async append is in flight: it would land that batch in the rolled file.
      if (!this.inFlight && this.size + bytes > this.maxBytes) this.roll();
      this.size += bytes;
      if (sync) {
        fs.appendFileSync(this.filePath, data, { mode: FILE_MODE });
        return;
      }
      this.inFlight = true;
      fs.appendFile(this.filePath, data, { mode: FILE_MODE }, err => {
        this.inFlight = false;
        if (err) {
          this.size -= bytes;
          this.fallback(data);
        }
        if (this.pendingBytes > 0) this.schedule();
      });
    } catch {
      this.inFlight = false;
      this.size = Math.max(0, this.size - bytes);
      this.fallback(data);
    }
  }

  /** A failed write goes to stderr, and the next batch retries the mkdir. */
  private fallback(data: string): void {
    this.ready = false;
    try {
      this.fallbackWrite(data);
    } catch {
      // silence
    }
  }

  /**
   * Rotate `<file>` → `<file>.1` → … → `<file>.N`. Synchronous and rare (once per maxBytes); a
   * rename chain within one filesystem is ~1–2 ms. Another process may have rolled already, so the
   * real on-disk size is read first.
   *
   * Node takes no lock, so a PHP writer (or another Node writer) may be rotating the same chain at
   * the same moment (§4.4, §5.5). Every step is therefore tolerant on its own: a generation that
   * vanished under us (ENOENT) or refuses to move is skipped, never fatal. That race may shift
   * history by one generation; it must NEVER delete it. So the only file this ever removes is `.N`,
   * and when the final `<file> → .1` rename fails the real size is re-read and appending carries on.
   */
  private roll(): void {
    let real: number;
    try {
      real = fs.statSync(this.filePath).size;
    } catch {
      // No active file: another writer rolled it a moment ago. The next append creates it.
      this.size = 0;
      return;
    }
    if (real < this.size) {
      // Someone else rolled (or truncated) the file. Adopt the real size; roll only if needed.
      this.size = real;
      return;
    }
    if (this.maxBackups === 0) {
      // maxBackups 0 is the configured "keep no history": the active file IS generation .N.
      tolerant(() => fs.rmSync(this.filePath, { force: true }));
      this.size = this.realSize();
      return;
    }
    tolerant(() => fs.rmSync(`${this.filePath}.${this.maxBackups}`, { force: true }));
    for (let i = this.maxBackups - 1; i >= 1; i--) {
      const from = `${this.filePath}.${i}`;
      tolerant(() => fs.renameSync(from, `${this.filePath}.${i + 1}`));
    }
    try {
      fs.renameSync(this.filePath, `${this.filePath}.1`);
      this.size = 0;
    } catch {
      // Could not move the active file (another roller got there first, or .1 is unmovable). Keep
      // appending to whatever is at filePath now; its real size decides the next roll.
      this.size = this.realSize();
    }
  }

  /** The active file's real size, 0 when it is absent. Never throws. */
  private realSize(): number {
    try {
      return fs.statSync(this.filePath).size;
    } catch {
      return 0;
    }
  }
}

/** Run one roll step; a failure (ENOENT from a concurrent roller, EISDIR, EPERM …) is ignored. */
function tolerant(step: () => void): void {
  try {
    step();
  } catch {
    // another writer moved it, or it cannot be moved: history shifts by one, nothing is deleted
  }
}
