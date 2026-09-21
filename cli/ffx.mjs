#!/usr/bin/env node
/**
 * The self-building entry — pm/cli.mdx §2.3.
 *
 * `just build` builds the CLI, so this is a backstop: a freshly cloned repo
 * works with zero setup steps even if nobody ran `just`. Compare source mtimes
 * against dist/, compile when stale, then run. Exit 69 (EX_UNAVAILABLE) when
 * the build itself fails, so a script can tell "not built" from "ran and failed".
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const srcDir = path.join(here, 'code', 'src');
const distDir = path.join(here, 'code', 'dist');
const entry = path.join(distDir, 'src', 'index.js');
const tsc = path.join(here, 'node_modules', 'typescript', 'bin', 'tsc');

function newest(dir) {
  let n = 0;
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, e.name);
    const m = e.isDirectory() ? newest(full) : fs.statSync(full).mtimeMs;
    if (m > n) n = m;
  }
  return n;
}

function needsBuild() {
  if (!fs.existsSync(entry)) return true;
  try {
    return newest(srcDir) > newest(path.join(distDir, 'src'));
  } catch {
    return true;
  }
}

if (needsBuild()) {
  if (!fs.existsSync(tsc)) {
    process.stderr.write('ffx: the CLI is not built and TypeScript is not installed.\n  fix: cd cli && npm install && just build\n');
    process.exit(69);
  }
  // A build log must never land on stdout, which belongs to the answer (§13.1).
  const r = spawnSync(process.execPath, [tsc, '-p', path.join(here, 'code', 'tsconfig.json')], { stdio: ['ignore', 2, 2], cwd: here });
  if (r.status !== 0) {
    process.stderr.write('ffx: could not build the CLI (see errors above)\n');
    process.exit(69);
  }
}

await import(entry);
