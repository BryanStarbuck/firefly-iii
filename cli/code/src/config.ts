/**
 * Configuration — every FFX_* variable, the paths, and target resolution.
 *
 * Read once, frozen. The resolved target is printed on stderr under --verbose
 * and always before a write (pm/cli.mdx §7.2).
 */
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const DEFAULT_HOST = '127.0.0.1';
export const DEFAULT_PORT = 7373;
export const DEFAULT_API = `http://${DEFAULT_HOST}:${DEFAULT_PORT}`;
export const PLANE_PREFIX = '/machine/v1';

export interface Config {
  /** The app's base URL, no trailing slash. */
  apiUrl: string;
  /** True when apiUrl was not overridden — the local install this checkout runs. */
  isDefaultTarget: boolean;
  /** The firefly-iii checkout this CLI belongs to. */
  repoRoot: string;
  /** ~/T/_firefly_iii — logs, pid file. */
  stateDir: string;
  credentialsFile: string;
  keyFromEnv: string | undefined;
  keyFile: string | undefined;
  statementsDir: string | undefined;
  noBringup: boolean;
  home: string;
}

function expandHome(p: string, home: string): string {
  if (p === '~') return home;
  if (p.startsWith('~/')) return path.join(home, p.slice(2));
  return p;
}

/** cli/code/dist/src/config.js → the repo root is four directories up. */
function defaultRepoRoot(): string {
  const here = path.dirname(fileURLToPath(import.meta.url));
  return path.resolve(here, '..', '..', '..', '..');
}

function envFlag(value: string | undefined): boolean {
  return value === '1' || value === 'true' || value === 'yes';
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env, apiOverride?: string): Config {
  const home = env.HOME ?? os.homedir();
  const rawApi = apiOverride ?? env.FFX_API_URL;
  const apiUrl = (rawApi ?? DEFAULT_API).replace(/\/+$/, '');
  return Object.freeze({
    apiUrl,
    isDefaultTarget: rawApi === undefined,
    repoRoot: expandHome(env.FFX_REPO_ROOT ?? defaultRepoRoot(), home),
    stateDir: expandHome(env.FFX_STATE_DIR ?? path.join(home, 'T', '_firefly_iii'), home),
    credentialsFile: expandHome(
      env.FFX_CREDENTIALS_FILE ?? path.join(home, '.credentials', 'firefly_iii.json'),
      home,
    ),
    keyFromEnv: env.FFX_MACHINE_KEY,
    keyFile: env.FFX_MACHINE_KEY_FILE ? expandHome(env.FFX_MACHINE_KEY_FILE, home) : undefined,
    statementsDir: env.FFX_STATEMENTS_DIR ? expandHome(env.FFX_STATEMENTS_DIR, home) : undefined,
    noBringup: envFlag(env.FFX_NO_BRINGUP),
    home,
  });
}

/** Loopback hosts — the only ones the key may be sent to over plain http (R3). */
export function isLoopbackHost(hostname: string): boolean {
  const h = hostname.replace(/^\[|\]$/g, '').toLowerCase();
  return h === '127.0.0.1' || h === 'localhost' || h === '::1' || h === '::ffff:127.0.0.1';
}

/**
 * R3 — the key is never attached to a URL that is neither loopback nor https.
 * Returns a reason string when the URL is not acceptable.
 */
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

export function portOf(apiUrl: string): number {
  const url = new URL(apiUrl);
  if (url.port) return parseInt(url.port, 10);
  return url.protocol === 'https:' ? 443 : 80;
}

/** Shorten the home directory to ~ for display. */
export function tildify(p: string, home: string): string {
  return p === home ? '~' : p.startsWith(home + path.sep) ? '~' + p.slice(home.length) : p;
}
