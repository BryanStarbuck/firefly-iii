/**
 * The JSON-RPC surface — pm/mcp.mdx §8, §9.3, §12.
 *
 * The SDK's low-level `Server` with explicit ListTools / CallTool handlers,
 * because the JSON Schemas are HAND-AUTHORED (§8.2) — the field descriptions
 * the model reads are exactly what tools/*.ts wrote — and zod does the runtime
 * parse behind them (gate 5). Capabilities are `{ tools: {} }` and nothing
 * else, so prompts/* and resources/* answer -32601 by not existing.
 *
 * Every result is ONE text content block of pretty-printed JSON — the envelope
 * `{ ok, tool, data, meta }` or `{ ok: false, tool, error }` with isError —
 * and a tool failure is never a thrown JSON-RPC error: losing one call must
 * not kill the model's loop (§12.2).
 */
import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { CallToolRequestSchema, ListToolsRequestSchema } from '@modelcontextprotocol/sdk/types.js';

import { auditLine } from './audit.js';
import type { PlaneEnvelope, PlaneMeta, Transport } from './client.js';
import type { McpConfig } from './config.js';
import { SERVER_KEY } from './config.js';
import { fail, ToolError, WRITE_SWITCHES_HINT } from './errors.js';
import type { ErrorCode } from './errors.js';
import { checkConfirm, checkInput, checkMode, checkNotForeign, reportedChangeCount, tooManyChanges } from './gates.js';
import type { Clamp } from './gates.js';
import type { Logger } from './logger.js';
import { findTool, TOOLS } from './tools/registry.js';
import type { ToolDef } from './tools/tool.js';
import { buildRequest } from './wire.js';

export const SERVER_VERSION = '0.1.0';

export interface HostOptions {
  config: McpConfig;
  transport: Transport;
  logger: Logger;
  keyFingerprint: string;
  instructions: string;
}

export interface Envelope {
  ok: boolean;
  tool: string;
  data?: unknown;
  meta?: Record<string, unknown>;
  error?: { code: ErrorCode; message: string; hint?: string; details?: unknown };
}

export interface CallResult {
  [key: string]: unknown;
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
}

/** Fields that always carry third-party or operator text (apis.mdx §16.3). */
const BASE_UNTRUSTED = ['description', 'notes'];

export class McpServerHost {
  readonly server: Server;
  readonly #o: HostOptions;
  /** Concurrency 1 for writes (§15); reads are unbounded. */
  #writeChain: Promise<unknown> = Promise.resolve();

  constructor(opts: HostOptions) {
    this.#o = opts;
    this.server = new Server(
      { name: SERVER_KEY, version: SERVER_VERSION },
      { capabilities: { tools: {} }, instructions: opts.instructions },
    );
    this.server.setRequestHandler(ListToolsRequestSchema, async () => this.handleListTools());
    this.server.setRequestHandler(CallToolRequestSchema, async (request) => this.handleCallTool(request.params.name, request.params.arguments ?? {}));
  }

  /** tools/list — a map over THE array (§9.3). */
  handleListTools(): { tools: Array<{ name: string; description: string; inputSchema: Record<string, unknown> }> } {
    return {
      tools: TOOLS.map((t) => ({ name: t.name, description: this.#listedDescription(t), inputSchema: t.inputSchema })),
    };
  }

  /**
   * A write tool the mode gate would refuse is still LISTED, and says so and
   * how to enable it — a tool that vanishes teaches nothing (§7.1 gate 4).
   */
  #listedDescription(t: ToolDef): string {
    if (t.tier !== 'write') return t.description;
    const { config } = this.#o;
    if (config.target === 'remote') {
      return `${t.description} CURRENTLY DISABLED: this server points at a remote install, which is read-only.`;
    }
    if (!config.allowWrite) {
      return `${t.description} CURRENTLY DISABLED: the write tier is off. ${WRITE_SWITCHES_HINT}`;
    }
    return t.description;
  }

  /** tools/call — dispatch reads the same array. */
  async handleCallTool(name: string, rawArgs: unknown): Promise<CallResult> {
    const started = Date.now();
    const tool = findTool(name);
    if (!tool) {
      this.#audit({ name, tier: 'unknown', args: rawArgs, ok: false, started, gate: 'dispatch', code: 'not_found' });
      return this.#respond({
        ok: false,
        tool: name,
        error: {
          code: 'not_found',
          message: `No tool named "${name}" on this server.`,
          hint: 'Every tool here is named ff_…; list the tools to see them. A tool without ff_ belongs to a different server and a different ledger.',
        },
      });
    }

    let gate = 'routing';
    try {
      checkNotForeign(rawArgs);
      gate = 'mode';
      checkMode(tool, this.#o.config);
      gate = 'input';
      const { args, clamps } = checkInput(tool, rawArgs, this.#o.config);
      gate = 'confirm';
      checkConfirm(tool, args);
      gate = 'plane';
      const run = () => this.#run(tool, args, clamps, started);
      const envelope = tool.tier === 'write' ? await this.#serialised(run) : await run();
      const rows = firstArrayLength(envelope.data);
      this.#audit({ name, tier: tool.tier, args: rawArgs, ok: true, started, rows });
      return this.#respond(envelope);
    } catch (err) {
      const te = err instanceof ToolError ? err : undefined;
      if (!te) this.#o.logger.error(`${name}: ${(err as Error)?.stack ?? String(err)}`);
      const e = te ?? fail('internal', 'The tool failed unexpectedly.', 'The detail is in ~/T/_firefly_iii/mcp.err; ask the operator to look.');
      this.#audit({ name, tier: tool.tier, args: rawArgs, ok: false, started, gate, code: e.code });
      return this.#respond({
        ok: false,
        tool: name,
        error: { code: e.code, message: e.message, ...(e.hint ? { hint: e.hint } : {}), ...(e.details !== undefined ? { details: e.details } : {}) },
      });
    }
  }

  async #serialised<T>(fn: () => Promise<T>): Promise<T> {
    const next = this.#writeChain.then(fn, fn);
    this.#writeChain = next.catch(() => undefined);
    return next;
  }

  async #run(tool: ToolDef, args: Record<string, unknown>, clamps: Clamp[], started: number): Promise<Envelope> {
    const { config } = this.#o;
    const req = buildRequest(tool, args, config);
    const plane = await this.#o.transport.call(req);

    if (!plane.ok) throw this.#planeFailure(tool, plane, req.ceiling);

    // The ceiling on what the dry run says would change (§9.7, T2). The count
    // is the PLANE's; this side only compares it.
    if (tool.write && req.dryRun) {
      const count = reportedChangeCount(plane.data);
      if (count !== undefined && count > req.ceiling) throw tooManyChanges(tool, count, req.ceiling);
    }

    const envelope: Envelope = {
      ok: true,
      tool: tool.name,
      data: plane.data ?? null,
      meta: this.#meta(tool, plane.meta ?? {}, clamps, started),
    };
    return this.#capBytes(envelope);
  }

  /** The plane's error, passed through in the closed vocabulary — with our own refinements. */
  #planeFailure(tool: ToolDef, plane: PlaneEnvelope, ceiling: number): ToolError {
    const err = plane.error ?? { code: 'internal' };
    const code = err.code as ErrorCode;
    if (code === 'conflict' && tool.write) {
      const count = reportedChangeCount(err.details, true);
      const d = err.details as Record<string, unknown> | undefined;
      const aboutCeiling = d !== undefined && (d.max_changes !== undefined || /max_changes|ceiling|too many/i.test(err.message ?? ''));
      if (count !== undefined && aboutCeiling && count > ceiling) return tooManyChanges(tool, count, ceiling);
    }
    let hint = err.hint;
    if (code === 'write_disabled' && !(hint ?? '').includes('FFMCP_ALLOW_WRITE')) hint = WRITE_SWITCHES_HINT;
    if (code === 'not_ready' && !hint) hint = 'Tell the operator to run `ffx up` (or `ffx doctor`) and wait — this server never starts the app.';
    return fail(code, err.message ?? `Firefly III refused the call (${code}).`, hint, err.details);
  }

  #meta(tool: ToolDef, pm: PlaneMeta, clamps: Clamp[], started: number): Record<string, unknown> {
    const { config } = this.#o;
    const untrusted = [...new Set([...(pm.untrusted ?? []), ...BASE_UNTRUSTED, ...tool.untrusted])];
    return {
      administrationId: pm.administrationId ?? null,
      administrationName: pm.administrationName ?? null,
      operator: pm.operator ?? null,
      primaryCurrency: pm.primaryCurrency ?? null,
      ...pm,
      target: config.target,
      ...(config.target === 'remote' ? { targetUrl: config.apiUrl } : {}),
      asOf: pm.asOf ?? new Date().toISOString(),
      tookMs: Date.now() - started,
      truncated: pm.truncated ?? false,
      untrusted,
      ...(clamps.length ? { clamped: clamps } : {}),
    };
  }

  /**
   * FFMCP_MAX_BYTES (§14): over it, the longest list in `data` is cut until the
   * envelope fits, and `truncated` says so — a cap that clamps and reports,
   * never a silent cap. A payload with no list to cut is refused instead.
   */
  #capBytes(envelope: Envelope): Envelope {
    const max = this.#o.config.maxBytes;
    if (serialisedLength(envelope) <= max) return envelope;
    const data = envelope.data;
    const key = longestArrayKey(data);
    if (key === undefined) {
      throw fail('invalid_input', `The answer is larger than this server's response cap (${max} bytes).`, 'Narrow the request: a shorter date range, fewer accounts, a lower limit.');
    }
    const list = (data as Record<string, unknown[]>)[key] as unknown[];
    let lo = 0;
    let hi = list.length;
    const trial = (n: number): Envelope => ({
      ...envelope,
      data: { ...(data as Record<string, unknown>), [key]: list.slice(0, n) },
      meta: { ...envelope.meta, truncated: true, truncated_by: 'FFMCP_MAX_BYTES', limit_applied: n, rows_available: list.length },
    });
    while (lo < hi) {
      const mid = Math.ceil((lo + hi) / 2);
      if (serialisedLength(trial(mid)) <= max) lo = mid;
      else hi = mid - 1;
    }
    return trial(lo);
  }

  #respond(envelope: Envelope): CallResult {
    return {
      content: [{ type: 'text', text: JSON.stringify(envelope, null, 2) }],
      ...(envelope.ok ? {} : { isError: true }),
    };
  }

  #audit(a: { name: string; tier: string; args: unknown; ok: boolean; started: number; rows?: number | undefined; gate?: string; code?: string }): void {
    try {
      this.#o.logger.audit(
        auditLine({
          tool: a.name,
          tier: a.tier,
          target: this.#o.config.target,
          args: a.args,
          ok: a.ok,
          tookMs: Date.now() - a.started,
          keyFingerprint: this.#o.keyFingerprint,
          rows: a.rows,
          gate: a.gate,
          code: a.code,
        }),
        !a.ok,
      );
    } catch {
      /* the audit never takes a call down */
    }
  }
}

function serialisedLength(e: Envelope): number {
  return Buffer.byteLength(JSON.stringify(e, null, 2));
}

function longestArrayKey(data: unknown): string | undefined {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return undefined;
  let best: string | undefined;
  let bestLen = 0;
  for (const [k, v] of Object.entries(data as Record<string, unknown>)) {
    if (Array.isArray(v) && v.length > bestLen) {
      best = k;
      bestLen = v.length;
    }
  }
  return best;
}

/** A row count for the audit line — counts yes, money no. */
function firstArrayLength(data: unknown): number | undefined {
  if (Array.isArray(data)) return data.length;
  if (!data || typeof data !== 'object') return undefined;
  for (const v of Object.values(data as Record<string, unknown>)) if (Array.isArray(v)) return v.length;
  return undefined;
}
