/**
 * Helpers every verb family shares: flag sets, date ranges, plane calls,
 * column builders, and the two-step write flow (pm/cli.mdx §11.1).
 */
import readline from 'node:readline';

import type { Envelope, Query } from '../client.js';
import { currentMonth, lastFullMonth, monthRange } from '../dates.js';
import { CliError, EXIT } from '../errors.js';
import type { Column, Outcome, Row, View } from '../render.js';
import { firstArray, getPath, inferColumns, objectView, renderTable, warn } from '../render.js';
import type { Ctx, FlagDef } from '../verbs.js';

// ------------------------------------------------------------- flag sets ---

export const LIST_FLAGS: Record<string, FlagDef> = {
  limit: { value: 'int', help: 'rows per page (server default 200, cap 5000 — clamped, and reported)' },
  offset: { value: 'int', help: 'skip this many rows' },
  order: { value: 'string', help: 'sort field, or -field for descending (the server sorts, never ffx)' },
};

export const RANGE_FLAGS: Record<string, FlagDef> = {
  start: { value: 'date', help: 'first day, inclusive (YYYY-MM-DD, YYYY-MM, today, last-month, ytd…)' },
  end: { value: 'date', help: 'last day, inclusive' },
  month: { value: 'month', help: 'a whole month: YYYY-MM, this-month or last-month' },
};

export const ACCOUNT_FILTER_FLAGS: Record<string, FlagDef> = {
  account: { value: 'string', repeat: true, help: 'account id or name (repeatable)' },
};

export const INTERVAL_FLAG: Record<string, FlagDef> = {
  interval: { value: 'choice', choices: ['month', 'quarter', 'year', 'none'], help: 'group by period (default month)' },
};

export const TRANSFERS_FLAG: Record<string, FlagDef> = {
  'include-transfers': { help: 'count transfers between your own accounts (excluded by default)' },
};

// ---------------------------------------------------------------- values ---

export function isNumericId(v: string): boolean {
  return /^\d+$/.test(v);
}

/** A path segment for {id}: the plane resolves a non-numeric segment as a name (apis.mdx §8). */
export function seg(v: string): string {
  return encodeURIComponent(v);
}

/** A body/query reference: `{ account_id: "12" }` or `{ account_name: "Checking" }`. */
export function ref(v: string | undefined, base: string): Record<string, string> {
  if (v === undefined) return {};
  return isNumericId(v) ? { [`${base}_id`]: v } : { [`${base}_name`]: v };
}

/** Repeatable id-or-name list → `{ account_ids: [...] }` and/or `{ account_names: [...] }`. */
export function refs(values: string[], base: string): Record<string, string[]> {
  const ids = values.filter(isNumericId);
  const names = values.filter((v) => !isNumericId(v));
  const out: Record<string, string[]> = {};
  if (ids.length) out[`${base}_ids`] = ids;
  if (names.length) out[`${base}_names`] = names;
  return out;
}

function resolveMonthWord(m: string): string {
  if (m === 'this-month') return currentMonth();
  if (m === 'last-month') return lastFullMonth();
  if (m === 'next-month') {
    const d = new Date();
    const n = new Date(d.getFullYear(), d.getMonth() + 1, 1);
    return `${n.getFullYear()}-${String(n.getMonth() + 1).padStart(2, '0')}`;
  }
  return m;
}

/** --month as YYYY-MM, with a fallback when absent. */
export function monthOf(ctx: Ctx, fallback: 'this-month' | 'last-month' | 'next-month' | undefined, name = 'month'): string | undefined {
  const raw = ctx.str(name) ?? fallback;
  return raw === undefined ? undefined : resolveMonthWord(raw);
}

export interface Range {
  start?: string;
  end?: string;
}

/**
 * The date range from --month or --start/--end, with a default. Echoed on
 * stderr so the resolved dates are always visible (§7.6).
 */
export function rangeOf(ctx: Ctx, fallback: 'this-month' | 'last-month' | 'ytd' | 'none' = 'none'): Range {
  const month = ctx.str('month');
  const start = ctx.str('start');
  const end = ctx.str('end');
  if (month && (start || end)) throw new CliError(EXIT.USAGE, 'give either --month or --start/--end, not both');
  let r: Range;
  if (month) {
    const mr = monthRange(resolveMonthWord(month));
    if (!mr) throw new CliError(EXIT.USAGE, `bad --month "${month}"`);
    r = mr;
  } else if (start || end) {
    r = { ...(start ? { start } : {}), ...(end ? { end } : {}) };
  } else if (fallback === 'this-month' || fallback === 'last-month') {
    r = monthRange(fallback === 'this-month' ? currentMonth() : lastFullMonth()) as Range;
    ctx.note(`(no period given — using ${fallback.replace('-', ' ')})`);
  } else if (fallback === 'ytd') {
    const y = new Date().getFullYear();
    const d = new Date();
    r = { start: `${y}-01-01`, end: `${y}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` };
    ctx.note('(no period given — using year to date)');
  } else {
    r = {};
  }
  if (r.start && r.end && r.start > r.end) throw new CliError(EXIT.USAGE, `--start ${r.start} is after --end ${r.end}`);
  if (r.start || r.end) ctx.note(`range ${r.start ?? '…'} .. ${r.end ?? '…'}`);
  return r;
}

export function listQuery(ctx: Ctx): Query {
  return { limit: ctx.str('limit'), offset: ctx.str('offset'), order: ctx.str('order') };
}

// ----------------------------------------------------------------- calls ---

export async function get(ctx: Ctx, route: string, query?: Query): Promise<Envelope> {
  const client = await ctx.plane();
  return client.call('GET', route, query ? { query } : {});
}

export async function send(ctx: Ctx, method: string, route: string, body: unknown, opts: { longRunning?: boolean } = {}): Promise<Envelope> {
  const client = await ctx.plane();
  return client.call(method, route, {
    body,
    ...(opts.longRunning ? { timeoutMs: null, progress: ctx.spinner } : {}),
  });
}

// --------------------------------------------------------------- columns ---

export const C = {
  text: (key: string, header = key): Column => ({ key, header, kind: 'text' }),
  id: (key: string, header = key): Column => ({ key, header, kind: 'id', align: 'right' }),
  date: (key: string, header = key): Column => ({ key, header, kind: 'date' }),
  bool: (key: string, header = key): Column => ({ key, header, kind: 'bool' }),
  amt: (key: string, header = key, currencyKey = 'currency_code'): Column => ({ key, header, kind: 'amount', currencyKey }),
};

/** The rows of a list response: the first of `keys` present, else the first array. */
export function rowsOf(data: unknown, ...keys: string[]): Row[] {
  for (const k of keys) {
    const v = getPath(data, k);
    if (Array.isArray(v)) return v as Row[];
  }
  return firstArray(data)?.rows ?? [];
}

export function listOutcome(env: Envelope, columns: Column[], keys: string[], notes: string[] = []): Outcome {
  const rows = rowsOf(env.data, ...keys);
  return { envelope: env, view: { rows, columns: columns.length ? columns : inferColumns(rows), notes } };
}

export function objectOutcome(env: Envelope, pick?: string): Outcome {
  const data = pick ? getPath(env.data, pick) : env.data;
  const obj = data && typeof data === 'object' && !Array.isArray(data) ? (data as Record<string, unknown>) : { value: data };
  return { envelope: env, view: objectView(obj) };
}

/** The provenance block analytics routes return, as stderr notes (apis.mdx §10.3). */
export function provenanceNotes(data: unknown): string[] {
  const p = getPath(data, 'provenance') as Record<string, unknown> | undefined;
  if (!p || typeof p !== 'object') return [];
  const bits = Object.entries(p).map(([k, v]) => `${k}=${Array.isArray(v) ? v.join(',') : typeof v === 'object' ? JSON.stringify(v) : String(v)}`);
  return bits.length ? [`provenance: ${bits.join(' · ')}`] : [];
}

// ----------------------------------------------------------- write flow ---

export interface WriteSpec {
  method: string;
  route: string;
  body: Record<string, unknown>;
  /** Long ingest routes: no client timeout, NDJSON progress. */
  longRunning?: boolean;
  /** How to show the dry run's plan. Default: changes + first list in data. */
  planView?: (env: Envelope) => View;
  /** How to show the applied result. Default: the same as the plan view. */
  appliedView?: (env: Envelope) => View;
}

function shellQuote(arg: string): string {
  return /^[A-Za-z0-9_./:@%+=,-]+$/.test(arg) ? arg : `'${arg.replace(/'/g, `'\\''`)}'`;
}

/** The exact command that applies this plan: the same argv, minus any old token, plus --write --token. */
export function applyCommand(token: string, argv: readonly string[] = process.argv.slice(2)): string {
  const kept: string[] = [];
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i] as string;
    if (a === '--token') {
      i++;
      continue;
    }
    if (a.startsWith('--token=') || a === '--write') continue;
    kept.push(a);
  }
  return `ffx ${[...kept, '--write', '--token', token].map(shellQuote).join(' ')}`;
}

/** Default rendering of a plan or result: a changes summary row set plus any listed items. */
export function defaultChangeView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const list = firstArray(data);
  if (list && list.rows.length) {
    return { rows: list.rows, columns: inferColumns(list.rows), notes: changeNotes(data) };
  }
  const changes = data.changes && typeof data.changes === 'object' ? (data.changes as Record<string, unknown>) : undefined;
  const view = objectView(changes ?? data);
  view.notes = changeNotes(data);
  return view;
}

export function changeNotes(data: Record<string, unknown>): string[] {
  const notes: string[] = [];
  const changes = data.changes as Record<string, unknown> | undefined;
  if (changes && typeof changes === 'object') {
    notes.push(`changes: ${Object.entries(changes).map(([k, v]) => `${k} ${String(v)}`).join(', ')}`);
  }
  if (typeof data.would_fire_webhooks === 'number' && data.would_fire_webhooks > 0) {
    notes.push(`note: applying would fire ${data.would_fire_webhooks} webhook(s)`);
  }
  if (typeof data.operation_id === 'number' || typeof data.operation_id === 'string') {
    notes.push(`recorded as operation #${String(data.operation_id)} — reverse it with: ffx undo`);
  }
  return notes;
}

function tokenOf(env: Envelope): { token?: string; expires?: string } {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const token = typeof data.confirm_token === 'string' ? data.confirm_token : undefined;
  const expires = typeof data.expires_at === 'string' ? data.expires_at : undefined;
  return { ...(token ? { token } : {}), ...(expires ? { expires } : {}) };
}

function ask(question: string): Promise<boolean> {
  const rl = readline.createInterface({ input: process.stdin, output: process.stderr });
  return new Promise((resolve) => {
    rl.question(question, (answer) => {
      rl.close();
      resolve(/^y(es)?$/i.test(answer.trim()));
    });
  });
}

/**
 * The two-step write — pm/cli.mdx §11.1.
 *
 *   no --write              → dry run: print the plan, the token, the apply command. Nothing changes.
 *   --write --token T       → apply with dry_run:false and the token. The server re-checks everything.
 *   --write, no token, TTY  → dry run, show the plan on stderr, ask, then apply with that token.
 *   --write, no token, pipe → dry run, print the plan, exit 2 naming the token. Scripts must capture a plan.
 */
export async function writeFlow(ctx: Ctx, spec: WriteSpec): Promise<Outcome> {
  const planView = spec.planView ?? defaultChangeView;
  const appliedView = spec.appliedView ?? planView;
  const maxChanges = ctx.universal.maxChanges;
  const base = { ...spec.body, ...(maxChanges ? { max_changes: parseInt(maxChanges, 10) } : {}) };
  const client = await ctx.plane();
  const long = spec.longRunning ? { timeoutMs: null, progress: ctx.spinner } : {};

  const apply = async (token: string): Promise<Outcome> => {
    ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
    if (spec.longRunning) ctx.spinner.start('Applying…');
    const env = await client.call(spec.method, spec.route, { body: { ...base, dry_run: false, confirm_token: token }, ...long });
    ctx.spinner.stop();
    const view = appliedView(env);
    view.title = 'APPLIED';
    return { envelope: env, view };
  };

  if (ctx.universal.write && ctx.universal.token) return apply(ctx.universal.token);

  if (spec.longRunning) ctx.spinner.start('Planning…');
  const plan = await client.call(spec.method, spec.route, { body: { ...base, dry_run: true }, ...long });
  ctx.spinner.stop();
  const view = planView(plan);
  const { token, expires } = tokenOf(plan);
  view.title = 'DRY RUN — nothing was changed';
  const tokenNotes = token
    ? [`confirm token  ${token}${expires ? `   expires ${expires}` : ''}`, `apply with:    ${applyCommand(token)}`]
    : ['(the server returned no confirm token — nothing to apply)'];
  view.notes = [...(view.notes ?? []), ...tokenNotes];

  if (!ctx.universal.write) return { envelope: plan, view };

  if (!token) return { envelope: plan, view, exit: EXIT.USAGE };
  if (ctx.stdinTTY && process.stderr.isTTY) {
    process.stderr.write(`${view.title}\n${renderTable(view)}\n${(view.notes ?? []).join('\n')}\n`);
    if (!(await ask('Apply these changes? [y/N] '))) {
      ctx.note('not applied.');
      return { envelope: plan, view, exit: EXIT.OK };
    }
    return apply(token);
  }
  warn(`ffx: refused: --write without --token when not on a terminal. Capture the token from the plan and pass it:\n  fix: ${applyCommand(token)}`);
  return { envelope: plan, view, exit: EXIT.USAGE };
}
