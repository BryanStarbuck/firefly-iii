/**
 * Configuration — every FFMCP_* variable (pm/mcp.mdx §14), read ONCE at
 * startup into a frozen object, before the transport attaches (§15), so a
 * misconfiguration is a clean refusal rather than a tool that fails later.
 *
 * The target rule (§7.3, gate 3): the base URL is loopback, or it is https:
 * AND FFMCP_ALLOW_REMOTE=1 — and a remote target is read-only whatever the
 * write switch says.
 */
import os from 'node:os';
import path from 'node:path';

import type { Config as CredentialsConfig } from './credentials-support.js';

export const SERVER_KEY = 'firefly_iii';
export const TOOL_PREFIX = 'ff_';
export const CLI_BINARY = 'ffx';
export const DEFAULT_API = 'http://127.0.0.1:7373';
export const PLANE_PREFIX = '/machine/v1';
export const DEFAULT_CREDENTIALS_DISPLAY = '~/.credentials/firefly_iii.json';

export type Target = 'local' | 'remote';
export type LogLevel = 'error' | 'warn' | 'info' | 'debug';

export interface McpConfig {
  apiUrl: string;
  target: Target;
  /** FFMCP_ALLOW_WRITE=1 — this side's switch. Always false on a remote target. */
  allowWrite: boolean;
  /** True when FFMCP_ALLOW_WRITE=1 was set but the target is remote (writes forced off). */
  writeForcedOffByRemote: boolean;
  maxChanges: number;
  maxRows: number;
  maxBytes: number;
  timeoutMs: number;
  promptFile: string | undefined;
  logLevel: LogLevel;
  logDir: string;
  credentialsFile: string;
  keyFromEnv: string | undefined;
  keyFile: string | undefined;
  home: string;
}

/** A refusal to start: one stderr line and exit 2 (index.ts). */
export class ConfigError extends Error {
  readonly fix: string;
  constructor(message: string, fix: string) {
    super(message);
    this.name = 'ConfigError';
    this.fix = fix;
  }
}

function expandHome(p: string, home: string): string {
  if (p === '~') return home;
  if (p.startsWith('~/')) return path.join(home, p.slice(2));
  return p;
}

function flag(value: string | undefined): boolean {
  return value === '1' || value === 'true' || value === 'yes';
}

function positiveInt(name: string, raw: string | undefined, fallback: number): number {
  if (raw === undefined || raw.trim() === '') return fallback;
  if (!/^\d+$/.test(raw.trim()) || raw.trim() === '0') {
    throw new ConfigError(`${name} must be a positive integer, got "${raw}"`, `unset ${name} or set it to e.g. ${fallback}`);
  }
  return parseInt(raw.trim(), 10);
}

/** Loopback hosts — the only ones the key may be sent to over plain http. */
export function isLoopbackHost(hostname: string): boolean {
  const h = hostname.replace(/^\[|\]$/g, '').toLowerCase();
  return h === '127.0.0.1' || h === 'localhost' || h === '::1' || h === '::ffff:127.0.0.1';
}

/** Classify a base URL, or throw the refusal gate 3 produces. */
export function classifyTarget(apiUrl: string, allowRemote: boolean): Target {
  let url: URL;
  try {
    url = new URL(apiUrl);
  } catch {
    throw new ConfigError(`FFMCP_API_URL is not a URL: ${apiUrl}`, `unset FFMCP_API_URL to use ${DEFAULT_API}`);
  }
  if (url.protocol !== 'http:' && url.protocol !== 'https:') {
    throw new ConfigError(`FFMCP_API_URL has an unsupported scheme ${url.protocol}`, 'use http://127.0.0.1:<port> or an https: URL');
  }
  if (url.username || url.password) {
    throw new ConfigError('FFMCP_API_URL must not carry credentials', 'remove the user:password@ part');
  }
  if (isLoopbackHost(url.hostname)) return 'local';
  if (!allowRemote) {
    throw new ConfigError(
      `refused: ${url.host} is not loopback — this server talks to the operator's own install on this computer`,
      'use http://127.0.0.1:7373, or set FFMCP_ALLOW_REMOTE=1 with an https: URL (read-only)',
    );
  }
  if (url.protocol !== 'https:') {
    throw new ConfigError(
      `refused: a remote target must be https: (${url.host} over plain http would send the machine key in cleartext)`,
      'use an https: URL for a remote install',
    );
  }
  return 'remote';
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): McpConfig {
  const home = env.HOME ?? os.homedir();
  const apiUrl = (env.FFMCP_API_URL?.trim() || DEFAULT_API).replace(/\/+$/, '');
  const allowRemote = flag(env.FFMCP_ALLOW_REMOTE);
  const target = classifyTarget(apiUrl, allowRemote);
  const wantWrite = flag(env.FFMCP_ALLOW_WRITE);
  const levelRaw = (env.FFMCP_LOG_LEVEL ?? 'info').toLowerCase();
  if (!['error', 'warn', 'info', 'debug'].includes(levelRaw)) {
    throw new ConfigError(`FFMCP_LOG_LEVEL must be error, warn, info or debug, got "${levelRaw}"`, 'unset FFMCP_LOG_LEVEL');
  }
  return Object.freeze({
    apiUrl,
    target,
    allowWrite: wantWrite && target === 'local',
    writeForcedOffByRemote: wantWrite && target === 'remote',
    maxChanges: positiveInt('FFMCP_MAX_CHANGES', env.FFMCP_MAX_CHANGES, 200),
    maxRows: positiveInt('FFMCP_MAX_ROWS', env.FFMCP_MAX_ROWS, 1000),
    maxBytes: positiveInt('FFMCP_MAX_BYTES', env.FFMCP_MAX_BYTES, 1_048_576),
    timeoutMs: positiveInt('FFMCP_TIMEOUT_MS', env.FFMCP_TIMEOUT_MS, 30_000),
    promptFile: env.FFMCP_PROMPT_FILE ? expandHome(env.FFMCP_PROMPT_FILE, home) : undefined,
    logLevel: levelRaw as LogLevel,
    logDir: expandHome(env.FFMCP_LOG_DIR ?? path.join(home, 'T', '_firefly_iii'), home),
    credentialsFile: expandHome(env.FFMCP_CREDENTIALS_FILE ?? path.join(home, '.credentials', 'firefly_iii.json'), home),
    keyFromEnv: env.FFMCP_MACHINE_KEY,
    keyFile: env.FFMCP_MACHINE_KEY_FILE ? expandHome(env.FFMCP_MACHINE_KEY_FILE, home) : undefined,
    home,
  });
}

/** The shape credentials.ts (the CLI's module) reads, built from FFMCP_* values. */
export function credentialsConfig(cfg: McpConfig): CredentialsConfig {
  return {
    apiUrl: cfg.apiUrl,
    credentialsFile: cfg.credentialsFile,
    keyFromEnv: cfg.keyFromEnv,
    keyFile: cfg.keyFile,
    statementsDir: undefined,
    home: cfg.home,
  };
}

/**
 * credentials.ts is the CLI's module verbatim, so its messages name the CLI's
 * variables. The MCP reads the FFMCP_ twins; translate before printing.
 */
export function mcpWording(text: string): string {
  return text.replace(/\bFFX_(MACHINE_KEY_FILE|MACHINE_KEY|CREDENTIALS_FILE)\b/g, 'FFMCP_$1');
}
