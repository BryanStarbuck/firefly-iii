/**
 * T6 — filesystem escape. This process writes only its log directory
 * (logger.js) and, in the CLI's shared module, the one-time credentials mint
 * (credentials.js — which the MCP never calls). Every other module is
 * read-only on disk; statements staging is the PLANE's, never ours.
 */
import type { Canary } from './canary.js';
import { builtFiles } from './canary.js';

const WRITE_API =
  /\b(writeFile|writeFileSync|appendFile|appendFileSync|writeSync|createWriteStream|rename|renameSync|unlink|unlinkSync|rm|rmSync|rmdir|rmdirSync|mkdir|mkdirSync|chmod|chmodSync|chown|chownSync|copyFile|copyFileSync|symlink|symlinkSync|truncate|truncateSync|utimes|utimesSync|cp|cpSync)\s*\(/;
const ALLOWED = new Set(['logger.js', 'credentials.js']);

export const canary: Canary = {
  name: 'no-fs-write',
  threats: ['T6'],
  summary: 'fs write APIs appear only in logger.js and credentials.js',
  check(ctx) {
    const problems: string[] = [];
    for (const f of builtFiles(ctx.distDir)) {
      if (ALLOWED.has(f.name)) continue;
      const m = f.code.match(WRITE_API);
      if (m) problems.push(`${f.name}: ${m[1]}()`);
      if (/openSync\([^)]*['"][wax]\+?['"]/.test(f.code)) problems.push(`${f.name}: opens a file for writing`);
    }
    return problems;
  },
};
