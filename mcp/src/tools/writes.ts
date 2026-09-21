/**
 * The write tier (19, off by default) — pm/mcp.mdx §9.5, §9.7; apis.mdx §7.
 *
 * Before one transaction changes: the plane's write tier is on
 * (FIREFLY_MACHINE_ALLOW_WRITE=1), this server's is on (FFMCP_ALLOW_WRITE=1),
 * and the call carries dry_run: false plus a `confirm` echoing the token the
 * dry run (or the matching plan_/preview_ tool) returned — under a ceiling.
 * Seventeen default dry_run to true; ff_undo and ff_trigger_recurrence have no
 * dry_run at all, and their schemas do not offer one.
 *
 * There is no delete tool of anything, no tool that marks a duplicate, and
 * none that re-creates a transaction the operator deleted.
 */
import { z } from 'zod';

import { amount, array, at, bool, choice, date, id, ids, int, numericId, numericIds, object, str, strings, text, tool, exactlyOne, invalid } from './tool.js';
import type { Param, ToolDef } from './tool.js';
import { transactionFilter } from './ledger.js';
import { ruleObject } from './statements.js';

const TX_TYPES = ['withdrawal', 'deposit', 'transfer'] as const;
const ONE_AT_A_TIME = 'Apply one write at a time, report what it did, and read the result back before proposing the next.';

/** One split of a transaction group, in Firefly's own field names. */
function split(forUpdate: boolean): Param {
  const req = <P extends Param>(p: P): Param => (forUpdate ? p : at.required(p));
  return object('One split (journal) of the group. Every split in a group shares its type; a transfer\'s splits share their accounts.', {
    ...(forUpdate ? { transaction_journal_id: numericId('Which existing split to change (omit to add a split).') } : {}),
    type: req(choice(TX_TYPES, 'withdrawal (money out), deposit (money in) or transfer (between two of the operator\'s own accounts — ONE row, never a withdrawal plus a deposit).')),
    date: req(date('The transaction date.')),
    amount: req(amount('The amount.')),
    currency_code: str('Currency (default: the source account\'s).', { max: 10 }),
    foreign_amount: amount('The amount in a second currency, if the bank charged one.'),
    foreign_currency_code: str('That second currency.', { max: 10 }),
    description: req(str('What it was.', { max: 1000 })),
    source_id: numericId('Source account id (for a withdrawal: the operator\'s asset account).'),
    source_name: str('Or the source account by exact name (for a deposit: who paid).', { max: 255 }),
    destination_id: numericId('Destination account id.'),
    destination_name: str('Or the destination by exact name (for a withdrawal: the payee — created as an expense account if new).', { max: 255 }),
    category_id: numericId('Category id.'),
    category_name: str('Or the category by name.', { max: 255 }),
    budget_id: numericId('Budget id (withdrawals only).'),
    budget_name: str('Or the budget by name.', { max: 255 }),
    bill_id: numericId('Link to this subscription.'),
    tags: strings('Tags.'),
    notes: text('The operator\'s note.'),
    external_id: str('An external reference (a bank id).', { max: 255 }),
  });
}

/** budget_id that may be null — "no budget" (apis.mdx §8.3 `budget_id | null`). */
function nullableBudget(): Param {
  return {
    json: {
      type: ['integer', 'string', 'null'],
      minimum: 1,
      minLength: 1,
      maxLength: 200,
      description: 'The budget to set — a Firefly id or the exact name — or null to REMOVE the budget from these transactions.',
    },
    zod: z.union([z.number().int().positive(), z.string().min(1).max(200), z.null()]),
    loc: 'body',
    required: true,
    kind: 'id',
  };
}

function selection(): Record<string, Param> {
  return {
    journal_ids: at.body(numericIds('The journal (split) ids to change.')),
    filter: at.body(object('Or: the same filter ff_list_transactions takes — so "show me these" and "change these" select the same set. Run ff_list_transactions with it first.', transactionFilter())),
  };
}

const writes: ToolDef[] = [
  // ------------------------------------------------------------- ingest ---
  tool({
    name: 'ff_apply_accounts',
    tier: 'write',
    route: { method: 'POST', path: '/ingest/accounts/apply' },
    write: { tokenRequired: 'always', planTool: 'ff_plan_accounts' },
    what: 'Creates the Firefly III accounts an archive manifest needs, as planned by ff_plan_accounts, in one batch, and writes the statements-to-account map beside the statements.',
    instead: 'Run ff_plan_accounts first and resolve every ambiguous row with the operator — ambiguous rows are never created.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_apply_statement_import',
    tier: 'write',
    route: { method: 'POST', path: '/ingest/apply' },
    long: true,
    write: { tokenRequired: 'always', planTool: 'ff_plan_statement_import', sendsMaxChanges: true },
    what: 'Runs a planned bank-statement import through Firefly III\'s own store path; the plan is recomputed now and refused with the new counts (conflict) if the ledger changed since.',
    instead: 'Run ff_plan_statement_import first and show the operator the plan per account; deleted transactions stay deleted and nothing here re-creates them.',
    extra: `The statement archive is read-only: this writes the ledger, never the archive. ${ONE_AT_A_TIME}`,
  }),
  tool({
    name: 'ff_apply_file_import',
    tier: 'write',
    route: { method: 'POST', path: '/ingest/file/apply' },
    long: true,
    write: { tokenRequired: 'always', planTool: 'ff_plan_file_import' },
    what: 'Imports one planned statement file into one Firefly III account through Firefly\'s own store path.',
    instead: 'Run ff_plan_file_import first; for a whole archive use ff_apply_statement_import instead.',
    extra: `The statement archive is read-only: this writes the ledger, never the file. ${ONE_AT_A_TIME}`,
  }),

  // ------------------------------------------------------- transactions ---
  tool({
    name: 'ff_add_transaction',
    tier: 'write',
    route: { method: 'POST', path: '/transactions' },
    params: {
      transactions: at.body(at.required(array('The splits — one for an ordinary transaction.', split(false), { min: 1, max: 100 }))),
      group_title: at.body(str('A title for a split transaction.', { max: 1000 })),
      apply_rules: at.body(bool('Run Firefly\'s rules on it, as for a hand-entered row (default true).')),
      idempotency_key: at.body(str('A retry-safe key: a repeat within 24 hours returns the original result instead of a second transaction.', { max: 128 })),
    },
    what: 'Adds one Firefly III transaction group — a withdrawal, deposit or transfer, with splits if needed — with Firefly\'s rules applied and its duplicate check pinned on (a duplicate is a conflict naming the existing group).',
    instead: 'A transfer between two of the operator\'s own accounts is ONE transfer, never a withdrawal plus a deposit; for importing statements use ff_plan_statement_import instead.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_update_transaction',
    tier: 'write',
    route: { method: 'PUT', path: '/transactions/{group_id}' },
    params: {
      group_id: at.path(numericId('The transaction GROUP id.')),
      group_title: at.body(str('New group title.', { max: 1000 })),
      transactions: at.body(array('The splits to change (by transaction_journal_id) or add; send only the fields that change.', split(true), { min: 1, max: 100 })),
      apply_rules: at.body(bool('Re-run Firefly\'s rules on it (default false — a hand edit is not re-categorised).')),
    },
    refine: (args) => {
      if (args.group_title === undefined && args.transactions === undefined) throw invalid('nothing to change: give group_title and/or transactions');
    },
    what: 'Changes one Firefly III transaction group\'s fields, including re-splitting it.',
    instead: 'Read it first with ff_get_transaction; to change only the category of many rows use ff_categorize_transactions, or the type, ff_convert_transaction.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_categorize_transactions',
    tier: 'write',
    route: { method: 'POST', path: '/transactions/categorize' },
    params: {
      ...selection(),
      category_id: at.required(at.body(id('The category to set.'))),
    },
    refine: exactlyOne('journal_ids', 'filter'),
    write: { sendsMaxChanges: true },
    what: 'Sets one category on named journals, or on every journal a filter selects, under a ceiling.',
    instead: 'For a pattern that will recur (every Whole Foods row), author a rule instead: ff_preview_rule, then ff_add_rule, then ff_run_rules.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_categorize_imported_transactions',
    tier: 'write',
    route: { method: 'POST', path: '/transactions/categorize-by-import' },
    params: {
      assignments: at.required(
        at.body(
          array(
            'One entry per imported row: which account it was imported into, its import id, and the category to give it.',
            object('One imported row and its category.', {
              account: at.required(id('The Firefly account the row was imported into.')),
              import_id: at.required(str('The row\'s import id — its external_id exactly as ff_get_statement_rows shows it (ofx:4021:… or ff1:4021:…).', { max: 255 })),
              category: at.required(id('The category — its id, or its FULL name from ff_get_category_tree, e.g. "Food > Groceries".')),
            }),
            { min: 1, max: 5000 },
          ),
        ),
      ),
      create_missing: at.body(bool('true creates a category name that does not exist yet (the default, false, reports it in unknown_categories and leaves its rows alone). Only with the operator\'s explicit yes.')),
    },
    write: { sendsMaxChanges: true },
    what: 'Sets categories on transactions ALREADY in the books, found by account + the import id the statement import stamped on each row; only the category changes, and every assignment comes back with an outcome (updated, unchanged, not_found, ambiguous_import_id, unknown_account, unknown_category).',
    instead: 'Read ff_get_category_tree first and use only its names; for rows you know by journal id or a filter, use ff_categorize_transactions instead.',
    extra: `An unknown category is reported, never invented — show unknown_categories to the operator. ${ONE_AT_A_TIME}`,
  }),
  tool({
    name: 'ff_set_transaction_budget',
    tier: 'write',
    route: { method: 'POST', path: '/transactions/set-budget' },
    params: { ...selection(), budget_id: nullableBudget() },
    refine: exactlyOne('journal_ids', 'filter'),
    write: { sendsMaxChanges: true },
    what: 'Sets a budget — or removes it, with null — on named journals or on every journal a filter selects, under a ceiling.',
    instead: 'For "which withdrawals have no budget" read ff_list_budget_gaps first.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_convert_transaction',
    tier: 'write',
    route: { method: 'POST', path: '/transactions/{group_id}/convert' },
    params: {
      group_id: at.path(numericId('The transaction GROUP id.')),
      to_type: at.body(at.required(choice(TX_TYPES, 'The new type.'))),
      source_id: at.body(id('The new source account, when the conversion needs one.')),
      destination_id: at.body(id('The new destination account, when the conversion needs one.')),
    },
    what: 'Converts a transaction between withdrawal, deposit and transfer — Firefly\'s "convert" — e.g. a withdrawal that was really a transfer to savings.',
    instead: 'To change other fields use ff_update_transaction instead.',
    extra: ONE_AT_A_TIME,
  }),

  // ------------------------------------------------------------ budgets ---
  tool({
    name: 'ff_set_budget_limit',
    tier: 'write',
    route: { method: 'PUT', path: '/budgets/{id}/limits' },
    params: {
      id: at.path(id('The budget.')),
      start: at.body(at.required(date('First day of the period.'))),
      end: at.body(at.required(date('Last day of the period.'))),
      currency_code: at.body(str('Currency (default: the primary).', { max: 10 })),
      amount: at.body(at.required(amount('The limit. "0.00" means "budgeted nothing", which is different from no limit.'))),
    },
    what: 'SETS one budget\'s limit for exactly one period and currency — creating or replacing it, never adding to it.',
    instead: 'Read ff_get_budget_period first; to build a whole month use ff_copy_previous_budget or ff_set_budget_to_average instead.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_set_available_budget',
    tier: 'write',
    route: { method: 'PUT', path: '/available-budgets' },
    params: {
      start: at.body(at.required(date('First day of the period.'))),
      end: at.body(at.required(date('Last day of the period.'))),
      currency_code: at.body(str('Currency (default: the primary).', { max: 10 })),
      amount: at.body(at.required(amount('The income to budget for the period.'))),
    },
    what: 'Sets the available budget — the income the operator expects to hand out — for one period and currency.',
    instead: 'Read ff_list_available_budgets first.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_copy_previous_budget',
    tier: 'write',
    route: { method: 'POST', path: '/budget-period/copy-previous' },
    params: {
      start: at.body(at.required(date('First day of the period to fill.'))),
      end: at.body(at.required(date('Last day of that period.'))),
      budget_ids: at.body(ids('Only these budgets (default all).')),
    },
    what: 'Copies every budget limit from the previous period of the same length into this one, and says how many budgets it would leave unbudgeted.',
    instead: 'To base the limits on what was actually spent use ff_set_budget_to_average instead.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_set_budget_to_average',
    tier: 'write',
    route: { method: 'POST', path: '/budget-period/set-average' },
    params: {
      start: at.body(at.required(date('First day of the period to fill.'))),
      end: at.body(at.required(date('Last day of that period.'))),
      periods: at.body(at.required(int('Average over this many previous periods (3, 6, 12 …).', { min: 1, max: 60 }))),
      budget_ids: at.body(ids('Only these budgets (default all).')),
    },
    what: 'Sets each budget\'s limit for the period to Firefly\'s average of what was SPENT over the previous N periods — the average is computed by Firefly, not by you.',
    instead: 'To repeat last period\'s limits unchanged use ff_copy_previous_budget instead.',
    extra: ONE_AT_A_TIME,
  }),

  // -------------------------------------------------------------- rules ---
  tool({
    name: 'ff_add_rule',
    tier: 'write',
    route: { method: 'POST', path: '/rules' },
    params: { rule: at.body(at.required(ruleObject())) },
    what: 'Creates a Firefly III rule (triggers and actions) in a rule group; it applies to future transactions, not past ones.',
    instead: 'Run ff_preview_rule with the same rule FIRST and show the operator what it matches; to apply it to history afterwards use ff_run_rules.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_run_rules',
    tier: 'write',
    route: { method: 'POST', path: '/rules/run' },
    params: {
      rule_ids: at.body(ids('The rules to run.')),
      rule_group_id: at.body(id('Or a whole rule group.')),
      start: at.body(at.required(date('First day of the history to run over.'))),
      end: at.body(at.required(date('Last day.'))),
      account_ids: at.body(ids('Only journals on these accounts.')),
    },
    refine: exactlyOne('rule_ids', 'rule_group_id'),
    write: { sendsMaxChanges: true },
    what: 'Runs existing Firefly III rules over past transactions — Firefly\'s own rule engine — under a ceiling.',
    instead: 'For a rule that does not exist yet: ff_preview_rule, then ff_add_rule, then this.',
    extra: ONE_AT_A_TIME,
  }),

  // ---------------------------------------------------------- reconcile ---
  tool({
    name: 'ff_apply_reconcile',
    tier: 'write',
    route: { method: 'POST', path: '/accounts/{id}/reconcile/apply' },
    params: {
      id: at.path(id('The account being reconciled — the same one as the plan.')),
      create_reconciliation: at.body(bool('If the difference is not zero, create ONE visible reconciliation transaction for it (default true). It is honest, but it is a plug — let the operator choose it knowingly.')),
    },
    write: { tokenRequired: 'always', planTool: 'ff_plan_reconcile' },
    what: 'Marks the planned journals reconciled and, if asked and the difference is not zero, creates Firefly\'s one visible reconciliation transaction for it.',
    instead: 'Run ff_plan_reconcile first; if its difference is not zero, find the cause with the operator before applying.',
    extra: ONE_AT_A_TIME,
  }),

  // -------------------------------------------- piggy banks, recurrences ---
  tool({
    name: 'ff_move_piggy_bank_money',
    tier: 'write',
    route: { method: 'POST', path: '/piggy-banks/{id}/{direction}' },
    params: {
      id: at.path(id('The piggy bank.')),
      direction: at.path(choice(['add', 'remove'] as const, 'add money to the savings goal, or remove it.')),
      amount: at.body(at.required(amount('How much.'))),
      account_id: at.body(id('Which linked asset account (when the piggy bank has several).')),
    },
    what: 'Moves money into or out of a Firefly III piggy bank (savings goal); Firefly refuses beyond what the account can spare, and says the figure.',
    instead: 'Check ff_get_piggy_progress first.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_trigger_recurrence',
    tier: 'write',
    route: { method: 'POST', path: '/recurrences/{id}/trigger' },
    params: { id: at.path(id('The recurring transaction.')), date: at.body(date('The occurrence date (default today).')) },
    write: { dryRun: false, confirm: false, tokenRequired: 'never', planTool: 'ff_list_recurrences', ceiling: false },
    what: 'Creates a Firefly III recurring transaction\'s occurrence now, as Firefly\'s own "trigger" does.',
    instead: 'Preview with ff_list_recurrences (it shows the next date and the amounts) and get the operator\'s yes; ff_undo can reverse it.',
    extra: ONE_AT_A_TIME,
  }),
  tool({
    name: 'ff_undo',
    tier: 'write',
    route: { method: 'POST', path: '/undo' },
    write: { dryRun: false, confirm: true, tokenRequired: 'always', planTool: 'ff_preview_undo', ceiling: false },
    what: 'Reverses the LAST write made through this plane (this server or the ffx CLI) and reports what it undid; it refuses if the operator has since edited a touched row, and it cannot undo anything done in the Firefly browser UI.',
    instead: 'Call ff_preview_undo first, pass its confirm_token, and tell the operator what was undone — not just that it worked.',
  }),
];

export const WRITE_TOOLS: ToolDef[] = writes;
