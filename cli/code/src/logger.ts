/**
 * cli.info / cli.err — pm/cli.mdx §15.2.
 *
 * - INFO and API_CALL lines go to cli.info, file only, never stdout.
 * - WARN and ERROR go to cli.err (and the caller prints them on stderr).
 * - The machine key is never logged; only its fingerprint.
 * - No financial data: counts yes; payees, amounts, account numbers no.
 * - Logging can never crash the CLI: every filesystem fault is swallowed.
 * - 0600, append-only, rotated at 8 MB, five generations.
 */
import fs from 'node:fs';
import path from 'node:path';

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
  } catch {
    /* missing file or a race — nothing to rotate */
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
  } catch {
    /* logging must never crash the CLI */
  }
}

export const log = {
  info: (message: string): void => write('INFO', message),
  apiCall: (message: string): void => write('API_CALL', message),
  warn: (message: string): void => write('WARN', message),
  error: (message: string): void => write('ERROR', message),
};
