// PHP / TypeScript parity — pm/error_err.mdx §12 "Parity", R16, AC 13. The regex strings of
// app/Machine/ErrorFile/Redactor.php (and Normalizer.php, and LineFormat's caps) are extracted from
// the PHP source and compared with the TypeScript ones. JS's `g` flag is ignored: preg_replace is
// always global.
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

import { MESSAGE_CAP, RECORD_CAP } from '../src/describe.js';
import { normalizerPatternsForParity } from '../src/fold.js';
import { DATA_CAP } from '../src/format.js';
import { KEY_CAP, LEDGER_KEY, LEDGER_REFUSED, MAX_KEYS, REDACTED, SCRUBS, SECRET_KEY, SQL_CUT, VALUE_CAP } from '../src/redact.js';

const ROOT = new URL('../../../', import.meta.url);
const REDACTOR = new URL('app/Machine/ErrorFile/Redactor.php', ROOT);
const NORMALIZER = new URL('app/Machine/ErrorFile/Normalizer.php', ROOT);
const LINE_FORMAT = new URL('app/Machine/ErrorFile/LineFormat.php', ROOT);

/** A PHP single-quoted string body → its value (only \\ and \' are escapes). */
function unquote(body: string): string {
  return body.replace(/\\([\\'])/g, '$1');
}

const SINGLE_QUOTED = String.raw`'((?:[^'\\]|\\.)*)'`;

/** The value of `const string NAME = '…';`. */
function phpConst(source: string, name: string): string {
  const m = new RegExp(String.raw`const\s+(?:string\s+)?${name}\s*=\s*${SINGLE_QUOTED}\s*;`).exec(source);
  assert.ok(m, `${name} is not a single-quoted string constant`);
  return unquote(m[1] ?? '');
}

/** The value of `const int NAME = 123;`. */
function phpInt(source: string, name: string): number {
  const m = new RegExp(String.raw`const\s+(?:int\s+)?${name}\s*=\s*([0-9_]+)\s*;`).exec(source);
  assert.ok(m, `${name} is not an int constant`);
  return Number.parseInt((m[1] ?? '').replace(/_/g, ''), 10);
}

/** Every `['pattern', 'replacement']` pair of `const array SCRUBS = [ … ];`, in order. */
function phpScrubs(source: string): Array<[string, string]> {
  const start = source.search(/const\s+(?:array\s+)?SCRUBS\s*=\s*\[/);
  assert.ok(start >= 0, 'SCRUBS is not a constant array');
  const end = source.indexOf('];', start);
  const block = source.slice(start, end);
  const pairs: Array<[string, string]> = [];
  for (const m of block.matchAll(new RegExp(String.raw`\[\s*${SINGLE_QUOTED}\s*,\s*${SINGLE_QUOTED}\s*\]`, 'g'))) {
    pairs.push([unquote(m[1] ?? ''), unquote(m[2] ?? '')]);
  }
  return pairs;
}

/** A JS regex as PHP writes it: `/source/flags`, without `g`. */
function asPhp(re: RegExp): string {
  return `/${re.source}/${re.flags.replace('g', '')}`;
}

describe('Redactor.php == redact.ts', { skip: existsSync(REDACTOR) ? false : 'app/Machine/ErrorFile/Redactor.php is not there yet' }, () => {
  const php = existsSync(REDACTOR) ? readFileSync(REDACTOR, 'utf8') : '';

  it('SECRET_KEY and LEDGER_KEY', () => {
    assert.equal(asPhp(SECRET_KEY), phpConst(php, 'SECRET_KEY'));
    assert.equal(asPhp(LEDGER_KEY), phpConst(php, 'LEDGER_KEY'));
  });

  it('SQL_CUT', () => {
    assert.equal(asPhp(SQL_CUT), phpConst(php, 'SQL_CUT'));
  });

  it('SCRUBS: the same patterns and replacements, in the same order', () => {
    const phpPairs = phpScrubs(php);
    assert.equal(phpPairs.length, SCRUBS.length, 'a different number of scrubs');
    assert.deepEqual(
      SCRUBS.map(([re, replacement]) => [asPhp(re), replacement]),
      phpPairs,
    );
  });

  it('the replacement words and the caps', () => {
    assert.equal(REDACTED, phpConst(php, 'REDACTED'));
    assert.equal(LEDGER_REFUSED, phpConst(php, 'LEDGER_REFUSED'));
    assert.equal(VALUE_CAP, phpInt(php, 'VALUE_CAP'));
    assert.equal(KEY_CAP, phpInt(php, 'KEY_CAP'));
    assert.equal(MAX_KEYS, phpInt(php, 'MAX_KEYS'));
  });
});

describe('Normalizer.php == fold.ts normalizeMessage()', { skip: existsSync(NORMALIZER) ? false : 'app/Machine/ErrorFile/Normalizer.php is not there yet' }, () => {
  it('UUID, HEX_RUN, ABS_PATH, DIGITS and the 300 cut', () => {
    const php = readFileSync(NORMALIZER, 'utf8');
    const ts = normalizerPatternsForParity();
    assert.equal(asPhp(ts.UUID), phpConst(php, 'UUID'));
    assert.equal(asPhp(ts.HEX_RUN), phpConst(php, 'HEX_RUN'));
    assert.equal(asPhp(ts.ABS_PATH), phpConst(php, 'ABS_PATH'));
    assert.equal(asPhp(ts.DIGITS), phpConst(php, 'DIGITS'));
    assert.equal(ts.MAX, phpInt(php, 'MAX'));
  });
});

describe('LineFormat.php caps == format.ts / describe.ts', { skip: existsSync(LINE_FORMAT) ? false : 'app/Machine/ErrorFile/LineFormat.php is not there yet' }, () => {
  it('MESSAGE_CAP, DATA_CAP, RECORD_CAP, MAX_KEYS', () => {
    const php = readFileSync(LINE_FORMAT, 'utf8');
    assert.equal(MESSAGE_CAP, phpInt(php, 'MESSAGE_CAP'));
    assert.equal(DATA_CAP, phpInt(php, 'DATA_CAP'));
    assert.equal(RECORD_CAP, phpInt(php, 'RECORD_CAP'));
    assert.equal(MAX_KEYS, phpInt(php, 'MAX_KEYS'));
  });
});
