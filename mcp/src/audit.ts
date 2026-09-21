/**
 * The audit line — pm/mcp.mdx §16.2.
 *
 *   2026-09-21T17:41:02.118Z CALL ff_get_budget_period tier=read target=local
 *     args=sha256:4f2a… month=2026-09 rows=22 ok=true tookMs=38 key=a3f1…/sha256:9c2b
 *
 * - Arguments are HASHED. Only a non-PII scalar allowlist is logged verbatim,
 *   and only when the value has a shape that cannot be money, a payee, an
 *   account id or a path (a date, a small integer, a boolean, a short enum word).
 * - No amount, ever. Counts yes, money no. The key only as a fingerprint.
 * - A denial logs the GATE and the code, never the value that failed.
 */
import crypto from 'node:crypto';

import { errorFileFor } from './vendor/error-file/index.js';

const errors = errorFileFor('mcp/src/audit.ts');

/** The only argument names whose values may appear in the log. */
export const LOGGABLE_SCALARS = ['month', 'start', 'end', 'interval', 'limit', 'offset', 'type', 'dry_run', 'format', 'top_n'] as const;

const SAFE_VALUE: Record<(typeof LOGGABLE_SCALARS)[number], RegExp> = {
  month: /^\d{4}-\d{2}$/,
  start: /^\d{4}-\d{2}-\d{2}$/,
  end: /^\d{4}-\d{2}-\d{2}$/,
  interval: /^(month|quarter|year|none|week|day)$/,
  limit: /^\d{1,6}$/,
  offset: /^\d{1,7}$/,
  type: /^[a-z][a-z _-]{0,23}$/,
  dry_run: /^(true|false)$/,
  format: /^[a-z]{2,8}$/,
  top_n: /^\d{1,4}$/,
};

/** A stable hash of the arguments: sorted keys, so the same call hashes the same. */
export function hashArgs(args: unknown): string {
  const canonical = (v: unknown): unknown => {
    if (Array.isArray(v)) return v.map(canonical);
    if (v && typeof v === 'object') {
      return Object.fromEntries(Object.keys(v as object).sort().map((k) => [k, canonical((v as Record<string, unknown>)[k])]));
    }
    return v;
  };
  let text: string;
  try {
    text = JSON.stringify(canonical(args ?? {})) ?? 'null';
  } catch (err) {
    // The designed fallback: the audit line says `unserialisable` and the call goes on.
    errors.expected('hashing the tool arguments', err);
    text = 'unserialisable';
  }
  return `sha256:${crypto.createHash('sha256').update(text).digest('hex').slice(0, 12)}`;
}

/** The allowlisted scalars with a safe shape, as `name=value` pairs. */
export function loggableScalars(args: unknown): string[] {
  if (!args || typeof args !== 'object' || Array.isArray(args)) return [];
  const out: string[] = [];
  for (const name of LOGGABLE_SCALARS) {
    const v = (args as Record<string, unknown>)[name];
    if (typeof v !== 'string' && typeof v !== 'number' && typeof v !== 'boolean') continue;
    const text = String(v);
    if (SAFE_VALUE[name].test(text)) out.push(`${name}=${text.replace(/ /g, '_')}`);
  }
  return out;
}

export interface AuditInput {
  tool: string;
  tier: string;
  target: string;
  args: unknown;
  ok: boolean;
  tookMs: number;
  keyFingerprint: string;
  rows?: number | undefined;
  gate?: string | undefined;
  code?: string | undefined;
  dryRun?: boolean | undefined;
}

export function auditLine(a: AuditInput): string {
  const parts = [
    new Date().toISOString(),
    'CALL',
    /^[a-z_]{1,64}$/.test(a.tool) ? a.tool : 'unknown_tool',
    `tier=${a.tier}`,
    `target=${a.target}`,
    `args=${hashArgs(a.args)}`,
    ...loggableScalars(a.args),
  ];
  if (a.rows !== undefined) parts.push(`rows=${a.rows}`);
  parts.push(`ok=${a.ok}`);
  if (a.gate !== undefined) parts.push(`gate=${a.gate}`);
  if (a.code !== undefined) parts.push(`code=${a.code}`);
  parts.push(`tookMs=${a.tookMs}`, `key=${a.keyFingerprint}`);
  return parts.join(' ');
}
