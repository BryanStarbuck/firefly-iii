/**
 * The read families that describe the ledger as it is — pm/mcp.mdx §9.5:
 * Orientation (4), Accounts (4), Transactions (4), Budgets and categories (8),
 * Reference data (7). Twenty-seven tools, every one read-only, every one ONE
 * route in apis.mdx §8.
 */
import { at, bool, choice, date, id, ids, limit, numericId, offset, order, str, amount, tool } from './tool.js';
import type { Param, ToolDef } from './tool.js';

const TX_UNTRUSTED = ['description', 'notes', 'internal_reference', 'group_title', 'source_name', 'destination_name', 'tags', 'category_name', 'budget_name'];
const NAME_UNTRUSTED = ['name', 'notes', 'description', 'title'];

// ------------------------------------------------------------ orientation ---

const orientation: ToolDef[] = [
  tool({
    name: 'ff_whoami',
    tier: 'read',
    route: { method: 'GET', path: '/whoami' },
    what: 'Who this server acts as in Firefly III: the operator, the bound administration (id and name — the set of books every other tool answers from), the primary currency, the key fingerprint, and which tiers (read, write) the plane has switched on.',
    instead: 'Call it first when it matters WHICH set of books you are reading; use ff_health instead to find out whether the app is up at all.',
  }),
  tool({
    name: 'ff_health',
    tier: 'read',
    route: { method: 'GET', path: '/health' },
    params: { probe: bool('true also opens the database and runs Firefly\'s own connection check.') },
    what: 'Whether Firefly III is up and its machine plane armed — database reachable and migrated, operator resolved — and, if not, the exact command the operator should run; it NEVER starts the app.',
    instead: 'When a tool returns not_ready, call this and relay its answer (usually `ffx up`) to the operator instead of retrying; for which set of books is bound use ff_whoami.',
  }),
  tool({
    name: 'ff_capabilities',
    tier: 'read',
    route: { method: 'GET', path: '/capabilities' },
    what: 'What this build of the Firefly III machine plane can do: every route with its tier and its status (live or planned), the limits, and the feature list.',
    instead: 'Use it when a tool answers not_ready naming a phase, to tell the operator what is missing; for "is it running" use ff_health instead.',
  }),
  tool({
    name: 'ff_list_administrations',
    tier: 'read',
    route: { method: 'GET', path: '/administrations' },
    what: 'The operator\'s Firefly III administrations (separate sets of books), marking the one this server is bound to.',
    instead: 'Use ff_whoami instead when you only need the bound one; switching administrations is not a tool — the operator does it in configuration.',
    untrusted: ['title'],
  }),
];

// --------------------------------------------------------------- accounts ---

const accountTypes = ['asset', 'expense', 'revenue', 'liability', 'cash', 'all'] as const;

const accounts: ToolDef[] = [
  tool({
    name: 'ff_list_accounts',
    tier: 'read',
    route: { method: 'GET', path: '/accounts' },
    params: {
      type: choice(accountTypes, 'Account type: asset (the operator\'s own money — the default), expense / revenue (Firefly\'s payees), liability (loans, mortgages), cash, or all.'),
      active: bool('Only active accounts (default true); false includes deactivated ones.'),
      with_balances: bool('Include each account\'s balance as of `as_of` (default true).'),
      as_of: date('The day the balances are for (end of day). Default today.'),
      search: str('Part of an account name.', { max: 200 }),
      limit: limit(),
      offset: offset(),
      order: order('name, current_balance, id, order'),
    },
    what: 'Firefly III accounts of one type with their balances (decimal strings with currency_code), as of a date.',
    instead: 'For one account\'s balance on a given day use ff_get_account_balance; for net worth use ff_net_worth rather than adding balances yourself.',
    untrusted: ['name', 'notes'],
  }),
  tool({
    name: 'ff_get_account',
    tier: 'read',
    route: { method: 'GET', path: '/accounts/{id}' },
    params: { id: at.path(id('The account.')) },
    what: 'One Firefly III account: type, role, currency, balance, and its IBAN or number as the last four digits only.',
    instead: 'For counts, first/last transaction dates and liability terms use ff_get_account_properties.',
    untrusted: ['name', 'notes'],
  }),
  tool({
    name: 'ff_get_account_balance',
    tier: 'read',
    route: { method: 'GET', path: '/accounts/{id}/balance' },
    params: { id: at.path(id('The account.')), as_of: date('End of which day. Default today.') },
    what: 'One account\'s balance at the end of a day, per currency — Firefly\'s own running balance, signed (a card or loan can be negative).',
    instead: 'Prefer this over summing ff_list_transactions, which gives a different, wrong number.',
  }),
  tool({
    name: 'ff_get_account_properties',
    tier: 'read',
    route: { method: 'GET', path: '/accounts/{id}/properties' },
    params: { id: at.path(id('The account.')) },
    what: 'An account\'s properties: transaction counts, first and last transaction dates, IBAN last-4, opening balance, virtual balance and liability terms.',
    instead: 'For the balance itself use ff_get_account_balance.',
  }),
];

// ----------------------------------------------------------- transactions ---

const txTypes = ['withdrawal', 'deposit', 'transfer', 'opening balance', 'reconciliation', 'all'] as const;

/** The GET /transactions filter — also the shape of `filter` on the bulk writes (one filter language, apis.mdx §8.3). */
export function transactionFilter(): Record<string, Param> {
  return {
    start: date('First day of the range.'),
    end: date('Last day of the range.'),
    type: choice(txTypes, 'Transaction type. Withdrawal is money out, deposit money in, transfer between the operator\'s own accounts.'),
    account_id: id('Only transactions touching this account.'),
    account_ids: ids('Only transactions touching any of these accounts.'),
    category_id: id('Only this category.'),
    without_category: bool('true: only transactions with NO category.'),
    budget_id: id('Only this budget.'),
    without_budget: bool('true: only withdrawals with NO budget.'),
    tag: str('Only this tag.', { max: 200 }),
    without_tag: bool('true: only transactions with no tag.'),
    bill_id: id('Only transactions linked to this subscription (Firefly\'s schema still calls it a bill).'),
    min_amount: amount('Only amounts at least this.'),
    max_amount: amount('Only amounts at most this.'),
    currency_code: str('Only this currency (ISO code, e.g. USD).', { max: 10 }),
    reconciled: bool('true: only reconciled; false: only unreconciled.'),
    search: str('Words in the description.', { max: 200 }),
  };
}

const transactions: ToolDef[] = [
  tool({
    name: 'ff_list_transactions',
    tier: 'read',
    route: { method: 'GET', path: '/transactions' },
    params: { ...transactionFilter(), limit: limit(), offset: offset(), order: order('date, amount, id (default: -date, then order, then id — Firefly\'s own)') },
    what: 'Firefly III transaction groups with every split, filtered by date range, account, category, budget, tag, type, amount bounds and the without_* flags.',
    instead: 'Never add these up for a total — use ff_spending_by_category, ff_spending_by_budget, ff_payee_leaderboard or ff_income_vs_expense instead; for "what is uncategorised" use ff_list_uncategorized.',
    untrusted: TX_UNTRUSTED,
  }),
  tool({
    name: 'ff_get_transaction',
    tier: 'read',
    route: { method: 'GET', path: '/transactions/{group_id}' },
    params: { group_id: at.path(numericId('The transaction GROUP id (not a journal id).')) },
    what: 'One Firefly III transaction group with every split (journal) and its links.',
    instead: 'Use ff_list_transactions instead to find a group by date, account or description.',
    untrusted: TX_UNTRUSTED,
  }),
  tool({
    name: 'ff_list_uncategorized',
    tier: 'read',
    route: { method: 'GET', path: '/transactions' },
    fixedQuery: { without_category: true },
    params: {
      start: date('First day of the range.'),
      end: date('Last day of the range.'),
      type: choice(txTypes, 'Transaction type (withdrawals are the usual question).'),
      account_id: id('Only this account.'),
      account_ids: ids('Only these accounts.'),
      limit: limit(),
      offset: offset(),
      order: order('date, amount, id'),
    },
    what: 'Transactions with no category — the commonest bookkeeping question, answered by Firefly\'s own without_category filter in one call.',
    instead: 'For the count and total first, use ff_get_uncategorized_summary; to fix them, ff_preview_rule then ff_add_rule, or ff_categorize_transactions for a handful.',
    untrusted: TX_UNTRUSTED,
  }),
  tool({
    name: 'ff_export_transactions',
    tier: 'read',
    route: { method: 'GET', path: '/transactions/export' },
    params: { ...transactionFilter(), format: choice(['csv'] as const, 'Export format (csv).') },
    what: 'The same filters as ff_list_transactions, exported as CSV by Firefly\'s own exporter.',
    instead: 'Use ff_list_transactions instead when you need to read rows yourself; this is for handing a file-shaped export to the operator.',
    untrusted: ['csv', ...TX_UNTRUSTED],
  }),
];

// ------------------------------------------------- budgets and categories ---

const budgets: ToolDef[] = [
  tool({
    name: 'ff_list_budgets',
    tier: 'read',
    route: { method: 'GET', path: '/budgets' },
    params: { active: bool('Only active budgets (default true).'), limit: limit(), offset: offset() },
    what: 'Firefly III budgets (envelope names) with their auto-budget settings.',
    instead: 'For how much is budgeted, spent and left this period use ff_get_budget_period instead.',
    untrusted: ['name', 'notes'],
  }),
  tool({
    name: 'ff_get_budget',
    tier: 'read',
    route: { method: 'GET', path: '/budgets/{id}' },
    params: { id: at.path(id('The budget.')), start: date('First day of the range.'), end: date('Last day of the range.') },
    what: 'One Firefly III budget with its budget limits and what was spent against it in a range, per currency.',
    instead: 'For every budget at once for a period use ff_get_budget_period.',
    untrusted: ['name', 'notes'],
  }),
  tool({
    name: 'ff_get_budget_period',
    tier: 'read',
    route: { method: 'GET', path: '/budget-period' },
    params: { start: date('First day of the period (default: the operator\'s current view range).'), end: date('Last day of the period.') },
    what: 'The Budgets page for a period: per budget per currency the limit (a decimal string, or null when NO limit is set — which is not a limit of zero), spent and left, plus available and left to spend.',
    instead: 'For budgets that have spending but no limit, or spending with no budget, use ff_list_budget_gaps; for several periods use ff_budget_performance.',
    extra: 'Report `limit: null` as "no limit set", never as zero.',
    untrusted: ['name'],
  }),
  tool({
    name: 'ff_list_budget_gaps',
    tier: 'read',
    route: { method: 'GET', path: '/budget-period/gaps' },
    params: { start: date('First day of the period.'), end: date('Last day of the period.'), min_spent: amount('Ignore gaps smaller than this.') },
    what: 'Budgets with spending but NO limit in the period, and withdrawals with no budget at all — "no limit is not a limit of zero" made into a question you can ask.',
    instead: 'For the full budget table use ff_get_budget_period.',
    untrusted: ['name', 'description'],
  }),
  tool({
    name: 'ff_list_available_budgets',
    tier: 'read',
    route: { method: 'GET', path: '/available-budgets' },
    params: { start: date('First day of the range.'), end: date('Last day of the range.') },
    what: 'Available budgets — the income the operator set aside to hand out — per period and currency.',
    instead: 'For what is left to spend this period use ff_get_budget_period instead.',
  }),
  tool({
    name: 'ff_list_categories',
    tier: 'read',
    route: { method: 'GET', path: '/categories' },
    params: { search: str('Part of a category name.', { max: 200 }), limit: limit(), offset: offset() },
    what: 'Firefly III categories.',
    instead: 'For what was spent per category use ff_spending_by_category instead; for one category over time, ff_category_trend.',
    untrusted: NAME_UNTRUSTED,
  }),
  tool({
    name: 'ff_get_category',
    tier: 'read',
    route: { method: 'GET', path: '/categories/{id}' },
    params: { id: at.path(id('The category.')), start: date('First day of the range.'), end: date('Last day of the range.') },
    what: 'One Firefly III category with what was spent and earned in it over a range, per currency.',
    instead: 'For every category at once use ff_spending_by_category.',
    untrusted: NAME_UNTRUSTED,
  }),
  tool({
    name: 'ff_get_category_tree',
    tier: 'read',
    route: { method: 'GET', path: '/categories/tree' },
    what: 'Every Firefly III category as the two-level tree Firefly computes from the "Group > Sub" naming convention (a category named "Food > Groceries" is subcategory Groceries of group Food; a name with no " > " is a group; a group with id null exists only as a prefix), returned as the server\'s YAML document, with the structured tree beside it.',
    instead: 'Read it BEFORE categorising — with ff_categorize_transactions or ff_categorize_imported_transactions — and pick a full_name (or id) from it; use ff_list_categories instead to search by part of a name.',
    extra: 'Assign the FULL name ("Food > Groceries"), never the bare subcategory; a category the tree does not contain does not exist — ask the operator rather than inventing one.',
    untrusted: ['name', 'full_name', 'yaml'],
    text: (data) => {
      const yaml = (data as { yaml?: unknown } | null)?.yaml;
      return typeof yaml === 'string' ? yaml : undefined;
    },
  }),
];

// ---------------------------------------------------------- reference data ---

const reference: ToolDef[] = [
  tool({
    name: 'ff_list_tags',
    tier: 'read',
    route: { method: 'GET', path: '/tags' },
    params: { search: str('Part of a tag.', { max: 200 }), limit: limit(), offset: offset() },
    what: 'Firefly III tags.',
    instead: 'For spending per tag use ff_spending_by_tag instead.',
    untrusted: ['tag', 'description'],
  }),
  tool({
    name: 'ff_list_subscriptions',
    tier: 'read',
    route: { method: 'GET', path: '/subscriptions' },
    params: { start: date('First day of the range for paid and expected dates.'), end: date('Last day of the range.') },
    what: 'Firefly III subscriptions (recurring expected payments, "bills" in older Firefly): expected amount range, frequency, paid dates and the next expected date.',
    instead: 'For paid versus unpaid in a period use ff_get_subscription_status; for recurring payments Firefly does NOT yet know about, ff_list_recurring.',
    untrusted: ['name', 'notes'],
  }),
  tool({
    name: 'ff_get_subscription_status',
    tier: 'read',
    route: { method: 'GET', path: '/subscriptions/status' },
    params: { start: date('First day of the period.'), end: date('Last day of the period.') },
    what: 'Which subscriptions are paid, unpaid, and expected-but-not-seen for a period.',
    instead: 'For the subscription definitions themselves use ff_list_subscriptions.',
    untrusted: ['name'],
  }),
  tool({
    name: 'ff_list_piggy_banks',
    tier: 'read',
    route: { method: 'GET', path: '/piggy-banks' },
    what: 'Firefly III piggy banks (savings goals) with saved, target and left to save.',
    instead: 'For on-track-for-the-target-date use ff_get_piggy_progress instead.',
    untrusted: ['name', 'notes'],
  }),
  tool({
    name: 'ff_list_recurrences',
    tier: 'read',
    route: { method: 'GET', path: '/recurrences' },
    what: 'Firefly III recurring transactions with their next occurrence dates.',
    instead: 'This is also the preview for ff_trigger_recurrence, which has no dry run.',
    untrusted: ['title', 'description', 'notes'],
  }),
  tool({
    name: 'ff_list_rules',
    tier: 'read',
    route: { method: 'GET', path: '/rules' },
    params: {
      rule_group_id: id('Only rules in this rule group.'),
      search: str('Part of a rule title.', { max: 200 }),
      trigger: choice(['store-journal', 'update-journal', 'manual'] as const, 'When the rule fires.'),
    },
    what: 'Firefly III rules by rule group, in execution order, with their triggers and actions.',
    instead: 'To see what a rule would change use ff_preview_rule.',
    untrusted: ['title', 'description'],
  }),
  tool({
    name: 'ff_list_currencies',
    tier: 'read',
    route: { method: 'GET', path: '/currencies' },
    params: { enabled: bool('Only enabled currencies (default true).') },
    what: 'The currencies this Firefly III install has enabled, and which is the primary one.',
    instead: 'For just the primary currency use ff_whoami instead — it is also on every reply as meta.primaryCurrency.',
  }),
];

export const LEDGER_TOOLS: ToolDef[] = [...orientation, ...accounts, ...transactions, ...budgets, ...reference];
