/**
 * The ONE http client — pm/mcp.mdx §4, §7.3, apis.mdx §4.6 and §5.
 *
 * This is the only module in this process that opens a socket (the
 * no-network canary asserts it). It:
 *   - refuses, before connecting, to send the key anywhere but loopback or https;
 *   - sends the key in X-Firefly-Machine-Key — never in a URL — and identifies
 *     itself with X-Firefly-Client: mcp (advisory for the plane's audit log,
 *     and the header the plane keys its admin refusal on, apis.mdx §6.1);
 *   - sends NO Origin and NO Sec-Fetch-* headers. That is why it is built on
 *     node:http rather than fetch(): the plane's origin gate 404s anything
 *     browser-shaped, and fetch implementations add Sec-Fetch-Mode themselves;
 *   - resolves every exchange into the plane's envelope, or throws a ToolError
 *     in the closed vocabulary. It never retries: one tool call, one request.
 */
import http from 'node:http';
import https from 'node:https';

import { isLoopbackHost, PLANE_PREFIX } from './config.js';
import type { McpConfig } from './config.js';
import { fail, isPlaneCode } from './errors.js';

export interface PlaneMeta {
  target?: string;
  operator?: string;
  administrationId?: number | string | null;
  administrationName?: string | null;
  primaryCurrency?: string;
  asOf?: string;
  tookMs?: number;
  truncated?: boolean;
  limit_applied?: number;
  untrusted?: string[];
  [key: string]: unknown;
}

export interface PlaneError {
  code: string;
  message?: string;
  hint?: string;
  details?: unknown;
}

export interface PlaneEnvelope {
  ok: boolean;
  data?: unknown;
  meta?: PlaneMeta;
  error?: PlaneError;
}

export type QueryValue = string | number | boolean | undefined | null | Array<string | number>;
export type Query = Record<string, QueryValue>;

export interface CallSpec {
  method: 'GET' | 'POST' | 'PUT';
  /** The concrete route under /machine/v1, e.g. `/accounts/12/balance`. */
  route: string;
  query?: Query | undefined;
  body?: unknown;
  /** Milliseconds, or null for the long ingest routes (apply, extract — §14). */
  timeoutMs?: number | null | undefined;
}

export interface Transport {
  call(spec: CallSpec): Promise<PlaneEnvelope>;
}

/** Arrays go as name[]=v, booleans as true/false; undefined and null are dropped. */
export function encodeQuery(query: Query | undefined): string {
  if (!query) return '';
  const parts: string[] = [];
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;
    if (Array.isArray(v)) {
      const name = k.endsWith('[]') ? k : `${k}[]`;
      for (const item of v) parts.push(`${encodeURIComponent(name)}=${encodeURIComponent(String(item))}`);
    } else {
      parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
    }
  }
  return parts.length ? `?${parts.join('&')}` : '';
}

export function isEnvelope(value: unknown): value is PlaneEnvelope {
  return value !== null && typeof value === 'object' && typeof (value as { ok?: unknown }).ok === 'boolean';
}

interface Raw {
  status: number;
  body: string;
}

/** Reason the key must not be sent to this URL, or undefined when it may. */
export function unsafeTargetReason(apiUrl: string): string | undefined {
  let url: URL;
  try {
    url = new URL(apiUrl);
  } catch {
    return `not a URL: ${apiUrl}`;
  }
  if (url.protocol === 'https:') return undefined;
  if (url.protocol !== 'http:') return `unsupported scheme ${url.protocol}`;
  if (isLoopbackHost(url.hostname)) return undefined;
  return `plain http to a non-loopback host (${url.host}) would send the machine key in cleartext`;
}

export class PlaneClient implements Transport {
  readonly #cfg: McpConfig;
  readonly #key: string;
  readonly #fingerprint: string;

  constructor(cfg: McpConfig, key: string, fingerprint: string) {
    this.#cfg = cfg;
    this.#key = key;
    this.#fingerprint = fingerprint;
  }

  async call(spec: CallSpec): Promise<PlaneEnvelope> {
    const reason = unsafeTargetReason(this.#cfg.apiUrl);
    if (reason) throw fail('forbidden', `refused to send the machine key: ${reason}`, 'use a loopback URL, or https: with FFMCP_ALLOW_REMOTE=1');
    const url = new URL(`${PLANE_PREFIX}${spec.route}${encodeQuery(spec.query)}`, this.#cfg.apiUrl);
    const headers: Record<string, string> = {
      'X-Firefly-Machine-Key': this.#key,
      'X-Firefly-Client': 'mcp',
      Accept: 'application/json',
      'User-Agent': 'firefly_iii-mcp',
    };
    let body: string | undefined;
    if (spec.body !== undefined) {
      body = JSON.stringify(spec.body);
      headers['Content-Type'] = 'application/json';
      headers['Content-Length'] = String(Buffer.byteLength(body));
    }
    const timeoutMs = spec.timeoutMs === undefined ? this.#cfg.timeoutMs : spec.timeoutMs;
    let raw: Raw;
    try {
      raw = await exchange(spec.method, url, headers, body, timeoutMs);
    } catch (err) {
      throw this.#transportError(err as NodeJS.ErrnoException, timeoutMs);
    }
    return this.#parse(raw);
  }

  #transportError(err: NodeJS.ErrnoException, timeoutMs: number | null) {
    if (err.code === 'ETIMEDOUT') {
      return fail('upstream_error', `Firefly III did not answer within ${timeoutMs} ms.`, 'narrow the request (a shorter date range, a lower limit), or ask the operator to check `ffx logs --laravel`');
    }
    if (err.code === 'ECONNREFUSED' || err.code === 'ENOTFOUND' || err.code === 'EHOSTUNREACH' || err.code === 'ECONNRESET' || err.code === 'EADDRNOTAVAIL') {
      return fail(
        'not_ready',
        `Firefly III is not running at ${this.#cfg.apiUrl}.`,
        `Tell the operator to run \`ffx up\` and wait for them — this server never starts the app. Do not retry in a loop.`,
      );
    }
    return fail('not_ready', `Could not talk to Firefly III at ${this.#cfg.apiUrl}.`, 'Tell the operator to check the app with `ffx doctor`.');
  }

  #parse(raw: Raw): PlaneEnvelope {
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw.body);
    } catch {
      parsed = undefined;
    }
    if (isEnvelope(parsed)) {
      if (!parsed.ok) {
        const code = parsed.error?.code;
        if (code === 'unauthorized') {
          throw fail(
            'unauthorized',
            `The plane refused this server's machine key (${this.#fingerprint}).`,
            'The key on disk changed after this MCP server started (it reads the key once). Restart this MCP server — nothing for the operator to type.',
          );
        }
        if (!isPlaneCode(code)) {
          throw fail('internal', parsed.error?.message ?? 'The plane answered with an unknown error code.', 'ask the operator to check `ffx logs --laravel`');
        }
      }
      return parsed;
    }
    if (raw.status === 404) {
      throw fail(
        'not_ready',
        'Firefly III is up, but the machine plane (/machine/v1) is not mounted.',
        'The running app must be this fork of Firefly III (the one with app/Machine/), not upstream — tell the operator.',
      );
    }
    throw fail('upstream_error', `Firefly III answered HTTP ${raw.status} with something that is not the plane's envelope.`, 'ask the operator to check `ffx logs --laravel`');
  }
}

/** A single HTTP exchange. Resolves with status + body; rejects only on transport failure. */
function exchange(method: string, url: URL, headers: Record<string, string>, body: string | undefined, timeoutMs: number | null): Promise<Raw> {
  return new Promise((resolve, reject) => {
    const lib = url.protocol === 'https:' ? https : http;
    const req = lib.request(url, { method, headers }, (res) => {
      let text = '';
      res.setEncoding('utf8');
      res.on('data', (chunk: string) => {
        text += chunk;
      });
      res.on('end', () => resolve({ status: res.statusCode ?? 0, body: text }));
      res.on('error', reject);
    });
    if (timeoutMs !== null) {
      req.setTimeout(timeoutMs, () => {
        req.destroy(Object.assign(new Error('timeout'), { code: 'ETIMEDOUT' }));
      });
    }
    req.on('error', reject);
    if (body !== undefined) req.write(body);
    req.end();
  });
}
