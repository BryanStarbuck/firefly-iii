/**
 * Regression tests — one per defect found in the bug hunt over the ffx CLI.
 * Each `it` names the bug it pins, so a revert shows up as a named failure.
 * pm/cli.mdx §18.
 */
import assert from 'node:assert/strict';
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';

import { parseArgs, resolveVerb } from '../src/args.js';
import { portAcceptsConnections, stopOurs, tailFile } from '../src/bringup.js';
import { pivotSeries, sparkline } from '../src/commands/analytics.js';
import { applyCommand } from '../src/commands/shared.js';
import { containedPath, realOrSelf } from '../src/commands/statements.js';
import { periodBody } from '../src/commands/writes.js';
import { loadConfig } from '../src/config.js';
import { loadMachineKey, tryLoadMachineKey } from '../src/credentials.js';
import { CliError } from '../src/errors.js';
import { compareDecimal } from '../src/money.js';
import { REGISTRY } from '../src/registry.js';
import { clipToWidth, displayWidth, renderCsv, renderTable } from '../src/render.js';
import type { Ctx, FlagValue } from '../src/verbs.js';
import type { FakePlane } from './fakeplane.js';
import { ok, startFakePlane, TEST_KEY, TEST_TOKEN, writeRoute } from './fakeplane.js';
import { runCli, sandbox } from './helpers.js';

function tmp(prefix: string): string {
  return fs.mkdtempSync(path.join(os.tmpdir(), prefix));
}

/** A Ctx with just enough to drive the pure helpers. */
function fakeCtx(flags: Record<string, FlagValue>): Ctx {
  const ctx = {
    flags,
    positionals: [],
    note: () => undefined,
    str: (n: string) => (typeof flags[n] === 'string' ? (flags[n] as string) : undefined),
    bool: (n: string) => flags[n] === true,
    list: (n: string) => {
      const v = flags[n];
      return Array.isArray(v) ? v : typeof v === 'string' ? [v] : [];
    },
  };
  return ctx as unknown as Ctx;
}

// ---------------------------------------------------------------- parser ---

describe('parser regressions', () => {
  it('a verb flag with a value BEFORE the verb words still resolves the verb (flags anywhere)', () => {
    const { verb } = resolveVerb(['transactions', '--month', '2026-09', 'list'], REGISTRY);
    assert.equal(verb?.path.join(' '), 'transactions list');
    const p = parseArgs(['transactions', '--month', '2026-09', 'list', '--without-category'], REGISTRY);
    assert.equal(p.verb.path.join(' '), 'transactions list');
    assert.equal(p.flags.month, '2026-09');
    assert.deepEqual(p.positionals, []);
  });

  it('--amount does not swallow the next flag as its value', () => {
    assert.throws(
      () => parseArgs(['transactions', 'add', '--amount', '--write'], REGISTRY),
      (e: unknown) => e instanceof CliError && e.exit === 2 && /--amount needs a value/.test(e.message),
    );
  });

  it('--amount still takes a negative-looking value so the refusal can explain direction', () => {
    for (const neg of ['-12.50', '-.5']) {
      assert.throws(
        () => parseArgs(['transactions', 'add', '--amount', neg], REGISTRY),
        (e: unknown) => e instanceof CliError && e.exit === 2 && /amounts are positive/.test(e.message),
      );
    }
  });

  it('a value flag cannot be negated with --no-, and says so instead of suggesting the flag', () => {
    assert.throws(
      () => parseArgs(['transactions', 'list', '--no-category'], REGISTRY),
      (e: unknown) => e instanceof CliError && e.exit === 2 && /cannot be negated/.test(e.message),
    );
  });

  it('a value flag followed by "--" is a missing value, not a value of "--"', () => {
    assert.throws(
      () => parseArgs(['search', '--limit', '--', 'x'], REGISTRY),
      (e: unknown) => e instanceof CliError && /needs a value/.test(e.message),
    );
  });

  it('undo accepts --show, as cli.mdx §11.2 documents', () => {
    const p = parseArgs(['undo', '--show'], REGISTRY);
    assert.equal(p.flags.show, true);
  });
});

// ------------------------------------------------------------ apply cmd ---

describe('the apply command reconstruction', () => {
  it('puts --write --token BEFORE a "--", or they would become positionals', () => {
    const cmd = applyCommand('cf_T', ['search', '--limit', '5', '--', '-foo', '--write']);
    assert.equal(cmd, 'ffx search --limit 5 --write --token cf_T -- -foo --write');
  });

  it('drops an old token and --write only before a "--"', () => {
    assert.equal(applyCommand('cf_new', ['undo', '--token=cf_old', '--write']), 'ffx undo --write --token cf_new');
  });
});

// ---------------------------------------------------------- budget period ---

describe('periodBody', () => {
  it('never mutates ctx.flags (it used to write --month into them and restore only on success)', () => {
    const flags: Record<string, FlagValue> = {};
    const r = periodBody(fakeCtx(flags));
    assert.deepEqual(Object.keys(flags), []);
    assert.match(r.start, /^\d{4}-\d{2}-01$/);
    const withMonth: Record<string, FlagValue> = { month: '2026-02' };
    assert.deepEqual(periodBody(fakeCtx(withMonth)), { start: '2026-02-01', end: '2026-02-28' });
    assert.deepEqual(withMonth, { month: '2026-02' });
  });

  it('refuses --month with --start and leaves the flags exactly as given', () => {
    const flags: Record<string, FlagValue> = { month: '2026-02', start: '2026-02-03' };
    assert.throws(() => periodBody(fakeCtx(flags)), /either --month or --start/);
    assert.deepEqual(flags, { month: '2026-02', start: '2026-02-03' });
  });
});

// ------------------------------------------------------------ containment ---

describe('statements path containment', () => {
  it('a file whose name starts with ".." is inside the root, not outside it', () => {
    const root = realOrSelf(tmp('ffx-root-'));
    fs.writeFileSync(path.join(root, '..statement.pdf'), 'x');
    assert.equal(containedPath(root, path.join(root, '..statement.pdf')), path.join(root, '..statement.pdf'));
  });

  it('a not-yet-existing file reached through a symlinked parent is compared resolved', () => {
    const root = realOrSelf(tmp('ffx-root-'));
    const linkDir = tmp('ffx-link-');
    const link = path.join(linkDir, 'archive');
    fs.symlinkSync(root, link);
    assert.equal(containedPath(root, path.join(link, 'new', 'file.pdf')), path.join(root, 'new', 'file.pdf'));
  });

  it('a symlink inside the root that points outside is refused even for a missing file under it', () => {
    const root = realOrSelf(tmp('ffx-root-'));
    const outside = tmp('ffx-outside-');
    fs.symlinkSync(outside, path.join(root, 'escape'));
    assert.throws(() => containedPath(root, path.join(root, 'escape', 'nope.pdf')), /outside the statements root/);
  });
});

// -------------------------------------------------------------- rendering ---

describe('rendering regressions', () => {
  it('aligns columns by display width with emoji and CJK', () => {
    const table = renderTable({
      rows: [{ n: 'Café ☕ run' }, { n: '寿司' }, { n: 'plain' }, { n: '👍🏽 ok' }],
      columns: [{ key: 'n', header: 'name' }],
    });
    const widths = table.split('\n').map(displayWidth);
    assert.ok(widths.every((w) => w === widths[0]), `ragged table:\n${table}`);
  });

  it('clips by columns and never splits a wide glyph', () => {
    assert.equal(clipToWidth('寿司寿司', 5), '寿司…');
    assert.ok(displayWidth(clipToWidth('寿司寿司', 5)) <= 5);
  });

  it('strips terminal control sequences from server text in a table', () => {
    const table = renderTable({ rows: [{ n: 'evil\u001b[2J\u001b[31mpayee\rX' }], columns: [{ key: 'n', header: 'n' }] });
    assert.ok(!table.includes('\u001b'));
    assert.ok(!table.includes('\r'));
  });

  it('a chart CSV header carries the series key, not an invented s0', () => {
    const { rows, columns } = pivotSeries({ x: { kind: 'period', labels: ['2026-07'] }, series: [{ key: 'net', label: 'Net', currency_code: 'USD', values: ['1.00'] }] });
    assert.equal(renderCsv({ rows, columns }).split('\n')[0], 'x,net');
  });

  it('sparkline treats "612.40" and "612.4" as one value', () => {
    const s = sparkline(['612.40', '612.4', '700']);
    assert.equal(s[0], s[1]);
    assert.notEqual(s[0], s[2]);
  });

  it('compareDecimal treats "-0.00" as zero', () => {
    assert.equal(compareDecimal('-0.00', '0'), 0);
    assert.equal(compareDecimal('-0.00', '0.01'), -1);
    assert.equal(compareDecimal('-0.01', '-0'), -1);
  });
});

// -------------------------------------------------------- bring-up, logs ---

describe('bring-up and logs regressions', () => {
  it('logs --lines 0 prints nothing, not the whole file', () => {
    const f = path.join(tmp('ffx-log-'), 'server.log');
    fs.writeFileSync(f, 'a\nb\nc\n');
    assert.deepEqual(tailFile(f, 0), []);
    assert.deepEqual(tailFile(f, 2), ['b', 'c']);
  });

  it('a listening port is detected without lsof (a plain connect)', async () => {
    const server = net.createServer();
    await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
    const port = (server.address() as net.AddressInfo).port;
    assert.equal(await portAcceptsConnections('127.0.0.1', port), true);
    await new Promise<void>((r) => server.close(() => r()));
    assert.equal(await portAcceptsConnections('127.0.0.1', port), false);
  });

  it('stop never signals a reused pid that is no longer the php server', async () => {
    const child = spawn('sleep', ['30'], { stdio: 'ignore' });
    try {
      const stateDir = tmp('ffx-state-');
      fs.writeFileSync(path.join(stateDir, 'server.pid'), `${child.pid}\n`);
      const cfg = { ...loadConfig({ HOME: stateDir, FFX_STATE_DIR: stateDir }) };
      const r = await stopOurs(cfg);
      assert.equal(r.stopped, false);
      assert.match(r.reason ?? '', /not the php server/);
      assert.equal(child.exitCode, null);
      assert.equal(child.signalCode, null);
      assert.ok(!fs.existsSync(path.join(stateDir, 'server.pid')));
    } finally {
      child.kill('SIGKILL');
    }
  });
});

// ----------------------------------------------------------- credentials ---

describe('credentials regressions', () => {
  it('an unreadable credentials file is a CliError (exit 2), never an uncaught crash', () => {
    if (typeof process.getuid === 'function' && process.getuid() === 0) return; // root reads anything
    const sb = sandbox({ key: TEST_KEY });
    fs.chmodSync(sb.credentialsFile, 0o000);
    try {
      const cfg = loadConfig(sb.env);
      assert.throws(() => loadMachineKey(cfg), (e: unknown) => e instanceof CliError && e.exit === 2);
      const t = tryLoadMachineKey(cfg);
      assert.ok(t.problem instanceof CliError);
    } finally {
      fs.chmodSync(sb.credentialsFile, 0o600);
    }
  });
});

// ----------------------------------------------------------- end to end ---

describe('end-to-end regressions (fake plane)', () => {
  let plane: FakePlane;
  before(async () => {
    plane = await startFakePlane();
  });
  after(async () => {
    await plane.close();
  });
  const env = (extra: Record<string, string> = {}): NodeJS.ProcessEnv => ({ ...sandbox({ key: TEST_KEY, apiUrl: plane.url }).env, ...extra });

  it('a flag before the verb words reaches the route', async () => {
    const r = await runCli(['transactions', '--month', '2026-09', 'list'], env());
    assert.equal(r.code, 0, r.stderr);
    const q = plane.calls.at(-1)?.query;
    assert.equal(q?.start, '2026-09-01');
    assert.equal(q?.end, '2026-09-30');
  });

  it('import-file sends only apis.mdx\'s arguments (account_* and path — no root)', async () => {
    const root = realOrSelf(tmp('ffx-stmts-'));
    const file = path.join(root, 'n.ofx');
    fs.writeFileSync(file, 'OFX');
    plane.routes.set('POST /ingest/file/plan', () => ok({ rows: [{ date: '2026-09-01', type: 'withdrawal', amount: '5.00', description: 'Coffee', verdict: 'new' }], confirm_token: TEST_TOKEN }));
    const r = await runCli(['statements', 'import-file', file, '--account', 'My Checking', '--format', 'table'], env({ FFX_STATEMENTS_DIR: root }));
    assert.equal(r.code, 0, r.stderr);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.deepEqual(Object.keys(body).sort(), ['account_name', 'path']);
    assert.match(r.stderr, /--account 'My Checking'/); // the printed apply command is copy-pasteable
  });

  it('--root outside the configured statements root is refused before any call', async () => {
    const configured = tmp('ffx-stmts-');
    const elsewhere = tmp('ffx-elsewhere-');
    const n = plane.calls.length;
    const r = await runCli(['statements', 'scan', '--root', elsewhere], env({ FFX_STATEMENTS_DIR: configured }));
    assert.equal(r.code, 2);
    assert.match(r.stderr, /outside the configured statements root/);
    assert.equal(plane.calls.length, n);
    const inside = path.join(configured, 'household');
    fs.mkdirSync(inside);
    plane.routes.set('POST /ingest/scan', () => ok({ groups: [] }));
    const okRun = await runCli(['statements', 'scan', '--root', inside], env({ FFX_STATEMENTS_DIR: configured }));
    assert.equal(okRun.code, 0, okRun.stderr);
  });

  it('rules run refuses --id with --group (the route takes one or the other)', async () => {
    const r = await runCli(['rules', 'run', '--id', '3', '--group', 'Imports'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /not both/);
  });

  it('undo --show previews and --show with --write is refused', async () => {
    const p = await runCli(['undo', '--show', '--format', 'table'], env());
    assert.equal(p.code, 0, p.stderr);
    assert.equal(plane.calls.at(-1)?.path, '/undo/last');
    const bad = await runCli(['undo', '--show', '--write', '--token', TEST_TOKEN], env());
    assert.equal(bad.code, 2);
  });

  it('budget set with no period uses this month and leaves no trace in the flags', async () => {
    plane.routes.set('PUT /budgets/Groceries/limits', writeRoute({ updated: 1 }));
    const r = await runCli(['budget', 'set', 'Groceries', '--amount', '650.00'], env());
    assert.equal(r.code, 0, r.stderr);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.match(String(body.start), /^\d{4}-\d{2}-01$/);
    assert.equal(body.amount, '650.00');
  });
});
