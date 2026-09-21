/**
 * cli.info / cli.err — pm/cli.mdx §15.2.
 *
 * - INFO and API_CALL lines go to cli.info, file only, never stdout.
 * - WARN and ERROR go to cli.err (and the caller prints them on stderr).
 * - The machine key is never logged; only its fingerprint.
 * - No financial data: counts yes; payees, amounts, account numbers no.
 * - Logging can never crash the CLI: a filesystem fault is written to ~/T/firefly/error.err, never thrown.
 * - 0600, append-only, rotated at 8 MB, five generations.
 */
import fs from 'node:fs';
import path from 'node:path';

import { errorFileFor } from './vendor/error-file/index.js';

const errors = errorFileFor('cli/code/src/logger.ts');

const MAX_BYTES = 8 * 1024 * 1024;
const GENERATIONS = 5;

type Level = 'INFO' | 'API_CALL' | 'WARN' | 'ERROR';

let stateDir: string | undefined;

export function initLogger(dir: string): void {
  stateDir = dir;
}

function rotate(file: string): void {
  try {
    const st = fs.statSync(file);
    if (st.size < MAX_BYTES) return;
    for (let i = GENERATIONS - 1; i >= 1; i--) {
      const from = `${file}.${i}`;
      if (fs.existsSync(from)) fs.renameSync(from, `${file}.${i + 1}`);
    }
    fs.renameSync(file, `${file}.1`);
  } catch (err) {
    // A missing file or a race is nothing to rotate; any other fs fault is visible, never thrown.
    if ((err as NodeJS.ErrnoException)?.code === 'ENOENT') errors.expected('rotating the CLI log', err);
    else errors.caught('rotating the CLI log', err);
  }
}

/** Anything that looks like a 64-hex key is masked before it can reach a file. */
export function scrub(text: string): string {
  return text.replace(/\b[0-9a-f]{64}\b/gi, '[REDACTED-KEY]');
}

function write(level: Level, message: string): void {
  if (!stateDir) return;
  try {
    fs.mkdirSync(stateDir, { recursive: true, mode: 0o700 });
    const file = path.join(stateDir, level === 'WARN' || level === 'ERROR' ? 'cli.err' : 'cli.info');
    rotate(file);
    const line = `${new Date().toISOString()} [${level}] ${scrub(message).replace(/\n/g, ' | ')}\n`;
    fs.appendFileSync(file, line, { mode: 0o600 });
  } catch (err) {
    // Still never crashes the CLI; the fault is now visible (pm/error_err.mdx §8.7, N14).
    errors.caught('appending to the CLI log', err);
  }
}

export const log = {
  info: (message: string): void => write('INFO', message),
  apiCall: (message: string): void => write('API_CALL', message),
  warn: (message: string): void => write('WARN', message),
  error: (message: string): void => write('ERROR', message),
};
