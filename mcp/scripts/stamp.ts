/**
 * dist/index.js gets a `#!/usr/bin/env node` banner and the execute bit — pm/mcp.mdx §5.1.
 * Claude Code spawns the path directly; without both the server SILENTLY never
 * starts (the tools are simply absent from the catalogue).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const entry = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', 'dist', 'index.js');
const source = fs.readFileSync(entry, 'utf8');
if (!source.startsWith('#!')) fs.writeFileSync(entry, `#!/usr/bin/env node\n${source}`, 'utf8');
fs.chmodSync(entry, 0o755);
process.stderr.write('stamp: dist/index.js is executable\n');
