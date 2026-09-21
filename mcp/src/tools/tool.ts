/**
 * The tool shape and the parameter vocabulary — pm/mcp.mdx §8.2, §9.
 *
 * A tool validates its arguments, makes ONE machine-plane call, and returns
 * Firefly's answer. It does not sum, filter, sort, round, convert, cache, or
 * decide anything a reasonable person could disagree with (§9.4). If a tool
 * seems to need computation, the computation becomes a route in apis.mdx first.
 *
 * Every parameter is declared ONCE, through the helpers below, and each helper
 * produces BOTH halves: the hand-authored JSON Schema the model reads (§8.2 —
 * those words are the product) and the zod schema gate 5 parses with. One
 * declaration, so the two can never disagree about what a field accepts.
 */
import { z } from 'zod';

import { fail } from '../errors.js';
import type { ToolError } from '../errors.js';

export type Tier = 'read' | 'write';
export type Loc = 'path' | 'query' | 'body';
export type Kind = 'plain' | 'id' | 'ids' | 'amount' | 'signed' | 'limit' | 'fspath' | 'dict';

export interface Param {
  json: Record<string, unknown>;
  zod: z.ZodType;
  loc: Loc;
  required: boolean;
  kind: Kind;
  /** Name on the wire when it differs from the argument name. */
  wire?: string | undefined;
}

export interface RouteRef {
  method: 'GET' | 'POST' | 'PUT';
  /** The route PATTERN under /machine/v1, exactly as apis.mdx writes it. */
  path: string;
}

export interface WriteSpec {
  /** false only for ff_undo and ff_trigger_recurrence (§9.6) — their schemas offer no dry_run. */
  dryRun: boolean;
  /** Whether the call carries a `confirm` echo at all (ff_trigger_recurrence has nothing to echo). */
  confirm: boolean;
  /**
   * 'apply' — a token is needed only for dry_run:false (the tool's own dry run mints it).
   * 'always' — the route applies a plan made by ANOTHER tool, so even its dry run needs that token.
   */
  tokenRequired: 'apply' | 'always' | 'never';
  /** The tool whose output carries the token, named in confirm_required. */
  planTool: string;
  /**
   * The route accepts `max_changes`. Every dry-run write route does — the plane accepts the four
   * control fields (dry_run, confirm_token, max_changes, idempotency_key) on all of them and
   * enforces the ceiling itself with the real count; the MCP-side check is the second wall.
   */
  sendsMaxChanges: boolean;
  /** Whether a max_changes argument is offered at all. */
  ceiling: boolean;
}

export interface ToolDef {
  name: string;
  description: string;
  /** The four clauses the description is assembled from (§9.2), kept for the catalogue test. */
  clauses: { what: string; cost: string; instead: string; server: string };
  inputSchema: Record<string, unknown>;
  tier: Tier;
  route: RouteRef;
  params: Record<string, Param>;
  write: WriteSpec | undefined;
  /** Exempt from FFMCP_TIMEOUT_MS (ingest apply and extract, §14). */
  long: boolean;
  /** Field names in `data` carrying bank / merchant / operator text (§7.5). */
  untrusted: string[];
  /** Fixed query arguments the tool always sends (ff_list_uncategorized). */
  fixedQuery: Record<string, string | boolean> | undefined;
  zod: z.ZodType;
  /** Cross-field rules zod cannot say well; throws invalid_input. */
  refine: ((args: Record<string, unknown>) => void) | undefined;
  /** Concrete route for a call; the default fills {placeholders} from path params. */
  resolvePath: (args: Record<string, unknown>) => string;
}

// ------------------------------------------------------------ the clauses ---

/** Clause 2 for the fifty-nine read tools (§9.2). */
export const READS_ONLY = 'Reads only.';

/** Clause 2 for the eighteen write tools (§9.2), verbatim. */
export const WRITES =
  "WRITES to the operator's real Firefly III ledger. Dry run by default; applying needs the confirm token from the dry run.";

/** Clause 2 for the two writes that have no dry run — still verbatim first, then the truth about them. */
export const WRITES_NO_DRY_RUN = `${WRITES} This tool is one of the two exceptions: it has no dry_run argument, so its preview is the separate read tool named next.`;

/** Clause 4, on every tool (§9.2), verbatim. */
export const WHICH_SERVER =
  "This server is the operator's OWN Firefly III install on this computer — not Actual Budget (`actual_budget`), not company bookkeeping (`quickbooks`).";

export interface DescribeInput {
  /** Clause 1 — what it does, in Firefly's language, one sentence. */
  what: string;
  tier: Tier;
  noDryRun?: boolean;
  /** Clause 3 — the sibling to use instead / first. */
  instead: string;
  /** Anything else the model must know (the conflict rule, the archive is read-only…). */
  extra?: string | undefined;
}

export function costClause(tier: Tier, noDryRun = false): string {
  return tier === 'read' ? READS_ONLY : noDryRun ? WRITES_NO_DRY_RUN : WRITES;
}

export function describe(d: DescribeInput): string {
  return [d.what, costClause(d.tier, d.noDryRun), d.instead, ...(d.extra ? [d.extra] : []), WHICH_SERVER].join(' ');
}

// ------------------------------------------------------------- the params ---

export const AMOUNT_PATTERN = '^\\d+(\\.\\d+)?$';
export const SIGNED_PATTERN = '^-?\\d+(\\.\\d+)?$';
export const DATE_PATTERN = '^\\d{4}-\\d{2}-\\d{2}$';
const AMOUNT_RE = new RegExp(AMOUNT_PATTERN);
const SIGNED_RE = new RegExp(SIGNED_PATTERN);

interface Opts {
  loc?: Loc;
  required?: boolean;
  wire?: string;
}

function param(json: Record<string, unknown>, zod: z.ZodType, kind: Kind, o: Opts = {}): Param {
  return { json, zod, kind, loc: o.loc ?? 'query', required: o.required ?? false, wire: o.wire };
}

export function str(description: string, o: Opts & { max?: number } = {}): Param {
  const max = o.max ?? 500;
  return param({ type: 'string', minLength: 1, maxLength: max, description }, z.string().min(1).max(max), 'plain', o);
}

export function text(description: string, o: Opts = {}): Param {
  return param({ type: 'string', maxLength: 4000, description }, z.string().max(4000), 'plain', o);
}

export function bool(description: string, o: Opts = {}): Param {
  return param({ type: 'boolean', description }, z.boolean(), 'plain', o);
}

export function int(description: string, o: Opts & { min?: number; max?: number } = {}): Param {
  const json: Record<string, unknown> = { type: 'integer', description };
  let zod = z.number().int();
  if (o.min !== undefined) {
    json.minimum = o.min;
    zod = zod.min(o.min);
  }
  if (o.max !== undefined) {
    json.maximum = o.max;
    zod = zod.max(o.max);
  }
  return param(json, zod, 'plain', o);
}

export function choice<const T extends readonly [string, ...string[]]>(values: T, description: string, o: Opts = {}): Param {
  return param({ type: 'string', enum: [...values], description }, z.enum(values), 'plain', o);
}

export function date(description: string, o: Opts = {}): Param {
  return param(
    { type: 'string', pattern: DATE_PATTERN, description: `${description} As YYYY-MM-DD (inclusive); resolve "last month" yourself and say which dates you used.` },
    z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'a date as YYYY-MM-DD — no relative dates on the wire'),
    'plain',
    o,
  );
}

/** A Firefly id — a positive integer — or the exact name, which the PLANE resolves (apis.mdx §8). */
export function id(description: string, o: Opts = {}): Param {
  return param(
    {
      type: ['integer', 'string'],
      minimum: 1,
      minLength: 1,
      maxLength: 200,
      description: `${description} A Firefly id (a positive integer), or the exact name, which Firefly resolves — an ambiguous name comes back as invalid_input with the candidates.`,
    },
    z.union([z.number().int().positive(), z.string().min(1).max(200)]),
    'id',
    o,
  );
}

/** A strictly numeric Firefly id (journal ids, group ids — names mean nothing there). */
export function numericId(description: string, o: Opts = {}): Param {
  return param(
    { type: ['integer', 'string'], minimum: 1, pattern: '^[1-9]\\d{0,13}$', description: `${description} A Firefly id: a positive integer.` },
    z.union([z.number().int().positive(), z.string().regex(/^[1-9]\d{0,13}$/, 'a Firefly id is a positive integer')]),
    'id',
    o,
  );
}

export function ids(description: string, o: Opts = {}): Param {
  return param(
    {
      type: 'array',
      maxItems: 1000,
      items: { type: ['integer', 'string'], minimum: 1, minLength: 1, maxLength: 200 },
      description: `${description} Firefly ids (positive integers) or exact names.`,
    },
    z.array(z.union([z.number().int().positive(), z.string().min(1).max(200)])).max(1000),
    'ids',
    o,
  );
}

export function numericIds(description: string, o: Opts = {}): Param {
  return param(
    { type: 'array', maxItems: 5000, items: { type: ['integer', 'string'], minimum: 1, pattern: '^[1-9]\\d{0,13}$' }, description: `${description} Positive integers.` },
    z.array(z.union([z.number().int().positive(), z.string().regex(/^[1-9]\d{0,13}$/, 'ids are positive integers')])).max(5000),
    'ids',
    o,
  );
}

export function strings(description: string, o: Opts = {}): Param {
  return param(
    { type: 'array', maxItems: 200, items: { type: 'string', minLength: 1, maxLength: 200 }, description },
    z.array(z.string().min(1).max(200)).max(200),
    'plain',
    o,
  );
}

/** The runtime half of the money rule (§10.1): a number or a negative gets the string it meant. */
function amountZod(signed: boolean): z.ZodType {
  return z.unknown().superRefine((value, ctx) => {
    if (typeof value === 'number') {
      const meant = String(value);
      ctx.addIssue({
        code: 'custom',
        message: signed
          ? `money is a decimal STRING, never a JSON number — send "${meant}"`
          : value < 0
            ? `money is a positive decimal STRING, never a JSON number — send "${meant.replace(/^-/, '')}" and let the transaction type carry the direction (withdrawal = money out, deposit = money in)`
            : `money is a decimal STRING, never a JSON number — send "${meant}"`,
      });
      return;
    }
    if (typeof value !== 'string') {
      ctx.addIssue({ code: 'custom', message: 'money is a decimal string such as "12.50"' });
      return;
    }
    if (!signed && /^-\d+(\.\d+)?$/.test(value)) {
      ctx.addIssue({
        code: 'custom',
        message: `amounts are positive; direction is the type — send "${value.slice(1)}" with type withdrawal (money out) or deposit (money in), not "${value}"`,
      });
      return;
    }
    if (!(signed ? SIGNED_RE : AMOUNT_RE).test(value)) {
      ctx.addIssue({ code: 'custom', message: `"${value}" is not a decimal string — digits, an optional point and digits, e.g. "12.50"` });
    }
  });
}

export function amount(description: string, o: Opts = {}): Param {
  return param(
    {
      type: 'string',
      pattern: AMOUNT_PATTERN,
      description: `${description} A decimal string in the named currency, e.g. "12.50" — never a JSON number, and positive (direction is the transaction type).`,
    },
    amountZod(false),
    'amount',
    o,
  );
}

/** A balance, which may be negative (an overdrawn account, a credit card, a loan). */
export function signedAmount(description: string, o: Opts = {}): Param {
  return param(
    {
      type: 'string',
      pattern: SIGNED_PATTERN,
      description: `${description} A signed decimal string in the account's currency, e.g. "4211.08" or "-350.00" — never a JSON number.`,
    },
    amountZod(true),
    'signed',
    o,
  );
}

/** A decimal that is not money (a z-score, a ratio) — still a string, never a JSON number. */
export function decimal(description: string, o: Opts = {}): Param {
  return param(
    { type: 'string', pattern: AMOUNT_PATTERN, description: `${description} A decimal string, e.g. "2.0".` },
    z.string().regex(AMOUNT_RE, 'a decimal string such as "2.0"'),
    'plain',
    o,
  );
}

export function limit(description = 'How many rows to return.'): Param {
  return param(
    { type: 'integer', minimum: 1, description: `${description} Capped at this server's row cap (FFMCP_MAX_ROWS) — clamped, never rejected, and the clamp is reported in meta.` },
    z.number().int().min(1),
    'limit',
  );
}

export function offset(): Param {
  return int('Rows to skip — stable only with the same order.', { min: 0 });
}

export function order(fields: string): Param {
  return param(
    { type: 'string', pattern: '^-?[a-z_]{1,40}$', description: `Sort field, "-field" for descending: ${fields}. The plane always tie-breaks on id.` },
    z.string().regex(/^-?[a-z_]{1,40}$/),
    'plain',
  );
}

/** A path inside the statements tree. The plane contains it with realpath(); we refuse `..` first, for a better message. */
export function fsPath(description: string, o: Opts = {}): Param {
  return param({ type: 'string', minLength: 1, maxLength: 1024, description }, z.string().min(1).max(1024), 'fspath', o);
}

/** Free-form string arguments spread into the query (the mirror's upstream arguments). */
export function dict(description: string): Param {
  return param(
    { type: 'object', description, additionalProperties: { type: 'string', maxLength: 500 }, maxProperties: 30 },
    z.record(z.string().regex(/^[a-z_\[\]]{1,40}$/, 'argument names are lowercase words'), z.string().max(500)),
    'dict',
  );
}

export function object(description: string, props: Record<string, Param>, o: Opts = {}): Param {
  const properties: Record<string, unknown> = {};
  const shape: Record<string, z.ZodType> = {};
  const required: string[] = [];
  for (const [k, p] of Object.entries(props)) {
    properties[k] = p.json;
    shape[k] = p.required ? p.zod : p.zod.optional();
    if (p.required) required.push(k);
  }
  return param(
    { type: 'object', description, properties, ...(required.length ? { required } : {}), additionalProperties: false },
    z.strictObject(shape),
    'plain',
    o,
  );
}

export function array(description: string, item: Param, o: Opts & { min?: number; max?: number } = {}): Param {
  const min = o.min ?? 0;
  const max = o.max ?? 100;
  return param(
    { type: 'array', description, items: item.json, ...(min ? { minItems: min } : {}), maxItems: max },
    z.array(item.zod).min(min).max(max),
    'plain',
    o,
  );
}

/** Mark a param as living in the path / body / required. */
export const at = {
  path: (p: Param): Param => ({ ...p, loc: 'path', required: true }),
  body: (p: Param): Param => ({ ...p, loc: 'body' }),
  required: (p: Param): Param => ({ ...p, required: true }),
  wire: (p: Param, wire: string): Param => ({ ...p, wire }),
};

// ------------------------------------------------------------------ build ---

export interface ToolInput {
  name: string;
  tier: Tier;
  route: RouteRef;
  what: string;
  instead: string;
  extra?: string;
  params?: Record<string, Param>;
  write?: Partial<WriteSpec> & { planTool?: string };
  long?: boolean;
  untrusted?: string[];
  fixedQuery?: Record<string, string | boolean>;
  refine?: (args: Record<string, unknown>) => void;
  resolvePath?: (args: Record<string, unknown>) => string;
}

function defaultResolvePath(pattern: string): (args: Record<string, unknown>) => string {
  return (args) =>
    pattern.replace(/\{([a-z_]+)\}/g, (_, name: string) => {
      const v = args[name];
      if (v === undefined || v === null || v === '') throw fail('invalid_input', `missing ${name}`);
      return encodeURIComponent(String(v));
    });
}

export function tool(t: ToolInput): ToolDef {
  const params: Record<string, Param> = { ...(t.params ?? {}) };
  let write: WriteSpec | undefined;
  if (t.tier === 'write') {
    write = {
      dryRun: t.write?.dryRun ?? true,
      confirm: t.write?.confirm ?? true,
      tokenRequired: t.write?.tokenRequired ?? 'apply',
      planTool: t.write?.planTool ?? t.name,
      sendsMaxChanges: t.write?.sendsMaxChanges ?? (t.write?.dryRun ?? true),
      ceiling: t.write?.ceiling ?? true,
    };
    if (write.dryRun) {
      params.dry_run = at.body(bool('true (the default) changes nothing and returns what would happen plus a confirm_token; false applies — and needs `confirm`.'));
    }
    if (write.confirm) {
      params.confirm = at.body(
        at.wire(
          str(
            write.tokenRequired === 'always'
              ? `The confirm_token from ${write.planTool}. Required even for a dry run of this tool — it applies that plan, and only that plan. You cannot invent it.`
              : `The confirm_token this tool's own dry run returned. Required with dry_run: false; you cannot invent it. Expires in ten minutes, single use.`,
            { max: 200 },
          ),
          'confirm_token',
        ),
      );
    }
    if (write.ceiling) {
      params.max_changes = at.body(
        int('The most changes this call may make (default FFMCP_MAX_CHANGES, 200). Over it the call is refused with the real count — raise it deliberately, or narrow the selection.', { min: 1 }),
      );
    }
  }
  const properties: Record<string, unknown> = {};
  const shape: Record<string, z.ZodType> = {};
  const required: string[] = [];
  for (const [k, p] of Object.entries(params)) {
    properties[k] = p.json;
    shape[k] = p.required ? p.zod : p.zod.optional();
    if (p.required) required.push(k);
  }
  const inputSchema: Record<string, unknown> = {
    type: 'object',
    properties,
    ...(required.length ? { required } : {}),
    additionalProperties: false,
  };
  const noDryRun = write !== undefined && !write.dryRun;
  return {
    name: t.name,
    description: describe({ what: t.what, tier: t.tier, noDryRun, instead: t.instead, extra: t.extra }),
    clauses: { what: t.what, cost: costClause(t.tier, noDryRun), instead: t.instead, server: WHICH_SERVER },
    inputSchema,
    tier: t.tier,
    route: t.route,
    params,
    write,
    long: t.long ?? false,
    untrusted: t.untrusted ?? [],
    fixedQuery: t.fixedQuery,
    zod: z.strictObject(shape),
    refine: t.refine,
    resolvePath: t.resolvePath ?? defaultResolvePath(t.route.path),
  };
}

/** Exactly one of two arguments (journal_ids | filter, rule | rule_id …). */
export function exactlyOne(a: string, b: string): (args: Record<string, unknown>) => void {
  return (args) => {
    const has = (k: string): boolean => args[k] !== undefined && !(Array.isArray(args[k]) && (args[k] as unknown[]).length === 0);
    if (has(a) === has(b)) throw invalid(`give exactly one of ${a} or ${b}`);
  };
}

export function invalid(message: string, hint?: string): ToolError {
  return fail('invalid_input', message, hint);
}

/** Runs several refinements in order. */
export function all(...fns: Array<(args: Record<string, unknown>) => void>): (args: Record<string, unknown>) => void {
  return (args) => {
    for (const f of fns) f(args);
  };
}
