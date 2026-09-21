/**
 * Search and the mirror (3), Statements and ingest (10), Previews for writes (3)
 * — pm/mcp.mdx §9.5, §11; apis.mdx §8.2, §8.7, §8.12, §11, §12, §13, §7.5.
 *
 * All read-tier. Two of the ingest reads touch disk — ff_extract_statements
 * writes the plane's staging directory (never the archive, never the ledger) —
 * and they say so. No tool here returns raw statement text: parsed rows only.
 */
import { at, bool, choice, date, fsPath, id, ids, limit, numericIds, offset, signedAmount, str, strings, tool, exactlyOne, invalid, object, array, dict } from './tool.js';
import type { Param, ToolDef } from './tool.js';

const ARCHIVE_READ_ONLY = 'The statement archive is the operator\'s audit evidence and is read-only: nothing here writes into it.';
const ROW_UNTRUSTED = ['description', 'internal_reference', 'memo', 'payee', 'name', 'label'];

/** A statements root: optional — the plane defaults to the configured one. */
const root = (): Param => fsPath('The statements root (absolute path). Default: the one configured for this install (ff_whoami shows it). The plane refuses any path outside a configured root.');

// ------------------------------------------------------ search and mirror ---

/** Upstream paths the plane's mirror refuses — mirrored here so the model gets a better message first (T4). */
export const MIRROR_DENIED_PREFIXES = ['users', 'configuration', 'cron', 'data/export', 'data/destroy', 'data/purge'] as const;

export function checkMirrorPath(path: string): void {
  if (!/^[A-Za-z0-9][A-Za-z0-9/_.-]*$/.test(path) || path.includes('..') || path.includes('//')) {
    throw invalid('path must be a relative /api/v1 path such as "insight/expense/category" — letters, digits, / _ . - only, no "..", no scheme, no leading slash');
  }
  const p = path.replace(/^api\/v1\//, '').toLowerCase();
  for (const denied of MIRROR_DENIED_PREFIXES) {
    if (p === denied || p.startsWith(`${denied}/`)) {
      throw invalid(`the mirror refuses ${denied}/* — users, configuration, cron and data exports are not readable from an agent`, 'use a typed ff_ tool; exports are ff_export_transactions');
    }
  }
}

const search: ToolDef[] = [
  tool({
    name: 'ff_search',
    tier: 'read',
    route: { method: 'GET', path: '/search' },
    params: {
      query: at.required(str('A query in Firefly\'s own search language, e.g. description_contains:"whole foods" amount_more:50 date_after:2026-01-01.', { max: 1000 })),
      limit: limit(),
      offset: offset(),
    },
    what: 'Firefly III\'s own search language over transactions — the search bar\'s operators — echoing which operators it parsed and which words it treated as free text.',
    instead: 'The last resort: call ff_describe_search_operators first, and prefer a typed tool (ff_list_transactions, ff_list_uncategorized) whenever one answers the question.',
    extra: 'Check the parsed operators in the reply: a misspelt operator silently becomes a text search.',
    untrusted: ['description', 'notes', 'internal_reference', 'source_name', 'destination_name', 'tags'],
  }),
  tool({
    name: 'ff_describe_search_operators',
    tier: 'read',
    route: { method: 'GET', path: '/search/operators' },
    what: 'Every operator Firefly III\'s search accepts, its argument type and an example, generated from Firefly\'s own operator table.',
    instead: 'Call this BEFORE ff_search so the query runs the first time.',
  }),
  tool({
    name: 'ff_get_upstream',
    tier: 'read',
    route: { method: 'GET', path: '/mirror/{path}' },
    params: {
      path: at.path(str('The upstream /api/v1 path to GET, relative, e.g. "insight/expense/category" or "autocomplete/accounts".', { max: 300 })),
      params: dict('Query arguments for the upstream route, as strings, e.g. {"start": "2026-01-01", "end": "2026-03-31"}.'),
    },
    refine: (args) => checkMirrorPath(String(args.path)),
    resolvePath: (args) => `/mirror/${String(args.path).split('/').map(encodeURIComponent).join('/')}`,
    what: 'Any GET route of Firefly III\'s standard /api/v1, read-only, run in-process as the operator and wrapped in this server\'s envelope.',
    instead: 'The last resort when no typed tool fits (try ff_list_transactions, ff_search, the analytics tools first); its answers are upstream\'s JSON:API shape with no provenance and no without_category filter.',
    untrusted: ['description', 'notes', 'name', 'title'],
  }),
];

// ------------------------------------------------------ statements, read ---

const ingest: ToolDef[] = [
  tool({
    name: 'ff_get_statement_manifest',
    tier: 'read',
    route: { method: 'GET', path: '/ingest/manifest' },
    params: { root: root(), manifest_path: fsPath('The manifest, relative to the root (default: the one the plane finds).') },
    what: 'The bank-statement archive\'s manifest: prepared or raw mode, every account with entity, institution, last-4, kind and coverage, the counts, and the archive\'s own warnings.',
    instead: 'Call this FIRST in any import conversation: in a prepared archive there is nothing to extract, so do not reach for ff_extract_statements.',
    extra: ARCHIVE_READ_ONLY,
    untrusted: ['label', 'warnings'],
  }),
  tool({
    name: 'ff_scan_statements',
    tier: 'read',
    route: { method: 'POST', path: '/ingest/scan' },
    params: {
      root: at.body(root()),
      mode: at.body(choice(['prepared', 'raw'] as const, 'The archive mode (default: what the manifest says).')),
      entity: at.body(str('Only this entity (e.g. household).', { max: 100 })),
      institution: at.body(str('Only this bank.', { max: 100 })),
      account: at.body(str('Only this account (its archive label).', { max: 200 })),
      year: at.body(str('Only this year, YYYY.', { max: 4 })),
    },
    what: 'The statement tree grouped per account-month: missing months, duplicate scans of the same statement, and unreadable files.',
    instead: 'For just the gaps use ff_list_missing_statements; for what was collapsed as a duplicate, ff_list_statement_duplicates.',
    extra: ARCHIVE_READ_ONLY,
    untrusted: ['label'],
  }),
  tool({
    name: 'ff_list_missing_statements',
    tier: 'read',
    route: { method: 'GET', path: '/ingest/coverage' },
    params: { root: root(), account: str('Only this account (its archive label).', { max: 200 }) },
    what: 'Which account-months have no statement in the archive — just the gaps.',
    instead: 'For the whole grouped tree use ff_scan_statements instead.',
    extra: ARCHIVE_READ_ONLY,
  }),
  tool({
    name: 'ff_list_statement_duplicates',
    tier: 'read',
    route: { method: 'GET', path: '/ingest/dupes' },
    params: { root: root() },
    what: 'What both de-duplication layers collapsed — identical scans, superseded statements, repeated rows — and the rule that decided each; conflicts name both files.',
    instead: 'For the whole grouped tree use ff_scan_statements. Nothing marks or merges duplicates: when two statements for one account-month disagree it is a conflict — show the operator both files and ask which is correct; do not choose.',
    extra: ARCHIVE_READ_ONLY,
    untrusted: ROW_UNTRUSTED,
  }),
  tool({
    name: 'ff_get_statement_rows',
    tier: 'read',
    route: { method: 'GET', path: '/ingest/rows' },
    params: {
      root: root(),
      account: at.required(str('The account (its archive label).', { max: 200 })),
      start: date('First day.'),
      end: date('Last day.'),
      limit: limit(),
    },
    what: 'The parsed statement rows for one account and range, each with its deterministic external_id — never the raw statement text.',
    instead: 'For what an import would do with them use ff_plan_statement_import instead.',
    extra: ARCHIVE_READ_ONLY,
    untrusted: ROW_UNTRUSTED,
  }),
  tool({
    name: 'ff_describe_statement_map',
    tier: 'read',
    route: { method: 'GET', path: '/ingest/map' },
    params: { root: root() },
    what: 'The statements-to-Firefly-account map and which archive accounts are still unmapped.',
    instead: 'To create the missing accounts and complete the map use ff_plan_accounts, then ff_apply_accounts.',
    untrusted: ['label', 'name'],
  }),
  tool({
    name: 'ff_extract_statements',
    tier: 'read',
    route: { method: 'POST', path: '/ingest/extract' },
    long: true,
    params: { root: at.body(root()), force: at.body(bool('Re-extract statements already staged.')) },
    what: 'Raw mode only: extracts statement PDFs into rows in the plane\'s staging directory ({ROOT}/.firefly-staging/) — it writes that rebuildable directory and never the ledger.',
    instead: 'Check ff_get_statement_manifest first: in a prepared archive this is twenty minutes spent re-deriving what the bank\'s own ids already give you.',
    extra: ARCHIVE_READ_ONLY,
  }),
  tool({
    name: 'ff_plan_accounts',
    tier: 'read',
    route: { method: 'POST', path: '/ingest/accounts/plan' },
    params: {
      root: at.body(root()),
      naming: at.body(str('Override the plane\'s naming template (default "{Entity} · {Institution} {Kind} ••{last4}").', { max: 200 })),
      liability_kinds: at.body(strings('Which manifest kinds become liabilities (default loan, mortgage).')),
    },
    what: 'Per manifest row: create, link, skip or ambiguous, with the reasoning, the proposed name, Firefly type and role, and a confirm_token for ff_apply_accounts — it creates nothing.',
    instead: 'Then STOP: show every ambiguous row by name and ask (two entities sharing a last-4 is normal), read out every liability and brokerage account, and never propose names of your own; only then ff_apply_accounts.',
    untrusted: ['label', 'name'],
  }),
  tool({
    name: 'ff_plan_statement_import',
    tier: 'read',
    route: { method: 'POST', path: '/ingest/plan' },
    params: {
      root: at.body(root()),
      accounts: at.body(strings('Only these accounts (archive labels or Firefly account ids); default all mapped accounts.')),
      start: at.body(date('First day.')),
      end: at.body(date('Last day.')),
      apply_rules: at.body(bool('Run Firefly\'s rules in the preview (default true).')),
    },
    what: 'Firefly\'s own dry run of an import, per account: new, duplicate of an existing group, or duplicate of a DELETED group (which stays deleted), plus what the rules would categorise, and a confirm_token.',
    instead: 'Then STOP and show the plan per account; only with the operator\'s yes, ff_apply_statement_import with the token.',
    extra: 'Firefly\'s duplicate hash decides what already exists — not this server, and not you. ' + ARCHIVE_READ_ONLY,
    untrusted: ROW_UNTRUSTED,
  }),
  tool({
    name: 'ff_plan_file_import',
    tier: 'read',
    route: { method: 'POST', path: '/ingest/file/plan' },
    params: {
      account_id: at.body(at.required(id('The Firefly account to import into.'))),
      path: at.body(at.required(fsPath('The statement file (OFX, QFX, CSV or camt.053), inside the statements root.'))),
    },
    what: 'One statement file into one Firefly account, previewed by Firefly\'s own store-and-roll-back: new rows, duplicates, and a confirm_token.',
    instead: 'For a whole archive use ff_plan_statement_import instead; apply this one with ff_apply_file_import.',
    extra: ARCHIVE_READ_ONLY,
    untrusted: ROW_UNTRUSTED,
  }),
];

// ------------------------------------------------------ previews for writes ---

/** Firefly's rule shape (upstream's /api/v1/rules), for a preview or a new rule. */
export function ruleObject(): Param {
  const trigger = object('One trigger.', {
    type: at.required(str('Trigger type, e.g. description_contains, amount_more, source_account_is.', { max: 60 })),
    value: at.required(str('What it matches.', { max: 1000 })),
    prohibited: bool('true inverts the trigger.'),
    active: bool('Default true.'),
    stop_processing: bool('Stop evaluating further triggers when this one matches.'),
  });
  const action = object('One action.', {
    type: at.required(str('Action type, e.g. set_category, set_budget, add_tag.', { max: 60 })),
    value: str('Its argument (a category name, a tag…).', { max: 1000 }),
    active: bool('Default true.'),
    stop_processing: bool('Stop evaluating further actions after this one.'),
  });
  return object('A Firefly rule, unsaved.', {
    title: at.required(str('The rule\'s title.', { max: 255 })),
    rule_group_id: id('The rule group it belongs to.'),
    rule_group_title: str('Or the rule group by title.', { max: 255 }),
    trigger: choice(['store-journal', 'update-journal'] as const, 'When it fires (default store-journal: on import and entry).'),
    active: bool('Default true.'),
    strict: bool('true: ALL triggers must match; false: ANY.'),
    stop_processing: bool('Stop processing later rules in the group when this one fires.'),
    description: str('What the rule is for.', { max: 1000 }),
    triggers: at.required(array('The triggers.', trigger, { min: 1, max: 50 })),
    actions: at.required(array('The actions.', action, { min: 1, max: 50 })),
  });
}

const previews: ToolDef[] = [
  tool({
    name: 'ff_preview_rule',
    tier: 'read',
    route: { method: 'POST', path: '/rules/preview' },
    params: {
      rule: at.body(ruleObject()),
      rule_id: at.body(id('Or an existing rule, to see what running it would change.')),
      start: at.body(date('First day of the journals to test against.')),
      end: at.body(date('Last day.')),
      account_ids: at.body(ids('Only journals on these accounts.')),
      limit: at.body(limit('How many matched journals to return.')),
    },
    refine: exactlyOne('rule', 'rule_id'),
    what: 'Which existing journals a rule (unsaved, or an existing one) would match and what each action would change — Firefly\'s own rule test.',
    instead: 'Run this and show the operator the match count and a sample BEFORE ff_add_rule or ff_run_rules.',
    untrusted: ['description', 'notes', 'title'],
  }),
  tool({
    name: 'ff_plan_reconcile',
    tier: 'read',
    route: { method: 'POST', path: '/accounts/{id}/reconcile/plan' },
    params: {
      id: at.path(id('The asset or liability account to reconcile.')),
      start: at.body(at.required(date('Statement start date.'))),
      end: at.body(at.required(date('Statement end date.'))),
      target_balance: at.body(at.required(signedAmount('The closing balance printed on the statement.'))),
      journal_ids: at.body(numericIds('The journals the operator ticked (default: every uncleared journal in range).')),
    },
    what: 'Firefly\'s reconcile screen as numbers: start and end balance, the sum of the selected journals, the operator\'s statement figure and the signed difference, plus a confirm_token for ff_apply_reconcile.',
    instead: 'If the difference is not zero, help find it (uncleared rows, a missing or duplicate transaction) before offering ff_apply_reconcile.',
  }),
  tool({
    name: 'ff_preview_undo',
    tier: 'read',
    route: { method: 'GET', path: '/undo/last' },
    what: 'What the last write made through this plane (this server or the ffx CLI) did, what undoing it would do, whether a browser edit since blocks it, and the confirm_token for ff_undo.',
    instead: 'Call this before ff_undo and tell the operator what will be undone.',
  }),
];

export const STATEMENT_TOOLS: ToolDef[] = [...search, ...ingest, ...previews];
