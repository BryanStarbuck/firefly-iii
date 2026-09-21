// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs
// The report path — pm/error_err.mdx §5.3, §7.2. Ported from Actual Budget's packages/error-file.
//
// Nothing in this file runs until an error happens (R3). `errorFileFor` builds one small object per
// module; every method runs only inside a catch. The report path is:
//
//   already reported? → mark (WeakSet) → fold? → describe + redact → sink (or pre-install queue)
//
// STATE IS PROCESS-GLOBAL, NOT MODULE-GLOBAL. The sink, the pre-install queue, the WeakSet and the
// fold table live on `globalThis` under a registry symbol, so two loaded copies of this module in
// one process still see one sink and one "already reported" set (R4).
//
// This library never calls console.* (the MCP stdout-purity canary bans it) and never reports its
// own failure: the last resort is ONE process.stderr line per process, then silence (R11).

import { describeCauses, errorHeadline, prop, safeString, stackOf } from './describe.js';
import { createBurstFolder, normalizeMessage } from './fold.js';
import type { BurstFolder, FoldSummary } from './fold.js';
import { formatRecord, mergeContext, summaryText } from './format.js';
import type { ErrorContext, ErrorLevel, ErrorRecord } from './format.js';
import { redactData, scrubText } from './redact.js';
import type { ErrorData } from './redact.js';

export type { ErrorData, ErrorDataValue } from './redact.js';
export type { ErrorContext, ErrorLevel, ErrorRecord } from './format.js';

/** Where records go once a host has booted (§5.5). */
export type ErrorSink = {
  /** The runtime tag stamped on records that do not carry one (§3.3): 'ffx', 'mcp'. */
  app: string;
  /** Echo each written record to stderr (FIREFLY_ERROR_FILE_ECHO=1). */
  echo: boolean;
  /** Write EXPECTED records too (FIREFLY_ERROR_FILE_VERBOSE=1) — R6. */
  verbose: boolean;
  write(record: ErrorRecord): void;
  /** Synchronous barrier. */
  flush(): void;
};

export type ErrorFile = {
  /** The repo-relative source path this object reports as (R14). */
  readonly where: string;
  /** ERROR → error.err. */
  caught(doing: string, err: unknown, data?: ErrorData): void;
  /** WARN → error.err. The error is optional: a standing condition can be a WARN on its own. */
  warn(doing: string, err?: unknown, data?: ErrorData): void;
  /** Nothing (EXPECTED under FIREFLY_ERROR_FILE_VERBOSE=1). The call records a DECISION — R6. */
  expected(doing: string, err: unknown): void;
  /** ERROR, then throw the SAME object (R5). */
  rethrow(doing: string, err: unknown, data?: ErrorData): never;
  /** FATAL, then a synchronous flush — R9. Bypasses every fold. */
  fatal(doing: string, err: unknown, data?: ErrorData): void;
};

/**
 * Options for a handle. Only a NET sets `net` (§3.2 "The `net=` key"): the process net is
 * `process`, a top-level classifier is `top`. An explicit call site passes nothing.
 */
export type ErrorFileOptions = {
  net?: 'process' | 'top';
};

/** Records held before any sink is installed (a module can fault at import time). */
export const PRE_INSTALL_CAP = 200;
/** R10: identical faults fold inside this window. */
export const FOLD_WINDOW_MS = 60_000;
/** §5.2: transient network faults fold per 10 minutes, as WARN. */
export const TRANSIENT_WINDOW_MS = 10 * 60_000;
/** The in-process fold table is capped. */
export const FOLD_MAX_KEYS = 1000;

type FoldContext = {
  level: ErrorLevel;
  app: string;
  where: string;
  doing: string;
  headline: string;
};

type State = {
  sink: ErrorSink | null;
  queue: ErrorRecord[];
  overflow: number;
  reported: WeakSet<object>;
  folder: BurstFolder;
  busy: boolean;
  fallbackUsed: boolean;
};

const REGISTRY = Symbol.for('firefly-iii/error-file/state@1');

function isFoldContext(value: unknown): value is FoldContext {
  return typeof value === 'object' && value !== null && 'headline' in value;
}

function createState(): State {
  const state: State = {
    sink: null,
    queue: [],
    overflow: 0,
    reported: new WeakSet<object>(),
    folder: createBurstFolder({
      maxKeys: FOLD_MAX_KEYS,
      emit: (summary: FoldSummary, context: unknown) => {
        if (!isFoldContext(context)) return;
        deliver(state, {
          ts: new Date().toISOString(),
          level: context.level,
          app: context.app,
          where: context.where,
          doing: context.doing,
          error: summaryText(summary.count, summary.windowMs, summary.firstAt, context.headline),
          cause: '',
          stack: [],
          data: null,
        });
      },
    }),
    busy: false,
    fallbackUsed: false,
  };
  return state;
}

function state(): State {
  const holder: Record<symbol, unknown> = globalThis;
  const existing = holder[REGISTRY];
  if (typeof existing === 'object' && existing !== null && 'folder' in existing) {
    // Written only by createState() below, under this private registry symbol.
    return existing as State;
  }
  const created = createState();
  holder[REGISTRY] = created;
  return created;
}

/** Read an environment variable, total. */
export function readEnv(name: string): string | undefined {
  try {
    const g: { process?: { env?: Record<string, string | undefined> } } = globalThis;
    return g.process?.env?.[name];
  } catch {
    return undefined;
  }
}

/** One write to stderr, total. The library's only output channel besides the file (R11). */
export function writeStderr(text: string): void {
  try {
    const g: { process?: { stderr?: { write?: (s: string) => unknown } } } = globalThis;
    g.process?.stderr?.write?.(text);
  } catch {
    // silence is the last fallback
  }
}

function lastResort(message: string, err: unknown): void {
  const s = state();
  if (s.fallbackUsed) return;
  s.fallbackUsed = true;
  writeStderr(`[error-file] ${message}: ${safeString(err)}\n`);
}

function echo(record: ErrorRecord): void {
  try {
    writeStderr(formatRecord(record));
  } catch {
    // echo is a convenience; never let it matter
  }
}

function deliver(s: State, record: ErrorRecord): void {
  const sink = s.sink;
  if (!sink) {
    if (s.queue.length < PRE_INSTALL_CAP) s.queue.push(record);
    else s.overflow += 1;
    return;
  }
  if (!record.app) record.app = sink.app;
  try {
    sink.write(record);
  } catch (e) {
    lastResort('the error sink threw', e);
  }
  if (sink.echo) echo(record);
}

function nameOf(err: unknown): string {
  const name = prop(err, 'name');
  return typeof name === 'string' ? name : typeof err;
}

function messageOf(err: unknown): string {
  const message = prop(err, 'message');
  return typeof message === 'string' ? message : safeString(err);
}

/** §5.2: a network error that is not a fault. The whole cause walk is looked at. */
export const TRANSIENT_CODES: ReadonlySet<string> = new Set([
  'ECONNREFUSED',
  'ENOTFOUND',
  'EAI_AGAIN',
  'EHOSTUNREACH',
  'ECONNRESET',
  'ETIMEDOUT',
]);

/** Does the error, or any link of its cause chain (5 deep), carry a transient network code? */
export function isTransientNetworkError(err: unknown): boolean {
  let current: unknown = err;
  for (let depth = 0; depth < 6 && current !== null && current !== undefined; depth++) {
    const code = prop(current, 'code');
    if (typeof code === 'string' && TRANSIENT_CODES.has(code)) return true;
    current = prop(current, 'cause');
  }
  return false;
}

function isMarkable(err: unknown): err is object {
  return (typeof err === 'object' && err !== null) || typeof err === 'function';
}

/** Has this exact error object already been written (R4)? */
export function isReported(err: unknown): boolean {
  try {
    return isMarkable(err) && state().reported.has(err);
  } catch {
    return false;
  }
}

type ReportArgs = {
  level: ErrorLevel;
  where: string;
  doing: string;
  err: unknown;
  hasErr: boolean;
  data: unknown;
  context: ErrorContext;
};

function report(args: ReportArgs): void {
  const s = state();
  // Re-entrancy: anything thrown or reported WHILE reporting is dropped, so the library can never
  // loop (R11) — a throwing getter on the error, a sink that reports, an echo that faults.
  if (s.busy) return;
  s.busy = true;
  try {
    const { where, doing, err, hasErr } = args;
    if (hasErr && isMarkable(err)) {
      if (s.reported.has(err)) return;
      s.reported.add(err);
    }
    let level = args.level;
    let windowMs = FOLD_WINDOW_MS;
    // §5.2 / R7: a transient network fault (a plane-call timeout) is one folded WARN per 10 minutes.
    if ((level === 'ERROR' || level === 'WARN') && hasErr && isTransientNetworkError(err)) {
      level = 'WARN';
      windowMs = TRANSIENT_WINDOW_MS;
    }
    const name = hasErr ? nameOf(err) : '';
    const app = s.sink?.app ?? '';
    const headline = hasErr ? scrubText(errorHeadline(err)) : '';
    if (level !== 'FATAL') {
      const key = [app, where, doing, name, normalizeMessage(hasErr ? messageOf(err) : '')].join('\u0001');
      const context: FoldContext = { level, app, where, doing, headline };
      if (!s.folder.admit(key, windowMs, context)) return;
    }
    deliver(s, {
      ts: new Date().toISOString(),
      level,
      app,
      where,
      doing,
      error: headline,
      cause: hasErr ? scrubText(describeCauses(err)) : '',
      stack: hasErr ? stackOf(err) : [],
      data: mergeContext(redactData(args.data), args.context),
    });
  } catch (e) {
    lastResort('could not report an error', e);
  } finally {
    s.busy = false;
  }
}

/** Write an already-built record. It is folded like any other record, but not de-duplicated. */
export function writeRecord(record: ErrorRecord): void {
  const s = state();
  if (s.busy) return;
  s.busy = true;
  try {
    if (record.level !== 'FATAL') {
      const key = [record.app, record.where, record.doing, normalizeMessage(record.error)].join('\u0001');
      const context: FoldContext = {
        level: record.level,
        app: record.app,
        where: record.where,
        doing: record.doing,
        headline: record.error,
      };
      if (!s.folder.admit(key, FOLD_WINDOW_MS, context)) return;
    }
    deliver(s, record);
  } catch (e) {
    lastResort('could not write a record', e);
  } finally {
    s.busy = false;
  }
}

/** Install (or with null, remove) the sink, and drain the pre-install queue into it. */
export function setErrorSink(sink: ErrorSink | null): void {
  try {
    const s = state();
    s.sink = sink;
    if (!sink) return;
    const queued = s.queue;
    const overflow = s.overflow;
    s.queue = [];
    s.overflow = 0;
    for (const record of queued) deliver(s, record);
    if (overflow > 0) {
      deliver(s, {
        ts: new Date().toISOString(),
        level: 'WARN',
        app: sink.app,
        where: 'errorfile/src/core.ts',
        doing: 'installing the error file',
        error: `${overflow} records were dropped before the error file was installed`,
        cause: '',
        stack: [],
        data: null,
      });
    }
  } catch (e) {
    lastResort('could not install the error sink', e);
  }
}

/** Is a sink installed in this process (by any copy of this module)? */
export function hasErrorSink(): boolean {
  try {
    return state().sink !== null;
  } catch {
    return false;
  }
}

/** Write every owed fold summary, then flush the sink synchronously. */
export function flushErrorFile(): void {
  try {
    const s = state();
    s.folder.flushAll();
    s.sink?.flush();
  } catch (e) {
    lastResort('could not flush the error file', e);
  }
}

/** Test seam: forget the sink, the queue, the fold table and the reported set. */
export function resetErrorFileForTests(): void {
  const s = state();
  s.folder.reset();
  s.sink = null;
  s.queue = [];
  s.overflow = 0;
  s.reported = new WeakSet<object>();
  s.busy = false;
  s.fallbackUsed = false;
}

function isVerbose(): boolean {
  return state().sink?.verbose === true || readEnv('FIREFLY_ERROR_FILE_VERBOSE') === '1';
}

/**
 * One object per module, created at module scope: `const errors = errorFileFor('<repo-relative path>')`.
 * Stores a string and does no other work (R3).
 */
export function errorFileFor(where: string, opts: ErrorFileOptions = {}): ErrorFile {
  const context: ErrorContext = opts.net ? { net: opts.net } : {};
  return {
    where,
    caught(doing, err, data) {
      report({ level: 'ERROR', where, doing, err, hasErr: true, data, context });
    },
    warn(doing, err, data) {
      report({ level: 'WARN', where, doing, err, hasErr: err !== undefined, data, context });
    },
    expected(doing, err) {
      if (!isVerbose()) return;
      report({ level: 'EXPECTED', where, doing, err, hasErr: true, data: null, context });
    },
    rethrow(doing, err, data) {
      report({ level: 'ERROR', where, doing, err, hasErr: true, data, context });
      throw err;
    },
    fatal(doing, err, data) {
      report({ level: 'FATAL', where, doing, err, hasErr: true, data, context });
      flushErrorFile();
    },
  };
}

// ── The wrappers (§7.2) ──────────────────────────────────────────────────────────────────────────

/** Run `fn`; on a throw, report it (ERROR) and return `fallback`. */
export function tryOr<T>(errors: ErrorFile, doing: string, fn: () => T, fallback: T): T {
  try {
    return fn();
  } catch (e) {
    errors.caught(doing, e);
    return fallback;
  }
}

/** Await `fn()`; on a rejection, report it (ERROR) and resolve to `fallback`. */
export async function tryOrAsync<T>(errors: ErrorFile, doing: string, fn: () => Promise<T>, fallback: T): Promise<T> {
  try {
    return await fn();
  } catch (e) {
    errors.caught(doing, e);
    return fallback;
  }
}

function isThenable(value: unknown): value is PromiseLike<unknown> {
  return (typeof value === 'object' || typeof value === 'function') && value !== null && typeof prop(value, 'then') === 'function';
}

/** A fire-and-forget promise: report its rejection instead of losing it. */
export function reportRejection(errors: ErrorFile, doing: string, p: unknown): void {
  if (!isThenable(p)) return;
  try {
    p.then(undefined, (e: unknown) => errors.caught(doing, e));
  } catch (e) {
    errors.caught(doing, e);
  }
}

/**
 * Wrap a callback nobody awaits (timer, listener, stream handler). Reports a synchronous throw AND
 * a rejected promise the callback returns. Allocates once, at wrap time — never call it in a loop.
 */
export function guard<A extends unknown[]>(errors: ErrorFile, doing: string, fn: (...a: A) => unknown): (...a: A) => void {
  return (...a: A) => {
    try {
      const result = fn(...a);
      if (isThenable(result)) reportRejection(errors, doing, result);
    } catch (e) {
      errors.caught(doing, e);
    }
  };
}
