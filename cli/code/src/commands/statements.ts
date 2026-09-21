/**
 * The statements pipeline — pm/cli.mdx §10, apis.mdx §11–§12.
 *
 * The server does the work: walking the archive, both de-duplication layers,
 * the canonical rows, and — through Firefly's own store path with
 * error_if_duplicate_hash — deciding what already exists. The CLI:
 *
 *   - resolves the statements root (--root → FFX_STATEMENTS_DIR → the credentials file),
 *   - contains every path inside it BEFORE the call (the server re-checks),
 *   - prints plans, tokens and the exact apply command,
 *   - never writes anything under the archive.
 */
import fs from 'node:fs';
import path from 'node:path';

import type { Envelope } from '../client.js';
import { tildify } from '../config.js';
import { resolveStatementsRoot } from '../credentials.js';
import { CliError, EXIT } from '../errors.js';
import type { Column, Outcome, Row, View } from '../render.js';
import { getPath, inferColumns } from '../render.js';
import type { Ctx, FlagDef, VerbDef } from '../verbs.js';
import { applyCommand, C, changeNotes, get, rangeOf, RANGE_FLAGS, ref, rowsOf, send } from './shared.js';

const G = 'The statements pipeline';

const ROOT_FLAG: Record<string, FlagDef> = {
  root: { value: 'path', help: 'the statements root (default: FFX_STATEMENTS_DIR, then firefly_iii.statements.root)' },
};

const SCOPE_FLAGS: Record<string, FlagDef> = {
  entity: { value: 'string', help: 'only this entity' },
  bank: { value: 'string', help: 'only this institution' },
  account: { value: 'string', help: 'only this account (archive label or last-4)' },
  year: { value: 'int', help: 'only this year' },
};

function realOrSelf(p: string): string {
  try {
    return fs.realpathSync(p);
  } catch {
    return path.resolve(p);
  }
}

/** The statements root, resolved and required. */
export function rootOf(ctx: Ctx): string {
  const raw = resolveStatementsRoot(ctx.cfg, ctx.str('root'));
  if (!raw) {
    throw new CliError(EXIT.USAGE, 'no statements root configured', {
      hint: 'pass --root, set FFX_STATEMENTS_DIR, or set firefly_iii.statements.root in ~/.credentials/firefly_iii.json',
    });
  }
  const root = realOrSelf(raw.replace(/^~(?=\/|$)/, ctx.cfg.home));
  if (!fs.existsSync(root) || !fs.statSync(root).isDirectory()) {
    throw new CliError(EXIT.NOT_FOUND, `the statements root does not exist: ${tildify(root, ctx.cfg.home)}`);
  }
  return root;
}

/**
 * Path containment (cli.mdx §16): a file argument must resolve — symlinks
 * followed — to somewhere inside the root. Refused here for a clear message;
 * the server refuses it again.
 */
export function containedPath(root: string, candidate: string): string {
  const resolved = realOrSelf(path.isAbsolute(candidate) ? candidate : path.resolve(process.cwd(), candidate));
  const rel = path.relative(root, resolved);
  if (rel === '' || rel.startsWith('..') || path.isAbsolute(rel)) {
    throw new CliError(EXIT.USAGE, `refused: ${candidate} is outside the statements root`, {
      hint: 'every statements path must live inside the configured root',
    });
  }
  return resolved;
}

function scopeBody(ctx: Ctx): Record<string, unknown> {
  return {
    entity: ctx.str('entity'),
    institution: ctx.str('bank'),
    account: ctx.str('account'),
    year: ctx.str('year') ? parseInt(ctx.str('year') as string, 10) : undefined,
  };
}

function pickColumns(rows: Row[], preferred: Column[]): Column[] {
  const first = rows[0] ?? {};
  const hits = preferred.filter((c) => getPath(first, c.key) !== undefined).length;
  return rows.length && hits >= Math.ceil(preferred.length / 2) ? preferred : inferColumns(rows);
}

function listed(env: Envelope, keys: string[], preferred: Column[], notes: string[] = []): Outcome {
  const rows = rowsOf(env.data, ...keys);
  return { envelope: env, view: { rows, columns: pickColumns(rows, preferred), notes } };
}

// ------------------------------------------------------------- discover ---

const roots: VerbDef = {
  path: ['statements', 'roots'],
  group: G,
  summary: 'the configured statement roots and whether the server can read each',
  route: 'GET /ingest/roots',
  async run(ctx) {
    const env = await get(ctx, '/ingest/roots');
    return listed(env, ['roots'], [C.text('root'), C.bool('readable'), C.text('mode'), C.text('manifest')]);
  },
};

const manifest: VerbDef = {
  path: ['statements', 'manifest'],
  group: G,
  summary: 'what a prepared archive says about itself — the FIRST call of any import (prepared vs raw)',
  route: 'GET /ingest/manifest',
  flags: { ...ROOT_FLAG, 'manifest-path': { value: 'path', help: 'the manifest, relative to the root (default: auto-detect)' } },
  async run(ctx) {
    const root = rootOf(ctx);
    const env = await get(ctx, '/ingest/manifest', { root, manifest_path: ctx.str('manifest-path') });
    const data = (env.data ?? {}) as Record<string, unknown>;
    const totals = data.totals as Record<string, unknown> | undefined;
    const notes = [
      `mode: ${String(data.mode ?? '?')}${data.mode === 'prepared' ? ' — the bank\'s own ids are the dedupe keys; there is nothing to extract' : ''}`,
      ...(totals ? [`totals: ${Object.entries(totals).map(([k, v]) => `${k} ${String(v)}`).join(', ')}`] : []),
    ];
    return listed(env, ['accounts'], [
      C.text('entity'),
      C.text('institution', 'bank'),
      C.text('label'),
      C.text('last4'),
      C.text('kind'),
      C.text('currency', 'cur'),
      C.text('statements'),
      C.text('transactions'),
      C.text('first'),
      C.text('last'),
      C.text('warnings'),
    ], notes);
  },
};

const scan: VerbDef = {
  path: ['statements', 'scan'],
  group: G,
  summary: 'walk the archive: counts per entity/bank/account/year, missing months, duplicate scans, unreadable files',
  route: 'POST /ingest/scan',
  flags: { ...ROOT_FLAG, ...SCOPE_FLAGS, mode: { value: 'choice', choices: ['prepared', 'raw'], help: 'default: what the manifest says' } },
  async run(ctx) {
    const root = rootOf(ctx);
    ctx.spinner.start('Scanning the statements tree…');
    const env = await send(ctx, 'POST', '/ingest/scan', { root, mode: ctx.str('mode'), ...scopeBody(ctx) });
    ctx.spinner.stop();
    const data = (env.data ?? {}) as Record<string, unknown>;
    const flags = ['missing_months', 'duplicate_scans', 'unreadable'].map((k) => {
      const v = data[k];
      return `${k.replace(/_/g, ' ')}: ${Array.isArray(v) ? v.length : String(v ?? '?')}`;
    });
    return listed(env, ['groups', 'accounts'], [
      C.text('entity'),
      C.text('institution', 'bank'),
      C.text('account'),
      C.text('year'),
      C.text('statements'),
      C.text('missing'),
      C.text('duplicates'),
      C.text('unreadable'),
    ], [flags.join(' · ')]);
  },
};

const missing: VerbDef = {
  path: ['statements', 'missing'],
  group: G,
  summary: 'only the gaps, one ENTITY/BANK/ACCOUNT YYYY-MM per line — exits 3 when nothing is missing',
  route: 'GET /ingest/coverage',
  flags: { ...ROOT_FLAG, account: { value: 'string', help: 'only this account' } },
  examples: ['ffx statements missing > gaps.txt', 'ffx statements missing || echo all-present'],
  async run(ctx) {
    const root = rootOf(ctx);
    const env = await get(ctx, '/ingest/coverage', { root, account: ctx.str('account') });
    const gaps = rowsOf(env.data, 'missing');
    const lines = gaps.map((g) => `${String(g.entity ?? '?')}/${String(g.institution ?? g.bank ?? '?')}/${String(g.account ?? g.label ?? '?')} ${String(g.period ?? '?')}`);
    if (!lines.length) ctx.note('no missing account-months');
    return { envelope: env, lines, exit: lines.length ? EXIT.OK : EXIT.NOT_FOUND };
  },
};

const dupes: VerbDef = {
  path: ['statements', 'dupes'],
  group: G,
  summary: 'what both de-dupe layers collapsed, the verdict, and the rule that decided it; --prefer resolves a conflict',
  route: 'GET /ingest/dupes, POST /ingest/prefer',
  flags: { ...ROOT_FLAG, prefer: { value: 'path', help: 'resolve a statement conflict in favour of this file (records your choice in staging)' }, conflicts: { help: 'only the conflicts that block apply' } },
  async run(ctx) {
    const root = rootOf(ctx);
    const prefer = ctx.str('prefer');
    if (prefer) {
      const file = containedPath(root, prefer);
      const env = await send(ctx, 'POST', '/ingest/prefer', { root, path: file });
      return { envelope: env, lines: [`recorded: ${tildify(file, ctx.cfg.home)} wins its account-month. Re-run: ffx statements plan`] };
    }
    const env = await get(ctx, '/ingest/dupes', { root });
    let rows = rowsOf(env.data, 'groups', 'dupes');
    if (ctx.bool('conflicts')) rows = rows.filter((r) => r.verdict === 'conflict' || r.status === 'conflict');
    const conflicts = rows.filter((r) => r.verdict === 'conflict' || r.status === 'conflict').length;
    return {
      envelope: env,
      view: {
        rows,
        columns: pickColumns(rows, [C.text('layer'), C.text('account'), C.text('period'), C.text('verdict'), C.text('rule'), C.text('file'), C.text('superseded_by', 'superseded by'), C.text('ordinal'), C.text('tie_group', 'tie group')]),
        notes: conflicts
          ? [`${conflicts} conflict(s) block apply for their account-months. Ask which file is right, then: ffx statements dupes --prefer <file>`]
          : [],
      },
    };
  },
};

const map: VerbDef = {
  path: ['statements', 'map'],
  group: G,
  summary: 'the statements→Firefly-account map, and what is unmapped (--infer proposes one; never saves it)',
  route: 'GET /ingest/map, POST /ingest/map/infer',
  flags: { ...ROOT_FLAG, infer: { help: 'propose a mapping (by IBAN last-4, then name) without saving it' } },
  async run(ctx) {
    const root = rootOf(ctx);
    const env = ctx.bool('infer') ? await send(ctx, 'POST', '/ingest/map/infer', { root }) : await get(ctx, '/ingest/map', { root });
    return listed(env, ['accounts', 'map', 'entries'], [C.text('entity'), C.text('institution', 'bank'), C.text('label'), C.text('last4'), C.id('account_id', 'firefly id'), C.text('account_name', 'firefly account'), C.text('status')]);
  },
};

const rows: VerbDef = {
  path: ['statements', 'rows'],
  group: G,
  summary: 'the staged, de-duplicated rows for one account and range — parsed rows, never statement text',
  route: 'GET /ingest/rows',
  flags: { ...ROOT_FLAG, account: { value: 'string', help: 'archive account label or last-4' }, ...RANGE_FLAGS, limit: { value: 'int', help: 'row cap' } },
  async run(ctx) {
    const root = rootOf(ctx);
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/ingest/rows', { root, account: ctx.str('account'), start: r.start, end: r.end, limit: ctx.str('limit') });
    return listed(env, ['rows'], [C.date('date'), C.text('type'), C.amt('amount'), C.text('description'), C.text('external_id'), C.text('ordinal'), C.text('source_kind', 'source')]);
  },
};

const extract: VerbDef = {
  path: ['statements', 'extract'],
  group: G,
  summary: 'RAW archives only: PDFs and sidecars → rows in the staging directory (never the ledger, never the archive)',
  route: 'POST /ingest/extract',
  flags: { ...ROOT_FLAG, force: { help: 're-extract statements that already have rows' } },
  async run(ctx) {
    const root = rootOf(ctx);
    ctx.spinner.start('Extracting statements…');
    const env = await send(ctx, 'POST', '/ingest/extract', { root, force: ctx.bool('force') || undefined }, { longRunning: true });
    ctx.spinner.stop();
    const data = (env.data ?? {}) as Record<string, unknown>;
    const empty = rowsOf(data, 'empty', 'no_rows');
    const out = listed(env, ['statements', 'results'], [C.text('file'), C.text('source_kind', 'source'), C.text('rows'), C.text('status')], [
      `extracted ${String(data.extracted ?? '?')} statement(s), ${String(data.rows ?? '?')} row(s)`,
    ]);
    if (empty.length) {
      out.view?.notes?.push(`${empty.length} statement(s) yielded ZERO rows — a silent zero is how a year goes missing:`, ...empty.map((e) => `  ${String(e.file ?? e.path ?? JSON.stringify(e))}`));
      out.exit = EXIT.FAILED;
    }
    return out;
  },
};

// ------------------------------------------------------------ plan/apply ---

/**
 * A plan route that mints a token, and a separate apply route that redeems it.
 * The apply verb requires --token; without --write it asks the server to
 * re-check the token (a dry run of the recomputed plan) and changes nothing.
 */
async function applyWithToken(ctx: Ctx, route: string, extra: Record<string, unknown>, planVerb: string): Promise<Outcome> {
  const token = ctx.universal.token;
  if (!token) {
    throw new CliError(EXIT.USAGE, `apply needs the confirm token from "ffx ${planVerb}"`, { hint: `ffx ${planVerb}   then copy the apply command it prints` });
  }
  const maxChanges = ctx.universal.maxChanges;
  const body = { ...extra, confirm_token: token, dry_run: !ctx.universal.write, ...(maxChanges ? { max_changes: parseInt(maxChanges, 10) } : {}) };
  const client = await ctx.plane();
  if (ctx.universal.write) ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
  ctx.spinner.start(ctx.universal.write ? 'Applying…' : 'Re-checking the plan…');
  const env = await client.call('POST', route, { body, timeoutMs: null, progress: ctx.spinner });
  ctx.spinner.stop();
  const view = importView(env);
  view.title = ctx.universal.write ? 'APPLIED' : 'DRY RUN — the plan still holds; nothing was changed';
  if (!ctx.universal.write) view.notes = [...(view.notes ?? []), `apply with: ${applyCommand(token)}`];
  return { envelope: env, view };
}

const IMPORT_COLUMNS = [
  C.text('label', 'archive account'),
  C.text('target', 'firefly account'),
  C.text('statements_primary', 'stmts'),
  C.text('superseded'),
  C.text('conflicts'),
  C.text('rows_extracted', 'rows'),
  C.text('rows_after_dedupe', 'after dedupe'),
  C.text('already_present', 'already in firefly'),
  C.text('previously_deleted', 'deleted (stays deleted)'),
  C.text('new', 'NEW'),
  C.text('rules_would_categorise', 'rules categorise'),
];

function importView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const accounts = rowsOf(data, 'accounts', 'plan');
  const notes = changeNotes(data);
  const blocked = accounts.filter((a) => Number.isInteger(a.conflicts) ? (a.conflicts as number) > 0 : a.blocked === true);
  if (blocked.length) notes.push(`${blocked.length} account(s) BLOCKED by statement conflicts — ffx statements dupes --conflicts`);
  const deleted = accounts.some((a) => a.previously_deleted && a.previously_deleted !== 0 && a.previously_deleted !== '0');
  if (deleted) notes.push('rows you deleted in Firefly are recognised by its duplicate check and stay deleted');
  const range = data.date_range as Record<string, unknown> | undefined;
  if (range) notes.push(`date range ${String(range.start ?? '?')} .. ${String(range.end ?? '?')}`);
  return { rows: accounts, columns: pickColumns(accounts, IMPORT_COLUMNS), notes };
}

function tokenNotes(env: Envelope, applyVerb: string): string[] {
  const token = getPath(env.data, 'confirm_token');
  const expires = getPath(env.data, 'expires_at');
  if (typeof token !== 'string') return ['(no confirm token — nothing to apply)'];
  return [`confirm token  ${token}${typeof expires === 'string' ? `   expires ${expires}` : ''}`, `apply with:    ffx ${applyVerb} --token ${token} --write`];
}

const accountsPlan: VerbDef = {
  path: ['statements', 'accounts-plan'],
  group: G,
  summary: 'create / link / ambiguous per manifest row, with reasoning — creates nothing',
  route: 'POST /ingest/accounts/plan',
  flags: {
    ...ROOT_FLAG,
    naming: { value: 'string', help: 'naming template (default "{Entity} · {Institution} {Kind} ••{last4}")' },
    'liability-kind': { value: 'string', repeat: true, help: 'manifest kinds to create as liabilities (default loan, mortgage)' },
  },
  async run(ctx) {
    const root = rootOf(ctx);
    const env = await send(ctx, 'POST', '/ingest/accounts/plan', { root, naming: ctx.str('naming'), liability_kinds: ctx.list('liability-kind').length ? ctx.list('liability-kind') : undefined });
    const plan = rowsOf(env.data, 'plan');
    const rows = plan.map((p) => ({
      action: p.action,
      entity: getPath(p, 'manifest.entity'),
      bank: getPath(p, 'manifest.institution'),
      label: getPath(p, 'manifest.label'),
      last4: getPath(p, 'manifest.last4'),
      name: getPath(p, 'proposed.name') ?? getPath(p, 'existing.name'),
      type: getPath(p, 'proposed.type') ?? getPath(p, 'existing.type'),
      role: getPath(p, 'proposed.account_role') ?? getPath(p, 'proposed.liability_type'),
      reason: p.reason,
    }));
    const ambiguous = rows.filter((r) => r.action === 'ambiguous');
    const summary = getPath(env.data, 'summary') as Record<string, unknown> | undefined;
    const notes = [
      ...(summary ? [`summary: ${Object.entries(summary).map(([k, v]) => `${k} ${String(v)}`).join(', ')}`] : []),
      ...(ambiguous.length ? [`${ambiguous.length} AMBIGUOUS row(s) — resolve them before applying; ambiguous never becomes a create`] : []),
      'check every liability and brokerage row: the wrong type turns market movement into spending',
      ...tokenNotes(env, 'statements accounts-apply'),
    ];
    return {
      envelope: env,
      view: {
        title: 'PLAN — nothing was created',
        rows,
        columns: [C.text('action'), C.text('entity'), C.text('bank'), C.text('label'), C.text('last4'), C.text('name', 'firefly name'), C.text('type'), C.text('role'), C.text('reason')],
        notes,
      },
    };
  },
};

const accountsApply: VerbDef = {
  path: ['statements', 'accounts-apply'],
  group: G,
  summary: 'create the planned accounts in one batch and write the map beside the statements',
  route: 'POST /ingest/accounts/apply',
  writes: true,
  async run(ctx) {
    return applyWithToken(ctx, '/ingest/accounts/apply', {}, 'statements accounts-plan');
  },
};

const plan: VerbDef = {
  path: ['statements', 'plan'],
  group: G,
  summary: 'the full import plan — Firefly\'s own store path, rolled back: new, already present, previously deleted',
  route: 'POST /ingest/plan',
  flags: {
    ...ROOT_FLAG,
    account: { value: 'string', repeat: true, help: 'only these archive accounts (label or last-4)' },
    ...RANGE_FLAGS,
    'no-rules': { help: 'store without running Firefly\'s rules (default: rules run, as for a hand-entered row)' },
  },
  async run(ctx) {
    const root = rootOf(ctx);
    const r = rangeOf(ctx, 'none');
    ctx.spinner.start('Planning the import (Firefly\'s duplicate check, rolled back)…');
    const env = await send(
      ctx,
      'POST',
      '/ingest/plan',
      { root, accounts: ctx.list('account').length ? ctx.list('account') : undefined, start: r.start, end: r.end, apply_rules: ctx.bool('no-rules') ? false : undefined },
      { longRunning: true },
    );
    ctx.spinner.stop();
    const view = importView(env);
    view.title = 'PLAN — nothing was imported';
    view.notes = [...(view.notes ?? []), ...tokenNotes(env, 'statements apply')];
    return { envelope: env, view };
  },
};

const apply: VerbDef = {
  path: ['statements', 'apply'],
  group: G,
  summary: 'run the plan through Firefly\'s store path — recomputed at apply time; refuses if the ledger moved',
  route: 'POST /ingest/apply',
  writes: true,
  examples: ['ffx statements apply --token cf_01J8… --write', 'ffx statements apply --token cf_01J8… --write --max-changes 20000'],
  async run(ctx) {
    return applyWithToken(ctx, '/ingest/apply', {}, 'statements plan');
  },
};

const importFile: VerbDef = {
  path: ['statements', 'import-file'],
  group: G,
  summary: 'one file into one account — plan it, then apply with --token --write',
  route: 'POST /ingest/file/plan, POST /ingest/file/apply',
  writes: true,
  positionals: [{ name: 'path', required: true, help: 'an .ofx/.qfx/.csv/camt .xml file inside the statements root' }],
  flags: { ...ROOT_FLAG, account: { value: 'string', help: 'the Firefly account id or name (required)' } },
  async run(ctx) {
    const root = rootOf(ctx);
    const file = containedPath(root, ctx.positionals[0] as string);
    const account = ctx.str('account');
    if (!account) throw new CliError(EXIT.USAGE, 'import-file needs --account <firefly account id or name>');
    if (ctx.universal.token) return applyWithToken(ctx, '/ingest/file/apply', {}, 'statements import-file');
    const env = await send(ctx, 'POST', '/ingest/file/plan', { root, path: file, ...ref(account, 'account') }, { longRunning: true });
    const view = importView(env);
    if (!view.rows.length) {
      const items = rowsOf(env.data, 'rows', 'transactions');
      view.rows = items;
      view.columns = pickColumns(items, [C.date('date'), C.text('type'), C.amt('amount'), C.text('description'), C.text('verdict'), C.text('duplicate_of', 'duplicate of')]);
    }
    view.title = 'PLAN — nothing was imported';
    view.notes = [...(view.notes ?? []), ...tokenNotes(env, `statements import-file ${ctx.positionals[0] as string} --account ${account}`)];
    return { envelope: env, view };
  },
};

const runs: VerbDef = {
  path: ['statements', 'runs'],
  group: G,
  summary: 'the import run log, or one run\'s full report',
  route: 'GET /ingest/runs, GET /ingest/runs/{id}',
  positionals: [{ name: 'id', help: 'a run id' }],
  async run(ctx) {
    const id = ctx.positionals[0];
    if (id) return listed(await get(ctx, `/ingest/runs/${encodeURIComponent(id)}`), ['accounts', 'batches'], []);
    const env = await get(ctx, '/ingest/runs');
    return listed(env, ['runs'], [C.text('id'), C.text('started'), C.text('kind'), C.text('created'), C.text('duplicates'), C.text('outcome')]);
  },
};

export const statementsVerbs: VerbDef[] = [roots, manifest, scan, missing, dupes, map, rows, extract, accountsPlan, accountsApply, plan, apply, importFile, runs];
