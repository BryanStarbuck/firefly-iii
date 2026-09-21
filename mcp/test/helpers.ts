/**
 * Test scaffolding: a fake machine plane (node:http, speaking apis.mdx's
 * envelope, key header, constant 401 and origin gate), a sandboxed
 * credentials file, an in-process host, and a stdio JSON-RPC driver for the
 * BUILT server. No test touches a real home directory, a real ledger, or the
 * network beyond 127.0.0.1.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import type { McpConfig } from '../src/config.js';
import { loadConfig } from '../src/config.js';
import { Logger } from '../src/logger.js';
import { PlaneClient } from '../src/client.js';
import { McpServerHost } from '../src/server.js';
import { INSTRUCTIONS } from '../src/instructions.js';
import { flushErrorFile } from '../src/vendor/error-file/index.js';
import { installNodeErrorFile, resetNodeErrorFileForTests } from '../src/vendor/error-file/node.js';

const here = path.dirname(fileURLToPath(import.meta.url));
/** .test-build/test → mcp/ */
export const MCP_ROOT = path.resolve(here, '..', '..');
export const REPO_ROOT = path.resolve(MCP_ROOT, '..');
export const DIST = path.join(MCP_ROOT, 'dist');
export const ENTRY = path.join(DIST, 'index.js');

export const TEST_KEY = '0123456789abcdef'.repeat(4);
export const TEST_TOKEN = 'cf_test_token_1';

export const META = {
  target: 'local',
  operator: 'ops@local',
  administrationId: 1,
  administrationName: 'Household',
  primaryCurrency: 'USD',
  serverVersion: '6.4.2',
  planeVersion: 'v1',
  asOf: '2026-09-21T18:41:02.118Z',
  tookMs: 3,
  truncated: false,
  tier: 'read',
};

export interface RecordedCall {
  method: string;
  path: string;
  query: Record<string, string | string[]>;
  body: unknown;
  headers: http.IncomingHttpHeaders;
}

export type Handler = (call: RecordedCall) => [number, unknown];

export interface FakePlane {
  url: string;
  calls: RecordedCall[];
  routes: Map<string, Handler>;
  close(): Promise<void>;
}

export function ok(data: unknown, meta: Record<string, unknown> = {}): [number, unknown] {
  return [200, { ok: true, data, meta: { ...META, ...meta } }];
}

export function failure(status: number, code: string, message: string, hint?: string, details?: unknown): [number, unknown] {
  return [status, { ok: false, error: { code, message, ...(hint ? { hint } : {}), ...(details !== undefined ? { details } : {}) } }];
}

/** A write route speaking the dry-run + confirm-token protocol (apis.mdx §7). */
export function writeRoute(changes: Record<string, number>, extra: Record<string, unknown> = {}): Handler {
  return (call) => {
    const body = (call.body ?? {}) as Record<string, unknown>;
    if (body.dry_run !== false) {
      return ok({ dry_run: true, changes, confirm_token: TEST_TOKEN, expires_at: '2026-09-21T19:11:02Z', fingerprint: 'sha256:7ab3', ...extra });
    }
    if (body.confirm_token !== TEST_TOKEN) {
      return failure(409, 'conflict', 'The ledger changed since the plan was made.', 'Re-plan and show the operator the new counts.', { changes: { created: 131 } });
    }
    return ok({ dry_run: false, changes, operation_id: 417, ...extra });
  };
}

function parseQuery(search: URLSearchParams): Record<string, string | string[]> {
  const q: Record<string, string | string[]> = {};
  for (const [k, v] of search) {
    const prev = q[k];
    q[k] = prev === undefined ? v : Array.isArray(prev) ? [...prev, v] : [prev, v];
  }
  return q;
}

export async function startFakePlane(opts: { key?: string } = {}): Promise<FakePlane> {
  const key = opts.key ?? TEST_KEY;
  const calls: RecordedCall[] = [];
  const routes = new Map<string, Handler>();
  const server = http.createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1');
    let raw = '';
    req.setEncoding('utf8');
    req.on('data', (c: string) => (raw += c));
    req.on('end', () => {
      const send = (status: number, payload: unknown, type = 'application/json'): void => {
        res.writeHead(status, { 'Content-Type': type });
        res.end(typeof payload === 'string' ? payload : JSON.stringify(payload));
      };
      if (!url.pathname.startsWith('/machine/v1')) return send(404, '<html>not found</html>', 'text/html');
      // Gate 2: a browser always sends Origin or Sec-Fetch-*; this client must send neither.
      if (req.headers.origin || req.headers['sec-fetch-site'] || req.headers['sec-fetch-mode']) return send(404, { ok: false, error: { code: 'not_found' } });
      if (url.searchParams.has('key')) return send(404, { ok: false, error: { code: 'not_found' } });
      if (req.headers['x-firefly-machine-key'] !== key) return send(401, { ok: false, error: { code: 'unauthorized' } });
      let body: unknown;
      try {
        body = raw ? JSON.parse(raw) : undefined;
      } catch {
        body = raw;
      }
      const route = url.pathname.slice('/machine/v1'.length);
      const call: RecordedCall = { method: req.method ?? 'GET', path: decodeURIComponent(route), query: parseQuery(url.searchParams), body, headers: req.headers };
      calls.push(call);
      const handler = routes.get(`${call.method} ${call.path}`) ?? routes.get('*');
      if (handler) {
        const [status, payload] = handler(call);
        return send(status, payload);
      }
      // Default: a read answers with an echo; a write speaks the token protocol.
      if (call.method === 'GET') return send(...ok({ echo: { method: call.method, path: call.path } }));
      return send(...writeRoute({ updated: 1 })(call));
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = (server.address() as AddressInfo).port;
  return {
    url: `http://127.0.0.1:${port}`,
    calls,
    routes,
    close: () =>
      new Promise<void>((resolve) => {
        server.closeAllConnections();
        server.close(() => resolve());
      }),
  };
}

export interface Sandbox {
  dir: string;
  /** The error file this sandbox writes (FIREFLY_ERROR_FILE for children; `file:` in process). */
  errorFile: string;
  credentialsFile: string;
  logDir: string;
  env: NodeJS.ProcessEnv;
}

export function sandbox(opts: { key?: string | null; mode?: number; apiUrl?: string; env?: NodeJS.ProcessEnv } = {}): Sandbox {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ffmcp-test-'));
  const credentialsFile = path.join(dir, 'firefly_iii.json');
  const logDir = path.join(dir, 'logs');
  if (opts.key !== null) {
    const doc = {
      other_product: { secret: 'must-survive' },
      firefly_iii: { machine: { api_key: opts.key ?? TEST_KEY, created: '2026-09-21T00:00:00Z', created_by: 'firefly-web', label: 'test' } },
    };
    fs.writeFileSync(credentialsFile, JSON.stringify(doc, null, 2), { mode: 0o600 });
    fs.chmodSync(credentialsFile, opts.mode ?? 0o600);
  }
  const env: NodeJS.ProcessEnv = {
    PATH: process.env.PATH,
    HOME: dir,
    FFMCP_CREDENTIALS_FILE: credentialsFile,
    FFMCP_LOG_DIR: logDir,
    FFMCP_API_URL: opts.apiUrl ?? 'http://127.0.0.1:1',
    // Every child writes its faults to the sandbox, never to ~/T/firefly/ (pm/error_err.mdx R13, §15).
    FIREFLY_ERROR_FILE: path.join(dir, 'error.err'),
    ...(opts.env ?? {}),
  };
  return { dir, errorFile: path.join(dir, 'error.err'), credentialsFile, logDir, env };
}

/**
 * Point this process's error file at a sandbox file (pm/error_err.mdx §5.5): the in-process host
 * never imports error-file-install.ts, so without this its records would sit in the pre-install
 * queue. Re-installs on every call, so each host() writes its own sandbox; a suite that uses it
 * calls resetNodeErrorFileForTests() in after().
 */
export function installSandboxErrorFile(sb: Sandbox): void {
  resetNodeErrorFileForTests();
  installNodeErrorFile({ app: 'mcp', where: 'mcp/test/helpers.ts', file: sb.errorFile, handleProcessErrors: false });
}

/** The record header lines in an error file, after flushing this process's buffer. */
export function errorRecords(file: string): string[] {
  flushErrorFile();
  if (!fs.existsSync(file)) return [];
  return fs.readFileSync(file, 'utf8').split('\n').filter((l) => l.startsWith('['));
}

/** An in-process host over a real PlaneClient pointed at the fake plane. */
export function host(plane: FakePlane, env: NodeJS.ProcessEnv = {}, stderr: string[] = []): { host: McpServerHost; config: McpConfig; logDir: string; errorFile: string } {
  const sb = sandbox({ apiUrl: plane.url, env });
  installSandboxErrorFile(sb);
  const config = loadConfig(sb.env);
  const logger = new Logger({ dir: sb.logDir, level: 'debug', stderr: (l) => stderr.push(l) });
  const h = new McpServerHost({ config, transport: new PlaneClient(config, TEST_KEY, '0123…/sha256:test'), logger, keyFingerprint: '0123…/sha256:test', instructions: INSTRUCTIONS });
  return { host: h, config, logDir: sb.logDir, errorFile: sb.errorFile };
}

export interface ToolReply {
  isError: boolean;
  envelope: Record<string, unknown>;
  raw: string;
}

export async function call(h: McpServerHost, name: string, args: unknown = {}): Promise<ToolReply> {
  const res = await h.handleCallTool(name, args);
  if (res.content.length !== 1 || res.content[0]?.type !== 'text') throw new Error('expected one text block');
  const raw = res.content[0].text;
  return { isError: res.isError === true, envelope: JSON.parse(raw) as Record<string, unknown>, raw };
}

export function errorOf(r: ToolReply): { code: string; message: string; hint?: string; details?: unknown } {
  return r.envelope.error as { code: string; message: string; hint?: string; details?: unknown };
}

// ------------------------------------------------------- spawned server ---

export interface Spawned {
  request(method: string, params?: unknown): Promise<Record<string, unknown>>;
  notify(method: string, params?: unknown): void;
  stdoutLines: string[];
  stderr(): string;
  close(): Promise<number | null>;
}

export function spawnServer(env: NodeJS.ProcessEnv, args: string[] = ['serve']): Spawned {
  const child = spawn(process.execPath, [ENTRY, ...args], { env, stdio: ['pipe', 'pipe', 'pipe'] });
  const stdoutLines: string[] = [];
  let pending = '';
  let err = '';
  let nextId = 1;
  const waiters = new Map<number, (msg: Record<string, unknown>) => void>();
  child.stdout.setEncoding('utf8');
  child.stdout.on('data', (chunk: string) => {
    pending += chunk;
    let nl = pending.indexOf('\n');
    while (nl !== -1) {
      const line = pending.slice(0, nl);
      pending = pending.slice(nl + 1);
      stdoutLines.push(line);
      try {
        const msg = JSON.parse(line) as Record<string, unknown>;
        const w = waiters.get(msg.id as number);
        if (w) {
          waiters.delete(msg.id as number);
          w(msg);
        }
      } catch {
        /* a non-JSON line is a purity failure the test asserts on */
      }
      nl = pending.indexOf('\n');
    }
  });
  child.stderr.setEncoding('utf8');
  child.stderr.on('data', (c: string) => (err += c));
  const exited = new Promise<number | null>((resolve) => child.on('exit', (code) => resolve(code)));
  return {
    stdoutLines,
    stderr: () => err,
    request(method, params) {
      const id = nextId++;
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error(`timeout waiting for ${method}; stderr: ${err}`)), 10_000);
        waiters.set(id, (m) => {
          clearTimeout(timer);
          resolve(m);
        });
        child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', id, method, ...(params !== undefined ? { params } : {}) })}\n`);
      });
    },
    notify(method, params) {
      child.stdin.write(`${JSON.stringify({ jsonrpc: '2.0', method, ...(params !== undefined ? { params } : {}) })}\n`);
    },
    async close() {
      child.stdin.end();
      const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
      const code = await exited;
      clearTimeout(timer);
      return code;
    },
  };
}

/** Run the built server to completion (for refuse-to-start tests). */
export function runToExit(env: NodeJS.ProcessEnv, args: string[] = ['serve']): Promise<{ code: number | null; stdout: string; stderr: string }> {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [ENTRY, ...args], { env, stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (c: Buffer) => (stdout += c.toString()));
    child.stderr.on('data', (c: Buffer) => (stderr += c.toString()));
    const timer = setTimeout(() => child.kill('SIGKILL'), 10_000);
    child.on('exit', (code) => {
      clearTimeout(timer);
      resolve({ code, stdout, stderr });
    });
  });
}

export const INITIALIZE = { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'test', version: '1' } };
