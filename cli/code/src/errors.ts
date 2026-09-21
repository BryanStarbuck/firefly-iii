/**
 * Exit codes and the one error type the CLI throws — pm/cli.mdx §14.
 *
 * Scripts branch on these codes, so they are part of the contract. Every
 * failure anywhere in the CLI becomes a CliError carrying one of them, a
 * one-sentence message, and (almost always) a hint naming the fix.
 */

export const EXIT = {
  OK: 0,
  /** It ran and failed: a doctor check, an empty extraction, a server-side internal error. */
  FAILED: 1,
  /** Usage, or a local refusal before any call was made. */
  USAGE: 2,
  /** Not found — a fact, not an error. */
  NOT_FOUND: 3,
  /** Conflict — a question for a human: a duplicate, a stale token, a statement conflict. */
  CONFLICT: 4,
  /** The app is not up, could not be brought up, or the plane is not mounted / not ready. */
  UNAVAILABLE: 5,
  /** A 401 from an app that IS reachable. Distinct from USAGE ("we never sent anything"). */
  AUTH: 6,
  /** The binary is not built (only the shim produces this). */
  NOT_BUILT: 69,
} as const;

export type ExitCode = (typeof EXIT)[keyof typeof EXIT];

/** The nine machine-plane error codes — apis.mdx §5.2. A tenth is a spec change. */
export const PLANE_CODES = [
  'unauthorized',
  'forbidden',
  'not_found',
  'invalid_input',
  'conflict',
  'write_disabled',
  'not_ready',
  'upstream_error',
  'internal',
] as const;

export type PlaneCode = (typeof PLANE_CODES)[number];

/** How each plane code becomes an exit code — cli.mdx §14. */
export const EXIT_FOR_CODE: Record<PlaneCode, ExitCode> = {
  unauthorized: EXIT.AUTH,
  forbidden: EXIT.USAGE,
  not_found: EXIT.NOT_FOUND,
  invalid_input: EXIT.USAGE,
  conflict: EXIT.CONFLICT,
  write_disabled: EXIT.USAGE,
  not_ready: EXIT.UNAVAILABLE,
  upstream_error: EXIT.FAILED,
  internal: EXIT.FAILED,
};

export function isPlaneCode(value: unknown): value is PlaneCode {
  return typeof value === 'string' && (PLANE_CODES as readonly string[]).includes(value);
}

export interface CliErrorOptions {
  hint?: string | undefined;
  /** Extra lines printed after the message and before the hint. */
  details?: string[] | undefined;
  /** The plane's error code, when the failure came from the server. */
  code?: string | undefined;
  /** Structured details from the server envelope, shown under --verbose and in --json-errors. */
  serverDetails?: unknown;
}

export class CliError extends Error {
  readonly exit: ExitCode;
  readonly hint: string | undefined;
  readonly details: string[];
  readonly code: string | undefined;
  readonly serverDetails: unknown;

  constructor(exit: ExitCode, message: string, options: CliErrorOptions = {}) {
    super(message);
    this.name = 'CliError';
    this.exit = exit;
    this.hint = options.hint;
    this.details = options.details ?? [];
    this.code = options.code;
    this.serverDetails = options.serverDetails;
  }
}

export function usage(message: string, hint?: string, details?: string[]): CliError {
  return new CliError(EXIT.USAGE, message, { hint, details });
}
