/**
 * mcp.info / mcp.err — pm/mcp.mdx §16.1.
 *
 * - There is deliberately NO stdout path in this module (or anywhere): stdout
 *   is the JSON-RPC wire (§8.1). Operational lines go to stderr and files.
 * - mcp.info: INFO/DEBUG and one CALL line per tool call — file only.
 * - mcp.err: WARN, ERROR and every denial — file AND stderr.
 * - 0600, append-only, rotated at 8 MB (five generations).
 * - Anything shaped like a 64-hex key is masked before it can reach a file.
 * - Logging never crashes the server: every filesystem fault is swallowed.
 */
import fs from 'node:fs';
import path from 'node:path';

import type { LogLevel } from './config.js';

const MAX_BYTES = 8 * 1024 * 1024;
const GENERATIONS = 5;
const RANK: Record<LogLevel, number> = { error: 0, warn: 1, info: 2, debug: 3 };

/** Mask anything that looks like a machine key. */
export function scrub(text: string): string {
  return text.replace(/\b[0-9a-f]{64}\b/gi, '[REDACTED-KEY]');
}

export interface LoggerOptions {
  dir: string;
  level: LogLevel;
  /** Where stderr lines go; injectable so tests can capture them. */
  stderr?: (line: string) => void;
}

export class Logger {
  readonly dir: string;
  readonly level: LogLevel;
  readonly #stderr: (line: string) => void;

  constructor(opts: LoggerOptions) {
    this.dir = opts.dir;
    this.level = opts.level;
    this.#stderr = opts.stderr ?? ((line: string) => void process.stderr.write(line));
  }

  get infoFile(): string {
    return path.join(this.dir, 'mcp.info');
  }

  get errFile(): string {
    return path.join(this.dir, 'mcp.err');
  }

  #rotate(file: string): void {
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

  #append(file: string, line: string): void {
    try {
      fs.mkdirSync(this.dir, { recursive: true, mode: 0o700 });
      this.#rotate(file);
      fs.appendFileSync(file, line, { mode: 0o600 });
    } catch {
      /* logging must never crash the server */
    }
  }

  #line(tag: string, message: string): string {
    return `${new Date().toISOString()} [${tag}] ${scrub(message).replace(/\r?\n/g, ' | ')}\n`;
  }

  #toStderr(message: string): void {
    try {
      this.#stderr(`${SERVER_TAG}: ${scrub(message).replace(/\r?\n/g, ' | ')}\n`);
    } catch {
      /* a closed stderr must not take the server down */
    }
  }

  debug(message: string): void {
    if (RANK[this.level] >= RANK.debug) this.#append(this.infoFile, this.#line('DEBUG', message));
  }

  info(message: string): void {
    if (RANK[this.level] >= RANK.info) this.#append(this.infoFile, this.#line('INFO', message));
  }

  warn(message: string): void {
    if (RANK[this.level] >= RANK.warn) {
      this.#append(this.errFile, this.#line('WARN', message));
      this.#toStderr(message);
    }
  }

  error(message: string): void {
    this.#append(this.errFile, this.#line('ERROR', message));
    this.#toStderr(message);
  }

  /** The startup banner: stderr and mcp.info. */
  banner(message: string): void {
    this.#toStderr(message);
    this.#append(this.infoFile, this.#line('INFO', message));
  }

  /**
   * The audit line (§16.2): always to mcp.info (whatever the level — the
   * audit is not optional); a denial also to mcp.err and stderr.
   */
  audit(line: string, denied: boolean): void {
    this.#append(this.infoFile, `${scrub(line)}\n`);
    if (denied) {
      this.#append(this.errFile, `${scrub(line)}\n`);
      this.#toStderr(line);
    }
  }
}

const SERVER_TAG = 'firefly_iii';
