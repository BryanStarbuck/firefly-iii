// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs
// Privacy — pm/error_err.mdx §12. The library enforces this; call sites are not trusted to (R12).
//
// The twin of app/Machine/ErrorFile/Redactor.php. Two kinds of defence:
//   • data KEYS: a key that names a secret has its value replaced with [redacted]; a key that
//     names ledger data has its value refused, even when it is null (§12.3);
//   • text VALUES — the error, every cause and every data value (never `where` or `doing`, which
//     are literals) — pass the §12.4 scrubs, in this order:
//       1. SQL_CUT: the ` (Connection: ` cut (a QueryException's SQL text);
//       2–7. SCRUBS: URL query values, ≥32 hex, IBAN, email, decimal amount, 9–17 digit runs;
//       8. the repo's absolute base path → nothing, then (the last SCRUBS entry) a home directory → `~`.
//
// PARITY: SECRET_KEY, LEDGER_KEY, SQL_CUT and SCRUBS are the same regexes, and the same
// replacements, as Redactor.php. errorfile/test/parity.test.ts extracts the PHP strings and
// compares them with these (JS's `g` flag aside: preg_replace is always global).

import { capMiddle, safeString, stripControlChars } from './describe.js';

/** Keys whose VALUES are secrets. */
export const SECRET_KEY = /pass(word)?|secret|token|auth|cookie|session|key|signature|credential|bearer|csrf|xsrf/i;

/** Keys whose values are ledger data — refused outright, the repo is public and the ledger is not. */
export const LEDGER_KEY =
  /amount|balance|payee|notes?|memo|account_?name|category_?name|description|imported_payee|statement|iban|bic|account_?number|source_?name|destination_?name|opening_balance|virtual_balance|foreign_amount|internal_reference|sepa_/i;

export const REDACTED = '[redacted]';
export const LEDGER_REFUSED = '[ledger-field refused]';

/**
 * §12.4 scrub 1: a QueryException message is cut at ` (Connection: `, which drops the SQL text. The
 * cut stops before a trailing ` (code=…)` suffix and before the next ` | cause: `, so it can run on
 * a whole headline or cause chain. Applied only when the text holds ` (Connection: `.
 */
export const SQL_CUT = / \(Connection: .*?(?=(?: \(code=[^()]*\))?(?: \| cause: |$))/gs;

/**
 * §12.4 scrubs 2–8, in order, each a global replace: URL query values (names kept), ≥32 hex, IBAN,
 * email, decimal amount, 9–17 digit runs, and — after the repo base path is removed — a home
 * directory. The home pattern names its directories inside a group, so the source never holds the
 * literal home prefix the CLI canary greps for.
 */
export const SCRUBS: ReadonlyArray<readonly [RegExp, string]> = [
  [/([?&](?:token|code|state|key|password|secret|signature|sig|api_key|access_token)=)[^&#\s"'<>]*/gi, '$1[redacted]'],
  [/[0-9a-fA-F]{32,}/g, '[REDACTED-KEY]'],
  [/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){2,7}(?: ?[A-Z0-9]{1,4})?\b/g, '[iban]'],
  [/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/g, '[email]'],
  [/(?<![\w.])-?\d{1,15}\.\d{2,12}(?![\w.])/g, '[amount]'],
  [/(?<!\d)\d{9,17}(?!\d)/g, '[number]'],
  [/\/(?:Users|home)\/[^\/\s:()'"]+/g, '~'],
];

/** The home-directory scrub is last; the repo base path is removed just before it. */
const HOME_SCRUB_INDEX = SCRUBS.length - 1;

const CONNECTION_CUT = ' (Connection: ';

let basePathMemo: string | null = null;

/**
 * The repo's absolute base path, from this module's own location — errorfile/src, cli/code/…/vendor
 * or mcp/…/vendor — so no path is ever written into the source. '' when it cannot be told.
 */
export function repoBasePath(): string {
  if (basePathMemo !== null) return basePathMemo;
  let base = '';
  try {
    const file = decodeURIComponent(new URL(import.meta.url).pathname);
    const m = /^(.*)\/(?:cli\/code|mcp|errorfile)\//.exec(file);
    base = m?.[1] ?? '';
  } catch {
    base = '';
  }
  basePathMemo = base;
  return base;
}

/** Test seam: pretend the repo lives at `base` (null restores the real one). */
export function useRepoBasePathForTests(base: string | null): void {
  basePathMemo = base;
}

/** §12.4: the value scrubs, in order — the twin of Redactor::text(). Total. */
export function scrubText(text: string): string {
  if (text === '') return '';
  try {
    let out = text.includes(CONNECTION_CUT) ? text.replace(SQL_CUT, '') : text;
    for (let i = 0; i < SCRUBS.length; i++) {
      if (i === HOME_SCRUB_INDEX) {
        // rule 8, part 2 first: the repo's absolute base path → nothing, before the home rule
        // would turn it into `~/…`.
        const base = repoBasePath();
        if (base.length > 1) out = out.split(`${base}/`).join('');
      }
      const scrub = SCRUBS[i];
      if (scrub) out = out.replace(scrub[0], scrub[1]);
    }
    return out;
  } catch {
    return '[unscrubbable text]';
  }
}

/** Per-value cap inside the data block; the whole block is capped again when formatted. */
export const VALUE_CAP = 300;
/** A data key is cut to this many characters. */
export const KEY_CAP = 60;
/** At most this many keys in a data block. */
export const MAX_KEYS = 40;

export type ErrorDataValue = string | number | boolean | null | undefined;
export type ErrorData = Record<string, ErrorDataValue>;

/**
 * Apply §12.3 and §12.4 to one key/value — the twin of Redactor::value(). Returns null when the
 * pair should be omitted (an undefined value, which PHP does not have).
 */
export function redactValue(key: string, value: unknown): string | null {
  if (LEDGER_KEY.test(key)) return LEDGER_REFUSED;
  if (value === undefined) return null;
  if (SECRET_KEY.test(key)) return REDACTED;
  if (value === null) return 'null';
  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number' || typeof value === 'string') {
    return capMiddle(stripControlChars(scrubText(String(value))), VALUE_CAP);
  }
  // Objects are not allowed in `data` (they are where ledger rows hide). Say so, never print it.
  return `[${Array.isArray(value) ? 'array' : typeof value} not allowed]`;
}

/** A data key, cleaned: control characters stripped, `[\s={}]` → `_`, cut to 60 characters. */
export function cleanKey(key: string): string {
  const clean = stripControlChars(key).replace(/[\s={}]/g, '_');
  return clean.length <= KEY_CAP ? clean : Array.from(clean).slice(0, KEY_CAP).join('');
}

/**
 * Redact a whole data block — the twin of Redactor::data(). Accepts `unknown` because a call site's
 * types are not a guarantee at runtime; nothing in it is trusted.
 */
export function redactData(data: unknown): Record<string, string> | null {
  if (data === null || data === undefined || typeof data !== 'object' || Array.isArray(data)) {
    return null;
  }
  try {
    const out: Record<string, string> = {};
    let count = 0;
    for (const key of Object.keys(data)) {
      if (count >= MAX_KEYS) break;
      let raw: unknown;
      try {
        raw = Reflect.get(data, key);
      } catch {
        raw = '[unreadable]';
      }
      const k = cleanKey(key);
      if (k === '') continue;
      const value = redactValue(k, raw);
      if (value === null) continue;
      out[k] = value;
      count++;
    }
    return count ? out : null;
  } catch {
    return { data: safeString('[unreadable data]') };
  }
}
