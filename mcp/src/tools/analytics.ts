/**
 * Analytics (15) and Charts and reports (2) — pm/mcp.mdx §9.5, apis.mdx §10.
 *
 * The most important family in the catalogue: it exists so a model never adds
 * two numbers together. Every figure comes back per currency, as a decimal
 * string, with the provenance of what was counted. This file computes nothing.
 */
import { amount, at, bool, choice, date, decimal, id, ids, int, invalid, str, strings, tool } from './tool.js';
import type { Param, ToolDef } from './tool.js';

const intervals = ['month', 'quarter', 'year', 'none'] as const;

/** Every analytics route takes these (apis.mdx §10.2). */
function common(): Record<string, Param> {
  return {
    start: at.required(date('First day of the range.')),
    end: at.required(date('Last day of the range.')),
    account_ids: ids('Only these accounts (default: the asset accounts).'),
    category_ids: ids('Only these categories.'),
    budget_ids: ids('Only these budgets.'),
    tags: strings('Only transactions with these tags.'),
    currency_code: str('Only this currency (ISO code).', { max: 10 }),
    include_transfers: bool('Count transfers between the operator\'s own accounts (default false — a transfer is not spending).'),
  };
}

const interval = (): Param => choice(intervals, 'Bucket the answer per month, quarter or year; none for one total per currency.');
const topN = (): Param => int('Only the largest N rows; the rest are reported together.', { min: 1, max: 500 });

function analytics(name: string, path: string, what: string, instead: string, extra: Record<string, Param> = {}, untrusted: string[] = ['name', 'label']): ToolDef {
  return tool({
    name,
    tier: 'read',
    route: { method: 'GET', path },
    params: { ...common(), ...extra },
    what,
    instead,
    untrusted,
  });
}

const family: ToolDef[] = [
  analytics(
    'ff_get_summary',
    '/analytics/summary',
    'The Firefly III dashboard boxes for a range: balance, subscriptions paid and unpaid, left to spend, and net worth — per currency.',
    'For any one of those in detail use ff_net_worth, ff_get_budget_period or ff_get_subscription_status.',
  ),
  analytics(
    'ff_spending_by_category',
    '/analytics/spending-by-category',
    'What was spent per category per interval, per currency, plus "no category" — Firefly\'s own aggregation, transfers and opening balances excluded.',
    'Use this instead of summing ff_list_transactions; for one category over time use ff_category_trend.',
    { interval: interval(), top_n: topN() },
  ),
  analytics(
    'ff_spending_by_budget',
    '/analytics/spending-by-budget',
    'What was spent per budget per interval, per currency, plus "no budget".',
    'For limit versus spent use ff_get_budget_period or ff_budget_performance instead.',
    { interval: interval(), top_n: topN() },
  ),
  analytics(
    'ff_spending_by_tag',
    '/analytics/spending-by-tag',
    'What was spent per tag per interval, per currency, plus "no tag".',
    'Use this instead of summing ff_list_transactions by tag.',
    { interval: interval(), top_n: topN() },
    ['tag', 'name', 'label'],
  ),
  analytics(
    'ff_payee_leaderboard',
    '/analytics/payee-leaderboard',
    'Who got the money: spending per expense account (Firefly\'s payees), or income per revenue account with direction "in", per currency.',
    'For spending by category use ff_spending_by_category instead.',
    { top_n: topN(), direction: choice(['out', 'in'] as const, 'out (default): expense accounts; in: revenue accounts.') },
  ),
  analytics(
    'ff_income_vs_expense',
    '/analytics/income-vs-expense',
    'Money in, money out and the net per interval, per currency, transfers excluded.',
    'For balances rolling forward use ff_cash_flow instead.',
    { interval: interval() },
  ),
  analytics(
    'ff_cash_flow',
    '/analytics/cash-flow',
    'Opening balance, in, out and closing balance per interval over the asset accounts, per currency.',
    'For assets minus liabilities use ff_net_worth instead.',
    { interval: interval() },
  ),
  analytics(
    'ff_net_worth',
    '/analytics/net-worth',
    'Assets, liabilities and net worth per interval and per account, per currency, honouring each account\'s include-in-net-worth flag.',
    'To draw it, use ff_get_chart with name "net-worth" instead.',
    { interval: interval() },
  ),
  analytics(
    'ff_category_trend',
    '/analytics/category-trend',
    'One category over time, per interval and currency, with its mean and median.',
    'For every category at once use ff_spending_by_category.',
    { category_id: at.required(id('The category.')), interval: interval() },
  ),
  analytics(
    'ff_budget_performance',
    '/analytics/budget-performance',
    'Limit versus spent versus left per budget per period, with the variance — a null limit means no limit was set, not zero.',
    'For just the current period use ff_get_budget_period.',
  ),
  analytics(
    'ff_list_recurring',
    '/analytics/recurring',
    'Recurring payments Firefly does NOT yet track as a subscription, each with its evidence (the occurrences and amounts) — a detector, not an oracle.',
    'For the subscriptions Firefly already knows use ff_list_subscriptions instead.',
    { min_occurrences: int('How many occurrences make a pattern (default 3).', { min: 2, max: 60 }), tolerance_days: int('Allowed drift in days (default 3).', { min: 0, max: 31 }) },
    ['name', 'description', 'label'],
  ),
  analytics(
    'ff_get_runway',
    '/analytics/runway',
    'How long the liquid assets would last: liquid balance divided by trailing average outflow, as a decimal string with its numerator, denominator and basis named.',
    'For the underlying numbers use ff_cash_flow instead; quote the basis with the answer.',
    { basis: choice(['3', '6', '12'] as const, 'Trailing months for the average outflow (default 6).') },
  ),
  analytics(
    'ff_get_uncategorized_summary',
    '/analytics/uncategorized-summary',
    'Count and total of uncategorised withdrawals, by account and by month, per currency.',
    'Then list them with ff_list_uncategorized.',
  ),
  analytics(
    'ff_list_anomalies',
    '/analytics/anomalies',
    'Categories and payees unusually far from their own trailing norm, each with the deviation and its evidence — a detector, not an oracle.',
    'For one category over time use ff_category_trend instead.',
    {
      z: decimal('How many standard deviations count as unusual (default "2.0").'),
      min_amount: amount('Ignore deviations smaller than this.'),
    },
  ),
  analytics(
    'ff_get_piggy_progress',
    '/analytics/piggy-progress',
    'Per piggy bank: saved versus target versus target date, and whether it is on track.',
    'For the plain list use ff_list_piggy_banks.',
  ),
];

// ------------------------------------------------------- charts and reports ---

const CHARTS = [
  'account-balances',
  'net-worth',
  'budget-overview',
  'category-overview',
  'spending-by-category',
  'income-vs-expense',
  'tag-overview',
  'piggy-bank',
  'subscription',
  'series',
] as const;

const charts: ToolDef[] = [
  tool({
    name: 'ff_get_chart',
    tier: 'read',
    route: { method: 'GET', path: '/charts/{name}' },
    params: {
      name: at.path(choice(CHARTS, 'Which chart. piggy-bank and subscription also need `id`; series needs `source`.')),
      id: id('The piggy bank or subscription, for those two charts.', { loc: 'path' }),
      source: str('For name "series": the analytics route to shape, e.g. /analytics/cash-flow.', { max: 100 }),
      start: date('First day of the range.'),
      end: date('Last day of the range.'),
      interval: choice(['day', 'week', 'month', 'quarter', 'year'] as const, 'The x-axis step.'),
      account_ids: ids('Only these accounts.'),
      currency_code: str('Only this currency.', { max: 10 }),
    },
    refine: (args) => {
      if ((args.name === 'piggy-bank' || args.name === 'subscription') && args.id === undefined) {
        throw invalid(`chart "${String(args.name)}" needs id — the piggy bank or subscription`);
      }
      if (args.name === 'series' && args.source === undefined) throw invalid('chart "series" needs source, e.g. /analytics/cash-flow');
    },
    resolvePath: (args) => {
      const name = String(args.name);
      return name === 'piggy-bank' || name === 'subscription' ? `/charts/${name}/${encodeURIComponent(String(args.id))}` : `/charts/${name}`;
    },
    what: 'Chart-ready series from Firefly III: x labels and, per series and currency, a list of decimal strings with null for "no data" (a gap, never zero).',
    instead: 'For the numbers alone use the matching analytics tool instead (ff_net_worth, ff_spending_by_category, ff_income_vs_expense); hand these series to an artifact exactly as returned — never fill a null, smooth, or merge currencies onto one axis.',
    untrusted: ['label'],
  }),
  tool({
    name: 'ff_get_report',
    tier: 'read',
    route: { method: 'GET', path: '/reports/{type}' },
    params: {
      type: at.path(choice(['default', 'audit', 'budget', 'category', 'tag', 'double'] as const, 'Which Firefly report: default, audit (every journal with the running balance after it), budget, category, tag, or double (expense/revenue).')),
      start: at.required(date('First day.')),
      end: at.required(date('Last day.')),
      account_ids: ids('The accounts the report covers.'),
      budget_ids: ids('For the budget report.'),
      category_ids: ids('For the category report.'),
      tags: strings('For the tag report.'),
      counterparty_ids: ids('For the double report: expense/revenue accounts.'),
    },
    what: 'The numbers behind one of Firefly III\'s six reports — default, audit, budget, category, tag, or expense/revenue — for a range.',
    instead: 'For a single figure prefer the matching analytics tool (ff_spending_by_category, ff_income_vs_expense …), which is smaller.',
    untrusted: ['description', 'notes', 'name'],
  }),
];

export const ANALYTICS_TOOLS: ToolDef[] = [...family, ...charts];
