/**
 * Re-copies the CLI's credentials module body into src/credentials.ts, keeping
 * this file's own header and import block (pm/mcp.mdx §4: duplicated, not
 * imported; test/static.test.ts asserts byte-identity below the imports).
 *
 *   node scripts/sync-credentials.ts          rewrite src/credentials.ts
 *   node scripts/sync-credentials.ts --check  exit 1 when they differ
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const mcpRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cliFile = path.resolve(mcpRoot, '..', 'cli', 'code', 'src', 'credentials.ts');
const mcpFile = path.join(mcpRoot, 'src', 'credentials.ts');

function split(text: string): { head: string; body: string } {
  const lines = text.split('\n');
  let last = -1;
  lines.forEach((l, i) => {
    if (/^import\b.*;\s*$/.test(l)) last = i;
  });
  if (last < 0) throw new Error('no import block');
  return { head: lines.slice(0, last + 1).join('\n'), body: lines.slice(last + 1).join('\n') };
}

const cli = split(fs.readFileSync(cliFile, 'utf8'));
const mine = split(fs.readFileSync(mcpFile, 'utf8'));
if (process.argv.includes('--check')) {
  if (cli.body !== mine.body) {
    process.stderr.write('sync-credentials: src/credentials.ts has drifted from cli/code/src/credentials.ts — run node scripts/sync-credentials.ts\n');
    process.exit(1);
  }
  process.stderr.write('sync-credentials: identical\n');
  process.exit(0);
}
fs.writeFileSync(mcpFile, `${mine.head}\n${cli.body}`, 'utf8');
process.stderr.write('sync-credentials: src/credentials.ts now matches the CLI below its imports\n');
