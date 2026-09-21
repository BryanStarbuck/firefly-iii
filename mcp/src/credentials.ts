/**
 * The machine key — pm/mcp.mdx §6, apis.mdx §4 (the authority).
 *
 * THIS FILE IS THE CLI'S cli/code/src/credentials.ts, BYTE FOR BYTE BELOW THE
 * IMPORT BLOCK. It is duplicated rather than imported (two independently built
 * tools must not share a build-order dependency), and test/parity.test.ts
 * fails on any drift — so a fix goes into BOTH files in the same change.
 *
 * The MCP uses only the read half: loadMachineKey() and fingerprint(). It
 * never mints or rotates (it refuses to start without a key, §6.3); the
 * writers stay in the file because the file is the CLI's, and the no-fs-write
 * canary names this module as the one place a credentials write may live.
 */
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

import type { Config } from './credentials-support.js';
import { tildify } from './credentials-support.js';
import { CliError, EXIT } from './credentials-support.js';

export const APP_KEY = 'firefly_iii';
const KEY_SHAPE = /^[0-9a-f]{64}$/;

export type KeySource = 'env' | 'key-file' | 'credentials-file';

export interface MachineKey {
  key: string;
  source: KeySource;
  /** The file it came from, for messages. Undefined for the env source. */
  file: string | undefined;
}

export interface CredentialsDoc {
  [product: string]: unknown;
}

interface MachineBlock {
  api_key?: unknown;
  created?: unknown;
  created_by?: unknown;
  label?: unknown;
}

export function isWellFormedKey(value: unknown): value is string {
  return typeof value === 'string' && KEY_SHAPE.test(value);
}

/** The ONLY printable representation of a key: first 4 hex + a SHA-256 prefix. */
export function fingerprint(key: string): string {
  const digest = crypto.createHash('sha256').update(key).digest('hex');
  return `${key.slice(0, 4)}…/sha256:${digest.slice(0, 4)}`;
}

/** 32 bytes from the OS CSPRNG, as 64 lowercase hex — longer than a UUID, not UUID-shaped. */
export function mintKey(): string {
  return crypto.randomBytes(32).toString('hex');
}

function fixHint(file: string, home: string): string {
  return `chmod 600 ${tildify(file, home)}`;
}

/**
 * Stat before read (R7): a regular file, not a symlink, 0600-or-tighter, ours.
 * Returns undefined when the file does not exist.
 */
export function checkCredentialsFile(file: string, home: string): fs.Stats | undefined {
  let st: fs.Stats;
  try {
    st = fs.lstatSync(file);
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === 'ENOENT') return undefined;
    throw new CliError(EXIT.USAGE, `cannot stat ${tildify(file, home)}: ${(err as Error).message}`);
  }
  if (st.isSymbolicLink()) {
    throw new CliError(EXIT.USAGE, `refused: ${tildify(file, home)} is a symlink`, {
      hint: 'replace it with a regular file — a symlinked secret is somebody redirecting it',
    });
  }
  if (!st.isFile()) {
    throw new CliError(EXIT.USAGE, `refused: ${tildify(file, home)} is not a regular file`);
  }
  if ((st.mode & 0o077) !== 0) {
    throw new CliError(
      EXIT.USAGE,
      `refused: ${tildify(file, home)} is readable by others (mode ${(st.mode & 0o777).toString(8)})`,
      { hint: fixHint(file, home) },
    );
  }
  const uid = typeof process.getuid === 'function' ? process.getuid() : undefined;
  if (uid !== undefined && st.uid !== uid) {
    throw new CliError(EXIT.USAGE, `refused: ${tildify(file, home)} is owned by uid ${st.uid}, not you`, {
      hint: `chown ${os.userInfo().username} ${tildify(file, home)} && ${fixHint(file, home)}`,
    });
  }
  return st;
}

export function readCredentialsDoc(file: string, home: string): CredentialsDoc | undefined {
  const st = checkCredentialsFile(file, home);
  if (!st) return undefined;
  // Read through a descriptor opened with O_NOFOLLOW and check it is the file we just stat'ed:
  // a symlink swapped in between the lstat and the read is refused, not followed (R7).
  let text: string;
  let fd: number | undefined;
  try {
    fd = fs.openSync(file, fs.constants.O_RDONLY | (fs.constants.O_NOFOLLOW ?? 0));
    const same = fs.fstatSync(fd);
    if (same.ino !== st.ino || same.dev !== st.dev) {
      throw new CliError(EXIT.USAGE, `refused: ${tildify(file, home)} changed while it was being read`);
    }
    text = fs.readFileSync(fd, 'utf8');
  } catch (err) {
    if (err instanceof CliError) throw err;
    throw new CliError(EXIT.USAGE, `cannot read ${tildify(file, home)}: ${(err as Error).message}`);
  } finally {
    if (fd !== undefined) fs.closeSync(fd);
  }
  if (text.trim() === '') return {};
  try {
    const parsed: unknown = JSON.parse(text);
    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new Error('top level is not an object');
    }
    return parsed as CredentialsDoc;
  } catch (err) {
    throw new CliError(EXIT.USAGE, `${tildify(file, home)} is not valid JSON: ${(err as Error).message}`, {
      hint: 'fix the file by hand — it may hold other products\' secrets, so it is never overwritten',
    });
  }
}

function productBlock(doc: CredentialsDoc | undefined): Record<string, unknown> | undefined {
  const block = doc?.[APP_KEY];
  return block && typeof block === 'object' && !Array.isArray(block) ? (block as Record<string, unknown>) : undefined;
}

function machineBlock(doc: CredentialsDoc | undefined): MachineBlock | undefined {
  const m = productBlock(doc)?.machine;
  return m && typeof m === 'object' ? (m as MachineBlock) : undefined;
}

/** firefly_iii.statements.root, if configured. */
export function statementsRootFromDoc(doc: CredentialsDoc | undefined): string | undefined {
  const s = productBlock(doc)?.statements;
  if (s && typeof s === 'object') {
    const root = (s as { root?: unknown }).root;
    if (typeof root === 'string' && root.trim()) return root;
  }
  return undefined;
}

function missingKeyError(cfg: Config): CliError {
  return new CliError(EXIT.USAGE, `refused: no machine key for ${cfg.apiUrl}`, {
    details: [
      `Looked in: FFX_MACHINE_KEY, FFX_MACHINE_KEY_FILE,`,
      `           ${tildify(cfg.credentialsFile, cfg.home)}  (${APP_KEY}.machine.api_key)`,
      `The Firefly web app mints this on its first run. Start it and try again:`,
      `  ffx up`,
      `Or mint it here without starting anything:`,
      `  ffx key init`,
    ],
  });
}

/** Resolution order (R6, §4.2): env → key file → credentials file → refuse loudly. */
export function loadMachineKey(cfg: Config): MachineKey {
  if (cfg.keyFromEnv !== undefined) {
    const key = cfg.keyFromEnv.trim();
    if (!isWellFormedKey(key)) {
      throw new CliError(EXIT.USAGE, 'FFX_MACHINE_KEY is set but is not 64 lowercase hex characters', {
        hint: 'unset it to use the credentials file',
      });
    }
    return { key, source: 'env', file: undefined };
  }
  if (cfg.keyFile !== undefined) {
    checkCredentialsFile(cfg.keyFile, cfg.home);
    let key: string;
    try {
      key = fs.readFileSync(cfg.keyFile, 'utf8').trim();
    } catch (err) {
      throw new CliError(EXIT.USAGE, `cannot read FFX_MACHINE_KEY_FILE: ${(err as Error).message}`);
    }
    if (!isWellFormedKey(key)) {
      throw new CliError(EXIT.USAGE, `${tildify(cfg.keyFile, cfg.home)} does not hold a 64-hex key`);
    }
    return { key, source: 'key-file', file: cfg.keyFile };
  }
  const doc = readCredentialsDoc(cfg.credentialsFile, cfg.home);
  const key = machineBlock(doc)?.api_key;
  if (key === undefined) throw missingKeyError(cfg);
  if (!isWellFormedKey(key)) {
    throw new CliError(EXIT.USAGE, `${APP_KEY}.machine.api_key in ${tildify(cfg.credentialsFile, cfg.home)} is malformed`, {
      hint: 'ffx key rotate --yes   (mints a well-formed key; restart the MCP afterwards)',
    });
  }
  return { key, source: 'credentials-file', file: cfg.credentialsFile };
}

/** Like loadMachineKey, but returns undefined instead of throwing on "no key" (for reports). */
export function tryLoadMachineKey(cfg: Config): { key?: MachineKey; problem?: CliError } {
  try {
    return { key: loadMachineKey(cfg) };
  } catch (err) {
    if (err instanceof CliError) return { problem: err };
    // An unreadable file (EACCES, EISDIR…) is a report line for bare `ffx`/status/doctor, never a crash.
    return { problem: new CliError(EXIT.USAGE, `cannot read the machine key: ${(err as Error).message}`) };
  }
}

function sleepSync(ms: number): void {
  Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms);
}

/**
 * An exclusive lock file beside the credentials file; stale locks (>30 s) are
 * broken. Breaking is inode-checked: two waiters that both saw the SAME stale
 * lock must not have the second one delete the fresh lock the first just took.
 * Release likewise removes the lock only while it is still ours.
 */
function withLock<T>(file: string, fn: () => T): T {
  const lock = `${file}.lock`;
  const deadline = Date.now() + 5000;
  let fd: number | undefined;
  while (fd === undefined) {
    try {
      fd = fs.openSync(lock, 'wx', 0o600);
    } catch (err) {
      if ((err as NodeJS.ErrnoException).code !== 'EEXIST') throw err;
      try {
        const seen = fs.lstatSync(lock);
        if (Date.now() - seen.mtimeMs > 30_000) {
          const now = fs.lstatSync(lock);
          if (now.ino === seen.ino && now.dev === seen.dev) fs.unlinkSync(lock);
        }
      } catch {
        /* raced with the holder releasing it */
      }
      if (Date.now() > deadline) {
        throw new CliError(EXIT.CONFLICT, `could not lock ${lock} — another process is writing the credentials file`);
      }
      sleepSync(50);
    }
  }
  const mine = fs.fstatSync(fd);
  try {
    return fn();
  } finally {
    fs.closeSync(fd);
    try {
      const st = fs.lstatSync(lock);
      if (st.ino === mine.ino && st.dev === mine.dev) fs.unlinkSync(lock);
    } catch {
      /* already gone */
    }
  }
}

/**
 * Merge-write: read, mutate our subtree, write back — preserving every key we
 * do not own. Atomic (O_EXCL temp, 0600 from creation, fsync, rename) and
 * symlink-refusing at the destination.
 */
export function writeMerged(file: string, home: string, mutate: (doc: CredentialsDoc) => void): void {
  const dir = path.dirname(file);
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  withLock(file, () => {
    const doc = readCredentialsDoc(file, home) ?? {};
    mutate(doc);
    const tmp = path.join(dir, `.${path.basename(file)}.${process.pid}.${crypto.randomBytes(4).toString('hex')}.tmp`);
    const fd = fs.openSync(tmp, 'wx', 0o600);
    try {
      try {
        fs.writeSync(fd, JSON.stringify(doc, null, 2) + '\n');
        fs.fsyncSync(fd);
      } finally {
        fs.closeSync(fd);
      }
      try {
        const st = fs.lstatSync(file);
        if (st.isSymbolicLink()) {
          throw new CliError(EXIT.USAGE, `refused: ${tildify(file, home)} became a symlink during the write`);
        }
      } catch (err) {
        if (err instanceof CliError) throw err;
        /* ENOENT: first write */
      }
      fs.renameSync(tmp, file);
    } catch (err) {
      // Never leave a 0600 copy of every product's secrets lying beside the real file.
      fs.rmSync(tmp, { force: true });
      throw err;
    }
  });
}

function setMachineBlock(doc: CredentialsDoc, key: string, label: string): void {
  const product = productBlock(doc) ?? {};
  product.machine = {
    api_key: key,
    created: new Date().toISOString(),
    created_by: 'ffx',
    label,
  };
  doc[APP_KEY] = product;
}

export interface MintResult {
  created: boolean;
  fingerprint: string;
}

/**
 * `ffx key init` — mint only if absent. Compare-and-set: if a well-formed key
 * appears (the web app minted one) while we hold the lock, keep that one.
 */
export function initMachineKey(cfg: Config): MintResult {
  let result: MintResult | undefined;
  writeMerged(cfg.credentialsFile, cfg.home, (doc) => {
    const existing = machineBlock(doc)?.api_key;
    if (isWellFormedKey(existing)) {
      result = { created: false, fingerprint: fingerprint(existing) };
      return;
    }
    const key = mintKey();
    setMachineBlock(doc, key, os.hostname());
    result = { created: true, fingerprint: fingerprint(key) };
  });
  // A read-back: trust the file, not our own mint. If another writer replaced our fresh key
  // between our rename and this read, the key on disk is not the one we created.
  const back = loadMachineKey({ ...cfg, keyFromEnv: undefined, keyFile: undefined });
  const fp = fingerprint(back.key);
  return { created: (result?.created ?? false) && result?.fingerprint === fp, fingerprint: fp };
}

/** `ffx key rotate --yes` — mint unconditionally. */
export function rotateMachineKey(cfg: Config): { previous: string | undefined; fingerprint: string } {
  let previous: string | undefined;
  writeMerged(cfg.credentialsFile, cfg.home, (doc) => {
    const existing = machineBlock(doc)?.api_key;
    previous = isWellFormedKey(existing) ? fingerprint(existing) : undefined;
    setMachineBlock(doc, mintKey(), os.hostname());
  });
  const back = loadMachineKey({ ...cfg, keyFromEnv: undefined, keyFile: undefined });
  return { previous, fingerprint: fingerprint(back.key) };
}

export interface KeyDescription {
  file: string;
  exists: boolean;
  mode?: string;
  owner?: number;
  fingerprint?: string;
  created?: string;
  created_by?: string;
  label?: string;
  statements_root?: string;
  problem?: string;
}

/** `ffx key show` — everything about the key except the key. */
export function describeKey(cfg: Config): KeyDescription {
  const out: KeyDescription = { file: tildify(cfg.credentialsFile, cfg.home), exists: false };
  try {
    const st = checkCredentialsFile(cfg.credentialsFile, cfg.home);
    if (!st) return out;
    out.exists = true;
    out.mode = (st.mode & 0o777).toString(8).padStart(4, '0');
    out.owner = st.uid;
    const doc = readCredentialsDoc(cfg.credentialsFile, cfg.home);
    const m = machineBlock(doc);
    if (isWellFormedKey(m?.api_key)) out.fingerprint = fingerprint(m.api_key);
    else if (m?.api_key !== undefined) out.problem = 'api_key is malformed';
    else out.problem = 'no firefly_iii.machine.api_key';
    if (typeof m?.created === 'string') out.created = m.created;
    if (typeof m?.created_by === 'string') out.created_by = m.created_by;
    if (typeof m?.label === 'string') out.label = m.label;
    const root = statementsRootFromDoc(doc);
    if (root) out.statements_root = root;
  } catch (err) {
    out.problem = (err as Error).message;
  }
  return out;
}

/** The statements root: --root → FFX_STATEMENTS_DIR → the credentials file → undefined. */
export function resolveStatementsRoot(cfg: Config, flag: string | undefined): string | undefined {
  if (flag) return flag;
  if (cfg.statementsDir) return cfg.statementsDir;
  try {
    return statementsRootFromDoc(readCredentialsDoc(cfg.credentialsFile, cfg.home));
  } catch {
    return undefined;
  }
}
