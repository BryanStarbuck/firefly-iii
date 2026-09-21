// NODE ONLY — the node sink and the process-level net (pm/error_err.mdx §3.1, §5.5, §5.6).
//
// Installed once per runtime by a side-effect module that is the FIRST import of its index.ts
// (cli/code/src/error-file-install.ts, mcp/src/error-file-install.ts). This module calls no fs
// write API: all writing is RollingFileWriter's (the MCP no-fs-write canary relies on it).

import { homedir, tmpdir, userInfo } from 'node:os';
import { join } from 'node:path';

import { errorFileFor, flushErrorFile, readEnv, resetErrorFileForTests, setErrorSink } from './core.js';
import type { ErrorSink } from './core.js';
import { formatRecord, mergeContext } from './format.js';
import { RollingFileWriter } from './rolling-file-writer.js';

export { RollingFileWriter } from './rolling-file-writer.js';
export type { RollingFileWriterOptions } from './rolling-file-writer.js';

export type NodeErrorFileOptions = {
  app: 'ffx' | 'mcp';
  /** `where` for process-level records. */
  where: string;
  /** Default resolveErrorFilePath(process.env). Tests always pass one (R13). */
  file?: string;
  /** Default FIREFLY_ERROR_FILE_ECHO === '1'. */
  echo?: boolean;
  /** Default true (ffx keeps Node's crash). */
  crashOnUnhandledRejection?: boolean;
  /** Default true. */
  handleProcessErrors?: boolean;
};

export type NodeErrorFile = {
  /** Synchronous barrier: owed summaries and buffered lines are on disk when it returns. */
  flush: () => void;
  /** The file this process writes. */
  file: string;
};

function uid(): string {
  try {
    return String(userInfo().uid);
  } catch {
    return 'user';
  }
}

function homeDir(env: Record<string, string | undefined>): string {
  if (env.HOME) return env.HOME;
  try {
    return homedir();
  } catch {
    return '';
  }
}

/**
 * §3.1, Node: FIREFLY_ERROR_FILE (non-empty, `~/` expanded with HOME), else ~/T/firefly/error.err,
 * else <tmp>/firefly_<uid>/error.err. There is no NODE_ENV branch: the test harness sets the variable.
 */
export function resolveErrorFilePath(env: Record<string, string | undefined>): string {
  const override = env.FIREFLY_ERROR_FILE;
  if (override) {
    if (override === '~') return homeDir(env) || override;
    if (override.startsWith('~/')) {
      const home = homeDir(env);
      return home ? join(home, override.slice(2)) : override;
    }
    return override;
  }
  const home = homeDir(env);
  if (home) return join(home, 'T', 'firefly', 'error.err');
  return join(tmpdir(), `firefly_${uid()}`, 'error.err');
}

type Listener = { event: string; fn: (...args: never[]) => void };

type Installed = {
  file: string;
  writer: RollingFileWriter;
  listeners: Listener[];
};
let installed: Installed | null = null;

function on(listeners: Listener[], event: string, fn: (...args: never[]) => void): void {
  process.on(event, fn as (...args: unknown[]) => void);
  listeners.push({ event, fn });
}

function installProcessHandlers(where: string, crashOnUnhandledRejection: boolean, listeners: Listener[]): void {
  const errors = errorFileFor(where, { net: 'process' });

  // uncaughtExceptionMonitor observes WITHOUT changing what happens next: Node's own crash (or the
  // host's uncaughtException handler) still runs. The FATAL record is on disk before it does (R9).
  on(listeners, 'uncaughtExceptionMonitor', (err: unknown) => {
    errors.fatal('an uncaught exception', err);
  });

  const onRejection = (reason: unknown): void => {
    const onlyUs = process.listenerCount('unhandledRejection') === 1;
    if (crashOnUnhandledRejection && onlyUs) {
      // Our listener replaced Node's default crash; restore it. The FATAL record is written and
      // flushed first; the monitor above then sees the same object and writes nothing (R4).
      errors.fatal('an unhandled promise rejection', reason);
      throw reason;
    }
    errors.caught('an unhandled promise rejection', reason);
  };
  on(listeners, 'unhandledRejection', onRejection);

  on(listeners, 'beforeExit', () => flushErrorFile());
  on(listeners, 'exit', () => flushErrorFile());

  for (const signal of ['SIGINT', 'SIGTERM'] as const) {
    const onSignal = (): void => {
      flushErrorFile();
      // Adding a listener removes Node's default "exit on signal". Only if ours is the ONLY one is
      // the signal re-raised, so the process ends exactly as it would have without us. A host that
      // registered its own handler (the MCP's shutdown, `ffx logs --follow`) keeps control (§5.5).
      if (process.listenerCount(signal) === 1) {
        process.removeListener(signal, onSignal);
        process.kill(process.pid, signal);
      }
    };
    on(listeners, signal, onSignal);
  }
}

/**
 * Install the node sink for this process. Idempotent: a second call returns the first install.
 * Every path is total — a broken environment degrades to stderr, never to a throw.
 */
export function installNodeErrorFile(opts: NodeErrorFileOptions): NodeErrorFile {
  if (installed) {
    return { flush: flushErrorFile, file: installed.file };
  }
  const file = opts.file ?? resolveErrorFilePath(process.env);
  const writer = new RollingFileWriter({ filePath: file });
  const pid = process.pid;
  const sink: ErrorSink = {
    app: opts.app,
    echo: opts.echo ?? readEnv('FIREFLY_ERROR_FILE_ECHO') === '1',
    verbose: readEnv('FIREFLY_ERROR_FILE_VERBOSE') === '1',
    // The node sink stamps the `pid` context key (§5.2); it is last in the fixed order.
    write: (record) => writer.write(formatRecord({ ...record, data: mergeContext(record.data, { pid }) })),
    flush: () => writer.flush(),
  };
  const listeners: Listener[] = [];
  installed = { file, writer, listeners };
  setErrorSink(sink);
  if (opts.handleProcessErrors ?? true) {
    try {
      installProcessHandlers(opts.where, opts.crashOnUnhandledRejection ?? true, listeners);
    } catch {
      // no usable process object; the sink still works
    }
  }
  return { flush: flushErrorFile, file };
}

/**
 * Test seam: flush what is buffered, remove the process listeners this install added, forget the
 * install and reset the in-process state (sink, queue, folds, the reported set).
 */
export function resetNodeErrorFileForTests(): void {
  const current = installed;
  installed = null;
  if (current) {
    try {
      flushErrorFile();
    } catch {
      // total
    }
    for (const l of current.listeners) {
      try {
        process.removeListener(l.event, l.fn as (...args: unknown[]) => void);
      } catch {
        // total
      }
    }
  }
  setErrorSink(null);
  resetErrorFileForTests();
}
