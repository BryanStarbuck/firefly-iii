/**
 * The write verbs — pm/cli.mdx §11.
 *
 * Every one is a dry run unless --write, and a real write carries the confirm
 * token from that dry run (shared.writeFlow). The server's write tier is a
 * separate switch; with it off, the server refuses and says how to enable it.
 *
 * There is no delete verb: deleting records is admin-tier on the server and a
 * human act in the Firefly UI.
 */
import fs from 'node:fs';

import type { Envelope } from '../client.js';
import { iso } from '../dates.js';
import { CliError, EXIT } from '../errors.js';
import type { Outcome, Row, View } from '../render.js';
import { getPath, inferColumns, objectView, warn } from '../render.js';
import type { Ctx, FlagDef, VerbDef } from '../verbs.js';
import { TRANSACTION_COLUMNS, TRANSACTION_FILTER_FLAGS, flattenGroups, transactionFilterQuery } from './ledger.js';
import { applyCommand, C, changeNotes, isNumericId, monthOf, RANGE_FLAGS, rangeOf, ref, refs, rowsOf, seg, writeFlow } from './shared.js';

const G = 'Writing (dry run unless --write)';

// --------------------------------------------------------------- helpers ---

function required(ctx: Ctx, name: string, why = ''): string {
  const v = ctx.str(name);
  if (v === undefined) throw new CliError(EXIT.USAGE, `--${name} is required${why ? ` — ${why}` : ''}`, { hint: `ffx help ${ctx.verb.path.join(' ')}` });
  return v;
}

/** journal ids from --ids, or the transactions-list filter shape — never both, never neither. */
function selection(ctx: Ctx): Record<string, unknown> {
  const ids = ctx.list('ids');
  const filter = transactionFilterQuery(ctx);
  const hasFilter = Object.values(filter).some((v) => v !== undefined);
  if (ids.length && hasFilter) throw new CliError(EXIT.USAGE, 'give either --ids or filter flags, not both');
  if (!ids.length && !hasFilter) {
    throw new CliError(EXIT.USAGE, 'which transactions? give --ids, or the same filter flags "ffx transactions list" takes', {
      hint: 'check the selection first: ffx transactions list <filters>',
    });
  }
  if (ids.some((i) => !isNumericId(i))) throw new CliError(EXIT.USAGE, '--ids takes journal ids (numbers)');
  if (ids.length) return { journal_ids: ids };
  const clean = Object.fromEntries(Object.entries(filter).filter(([, v]) => v !== undefined));
  return { filter: clean };
}

const SELECTION_FLAGS: Record<string, FlagDef> = {
  ids: { value: 'string', repeat: true, help: 'journal ids (comma-separated or repeated)' },
  ...(TRANSACTION_FILTER_FLAGS as Record<string, FlagDef>),
};

function readJsonFile(path: string, what: string): unknown {
  let text: string;
  try {
    text = fs.readFileSync(path, 'utf8');
  } catch (err) {
    throw new CliError(EXIT.USAGE, `cannot read ${what} ${path}: ${(err as Error).message}`);
  }
  try {
    return JSON.parse(text);
  } catch (err) {
    throw new CliError(EXIT.USAGE, `${what} ${path} is not valid JSON: ${(err as Error).message}`);
  }
}

/** A plan view that shows affected transactions when the server lists them. */
function transactionChangeView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const affected = rowsOf(data, 'transactions', 'affected', 'journals');
  const notes = changeNotes(data);
  if (affected.length) {
    const rows = flattenGroups(affected);
    const extra = rows.some((r) => r.new_category_name !== undefined || r.new_budget_name !== undefined)
      ? [C.text('new_category_name', '→ category'), C.text('new_budget_name', '→ budget')]
      : [];
    return { rows, columns: [...TRANSACTION_COLUMNS, ...extra], notes };
  }
  const changes = data.changes as Record<string, unknown> | undefined;
  const view = objectView(changes ?? data);
  view.notes = notes;
  return view;
}

/**
 * Plan with one route, apply with another (reconcile, undo): the plan returns
 * the token; the apply verb run carries it back.
 */
async function planThenApply(
  ctx: Ctx,
  plan: { method: string; route: string; body?: Record<string, unknown>; view: (env: Envelope) => View },
  apply: { route: string; body?: Record<string, unknown>; noDryRun?: boolean },
): Promise<Outcome> {
  const client = await ctx.plane();
  const token = ctx.universal.token;
  if (token) {
    if (!ctx.universal.write && apply.noDryRun) {
      throw new CliError(EXIT.USAGE, 'this operation has no dry run — its plan is the preview you already read', { hint: applyCommand(token) });
    }
    if (ctx.universal.write) ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
    const body = { ...(apply.body ?? {}), confirm_token: token, ...(apply.noDryRun ? {} : { dry_run: !ctx.universal.write }) };
    const env = await client.call('POST', apply.route, { body });
    const view = plan.view(env);
    view.title = ctx.universal.write ? 'APPLIED' : 'DRY RUN — nothing was changed';
    return { envelope: env, view };
  }
  const env = await client.call(plan.method, plan.route, plan.body ? { body: plan.body } : {});
  const view = plan.view(env);
  const t = getPath(env.data, 'confirm_token');
  view.title = 'PLAN — nothing was changed';
  view.notes = [
    ...(view.notes ?? []),
    ...(typeof t === 'string' ? [`confirm token  ${t}`, `apply with:    ${applyCommand(t)}`] : ['(no confirm token — nothing to apply)']),
  ];
  if (ctx.universal.write) warn('ffx: refused: --write needs --token — read the plan, then run the apply command it prints');
  return { envelope: env, view, exit: ctx.universal.write ? EXIT.USAGE : EXIT.OK };
}

// ---------------------------------------------------------- transactions ---

const transactionsAdd: VerbDef = {
  path: ['transactions', 'add'],
  group: G,
  summary: 'one withdrawal, deposit or transfer; rules run as they would for a hand-entered row',
  route: 'POST /transactions',
  writes: true,
  flags: {
    type: { value: 'choice', choices: ['withdrawal', 'deposit', 'transfer'], help: 'the direction — amounts are always positive' },
    amount: { value: 'amount', help: 'a positive decimal string, e.g. "12.50"' },
    from: { value: 'string', help: 'source account id or name (for a deposit: who paid you)' },
    to: { value: 'string', help: 'destination account id or name (for a withdrawal: the payee)' },
    description: { value: 'string', help: 'what it was (required by Firefly)' },
    date: { value: 'date', help: 'default today' },
    currency: { value: 'string', help: 'currency code (default: the source account\'s)' },
    category: { value: 'string', help: 'category id or name' },
    budget: { value: 'string', help: 'budget id or name (withdrawals)' },
    tag: { value: 'string', repeat: true, help: 'tags' },
    notes: { value: 'string', help: 'your note' },
    rules: { help: 'run Firefly\'s rules on it (default on; --no-rules to skip)' },
    'idempotency-key': { value: 'string', help: 'a retry with the same key returns the original result instead of adding twice' },
  },
  examples: ['ffx transactions add --type withdrawal --amount "12.50" --from Checking --to "Blue Bottle" --description Coffee'],
  async run(ctx) {
    const type = required(ctx, 'type');
    const amount = required(ctx, 'amount');
    const description = required(ctx, 'description');
    const from = ctx.str('from');
    const to = ctx.str('to');
    if (!from || !to) throw new CliError(EXIT.USAGE, 'a transaction needs --from and --to (double-entry: money moves from one account to another)');
    const split: Record<string, unknown> = {
      type,
      date: ctx.str('date') ?? iso(new Date()),
      amount,
      description,
      currency_code: ctx.str('currency'),
      ...ref(from, 'source'),
      ...ref(to, 'destination'),
      ...ref(ctx.str('category'), 'category'),
      ...ref(ctx.str('budget'), 'budget'),
      tags: ctx.list('tag').length ? ctx.list('tag') : undefined,
      notes: ctx.str('notes'),
    };
    return writeFlow(ctx, {
      method: 'POST',
      route: '/transactions',
      body: {
        transactions: [split],
        apply_rules: ctx.flags.rules === false ? false : true,
        idempotency_key: ctx.str('idempotency-key'),
      },
      planView: transactionChangeView,
    });
  },
};

const transactionsUpdate: VerbDef = {
  path: ['transactions', 'update'],
  group: G,
  summary: 'change one transaction group\'s fields (single-split groups; re-split in the UI or via the MCP)',
  route: 'PUT /transactions/{group_id}',
  writes: true,
  positionals: [{ name: 'group-id', required: true, help: 'the transaction group id' }],
  flags: {
    description: { value: 'string', help: 'new description' },
    date: { value: 'date', help: 'new date' },
    amount: { value: 'amount', help: 'new amount, a positive decimal string' },
    category: { value: 'string', help: 'category id or name' },
    budget: { value: 'string', help: 'budget id or name' },
    tag: { value: 'string', repeat: true, help: 'replace the tags with these' },
    notes: { value: 'string', help: 'replace your note' },
    rules: { help: 're-run rules after the edit (default off)' },
  },
  async run(ctx) {
    const id = ctx.positionals[0] as string;
    if (!isNumericId(id)) throw new CliError(EXIT.USAGE, 'a transaction group id is a number');
    const split: Record<string, unknown> = {
      description: ctx.str('description'),
      date: ctx.str('date'),
      amount: ctx.str('amount'),
      ...ref(ctx.str('category'), 'category'),
      ...ref(ctx.str('budget'), 'budget'),
      tags: ctx.list('tag').length ? ctx.list('tag') : undefined,
      notes: ctx.str('notes'),
    };
    if (Object.values(split).every((v) => v === undefined)) throw new CliError(EXIT.USAGE, 'nothing to change — give at least one field flag');
    return writeFlow(ctx, {
      method: 'PUT',
      route: `/transactions/${id}`,
      body: { transactions: [split], apply_rules: ctx.bool('rules') || undefined },
      planView: transactionChangeView,
    });
  },
};

const transactionsCategorize: VerbDef = {
  path: ['transactions', 'categorize'],
  group: G,
  summary: 'set a category on named journals, or on everything a filter selects — under a ceiling',
  route: 'POST /transactions/categorize',
  writes: true,
  flags: { to: { value: 'string', help: 'the category id or name to set (required)' }, ...SELECTION_FLAGS },
  examples: ['ffx transactions categorize --ids 812,813 --to Groceries', 'ffx transactions categorize --search "WHOLE FOODS" --without-category --to Groceries'],
  async run(ctx) {
    const category = required(ctx, 'to', 'the category to set');
    return writeFlow(ctx, { method: 'POST', route: '/transactions/categorize', body: { ...selection(ctx), ...ref(category, 'category') }, planView: transactionChangeView });
  },
};

const transactionsSetBudget: VerbDef = {
  path: ['transactions', 'set-budget'],
  group: G,
  summary: 'set a budget (or --none) on named journals or a filter',
  route: 'POST /transactions/set-budget',
  writes: true,
  flags: { to: { value: 'string', help: 'the budget id or name' }, none: { help: 'clear the budget instead' }, ...SELECTION_FLAGS },
  async run(ctx) {
    const budget = ctx.str('to');
    if (!budget && !ctx.bool('none')) throw new CliError(EXIT.USAGE, 'give --to <budget> or --none');
    if (budget && ctx.bool('none')) throw new CliError(EXIT.USAGE, '--to and --none contradict each other');
    const target = budget ? ref(budget, 'budget') : { budget_id: null };
    return writeFlow(ctx, { method: 'POST', route: '/transactions/set-budget', body: { ...selection(ctx), ...target }, planView: transactionChangeView });
  },
};

const transactionsConvert: VerbDef = {
  path: ['transactions', 'convert'],
  group: G,
  summary: 'convert a transaction: withdrawal ↔ transfer ↔ deposit (the UI\'s "convert")',
  route: 'POST /transactions/{group_id}/convert',
  writes: true,
  positionals: [{ name: 'group-id', required: true, help: 'the transaction group id' }],
  flags: {
    to: { value: 'choice', choices: ['withdrawal', 'deposit', 'transfer'], help: 'the new type (required)' },
    source: { value: 'string', help: 'new source account id or name, when the type change needs one' },
    destination: { value: 'string', help: 'new destination account id or name' },
  },
  async run(ctx) {
    const id = ctx.positionals[0] as string;
    if (!isNumericId(id)) throw new CliError(EXIT.USAGE, 'a transaction group id is a number');
    const toType = required(ctx, 'to');
    return writeFlow(ctx, {
      method: 'POST',
      route: `/transactions/${id}/convert`,
      body: { to_type: toType, ...ref(ctx.str('source'), 'source'), ...ref(ctx.str('destination'), 'destination') },
      planView: transactionChangeView,
    });
  },
};

// ---------------------------------------------------------------- budget ---

function limitView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const rows = rowsOf(data, 'limits', 'budgets');
  const notes = changeNotes(data);
  const unbudgeted = data.left_unbudgeted ?? data.budgets_without_limit;
  if (unbudgeted !== undefined) notes.push(`${Array.isArray(unbudgeted) ? unbudgeted.length : String(unbudgeted)} budget(s) would be left with NO limit (not a limit of zero)`);
  if (!rows.length) {
    const v = objectView((data.changes as Record<string, unknown> | undefined) ?? data);
    v.notes = notes;
    return v;
  }
  const preferred = [C.text('name', 'budget'), C.date('start'), C.date('end'), C.text('currency_code', 'cur'), C.amt('previous', 'was'), C.amt('amount', 'limit')];
  const first = rows[0] ?? {};
  const columns = preferred.filter((c) => getPath(first, c.key) !== undefined).length >= 3 ? preferred : inferColumns(rows);
  return { rows, columns, notes };
}

function periodBody(ctx: Ctx): { start: string; end: string } {
  const month = ctx.str('month');
  if (month || (!ctx.str('start') && !ctx.str('end'))) {
    const m = monthOf(ctx, 'this-month') as string;
    const saved = ctx.flags.month;
    ctx.flags.month = m;
    const r = rangeOf(ctx, 'none');
    if (saved === undefined) delete ctx.flags.month;
    else ctx.flags.month = saved;
    return { start: r.start as string, end: r.end as string };
  }
  const r = rangeOf(ctx, 'none');
  if (!r.start || !r.end) throw new CliError(EXIT.USAGE, 'a budget period needs both --start and --end (or --month)');
  return { start: r.start, end: r.end };
}

const budgetSet: VerbDef = {
  path: ['budget', 'set'],
  group: G,
  summary: 'SET one budget\'s limit for one period (creates or replaces it)',
  route: 'PUT /budgets/{id}/limits',
  writes: true,
  positionals: [{ name: 'budget', required: true, help: 'budget id or name' }],
  flags: { amount: { value: 'amount', help: 'the limit, a decimal string ("0.00" is a real limit of zero)' }, currency: { value: 'string', help: 'default: the primary currency' }, ...RANGE_FLAGS },
  examples: ['ffx budget set Groceries --amount "650.00" --month 2026-10'],
  async run(ctx) {
    const amount = required(ctx, 'amount');
    return writeFlow(ctx, {
      method: 'PUT',
      route: `/budgets/${seg(ctx.positionals[0] as string)}/limits`,
      body: { ...periodBody(ctx), amount, currency_code: ctx.str('currency') },
      planView: limitView,
    });
  },
};

const budgetSetAvailable: VerbDef = {
  path: ['budget', 'set-available'],
  group: G,
  summary: 'set the income-to-budget (available budget) for a period',
  route: 'PUT /available-budgets',
  writes: true,
  flags: { amount: { value: 'amount', help: 'a decimal string' }, currency: { value: 'string', help: 'default: the primary currency' }, ...RANGE_FLAGS },
  async run(ctx) {
    const amount = required(ctx, 'amount');
    return writeFlow(ctx, { method: 'PUT', route: '/available-budgets', body: { ...periodBody(ctx), amount, currency_code: ctx.str('currency') }, planView: limitView });
  },
};

const BUDGET_SCOPE: Record<string, FlagDef> = { budget: { value: 'string', repeat: true, help: 'only these budgets (id or name)' }, ...RANGE_FLAGS };

const budgetCopyPrevious: VerbDef = {
  path: ['budget', 'copy-previous'],
  group: G,
  summary: 'copy every limit from the previous period of the same length into this one',
  route: 'POST /budget-period/copy-previous',
  writes: true,
  flags: BUDGET_SCOPE,
  async run(ctx) {
    return writeFlow(ctx, { method: 'POST', route: '/budget-period/copy-previous', body: { ...periodBody(ctx), ...refs(ctx.list('budget'), 'budget') }, planView: limitView });
  },
};

const budgetSetAverage: VerbDef = {
  path: ['budget', 'set-average'],
  group: G,
  summary: 'set each limit to the average SPENT over the previous N periods (the server computes it)',
  route: 'POST /budget-period/set-average',
  writes: true,
  flags: { periods: { value: 'int', help: 'how many previous periods (default 6)' }, ...BUDGET_SCOPE },
  examples: ['ffx budget set-average --month 2026-10 --periods 6'],
  async run(ctx) {
    return writeFlow(ctx, {
      method: 'POST',
      route: '/budget-period/set-average',
      body: { ...periodBody(ctx), periods: parseInt(ctx.str('periods') ?? '6', 10), ...refs(ctx.list('budget'), 'budget') },
      planView: limitView,
    });
  },
};

// ----------------------------------------------------------------- rules ---

function ruleArg(ctx: Ctx): Record<string, unknown> {
  const file = ctx.str('rule-file');
  const id = ctx.str('id');
  if (file && id) throw new CliError(EXIT.USAGE, 'give --rule-file or --id, not both');
  if (file) return { rule: readJsonFile(file, 'rule file') };
  if (id) return { rule_id: id };
  throw new CliError(EXIT.USAGE, 'give --rule-file <json> (an unsaved rule) or --id <rule id>');
}

function matchesView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const rows = flattenGroups(rowsOf(data, 'matches', 'transactions'));
  const notes = changeNotes(data);
  if (data.match_count !== undefined) notes.unshift(`matches ${String(data.match_count)} transaction(s)${data.truncated_sample ? ' (sample shown)' : ''}`);
  const actionCols = rows.some((r) => r.would_change !== undefined) ? [C.text('would_change', 'would change')] : [];
  return { rows, columns: [...TRANSACTION_COLUMNS.slice(0, 7), ...actionCols], notes };
}

const rulesPreview: VerbDef = {
  path: ['rules', 'preview'],
  group: G,
  summary: 'which existing transactions a rule would match, and what it would change — reads only',
  route: 'POST /rules/preview',
  flags: {
    'rule-file': { value: 'path', help: 'a JSON file with an UNSAVED rule (Firefly\'s rule shape)' },
    id: { value: 'string', help: 'an existing rule id' },
    ...RANGE_FLAGS,
    account: { value: 'string', repeat: true, help: 'only these accounts' },
    limit: { value: 'int', help: 'how many matches to list' },
  },
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const client = await ctx.plane();
    const env = await client.call('POST', '/rules/preview', { body: { ...ruleArg(ctx), start: r.start, end: r.end, ...refs(ctx.list('account'), 'account'), limit: ctx.str('limit') ? parseInt(ctx.str('limit') as string, 10) : undefined } });
    const view = matchesView(env);
    view.title = 'PREVIEW — the rule was not saved or run';
    return { envelope: env, view };
  },
};

const rulesAdd: VerbDef = {
  path: ['rules', 'add'],
  group: G,
  summary: 'save a new rule from a JSON file — run "ffx rules preview --rule-file" first',
  route: 'POST /rules',
  writes: true,
  flags: { 'rule-file': { value: 'path', help: 'the rule, as JSON (required)' } },
  async run(ctx) {
    const file = required(ctx, 'rule-file');
    return writeFlow(ctx, { method: 'POST', route: '/rules', body: { rule: readJsonFile(file, 'rule file') } });
  },
};

const rulesRun: VerbDef = {
  path: ['rules', 'run'],
  group: G,
  summary: 'run rules (or a rule group) over existing transactions — re-categorises history, under a ceiling',
  route: 'POST /rules/run',
  writes: true,
  flags: {
    id: { value: 'string', repeat: true, help: 'rule ids' },
    group: { value: 'string', help: 'a rule group id or name' },
    ...RANGE_FLAGS,
    account: { value: 'string', repeat: true, help: 'only these accounts' },
  },
  async run(ctx) {
    const ids = ctx.list('id');
    const group = ctx.str('group');
    if (!ids.length && !group) throw new CliError(EXIT.USAGE, 'give --id <rule> or --group <rule group>');
    const r = rangeOf(ctx, 'none');
    return writeFlow(ctx, {
      method: 'POST',
      route: '/rules/run',
      body: { ...(ids.length ? { rule_ids: ids } : {}), ...ref(group, 'rule_group'), start: r.start, end: r.end, ...refs(ctx.list('account'), 'account') },
      planView: matchesView,
    });
  },
};

// ------------------------------------------------------------- reconcile ---

function reconcileView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const fields: Row[] = ['start_balance', 'end_balance', 'selected_sum', 'target_balance', 'difference']
    .filter((k) => data[k] !== undefined)
    .map((k) => ({ figure: k.replace(/_/g, ' '), amount: data[k], currency_code: data.currency_code }));
  const notes = changeNotes(data);
  if (data.uncleared_count !== undefined) notes.push(`${String(data.uncleared_count)} uncleared transaction(s) in range`);
  const diff = data.difference;
  if (typeof diff === 'string' && !/^-?0+(\.0+)?$/.test(diff)) {
    notes.push('the difference is NOT zero — hunt it first (missing row? duplicate? wrong date?). Applying now creates one visible reconciliation transaction for it.');
  }
  return { rows: fields, columns: [C.text('figure'), C.amt('amount')], notes };
}

const reconcile: VerbDef = {
  path: ['reconcile'],
  group: G,
  summary: 'reconcile an account to a statement balance: plan (the difference), then --token --write',
  route: 'POST /accounts/{id}/reconcile/plan, POST /accounts/{id}/reconcile/apply',
  writes: true,
  positionals: [{ name: 'account', required: true, help: 'asset account id or name' }],
  flags: {
    balance: { value: 'amount', help: 'the statement\'s closing balance, a decimal string (required for the plan)' },
    negative: { help: 'the statement balance is negative (an overdrawn account or a card)' },
    ...RANGE_FLAGS,
    journal: { value: 'string', repeat: true, help: 'only these journal ids (default: every uncleared row in range)' },
    'no-adjustment': { help: 'do not create a reconciliation transaction for a non-zero difference' },
  },
  examples: ['ffx reconcile Checking --balance "4211.08" --end 2026-08-31', 'ffx reconcile Checking --token cf_01J8… --write'],
  async run(ctx) {
    const account = seg(ctx.positionals[0] as string);
    if (!ctx.universal.token && !ctx.str('balance')) throw new CliError(EXIT.USAGE, '--balance is required for the plan');
    const r = rangeOf(ctx, 'none');
    const balance = ctx.str('balance');
    const target = balance === undefined ? undefined : ctx.bool('negative') ? `-${balance}` : balance;
    const journals = ctx.list('journal');
    return planThenApply(
      ctx,
      {
        method: 'POST',
        route: `/accounts/${account}/reconcile/plan`,
        body: { start: r.start, end: r.end, target_balance: target, journal_ids: journals.length ? journals : undefined },
        view: reconcileView,
      },
      { route: `/accounts/${account}/reconcile/apply`, body: { create_reconciliation: !ctx.bool('no-adjustment') } },
    );
  },
};

// ------------------------------------------------- piggy banks, recurring ---

function piggyMove(direction: 'add' | 'remove'): VerbDef {
  return {
    path: ['piggy-banks', direction],
    group: G,
    summary: direction === 'add' ? 'put money into a savings goal' : 'take money out of a savings goal',
    route: `POST /piggy-banks/{id}/${direction}`,
    writes: true,
    positionals: [{ name: 'piggy', required: true, help: 'piggy bank id or name' }],
    flags: { amount: { value: 'amount', help: 'a positive decimal string (required)' }, account: { value: 'string', help: 'which linked account (when the piggy bank has several)' } },
    async run(ctx) {
      const amount = required(ctx, 'amount');
      return writeFlow(ctx, { method: 'POST', route: `/piggy-banks/${seg(ctx.positionals[0] as string)}/${direction}`, body: { amount, ...ref(ctx.str('account'), 'account') } });
    },
  };
}

const recurringTrigger: VerbDef = {
  path: ['recurring', 'trigger'],
  group: G,
  summary: 'create a recurring transaction\'s occurrence now (no dry run exists — without --write it shows the next date)',
  route: 'POST /recurrences/{id}/trigger',
  writes: true,
  positionals: [{ name: 'id', required: true, help: 'recurrence id' }],
  flags: { date: { value: 'date', help: 'the occurrence date (default: the next one)' } },
  async run(ctx) {
    const id = seg(ctx.positionals[0] as string);
    const client = await ctx.plane();
    if (!ctx.universal.write) {
      const env = await client.call('GET', `/recurrences/${id}`, { query: { count: 3 } });
      const view = objectView(((getPath(env.data, 'recurrence') ?? env.data) as Record<string, unknown>) ?? {});
      view.title = 'PREVIEW — nothing was created';
      view.notes = [`this operation has no dry run; to create the occurrence: ffx ${process.argv.slice(2).join(' ')} --write`];
      return { envelope: env, view };
    }
    ctx.note(`writing to ${ctx.cfg.apiUrl} (key ${client.fingerprint})`);
    const env = await client.call('POST', `/recurrences/${id}/trigger`, { body: { date: ctx.str('date') } });
    return { envelope: env, view: { ...transactionChangeView(env), title: 'APPLIED' } };
  },
};

// ------------------------------------------------------------------ undo ---

function undoView(env: Envelope): View {
  const data = (env.data ?? {}) as Record<string, unknown>;
  const rows: Row[] = rowsOf(data, 'effects', 'rows');
  const notes: string[] = [];
  if (data.operation_id !== undefined) notes.push(`operation #${String(data.operation_id)} · ${String(data.route ?? '')} · ${String(data.at ?? '')}`);
  if (typeof data.description === 'string') notes.push(data.description);
  if (Array.isArray(data.blocked_by) && data.blocked_by.length) {
    notes.push(`BLOCKED: ${data.blocked_by.length} touched row(s) were edited in the browser since — undo would destroy a human's change, so it refuses`);
  }
  notes.push('undo reaches only writes made through ffx or the MCP, never changes made in the Firefly UI');
  const columns = rows.length ? inferColumns(rows) : [];
  return rows.length ? { rows, columns, notes } : { ...objectView(data), notes };
}

const undo: VerbDef = {
  path: ['undo'],
  group: G,
  summary: 'reverse the most recent write made through the plane — preview first, then --token --write',
  route: 'GET /undo/last, POST /undo',
  writes: true,
  async run(ctx) {
    return planThenApply(ctx, { method: 'GET', route: '/undo/last', view: undoView }, { route: '/undo', noDryRun: true });
  },
};

export const writeVerbs: VerbDef[] = [
  transactionsAdd,
  transactionsUpdate,
  transactionsCategorize,
  transactionsSetBudget,
  transactionsConvert,
  budgetSet,
  budgetSetAvailable,
  budgetCopyPrevious,
  budgetSetAverage,
  rulesPreview,
  rulesAdd,
  rulesRun,
  reconcile,
  piggyMove('add'),
  piggyMove('remove'),
  recurringTrigger,
  undo,
];
