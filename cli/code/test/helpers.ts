/**
 * Run the built CLI as a subprocess, with a temp state dir and a temp
 * credentials file, so no test ever touches the real home directory.
 */
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
/** code/dist/test → code/dist/src/index.js */
export const ENTRY = path.resolve(here, '..', 'src', 'index.js');
export const DIST_SRC = path.resolve(here, '..', 'src');
/** code/dist/test → code/src (the TypeScript sources) */
export const SRC = path.resolve(here, '..', '..', 'src');
export const CLI_ROOT = path.resolve(here, '..', '..', '..');

export interface Sandbox {
  dir: string;
  /** The error file this sandbox's children write (FIREFLY_ERROR_FILE). */
  errorFile: string;
  credentialsFile: string;
  stateDir: string;
  env: NodeJS.ProcessEnv;
}

export function sandbox(opts: { key?: string | undefined; apiUrl?: string; mode?: number; statementsRoot?: string } = {}): Sandbox {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ffx-test-'));
  const credentialsFile = path.join(dir, 'firefly_iii.json');
  const stateDir = path.join(dir, 'state');
  if (opts.key !== undefined) {
    const doc = {
      other_product: { secret: 'must-survive' },
      firefly_iii: { machine: { api_key: opts.key, created: '2026-09-21T00:00:00Z', created_by: 'firefly-web', label: 'test' }, ...(opts.statementsRoot ? { statements: { root: opts.statementsRoot } } : {}) },
    };
    fs.writeFileSync(credentialsFile, JSON.stringify(doc, null, 2), { mode: opts.mode ?? 0o600 });
    fs.chmodSync(credentialsFile, opts.mode ?? 0o600);
  }
  const env: NodeJS.ProcessEnv = {
    PATH: process.env.PATH,
    HOME: dir,
    FFX_CREDENTIALS_FILE: credentialsFile,
    FFX_STATE_DIR: stateDir,
    FFX_NO_BRINGUP: '1',
    // Every child writes its faults to the sandbox, never to ~/T/firefly/ (pm/error_err.mdx R13, §15).
    FIREFLY_ERROR_FILE: path.join(dir, 'error.err'),
    ...(opts.apiUrl ? { FFX_API_URL: opts.apiUrl } : { FFX_API_URL: 'http://127.0.0.1:1' }),
  };
  return { dir, errorFile: path.join(dir, 'error.err'), credentialsFile, stateDir, env };
}

export interface RunResult {
  code: number;
  stdout: string;
  stderr: string;
}

export function runCli(args: string[], env: NodeJS.ProcessEnv, stdin?: string): Promise<RunResult> {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [ENTRY, ...args], { env, stdio: ['pipe', 'pipe', 'pipe'] });
    let stdout = '';
    let stderr = '';
    child.stdout.setEncoding('utf8').on('data', (c: string) => (stdout += c));
    child.stderr.setEncoding('utf8').on('data', (c: string) => (stderr += c));
    if (stdin !== undefined) child.stdin.write(stdin);
    child.stdin.end();
    child.on('close', (code) => resolve({ code: code ?? -1, stdout, stderr }));
  });
}

/** Every .js file under a directory, recursively. */
export function jsFiles(dir: string): string[] {
  const out: string[] = [];
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    if (e.isDirectory()) out.push(...jsFiles(full));
    else if (e.name.endsWith('.js')) out.push(full);
  }
  return out;
}
