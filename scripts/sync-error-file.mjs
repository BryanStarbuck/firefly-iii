#!/usr/bin/env node
// Copy the vendored set of errorfile/src into cli/ and mcp/ — pm/error_err.mdx §5.4.
//
// cli/ and mcp/ are separate npm packages with their own tsc builds, so neither can import TypeScript
// from errorfile/. They get a generated copy instead, and "copy" must never mean "drift": the drift
// tests in cli/code/test/canaries.test.ts and mcp/test/static.test.ts fail if a copy differs from the
// source after the header, and `just test` runs `--check` before any build.
//
// A file is written ONLY when its content differs, so cli/ffx.mjs's mtime-based self-rebuild does not
// churn. The writing sync runs from `just build` and by hand; `just test` only ever checks.
//
//   node scripts/sync-error-file.mjs          write the copies that differ
//   node scripts/sync-error-file.mjs --check  exit 1 if any copy is missing or stale (writes nothing)

import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'errorfile', 'src');

// The vendored set (8). test-sink.ts stays in errorfile/src and is never vendored.
export const VENDORED_FILES = [
  'index.ts',
  'core.ts',
  'describe.ts',
  'redact.ts',
  'fold.ts',
  'format.ts',
  'node.ts',
  'rolling-file-writer.ts',
];

export const VENDOR_DIRS = ['cli/code/src/vendor/error-file', 'mcp/src/vendor/error-file'];

export const HEADER = '// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs\n';

const check = process.argv.includes('--check');
let stale = 0;

for (const dir of VENDOR_DIRS) {
  const target = join(root, dir);
  for (const file of VENDORED_FILES) {
    const wanted = HEADER + readFileSync(join(source, file), 'utf8');
    const dest = join(target, file);
    const current = existsSync(dest) ? readFileSync(dest, 'utf8') : null;
    if (current === wanted) continue;
    stale++;
    if (check) {
      process.stderr.write(`stale: ${relative(root, dest)}\n`);
    } else {
      mkdirSync(target, { recursive: true });
      writeFileSync(dest, wanted);
      process.stdout.write(`wrote ${relative(root, dest)}\n`);
    }
  }
}

if (check && stale > 0) {
  process.stderr.write(`${stale} vendored error-file copies differ from errorfile/src — run node scripts/sync-error-file.mjs\n`);
  process.exit(1);
}
if (!check) {
  process.stdout.write(stale ? `synced ${stale} error-file files\n` : 'vendored error-file copies are current\n');
}
