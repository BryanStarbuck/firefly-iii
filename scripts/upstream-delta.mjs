#!/usr/bin/env node
// The upstream-delta guard behind `just check-delta` (pm/error_err.mdx §17.2).
//
// This fork tracks firefly-iii/firefly-iii on `develop`. Every upstream file we modify is a place a
// merge can conflict, so the set is pinned: scripts/upstream-delta.allow lists the class-A files
// (§17.1). This script diffs the working tree against `git merge-base HEAD upstream/develop` and:
//   * fails (exit 1) when a MODIFIED or DELETED upstream path is not on the allowlist;
//   * prints ADDED paths outside the fork-owned directories as class B (a name collision only);
//   * ignores the fork-owned class-C paths entirely.
// With no `upstream` remote it prints a note and exits 0.
//
//   node scripts/upstream-delta.mjs                 check against upstream/develop
//   node scripts/upstream-delta.mjs --base <commit> check against an explicit base (no remote needed)
//
// Read-only: it runs `git` and reads the allowlist. It never fetches, never writes.

import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const allowFile = join(root, 'scripts/upstream-delta.allow');

/** Class C — fork-owned paths (§17.1). A path equal to, or under, one of these is never upstream's. */
export const FORK_OWNED = [
  'app/Machine/',
  'tests/Machine/',
  'errorfile/',
  'scripts/',
  'cli/',
  'mcp/',
  'pm/',
  'ai/',
  'justfile',
  'CLAUDE.md',
  'phpunit.machine.xml',
];

export function isForkOwned(path) {
  return FORK_OWNED.some(p => (p.endsWith('/') ? path.startsWith(p) : path === p));
}

export function parseAllow(text) {
  return new Set(
    text
      .split('\n')
      .map(l => l.trim())
      .filter(l => l !== '' && !l.startsWith('#')),
  );
}

/** Parse `git diff --name-status -z --no-renames` output into [{status, path}]. */
export function parseNameStatus(z) {
  const parts = z.split('\0');
  const out = [];
  for (let i = 0; i + 1 < parts.length; i += 2) {
    if (parts[i] === '') break;
    out.push({ status: parts[i][0], path: parts[i + 1] });
  }
  return out;
}

/** Split changes into class A (modified upstream), class B (added) and violations. */
export function classify(changes, allow) {
  const classA = [];
  const classB = [];
  const violations = [];
  for (const { status, path } of changes) {
    if (isForkOwned(path)) continue;
    if (status === 'A' || status === '?') {
      classB.push(path);
    } else {
      classA.push(`${status} ${path}`);
      if (!allow.has(path)) violations.push(`${status} ${path}`);
    }
  }
  const touched = new Set(classA.map(l => l.slice(2)));
  const stale = [...allow].filter(p => !touched.has(p));
  return { classA, classB, violations, stale };
}

function git(args) {
  return execFileSync('git', args, { cwd: root, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
}

function main(argv) {
  let base = null;
  const at = argv.indexOf('--base');
  if (at !== -1) {
    base = argv[at + 1];
    if (!base) {
      process.stderr.write('check-delta: --base needs a commit\n');
      return 2;
    }
    base = git(['rev-parse', '--verify', `${base}^{commit}`]).trim();
  } else {
    const remotes = git(['remote']).split('\n').map(s => s.trim());
    if (!remotes.includes('upstream')) {
      process.stdout.write(
        'check-delta: no `upstream` remote — nothing to compare against; skipped.\n' +
          '  add it with: git remote add upstream https://github.com/firefly-iii/firefly-iii.git && git fetch upstream develop\n',
      );
      return 0;
    }
    try {
      git(['rev-parse', '--verify', 'upstream/develop^{commit}']);
    } catch {
      process.stderr.write('check-delta: upstream/develop is not fetched — run: git fetch upstream develop\n');
      return 1;
    }
    base = git(['merge-base', 'HEAD', 'upstream/develop']).trim();
  }

  const tracked = parseNameStatus(git(['diff', '--no-ext-diff', '--name-status', '-z', '--no-renames', base]));
  const untracked = git(['ls-files', '--others', '--exclude-standard', '-z'])
    .split('\0')
    .filter(Boolean)
    .map(path => ({ status: '?', path }));
  const allow = parseAllow(readFileSync(allowFile, 'utf8'));
  const { classA, classB, violations, stale } = classify([...tracked, ...untracked], allow);

  const out = [];
  out.push(`check-delta: base ${base.slice(0, 10)} (pm/error_err.mdx §17)`);
  out.push(`  class A — modified upstream files: ${classA.length}`);
  for (const l of classA) out.push(`    ${l}${violations.includes(l) ? '   <- NOT ON scripts/upstream-delta.allow' : ''}`);
  out.push(`  class B — new files in upstream directories: ${classB.length}`);
  for (const p of classB) out.push(`    A ${p}`);
  for (const p of stale) out.push(`  note: ${p} is on the allowlist but unmodified`);
  if (violations.length > 0) {
    out.push(
      `check-delta: FAIL — ${violations.length} upstream path(s) modified outside the allowlist. ` +
        'Move the change into a fork-owned file, or (only if pm/error_err.mdx §17 allows it) add the path to scripts/upstream-delta.allow.',
    );
  } else {
    out.push('check-delta: OK');
  }
  process.stdout.write(out.join('\n') + '\n');
  return violations.length > 0 ? 1 : 0;
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  process.exitCode = main(process.argv.slice(2));
}
