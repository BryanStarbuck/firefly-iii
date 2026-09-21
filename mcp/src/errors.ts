/**
 * The closed error vocabulary — pm/mcp.mdx §12.3.
 *
 * Twelve codes: the plane's nine (apis.mdx §5.2), passed through unchanged,
 * plus three minted HERE and never seen on the wire. A thirteenth is a spec
 * change, not a commit.
 */

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

/** Minted by this server only: a write without an echo, our ceiling, a foreign-shaped id. */
export const MCP_ONLY_CODES = ['confirm_required', 'too_many_changes', 'wrong_server'] as const;

export const ERROR_CODES = [...PLANE_CODES, ...MCP_ONLY_CODES] as const;

export type ErrorCode = (typeof ERROR_CODES)[number];

export function isErrorCode(value: unknown): value is ErrorCode {
  return typeof value === 'string' && (ERROR_CODES as readonly string[]).includes(value);
}

export function isPlaneCode(value: unknown): value is (typeof PLANE_CODES)[number] {
  return typeof value === 'string' && (PLANE_CODES as readonly string[]).includes(value);
}

/** Every tool failure is one of these; the server turns it into an `ok: false` envelope. */
export class ToolError extends Error {
  readonly code: ErrorCode;
  readonly hint: string | undefined;
  readonly details: unknown;
  /**
   * The X-Firefly-Request-Id this server sent on the plane call that failed, so the [mcp] record in
   * ~/T/firefly/error.err joins the plane's own record (pm/error_err.mdx §4.8). Never serialised
   * into an envelope.
   */
  rid: string | undefined;

  constructor(code: ErrorCode, message: string, hint?: string, details?: unknown, cause?: unknown) {
    super(message, cause !== undefined ? { cause } : undefined);
    this.rid = undefined;
    this.name = 'ToolError';
    this.code = code;
    this.hint = hint;
    this.details = details;
  }
}

export function fail(code: ErrorCode, message: string, hint?: string, details?: unknown, cause?: unknown): ToolError {
  return new ToolError(code, message, hint, details, cause);
}

/** Both switches, named — the only honest answer to "why can't it write" (§9.7). */
export const WRITE_SWITCHES_HINT =
  'Set FIREFLY_MACHINE_ALLOW_WRITE=1 in the Firefly .env (then ffx stop && ffx up) and FFMCP_ALLOW_WRITE=1 ' +
  'in this MCP server\'s env block (then restart the MCP server), then retry. Both switches are the operator\'s decision.';
