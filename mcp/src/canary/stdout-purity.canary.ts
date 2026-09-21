/**
 * T13 — stdout corruption. One console.log anywhere corrupts the JSON-RPC
 * stream and the client simply goes quiet (§8.1). Enforced on the BUILT bundle.
 */
import type { Canary } from './canary.js';
import { builtFiles } from './canary.js';

export const canary: Canary = {
  name: 'stdout-purity',
  threats: ['T13'],
  summary: 'dist/ has no console.* call and no process.stdout.write — stdout carries only JSON-RPC',
  check(ctx) {
    const problems: string[] = [];
    for (const f of builtFiles(ctx.distDir)) {
      if (/\bconsole\.[a-z]+\s*\(/.test(f.code)) problems.push(`${f.name}: calls console.*`);
      if (/process\.stdout\.write\s*\(/.test(f.code)) problems.push(`${f.name}: writes to process.stdout`);
      if (/process\.stdout\b/.test(f.code)) problems.push(`${f.name}: touches process.stdout`);
    }
    return problems;
  },
};
