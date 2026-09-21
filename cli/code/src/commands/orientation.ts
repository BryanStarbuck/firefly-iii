/**
 * Orientation and operations — pm/cli.mdx §3.3, §8, §12.
 *
 *   ffx                 the orientation report (never starts the app)
 *   ffx status | doctor | up | stop | logs | whoami | capabilities
 *   ffx key init | show | rotate --yes
 *   ffx help [verb] | install-path
 */
import fs from 'node:fs';
import path from 'node:path';

import { ensureUp, isAlive, onPath, phpInfo, readPid, serverLogPath, startDetached, stopOurs, tailFile } from '../bringup.js';
import type { Envelope } from '../client.js';
import { PlaneClient, probeUp } from '../client.js';
import type { Config } from '../config.js';
import { tildify, unsafeTargetReason } from '../config.js';
import {
  describeKey,
  fingerprint,
  initMachineKey,
  resolveStatementsRoot,
  rotateMachineKey,
  tryLoadMachineKey,
} from '../credentials.js';
import { CliError, EXIT } from '../errors.js';
import { catalogue, verbHelp } from '../help.js';
import { resolveVerb } from '../args.js';
import type { Outcome, Row } from '../render.js';
import { out, stripControls } from '../render.js';
import type { Ctx, VerbDef } from '../verbs.js';
import { C, objectOutcome } from './shared.js';
import { resolveErrorFilePath } from '../vendor/error-file/node.js';
import { errorFileFor } from '../vendor/error-file/index.js';

const errors = errorFileFor('cli/code/src/commands/orientation.ts');

const G = 'Orientation';

// ------------------------------------------------------------ plane peek ---

interface PlanePeek {
  up: boolean;
  pid?: number;
  plane: 'ok' | 'unauthorized' | 'not-mounted' | 'no-key' | 'error' | 'skipped';
  planeDetail?: string;
  fingerprint?: string;
  whoami?: Record<string, unknown>;
  client?: PlaneClient;
}

/** Look at the app and the plane without starting anything and without throwing. */
async function peek(cfg: Config): Promise<PlanePeek> {
  const pid = readPid(cfg);
  const result: PlanePeek = { up: await probeUp(cfg), plane: 'skipped', ...(pid && isAlive(pid) ? { pid } : {}) };
  const { key, problem } = tryLoadMachineKey(cfg);
  if (key) result.fingerprint = fingerprint(key.key);
  if (!result.up) return result;
  if (!key) {
    result.plane = 'no-key';
    result.planeDetail = problem?.message ?? 'no key';
    return result;
  }
  const client = new PlaneClient(cfg, key, { timeoutMs: 5000, verbose: false });
  try {
    await client.call('GET', '/ping');
    result.plane = 'ok';
    result.client = client;
    try {
      const who = await client.call('GET', '/whoami');
      result.whoami = (who.data ?? {}) as Record<string, unknown>;
    } catch (err) {
      // A plane answer is shown in the report (R7); anything that is not a CliError is a fault.
      if (err instanceof CliError) errors.expected('asking the plane who we are', err);
      else errors.caught('asking the plane who we are', err);
      result.planeDetail = (err as Error).message;
    }
  } catch (err) {
    // The ping IS the question: its answer becomes the report's plane row (R7).
    if (err instanceof CliError) errors.expected('pinging the machine plane', err);
    else errors.caught('pinging the machine plane', err);
    const e = err as CliError;
    result.plane = e.exit === EXIT.AUTH ? 'unauthorized' : /not mounted/.test(e.message) ? 'not-mounted' : 'error';
    result.planeDetail = e.message;
  }
  return result;
}

function whoField(w: Record<string, unknown> | undefined, ...keys: string[]): string | undefined {
  if (!w) return undefined;
  for (const k of keys) {
    const v = w[k];
    if (typeof v === 'string' || typeof v === 'number') return String(v);
  }
  return undefined;
}

function writesLabel(w: Record<string, unknown> | undefined): string {
  const tiers = w?.tiers as Record<string, unknown> | undefined;
  if (!tiers) return 'unknown';
  return tiers.write ? 'ENABLED on the server' : 'DISABLED on the server   (FIREFLY_MACHINE_ALLOW_WRITE)';
}

async function count(client: PlaneClient | undefined, route: string, query: Record<string, string>, pick: (data: Record<string, unknown>) => string | undefined): Promise<string | undefined> {
  if (!client) return undefined;
  try {
    const env = await client.call('GET', route, { query, timeoutMs: 5000 });
    return pick((env.data ?? {}) as Record<string, unknown>);
  } catch (err) {
    // The orientation facts are optional: a plane answer leaves the fact blank (R7).
    if (err instanceof CliError) errors.expected('counting for the orientation report', err);
    else errors.caught('counting for the orientation report', err);
    return undefined;
  }
}

// ------------------------------------------------------------------ bare ---

const bare: VerbDef = {
  path: [],
  group: G,
  summary: 'the orientation report — what is running, and what needs doing (never starts the app)',
  async run(ctx) {
    const cfg = ctx.cfg;
    const p = await peek(cfg);
    const w = p.whoami;
    const lines = ['Firefly III — this machine'];
    lines.push(`  web          ${cfg.apiUrl}/   ${p.up ? 'UP' : 'down'}${p.pid ? `    (pid ${p.pid})` : ''}`);
    const planeText: Record<PlanePeek['plane'], string> = {
      ok: `ok    (key ${p.fingerprint})`,
      unauthorized: 'REFUSED the key — it changed on disk, or the app reads another file (ffx doctor)',
      'not-mounted': 'not mounted — is this checkout the fork with app/Machine/?',
      'no-key': `no machine key yet — the web app mints it on first run (${p.planeDetail ?? ''})`,
      error: `error — ${p.planeDetail ?? ''}`,
      skipped: p.fingerprint ? `not checked (app down) · key ${p.fingerprint}` : 'not checked (app down) · no key on disk yet',
    };
    lines.push(`  plane        /machine/v1   ${planeText[p.plane]}`);
    if (w) {
      const admin = whoField(w, 'administrationName', 'administration_name');
      const adminId = whoField(w, 'administrationId', 'administration_id');
      lines.push(`  operator     ${whoField(w, 'operator') ?? '?'} · administration "${admin ?? '?'}"${adminId ? ` (#${adminId})` : ''} · ${whoField(w, 'primaryCurrency', 'primary_currency') ?? ''}`);
      lines.push(`  writes       ${writesLabel(w)}`);
    }
    const facts: string[] = [];
    const uncategorized = await count(p.client, '/analytics/uncategorized-summary', {}, (d) => {
      const n = d.count ?? (d.totals as Record<string, unknown> | undefined)?.count;
      return n !== undefined ? `${String(n)} transactions without a category` : undefined;
    });
    if (uncategorized) facts.push(uncategorized);
    const gaps = await count(p.client, '/budget-period/gaps', {}, (d) => {
      const list = d.budgets_without_limit ?? d.gaps;
      return Array.isArray(list) ? `${list.length} budgets with spending and no limit this period` : undefined;
    });
    if (gaps) facts.push(gaps);
    if (facts.length) lines.push('', `  ${facts.join(' · ')}`);
    const root = resolveStatementsRoot(cfg, undefined);
    lines.push(`  statements   ${root ? tildify(root, cfg.home) : 'not configured (firefly_iii.statements.root in the credentials file)'}`);
    lines.push('');
    if (!p.up) lines.push('  ffx up                  start Firefly III');
    lines.push('  ffx doctor              check the environment');
    if (p.plane === 'ok') lines.push('  ffx budget period       this period\'s budgets', '  ffx transactions list --without-category --month this-month');
    if (root) lines.push('  ffx statements scan     look at the statements tree');
    lines.push('  ffx help                every verb');
    return {
      lines,
      json: {
        ok: true,
        data: {
          web: { url: cfg.apiUrl, up: p.up, pid: p.pid ?? null },
          plane: { state: p.plane, detail: p.planeDetail ?? null, key: p.fingerprint ?? null },
          whoami: w ?? null,
          statements_root: root ?? null,
        },
      },
    };
  },
};

// ---------------------------------------------------------------- status ---

const status: VerbDef = {
  path: ['status'],
  group: G,
  summary: 'web URL, pid, /up, the plane ping, key fingerprint, operator, administration, tiers',
  route: 'GET /up, GET /machine/v1/ping, GET /machine/v1/whoami',
  async run(ctx) {
    const p = await peek(ctx.cfg);
    const w = p.whoami;
    const rows: Row[] = [
      { field: 'web', value: `${ctx.cfg.apiUrl}/ ${p.up ? 'UP' : 'down'}` },
      { field: 'pid', value: p.pid ? String(p.pid) : '—' },
      { field: 'plane', value: p.plane + (p.planeDetail && p.plane !== 'ok' ? ` — ${p.planeDetail}` : '') },
      { field: 'key', value: p.fingerprint ?? 'none on disk' },
      { field: 'operator', value: whoField(w, 'operator') ?? '—' },
      { field: 'administration', value: whoField(w, 'administrationName', 'administration_name') ?? '—' },
      { field: 'primary currency', value: whoField(w, 'primaryCurrency', 'primary_currency') ?? '—' },
      { field: 'writes', value: w ? writesLabel(w) : '—' },
      { field: 'server log', value: tildify(serverLogPath(ctx.cfg), ctx.cfg.home) },
    ];
    return {
      view: { rows, columns: [C.text('field'), C.text('value')] },
      json: { ok: true, data: { up: p.up, pid: p.pid ?? null, plane: p.plane, key: p.fingerprint ?? null, whoami: w ?? null } },
      exit: p.up ? EXIT.OK : EXIT.UNAVAILABLE,
    };
  },
};

// ---------------------------------------------------------------- doctor ---

interface Check {
  check: string;
  required: boolean;
  ok: boolean;
  detail: string;
  fix?: string;
}

function readDotEnv(file: string): Record<string, string> {
  const out: Record<string, string> = {};
  try {
    for (const line of fs.readFileSync(file, 'utf8').split('\n')) {
      const m = /^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/.exec(line);
      if (m?.[1]) out[m[1]] = (m[2] ?? '').replace(/^["']|["']$/g, '');
    }
  } catch (err) {
    errors.expected('reading the app env file', err); // no .env
  }
  return out;
}

function findSqliteInside(dir: string): string[] {
  const hits: string[] = [];
  try {
    for (const name of fs.readdirSync(dir)) if (/\.sqlite$/.test(name)) hits.push(path.join(dir, name));
  } catch (err) {
    errors.expected('listing the SQLite files in a directory', err); // no dir
  }
  return hits;
}

const RECORD_HEADER = /^\[(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z)\] \[(?:WARN|ERROR|FATAL|EXPECTED)\] /;

/** Records (header lines) in the error file whose timestamp is within the last 24 hours. */
export function recentRecordCount(file: string, nowMs = Date.now()): number {
  let text: string;
  try {
    text = fs.readFileSync(file, 'utf8');
  } catch (err) {
    errors.expected('counting the recent error file records', err); // no error file yet: no records
    return 0;
  }
  const since = nowMs - 24 * 60 * 60 * 1000;
  let n = 0;
  for (const line of text.split('\n')) {
    const m = RECORD_HEADER.exec(line);
    if (m?.[1] && Date.parse(m[1]) >= since) n++;
  }
  return n;
}

/** The nearest directory at or above `p` that exists, or undefined. */
function nearestExisting(p: string): string | undefined {
  let dir = p;
  for (;;) {
    if (fs.existsSync(dir)) return dir;
    const up = path.dirname(dir);
    if (up === dir) return undefined;
    dir = up;
  }
}

/**
 * The `error file` doctor row — pm/error_err.mdx §5.7. PASS when the path is writable (or can be
 * created) and the file is 0600. WARN when the mode is wider, or when .env's FIREFLY_ERROR_FILE
 * points PHP at a different file than this process writes. Read-only: it never creates the file
 * or its directory.
 */
export function errorFileCheck(env: NodeJS.ProcessEnv, dotEnv: Record<string, string>, home: string, nowMs = Date.now()): Check {
  const file = resolveErrorFilePath(env);
  const problems: string[] = [];
  const fixes: string[] = [];
  let writable = false;
  let mode: number | undefined;
  if (fs.existsSync(file)) {
    try {
      fs.accessSync(file, fs.constants.W_OK);
      writable = true;
    } catch (err) {
      errors.expected('checking the error file is writable', err); // a doctor row, not a fault
      writable = false;
    }
    try {
      mode = fs.statSync(file).mode & 0o777;
    } catch (err) {
      errors.expected('reading the error file mode', err); // removed since existsSync
      mode = undefined;
    }
  } else {
    const dir = nearestExisting(path.dirname(file));
    if (dir) {
      try {
        fs.accessSync(dir, fs.constants.W_OK);
        writable = fs.statSync(dir).isDirectory();
      } catch (err) {
        errors.expected('checking the error file directory is writable', err); // a doctor row, not a fault
        writable = false;
      }
    }
  }
  if (!writable) {
    problems.push('not writable');
    fixes.push(`make ${tildify(path.dirname(file), home)} writable, or set FIREFLY_ERROR_FILE`);
  }
  if (mode !== undefined && mode !== 0o600) {
    problems.push(`mode ${mode.toString(8).padStart(4, '0')} is wider than 0600`);
    fixes.push(`chmod 600 ${tildify(file, home)}`);
  }
  const fromDotEnv = dotEnv.FIREFLY_ERROR_FILE;
  if (fromDotEnv) {
    // PHP reads .env when the shell does not export the variable; a server started from another
    // shell reads it regardless. Either way a .env value that resolves elsewhere splits the trail.
    if (resolveErrorFilePath({ ...env, FIREFLY_ERROR_FILE: fromDotEnv }) !== file) {
      problems.push(`.env's FIREFLY_ERROR_FILE (${fromDotEnv}) differs from this shell's`);
      fixes.push('set FIREFLY_ERROR_FILE in the shell, not only in .env, so ffx and PHP write one file');
    }
  }
  const recent = recentRecordCount(file, nowMs);
  const where = `${tildify(file, home)}${mode !== undefined ? ` ${mode.toString(8).padStart(4, '0')}` : ' (not created yet)'}`;
  return {
    check: 'error file',
    required: false,
    ok: problems.length === 0,
    detail: `${where}, ${recent} record${recent === 1 ? '' : 's'} in the last 24 h${problems.length ? ` — ${problems.join('; ')}` : ''}`,
    fix: fixes.join('; '),
  };
}

/** The newest mtime of any file under `dir`, recursively; 0 when there is none. */
function newestMtime(dir: string): number {
  let latest = 0;
  let entries: fs.Dirent[];
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch (err) {
    errors.expected('listing the browser module sources', err); // no dir: nothing newer
    return latest;
  }
  for (const e of entries) {
    const full = path.join(dir, e.name);
    try {
      latest = Math.max(latest, e.isDirectory() ? newestMtime(full) : fs.statSync(full).mtimeMs);
    } catch (err) {
      errors.expected('reading a browser module source mtime', err); // gone between readdir and stat
    }
  }
  return latest;
}

/**
 * The `web bundle` doctor row — pm/error_err.mdx §5.7: PASS when public/build/manifest.json is newer
 * than every file under resources/assets/v3/js/support/, so the browser error net is in the bundle.
 */
export function webBundleCheck(repoRoot: string): Check {
  const manifest = path.join(repoRoot, 'public', 'build', 'manifest.json');
  const fix = 'the browser error net is not in the built bundle — just build-web';
  let built: number;
  try {
    built = fs.statSync(manifest).mtimeMs;
  } catch (err) {
    errors.expected('reading the web bundle manifest', err); // not built: a doctor WARN row
    return { check: 'web bundle', required: false, ok: false, detail: 'public/build/manifest.json missing', fix };
  }
  const latest = newestMtime(path.join(repoRoot, 'resources', 'assets', 'v3', 'js', 'support'));
  const ok = built >= latest;
  return {
    check: 'web bundle',
    required: false,
    ok,
    detail: ok ? 'public/build is newer than resources/assets/v3/js/support/' : 'public/build is older than resources/assets/v3/js/support/',
    fix,
  };
}

const doctor: VerbDef = {
  path: ['doctor'],
  group: G,
  summary: 'is this environment sane? exits 1 if a REQUIRED check fails',
  async run(ctx) {
    const cfg = ctx.cfg;
    const checks: Check[] = [];
    const add = (c: Check): void => void checks.push(c);

    const nodeMajor = parseInt(process.versions.node.split('.')[0] ?? '0', 10);
    add({ check: 'Node >= 22', required: true, ok: nodeMajor >= 22, detail: process.versions.node, fix: 'brew upgrade node' });

    const php = phpInfo();
    add({ check: 'php >= 8.5 on PATH', required: true, ok: php.ok, detail: php.found ? (php.version ?? '?') : 'not found', fix: 'brew install php' });

    const composer = onPath('composer');
    const vendor = fs.existsSync(path.join(cfg.repoRoot, 'vendor', 'autoload.php'));
    add({ check: 'composer + vendor/', required: true, ok: !!composer && vendor, detail: `${composer ? 'composer ok' : 'no composer'}, ${vendor ? 'vendor ok' : 'no vendor/'}`, fix: 'brew install composer && just setup' });

    const envFile = path.join(cfg.repoRoot, '.env');
    const env = readDotEnv(envFile);
    const dbPath = env.DB_DATABASE ?? '';
    const dbInside = !dbPath || path.resolve(cfg.repoRoot, dbPath.replace(/^~/, cfg.home)).startsWith(cfg.repoRoot + path.sep);
    add({
      check: '.env, database outside the repo',
      required: true,
      ok: fs.existsSync(envFile) && (env.DB_CONNECTION !== 'sqlite' || !dbInside),
      detail: !fs.existsSync(envFile) ? 'no .env' : env.DB_CONNECTION === 'sqlite' ? `DB_DATABASE=${dbPath || '(default, inside the repo)'}` : `DB_CONNECTION=${env.DB_CONNECTION ?? '?'}`,
      fix: 'just setup — puts the SQLite file at ~/T/_firefly_iii/db/firefly.sqlite',
    });

    const strays = findSqliteInside(path.join(cfg.repoRoot, 'storage', 'database'));
    add({ check: 'no SQLite file inside the repo', required: true, ok: strays.length === 0, detail: strays.length ? strays.map((s) => path.relative(cfg.repoRoot, s)).join(', ') : 'none', fix: 'move it out of the repo tree (private data must never be in a public repo)' });

    let stateOk = false;
    try {
      fs.mkdirSync(cfg.stateDir, { recursive: true, mode: 0o700 });
      fs.accessSync(cfg.stateDir, fs.constants.W_OK);
      stateOk = true;
    } catch (err) {
      errors.expected('checking the state dir is writable', err); // reported as a FAIL row
      stateOk = false;
    }
    add({ check: 'state dir writable', required: true, ok: stateOk, detail: tildify(cfg.stateDir, cfg.home), fix: `mkdir -p ${tildify(cfg.stateDir, cfg.home)}` });

    const kd = describeKey(cfg);
    add({
      check: 'credentials file 0600, ours, key well-formed',
      required: true,
      ok: kd.exists && !kd.problem && !!kd.fingerprint,
      detail: kd.exists ? (kd.problem ?? `${kd.mode} ${kd.fingerprint}`) : `${kd.file} missing`,
      fix: kd.exists ? `chmod 600 ${kd.file}` : 'start the app once (ffx up) — the web app mints it — or ffx key init',
    });

    const reason = unsafeTargetReason(cfg.apiUrl);
    if (reason) add({ check: 'target is loopback or https', required: true, ok: false, detail: reason, fix: 'fix --api / FFX_API_URL' });

    const p = await peek(cfg);
    add({ check: `${cfg.apiUrl}/up`, required: false, ok: p.up, detail: p.up ? 'UP' : 'down', fix: 'ffx up' });
    add({ check: 'machine plane ping with the key', required: false, ok: p.plane === 'ok', detail: p.plane + (p.planeDetail && p.plane !== 'ok' ? ` — ${p.planeDetail}` : ''), fix: p.plane === 'unauthorized' ? 'the app holds a different key — check FIREFLY_MACHINE_CREDENTIALS_FILE in .env' : 'ffx up' });
    if (p.plane === 'ok') {
      const op = whoField(p.whoami, 'operator');
      add({ check: 'operator + administration resolved', required: false, ok: !!op, detail: op ?? p.planeDetail ?? 'unresolved', fix: 'set FIREFLY_MACHINE_OPERATOR in .env' });
    }
    const root = resolveStatementsRoot(cfg, undefined);
    let rootOk = false;
    if (root) {
      try {
        fs.accessSync(root, fs.constants.R_OK);
        rootOk = true;
      } catch (err) {
        errors.expected('checking the statements root is readable', err); // reported as a WARN row
        rootOk = false;
      }
    }
    add({ check: 'statements root configured + readable', required: false, ok: rootOk, detail: root ? tildify(root, cfg.home) : 'not configured', fix: 'set firefly_iii.statements.root in the credentials file' });

    add(errorFileCheck(process.env, env, cfg.home));
    add(webBundleCheck(cfg.repoRoot));

    const onPathFfx = onPath('ffx');
    const ours = path.join(cfg.repoRoot, 'cli', 'ffx');
    add({ check: 'ffx on PATH is this checkout', required: false, ok: onPathFfx === ours, detail: onPathFfx ?? 'not on PATH', fix: 'ffx install-path' });

    const failedRequired = checks.some((c) => c.required && !c.ok);
    const rows: Row[] = checks.map((c) => ({ ...c, status: c.ok ? 'ok' : c.required ? 'FAIL' : 'warn', fix: c.ok ? '' : c.fix ?? '' }));
    return {
      view: {
        rows,
        columns: [C.text('status'), C.text('check'), C.text('detail'), C.text('fix')],
        notes: [failedRequired ? 'doctor: a REQUIRED check failed' : 'doctor: required checks passed'],
      },
      json: { ok: !failedRequired, data: { checks } },
      exit: failedRequired ? EXIT.FAILED : EXIT.OK,
    };
  },
};

// ------------------------------------------------------------- up / stop ---

const up: VerbDef = {
  path: ['up'],
  group: G,
  summary: 'bring Firefly III up (php artisan serve, detached) and wait for /up',
  async run(ctx) {
    if (await probeUp(ctx.cfg)) {
      return { lines: [`already up at ${ctx.cfg.apiUrl}/`], json: { ok: true, data: { up: true, started: false } } };
    }
    if (!ctx.cfg.isDefaultTarget) {
      throw new CliError(EXIT.UNAVAILABLE, `ffx only brings up the local install, not ${ctx.cfg.apiUrl}`);
    }
    const pid = await startDetached(ctx.cfg, ctx.spinner);
    return {
      lines: [`up at ${ctx.cfg.apiUrl}/  (pid ${pid}, log ${tildify(serverLogPath(ctx.cfg), ctx.cfg.home)})`],
      json: { ok: true, data: { up: true, started: true, pid } },
    };
  },
};

const stop: VerbDef = {
  path: ['stop'],
  group: G,
  summary: 'stop OUR instance (the recorded pid tree) — never a foreign process',
  async run(ctx) {
    const r = await stopOurs(ctx.cfg);
    return {
      lines: [r.stopped ? `stopped pid ${r.pid}` : `nothing stopped: ${r.reason}`],
      json: { ok: true, data: r },
    };
  },
};

// ------------------------------------------------------------------ logs ---

/**
 * Laravel's own daily log that actually exists: the newest storage/logs/ff3-*.log by mtime
 * (ff3-cli-server-YYYY-MM-DD.log for the web process, ff3-cli-… for artisan), not the `single`
 * channel's file, which this install never writes (pm/error_err.mdx §5.7, §18.1 H11).
 */
export function newestLaravelLog(repoRoot: string): string | undefined {
  const dir = path.join(repoRoot, 'storage', 'logs');
  let best: { file: string; mtime: number } | undefined;
  let names: string[];
  try {
    names = fs.readdirSync(dir);
  } catch (err) {
    errors.expected('listing the Laravel daily logs', err); // no storage/logs yet
    return undefined;
  }
  for (const name of names) {
    if (!/^ff3-.*\.log$/.test(name)) continue;
    const file = path.join(dir, name);
    try {
      const mtime = fs.statSync(file).mtimeMs;
      if (!best || mtime > best.mtime) best = { file, mtime };
    } catch (err) {
      errors.expected('reading a Laravel daily log mtime', err); // rotated away between readdir and stat
    }
  }
  return best?.file;
}

/**
 * The owed burst counts in the fold sidecar (error.fold, next to the error file — pm/error_err.mdx
 * §3.5): one `owed: ×N <where> <doing>` line per open key. PHP rewrites the sidecar in place, so this
 * read takes no lock and tolerates a half-written file: empty or invalid JSON prints
 * `owed counts unavailable`. It never retries, never repairs and never writes. A missing sidecar
 * owes nothing.
 */
export function owedCounts(errorFile: string): string[] {
  const fold = path.join(path.dirname(errorFile), 'error.fold');
  let text: string;
  try {
    text = fs.readFileSync(fold, 'utf8');
  } catch (err) {
    errors.expected('reading the fold sidecar', err); // a missing sidecar owes nothing
    return [];
  }
  let state: unknown;
  try {
    state = JSON.parse(text);
  } catch (err) {
    errors.expected('parsing the fold sidecar', err); // half-written by PHP: printed as unavailable
    return ['owed counts unavailable'];
  }
  const keys = (state as { v?: unknown; k?: unknown } | null)?.k;
  if ((state as { v?: unknown } | null)?.v !== 1 || keys === null || typeof keys !== 'object' || Array.isArray(keys)) {
    return ['owed counts unavailable'];
  }
  const lines: string[] = [];
  for (const entry of Object.values(keys as Record<string, unknown>)) {
    const e = entry as { n?: unknown; W?: unknown; d?: unknown } | null;
    const n = e?.n;
    if (typeof n !== 'number' || !(n > 0)) continue;
    lines.push(stripControls(`owed: ×${n} ${String(e?.W ?? '')} ${String(e?.d ?? '')}`));
  }
  return lines;
}

const logs: VerbDef = {
  path: ['logs'],
  group: G,
  summary: 'tail ~/T/_firefly_iii/server.log, or the error file with --errors',
  flags: {
    follow: { help: 'keep printing new lines (Ctrl-C to stop)' },
    lines: { value: 'int', help: 'how many lines (default 40)' },
    errors: { help: 'the fault trail ~/T/firefly/error.err (FIREFLY_ERROR_FILE), then any owed burst counts' },
    laravel: { help: 'the newest Laravel daily log (storage/logs/ff3-*.log)' },
    cli: { help: 'the CLI\'s own cli.err' },
  },
  async run(ctx) {
    const errorsView = ctx.bool('errors');
    let file: string;
    if (errorsView) {
      file = resolveErrorFilePath(process.env);
    } else if (ctx.bool('laravel')) {
      const newest = newestLaravelLog(ctx.cfg.repoRoot);
      if (!newest) {
        throw new CliError(EXIT.NOT_FOUND, `no Laravel daily log (storage/logs/ff3-*.log) in ${tildify(ctx.cfg.repoRoot, ctx.cfg.home)} yet`);
      }
      file = newest;
    } else if (ctx.bool('cli')) {
      file = path.join(ctx.cfg.stateDir, 'cli.err');
    } else {
      file = serverLogPath(ctx.cfg);
    }
    ctx.note(`==> ${tildify(file, ctx.cfg.home)}`);
    const n = parseInt(ctx.str('lines') ?? '40', 10);
    const lines = tailFile(file, n);
    const owed = errorsView ? owedCounts(file) : [];
    if (!ctx.bool('follow')) {
      if (!fs.existsSync(file)) {
        if (owed.length) return { lines: owed, plain: true };
        throw new CliError(EXIT.NOT_FOUND, `${tildify(file, ctx.cfg.home)} does not exist yet`);
      }
      return { lines: [...lines, ...owed], plain: true };
    }
    if (lines.length || owed.length) out([...lines, ...owed].join('\n'));
    let offset = fs.existsSync(file) ? fs.statSync(file).size : 0;
    await new Promise<void>((resolve) => {
      const onSig = (): void => {
        fs.unwatchFile(file);
        resolve();
      };
      process.once('SIGINT', onSig);
      fs.watchFile(file, { interval: 500 }, (cur) => {
        if (cur.size < offset) offset = 0; // rotated
        if (cur.size === offset) return;
        const fd = fs.openSync(file, 'r');
        const buf = Buffer.alloc(cur.size - offset);
        fs.readSync(fd, buf, 0, buf.length, offset);
        fs.closeSync(fd);
        offset = cur.size;
        out(buf.toString('utf8').replace(/\n$/, ''));
      });
    });
    return { lines: [], plain: true };
  },
};

// ------------------------------------------------------------------- key ---

const keyInit: VerbDef = {
  path: ['key', 'init'],
  group: G,
  summary: 'mint the machine key if absent (the web app normally does this on its first run)',
  async run(ctx) {
    const r = initMachineKey(ctx.cfg);
    return {
      lines: [r.created ? `minted a new machine key ${r.fingerprint} in ${tildify(ctx.cfg.credentialsFile, ctx.cfg.home)}` : `a key already exists: ${r.fingerprint} (kept)`],
      json: { ok: true, data: r },
    };
  },
};

const keyShow: VerbDef = {
  path: ['key', 'show'],
  group: G,
  summary: 'path, mode, owner, fingerprint, created, created_by, label — never the key',
  async run(ctx) {
    const d = describeKey(ctx.cfg);
    const env: Envelope<unknown> = { ok: true, data: d };
    return { ...objectOutcome(env as Envelope), exit: d.exists && !d.problem ? EXIT.OK : EXIT.NOT_FOUND };
  },
};

const keyRotate: VerbDef = {
  path: ['key', 'rotate'],
  group: G,
  summary: 'mint a NEW machine key (needs --yes); restart the MCP server afterwards',
  async run(ctx) {
    if (!ctx.universal.yes) {
      throw new CliError(EXIT.USAGE, 'rotating the key changes the secret every client uses', { hint: 'ffx key rotate --yes' });
    }
    const r = rotateMachineKey(ctx.cfg);
    return {
      lines: [
        `rotated: ${r.previous ?? '(none)'} → ${r.fingerprint}`,
        'The Firefly app picks the new key up on its next request.',
        'Restart the firefly_iii MCP server so it re-reads the key.',
      ],
      json: { ok: true, data: r },
    };
  },
};

// ----------------------------------------------------- whoami / capabilities

const whoami: VerbDef = {
  path: ['whoami'],
  group: G,
  summary: 'the operator, administration, primary currency and tiers the plane is bound to',
  route: 'GET /whoami',
  async run(ctx) {
    const client = await ctx.plane();
    return objectOutcome(await client.call('GET', '/whoami'));
  },
};

const capabilities: VerbDef = {
  path: ['capabilities'],
  group: G,
  summary: 'what this server build can do: every route, its tier, and whether it is live or planned',
  route: 'GET /capabilities',
  flags: { planned: { help: 'only the routes the server has declared but not built yet' } },
  async run(ctx) {
    const client = await ctx.plane();
    const env = await client.call('GET', '/capabilities');
    const routes = ((env.data as Record<string, unknown> | undefined)?.routes ?? []) as Row[];
    const rows: Row[] = [];
    for (const r of routes) {
      const methods = (r.methods as string[] | undefined) ?? [];
      for (const m of methods) {
        const st = (r.status as Record<string, string> | undefined)?.[m] ?? '';
        if (ctx.bool('planned') && st !== 'planned') continue;
        rows.push({
          method: m,
          path: r.path,
          tier: (r.tier as Record<string, string> | undefined)?.[m] ?? '',
          status: st,
          summary: (r.summary as Record<string, string> | undefined)?.[m] ?? '',
        });
      }
    }
    const data = (env.data ?? {}) as Record<string, unknown>;
    const tiers = data.tiers as Record<string, unknown> | undefined;
    return {
      envelope: env,
      view: {
        rows,
        columns: [C.text('method'), C.text('path'), C.text('tier'), C.text('status'), C.text('summary')],
        notes: [
          `api ${String(data.apiVersion ?? '?')} · server ${String(data.serverVersion ?? '?')} · tiers read=${String(tiers?.read)} write=${String(tiers?.write)} admin=${String(tiers?.admin)}`,
          `features: ${Array.isArray(data.features) ? data.features.join(', ') : '?'}`,
        ],
      },
    };
  },
};

// ------------------------------------------------------------------ help ---

const help: VerbDef = {
  path: ['help'],
  group: G,
  summary: 'every verb, or one verb\'s flags and route',
  positionals: [{ name: 'verb', rest: true, help: 'the verb to explain, e.g. "transactions list"' }],
  async run(ctx: Ctx): Promise<Outcome> {
    if (!ctx.positionals.length) return { lines: [catalogue(ctx.registry)], plain: true };
    const { verb } = resolveVerb(ctx.positionals, ctx.registry);
    if (!verb || verb.path.length === 0) throw new CliError(EXIT.USAGE, `no verb "${ctx.positionals.join(' ')}"`, { hint: 'ffx help' });
    return { lines: [verbHelp(verb)], plain: true };
  },
};

const installPath: VerbDef = {
  path: ['install-path'],
  group: G,
  summary: 'print the PATH line for ~/.zshrc (never edits your profile)',
  async run(ctx) {
    const dir = path.join(ctx.cfg.repoRoot, 'cli');
    return { lines: [`export PATH="${dir}:$PATH"   # add this line to ~/.zshrc`], plain: true };
  },
};

/** Used by main for verbs that need the plane: bring the app up if needed. */
export async function ensureAppUp(ctx: Ctx): Promise<void> {
  await ensureUp(ctx.cfg, ctx.spinner, { noBringup: ctx.universal.noBringup || ctx.cfg.noBringup });
}

export const orientationVerbs: VerbDef[] = [bare, status, doctor, up, stop, logs, keyInit, keyShow, keyRotate, whoami, capabilities, help, installPath];
