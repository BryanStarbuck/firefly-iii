// Turning a thrown value into text — pm/error_err.mdx §3.2.
//
// Everything here runs ONLY on the error path, and every function is total: a hostile or broken
// throwable (a throwing getter, a throwing toString, a circular cause chain) produces text, never
// an exception (R11). Ported from Actual Budget's packages/error-file/src/describe.ts (§5.2).

/** The message is capped here, cut in the middle so the start and the end both survive. */
export const MESSAGE_CAP = 2000;
/** The whole record (header + stack + the final newline) is capped here, in UTF-8 bytes. */
export const RECORD_CAP = 8000;
/** How far `err.cause` is walked. */
export const CAUSE_DEPTH = 5;
/** Stack frames kept after trimming. */
export const STACK_FRAMES = 12;

// \x00-\x1f, \x7f, U+2028 and U+2029: anything that could end a line or forge a second header.
const CONTROL_CHARS = /[\u0000-\u001f\u007f\u2028\u2029]/g;

/** Replace every control character (and the two Unicode line separators) with a space. */
export function stripControlChars(value: string): string {
  return value.replace(CONTROL_CHARS, ' ');
}

/** The number of code points in a string (PHP's mb_strlen), not UTF-16 units. */
export function codePointLength(value: string): number {
  let n = 0;
  for (let i = 0; i < value.length; i++) {
    const c = value.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff && i + 1 < value.length) {
      const d = value.charCodeAt(i + 1);
      if (d >= 0xdc00 && d <= 0xdfff) i++;
    }
    n++;
  }
  return n;
}

/** The UTF-8 byte length of a string (PHP's strlen), without Buffer. */
export function utf8Length(value: string): number {
  let n = 0;
  for (let i = 0; i < value.length; i++) {
    const c = value.charCodeAt(i);
    if (c < 0x80) n += 1;
    else if (c < 0x800) n += 2;
    else if (c >= 0xd800 && c <= 0xdbff && i + 1 < value.length) {
      const d = value.charCodeAt(i + 1);
      if (d >= 0xdc00 && d <= 0xdfff) {
        n += 4;
        i++;
      } else {
        n += 3;
      }
    } else n += 3;
  }
  return n;
}

/**
 * Cap a string at `max` code points by cutting out its middle (` … ` marks the cut). Counted in
 * code points, like PHP's mb_substr, so both languages cut the same text at the same place and a
 * surrogate pair is never split.
 */
export function capMiddle(value: string, max: number): string {
  if (value.length <= max) return value;
  const points = Array.from(value);
  if (points.length <= max) return value;
  const marker = ' … ';
  const keep = Math.max(0, max - 3);
  const head = Math.ceil(keep / 2);
  const tail = keep - head;
  return points.slice(0, head).join('') + marker + (tail > 0 ? points.slice(points.length - tail).join('') : '');
}

/** Read a property without letting a throwing getter escape. */
export function prop(value: unknown, key: string): unknown {
  if (value === null || (typeof value !== 'object' && typeof value !== 'function')) {
    return undefined;
  }
  try {
    return Reflect.get(value, key);
  } catch {
    return undefined;
  }
}

/** String(value), but total. */
export function safeString(value: unknown): string {
  try {
    if (typeof value === 'string') return value;
    if (value === undefined) return 'undefined';
    if (value === null) return 'null';
    if (typeof value === 'object') {
      try {
        const json = JSON.stringify(value);
        if (typeof json === 'string' && json !== '{}') return json;
      } catch {
        // circular or throwing toJSON — fall through to String()
      }
    }
    return String(value);
  } catch {
    return '[unprintable value]';
  }
}

function isErrorLike(value: unknown): boolean {
  return value instanceof Error || (typeof value === 'object' && value !== null && typeof prop(value, 'message') === 'string');
}

/** The `(code=… errno=… syscall=… type=…)` suffix. */
function codeSuffix(err: unknown): string {
  const parts: string[] = [];
  for (const key of ['code', 'errno', 'syscall', 'type']) {
    const v = prop(err, key);
    if (typeof v === 'string' || typeof v === 'number') {
      // `type` duplicates the name for some error classes; skip it when it adds nothing.
      if (key === 'type' && v === prop(err, 'name')) continue;
      parts.push(`${key}=${String(v)}`);
    }
  }
  return parts.length ? ` (${parts.join(' ')})` : '';
}

/** `Name: message (code=…)` for one throwable, without its cause chain. */
export function errorHeadline(err: unknown): string {
  try {
    if (isErrorLike(err)) {
      const rawName = prop(err, 'name');
      const name = typeof rawName === 'string' && rawName ? rawName : 'Error';
      const rawMessage = prop(err, 'message');
      const message = typeof rawMessage === 'string' ? rawMessage : '';
      const text = message ? `${name}: ${message}` : name;
      return capMiddle(stripControlChars(text), MESSAGE_CAP) + codeSuffix(err);
    }
    return capMiddle(
      stripControlChars(`${typeof err === 'object' ? 'Thrown' : 'Thrown ' + typeof err}: ${safeString(err)}`),
      MESSAGE_CAP,
    );
  } catch {
    return 'Thrown: [unprintable value]';
  }
}

/** ` | cause: …` for each link of the cause chain, up to CAUSE_DEPTH deep, cycle-safe. */
export function describeCauses(err: unknown): string {
  let out = '';
  const seen = new Set<unknown>([err]);
  let current = prop(err, 'cause');
  for (let depth = 0; depth < CAUSE_DEPTH && current !== undefined && current !== null; depth++) {
    if (seen.has(current)) break;
    seen.add(current);
    out += ` | cause: ${errorHeadline(current)}`;
    current = prop(current, 'cause');
  }
  return out;
}

/**
 * The one-line description of a throwable: headline plus cause chain, no stack. For code that has
 * to SHOW a message (a CLI line) — it must never be used to build a report by hand (R2).
 */
export function describeError(err: unknown): string {
  return errorHeadline(err) + describeCauses(err);
}

// Absolute prefixes shortened to repo-relative: anything up to the first cli/ (cli/code/…, and the
// self-building shim cli/ffx.mjs, which is on every ffx stack as `at async file:///…/cli/ffx.mjs`), mcp/src|dist/
// or errorfile/ segment. Also strips a loopback browser origin. No URL literal is written out here
// (the no-network canaries grep the built output for one).
const REPO_PREFIX = /(?:file:\/\/)?(?:\/[^\s():]*?)?\/(cli\/(?:code\/|ffx\.mjs)|mcp\/(?:src|dist)\/|errorfile\/)/g;
const ORIGIN_PREFIX = /\bhttps?:\/\/(?:localhost|127\.0\.0\.1|\[::1\])(?::\d+)?\//g;
const FRAME = /^\s*at\s|^[^\s@]*@\S+:\d+/;

function isOurOwnFrame(line: string): boolean {
  return (
    line.includes('node_modules') ||
    line.includes('(node:internal/') ||
    line.includes(' node:internal/') ||
    line.includes('errorfile/src/') ||
    line.includes('errorfile/.build/src/') ||
    line.includes('vendor/error-file/')
  );
}

/** Keep only frame lines, drop node_modules, node internals and our own frames, cap, shorten paths. */
export function trimStack(stack: string, maxFrames: number = STACK_FRAMES): string[] {
  try {
    const frames: string[] = [];
    for (const raw of stack.split('\n')) {
      if (frames.length >= maxFrames) break;
      if (!FRAME.test(raw) || isOurOwnFrame(raw)) continue;
      const line = stripControlChars(raw.trim()).replace(REPO_PREFIX, '$1').replace(ORIGIN_PREFIX, '');
      frames.push(line);
    }
    return frames;
  } catch {
    return [];
  }
}

/** The trimmed stack frames of a throwable (no indent), or [] when it has none. */
export function stackOf(err: unknown): string[] {
  const stack = prop(err, 'stack');
  return typeof stack === 'string' ? trimStack(stack) : [];
}
