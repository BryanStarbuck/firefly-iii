/**
 * Arguments → one machine-plane request. Mechanical, and nothing else:
 * path placeholders filled, each argument put where its route takes it, and an
 * id that is a NAME sent under its `*_name` twin so the PLANE resolves it
 * (apis.mdx §8 — "ids are the contract; names are a convenience, and the
 * server, never the client, does the resolving").
 */
import type { CallSpec, Query } from './client.js';
import type { McpConfig } from './config.js';
import type { ToolDef } from './tools/tool.js';

/** The id arguments that accept a name instead, and the name each becomes. */
const NAME_TWIN: Record<string, string> = {
  account_id: 'account_name',
  account_ids: 'account_names',
  category_id: 'category_name',
  category_ids: 'category_names',
  budget_id: 'budget_name',
  budget_ids: 'budget_names',
  bill_id: 'bill_name',
  rule_group_id: 'rule_group_title',
  rule_ids: 'rule_names',
  source_id: 'source_name',
  destination_id: 'destination_name',
  counterparty_ids: 'counterparty_names',
};

const NUMERIC = /^[1-9]\d*$/;

function isNumericId(v: unknown): boolean {
  return (typeof v === 'number' && Number.isInteger(v) && v > 0) || (typeof v === 'string' && NUMERIC.test(v));
}

/** Rewrite name-valued ids into their *_name twins, recursively. */
export function twinNames(value: unknown, depth = 0): unknown {
  if (depth > 6) return value;
  if (Array.isArray(value)) return value.map((v) => twinNames(v, depth + 1));
  if (!value || typeof value !== 'object') return value;
  const out: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(value as Record<string, unknown>)) {
    const twin = NAME_TWIN[k];
    if (twin && typeof v === 'string' && !isNumericId(v)) {
      out[twin] = v;
    } else if (twin && Array.isArray(v) && v.some((x) => !isNumericId(x))) {
      const ids = v.filter(isNumericId);
      const names = v.filter((x) => !isNumericId(x));
      if (ids.length) out[k] = ids;
      out[twin] = names;
    } else {
      out[k] = twinNames(v, depth + 1);
    }
  }
  return out;
}

export interface BuiltRequest extends CallSpec {
  /** True when the request is a dry run (so the ceiling applies to what comes back). */
  dryRun: boolean;
  /** The ceiling in force for this call. */
  ceiling: number;
}

export function buildRequest(tool: ToolDef, args: Record<string, unknown>, cfg: McpConfig): BuiltRequest {
  const route = tool.resolvePath(args);
  const query: Record<string, unknown> = { ...(tool.fixedQuery ?? {}) };
  const body: Record<string, unknown> = {};
  for (const [name, p] of Object.entries(tool.params)) {
    const v = args[name];
    if (v === undefined || p.loc === 'path') continue;
    if (name === 'max_changes' || name === 'dry_run') continue; // the write protocol places these
    if (p.kind === 'dict') {
      Object.assign(query, v as Record<string, unknown>);
      continue;
    }
    (p.loc === 'body' ? body : query)[p.wire ?? name] = v;
  }
  const w = tool.write;
  let dryRun = false;
  const ceiling = typeof args.max_changes === 'number' ? args.max_changes : cfg.maxChanges;
  if (w) {
    if (w.dryRun) {
      dryRun = args.dry_run !== false;
      body.dry_run = dryRun;
    }
    if (w.sendsMaxChanges) body.max_changes = ceiling;
  }
  const isBodyMethod = tool.route.method !== 'GET';
  return {
    method: tool.route.method,
    route,
    query: twinNames(query) as Query,
    body: isBodyMethod ? twinNames(body) : undefined,
    timeoutMs: tool.long ? null : cfg.timeoutMs,
    dryRun,
    ceiling,
  };
}
