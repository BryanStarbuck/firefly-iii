/**
 * T5 — PII and statement exfiltration. The PLANE redacts (apis.mdx §16.2);
 * this detector exists to fail a test the day it regresses: a full IBAN or
 * account number, a machine key, an absolute home path, or raw statement text
 * anywhere in a response.
 */
import type { Canary } from './canary.js';

const PATTERNS: Array<[string, RegExp]> = [
  ['a full IBAN', /\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){3,7}(?:\s?[A-Z0-9]{1,4})?\b/],
  ['a full account number', /\b\d{9,17}\b/],
  ['a machine key', /\b[0-9a-f]{64}\b/i],
  ['an absolute home path', /\/(Users|home)\/[^/\s"]+/],
  ['raw statement text', /(%PDF-|_claude\.txt|_ocr\.txt)/],
];

/** Where in a response something that must never be exposed appears. */
export function findUnredacted(value: unknown, where = '$'): string[] {
  if (typeof value === 'string') {
    return PATTERNS.filter(([, re]) => re.test(value)).map(([what]) => `${where}: ${what}`);
  }
  if (Array.isArray(value)) return value.flatMap((v, i) => findUnredacted(v, `${where}[${i}]`));
  if (value && typeof value === 'object') {
    return Object.entries(value as Record<string, unknown>).flatMap(([k, v]) => findUnredacted(v, `${where}.${k}`));
  }
  return [];
}

export const canary: Canary = {
  name: 'redaction',
  threats: ['T5'],
  summary: 'a response carrying a full IBAN, account number, key, home path or raw statement text is flagged',
  check() {
    const problems: string[] = [];
    const clean = { accounts: [{ name: 'Household · Northbank Checking ••4021', iban_last4: '4021', current_balance: '4211.08', currency_code: 'USD' }] };
    const dirty = { account: { iban: 'GB33BUKB20201555555555', number: '123456789012', path: '/Users/someone/statements/x.pdf' } };
    if (findUnredacted(clean).length) problems.push(`false positive: ${findUnredacted(clean).join('; ')}`);
    if (findUnredacted(dirty).length < 3) problems.push('the detector missed a full IBAN, number or path');
    return problems;
  },
};
