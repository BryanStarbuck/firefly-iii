/**
 * Reading the ledger — pm/cli.mdx §9. Parse, call one route, render.
 *
 * No verb here filters, sums, sorts or converts anything. Every figure is the
 * server's, and every amount is shown as the server's decimal string.
 */
import type { Envelope, Query } from '../client.js';
import { CliError, EXIT } from '../errors.js';
import type { Outcome, Row, View } from '../render.js';
import { getPath } from '../render.js';
import type { Ctx, VerbDef } from '../verbs.js';
import {
  ACCOUNT_FILTER_FLAGS,
  C,
  get,
  LIST_FLAGS,
  listOutcome,
  listQuery,
  objectOutcome,
  RANGE_FLAGS,
  rangeOf,
  ref,
  refs,
  rowsOf,
  seg,
} from './shared.js';

const G = 'Reading the ledger';

// --------------------------------------------------------- transactions ---

/**
 * A group with several splits becomes several rows, one per journal, each
 * carrying its group id and "n/m" — so a human summing a column by eye can see
 * the split (§9.1). Nothing is merged or totalled.
 */
export function flattenGroups(groups: Row[]): Row[] {
  const rows: Row[] = [];
  for (const g of groups) {
    const splits = Array.isArray(g.transactions) ? (g.transactions as Row[]) : undefined;
    if (!splits) {
      rows.push({ ...g, group_id: g.group_id ?? g.id, split: '' });
      continue;
    }
    splits.forEach((s, i) => {
      rows.push({
        ...s,
        group_id: g.id ?? s.transaction_group_id,
        journal_id: s.transaction_journal_id ?? s.journal_id ?? s.id,
        date: typeof s.date === 'string' ? s.date.slice(0, 10) : s.date,
        split: splits.length > 1 ? `${i + 1}/${splits.length}` : '',
        description: splits.length > 1 && g.group_title ? `${String(g.group_title)} · ${String(s.description ?? '')}` : s.description,
      });
    });
  }
  return rows;
}

export const TRANSACTION_COLUMNS = [
  C.id('group_id', 'group'),
  C.text('split'),
  C.date('date'),
  C.text('type'),
  C.text('description'),
  C.amt('amount'),
  C.text('source_name', 'from'),
  C.text('destination_name', 'to'),
  C.text('category_name', 'category'),
  C.text('budget_name', 'budget'),
];

export function transactionsOutcome(env: Envelope): Outcome {
  const groups = rowsOf(env.data, 'transactions', 'groups');
  const view: View = { rows: flattenGroups(groups), columns: TRANSACTION_COLUMNS };
  const hasSplits = view.rows.some((r) => r.split);
  view.notes = hasSplits ? ['rows marked n/m are splits of one transaction group — they are not separate transactions'] : [];
  return { envelope: env, view };
}

export function transactionFilterQuery(ctx: Ctx): Query {
  const r = rangeOf(ctx, 'none');
  const accounts = refs(ctx.list('account'), 'account');
  return {
    start: r.start,
    end: r.end,
    type: ctx.str('type'),
    ...accounts,
    ...ref(ctx.str('category'), 'category'),
    ...ref(ctx.str('budget'), 'budget'),
    tag: ctx.str('tag'),
    bill_id: ctx.str('subscription'),
    without_category: ctx.bool('without-category') || undefined,
    without_budget: ctx.bool('without-budget') || undefined,
    without_tag: ctx.bool('without-tag') || undefined,
    min_amount: ctx.str('min'),
    max_amount: ctx.str('max'),
    currency_code: ctx.str('currency'),
    reconciled: ctx.flags.reconciled === undefined ? undefined : ctx.bool('reconciled'),
    search: ctx.str('search'),
  };
}

export const TRANSACTION_FILTER_FLAGS = {
  ...RANGE_FLAGS,
  ...ACCOUNT_FILTER_FLAGS,
  type: { value: 'choice', choices: ['withdrawal', 'deposit', 'transfer', 'opening-balance', 'reconciliation'], help: 'transaction type' },
  category: { value: 'string', help: 'category id or name' },
  budget: { value: 'string', help: 'budget id or name' },
  tag: { value: 'string', help: 'a tag' },
  subscription: { value: 'string', help: 'subscription (bill) id' },
  'without-category': { help: 'only transactions with no category — the commonest real question' },
  'without-budget': { help: 'only withdrawals with no budget' },
  'without-tag': { help: 'only transactions with no tag' },
  min: { value: 'amount', help: 'minimum amount, a decimal string like "500"' },
  max: { value: 'amount', help: 'maximum amount' },
  currency: { value: 'string', help: 'currency code, e.g. USD' },
  reconciled: { help: 'only reconciled (use --no-reconciled for only unreconciled)' },
  search: { value: 'string', help: 'free text in the description' },
} as const;

// ------------------------------------------------------------- accounts ---

const ACCOUNT_COLUMNS = [
  C.id('id'),
  C.text('name'),
  C.text('type'),
  C.text('account_role', 'role'),
  C.amt('current_balance', 'balance'),
  C.date('current_balance_date', 'as of'),
  C.bool('active'),
];

const accountsList: VerbDef = {
  path: ['accounts', 'list'],
  group: G,
  summary: 'accounts with balances — asset accounts unless --type says otherwise',
  route: 'GET /accounts',
  flags: {
    type: { value: 'choice', choices: ['asset', 'expense', 'revenue', 'liability', 'cash', 'all'], help: 'account type (default asset)' },
    inactive: { help: 'include deactivated accounts' },
    'as-of': { value: 'date', help: 'balances at the end of this day' },
    search: { value: 'string', help: 'name contains' },
    ...LIST_FLAGS,
  },
  examples: ['ffx accounts list', 'ffx accounts list --type expense --order -current_balance --limit 20'],
  async run(ctx) {
    const env = await get(ctx, '/accounts', {
      type: ctx.str('type') ?? 'asset',
      active: ctx.bool('inactive') ? undefined : true,
      as_of: ctx.str('as-of'),
      search: ctx.str('search'),
      ...listQuery(ctx),
    });
    return listOutcome(env, ACCOUNT_COLUMNS, ['accounts']);
  },
};

const accountArg = [{ name: 'account', required: true, help: 'account id or name' }] as const;

const accountsShow: VerbDef = {
  path: ['accounts', 'show'],
  group: G,
  summary: 'one account',
  route: 'GET /accounts/{id}',
  positionals: accountArg,
  async run(ctx) {
    return objectOutcome(await get(ctx, `/accounts/${seg(ctx.positionals[0] as string)}`), 'account');
  },
};

const accountsBalance: VerbDef = {
  path: ['accounts', 'balance'],
  group: G,
  summary: 'an account\'s balance at the end of a day, per currency — Firefly\'s running balance, never a client sum',
  route: 'GET /accounts/{id}/balance',
  positionals: accountArg,
  flags: { 'as-of': { value: 'date', help: 'end of this day (default today)' } },
  async run(ctx) {
    const env = await get(ctx, `/accounts/${seg(ctx.positionals[0] as string)}/balance`, { as_of: ctx.str('as-of') });
    const rows = rowsOf(env.data, 'balances');
    if (rows.length) {
      return { envelope: env, view: { rows, columns: [C.text('currency_code', 'currency'), C.amt('balance'), C.date('as_of', 'as of')] } };
    }
    return objectOutcome(env);
  },
};

const accountsProperties: VerbDef = {
  path: ['accounts', 'properties'],
  group: G,
  summary: 'counts, first/last transaction, opening balance, liability terms',
  route: 'GET /accounts/{id}/properties',
  positionals: accountArg,
  async run(ctx) {
    return objectOutcome(await get(ctx, `/accounts/${seg(ctx.positionals[0] as string)}/properties`));
  },
};

const accountsTransactions: VerbDef = {
  path: ['accounts', 'transactions'],
  group: G,
  summary: 'one account\'s transactions for a period',
  route: 'GET /accounts/{id}/transactions',
  positionals: accountArg,
  flags: { ...RANGE_FLAGS, ...LIST_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'this-month');
    const env = await get(ctx, `/accounts/${seg(ctx.positionals[0] as string)}/transactions`, { start: r.start, end: r.end, ...listQuery(ctx) });
    return transactionsOutcome(env);
  },
};

// --------------------------------------------------------- transactions ---

const transactionsList: VerbDef = {
  path: ['transactions', 'list'],
  group: G,
  summary: 'transactions, filtered — one row per split, splits marked',
  route: 'GET /transactions',
  flags: { ...TRANSACTION_FILTER_FLAGS, ...LIST_FLAGS },
  examples: [
    'ffx transactions list --without-category --month last-month',
    'ffx transactions list --account Checking --min 500 --start 2026-06-01',
  ],
  async run(ctx) {
    return transactionsOutcome(await get(ctx, '/transactions', { ...transactionFilterQuery(ctx), ...listQuery(ctx) }));
  },
};

const transactionsShow: VerbDef = {
  path: ['transactions', 'show'],
  group: G,
  summary: 'one transaction group with every split',
  route: 'GET /transactions/{group_id}',
  positionals: [{ name: 'group-id', required: true, help: 'the transaction group id' }],
  async run(ctx) {
    const id = ctx.positionals[0] as string;
    if (!/^\d+$/.test(id)) throw new CliError(EXIT.USAGE, 'a transaction group id is a number');
    const env = await get(ctx, `/transactions/${id}`);
    const group = (getPath(env.data, 'transaction') ?? getPath(env.data, 'group') ?? env.data) as Row;
    return { envelope: env, view: { rows: flattenGroups([group]), columns: [...TRANSACTION_COLUMNS, C.text('tags'), C.text('notes'), C.bool('reconciled')] } };
  },
};

const transactionsExport: VerbDef = {
  path: ['transactions', 'export'],
  group: G,
  summary: 'the same filters, as CSV from Firefly\'s own exporter',
  route: 'GET /transactions/export',
  flags: { ...TRANSACTION_FILTER_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/transactions/export', { ...transactionFilterQuery(ctx), format: 'csv' });
    const csv = getPath(env.data, 'csv');
    if (typeof csv !== 'string') return objectOutcome(env);
    return { envelope: env, lines: [csv.replace(/\n$/, '')], plain: ctx.format !== 'json' };
  },
};

// ------------------------------------------------ categories and budgets ---

const categoriesList: VerbDef = {
  path: ['categories', 'list'],
  group: G,
  summary: 'categories',
  route: 'GET /categories',
  flags: { search: { value: 'string', help: 'name contains' }, ...LIST_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/categories', { search: ctx.str('search'), ...listQuery(ctx) });
    return listOutcome(env, [C.id('id'), C.text('name'), C.text('notes')], ['categories']);
  },
};

const categoriesShow: VerbDef = {
  path: ['categories', 'show'],
  group: G,
  summary: 'one category with spent and earned in a period, per currency',
  route: 'GET /categories/{id}',
  positionals: [{ name: 'category', required: true, help: 'category id or name' }],
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'this-month');
    const env = await get(ctx, `/categories/${seg(ctx.positionals[0] as string)}`, { start: r.start, end: r.end });
    const rows = [...rowsOf(env.data, 'spent').map((x) => ({ ...x, kind: 'spent' })), ...rowsOf(env.data, 'earned').map((x) => ({ ...x, kind: 'earned' }))];
    if (rows.length) return { envelope: env, view: { rows, columns: [C.text('kind'), C.text('currency_code', 'currency'), C.amt('sum', 'amount')] } };
    return objectOutcome(env, 'category');
  },
};

const budgetsList: VerbDef = {
  path: ['budgets', 'list'],
  group: G,
  summary: 'budgets with their auto-budget settings',
  route: 'GET /budgets',
  flags: { inactive: { help: 'include disabled budgets' }, ...LIST_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/budgets', { active: ctx.bool('inactive') ? undefined : true, ...listQuery(ctx) });
    return listOutcome(env, [C.id('id'), C.text('name'), C.bool('active'), C.text('auto_budget_type', 'auto'), C.amt('auto_budget_amount', 'auto amount', 'auto_budget_currency_code'), C.text('auto_budget_period', 'every')], ['budgets']);
  },
};

const budgetsShow: VerbDef = {
  path: ['budgets', 'show'],
  group: G,
  summary: 'one budget, its limits and spent in a period',
  route: 'GET /budgets/{id}',
  positionals: [{ name: 'budget', required: true, help: 'budget id or name' }],
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'this-month');
    const env = await get(ctx, `/budgets/${seg(ctx.positionals[0] as string)}`, { start: r.start, end: r.end });
    const limits = rowsOf(env.data, 'limits');
    if (limits.length) {
      return { envelope: env, view: { rows: limits, columns: [C.date('start'), C.date('end'), C.text('currency_code', 'currency'), C.amt('amount', 'limit'), C.amt('spent'), C.amt('left')] } };
    }
    return objectOutcome(env, 'budget');
  },
};

/** "No limit" renders as —, never 0.00 — cli.mdx §9.1, apis.mdx §14.2. */
export const BUDGET_PERIOD_COLUMNS = [
  C.id('budget_id', 'id'),
  C.text('name', 'budget'),
  C.text('currency_code', 'cur'),
  C.amt('limit'),
  C.amt('spent'),
  C.amt('left'),
  C.text('auto_budget', 'auto'),
];

const budgetPeriod: VerbDef = {
  path: ['budget', 'period'],
  group: G,
  summary: 'the Budgets page: limit, spent and left per budget; "—" means NO limit, which is not a limit of zero',
  route: 'GET /budget-period',
  flags: { ...RANGE_FLAGS },
  examples: ['ffx budget period', 'ffx budget period --month 2026-10'],
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/budget-period', { start: r.start, end: r.end });
    const data = (env.data ?? {}) as Record<string, unknown>;
    const rows = rowsOf(data, 'budgets').map((b) => ({ ...b, budget_id: b.budget_id ?? b.id }));
    const notes: string[] = [];
    if (data.start || data.end) notes.push(`period ${String(data.start ?? '?')} .. ${String(data.end ?? '?')}`);
    for (const t of rowsOf(data, 'totals')) {
      notes.push(
        `${String(t.currency_code ?? '')}: available ${String(t.available ?? '—')} · budgeted ${String(t.budgeted_total ?? '—')} · spent ${String(t.spent_total ?? '—')} · left to spend ${String(t.left_to_spend ?? '—')}`,
      );
    }
    if (data.budgets_with_limit !== undefined || data.budgets_without_limit !== undefined) {
      notes.push(`${String(data.budgets_with_limit ?? '?')} budgets with a limit, ${String(data.budgets_without_limit ?? '?')} with NO limit (not the same as a limit of zero)`);
    }
    return { envelope: env, view: { rows, columns: BUDGET_PERIOD_COLUMNS, notes } };
  },
};

const budgetGaps: VerbDef = {
  path: ['budget', 'gaps'],
  group: G,
  summary: 'budgets with spending and no limit, and withdrawals with no budget at all',
  route: 'GET /budget-period/gaps',
  flags: { ...RANGE_FLAGS, 'min-spent': { value: 'amount', help: 'ignore gaps smaller than this' } },
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/budget-period/gaps', { start: r.start, end: r.end, min_spent: ctx.str('min-spent') });
    const data = (env.data ?? {}) as Record<string, unknown>;
    const rows = [
      ...rowsOf(data, 'budgets_without_limit', 'gaps').map((g) => ({ ...g, gap: 'no limit set' })),
      ...rowsOf(data, 'without_budget').map((g) => ({ ...g, name: g.name ?? '(no budget)', gap: 'no budget at all' })),
    ];
    return { envelope: env, view: { rows, columns: [C.text('gap'), C.text('name', 'budget'), C.text('currency_code', 'cur'), C.amt('spent'), C.text('count', 'transactions')] } };
  },
};

const budgetAvailable: VerbDef = {
  path: ['budget', 'available'],
  group: G,
  summary: 'income-to-budget (available budget) per period',
  route: 'GET /available-budgets',
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/available-budgets', { start: r.start, end: r.end });
    return listOutcome(env, [C.date('start'), C.date('end'), C.text('currency_code', 'cur'), C.amt('amount', 'available')], ['available_budgets']);
  },
};

// --------------------------------------- subscriptions, piggies, recurring ---

const subscriptionsList: VerbDef = {
  path: ['subscriptions', 'list'],
  group: G,
  summary: 'subscriptions (Firefly "bills"): expected amounts, frequency, next expected',
  route: 'GET /subscriptions',
  flags: { ...RANGE_FLAGS, ...LIST_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'none');
    const env = await get(ctx, '/subscriptions', { start: r.start, end: r.end, ...listQuery(ctx) });
    return listOutcome(env, [C.id('id'), C.text('name'), C.amt('amount_min', 'min'), C.amt('amount_max', 'max'), C.text('repeat_freq', 'every'), C.date('next_expected_match', 'next'), C.bool('active')], ['subscriptions', 'bills']);
  },
};

const subscriptionsStatus: VerbDef = {
  path: ['subscriptions', 'status'],
  group: G,
  summary: 'paid, unpaid, and expected-but-not-seen for a period',
  route: 'GET /subscriptions/status',
  flags: { ...RANGE_FLAGS },
  async run(ctx) {
    const r = rangeOf(ctx, 'this-month');
    const env = await get(ctx, '/subscriptions/status', { start: r.start, end: r.end });
    return listOutcome(env, [C.text('name'), C.text('status'), C.amt('expected_amount', 'expected'), C.amt('paid_amount', 'paid'), C.date('paid_date', 'paid on'), C.date('expected_date', 'expected')], ['subscriptions', 'status']);
  },
};

const piggyList: VerbDef = {
  path: ['piggy-banks', 'list'],
  group: G,
  summary: 'savings goals: saved, target, left, target date',
  route: 'GET /piggy-banks',
  flags: { ...LIST_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/piggy-banks', listQuery(ctx));
    return listOutcome(env, [C.id('id'), C.text('name'), C.amt('current_amount', 'saved'), C.amt('target_amount', 'target'), C.amt('left_to_save', 'left'), C.date('target_date', 'by'), C.amt('save_per_month', 'per month')], ['piggy_banks']);
  },
};

const piggyShow: VerbDef = {
  path: ['piggy-banks', 'show'],
  group: G,
  summary: 'one piggy bank and its add/remove events',
  route: 'GET /piggy-banks/{id}, GET /piggy-banks/{id}/events',
  positionals: [{ name: 'piggy', required: true, help: 'piggy bank id or name' }],
  flags: { events: { help: 'list the add/remove events instead' } },
  async run(ctx) {
    const id = seg(ctx.positionals[0] as string);
    if (ctx.bool('events')) {
      const env = await get(ctx, `/piggy-banks/${id}/events`);
      return listOutcome(env, [C.date('date'), C.amt('amount'), C.text('account_name', 'account'), C.id('transaction_group_id', 'group')], ['events']);
    }
    return objectOutcome(await get(ctx, `/piggy-banks/${id}`), 'piggy_bank');
  },
};

const recurringList: VerbDef = {
  path: ['recurring', 'list'],
  group: G,
  summary: 'recurring transactions and their next dates',
  route: 'GET /recurrences',
  flags: { ...LIST_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/recurrences', listQuery(ctx));
    return listOutcome(env, [C.id('id'), C.text('title'), C.text('type'), C.date('first_date', 'first'), C.date('next_date', 'next'), C.bool('active')], ['recurrences']);
  },
};

const recurringShow: VerbDef = {
  path: ['recurring', 'show'],
  group: G,
  summary: 'one recurring transaction and its next N dates',
  route: 'GET /recurrences/{id}',
  positionals: [{ name: 'id', required: true, help: 'recurrence id' }],
  flags: { count: { value: 'int', help: 'how many upcoming dates (default 5)' } },
  async run(ctx) {
    return objectOutcome(await get(ctx, `/recurrences/${seg(ctx.positionals[0] as string)}`, { count: ctx.str('count') }), 'recurrence');
  },
};

// --------------------------------------------------------------- rules ---

const rulesList: VerbDef = {
  path: ['rules', 'list'],
  group: G,
  summary: 'rules, by group, in execution order',
  route: 'GET /rules',
  flags: { group: { value: 'string', help: 'rule group id or name' }, search: { value: 'string', help: 'title contains' }, ...LIST_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/rules', { ...ref(ctx.str('group'), 'rule_group'), search: ctx.str('search'), ...listQuery(ctx) });
    return listOutcome(env, [C.id('id'), C.text('rule_group_title', 'group'), C.text('order'), C.text('title'), C.text('trigger', 'on'), C.bool('active'), C.bool('strict'), C.bool('stop_processing', 'stop')], ['rules']);
  },
};

const rulesShow: VerbDef = {
  path: ['rules', 'show'],
  group: G,
  summary: 'one rule with its triggers and actions',
  route: 'GET /rules/{id}',
  positionals: [{ name: 'id', required: true, help: 'rule id or title' }],
  async run(ctx) {
    const env = await get(ctx, `/rules/${seg(ctx.positionals[0] as string)}`);
    const rule = (getPath(env.data, 'rule') ?? env.data) as Row;
    const rows: Row[] = [
      ...((rule.triggers as Row[] | undefined) ?? []).map((t) => ({ part: 'trigger', type: t.type, value: t.value, prohibited: t.prohibited, active: t.active })),
      ...((rule.actions as Row[] | undefined) ?? []).map((a) => ({ part: 'action', type: a.type, value: a.value, active: a.active })),
    ];
    if (!rows.length) return objectOutcome(env, 'rule');
    return {
      envelope: env,
      view: {
        title: `rule #${String(rule.id ?? '?')} "${String(rule.title ?? '')}" — ${rule.strict ? 'ALL triggers must match' : 'ANY trigger matches'}`,
        rows,
        columns: [C.text('part'), C.text('type'), C.text('value'), C.bool('prohibited', 'not'), C.bool('active')],
      },
    };
  },
};

const ruleGroups: VerbDef = {
  path: ['rules', 'groups'],
  group: G,
  summary: 'rule groups in execution order',
  route: 'GET /rule-groups',
  async run(ctx) {
    const env = await get(ctx, '/rule-groups');
    return listOutcome(env, [C.id('id'), C.text('order'), C.text('title'), C.bool('active'), C.text('rule_count', 'rules')], ['rule_groups']);
  },
};

// ----------------------------------------------------------- reference ---

const tagsList: VerbDef = {
  path: ['tags', 'list'],
  group: G,
  summary: 'tags',
  route: 'GET /tags',
  flags: { search: { value: 'string', help: 'tag contains' }, ...LIST_FLAGS },
  async run(ctx) {
    const env = await get(ctx, '/tags', { search: ctx.str('search'), ...listQuery(ctx) });
    return listOutcome(env, [C.id('id'), C.text('tag'), C.date('date'), C.text('description')], ['tags']);
  },
};

const currenciesList: VerbDef = {
  path: ['currencies', 'list'],
  group: G,
  summary: 'currencies, enabled first, with the primary one marked',
  route: 'GET /currencies',
  flags: { all: { help: 'include disabled currencies' } },
  async run(ctx) {
    const env = await get(ctx, '/currencies', { enabled: ctx.bool('all') ? undefined : true });
    return listOutcome(env, [C.text('code'), C.text('name'), C.text('symbol'), C.text('decimal_places', 'places'), C.bool('enabled'), C.bool('primary')], ['currencies']);
  },
};

const administrationsList: VerbDef = {
  path: ['administrations', 'list'],
  group: G,
  summary: 'your sets of books (administrations), marking the one the plane is bound to',
  route: 'GET /administrations',
  async run(ctx) {
    const env = await get(ctx, '/administrations');
    return listOutcome(env, [C.id('id'), C.text('title'), C.text('primary_currency_code', 'currency'), C.bool('bound', 'bound here')], ['administrations'], ['switching administrations is configuration (FIREFLY_MACHINE_ADMINISTRATION), never a CLI call']);
  },
};

// ------------------------------------------------------ search and mirror ---

const search: VerbDef = {
  path: ['search'],
  group: G,
  summary: 'Firefly\'s own search language, e.g. \'description_contains:"whole foods" amount_more:50\'',
  route: 'GET /search, GET /search/count',
  positionals: [{ name: 'query', required: true, rest: true, help: 'the query (quote it)' }],
  flags: { count: { help: 'only the number of matches' }, ...LIST_FLAGS },
  examples: ['ffx search \'category_is:Groceries date_after:2026-01-01\'', 'ffx search operators'],
  async run(ctx) {
    const query = ctx.positionals.join(' ');
    if (query === 'operators') return searchOperators.run(ctx);
    if (ctx.bool('count')) {
      const env = await get(ctx, '/search/count', { query });
      return { envelope: env, lines: [String(getPath(env.data, 'count') ?? '?')] };
    }
    const env = await get(ctx, '/search', { query, ...listQuery(ctx) });
    const parsed = getPath(env.data, 'parsed_operators');
    const words = getPath(env.data, 'free_text');
    if (Array.isArray(parsed)) ctx.note(`parsed operators: ${parsed.map((p) => (typeof p === 'string' ? p : JSON.stringify(p))).join(' ') || '(none)'}`);
    if (Array.isArray(words) && words.length) ctx.note(`treated as free text (misspelt operator?): ${words.join(' ')}`);
    return transactionsOutcome(env);
  },
};

const searchOperators: VerbDef = {
  path: ['search', 'operators'],
  group: G,
  summary: 'every search operator, its argument, and an example',
  route: 'GET /search/operators',
  async run(ctx) {
    const env = await get(ctx, '/search/operators');
    return listOutcome(env, [C.text('operator'), C.text('argument'), C.text('example'), C.text('description')], ['operators']);
  },
};

const apiGet: VerbDef = {
  path: ['api', 'get'],
  group: G,
  summary: 'any upstream /api/v1 GET, read-only, through the plane\'s mirror — the escape hatch',
  route: 'GET /mirror/{path}',
  positionals: [{ name: 'path', required: true, help: 'the /api/v1 path, e.g. "insight/expense/category?start=2026-01-01&end=2026-06-30"' }],
  async run(ctx) {
    const raw = (ctx.positionals[0] as string).replace(/^\/?(api\/v1\/)?/, '');
    const q = raw.indexOf('?');
    const pathPart = q === -1 ? raw : raw.slice(0, q);
    const query: Query = {};
    if (q !== -1) {
      for (const [k, v] of new URLSearchParams(raw.slice(q + 1))) {
        const prev = query[k];
        query[k] = prev === undefined ? v : Array.isArray(prev) ? [...prev, v] : [String(prev), v];
      }
    }
    const route = `/mirror/${pathPart.split('/').map(encodeURIComponent).join('/')}`;
    return { envelope: await get(ctx, route, query) };
  },
};

export const ledgerVerbs: VerbDef[] = [
  accountsList,
  accountsShow,
  accountsBalance,
  accountsProperties,
  accountsTransactions,
  transactionsList,
  transactionsShow,
  transactionsExport,
  categoriesList,
  categoriesShow,
  budgetsList,
  budgetsShow,
  budgetPeriod,
  budgetGaps,
  budgetAvailable,
  subscriptionsList,
  subscriptionsStatus,
  piggyList,
  piggyShow,
  recurringList,
  recurringShow,
  rulesList,
  rulesShow,
  ruleGroups,
  tagsList,
  currenciesList,
  administrationsList,
  search,
  searchOperators,
  apiGet,
];
