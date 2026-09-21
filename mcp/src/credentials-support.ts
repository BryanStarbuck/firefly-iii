/**
 * The three names credentials.ts needs from its surroundings — `Config`,
 * `tildify`, `CliError`/`EXIT` — provided under the same names the CLI's
 * config.ts and errors.ts provide them, so that credentials.ts can be the
 * CLI's module byte for byte below its import block (pm/mcp.mdx §4: the one
 * module shared in substance, duplicated rather than imported, with a parity
 * test).
 *
 * Nothing here is MCP policy. The MCP's own configuration is config.ts; it
 * builds one of these from FFMCP_* variables in `credentialsConfig()`.
 */
import path from 'node:path';

/** The subset of the CLI's Config that the credentials module reads. */
export interface Config {
  apiUrl: string;
  credentialsFile: string;
  keyFromEnv: string | undefined;
  keyFile: string | undefined;
  statementsDir: string | undefined;
  home: string;
}

/** Shorten the home directory to ~ for display. Identical to the CLI's. */
export function tildify(p: string, home: string): string {
  return p === home ? '~' : p.startsWith(home + path.sep) ? '~' + p.slice(home.length) : p;
}

/** The CLI's exit codes, the two the credentials module uses plus the rest for shape. */
export const EXIT = {
  OK: 0,
  FAILED: 1,
  USAGE: 2,
  NOT_FOUND: 3,
  CONFLICT: 4,
  UNAVAILABLE: 5,
  AUTH: 6,
} as const;

export type ExitCode = (typeof EXIT)[keyof typeof EXIT];

export interface CliErrorOptions {
  hint?: string | undefined;
  details?: string[] | undefined;
}

/**
 * What credentials.ts throws. In the MCP every one of these ends the process
 * before the transport attaches: one stderr line, exit 2 (§6.3).
 */
export class CliError extends Error {
  readonly exit: ExitCode;
  readonly hint: string | undefined;
  readonly details: string[];

  constructor(exit: ExitCode, message: string, options: CliErrorOptions = {}) {
    super(message);
    this.name = 'CliError';
    this.exit = exit;
    this.hint = options.hint;
    this.details = options.details ?? [];
  }
}
