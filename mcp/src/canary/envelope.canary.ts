/**
 * T8 — prompt injection from ledger data — and the §12 response contract:
 * one envelope shape, the administration named on every success, row text
 * marked in meta.untrusted, every failure a code from the closed twelve.
 */
import { isErrorCode } from '../errors.js';
import type { Canary } from './canary.js';

export function envelopeProblems(text: string): string[] {
  let e: Record<string, unknown>;
  try {
    e = JSON.parse(text) as Record<string, unknown>;
  } catch {
    return ['not JSON'];
  }
  const problems: string[] = [];
  if (typeof e.tool !== 'string' || !e.tool.startsWith('ff_')) problems.push('tool missing');
  if (e.ok === true) {
    const meta = (e.meta ?? {}) as Record<string, unknown>;
    for (const k of ['administrationId', 'administrationName', 'target', 'asOf', 'tookMs', 'truncated', 'untrusted']) {
      if (!(k in meta)) problems.push(`meta.${k} missing`);
    }
    if (!Array.isArray(meta.untrusted) || !(meta.untrusted as unknown[]).includes('description')) problems.push('meta.untrusted must name description');
    if ('error' in e) problems.push('success carries error');
  } else if (e.ok === false) {
    const err = (e.error ?? {}) as Record<string, unknown>;
    if (!isErrorCode(err.code)) problems.push(`error.code ${String(err.code)} is outside the closed vocabulary`);
    if (typeof err.message !== 'string') problems.push('error.message missing');
    if (/\bat .+\(.+:\d+:\d+\)/.test(String(err.message)) || /Illuminate\\|Exception:/.test(String(err.message))) problems.push('error leaks a stack');
  } else {
    problems.push('ok is not a boolean');
  }
  return problems;
}

export const canary: Canary = {
  name: 'envelope',
  threats: ['T8'],
  summary: 'every response is the §12 envelope; meta names the administration and the untrusted fields',
  check() {
    const good = JSON.stringify({ ok: true, tool: 'ff_whoami', data: {}, meta: { administrationId: 1, administrationName: 'Household', target: 'local', asOf: 'x', tookMs: 1, truncated: false, untrusted: ['description', 'notes'] } });
    const bad = JSON.stringify({ ok: false, tool: 'ff_whoami', error: { code: 'boom', message: 'x' } });
    const problems: string[] = [];
    if (envelopeProblems(good).length) problems.push(`false positive: ${envelopeProblems(good).join('; ')}`);
    if (!envelopeProblems(bad).length) problems.push('an unknown error code passed');
    return problems;
  },
};
