/**
 * Unit tests — money, dates, the parser, rendering, credentials, the client's
 * pure helpers, and the chart pivot. pm/cli.mdx §18.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, it } from 'node:test';

import { editDistance, parseArgs, resolveVerb } from '../src/args.js';
import { encodeQuery, isEnvelope } from '../src/client.js';
import { pivotSeries, sparkline } from '../src/commands/analytics.js';
import { flattenGroups } from '../src/commands/ledger.js';
import { applyCommand, ref, refs } from '../src/commands/shared.js';
import { isLoopbackHost, loadConfig, unsafeTargetReason } from '../src/config.js';
import {
  describeKey,
  fingerprint,
  initMachineKey,
  isWellFormedKey,
  loadMachineKey,
  mintKey,
  rotateMachineKey,
} from '../src/credentials.js';
import { lastFullMonth, monthRange, relativeRange, resolveDate } from '../src/dates.js';
import { CliError, EXIT, EXIT_FOR_CODE, PLANE_CODES } from '../src/errors.js';
import { verbHelp } from '../src/help.js';
import { scrub } from '../src/logger.js';
import { amountInputProblem, compareDecimal, displayAmount, formatAmount, trimToPlaces } from '../src/money.js';
import { REGISTRY, assertUniquePaths } from '../src/registry.js';
import { cellText, renderCsv, renderTable } from '../src/render.js';
import { UNIVERSAL_FLAGS, verbName } from '../src/verbs.js';

// ----------------------------------------------------------------- money ---

describe('money — strings in, strings out', () => {
  it('groups the integer part without touching the digits', () => {
    assert.equal(formatAmount('1234567.891'), '1,234,567.891');
    assert.equal(formatAmount('-1234.5'), '-1,234.5');
    assert.equal(formatAmount('999'), '999');
    assert.equal(formatAmount('1000'), '1,000');
    assert.equal(formatAmount('0.01'), '0.01');
  });

  it('never "corrects" something that is not a decimal string', () => {
    assert.equal(formatAmount('abc'), 'abc');
    assert.equal(formatAmount('1e5'), '1e5');
  });

  it('trims Firefly\'s 12-place storage form to the currency\'s places without rounding', () => {
    assert.equal(trimToPlaces('12.500000000000', 2), '12.50');
    assert.equal(trimToPlaces('12.345000000000', 2), '12.345'); // a real digit is kept, never rounded away
    assert.equal(trimToPlaces('7', 2), '7.00');
    assert.equal(trimToPlaces('7.1', 2), '7.10');
    assert.equal(trimToPlaces('5000.000000000000', 0), '5000');
  });

  it('displays null as — (absent), and zero as zero', () => {
    assert.equal(displayAmount(null, 'USD'), '—');
    assert.equal(displayAmount(undefined), '—');
    assert.equal(displayAmount('0.00', 'USD'), '0.00 USD');
    assert.equal(displayAmount('4211.08', 'USD'), '4,211.08 USD');
  });

  it('accepts only positive plain decimals as input, and explains the refusal', () => {
    assert.equal(amountInputProblem('12.50'), undefined);
    assert.equal(amountInputProblem('0'), undefined);
    assert.match(amountInputProblem('-12.50') ?? '', /positive/);
    assert.match(amountInputProblem('1,200.00') ?? '', /separators/);
    assert.match(amountInputProblem('$5') ?? '', /currency symbol/);
    assert.match(amountInputProblem('1e3') ?? '', /exponent/);
    assert.ok(amountInputProblem('twelve'));
  });

  it('compares decimal strings without converting them to numbers', () => {
    assert.equal(compareDecimal('2', '10'), -1);
    assert.equal(compareDecimal('10.5', '10.49'), 1);
    assert.equal(compareDecimal('-3', '2'), -1);
    assert.equal(compareDecimal('-3', '-2'), -1);
    assert.equal(compareDecimal('1.50', '1.5'), 0);
    assert.equal(compareDecimal('0.1', '0.2'), -1);
    assert.equal(compareDecimal('99999999999999999999.01', '99999999999999999999.02'), -1); // beyond float precision
  });
});

// ----------------------------------------------------------------- dates ---

describe('dates — the wire never carries a relative date', () => {
  const now = new Date(2026, 8, 21, 23, 59); // 2026-09-21 23:59 local

  it('expands a month to its first and last day, leap years included', () => {
    assert.deepEqual(monthRange('2026-02'), { start: '2026-02-01', end: '2026-02-28' });
    assert.deepEqual(monthRange('2028-02'), { start: '2028-02-01', end: '2028-02-29' });
    assert.equal(monthRange('2026-13'), undefined);
  });

  it('resolves the closed vocabulary at month and year boundaries', () => {
    assert.deepEqual(relativeRange('last-month', now), { start: '2026-08-01', end: '2026-08-31' });
    assert.deepEqual(relativeRange('this-month', now), { start: '2026-09-01', end: '2026-09-30' });
    assert.deepEqual(relativeRange('ytd', now), { start: '2026-01-01', end: '2026-09-21' });
    assert.deepEqual(relativeRange('last-month', new Date(2026, 0, 15)), { start: '2025-12-01', end: '2025-12-31' });
    assert.equal(lastFullMonth(new Date(2026, 0, 1)), '2025-12');
  });

  it('picks the start or end of a range by side, and rejects nonsense', () => {
    assert.equal(resolveDate('2026-09', 'start'), '2026-09-01');
    assert.equal(resolveDate('2026-09', 'end'), '2026-09-30');
    assert.equal(resolveDate('2026-02-30', 'start'), undefined);
    assert.equal(resolveDate('soon', 'start'), undefined);
  });
});

// ---------------------------------------------------------------- parser ---

describe('the argument contract', () => {
  it('resolves the longest verb path, with flags anywhere', () => {
    const p = parseArgs(['--format', 'json', 'transactions', 'list', '--without-category', '--month', '2026-08'], REGISTRY);
    assert.equal(verbName(p.verb), 'transactions list');
    assert.equal(p.flags['without-category'], true);
    assert.equal(p.flags.start, undefined);
    assert.equal(p.flags.month, '2026-08');
    assert.equal(p.universal.format, 'json');
  });

  it('accepts --flag=value and repeatable comma lists', () => {
    const p = parseArgs(['transactions', 'list', '--account=1,Checking', '--account', 'Savings'], REGISTRY);
    assert.deepEqual(p.flags.account, ['1', 'Checking', 'Savings']);
  });

  it('refuses an unknown flag with the nearest match', () => {
    assert.throws(() => parseArgs(['accounts', 'list', '--typ', 'asset'], REGISTRY), (e: unknown) => e instanceof CliError && e.exit === EXIT.USAGE && /--type/.test(e.hint ?? ''));
  });

  it('refuses a missing value, a bad choice, a bad date and a negative amount', () => {
    assert.throws(() => parseArgs(['accounts', 'list', '--type'], REGISTRY), /needs a value/);
    assert.throws(() => parseArgs(['accounts', 'list', '--type', 'bank'], REGISTRY), /must be one of/);
    assert.throws(() => parseArgs(['transactions', 'list', '--start', 'yesterdayish'], REGISTRY), /YYYY-MM-DD/);
    assert.throws(() => parseArgs(['transactions', 'add', '--amount', '-5'], REGISTRY), /positive/);
  });

  it('resolves relative dates at parse time', () => {
    const p = parseArgs(['transactions', 'list', '--start', '2026-06'], REGISTRY);
    assert.equal(p.flags.start, '2026-06-01');
    const q = parseArgs(['transactions', 'list', '--end', '2026-06'], REGISTRY);
    assert.equal(q.flags.end, '2026-06-30');
  });

  it('refuses a stray positional that a switch cannot take (the missed-arity shape)', () => {
    assert.throws(() => parseArgs(['accounts', 'list', '--inactive', 'yes'], REGISTRY), /unexpected argument "yes"/);
  });

  it('supports --no-<switch>', () => {
    const p = parseArgs(['transactions', 'add', '--no-rules'], REGISTRY);
    assert.equal(p.flags.rules, false);
  });

  it('only matches bare ffx when there are no words', () => {
    assert.equal(resolveVerb([], REGISTRY).verb?.path.length, 0);
    assert.equal(resolveVerb(['nope'], REGISTRY).verb, undefined);
  });

  it('has unique verb paths, and every verb\'s help lists exactly its declared flags', () => {
    assertUniquePaths();
    for (const v of REGISTRY) {
      const help = verbHelp(v);
      for (const f of Object.keys(v.flags ?? {})) assert.ok(help.includes(`--${f}`), `${verbName(v)} help misses --${f}`);
      for (const f of Object.keys(UNIVERSAL_FLAGS)) assert.ok(help.includes(`--${f}`), `${verbName(v)} help misses universal --${f}`);
      for (const f of Object.keys(v.flags ?? {})) assert.ok(!(f in UNIVERSAL_FLAGS), `${verbName(v)} redeclares universal --${f}`);
    }
  });

  it('edit distance is sane', () => {
    assert.equal(editDistance('acounts', 'accounts'), 1);
    assert.equal(editDistance('', 'abc'), 3);
  });
});

// ------------------------------------------------------------- rendering ---

describe('rendering', () => {
  const cols = [
    { key: 'name', header: 'name' },
    { key: 'limit', header: 'limit', kind: 'amount' as const },
  ];
  const rows = [
    { name: 'Groceries', limit: '650.00', currency_code: 'USD' },
    { name: 'Dining', limit: null, currency_code: 'USD' },
    { name: 'Gifts', limit: '0.00', currency_code: 'USD' },
  ];

  it('renders "no limit" as — and a zero limit as 0.00 in a table', () => {
    const t = renderTable({ rows, columns: cols });
    assert.match(t, /Dining\s*│\s*—\s*│/);
    assert.match(t, /Gifts\s*│\s*0\.00 USD/);
  });

  it('renders "no limit" as an empty CSV field and keeps the server string verbatim', () => {
    const csv = renderCsv({ rows, columns: cols });
    assert.equal(csv, 'name,limit\nGroceries,650.00\nDining,\nGifts,0.00');
  });

  it('quotes CSV fields per RFC 4180', () => {
    assert.equal(renderCsv({ rows: [{ a: 'x,"y"' }], columns: [{ key: 'a', header: 'a' }] }), 'a\n"x,""y"""');
  });

  it('keeps a table amount\'s digits exactly', () => {
    assert.equal(cellText({ v: '1234567.891', currency_code: 'USD' }, { key: 'v', header: 'v', kind: 'amount' }, 'table'), '1,234,567.891 USD');
  });

  it('flattens a split group into one row per journal, marked n/m', () => {
    const flat = flattenGroups([{ id: 88, group_title: 'Costco', transactions: [{ transaction_journal_id: 1, date: '2026-09-03T00:00:00Z', description: 'food' }, { transaction_journal_id: 2, description: 'office' }] }]);
    assert.equal(flat.length, 2);
    assert.equal(flat[0]?.split, '1/2');
    assert.equal(flat[0]?.group_id, 88);
    assert.equal(flat[0]?.date, '2026-09-03');
    assert.equal(flat[1]?.description, 'Costco · office');
  });

  it('pivots chart series with gaps kept as gaps', () => {
    const { rows: r, sparks } = pivotSeries({ x: { kind: 'period', labels: ['a', 'b', 'c'] }, series: [{ label: 'Net', currency_code: 'USD', values: ['10', null, '30'] }] });
    assert.equal(r[1]?.s0, null);
    assert.match(sparks[0] ?? '', /Net \(USD\)/);
    assert.equal(sparkline(['10', null, '30']), '▁ █');
    assert.equal(sparkline(['5', '5']), '▄▄');
  });
});

// ---------------------------------------------------------------- client ---

describe('client helpers', () => {
  it('encodes arrays as name[]=v and drops undefined', () => {
    assert.equal(encodeQuery({ a: '1', b: undefined, ids: ['1', '2'], t: true }), '?a=1&ids%5B%5D=1&ids%5B%5D=2&t=true');
    assert.equal(encodeQuery({}), '');
  });

  it('recognises the envelope', () => {
    assert.ok(isEnvelope({ ok: true }));
    assert.ok(!isEnvelope({ data: 1 }));
    assert.ok(!isEnvelope(null));
  });

  it('refuses to send the key over plain http to a non-loopback host (R3)', () => {
    assert.equal(unsafeTargetReason('http://127.0.0.1:7373'), undefined);
    assert.equal(unsafeTargetReason('http://localhost:7373'), undefined);
    assert.equal(unsafeTargetReason('https://books.example.com'), undefined);
    assert.match(unsafeTargetReason('http://books.example.com:7373') ?? '', /cleartext/);
    assert.ok(isLoopbackHost('[::1]'));
  });

  it('maps every one of the nine plane codes to an exit code', () => {
    assert.equal(PLANE_CODES.length, 9);
    for (const c of PLANE_CODES) assert.ok(EXIT_FOR_CODE[c] !== undefined);
    assert.equal(EXIT_FOR_CODE.unauthorized, EXIT.AUTH);
    assert.equal(EXIT_FOR_CODE.conflict, EXIT.CONFLICT);
    assert.equal(EXIT_FOR_CODE.not_found, EXIT.NOT_FOUND);
  });

  it('builds the apply command from the same argv, replacing any old token', () => {
    assert.equal(applyCommand('cf_1', ['budget', 'set', 'Groceries', '--amount', '650.00', '--token', 'old']), 'ffx budget set Groceries --amount 650.00 --write --token cf_1');
    assert.equal(applyCommand('cf_1', ['search', 'a b']), "ffx search 'a b' --write --token cf_1");
  });

  it('builds id-or-name references', () => {
    assert.deepEqual(ref('12', 'account'), { account_id: '12' });
    assert.deepEqual(ref('Checking', 'account'), { account_name: 'Checking' });
    assert.deepEqual(refs(['1', 'Savings', '2'], 'account'), { account_ids: ['1', '2'], account_names: ['Savings'] });
  });

  it('scrubs anything key-shaped from log text', () => {
    assert.equal(scrub(`k=${'f'.repeat(64)} ok`), 'k=[REDACTED-KEY] ok');
  });
});

// ----------------------------------------------------------- credentials ---

describe('credentials — the machine key', () => {
  function tmpCfg(): { file: string; cfg: ReturnType<typeof loadConfig> } {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ffx-cred-'));
    const file = path.join(dir, 'sub', 'firefly_iii.json');
    const cfg = loadConfig({ HOME: dir, FFX_CREDENTIALS_FILE: file, FFX_STATE_DIR: path.join(dir, 'state') });
    return { file, cfg };
  }

  it('mints 64 lowercase hex from the CSPRNG — longer than a UUID', () => {
    const k = mintKey();
    assert.ok(isWellFormedKey(k));
    assert.ok(k.length > 36);
    assert.notEqual(mintKey(), k);
  });

  it('the fingerprint never contains the key', () => {
    const k = mintKey();
    const fp = fingerprint(k);
    assert.match(fp, /^[0-9a-f]{4}…\/sha256:[0-9a-f]{4}$/);
    assert.ok(!fp.includes(k.slice(4, 20)));
  });

  it('refuses loudly with the remedy when there is no key (R6)', () => {
    const { cfg } = tmpCfg();
    assert.throws(() => loadMachineKey(cfg), (e: unknown) => e instanceof CliError && e.exit === EXIT.USAGE && e.details.some((d) => d.includes('ffx key init')));
  });

  it('init mints at 0600 in a 0700 dir, merges, and keeps an existing key', () => {
    const { file, cfg } = tmpCfg();
    fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
    fs.writeFileSync(file, JSON.stringify({ other_product: { secret: 'keep-me' } }), { mode: 0o600 });
    const first = initMachineKey(cfg);
    assert.equal(first.created, true);
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
    const doc = JSON.parse(fs.readFileSync(file, 'utf8'));
    assert.equal(doc.other_product.secret, 'keep-me');
    assert.ok(isWellFormedKey(doc.firefly_iii.machine.api_key));
    const second = initMachineKey(cfg);
    assert.equal(second.created, false);
    assert.equal(second.fingerprint, first.fingerprint);
  });

  it('rotate replaces the key and keeps other products', () => {
    const { file, cfg } = tmpCfg();
    initMachineKey(cfg);
    const before = loadMachineKey(cfg).key;
    const r = rotateMachineKey(cfg);
    const after = loadMachineKey(cfg).key;
    assert.notEqual(before, after);
    assert.equal(r.previous, fingerprint(before));
    assert.ok(fs.existsSync(file));
  });

  it('refuses a world-readable file with the chmod fix', () => {
    const { file, cfg } = tmpCfg();
    initMachineKey(cfg);
    fs.chmodSync(file, 0o644);
    assert.throws(() => loadMachineKey(cfg), (e: unknown) => e instanceof CliError && /chmod 600/.test(e.hint ?? ''));
  });

  it('refuses a symlinked credentials file', () => {
    const { file, cfg } = tmpCfg();
    initMachineKey(cfg);
    const real = `${file}.real`;
    fs.renameSync(file, real);
    fs.symlinkSync(real, file);
    assert.throws(() => loadMachineKey(cfg), /symlink/);
  });

  it('refuses a malformed key instead of trimming or padding it', () => {
    const { file, cfg } = tmpCfg();
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, JSON.stringify({ firefly_iii: { machine: { api_key: 'ABC' } } }), { mode: 0o600 });
    assert.throws(() => loadMachineKey(cfg), /malformed/);
  });

  it('env beats the file, and a bad env key is refused', () => {
    const { cfg } = tmpCfg();
    const k = mintKey();
    assert.equal(loadMachineKey({ ...cfg, keyFromEnv: k }).source, 'env');
    assert.throws(() => loadMachineKey({ ...cfg, keyFromEnv: 'nope' }), /64 lowercase hex/);
  });

  it('key show describes everything except the key', () => {
    const { cfg } = tmpCfg();
    initMachineKey(cfg);
    const d = describeKey(cfg);
    const k = loadMachineKey(cfg).key;
    assert.equal(d.mode, '0600');
    assert.ok(!JSON.stringify(d).includes(k));
  });
});
