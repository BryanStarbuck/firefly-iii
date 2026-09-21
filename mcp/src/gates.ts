/**
 * The six gates — pm/mcp.mdx §7.1 — and the checks that ride with them.
 *
 *   1 transport   stdio only; this process binds no port             (index.ts)
 *   2 key         read at startup from a 0600 file, or no start      (index.ts)
 *   3 target      loopback, or https + FFMCP_ALLOW_REMOTE=1 (r/o)     (config.ts)
 *   4 mode        writes need FFMCP_ALLOW_WRITE=1 here (and the plane's switch there)
 *   5 input       the hand-authored schema, then zod; limits CLAMPED, never rejected
 *   6 plane       the machine plane's own ladder, which trusts 1–5 not at all
 *
 * Plus the three refusals minted here and never on the wire (§12.3):
 * wrong_server (a foreign-shaped id, §3.4 layer 6), confirm_required (a write
 * with no echo) and too_many_changes (this side's ceiling).
 */
import type { z } from 'zod';

import type { McpConfig } from './config.js';
import { fail, WRITE_SWITCHES_HINT } from './errors.js';
import type { ToolDef } from './tools/tool.js';

// ------------------------------------------------ §3.4 layer 6: wrong_server ---

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** QuickBooks realm (company) ids are 15–20 digit numbers; no Firefly id is that long. */
const QB_REALM = /^\d{15,20}$/;
/** An invoice number: INV-1001, Invoice #1001, inv_1001. */
const QB_INVOICE = /^(inv|invoice)[\s#:_-]*\d+$/i;
/** Argument names that only mean something in company bookkeeping. */
const QB_KEYS = /^(realm_?id|realmid|company_?id|invoice_?(id|number|no|num)|doc_?number|vendor_?id|customer_?id|journal_?entry_?id|qbo_?\w*)$/i;
/** Argument names that only mean something in Actual Budget. */
const AB_KEYS = /^(\w*_uuid|uuid|budget_?file_?id|sync_?id|cloud_?file_?id)$/i;
/** Where a Firefly id belongs. */
const ID_KEY = /(^|_)ids?$/i;
/** Id-named fields that legitimately carry someone else's identifier (a bank's FITID can be UUID-shaped). */
const NOT_FIREFLY_IDS = new Set(['external_id', 'internal_reference']);

export interface ForeignHit {
  server: 'actual_budget' | 'quickbooks';
  why: string;
}

function classify(key: string, value: unknown): ForeignHit | undefined {
  if (QB_KEYS.test(key)) return { server: 'quickbooks', why: `"${key}" is a QuickBooks argument` };
  if (AB_KEYS.test(key)) return { server: 'actual_budget', why: `"${key}" is an Actual Budget argument` };
  if (!ID_KEY.test(key) || NOT_FIREFLY_IDS.has(key)) return undefined;
  const values = Array.isArray(value) ? value : [value];
  for (const v of values) {
    if (typeof v === 'number' && Number.isInteger(v) && v >= 1e14) return { server: 'quickbooks', why: 'a 15+ digit number is a QuickBooks realm id' };
    if (typeof v !== 'string') continue;
    const s = v.trim();
    if (UUID.test(s)) return { server: 'actual_budget', why: 'a UUID is an Actual Budget id' };
    if (QB_REALM.test(s)) return { server: 'quickbooks', why: 'a 15+ digit number is a QuickBooks realm id' };
    if (QB_INVOICE.test(s)) return { server: 'quickbooks', why: 'an invoice number is QuickBooks\' vocabulary' };
  }
  return undefined;
}

export function findForeign(args: unknown, depth = 0): ForeignHit | undefined {
  if (depth > 6 || args === null || typeof args !== 'object') return undefined;
  if (Array.isArray(args)) {
    for (const item of args) {
      const hit = findForeign(item, depth + 1);
      if (hit) return hit;
    }
    return undefined;
  }
  for (const [k, v] of Object.entries(args as Record<string, unknown>)) {
    const hit = classify(k, v) ?? findForeign(v, depth + 1);
    if (hit) return hit;
  }
  return undefined;
}

/** Refuse-with-redirect. Names a SERVER, never a tool to call (§7.5). */
export function checkNotForeign(args: unknown): void {
  const hit = findForeign(args);
  if (!hit) return;
  if (hit.server === 'actual_budget') {
    throw fail(
      'wrong_server',
      `That is not a Firefly III id: ${hit.why}. Firefly ids are positive integers.`,
      'This server is the operator\'s Firefly III install. For their Actual Budget install use the `actual_budget` server (its tools start with ab_) — or ask the operator which app they mean.',
    );
  }
  throw fail(
    'wrong_server',
    `That is not a Firefly III id: ${hit.why}. Firefly ids are positive integers.`,
    'This server is the operator\'s own Firefly III ledger. Company bookkeeping — invoices, vendors, customers, realm ids — is the `quickbooks` server.',
  );
}

// ------------------------------------------------------------ gate 4: mode ---

export function checkMode(tool: ToolDef, cfg: McpConfig): void {
  if (tool.tier !== 'write') return;
  if (cfg.target === 'remote') {
    throw fail(
      'write_disabled',
      `The write tier is off: this server is pointed at a remote install (${cfg.apiUrl}), which is read-only whatever the switches say.`,
      'Writes happen only against the local install. Point FFMCP_API_URL at http://127.0.0.1:7373 and see the two switches: ' + WRITE_SWITCHES_HINT,
    );
  }
  if (!cfg.allowWrite) {
    throw fail('write_disabled', 'The write tier is off.', WRITE_SWITCHES_HINT);
  }
}

// ----------------------------------------------------------- gate 5: input ---

export interface Clamp {
  field: string;
  requested: number;
  applied: number;
}

function formatIssues(tool: ToolDef, issues: z.core.$ZodIssue[]): string {
  const known = Object.keys(tool.params);
  return issues
    .slice(0, 6)
    .map((issue) => {
      const where = issue.path.length ? issue.path.map(String).join('.') : '';
      if (issue.code === 'unrecognized_keys') {
        const keys = (issue as { keys?: string[] }).keys ?? [];
        return `unknown argument${keys.length > 1 ? 's' : ''} ${keys.map((k) => `"${k}"`).join(', ')}${where ? ` in ${where}` : ''} — ${tool.name} takes: ${known.join(', ') || '(no arguments)'}`;
      }
      if (issue.code === 'invalid_type' && issue.message.toLowerCase().includes('undefined')) {
        return `${where || 'argument'} is required`;
      }
      return where ? `${where}: ${issue.message}` : issue.message;
    })
    .join('; ');
}

export function checkInput(tool: ToolDef, args: unknown, cfg: McpConfig): { args: Record<string, unknown>; clamps: Clamp[] } {
  const raw = args === undefined || args === null ? {} : args;
  if (typeof raw !== 'object' || Array.isArray(raw)) throw fail('invalid_input', 'arguments must be a JSON object');
  const parsed = tool.zod.safeParse(raw);
  if (!parsed.success) {
    throw fail('invalid_input', formatIssues(tool, parsed.error.issues), `the schema in tools/list for ${tool.name} is exact — send decimal strings for money and YYYY-MM-DD dates`);
  }
  const out = { ...(parsed.data as Record<string, unknown>) };
  const clamps: Clamp[] = [];
  for (const [name, p] of Object.entries(tool.params)) {
    const v = out[name];
    if (p.kind === 'limit' && typeof v === 'number' && v > cfg.maxRows) {
      clamps.push({ field: name, requested: v, applied: cfg.maxRows });
      out[name] = cfg.maxRows;
    }
    if (p.kind === 'fspath' && typeof v === 'string') checkContained(name, v);
  }
  tool.refine?.(out);
  return { args: out, clamps };
}

/** T6: a statements path never climbs. The plane contains it with realpath(); this is the better message first. */
export function checkContained(name: string, value: string): void {
  if (value.includes('\0') || value.split(/[\\/]+/).includes('..')) {
    throw fail('forbidden', `${name} must stay inside the statements root — ".." is refused`, 'pass a path inside the configured statements root (ff_whoami shows it)');
  }
}

// ------------------------------------------------ the confirm echo (§9.7) ---

export function checkConfirm(tool: ToolDef, args: Record<string, unknown>): void {
  const w = tool.write;
  if (!w || !w.confirm) return;
  const confirm = args.confirm;
  const has = typeof confirm === 'string' && confirm.length > 0;
  if (has) return;
  if (w.tokenRequired === 'always') {
    throw fail(
      'confirm_required',
      `${tool.name} applies a plan, and needs that plan's confirm_token as \`confirm\`.`,
      `Call ${w.planTool} first, show the operator what it says, and pass its confirm_token as confirm once they say yes.`,
    );
  }
  if (w.dryRun && args.dry_run === false) {
    throw fail(
      'confirm_required',
      `dry_run: false needs \`confirm\` — the confirm_token from a dry run of ${w.planTool}.`,
      `Call ${w.planTool} with dry_run: true (the default), show the operator the result, and pass its confirm_token as confirm once they say yes.`,
    );
  }
}

// -------------------------------------------------- the ceiling (§9.7, T2) ---

/** Counts that are not changes. */
const NOT_CHANGES = new Set(['unchanged', 'duplicates', 'duplicate', 'skipped', 'ambiguous', 'conflicts', 'deleted_duplicates', 'previously_deleted']);
const COUNT_FIELDS = ['change_count', 'changes_total', 'total_changes', 'would_change'];
/** In a refusal's details, a bare `count` is the count (apis.mdx §5.4: "1,904 transactions would change"). */
const DETAIL_COUNT_FIELDS = [...COUNT_FIELDS, 'count', 'real_count'];

function isCount(v: unknown): v is number {
  return typeof v === 'number' && Number.isInteger(v) && v >= 0;
}

/**
 * The number of changes the PLANE reported — read, never estimated. Counting
 * rows is not money arithmetic; when the plane names a total it wins.
 */
export function reportedChangeCount(source: unknown, fromDetails = false): number | undefined {
  if (!source || typeof source !== 'object') return undefined;
  const o = source as Record<string, unknown>;
  for (const f of fromDetails ? DETAIL_COUNT_FIELDS : COUNT_FIELDS) {
    if (isCount(o[f])) return o[f];
  }
  const changes = o.changes;
  if (changes && typeof changes === 'object' && !Array.isArray(changes)) {
    let total = 0;
    let any = false;
    for (const [k, v] of Object.entries(changes as Record<string, unknown>)) {
      if (NOT_CHANGES.has(k) || !isCount(v)) continue;
      total += v;
      any = true;
    }
    return any ? total : undefined;
  }
  if (isCount(changes)) return changes;
  return undefined;
}

export function tooManyChanges(tool: ToolDef, count: number, ceiling: number) {
  return fail(
    'too_many_changes',
    `${tool.name} would make ${count} changes, over the ceiling of ${ceiling}.`,
    `Raise max_changes to at least ${count} deliberately (with the operator's knowledge), or narrow the selection — a shorter date range, fewer accounts, named journal ids.`,
    { count, max_changes: ceiling },
  );
}
