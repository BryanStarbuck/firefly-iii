/**
 * Bring-up — pm/cli.mdx §3. The CLI's justfile-equivalent duty.
 *
 * If the app is down, the CLI starts it — the same effective work as
 * `just server-bg` — and gates on Laravel's /up before any call:
 *
 *   php artisan serve --host=127.0.0.1 --port=7373   (detached, PHP_CLI_SERVER_WORKERS=4)
 *   stdout+stderr → ~/T/_firefly_iii/server.log, pid → server.pid
 *
 * It refuses, loudly and with the fix, rather than guessing:
 *   - no php / php older than 8.5          → brew install php
 *   - no vendor/                           → just setup   (never composer install unasked)
 *   - a FOREIGN process on the port        → named, never killed
 *
 * child_process is used here and nowhere else in the CLI, with fixed argv and
 * no shell, and never with operator-supplied text.
 */
import { spawn, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';

import { probeUp } from './client.js';
import type { Config } from './config.js';
import { portOf, tildify } from './config.js';
import { CliError, EXIT } from './errors.js';
import { log } from './logger.js';
import type { Spinner } from './progress.js';

export const MIN_PHP = [8, 5] as const;
const HEALTH_WAIT_MS = 60_000;

export function serverLogPath(cfg: Config): string {
  return path.join(cfg.stateDir, 'server.log');
}

export function pidFilePath(cfg: Config): string {
  return path.join(cfg.stateDir, 'server.pid');
}

export interface PhpInfo {
  found: boolean;
  version?: string;
  ok: boolean;
}

export function phpInfo(): PhpInfo {
  const r = spawnSync('php', ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8', timeout: 10_000 });
  if (r.error || r.status !== 0) return { found: false, ok: false };
  const version = r.stdout.trim();
  const [maj = 0, min = 0] = version.split('.').map((p) => parseInt(p, 10));
  return { found: true, version, ok: maj > MIN_PHP[0] || (maj === MIN_PHP[0] && min >= MIN_PHP[1]) };
}

/** A command on PATH, without spawning it. */
export function onPath(cmd: string): string | undefined {
  for (const dir of (process.env.PATH ?? '').split(path.delimiter)) {
    if (!dir) continue;
    const full = path.join(dir, cmd);
    try {
      fs.accessSync(full, fs.constants.X_OK);
      return full;
    } catch {
      /* keep looking */
    }
  }
  return undefined;
}

export function readPid(cfg: Config): number | undefined {
  try {
    const n = parseInt(fs.readFileSync(pidFilePath(cfg), 'utf8').trim(), 10);
    return n > 0 ? n : undefined;
  } catch {
    return undefined;
  }
}

export function isAlive(pid: number): boolean {
  try {
    process.kill(pid, 0);
    return true;
  } catch (err) {
    return (err as NodeJS.ErrnoException).code === 'EPERM';
  }
}

/**
 * Who holds a TCP port. `pid` via lsof; when lsof is absent (or answers
 * nothing) a plain TCP connect still tells us whether SOMETHING listens —
 * treating "lsof missing" as "port free" would start a second server, which
 * `php artisan serve` then silently moves to the next port.
 */
export interface PortHolder {
  held: boolean;
  pid?: number;
}

export function listenerPid(port: number): number | undefined {
  const r = spawnSync('lsof', ['-nP', `-iTCP:${port}`, '-sTCP:LISTEN', '-t'], { encoding: 'utf8', timeout: 5000 });
  if (r.error || !r.stdout) return undefined;
  const n = parseInt(r.stdout.trim().split('\n')[0] ?? '', 10);
  return n > 0 ? n : undefined;
}

export function portAcceptsConnections(host: string, port: number, timeoutMs = 1000): Promise<boolean> {
  return new Promise((resolve) => {
    const sock = net.connect({ host, port });
    const done = (v: boolean): void => {
      sock.destroy();
      resolve(v);
    };
    sock.setTimeout(timeoutMs, () => done(false));
    sock.once('connect', () => done(true));
    sock.once('error', () => done(false));
  });
}

export async function portHolder(host: string, port: number): Promise<PortHolder> {
  const pid = listenerPid(port);
  if (pid !== undefined) return { held: true, pid };
  return { held: await portAcceptsConnections(host, port) };
}

function psField(pid: number, field: 'ppid' | 'pgid' | 'comm' | 'command'): string | undefined {
  const r = spawnSync('ps', ['-o', `${field}=`, '-p', String(pid)], { encoding: 'utf8', timeout: 5000 });
  return r.error || r.status !== 0 ? undefined : r.stdout.trim();
}

/** True when `pid` is the recorded server or belongs to its process group / is its child. */
export function isOurs(cfg: Config, pid: number): boolean {
  const recorded = readPid(cfg);
  if (recorded === undefined) return false;
  if (pid === recorded) return true;
  const ppid = parseInt(psField(pid, 'ppid') ?? '', 10);
  const pgid = parseInt(psField(pid, 'pgid') ?? '', 10);
  return ppid === recorded || pgid === recorded;
}

export function tailFile(file: string, lines = 20): string[] {
  if (lines <= 0) return []; // slice(-0) is the WHOLE file, not none of it
  try {
    const text = fs.readFileSync(file, 'utf8');
    const all = text.split('\n');
    if (all[all.length - 1] === '') all.pop();
    return all.slice(-lines);
  } catch {
    return [];
  }
}

function failWithLogTail(cfg: Config, message: string, hint?: string): CliError {
  const tail = tailFile(serverLogPath(cfg));
  return new CliError(EXIT.UNAVAILABLE, message, {
    details: tail.length ? [`last lines of ${tildify(serverLogPath(cfg), cfg.home)}:`, ...tail.map((l) => `  ${l}`)] : [],
    hint: hint ?? 'ffx logs',
  });
}

/** Refusals that do not depend on the port — checked before anything is started. */
export function preflightRefusal(cfg: Config): CliError | undefined {
  if (!fs.existsSync(path.join(cfg.repoRoot, 'artisan'))) {
    return new CliError(EXIT.UNAVAILABLE, `${tildify(cfg.repoRoot, cfg.home)} does not look like the firefly-iii checkout (no artisan)`, {
      hint: 'set FFX_REPO_ROOT, or run the ffx that lives in this checkout',
    });
  }
  const php = phpInfo();
  if (!php.found) {
    return new CliError(EXIT.UNAVAILABLE, 'php is not on PATH — Firefly III needs PHP 8.5', { hint: 'brew install php composer' });
  }
  if (!php.ok) {
    return new CliError(EXIT.UNAVAILABLE, `php ${php.version} is too old — Firefly III needs PHP ${MIN_PHP.join('.')} or newer`, {
      hint: 'brew upgrade php',
    });
  }
  if (!fs.existsSync(path.join(cfg.repoRoot, 'vendor', 'autoload.php'))) {
    return new CliError(EXIT.UNAVAILABLE, 'Composer dependencies are not installed (no vendor/)', { hint: 'just setup' });
  }
  if (!fs.existsSync(path.join(cfg.repoRoot, '.env'))) {
    return new CliError(EXIT.UNAVAILABLE, 'no .env in the checkout — the app has no database configured', { hint: 'just setup' });
  }
  return undefined;
}

function sleep(ms: number): Promise<void> {
  return new Promise((r) => setTimeout(r, ms));
}

function lockFilePath(cfg: Config): string {
  return path.join(cfg.stateDir, 'server.lock');
}

const BRINGUP_LOCK_STALE_MS = HEALTH_WAIT_MS + 30_000;

/**
 * One bring-up at a time. Two ffx invocations that both find the app down
 * would otherwise both spawn a server: the second lands on :7374 (artisan
 * serve moves on when the port is taken), and its pid overwrites server.pid,
 * so `ffx stop` stops the wrong one and the first is orphaned.
 */
async function withBringupLock<T>(cfg: Config, fn: () => Promise<T>): Promise<T> {
  fs.mkdirSync(cfg.stateDir, { recursive: true, mode: 0o700 });
  const lock = lockFilePath(cfg);
  const deadline = Date.now() + BRINGUP_LOCK_STALE_MS;
  let fd: number | undefined;
  while (fd === undefined) {
    try {
      fd = fs.openSync(lock, 'wx', 0o600);
      fs.writeSync(fd, `${process.pid}\n`);
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code !== 'EEXIST') throw err;
      try {
        const holder = parseInt(fs.readFileSync(lock, 'utf8').trim(), 10);
        const age = Date.now() - fs.statSync(lock).mtimeMs;
        if (!(holder > 0 && isAlive(holder)) || age > BRINGUP_LOCK_STALE_MS) fs.rmSync(lock, { force: true });
      } catch {
        /* released while we looked */
      }
      if (Date.now() > deadline) throw new CliError(EXIT.UNAVAILABLE, `another ffx is bringing Firefly III up (${lock})`, { hint: 'wait for it, or ffx status' });
      await sleep(250);
    }
  }
  try {
    return await fn();
  } finally {
    fs.closeSync(fd);
    fs.rmSync(lock, { force: true });
  }
}

/** Start `php artisan serve` detached, and wait for /up. */
export async function startDetached(cfg: Config, spinner: Spinner): Promise<number> {
  const refusal = preflightRefusal(cfg);
  if (refusal) throw refusal;
  return withBringupLock(cfg, async () => {
    // Another ffx may have brought it up while we waited for the lock.
    if (await probeUp(cfg)) return readPid(cfg) ?? 0;
    return spawnAndWait(cfg, spinner);
  });
}

async function spawnAndWait(cfg: Config, spinner: Spinner): Promise<number> {
  const url = new URL(cfg.apiUrl);
  const port = portOf(cfg.apiUrl);
  const holder = await portHolder(url.hostname, port);
  if (holder.held) {
    if (holder.pid === undefined) {
      throw new CliError(EXIT.UNAVAILABLE, `port ${port} is taken by a process ffx cannot identify (lsof is not available), and it is not answering /up`, {
        hint: 'find it yourself (e.g. lsof -iTCP:' + port + ') — ffx never starts a second server beside it, and never kills a port',
      });
    }
    if (!isOurs(cfg, holder.pid)) {
      const comm = psField(holder.pid, 'command') ?? psField(holder.pid, 'comm') ?? 'unknown';
      throw new CliError(EXIT.UNAVAILABLE, `port ${port} is held by a process ffx did not start: pid ${holder.pid} (${comm})`, {
        hint: 'stop it yourself, or point ffx elsewhere with --api — ffx never kills a foreign process',
      });
    }
    throw failWithLogTail(cfg, `our server (pid ${readPid(cfg)}) holds :${port} but /up is not answering`, 'ffx stop && ffx up');
  }


  fs.mkdirSync(cfg.stateDir, { recursive: true, mode: 0o700 });
  const logFd = fs.openSync(serverLogPath(cfg), 'a', 0o600);
  fs.writeSync(logFd, `\n==== ffx up ${new Date().toISOString()} ====\n`);
  const child = spawn('php', ['artisan', 'serve', `--host=${url.hostname}`, `--port=${port}`], {
    cwd: cfg.repoRoot,
    detached: true,
    stdio: ['ignore', logFd, logFd],
    env: { ...process.env, PHP_CLI_SERVER_WORKERS: process.env.PHP_CLI_SERVER_WORKERS ?? '4' },
  });
  fs.closeSync(logFd);
  let exited: number | null = null;
  child.on('exit', (code) => {
    exited = code ?? -1;
  });
  child.unref();
  if (child.pid === undefined) throw failWithLogTail(cfg, 'could not start php artisan serve');
  fs.writeFileSync(pidFilePath(cfg), `${child.pid}\n`, { mode: 0o600 });
  log.info(`bring-up: started php artisan serve pid=${child.pid} port=${port}`);

  spinner.start(`Starting Firefly III on ${cfg.apiUrl}…`);
  const deadline = Date.now() + HEALTH_WAIT_MS;
  while (Date.now() < deadline) {
    if (await probeUp(cfg, 2000)) {
      spinner.stop();
      return child.pid;
    }
    if (exited !== null) {
      spinner.stop();
      throw failWithLogTail(cfg, `php artisan serve exited (code ${exited}) before /up answered`);
    }
    spinner.tick('Waiting for Firefly III to answer /up…');
    await sleep(500);
  }
  spinner.stop();
  throw failWithLogTail(cfg, `Firefly III did not answer /up within ${HEALTH_WAIT_MS / 1000}s`);
}

export interface EnsureOptions {
  noBringup: boolean;
}

/** The preflight — §3.1. Returns true when this call started the app. */
export async function ensureUp(cfg: Config, spinner: Spinner, opts: EnsureOptions): Promise<boolean> {
  if (await probeUp(cfg)) return false;
  if (!cfg.isDefaultTarget) {
    throw new CliError(EXIT.UNAVAILABLE, `${cfg.apiUrl} is not answering /up, and ffx only brings up the local install`, {
      hint: 'start that install yourself',
    });
  }
  if (opts.noBringup) {
    throw new CliError(EXIT.UNAVAILABLE, `Firefly III is not running at ${cfg.apiUrl}`, { hint: 'ffx up' });
  }
  await startDetached(cfg, spinner);
  return true;
}

export interface StopResult {
  stopped: boolean;
  pid?: number;
  reason?: string;
}

/** Stop OUR instance: the recorded pid's process group. Never a foreign process. */
export async function stopOurs(cfg: Config): Promise<StopResult> {
  const pid = readPid(cfg);
  if (pid === undefined) return { stopped: false, reason: 'no server.pid — ffx did not start the running app (if any)' };
  if (!isAlive(pid)) {
    fs.rmSync(pidFilePath(cfg), { force: true });
    return { stopped: false, pid, reason: `recorded pid ${pid} is not running (stale pid file removed)` };
  }
  // A stale server.pid whose number the OS has since reused belongs to somebody else's process
  // (group). Only signal it when it still looks like the php server ffx started.
  const command = psField(pid, 'command') ?? psField(pid, 'comm');
  if (command !== undefined && !/\bphp\b|artisan/.test(command)) {
    fs.rmSync(pidFilePath(cfg), { force: true });
    return { stopped: false, pid, reason: `recorded pid ${pid} is now "${command}", not the php server ffx started — stale pid file removed, nothing stopped` };
  }
  try {
    process.kill(-pid, 'SIGTERM');
  } catch {
    try {
      process.kill(pid, 'SIGTERM');
    } catch {
      /* raced with exit */
    }
  }
  const deadline = Date.now() + 5000;
  while (Date.now() < deadline && (isAlive(pid) || (await probeUp(cfg, 500)))) await sleep(200);
  if (isAlive(pid)) {
    try {
      process.kill(-pid, 'SIGKILL');
    } catch {
      /* gone */
    }
  }
  fs.rmSync(pidFilePath(cfg), { force: true });
  log.info(`stop: stopped pid=${pid}`);
  return { stopped: true, pid };
}
