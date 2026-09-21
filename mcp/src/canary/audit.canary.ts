/**
 * T11 — PII or money in the log. The audit line hashes the arguments and
 * logs only allowlisted scalars with a safe shape: no amount, payee,
 * account id or path, ever — and the key only as a fingerprint.
 */
import { auditLine, LOGGABLE_SCALARS } from '../audit.js';
import type { Canary } from './canary.js';

export const canary: Canary = {
  name: 'audit',
  threats: ['T11'],
  summary: 'an audit line over money-, payee-, id- and path-bearing arguments contains none of them',
  check() {
    const args = {
      start: '2026-09-01',
      end: '2026-09-30',
      type: 'withdrawal',
      account_id: 4021,
      amount: '1234.56',
      min_amount: '987.65',
      limit: '4321.09',
      description: 'Blue Bottle Coffee',
      root: '/Users/someone/statements',
      transactions: [{ amount: '55.55', destination_name: 'Whole Foods' }],
    };
    const line = auditLine({ tool: 'ff_list_transactions', tier: 'read', target: 'local', args, ok: true, tookMs: 3, keyFingerprint: 'abcd…/sha256:1234' });
    const problems: string[] = [];
    for (const needle of ['1234.56', '987.65', '4321.09', '55.55', 'Blue Bottle', 'Whole Foods', '/Users/', '4021']) {
      if (line.includes(needle)) problems.push(`audit line contains "${needle}"`);
    }
    if (!line.includes('start=2026-09-01')) problems.push('allowlisted scalar start was not logged');
    if (!/args=sha256:[0-9a-f]{12}/.test(line)) problems.push('arguments are not hashed');
    if ((LOGGABLE_SCALARS as readonly string[]).some((s) => /amount|name|id$|path|root|description/.test(s))) problems.push('the allowlist names a money/PII field');
    return problems;
  },
};
