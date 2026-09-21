/**
 * The catalogue — pm/mcp.mdx §9, §17 "Catalogue tests", acceptance 6 and 17.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';

import { READ_TOOLS, TOOLS, TOTAL_TOOLS, WRITE_TOOL_COUNT, findTool } from '../src/tools/registry.js';
import { AMOUNT_PATTERN, READS_ONLY, SIGNED_PATTERN, WHICH_SERVER, WRITES } from '../src/tools/tool.js';
import type { ToolDef } from '../src/tools/tool.js';
import { PROMPT_TOKENS } from '../src/prompt-tokens.js';
import { REPO_ROOT, TEST_TOKEN, call, host, startFakePlane } from './helpers.js';
import type { FakePlane } from './helpers.js';

const MCP_MDX = fs.readFileSync(path.join(REPO_ROOT, 'pm', 'mcp.mdx'), 'utf8');
const APIS_MDX = fs.readFileSync(path.join(REPO_ROOT, 'pm', 'apis.mdx'), 'utf8');

/** §9.1: the closed verb set, and whether each writes. */
const READ_VERBS = ['list', 'get', 'scan', 'plan', 'preview', 'describe', 'extract', 'export'];
const WRITE_VERBS = ['add', 'update', 'set', 'apply', 'categorize', 'convert', 'copy', 'run', 'move', 'trigger'];
/** Bare names and the analytics family, named for the figure they return (§9.1). */
const NOUN_NAMES: Record<string, 'read' | 'write'> = {
  ff_whoami: 'read',
  ff_health: 'read',
  ff_undo: 'write',
  ff_search: 'read',
  ff_capabilities: 'read',
  ff_spending_by_category: 'read',
  ff_spending_by_budget: 'read',
  ff_spending_by_tag: 'read',
  ff_payee_leaderboard: 'read',
  ff_income_vs_expense: 'read',
  ff_cash_flow: 'read',
  ff_net_worth: 'read',
  ff_category_trend: 'read',
  ff_budget_performance: 'read',
};

/** mcp.mdx §9.5's tables: tool → "METHOD /route" as the spec writes it. */
function specCatalogue(): Map<string, string> {
  const start = MCP_MDX.indexOf('### 9.5 The families');
  const end = MCP_MDX.indexOf('### 9.6');
  const section = MCP_MDX.slice(start, end);
  const out = new Map<string, string>();
  for (const m of section.matchAll(/^\|\s*`(ff_[a-z_]+)`\s*\|\s*`(GET|POST|PUT) ([^`]+)`/gm)) {
    out.set(m[1] as string, `${m[2]} ${m[3]}`);
  }
  return out;
}

/** Every (method, path) apis.mdx publishes, in any of its three notations. */
function apisRoutes(): Array<{ method: string; path: string }> {
  const out: Array<{ method: string; path: string }> = [];
  for (const m of APIS_MDX.matchAll(/^\|\s*`(\/[^`]+)`\s*\|\s*(GET|POST|PUT|DELETE)\s*\|/gm)) out.push({ method: m[2] as string, path: m[1] as string });
  for (const m of APIS_MDX.matchAll(/`(GET|POST|PUT|DELETE) (\/[A-Za-z0-9{}_/.-]+)/g)) out.push({ method: m[1] as string, path: (m[2] as string).replace(/^\/machine\/v1/, '') });
  for (const m of APIS_MDX.matchAll(/^\|\s*`(\/analytics\/[^`]+)`\s*\|/gm)) out.push({ method: 'GET', path: m[1] as string });
  for (const m of APIS_MDX.matchAll(/`(\/charts\/[a-z0-9{}/-]+)`/g)) out.push({ method: 'GET', path: m[1] as string });
  return out;
}

function routeRegex(pattern: string): RegExp {
  const escaped = pattern.replace(/[.*+?^$()|[\]\\]/g, '\\$&');
  return new RegExp(`^${escaped.replace(/\{path\}/g, '.+').replace(/\{[a-z_]+\}/g, '[^/]+')}$`);
}

function verbOf(name: string): string | undefined {
  const rest = name.slice(3);
  return [...READ_VERBS, ...WRITE_VERBS].find((v) => rest.startsWith(`${v}_`));
}

/** Every leaf schema in a tool's input, with its dotted path. */
function leaves(schema: Record<string, unknown>, where = ''): Array<{ where: string; s: Record<string, unknown> }> {
  const out: Array<{ where: string; s: Record<string, unknown> }> = [];
  const props = schema.properties as Record<string, Record<string, unknown>> | undefined;
  if (props) for (const [k, v] of Object.entries(props)) out.push({ where: where ? `${where}.${k}` : k, s: v }, ...leaves(v, where ? `${where}.${k}` : k));
  const items = schema.items as Record<string, unknown> | undefined;
  if (items) out.push(...leaves(items, `${where}[]`));
  return out;
}

const MONEY_FIELD = /(^|\.)(amount|min_amount|max_amount|foreign_amount|min_spent|target_balance)$/;

describe('the catalogue', () => {
  it('has 79 tools: 60 read, 19 write', () => {
    assert.equal(TOTAL_TOOLS, 79);
    assert.equal(READ_TOOLS, 60);
    assert.equal(WRITE_TOOL_COUNT, 19);
    assert.equal(new Set(TOOLS.map((t) => t.name)).size, 79, 'names are unique');
  });

  it('the counts the instructions quote are the registry\'s counts', () => {
    assert.equal(PROMPT_TOKENS.TOTAL_TOOLS, String(TOTAL_TOOLS));
    assert.equal(PROMPT_TOKENS.READ_TOOLS, String(READ_TOOLS));
    assert.equal(PROMPT_TOKENS.WRITE_TOOLS, String(WRITE_TOOL_COUNT));
  });

  it('is exactly the §9.5 tables, both directions, route for route', () => {
    const spec = specCatalogue();
    assert.equal(spec.size, 79, 'the spec tables parse to 79 tools');
    assert.deepEqual([...spec.keys()].sort(), TOOLS.map((t) => t.name).sort());
    for (const t of TOOLS) {
      const want = (spec.get(t.name) as string).replace('?without_category=true', '').replace(/ · .*$/, '');
      const got = `${t.route.method} ${t.route.path}`;
      if (t.name === 'ff_move_piggy_bank_money') assert.equal(want, 'POST /piggy-banks/{id}/add');
      else assert.equal(got, want, t.name);
    }
    assert.deepEqual(findTool('ff_list_uncategorized')?.fixedQuery, { without_category: true });
  });

  it('maps every tool to a route apis.mdx publishes', () => {
    const routes = apisRoutes();
    for (const t of TOOLS) {
      const re = routeRegex(t.route.path);
      assert.ok(routes.some((r) => r.method === t.route.method && re.test(r.path)), `${t.name}: ${t.route.method} ${t.route.path} is not in apis.mdx`);
    }
  });

  it('names every tool ^ff_[a-z_]+$ with a verb from the closed set, and the verb agrees with the tier', () => {
    for (const t of TOOLS) {
      assert.match(t.name, /^ff_[a-z_]+$/);
      const noun = NOUN_NAMES[t.name];
      if (noun) {
        assert.equal(t.tier, noun, t.name);
        continue;
      }
      const verb = verbOf(t.name);
      assert.ok(verb, `${t.name}: verb not in the closed set`);
      assert.equal(t.tier, WRITE_VERBS.includes(verb as string) ? 'write' : 'read', `${t.name}: verb ${verb} vs tier ${t.tier}`);
    }
  });

  it('carries the four description clauses on every tool', () => {
    for (const t of TOOLS) {
      const d = t.description;
      assert.ok(d.length > 120, `${t.name}: description too thin`);
      assert.ok(/^[A-Z]/.test(d), `${t.name}: clause 1 (what it does) missing`);
      assert.ok(d.includes(t.tier === 'read' ? READS_ONLY : WRITES), `${t.name}: clause 2 (cost) missing`);
      if (t.tier === 'read') assert.ok(!d.includes('WRITES'), `${t.name}: a read tool that says it writes`);
      assert.ok(d.startsWith(t.clauses.what) && d.includes(t.clauses.cost) && d.includes(t.clauses.instead), `${t.name}: assembled from its clauses`);
      assert.match(t.clauses.instead, /\bff_[a-z_]+/, `${t.name}: clause 3 names no sibling tool`);
      assert.ok(d.endsWith(WHICH_SERVER), `${t.name}: clause 4 (which server) missing`);
      const siblings = d.match(/\bff_[a-z_]+/g) ?? [];
      for (const s of siblings) assert.ok(findTool(s), `${t.name}: names ${s}, which does not exist`);
    }
  });

  it('types every money field as a pattern-checked decimal string, never a number', () => {
    let seen = 0;
    for (const t of TOOLS) {
      for (const { where, s } of leaves(t.inputSchema)) {
        if (!MONEY_FIELD.test(where)) continue;
        seen++;
        assert.equal(s.type, 'string', `${t.name}.${where}`);
        assert.ok(s.pattern === AMOUNT_PATTERN || s.pattern === SIGNED_PATTERN, `${t.name}.${where}: pattern`);
        assert.match(String(s.description), /decimal string/, `${t.name}.${where}: says decimal string`);
      }
      for (const { where, s } of leaves(t.inputSchema)) {
        if (s.type === 'number') assert.fail(`${t.name}.${where}: a JSON number field`);
      }
    }
    assert.ok(seen >= 12, `expected many money fields, saw ${seen}`);
  });

  it('closes every schema (additionalProperties: false) — unknown arguments are refused, not ignored', () => {
    for (const t of TOOLS) {
      assert.equal(t.inputSchema.type, 'object');
      assert.equal(t.inputSchema.additionalProperties, false, t.name);
    }
  });

  it('gives seventeen writes dry_run and a confirm echo, and ff_undo / ff_trigger_recurrence no dry_run', () => {
    const writes = TOOLS.filter((t) => t.tier === 'write');
    const withDryRun = writes.filter((t) => 'dry_run' in (t.inputSchema.properties as object));
    assert.equal(withDryRun.length, 17);
    for (const name of ['ff_undo', 'ff_trigger_recurrence']) {
      const t = findTool(name) as ToolDef;
      assert.ok(!('dry_run' in (t.inputSchema.properties as object)), `${name} offers dry_run`);
      assert.equal(t.write?.dryRun, false);
    }
    for (const t of withDryRun) assert.ok('confirm' in (t.inputSchema.properties as object), `${t.name}: confirm`);
  });

  it('has no delete, purge, admin, webhook, credential or duplicate-marking tool', () => {
    for (const t of TOOLS) assert.doesNotMatch(t.name, /delete|purge|destroy|_admin(_|$)|webhook|password|token|rotate|mark_duplicate|merge|switch/);
  });
});

// ---------------------------------------------- tools/list == dispatch ---

/** A minimal valid argument set, generated from the hand-authored schema. */
function sample(s: Record<string, unknown>, key: string): unknown {
  if (Array.isArray(s.enum)) return s.enum[0];
  if (s.pattern === '^\\d{4}-\\d{2}-\\d{2}$') return '2026-09-01';
  if (s.pattern === AMOUNT_PATTERN) return '12.50';
  if (s.pattern === SIGNED_PATTERN) return '-12.50';
  const type = s.type;
  if (Array.isArray(type)) return type.includes('integer') ? 3 : 'x';
  if (type === 'integer') return typeof s.minimum === 'number' && s.minimum > 1 ? s.minimum : 1;
  if (type === 'boolean') return true;
  if (type === 'array') return [sample(s.items as Record<string, unknown>, key)];
  if (type === 'object') {
    const out: Record<string, unknown> = {};
    const props = (s.properties ?? {}) as Record<string, Record<string, unknown>>;
    for (const r of (s.required as string[] | undefined) ?? []) out[r] = sample(props[r] as Record<string, unknown>, r);
    return out;
  }
  if (key === 'root' || key === 'path' || key === 'manifest_path') return 'bank/household';
  return 'Groceries';
}

const EXTRA: Record<string, Record<string, unknown>> = {
  ff_get_upstream: { path: 'insight/expense/category' },
  ff_get_chart: { name: 'net-worth' },
  ff_categorize_transactions: { journal_ids: [101] },
  ff_set_transaction_budget: { journal_ids: [101] },
  ff_run_rules: { rule_ids: [3] },
  ff_preview_rule: { rule_id: 3 },
  ff_update_transaction: { group_title: 'Costco run' },
};

export function minimalArgs(t: ToolDef): Record<string, unknown> {
  const args: Record<string, unknown> = {};
  const props = t.inputSchema.properties as Record<string, Record<string, unknown>>;
  for (const r of (t.inputSchema.required as string[] | undefined) ?? []) args[r] = sample(props[r] as Record<string, unknown>, r);
  Object.assign(args, EXTRA[t.name] ?? {});
  if (t.write?.tokenRequired === 'always') args.confirm = TEST_TOKEN;
  return args;
}

describe('tools/list and dispatch read the same array', () => {
  let plane: FakePlane;
  before(async () => {
    plane = await startFakePlane();
  });
  after(async () => plane.close());

  it('lists exactly the registry, in order, with its schemas', () => {
    const { host: h } = host(plane, { FFMCP_ALLOW_WRITE: '1' });
    const listed = h.handleListTools().tools;
    assert.deepEqual(listed.map((t) => t.name), TOOLS.map((t) => t.name));
    for (const [i, t] of listed.entries()) assert.equal(t.inputSchema, TOOLS[i]?.inputSchema);
  });

  it('dispatches every listed tool to exactly ONE request on its own route', async () => {
    const { host: h } = host(plane, { FFMCP_ALLOW_WRITE: '1' });
    for (const t of h.handleListTools().tools) {
      const def = findTool(t.name) as ToolDef;
      const before = plane.calls.length;
      const r = await call(h, t.name, minimalArgs(def));
      assert.equal(r.isError, false, `${t.name}: ${r.raw}`);
      assert.equal(plane.calls.length, before + 1, `${t.name}: one request`);
      const sent = plane.calls[before]!;
      assert.equal(sent.method, def.route.method, t.name);
      assert.match(sent.path, routeRegex(def.route.path), `${t.name}: ${sent.path}`);
      assert.equal(sent.headers['x-firefly-client'], 'mcp');
    }
  });

  it('an unknown tool is a tool-level not_found, never a protocol error', async () => {
    const { host: h } = host(plane);
    const r = await call(h, 'get_credits', {});
    assert.equal(r.isError, true);
    assert.equal((r.envelope.error as { code: string }).code, 'not_found');
  });
});
