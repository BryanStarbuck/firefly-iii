/**
 * Analytics, charts and reports — pm/cli.mdx §9.3, apis.mdx §10.
 *
 * Every total comes from the server, per currency, with its provenance. The
 * CLI never adds two amounts. Charts are chart-ready series from the server;
 * in a table each series gets a sparkline whose glyph heights come from the
 * RANK ORDER of its values (compared as strings) — never from arithmetic.
 */
import type { Envelope, Query } from '../client.js';
import { CliError, EXIT } from '../errors.js';
import { compareDecimal, isDecimalString } from '../money.js';
import type { Column, Outcome, Row } from '../render.js';
import { getPath, inferColumns } from '../render.js';
import type { Ctx, VerbDef } from '../verbs.js';
import {
  ACCOUNT_FILTER_FLAGS,
  C,
  get,
  INTERVAL_FLAG,
  provenanceNotes,
  RANGE_FLAGS,
  rangeOf,
  refs,
  rowsOf,
  seg,
  TRANSFERS_FLAG,
} from './shared.js';

const G = 'Analytics, charts and reports';

const COMMON_FLAGS = {
  ...RANGE_FLAGS,
  ...ACCOUNT_FILTER_FLAGS,
  currency: { value: 'string', help: 'only this currency' },
  ...TRANSFERS_FLAG,
} as const;

/** The query every analytics route shares (apis.mdx §10.2). */
function commonQuery(ctx: Ctx, fallback: 'this-month' | 'last-month' | 'ytd' = 'last-month'): Query {
  const r = rangeOf(ctx, fallback);
  return {
    start: r.start,
    end: r.end,
    ...refs(ctx.list('account'), 'account'),
    currency_code: ctx.str('currency'),
    include_transfers: ctx.bool('include-transfers') || undefined,
  };
}

/** Use the preferred columns when the rows carry them; otherwise infer from the rows. */
function smartOutcome(env: Envelope, preferred: Column[], keys: string[], extraNotes: string[] = []): Outcome {
  const rows = rowsOf(env.data, ...keys);
  const first = rows[0] ?? {};
  const hits = preferred.filter((c) => getPath(first, c.key) !== undefined).length;
  const columns = rows.length && hits >= Math.ceil(preferred.length / 2) ? preferred : inferColumns(rows);
  const notes = [...extraNotes, ...provenanceNotes(env.data)];
  const excluded = getPath(env.data, 'excluded');
  if (Array.isArray(excluded) && excluded.length) notes.push(`excluded: ${excluded.join(', ')}`);
  return { envelope: env, view: { rows, columns, notes } };
}

// ------------------------------------------------------------- sparkline ---

const BARS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

/** Glyph heights from rank order. null → a gap, never a zero. */
export function sparkline(values: readonly unknown[]): string {
  const nums = values.filter((v): v is string => isDecimalString(v));
  // Distinct by VALUE, not by spelling: "612.40" and "612.4" are one amount and get one height.
  const distinct: string[] = [];
  for (const n of [...nums].sort(compareDecimal)) {
    const last = distinct[distinct.length - 1];
    if (last === undefined || compareDecimal(last, n) !== 0) distinct.push(n);
  }
  const top = BARS.length - 1;
  return values
    .map((v) => {
      if (!isDecimalString(v)) return ' ';
      if (distinct.length <= 1) return BARS[3];
      const rank = distinct.findIndex((d) => compareDecimal(d, v) === 0);
      return BARS[Math.round((rank * top) / (distinct.length - 1))];
    })
    .join('');
}

// ----------------------------------------------------------------- verbs ---

const spending: VerbDef = {
  path: ['spending'],
  group: G,
  summary: 'what was spent, by category (or budget, or tag), per interval — including the "none" bucket',
  route: 'GET /analytics/spending-by-{category|budget|tag}',
  flags: {
    by: { value: 'choice', choices: ['category', 'budget', 'tag'], help: 'what to group by (default category)' },
    top: { value: 'int', help: 'only the N largest' },
    ...INTERVAL_FLAG,
    ...COMMON_FLAGS,
  },
  examples: ['ffx spending --month last-month', 'ffx spending --by budget --start ytd --interval month'],
  async run(ctx) {
    const by = ctx.str('by') ?? 'category';
    const env = await get(ctx, `/analytics/spending-by-${by}`, { ...commonQuery(ctx), interval: ctx.str('interval'), top_n: ctx.str('top') });
    return smartOutcome(env, [C.text('period'), C.text('name', by), C.text('currency_code', 'cur'), C.amt('spent'), C.text('count', 'transactions')], ['rows', 'spending', 'items']);
  },
};

const payees: VerbDef = {
  path: ['payees'],
  group: G,
  summary: 'who got the money (expense accounts), or who paid you (--direction in)',
  route: 'GET /analytics/payee-leaderboard',
  flags: { top: { value: 'int', help: 'how many (default 20)' }, direction: { value: 'choice', choices: ['out', 'in'], help: 'out (default) or in' }, ...COMMON_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/analytics/payee-leaderboard', { ...commonQuery(ctx), top_n: ctx.str('top'), direction: ctx.str('direction') });
    return smartOutcome(env, [C.text('name', 'payee'), C.text('currency_code', 'cur'), C.amt('amount'), C.text('count', 'transactions')], ['rows', 'payees', 'items']);
  },
};

function periodFlow(path: string[], route: string, summary: string, preferred: Column[]): VerbDef {
  return {
    path,
    group: G,
    summary,
    route: `GET ${route}`,
    flags: { ...INTERVAL_FLAG, ...COMMON_FLAGS },
    async run(ctx) {
      const env = await get(ctx, route, { ...commonQuery(ctx, 'ytd'), interval: ctx.str('interval') });
      return smartOutcome(env, preferred, ['rows', 'periods', 'items']);
    },
  };
}

const incomeVsExpense = periodFlow(['income-vs-expense'], '/analytics/income-vs-expense', 'in, out and net per interval', [
  C.text('period'),
  C.text('currency_code', 'cur'),
  C.amt('income', 'in'),
  C.amt('expense', 'out'),
  C.amt('net'),
]);

const cashFlow = periodFlow(['cash-flow'], '/analytics/cash-flow', 'opening, in, out and closing per interval, over asset accounts', [
  C.text('period'),
  C.text('currency_code', 'cur'),
  C.amt('opening'),
  C.amt('in'),
  C.amt('out'),
  C.amt('closing'),
]);

const netWorth = periodFlow(['net-worth'], '/analytics/net-worth', 'assets, liabilities and net worth per interval', [
  C.text('period'),
  C.text('currency_code', 'cur'),
  C.amt('assets'),
  C.amt('liabilities'),
  C.amt('net'),
]);

const trend: VerbDef = {
  path: ['trend'],
  group: G,
  summary: 'one category over time, with the server\'s mean and median',
  route: 'GET /analytics/category-trend',
  positionals: [{ name: 'category', required: true, help: 'category id or name' }],
  flags: { ...INTERVAL_FLAG, ...COMMON_FLAGS },
  async run(ctx) {
    const cat = ctx.positionals[0] as string;
    const env = await get(ctx, '/analytics/category-trend', {
      ...commonQuery(ctx, 'ytd'),
      interval: ctx.str('interval'),
      ...(/^\d+$/.test(cat) ? { category_id: cat } : { category_name: cat }),
    });
    const data = (env.data ?? {}) as Record<string, unknown>;
    const notes: string[] = [];
    for (const s of rowsOf(data, 'stats')) notes.push(`${String(s.currency_code ?? '')}: mean ${String(s.mean ?? '—')} · median ${String(s.median ?? '—')}`);
    if (data.mean !== undefined) notes.push(`mean ${String(data.mean)} · median ${String(data.median ?? '—')}`);
    return smartOutcome(env, [C.text('period'), C.text('currency_code', 'cur'), C.amt('spent')], ['rows', 'periods'], notes);
  },
};

const budgetPerformance: VerbDef = {
  path: ['budget', 'performance'],
  group: G,
  summary: 'limit vs spent vs left per budget per period, and the variance',
  route: 'GET /analytics/budget-performance',
  flags: { budget: { value: 'string', repeat: true, help: 'budget id or name (repeatable)' }, ...COMMON_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/analytics/budget-performance', { ...commonQuery(ctx, 'ytd'), ...refs(ctx.list('budget'), 'budget') });
    return smartOutcome(env, [C.text('period'), C.text('name', 'budget'), C.text('currency_code', 'cur'), C.amt('limit'), C.amt('spent'), C.amt('left'), C.amt('variance')], ['rows', 'budgets']);
  },
};

const runway: VerbDef = {
  path: ['runway'],
  group: G,
  summary: 'liquid assets ÷ trailing average outflow, in months — with the basis stated',
  route: 'GET /analytics/runway',
  flags: { basis: { value: 'choice', choices: ['3', '6', '12'], help: 'trailing months (default 6)' }, currency: { value: 'string', help: 'only this currency' } },
  async run(ctx) {
    const env = await get(ctx, '/analytics/runway', { basis: ctx.str('basis'), currency_code: ctx.str('currency') });
    return smartOutcome(env, [C.text('currency_code', 'cur'), C.amt('liquid'), C.amt('average_outflow', 'avg out / month'), C.text('months', 'runway (months)')], ['rows', 'currencies'], [
      'runway assumes income stops and spending continues at the trailing average — the server states its basis above',
    ]);
  },
};

const recurringDetect: VerbDef = {
  path: ['recurring-detect'],
  group: G,
  summary: 'recurring payments that are not yet a subscription — a detector, with its evidence',
  route: 'GET /analytics/recurring',
  flags: {
    'min-occurrences': { value: 'int', help: 'default 3' },
    'tolerance-days': { value: 'int', help: 'default 3' },
    ...RANGE_FLAGS,
  },
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/analytics/recurring', { start: r.start, end: r.end, min_occurrences: ctx.str('min-occurrences'), tolerance_days: ctx.str('tolerance-days') });
    return smartOutcome(env, [C.text('name', 'payee'), C.text('currency_code', 'cur'), C.amt('typical_amount', 'typical'), C.text('cadence'), C.text('occurrences'), C.amt('annualised'), C.date('last_seen', 'last')], ['items', 'rows'], ['each item is evidence, not a verdict — check it before acting on it']);
  },
};

const anomalies: VerbDef = {
  path: ['anomalies'],
  group: G,
  summary: 'categories and payees unusually far from their own norm — with the deviation',
  route: 'GET /analytics/anomalies',
  flags: { z: { value: 'string', help: 'threshold in standard deviations, a decimal string (default "2.0")' }, min: { value: 'amount', help: 'ignore amounts below this' }, ...COMMON_FLAGS },
  async run(ctx) {
    const z = ctx.str('z');
    if (z !== undefined && !/^\d+(\.\d+)?$/.test(z)) throw new CliError(EXIT.USAGE, `--z takes a decimal like "2.0" (got "${z}")`);
    const env = await get(ctx, '/analytics/anomalies', { ...commonQuery(ctx, 'this-month'), z, min_amount: ctx.str('min') });
    return smartOutcome(env, [C.text('kind'), C.text('name'), C.text('currency_code', 'cur'), C.amt('amount', 'this period'), C.amt('trailing_mean', 'usual'), C.text('deviation', 'z')], ['items', 'rows']);
  },
};

const uncategorized: VerbDef = {
  path: ['uncategorized'],
  group: G,
  summary: 'count and total of uncategorised withdrawals, by account and by month',
  route: 'GET /analytics/uncategorized-summary',
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/analytics/uncategorized-summary', { start: r.start, end: r.end });
    return smartOutcome(env, [C.text('period'), C.text('account_name', 'account'), C.text('currency_code', 'cur'), C.text('count', 'transactions'), C.amt('amount')], ['rows', 'by_month', 'items'], [
      'list them: ffx transactions list --without-category',
    ]);
  },
};

const summary: VerbDef = {
  path: ['summary'],
  group: G,
  summary: 'the dashboard boxes: balance, bills paid/unpaid, left to spend, net worth',
  route: 'GET /analytics/summary',
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'this-month');
    const env = await get(ctx, '/analytics/summary', { start: r.start, end: r.end });
    return smartOutcome(env, [C.text('key', 'box'), C.text('title'), C.text('currency_code', 'cur'), C.amt('value')], ['boxes', 'rows']);
  },
};

const subscriptionsCost: VerbDef = {
  path: ['subscriptions', 'cost'],
  group: G,
  summary: 'per subscription: expected, paid, missed, and annualised cost',
  route: 'GET /analytics/subscriptions',
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'ytd');
    const env = await get(ctx, '/analytics/subscriptions', { start: r.start, end: r.end });
    return smartOutcome(env, [C.text('name'), C.text('currency_code', 'cur'), C.text('paid_count', 'paid'), C.text('missed_count', 'missed'), C.amt('paid_amount', 'paid total'), C.amt('annualised')], ['subscriptions', 'rows']);
  },
};

const piggyProgress: VerbDef = {
  path: ['piggy-banks', 'progress'],
  group: G,
  summary: 'saved vs target vs date, per piggy bank',
  route: 'GET /analytics/piggy-progress',
  async run(ctx) {
    const env = await get(ctx, '/analytics/piggy-progress');
    return smartOutcome(env, [C.text('name'), C.amt('saved'), C.amt('target'), C.amt('left'), C.date('target_date', 'by'), C.text('on_track', 'on track')], ['piggy_banks', 'rows']);
  },
};

// ---------------------------------------------------------------- charts ---

export const CHART_NAMES = [
  'account-balances',
  'net-worth',
  'budget-overview',
  'category-overview',
  'spending-by-category',
  'income-vs-expense',
  'tag-overview',
  'series',
] as const;

/**
 * Pivot chart-ready series (apis.mdx §10.5) into rows: one row per x label,
 * one column per series. A null stays empty — a gap, not a zero.
 */
export function pivotSeries(data: unknown): { rows: Row[]; columns: Column[]; sparks: string[] } {
  const labels = (getPath(data, 'x.labels') as unknown[] | undefined) ?? [];
  const series = (getPath(data, 'series') as Row[] | undefined) ?? [];
  const columns: Column[] = [C.text('x', String(getPath(data, 'x.kind') ?? 'x'))];
  const rows: Row[] = labels.map((l) => ({ x: l }));
  const sparks: string[] = [];
  const used = new Set<string>(['x']);
  series.forEach((s, i) => {
    // The column key is what a CSV header prints, so use the server's own series key when it is a
    // safe, unique identifier; fall back to s0, s1… only when it is not.
    const own = typeof s.key === 'string' && /^[A-Za-z0-9_-]+$/.test(s.key) && !used.has(s.key) ? s.key : undefined;
    const key = own ?? `s${i}`;
    used.add(key);
    const cur = typeof s.currency_code === 'string' ? s.currency_code : '';
    columns.push({ key, header: `${String(s.label ?? s.key ?? key)}${cur ? ` (${cur})` : ''}`, kind: 'amount', currencyKey: '__none__' });
    const values = Array.isArray(s.values) ? s.values : [];
    values.forEach((v, j) => {
      const row = rows[j] ?? (rows[j] = { x: labels[j] ?? j });
      row[key] = v;
    });
    sparks.push(`${String(s.label ?? s.key ?? key)}${cur ? ` (${cur})` : ''}  ${sparkline(values)}`);
  });
  return { rows, columns, sparks };
}

const chart: VerbDef = {
  path: ['chart'],
  group: G,
  summary: 'chart-ready series from the server; the table pivots them and adds a sparkline per series',
  route: 'GET /charts/{name}',
  positionals: [{ name: 'name', required: true, help: CHART_NAMES.join(' | ') + ', or piggy-bank/<id>, subscription/<id>' }],
  flags: {
    ...INTERVAL_FLAG,
    ...COMMON_FLAGS,
    source: { value: 'string', help: 'for "series": the analytics route to turn into series, e.g. /analytics/cash-flow' },
  },
  examples: ['ffx chart net-worth --interval month --start this-year', 'ffx chart series --source /analytics/cash-flow --interval month'],
  async run(ctx) {
    const name = ctx.positionals[0] as string;
    const known = (CHART_NAMES as readonly string[]).includes(name) || /^(piggy-bank|subscription)\/[^/]+$/.test(name);
    if (!known) throw new CliError(EXIT.USAGE, `unknown chart "${name}"`, { hint: `one of: ${CHART_NAMES.join(', ')}, piggy-bank/<id>, subscription/<id>` });
    if (name === 'series' && !ctx.str('source')) throw new CliError(EXIT.USAGE, 'chart series needs --source /analytics/<route>');
    const route = `/charts/${name.split('/').map(seg).join('/')}`;
    const env = await get(ctx, route, { ...commonQuery(ctx, 'ytd'), interval: ctx.str('interval'), source: ctx.str('source') });
    const { rows, columns, sparks } = pivotSeries(env.data);
    return { envelope: env, view: { rows, columns, notes: [...sparks, 'empty cells are periods with no data — gaps, not zeros', ...provenanceNotes(env.data)] } };
  },
};

// --------------------------------------------------------------- reports ---

const REPORT_TYPES = ['default', 'audit', 'budget', 'category', 'tag', 'double'] as const;

const report: VerbDef = {
  path: ['report'],
  group: G,
  summary: 'the numbers behind Firefly\'s reports: default, audit, budget, category, tag, double (expense/revenue)',
  route: 'GET /reports/{type}',
  positionals: [{ name: 'type', required: true, help: REPORT_TYPES.join(' | ') }],
  flags: {
    ...RANGE_FLAGS,
    ...ACCOUNT_FILTER_FLAGS,
    budget: { value: 'string', repeat: true, help: 'budget report: budgets' },
    category: { value: 'string', repeat: true, help: 'category report: categories' },
    tag: { value: 'string', repeat: true, help: 'tag report: tags' },
    counterparty: { value: 'string', repeat: true, help: 'double report: expense/revenue accounts' },
    section: { value: 'string', help: 'show one section of the report as the table (default: the first)' },
  },
  async run(ctx) {
    const type = ctx.positionals[0] as string;
    if (!(REPORT_TYPES as readonly string[]).includes(type)) throw new CliError(EXIT.USAGE, `unknown report "${type}"`, { hint: REPORT_TYPES.join(', ') });
    const r = rangeOf(ctx, 'last-month');
    const env = await get(ctx, `/reports/${type}`, {
      start: r.start,
      end: r.end,
      ...refs(ctx.list('account'), 'account'),
      ...refs(ctx.list('budget'), 'budget'),
      ...refs(ctx.list('category'), 'category'),
      tags: ctx.list('tag').length ? ctx.list('tag') : undefined,
      ...refs(ctx.list('counterparty'), 'counterparty'),
    });
    const data = (env.data ?? {}) as Record<string, unknown>;
    const sections = Object.entries(data).filter(([, v]) => Array.isArray(v)).map(([k]) => k);
    const wanted = ctx.str('section');
    if (wanted && !sections.includes(wanted)) throw new CliError(EXIT.USAGE, `no section "${wanted}" in this report`, { hint: `sections: ${sections.join(', ')}` });
    const key = wanted ?? sections[0];
    const rows = key ? (data[key] as Row[]) : [];
    return {
      envelope: env,
      view: { title: key ? `section: ${key}` : undefined, rows, columns: inferColumns(rows), notes: sections.length > 1 ? [`other sections: ${sections.filter((s) => s !== key).join(', ')} (--section <name>)`] : [] },
    };
  },
};

export const analyticsVerbs: VerbDef[] = [
  summary,
  spending,
  payees,
  incomeVsExpense,
  cashFlow,
  netWorth,
  trend,
  budgetPerformance,
  runway,
  recurringDetect,
  anomalies,
  uncategorized,
  subscriptionsCost,
  piggyProgress,
  chart,
  report,
];
