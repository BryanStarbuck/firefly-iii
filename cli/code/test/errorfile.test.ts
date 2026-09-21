/**
 * ffx and the error file — pm/error_err.mdx §5.5–§5.7, §15 ("cli node --test"), R7, R13, R17.
 *
 * Spawned children write to their sandbox's FIREFLY_ERROR_FILE; in-process tests install the sink
 * with an explicit `file:` and reset it afterwards. Nothing here reaches ~/T/firefly/.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';

import { PlaneClient } from '../src/client.js';
import { errorFileCheck, owedCounts, recentRecordCount, webBundleCheck } from '../src/commands/orientation.js';
import { loadConfig } from '../src/config.js';
import { CliError, EXIT } from '../src/errors.js';
import { reportError } from '../src/main.js';
import { installNodeErrorFile, resetNodeErrorFileForTests } from '../src/vendor/error-file/node.js';
import type { FakePlane } from './fakeplane.js';
import { fail, ok, startFakePlane, TEST_KEY } from './fakeplane.js';
import { runCli, sandbox } from './helpers.js';

/** The canonical fault hint, R17. */
const H = 'The detail is in ~/T/firefly/error.err — ffx logs --errors';

function records(file: string): string[] {
  if (!fs.existsSync(file)) return [];
  return fs.readFileSync(file, 'utf8').split('\n').filter((l) => l.startsWith('['));
}

/** A plane that answers /up and never answers /machine/v1 — the timeout case. */
async function startHangingPlane(): Promise<{ url: string; close(): Promise<void> }> {
  const server = http.createServer((req, res) => {
    if ((req.url ?? '').startsWith('/up')) {
      res.writeHead(200, { 'Content-Type': 'text/html' });
      res.end('ok');
    }
    // /machine/v1/*: no answer, ever.
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  return {
    url: `http://127.0.0.1:${(server.address() as AddressInfo).port}`,
    close: () =>
      new Promise<void>((resolve) => {
        server.closeAllConnections();
        server.close(() => resolve());
      }),
  };
}

let plane: FakePlane;

before(async () => {
  plane = await startFakePlane();
});

after(async () => {
  await plane.close();
});

describe('spawned ffx writes faults, and only faults, to its FIREFLY_ERROR_FILE', () => {
  it('a usage error is an answer: no record', async () => {
    const sb = sandbox({ key: TEST_KEY, apiUrl: plane.url });
    const r = await runCli(['accounts', 'lisst'], sb.env);
    assert.equal(r.code, EXIT.USAGE, r.stderr);
    assert.equal(fs.existsSync(sb.errorFile), false, 'a usage error wrote the error file');
  });

  it('a plane refusal (not_found, conflict) is an answer: no record', async () => {
    plane.routes.set('GET /accounts/999', () => fail(404, 'not_found', 'No account #999.'));
    const sb = sandbox({ key: TEST_KEY, apiUrl: plane.url });
    const r = await runCli(['accounts', 'show', '999'], sb.env);
    assert.equal(r.code, EXIT.NOT_FOUND, r.stderr);
    assert.equal(fs.existsSync(sb.errorFile), false);
  });

  it('a plane internal is one [ERROR] [ffx] record carrying the rid the plane received, and the printed hint is H', async () => {
    plane.routes.set('GET /whoami', () => fail(500, 'internal', 'Firefly III failed on the server.', H));
    const sb = sandbox({ key: TEST_KEY, apiUrl: plane.url });
    try {
      const r = await runCli(['whoami'], sb.env);
      assert.equal(r.code, EXIT.FAILED, r.stderr);
      assert.ok(r.stderr.includes(`fix: ${H}\n`), r.stderr);
      const rid = plane.calls.at(-1)?.headers['x-firefly-request-id'];
      assert.match(String(rid), /^[0-9a-f]{8}$/);
      const lines = records(sb.errorFile);
      assert.equal(lines.length, 1, lines.join('\n'));
      assert.match(lines[0] as string, /^\[[^\]]+Z\] \[ERROR\] \[ffx\] \[cli\/code\/src\/main\.ts\] running an ffx verb — CliError: Firefly III failed on the server\. \(code=internal\) \{verb=whoami exit=1 code=internal rid=([0-9a-f]{8}) net=top pid=\d+\}$/);
      assert.ok((lines[0] as string).includes(`rid=${String(rid)} `));
      // --json-errors never serialises the rid.
      const j = await runCli(['whoami', '--json-errors'], sb.env);
      assert.ok(!j.stderr.includes('rid'), j.stderr);
    } finally {
      plane.routes.delete('GET /whoami');
    }
  });

  it('a non-CliError is one [ERROR] [ffx] record at 0600 with no key in it, and stderr names the file', async () => {
    plane.routes.set('GET /charts/net-worth', () => ok({ series: 5 }));
    const sb = sandbox({ key: TEST_KEY, apiUrl: plane.url });
    try {
      const r = await runCli(['chart', 'net-worth', '--format', 'table'], sb.env);
      assert.equal(r.code, EXIT.FAILED, r.stderr);
      assert.ok(r.stderr.includes(`\n  ${H}\n`), r.stderr);
      const lines = records(sb.errorFile);
      assert.equal(lines.length, 1, lines.join('\n'));
      assert.match(lines[0] as string, /\[ERROR\] \[ffx\] \[cli\/code\/src\/main\.ts\] running an ffx verb — TypeError: .*\{verb=chart exit=1 net=top pid=\d+\}/);
      const text = fs.readFileSync(sb.errorFile, 'utf8');
      assert.ok(!text.includes(TEST_KEY), 'the key reached the error file');
      assert.equal(fs.statSync(sb.errorFile).mode & 0o777, 0o600);
      assert.equal(fs.statSync(path.dirname(sb.errorFile)).isDirectory(), true);
    } finally {
      plane.routes.delete('GET /charts/net-worth');
    }
  });

  it('a non-envelope HTTP 500 is a [WARN] reading the app answer, with its status', async () => {
    const server = http.createServer((req, res) => {
      res.writeHead(req.url?.startsWith('/up') ? 200 : 500, { 'Content-Type': 'text/html' });
      res.end('<html>Whoops</html>');
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const sb = sandbox({ key: TEST_KEY, apiUrl: `http://127.0.0.1:${(server.address() as AddressInfo).port}` });
    try {
      const r = await runCli(['whoami'], sb.env);
      assert.equal(r.code, EXIT.UNAVAILABLE, r.stderr);
      assert.ok(r.stderr.includes(`fix: ${H}`), r.stderr);
      const lines = records(sb.errorFile);
      assert.equal(lines.length, 1, lines.join('\n'));
      assert.match(lines[0] as string, /\[WARN\] \[ffx\] \[cli\/code\/src\/client\.ts\] reading the app answer \{status=500 pid=\d+\}$/);
    } finally {
      server.closeAllConnections();
      await new Promise<void>((resolve) => server.close(() => resolve()));
    }
  });

  it('a plane-call timeout is one [WARN] [ffx], its hint ends with H, and --json-errors is unchanged', async () => {
    const hang = await startHangingPlane();
    const sb = sandbox({ key: TEST_KEY, apiUrl: hang.url });
    try {
      const r = await runCli(['whoami', '--timeout', '200'], sb.env);
      assert.equal(r.code, EXIT.FAILED, r.stderr);
      assert.ok(r.stderr.includes(`fix: raise --timeout. ${H}`), r.stderr);
      const lines = records(sb.errorFile);
      assert.equal(lines.length, 1, lines.join('\n'));
      assert.match(lines[0] as string, /\[WARN\] \[ffx\] \[cli\/code\/src\/main\.ts\] running an ffx verb — CliError: no answer from .* \{verb=whoami exit=1 net=top pid=\d+\} \| cause: Error: timeout \(code=ETIMEDOUT\)$/);
      const j = await runCli(['whoami', '--timeout', '200', '--json-errors'], sb.env);
      const parsed = JSON.parse(j.stderr.trim());
      assert.deepEqual(Object.keys(parsed.error).sort(), ['code', 'details', 'hint', 'message']);
      assert.equal(parsed.exit, 1);
    } finally {
      await hang.close();
    }
  });

  it('logs --errors tails the error file, then prints the owed counts', async () => {
    const sb = sandbox({ key: TEST_KEY, apiUrl: plane.url });
    fs.writeFileSync(sb.errorFile, '[2026-09-21T18:43:00.561Z] [ERROR] [ffx] [cli/code/src/main.ts] running an ffx verb — Error: synthetic {pid=1}\n', { mode: 0o600 });
    fs.writeFileSync(
      path.join(sb.dir, 'error.fold'),
      JSON.stringify({ v: 1, k: { a: { f: 1, s: 2, n: 37, l: 'ERROR', a: 'php-machine', W: 'app/Machine/Ingest/Importer.php', d: 'importing a statement row', h: 'RuntimeException: Synthetic' } }, b: {}, r: {} }),
    );
    const r = await runCli(['logs', '--errors'], sb.env);
    assert.equal(r.code, 0, r.stderr);
    assert.equal(
      r.stdout,
      '[2026-09-21T18:43:00.561Z] [ERROR] [ffx] [cli/code/src/main.ts] running an ffx verb — Error: synthetic {pid=1}\nowed: ×37 app/Machine/Ingest/Importer.php importing a statement row\n',
    );
    assert.equal(fs.existsSync(path.join(sb.dir, 'error.fold')), true);
  });
});

describe('in process: the classifier, owed counts and the doctor rows', () => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ffx-errorfile-'));
  const file = path.join(dir, 'error.err');
  const stderrWrite = process.stderr.write.bind(process.stderr);

  before(() => {
    installNodeErrorFile({ app: 'ffx', where: 'cli/code/test/errorfile.test.ts', file, handleProcessErrors: false });
  });

  after(() => {
    resetNodeErrorFileForTests();
  });

  it('a second plane-call timeout within 10 minutes writes no second line', async () => {
    const hang = await startHangingPlane();
    const sb = sandbox({ key: TEST_KEY, apiUrl: hang.url });
    const cfg = loadConfig(sb.env);
    const client = new PlaneClient(cfg, { key: TEST_KEY, source: 'env' } as never, { timeoutMs: 100, verbose: false });
    process.stderr.write = (() => true) as typeof process.stderr.write;
    try {
      for (let i = 0; i < 2; i++) {
        const err = await client.call('GET', '/whoami').then(
          () => undefined,
          (e: unknown) => e,
        );
        assert.ok(err instanceof CliError);
        assert.match(String(err.rid), /^[0-9a-f]{8}$/);
        assert.equal(reportError(err, false, false, 'whoami'), EXIT.FAILED);
      }
    } finally {
      process.stderr.write = stderrWrite;
      await hang.close();
    }
    resetNodeErrorFileForTests();
    installNodeErrorFile({ app: 'ffx', where: 'cli/code/test/errorfile.test.ts', file, handleProcessErrors: false });
    // One WARN; the second timeout is only counted, and the count is flushed as the window's
    // summary line — never a second record (§5.2, R10).
    const lines = records(file);
    assert.equal(lines.length, 2, lines.join('\n'));
    assert.match(lines[0] as string, /\[WARN\] \[ffx\] \[cli\/code\/src\/main\.ts\] running an ffx verb — CliError: no answer/);
    assert.match(lines[1] as string, /\[WARN\] \[ffx\] \[cli\/code\/src\/main\.ts\] running an ffx verb — ×1 more in the 10m window from /);
  });

  it('an answer CliError writes nothing; the same fault object is written once', () => {
    fs.rmSync(file, { force: true });
    process.stderr.write = (() => true) as typeof process.stderr.write;
    try {
      reportError(new CliError(EXIT.CONFLICT, 'a duplicate'), false, false, 'tx add');
      reportError(new CliError(EXIT.FAILED, 'doctor: a REQUIRED check failed'), false, false, 'doctor');
      const fault = new CliError(EXIT.FAILED, 'Firefly III failed on the server.', { code: 'internal', rid: 'deadbeef' });
      reportError(fault, false, false, 'batch');
      reportError(fault, true, false, 'batch');
    } finally {
      process.stderr.write = stderrWrite;
    }
    resetNodeErrorFileForTests();
    installNodeErrorFile({ app: 'ffx', where: 'cli/code/test/errorfile.test.ts', file, handleProcessErrors: false });
    const lines = records(file);
    assert.equal(lines.length, 1, lines.join('\n'));
    assert.match(lines[0] as string, /\{verb=batch exit=1 code=internal rid=deadbeef net=top pid=\d+\}$/);
  });

  it('owed counts: missing sidecar → nothing; empty or invalid → "owed counts unavailable"; zero counts are skipped', () => {
    const d = fs.mkdtempSync(path.join(os.tmpdir(), 'ffx-fold-'));
    const f = path.join(d, 'error.err');
    assert.deepEqual(owedCounts(f), []);
    fs.writeFileSync(path.join(d, 'error.fold'), '');
    assert.deepEqual(owedCounts(f), ['owed counts unavailable']);
    fs.writeFileSync(path.join(d, 'error.fold'), '{"v":1,"k":{');
    assert.deepEqual(owedCounts(f), ['owed counts unavailable']);
    fs.writeFileSync(path.join(d, 'error.fold'), '{"v":2,"k":{}}');
    assert.deepEqual(owedCounts(f), ['owed counts unavailable']);
    fs.writeFileSync(path.join(d, 'error.fold'), JSON.stringify({ v: 1, k: { a: { n: 0, W: 'x', d: 'y' }, b: { n: 2, W: 'mcp/src/server.ts', d: 'running an MCP tool' } } }));
    assert.deepEqual(owedCounts(f), ['owed: ×2 mcp/src/server.ts running an MCP tool']);
  });

  it('the error-file doctor row: ok when creatable, WARN on a wide mode and on a .env that points elsewhere', () => {
    const d = fs.mkdtempSync(path.join(os.tmpdir(), 'ffx-doctor-'));
    const f = path.join(d, 'nested', 'error.err');
    const env = { HOME: d, FIREFLY_ERROR_FILE: f };
    const fresh = errorFileCheck(env, {}, d);
    assert.equal(fresh.ok, true, fresh.detail);
    assert.equal(fs.existsSync(path.dirname(f)), false, 'the doctor created the directory');
    fs.mkdirSync(path.dirname(f));
    fs.writeFileSync(f, `[${new Date().toISOString()}] [ERROR] [ffx] [x] y — Error: z\n[2020-01-01T00:00:00.000Z] [ERROR] [ffx] [x] y — Error: z\n    at frame\n`, { mode: 0o644 });
    fs.chmodSync(f, 0o644);
    const wide = errorFileCheck(env, {}, d);
    assert.equal(wide.ok, false);
    assert.match(wide.detail, /0644 is wider than 0600/);
    assert.match(wide.detail, /1 record in the last 24 h/);
    fs.chmodSync(f, 0o600);
    assert.equal(errorFileCheck(env, { FIREFLY_ERROR_FILE: f }, d).ok, true);
    const split = errorFileCheck(env, { FIREFLY_ERROR_FILE: '~/elsewhere/error.err' }, d);
    assert.equal(split.ok, false);
    assert.match(split.fix ?? '', /set FIREFLY_ERROR_FILE in the shell/);
    assert.equal(recentRecordCount(f), 1);
  });

  it('the web-bundle doctor row: WARN when the manifest is missing or older than support/', () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ffx-bundle-'));
    const support = path.join(root, 'resources', 'assets', 'v3', 'js', 'support');
    fs.mkdirSync(support, { recursive: true });
    fs.writeFileSync(path.join(support, 'error-file.js'), '// synthetic\n');
    assert.equal(webBundleCheck(root).ok, false);
    fs.mkdirSync(path.join(root, 'public', 'build'), { recursive: true });
    const manifest = path.join(root, 'public', 'build', 'manifest.json');
    fs.writeFileSync(manifest, '{}');
    const future = new Date(Date.now() + 60_000);
    fs.utimesSync(manifest, future, future);
    assert.equal(webBundleCheck(root).ok, true);
    const past = new Date(Date.now() - 60_000);
    fs.utimesSync(manifest, past, past);
    const stale = webBundleCheck(root);
    assert.equal(stale.ok, false);
    assert.match(stale.fix ?? '', /just build-web/);
  });
});
