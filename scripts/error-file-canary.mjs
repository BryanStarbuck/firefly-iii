#!/usr/bin/env node
// The runtime canaries C1–C15 — pm/error_err.mdx §13.4. `just check-errors --canary` runs it.
//
// Every canary makes ONE net fire (or proves that one does NOT) and asserts the exact lines that land
// in a TEMP error file. The script creates a temp directory, points every child at it, runs the
// canaries, and deletes the directory — nothing is ever written to the real ~/T/firefly/error.err,
// the operator's ledger, key or state directory, and the operator's server on 7373 is never touched:
//
//   FIREFLY_ERROR_FILE=<tmp>/error.err  FIREFLY_ERROR_FILE_CANARY=1   (the canary routes, the artisan
//                                        command and the __canary branches refuse without BOTH)
//   DB_DATABASE=<tmp>/db.sqlite          an empty SQLite file, never the operator's ledger
//   FIREFLY_MACHINE_CREDENTIALS_FILE, FIREFLY_MACHINE_STATE_DIR → <tmp>  (an HTTP boot arms the plane
//                                        and would otherwise mint into the real key file)
//   LOG_CHANNEL=errorlog, SEND_ERROR_MESSAGE=false, MAIL_MAILER=array, CACHE_STORE=array,
//   SESSION_DRIVER=array                 no line in the operator's daily log, no error mail, no shared cache
//
// The PHP canaries run under the REAL SAPI: `php -S 127.0.0.1:<free port> -t public server.php`, from
// the repo root, so they see the real `app` value (§3.3).
//
//   C1   GET /__error-file-canary/throw                      one [ERROR] [php-web], net=report
//   C2   GET /api/v1/__error-file-canary/throw               one [ERROR] [php-api]
//   C3   GET /machine/v1/__error-file-canary/internal        one [ERROR] [php-machine], the previous RuntimeException, code=internal
//   C4   GET /machine/v1/__error-file-canary/conflict        no line
//   C5   GET /__error-file-canary/abort500                   one [ERROR] [php-web] (the >= 500 override)
//   C6   GET /__error-file-canary/log-bare                   one [WARN] [php-web], net=log, where = CanaryController.php:<line>
//   C7   GET /__error-file-canary/log-duplicate              no line
//   C8   GET /__error-file-canary/oom                        one [FATAL] [php-web], net=shutdown
//   C9   POST /error-report: (a) the fetch shape, (b) the beacon shape, (c) 60 events, (d) a cross-site
//        beacon, (e) another localhost origin                (a) one [ERROR] [web] via=php-web, (b) one more,
//                                                            (c) 50 lines and error.fold r.x = 10, (d)(e) nothing
//   C10  php artisan firefly-machine:error-file-canary throw  one [ERROR] [php-artisan]
//   C11  … fatal                                             one [FATAL] [php-artisan] FatalError, net=shutdown
//   C12  … queue                                             one [ERROR] [php-queue] with during=
//   C13  cli/ffx __canary throw | reject                     one [FATAL] [ffx] each, net=process
//   C14  node mcp/dist/index.js __canary                     one [FATAL] [mcp], stdout empty
//   C15  node --test errorfile/test/browser-core.test.mjs    passes; its committed batch carries the browser nets
//
// The run passes only when every canary passes and every net= value is seen across C1–C15 (the
// browser nets through C15's committed fixture errorfile/fixtures/browser-batch.json, which that test
// asserts byte for byte). `ingest` is not required: the one record that carries it by name — the
// lazy "dropped N browser reports" WARN — is written after the 60 s rate window closes and has no
// net= key in the locked §3.4 golden; IngestRouteTest asserts it with a fake clock.
//
//   node scripts/error-file-canary.mjs            run all
//   node scripts/error-file-canary.mjs --keep     keep the temp directory (printed) for inspection

import { spawn, spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { request } from 'node:http';
import { createServer, connect } from 'node:net';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const keep = process.argv.includes('--keep');
const REQUIRED_NETS = ['report', 'shutdown', 'log', 'process', 'top', 'window', 'rejection', 'axios', 'ajax', 'alpine', 'watchdog'];
const HEADER = /^\[(\d{4}-\d\d-\d\dT[^\]]+)\] \[([A-Z]+)\] \[([^\]]+)\] \[([^\]]*)\] (.*)$/;

const out = (line) => process.stdout.write(line + '\n');

// ---------------------------------------------------------------------------------------------
// The sandbox
// ---------------------------------------------------------------------------------------------

const dir = mkdtempSync(join(tmpdir(), 'firefly-error-canary-'));
const errorFile = join(dir, 'error.err');
const foldFile = join(dir, 'error.fold');
writeFileSync(join(dir, 'db.sqlite'), '');

const env = { ...process.env };
for (const k of ['FIREFLY_ERROR_FILE_VERBOSE', 'FIREFLY_ERROR_FILE_ECHO', 'FIREFLY_ERROR_FILE_TEST_PATH']) delete env[k];
Object.assign(env, {
  FIREFLY_ERROR_FILE: errorFile,
  FIREFLY_ERROR_FILE_CANARY: '1',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: join(dir, 'db.sqlite'),
  FIREFLY_MACHINE_CREDENTIALS_FILE: join(dir, 'credentials.json'),
  FIREFLY_MACHINE_STATE_DIR: join(dir, 'state'),
  LOG_CHANNEL: 'errorlog',
  SEND_ERROR_MESSAGE: 'false',
  MAIL_MAILER: 'array',
  CACHE_STORE: 'array',
  SESSION_DRIVER: 'array',
});

// A last guard: the paths this script hands its children must all be inside the temp directory.
for (const key of ['FIREFLY_ERROR_FILE', 'DB_DATABASE', 'FIREFLY_MACHINE_CREDENTIALS_FILE', 'FIREFLY_MACHINE_STATE_DIR']) {
  if (!env[key].startsWith(dir + '/')) throw new Error(`refusing to run: ${key} is outside the canary sandbox`);
}

// ---------------------------------------------------------------------------------------------
// Reading the file
// ---------------------------------------------------------------------------------------------

function headers() {
  if (!existsSync(errorFile)) return [];
  return readFileSync(errorFile, 'utf8')
    .split('\n')
    .map((line) => {
      const m = line.match(HEADER);
      if (!m) return null;
      const rest = m[5];
      const data = rest.match(/ \{([^{}]*)\}(?: \| cause: |$)/)?.[1] ?? '';
      return { line, level: m[2], app: m[3], where: m[4], rest, data, net: data.match(/(?:^| )net=([a-z]+)/)?.[1] ?? null };
    })
    .filter(Boolean);
}

const results = [];
const nets = new Set();

/** Run one canary: act(), then check the header lines it appended. */
async function canary(id, label, act, check) {
  const before = headers().length;
  let problem = null;
  try {
    await act();
    const added = headers().slice(before);
    for (const h of added) if (h.net) nets.add(h.net);
    problem = check(added);
  } catch (err) {
    problem = `threw: ${err?.message ?? err}`;
  }
  results.push({ id, label, ok: problem === null, problem });
  out(`  ${problem === null ? 'PASS' : 'FAIL'}  ${id.padEnd(4)} ${label}${problem === null ? '' : `\n          ${problem}`}`);
}

function exactly(n, added, predicate, what) {
  if (added.length !== n) return `expected ${n} line(s) ${what}, got ${added.length}: ${added.map((h) => h.line).join(' ‖ ') || '(none)'}`;
  const bad = added.find((h) => !predicate(h));
  return bad ? `expected ${what}, got: ${bad.line}` : null;
}

// ---------------------------------------------------------------------------------------------
// Processes
// ---------------------------------------------------------------------------------------------

function freePort() {
  return new Promise((resolve, reject) => {
    const srv = createServer();
    srv.unref();
    srv.on('error', reject);
    srv.listen(0, '127.0.0.1', () => {
      const { port } = srv.address();
      srv.close(() => resolve(port));
    });
  });
}

function waitForPort(port, ms) {
  const until = Date.now() + ms;
  return new Promise((resolve, reject) => {
    const attempt = () => {
      const sock = connect(port, '127.0.0.1');
      sock.once('connect', () => {
        sock.destroy();
        resolve();
      });
      sock.once('error', () => {
        sock.destroy();
        if (Date.now() > until) reject(new Error(`the canary server did not listen on ${port}`));
        else setTimeout(attempt, 100);
      });
    };
    attempt();
  });
}

function http(port, method, path, { headers = {}, body } = {}) {
  return new Promise((resolve, reject) => {
    // a browser always sends Content-Length (the gate requires it); without it node would send chunked
    const length = body === undefined ? {} : { 'Content-Length': String(Buffer.byteLength(body)) };
    const req = request({ host: '127.0.0.1', port, method, path, headers: { Host: `127.0.0.1:${port}`, ...length, ...headers } }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ status: res.statusCode, headers: res.headers, body: Buffer.concat(chunks).toString('utf8') }));
    });
    req.setTimeout(60_000, () => req.destroy(new Error(`${method} ${path} timed out`)));
    req.on('error', reject);
    if (body !== undefined) req.write(body);
    req.end();
  });
}

function run(cmd, args, opts = {}) {
  return spawnSync(cmd, args, { cwd: root, env, encoding: 'utf8', timeout: 120_000, ...opts });
}

// ---------------------------------------------------------------------------------------------
// The canaries
// ---------------------------------------------------------------------------------------------

let server = null;
try {
  out(`error-file runtime canaries (pm/error_err.mdx §13.4) — sandbox ${dir}`);

  const port = await freePort();
  if (port === 7373) throw new Error('refusing to run on the operator port 7373');
  server = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', 'public', 'server.php'], { cwd: root, env, stdio: ['ignore', 'ignore', 'ignore'] });
  await waitForPort(port, 15_000);
  const get = (path) => http(port, 'GET', path);

  await canary('C1', 'GET /__error-file-canary/throw', () => get('/__error-file-canary/throw'), (a) =>
    exactly(1, a, (h) => h.level === 'ERROR' && h.app === 'php-web' && h.net === 'report', '[ERROR] [php-web] net=report'),
  );
  await canary('C2', 'GET /api/v1/__error-file-canary/throw', () => get('/api/v1/__error-file-canary/throw'), (a) =>
    exactly(1, a, (h) => h.level === 'ERROR' && h.app === 'php-api' && h.net === 'report', '[ERROR] [php-api]'),
  );
  await canary('C3', 'GET /machine/v1/__error-file-canary/internal', () => get('/machine/v1/__error-file-canary/internal'), (a) =>
    exactly(
      1,
      a,
      (h) => h.level === 'ERROR' && h.app === 'php-machine' && / — RuntimeException: Synthetic canary cause /.test(h.rest) && /(?:^| )code=internal(?: |$)/.test(h.data),
      '[ERROR] [php-machine] headed by the previous RuntimeException, code=internal',
    ),
  );
  await canary('C4', 'GET /machine/v1/__error-file-canary/conflict', () => get('/machine/v1/__error-file-canary/conflict'), (a) =>
    exactly(0, a, () => true, '(a refusal is an answer)'),
  );
  await canary('C5', 'GET /__error-file-canary/abort500', () => get('/__error-file-canary/abort500'), (a) =>
    exactly(1, a, (h) => h.level === 'ERROR' && h.app === 'php-web' && /(?:^| )status=500(?: |$)/.test(h.data), '[ERROR] [php-web] status=500'),
  );
  await canary('C6', 'GET /__error-file-canary/log-bare', () => get('/__error-file-canary/log-bare'), (a) =>
    exactly(
      1,
      a,
      (h) => h.level === 'WARN' && h.app === 'php-web' && h.net === 'log' && /^app\/Machine\/ErrorFile\/Canary\/CanaryController\.php:\d+$/.test(h.where),
      '[WARN] [php-web] net=log at CanaryController.php:<line>',
    ),
  );
  await canary('C7', 'GET /__error-file-canary/log-duplicate', () => get('/__error-file-canary/log-duplicate'), (a) =>
    exactly(0, a, () => true, '(one expected duplicate transaction)'),
  );
  await canary('C8', 'GET /__error-file-canary/oom', () => get('/__error-file-canary/oom'), (a) =>
    exactly(1, a, (h) => h.level === 'FATAL' && h.app === 'php-web' && h.net === 'shutdown', '[FATAL] [php-web] net=shutdown'),
  );

  // C9 — the ingest route, in five shapes
  const origin = `http://127.0.0.1:${port}`;
  const post = (headers, events, sid = 'canary01') =>
    http(port, 'POST', '/error-report', { headers: { 'Content-Type': 'text/plain;charset=UTF-8', ...headers }, body: JSON.stringify({ sid, events }) });
  // letters only (g–z): the fold key normalises digits and long hex runs, so each event stays distinct
  const tag = (i) => 'ghjkmnpqrstuvwxyz'[Math.floor(i / 17) % 17] + 'ghjkmnpqrstuvwxyz'[i % 17];
  const event = (text, net = 'window') => ({
    ts: new Date().toISOString(),
    level: 'ERROR',
    where: '/canary/page',
    doing: 'running page script',
    error: `Error: Synthetic canary browser event ${text}`,
    cause: '',
    stack: 'at build/assets/canary.js:1:1',
    data: { net },
  });
  const expectNoContent = (res) => {
    if (res.status !== 204) throw new Error(`/error-report answered ${res.status}, not 204`);
  };
  const web = (h) => h.level === 'ERROR' && h.app === 'web' && /(?:^| )via=php-web(?: |$)/.test(h.data);
  await canary('C9a', 'POST /error-report, the keepalive fetch shape', async () => expectNoContent(await post({ Origin: origin, 'Sec-Fetch-Site': 'same-origin' }, [event('fetch')])), (a) =>
    exactly(1, a, web, '[ERROR] [web] … via=php-web'),
  );
  await canary('C9b', 'POST /error-report, the beacon shape (Origin: null)', async () => expectNoContent(await post({ Origin: 'null', 'Sec-Fetch-Site': 'same-origin' }, [event('beacon')])), (a) =>
    exactly(1, a, web, 'one more [ERROR] [web]'),
  );
  await canary(
    'C9c',
    'POST /error-report, 60 distinct events in one body',
    async () => expectNoContent(await post({ Origin: origin, 'Sec-Fetch-Site': 'same-origin' }, Array.from({ length: 60 }, (_, i) => event(`burst ${tag(i)}`)))),
    (a) => {
      const lines = exactly(50, a, web, '50 [ERROR] [web] lines (the event cap)');
      if (lines) return lines;
      let fold;
      try {
        fold = JSON.parse(readFileSync(foldFile, 'utf8'));
      } catch {
        return 'error.fold is missing or not JSON';
      }
      return fold?.r?.x === 10 ? null : `error.fold r.x is ${JSON.stringify(fold?.r?.x)}, expected 10`;
    },
  );
  await canary('C9d', 'POST /error-report, a cross-site beacon', async () => expectNoContent(await post({ Origin: 'null', 'Sec-Fetch-Site': 'cross-site' }, [event('cross site')])), (a) =>
    exactly(0, a, () => true, '(refused by the same-origin guard)'),
  );
  await canary('C9e', 'POST /error-report, another localhost origin', async () => expectNoContent(await post({ Origin: 'http://localhost:8000' }, [event('other origin')])), (a) =>
    exactly(0, a, () => true, '(refused: another origin)'),
  );

  server.kill('SIGTERM');
  server = null;

  // C10–C12 — the artisan canaries
  const artisan = (kind) => () => {
    const r = run('php', ['artisan', 'firefly-machine:error-file-canary', kind, '--no-interaction']);
    if (r.error) throw r.error;
  };
  await canary('C10', 'php artisan firefly-machine:error-file-canary throw', artisan('throw'), (a) =>
    exactly(1, a, (h) => h.level === 'ERROR' && h.app === 'php-artisan' && h.net === 'report', '[ERROR] [php-artisan]'),
  );
  await canary('C11', 'php artisan firefly-machine:error-file-canary fatal', artisan('fatal'), (a) =>
    exactly(
      1,
      a,
      (h) => h.level === 'FATAL' && h.app === 'php-artisan' && h.net === 'shutdown' && / — Symfony\\Component\\ErrorHandler\\Error\\FatalError: /.test(h.rest),
      '[FATAL] [php-artisan] Symfony\\Component\\ErrorHandler\\Error\\FatalError, net=shutdown',
    ),
  );
  await canary('C12', 'php artisan firefly-machine:error-file-canary queue', artisan('queue'), (a) =>
    exactly(1, a, (h) => h.level === 'ERROR' && h.app === 'php-queue' && /(?:^| )during=/.test(h.data), '[ERROR] [php-queue] with during='),
  );

  // C13 — ffx, through the shim (which rebuilds the CLI when its source is newer)
  for (const kind of ['throw', 'reject']) {
    await canary(`C13`, `cli/ffx __canary ${kind}`, () => {
      const r = run(join(root, 'cli', 'ffx'), ['__canary', kind]);
      if (r.error) throw r.error;
      if (r.status === 69) throw new Error('the CLI could not be built (exit 69): run just build');
    }, (a) => exactly(1, a, (h) => h.level === 'FATAL' && h.app === 'ffx' && h.net === 'process', '[FATAL] [ffx] net=process'));
  }
  // … and the branch refuses without the canary switch: no line
  await canary('C13r', 'cli/ffx __canary throw without FIREFLY_ERROR_FILE_CANARY', () => {
    const r = run(join(root, 'cli', 'ffx'), ['__canary', 'throw'], { env: { ...env, FIREFLY_ERROR_FILE_CANARY: '' } });
    if (r.error) throw r.error;
  }, (a) => exactly(0, a, () => true, '(the canary refuses)'));

  // C14 — the MCP server
  await canary('C14', 'node mcp/dist/index.js __canary', () => {
    const entry = join(root, 'mcp', 'dist', 'index.js');
    if (!existsSync(entry)) throw new Error('mcp/dist/index.js is missing: run just build');
    if (statSync(join(root, 'mcp', 'src', 'index.ts')).mtimeMs > statSync(entry).mtimeMs) throw new Error('mcp/dist is older than mcp/src: run just build');
    const r = run(process.execPath, [entry, '__canary']);
    if (r.error) throw r.error;
    if (r.stdout !== '') throw new Error(`stdout is not empty: ${JSON.stringify(r.stdout.slice(0, 200))}`);
  }, (a) => exactly(1, a, (h) => h.level === 'FATAL' && h.app === 'mcp', '[FATAL] [mcp]'));

  // C15 — the browser core, in node, against the committed batch
  await canary('C15', 'node --test errorfile/test/browser-core.test.mjs', () => {
    const r = run(process.execPath, ['--test', 'errorfile/test/browser-core.test.mjs']);
    if (r.error) throw r.error;
    if (r.status !== 0) throw new Error(`the browser-core test failed (exit ${r.status}):\n${(r.stdout + r.stderr).split('\n').filter((l) => /not ok|✖|fail/i.test(l)).slice(0, 12).join('\n')}`);
    const batch = JSON.parse(readFileSync(join(root, 'errorfile', 'fixtures', 'browser-batch.json'), 'utf8'));
    for (const e of batch.events ?? []) if (typeof e?.data?.net === 'string') nets.add(e.data.net);
  }, (a) => exactly(0, a, () => true, '(the browser core writes nothing itself)'));
} catch (err) {
  results.push({ id: '—', label: 'the canary run', ok: false, problem: err?.message ?? String(err) });
  out(`  FAIL  the canary run: ${err?.message ?? err}`);
} finally {
  if (server) server.kill('SIGKILL');
  if (keep) out(`  kept: ${dir}`);
  else rmSync(dir, { recursive: true, force: true });
}

const missing = REQUIRED_NETS.filter((n) => !nets.has(n));
const failed = results.filter((r) => !r.ok);
out(`  nets seen: ${[...nets].sort().join(' ')}${missing.length ? `   MISSING: ${missing.join(' ')}` : ''}`);
out(`  ${results.length - failed.length} of ${results.length} canary checks passed${missing.length ? `, ${missing.length} net value(s) never seen` : ', every net= value seen'}`);
if (failed.length > 0 || missing.length > 0) process.exitCode = 1;
