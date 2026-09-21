/**
 * The ONE HTTP client — pm/cli.mdx §5, apis.mdx §4.6 and §5.
 *
 * This is the only module in the CLI that opens a socket. It:
 *   - refuses, before connecting, to send the key anywhere but loopback or https (R3);
 *   - sends the key in X-Firefly-Machine-Key — never in a URL;
 *   - identifies itself with X-Firefly-Client: ffx (advisory, for the server's audit log);
 *   - sends NO Origin and NO Sec-Fetch-* headers. That is why it is built on
 *     node:http rather than fetch(): the plane's origin gate refuses browser-shaped
 *     requests, and fetch implementations add Sec-Fetch-Mode on their own;
 *   - turns every response into the envelope, or a CliError with the right exit code;
 *   - streams NDJSON progress for the long ingest routes into the spinner.
 */
import http from 'node:http';
import https from 'node:https';

import type { Config } from './config.js';
import { PLANE_PREFIX, unsafeTargetReason } from './config.js';
import type { MachineKey } from './credentials.js';
import { fingerprint } from './credentials.js';
import { CliError, EXIT, EXIT_FOR_CODE, isPlaneCode } from './errors.js';
import { log, scrub } from './logger.js';
import type { Spinner } from './progress.js';

export interface Meta {
  target?: string;
  operator?: string;
  administrationId?: number | string;
  administrationName?: string;
  primaryCurrency?: string;
  serverVersion?: string;
  asOf?: string;
  tookMs?: number;
  truncated?: boolean;
  limit_applied?: number;
  tier?: string;
  replayed?: boolean;
  composed?: boolean;
  untrusted?: string[];
  [key: string]: unknown;
}

export interface EnvelopeError {
  code: string;
  message?: string;
  hint?: string;
  details?: unknown;
}

export interface Envelope<T = Record<string, unknown>> {
  ok: boolean;
  data?: T;
  meta?: Meta;
  error?: EnvelopeError;
}

export type QueryValue = string | number | boolean | undefined | null | string[];
export type Query = Record<string, QueryValue>;

export interface CallOptions {
  query?: Query;
  body?: unknown;
  /** Milliseconds, or null for no client-side timeout (the long ingest routes — §7.4). */
  timeoutMs?: number | null;
  /** Ask for NDJSON progress and feed it to this spinner. */
  progress?: Spinner;
}

export interface RawResponse {
  status: number;
  contentType: string;
  body: string;
}

export function isEnvelope(value: unknown): value is Envelope {
  return value !== null && typeof value === 'object' && typeof (value as { ok?: unknown }).ok === 'boolean';
}

/** Arrays go as name[]=v, booleans as true/false; undefined and null are dropped. */
export function encodeQuery(query: Query | undefined): string {
  if (!query) return '';
  const parts: string[] = [];
  for (const [k, v] of Object.entries(query)) {
    if (v === undefined || v === null) continue;
    if (Array.isArray(v)) {
      const name = k.endsWith('[]') ? k : `${k}[]`;
      for (const item of v) parts.push(`${encodeURIComponent(name)}=${encodeURIComponent(item)}`);
    } else {
      parts.push(`${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`);
    }
  }
  return parts.length ? `?${parts.join('&')}` : '';
}

interface RequestSpec {
  method: string;
  url: URL;
  headers: Record<string, string>;
  body?: string | undefined;
  timeoutMs: number | null;
  onLine?: ((line: string) => void) | undefined;
}

/** A single HTTP exchange. Resolves with status + body; rejects only on transport failure. */
function exchange(spec: RequestSpec): Promise<RawResponse> {
  return new Promise((resolve, reject) => {
    const lib = spec.url.protocol === 'https:' ? https : http;
    const req = lib.request(
      spec.url,
      { method: spec.method, headers: spec.headers },
      (res) => {
        const contentType = String(res.headers['content-type'] ?? '');
        const streaming = spec.onLine !== undefined && contentType.includes('ndjson');
        let body = '';
        let pending = '';
        res.setEncoding('utf8');
        res.on('data', (chunk: string) => {
          if (!streaming) {
            body += chunk;
            return;
          }
          pending += chunk;
          let nl = pending.indexOf('\n');
          while (nl !== -1) {
            const line = pending.slice(0, nl).trim();
            pending = pending.slice(nl + 1);
            if (line) {
              body = line; // the last complete line is the envelope
              spec.onLine?.(line);
            }
            nl = pending.indexOf('\n');
          }
        });
        res.on('end', () => {
          if (streaming && pending.trim()) body = pending.trim();
          resolve({ status: res.statusCode ?? 0, contentType, body });
        });
        res.on('error', reject);
      },
    );
    if (spec.timeoutMs !== null) {
      req.setTimeout(spec.timeoutMs, () => {
        req.destroy(Object.assign(new Error('timeout'), { code: 'ETIMEDOUT' }));
      });
    }
    req.on('error', reject);
    if (spec.body !== undefined) req.write(spec.body);
    req.end();
  });
}

function transportError(err: NodeJS.ErrnoException, cfg: Config, timeoutMs: number | null): CliError {
  if (err.code === 'ECONNREFUSED' || err.code === 'ENOTFOUND' || err.code === 'EHOSTUNREACH') {
    return new CliError(EXIT.UNAVAILABLE, `Firefly III is not reachable at ${cfg.apiUrl}`, {
      hint: 'ffx up   (or drop --no-bringup and let ffx start it)',
    });
  }
  if (err.code === 'ETIMEDOUT') {
    return new CliError(EXIT.FAILED, `no answer from ${cfg.apiUrl} within ${timeoutMs} ms`, {
      hint: 'raise --timeout, or check the app: ffx logs',
    });
  }
  return new CliError(EXIT.UNAVAILABLE, `could not talk to ${cfg.apiUrl}: ${err.message}`);
}

/** Probe Laravel's /up health route. No key is sent — /up is not the plane. */
export async function probeUp(cfg: Config, timeoutMs = 2000): Promise<boolean> {
  try {
    const res = await exchange({
      method: 'GET',
      url: new URL('/up', cfg.apiUrl),
      headers: { Accept: 'text/html,application/json' },
      timeoutMs,
    });
    return res.status >= 200 && res.status < 300;
  } catch {
    return false;
  }
}

export interface ClientOptions {
  timeoutMs: number;
  verbose: boolean;
}

export class PlaneClient {
  constructor(
    private readonly cfg: Config,
    private readonly key: MachineKey,
    private readonly opts: ClientOptions,
  ) {}

  get fingerprint(): string {
    return fingerprint(this.key.key);
  }

  /** One call to /machine/v1{route}. Resolves with a SUCCESS envelope; throws CliError otherwise. */
  async call<T = Record<string, unknown>>(method: string, route: string, options: CallOptions = {}): Promise<Envelope<T>> {
    const reason = unsafeTargetReason(this.cfg.apiUrl);
    if (reason) {
      throw new CliError(EXIT.USAGE, `refused to send the machine key: ${reason}`, {
        hint: 'use a loopback URL or https:',
      });
    }
    const url = new URL(`${PLANE_PREFIX}${route}${encodeQuery(options.query)}`, this.cfg.apiUrl);
    const headers: Record<string, string> = {
      'X-Firefly-Machine-Key': this.key.key,
      'X-Firefly-Client': 'ffx',
      Accept: options.progress ? 'application/x-ndjson, application/json' : 'application/json',
      'User-Agent': 'ffx',
    };
    let body: string | undefined;
    if (options.body !== undefined) {
      body = JSON.stringify(options.body);
      headers['Content-Type'] = 'application/json';
      headers['Content-Length'] = String(Buffer.byteLength(body));
    }
    const timeoutMs = options.timeoutMs === undefined ? this.opts.timeoutMs : options.timeoutMs;
    const started = Date.now();
    let res: RawResponse;
    try {
      res = await exchange({
        method,
        url,
        headers,
        body,
        timeoutMs,
        onLine: options.progress ? (line) => this.onProgressLine(line, options.progress as Spinner) : undefined,
      });
    } catch (err) {
      log.apiCall(`${method} ${route} transport-error ${(err as NodeJS.ErrnoException).code ?? ''} ${Date.now() - started}ms`);
      throw transportError(err as NodeJS.ErrnoException, this.cfg, timeoutMs);
    }
    const took = Date.now() - started;
    const envelope = this.parse(res, route);
    log.apiCall(
      `${method} ${route} status=${res.status} ok=${envelope.ok}${envelope.error ? ` code=${envelope.error.code}` : ''} ${took}ms` +
        (options.query ? ` args=${Object.keys(options.query).filter((k) => options.query?.[k] !== undefined).join(',')}` : ''),
    );
    if (!envelope.ok) throw this.toError(envelope, res.status);
    return envelope as Envelope<T>;
  }

  private onProgressLine(line: string, spinner: Spinner): void {
    try {
      const parsed = JSON.parse(line) as { progress?: { phase?: string; done?: number; total?: number } };
      if (parsed.progress) spinner.tick(parsed.progress.phase, parsed.progress.done, parsed.progress.total);
    } catch {
      /* a non-JSON line is not progress; the final envelope is parsed separately */
    }
  }

  private parse(res: RawResponse, route: string): Envelope {
    let parsed: unknown;
    try {
      parsed = JSON.parse(res.body);
    } catch {
      parsed = undefined;
    }
    if (isEnvelope(parsed)) return parsed;
    if (res.status === 404) {
      throw new CliError(EXIT.UNAVAILABLE, `the app is up but the machine plane is not mounted (404 on ${PLANE_PREFIX}${route})`, {
        hint: 'this checkout must be the firefly-iii fork with app/Machine/ — see pm/apis.mdx §3',
      });
    }
    const snippet = scrub(res.body.slice(0, 200)).replace(/\s+/g, ' ');
    throw new CliError(EXIT.UNAVAILABLE, `the app answered HTTP ${res.status} with something that is not the plane's envelope`, {
      details: snippet ? [`first bytes: ${snippet}`] : [],
      hint: 'ffx logs --laravel',
    });
  }

  private toError(envelope: Envelope, status: number): CliError {
    const code = envelope.error?.code ?? 'internal';
    const exit = isPlaneCode(code) ? EXIT_FOR_CODE[code] : EXIT.FAILED;
    if (code === 'unauthorized') {
      const where = this.key.source === 'credentials-file' ? this.key.file : this.key.source === 'env' ? 'FFX_MACHINE_KEY' : this.key.file;
      return new CliError(EXIT.AUTH, `the app refused the machine key (${fingerprint(this.key.key)} from ${where})`, {
        code,
        hint:
          'the key on disk and the key the app holds differ. If you just rotated it, retry once; ' +
          'otherwise the app may be reading a different file (FIREFLY_MACHINE_CREDENTIALS_FILE) — ffx doctor',
      });
    }
    const message = envelope.error?.message ?? `the server refused the call (HTTP ${status}, ${code})`;
    return new CliError(exit, message, { code, hint: envelope.error?.hint, serverDetails: envelope.error?.details });
  }
}
