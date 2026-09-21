// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs
// Record → line(s) — pm/error_err.mdx §3.2 (LOCKED). The PHP twin is app/Machine/ErrorFile/LineFormat.php;
// errorfile/fixtures/golden-lines.txt pins both byte for byte (R16).
//
//   [ts] [LEVEL] [app] [where] doing — Name: message (code=…) {k=v k2="v w"} | cause: …
//       at frame
//       at frame

import { capMiddle, codePointLength, RECORD_CAP, stripControlChars, utf8Length } from './describe.js';
import { cleanKey, MAX_KEYS } from './redact.js';

export type ErrorLevel = 'WARN' | 'ERROR' | 'FATAL' | 'EXPECTED';

export const LEVELS: readonly ErrorLevel[] = ['WARN', 'ERROR', 'FATAL', 'EXPECTED'];

/** One fault, already described and redacted. Plain data. */
export type ErrorRecord = {
  ts: string;
  level: ErrorLevel;
  /** The runtime (§3.3). Empty until a sink stamps it. */
  app: string;
  /** Repo-relative source path (R14). */
  where: string;
  /** Gerund phrase: what was being done. */
  doing: string;
  /** `Name: message (code=…)` — '' for a WARN with no error. */
  error: string;
  /** ` | cause: …` chain, '' when there is none. */
  cause: string;
  /** Trimmed stack frames WITHOUT the four-space indent (`at fn (path:line)`), as PHP's Record holds them. */
  stack: string[];
  /** Redacted data (call-site keys, then context keys), or null. */
  data: Record<string, string> | null;
};

/** The data block is capped here (characters). */
export const DATA_CAP = 1000;

/**
 * Context keys, in their fixed order (§3.2). They follow the call-site keys and are added only
 * where the key is absent.
 */
export const CONTEXT_ORDER = ['net', 'route', 'status', 'code', 'caller', 'rid', 'took_ms', 'during', 'via', 'pid'] as const;
export type ContextKey = (typeof CONTEXT_ORDER)[number];
export type ErrorContext = Partial<Record<ContextKey, string | number | null | undefined>>;

/** Append the context keys that are absent, in CONTEXT_ORDER, to a (redacted) data block. */
export function mergeContext(data: Record<string, string> | null, context: ErrorContext): Record<string, string> | null {
  try {
    const out: Record<string, string> = data ? { ...data } : {};
    let count = Object.keys(out).length;
    for (const key of CONTEXT_ORDER) {
      if (count >= MAX_KEYS) break;
      const value = context[key];
      if (value === undefined || value === null || value === '') continue;
      if (Object.prototype.hasOwnProperty.call(out, key)) continue;
      out[key] = String(value);
      count++;
    }
    return count ? out : null;
  } catch {
    return data;
  }
}

/** A value containing a space, `=`, `{`, `}` or `"` is double-quoted, with `"` written as `\"`. */
export function quoteValue(value: string): string {
  return /[ ={}"]/.test(value) ? `"${value.replace(/"/g, '\\"')}"` : value;
}

/**
 * `{k=v k2="v with space"}` with its leading space, or '' for no data — the twin of
 * LineFormat::dataBlock(). Keys in the given order, at most 40, cleaned; the inside is capped at
 * 1,000 characters.
 */
export function formatData(data: Record<string, string> | null): string {
  if (!data) return '';
  const pairs: string[] = [];
  for (const key of Object.keys(data)) {
    if (pairs.length >= MAX_KEYS) break;
    const k = cleanKey(key);
    if (k === '') continue;
    pairs.push(`${k}=${quoteValue(stripControlChars(data[key] ?? ''))}`);
  }
  if (!pairs.length) return '';
  return ` {${capMiddle(pairs.join(' '), DATA_CAP)}}`;
}

function headerWith(record: ErrorRecord, error: string, cause: string): string {
  let out = `[${stripControlChars(record.ts)}] [${record.level}] [${stripControlChars(record.app || '?')}]`;
  if (record.where !== '') out += ` [${stripControlChars(record.where)}]`;
  if (record.doing !== '') out += ` ${stripControlChars(record.doing)}`;
  if (error !== '') out += ` — ${stripControlChars(error)}`;
  return out + formatData(record.data) + stripControlChars(cause);
}

/** The single header line (no trailing newline). A field with no content is omitted with its separator. */
export function formatHeader(record: ErrorRecord): string {
  return headerWith(record, record.error, record.cause);
}

const MARKER_LENGTH = 3; // ' … '

/** Middle-cut `text` so it loses at least `overBytes` bytes ('' when too little would be left). */
function shrink(text: string, overBytes: number): string {
  const target = codePointLength(text) - overBytes - MARKER_LENGTH;
  return target <= MARKER_LENGTH ? '' : capMiddle(text, target);
}

/** Cut a string to at most `max` UTF-8 bytes, never inside a character (PHP's mb_strcut). */
function cutToBytes(value: string, max: number): string {
  let bytes = 0;
  let out = '';
  for (const ch of value) {
    const n = utf8Length(ch);
    if (bytes + n > max) break;
    bytes += n;
    out += ch;
  }
  return out;
}

/**
 * The whole record: header plus indented stack lines, newline-terminated, at most RECORD_CAP UTF-8
 * bytes including that newline — the twin of LineFormat::record(). The stack is cut only at a frame
 * boundary; a header that alone would pass the cap has its error, then its cause, middle-cut until
 * it fits, and is byte-cut as the last resort (§3.2 "The caps").
 */
export function formatRecord(record: ErrorRecord): string {
  const headerCap = RECORD_CAP - 1;
  let error = record.error;
  let cause = record.cause;
  let header = headerWith(record, error, cause);
  for (let i = 0; i < 8 && utf8Length(header) > headerCap && error !== ''; i++) {
    error = shrink(error, utf8Length(header) - headerCap);
    header = headerWith(record, error, cause);
  }
  for (let i = 0; i < 8 && utf8Length(header) > headerCap && cause !== ''; i++) {
    cause = shrink(cause, utf8Length(header) - headerCap);
    header = headerWith(record, error, cause);
  }
  if (utf8Length(header) > headerCap) header = cutToBytes(header, headerCap);
  let out = `${header}\n`;
  let bytes = utf8Length(out);
  for (const frame of record.stack) {
    const line = `    ${stripControlChars(frame)}\n`;
    const n = utf8Length(line);
    if (bytes + n > RECORD_CAP) break;
    out += line;
    bytes += n;
  }
  return out;
}

function pad2(n: number): string {
  return n < 10 ? `0${n}` : String(n);
}

/** HH:MM:SS (UTC, like the timestamps) for a folded summary's window start. */
export function clockTime(ms: number): string {
  const d = new Date(ms);
  return `${pad2(d.getUTCHours())}:${pad2(d.getUTCMinutes())}:${pad2(d.getUTCSeconds())}`;
}

/** The window as Actual Budget renders it: `60s`, `10m`. */
export function windowText(windowMs: number): string {
  return windowMs > 60_000 && windowMs % 60_000 === 0 ? `${windowMs / 60_000}m` : `${Math.round(windowMs / 1000)}s`;
}

/** The `error` text of a folded summary line (§3.4): `×N more in the 60s window from HH:MM:SS: <headline>`. */
export function summaryText(count: number, windowMs: number, firstAt: number, headline: string): string {
  const what = headline ? `: ${headline}` : '';
  return `×${count} more in the ${windowText(windowMs)} window from ${clockTime(firstAt)}${what}`;
}
