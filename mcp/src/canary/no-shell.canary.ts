/**
 * T9 — RCE through the process. No child_process, no worker spawning, no
 * eval — and the server's import graph never reaches the test-only canaries.
 */
import type { Canary } from './canary.js';
import { builtFiles } from './canary.js';

export const canary: Canary = {
  name: 'no-shell',
  threats: ['T9'],
  summary: 'no child_process, worker_threads, cluster, vm, eval or new Function anywhere in dist/',
  check(ctx) {
    const problems: string[] = [];
    for (const f of builtFiles(ctx.distDir)) {
      if (/child_process|worker_threads|node:cluster|node:vm|['"]vm['"]/.test(f.code)) problems.push(`${f.name}: imports a process/VM module`);
      if (/\beval\s*\(|new\s+Function\s*\(/.test(f.code)) problems.push(`${f.name}: evaluates code`);
      if (/from\s+['"][./]*canary\//.test(f.code)) problems.push(`${f.name}: imports a test-only canary`);
    }
    return problems;
  },
};
