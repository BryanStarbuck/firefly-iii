/**
 * Canaries — they grep the BUILT output, because a violation bundled in from
 * anywhere shows up there and not in a source grep. pm/cli.mdx §17, §18.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it } from 'node:test';

import { CLI_ROOT, DIST_SRC, jsFiles, SRC } from './helpers.js';

function built(): Array<{ file: string; name: string; text: string }> {
  return jsFiles(DIST_SRC).map((file) => ({ file, name: path.relative(DIST_SRC, file), text: fs.readFileSync(file, 'utf8') }));
}

function sources(): Array<{ name: string; text: string }> {
  const out: Array<{ name: string; text: string }> = [];
  const walk = (dir: string): void => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) walk(full);
      else if (e.name.endsWith('.ts')) out.push({ name: path.relative(SRC, full), text: fs.readFileSync(full, 'utf8') });
    }
  };
  walk(SRC);
  return out;
}

/** Strip comments so documentation that NAMES a banned call does not trip the canary. */
function code(text: string): string {
  return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:"'`])\/\/.*$/gm, '$1');
}

describe('stdout purity', () => {
  it('only render.js writes to stdout, and nothing uses console.log', () => {
    for (const f of built()) {
      const c = code(f.text);
      assert.ok(!/console\.(log|info)\(/.test(c), `${f.name} uses console.log/info`);
      if (f.name !== 'render.js') assert.ok(!/process\.stdout\.write\(/.test(c), `${f.name} writes to stdout`);
    }
  });
});

describe('money never becomes a number', () => {
  it('no parseFloat, Number(), toFixed, Intl.NumberFormat or unary-plus-on-amount anywhere', () => {
    for (const f of built()) {
      const c = code(f.text);
      assert.ok(!/parseFloat\(/.test(c), `${f.name}: parseFloat`);
      assert.ok(!/(^|[^.\w])Number\(/.test(c), `${f.name}: Number()`);
      assert.ok(!/\.toFixed\(/.test(c), `${f.name}: toFixed`);
      assert.ok(!/Intl\.NumberFormat/.test(c), `${f.name}: Intl.NumberFormat`);
      assert.ok(!/\+\s*(amount|balance|limit|spent)\b/.test(c), `${f.name}: unary plus on an amount`);
    }
  });
});

describe('one socket, one process-spawner', () => {
  it('only client.js opens HTTP connections', () => {
    for (const f of built()) {
      const c = code(f.text);
      if (f.name === 'client.js') continue;
      assert.ok(!/from ['"]node:https?['"]/.test(c), `${f.name} imports node:http(s)`);
      assert.ok(!/\bfetch\(/.test(c), `${f.name} calls fetch`);
    }
  });

  it('child_process appears only in bringup.js (the doctor asks bringup for php info)', () => {
    for (const f of built()) {
      if (f.name === 'bringup.js') continue;
      assert.ok(!/child_process/.test(code(f.text)), `${f.name} imports child_process`);
    }
  });

  it('no network host literal other than loopback', () => {
    for (const f of built()) {
      const hosts = code(f.text).match(/https?:\/\/[a-z0-9.-]+/gi) ?? [];
      for (const h of hosts) assert.match(h, /^https?:\/\/(127\.0\.0\.1|localhost)$/i, `${f.name}: ${h}`);
    }
  });
});

describe('open-source safety (§17)', () => {
  it('no 64-hex secret literal in the built output', () => {
    for (const f of built()) assert.ok(!/\b[0-9a-f]{64}\b/.test(f.text), `${f.name} holds a key-shaped literal`);
  });

  it('no private path or private archive name in the source', () => {
    for (const s of sources()) {
      assert.ok(!s.text.includes('/Users/'), `${s.name} contains /Users/`);
      assert.ok(!/Bryan_Arindom|bank_statements\//.test(s.text), `${s.name} names the private archive`);
    }
  });

  it('the package is private and has no runtime dependencies', () => {
    const pkg = JSON.parse(fs.readFileSync(path.join(CLI_ROOT, 'package.json'), 'utf8'));
    assert.equal(pkg.private, true);
    assert.deepEqual(pkg.dependencies ?? {}, {});
  });

  it('the build is not stale', () => {
    const newest = (dir: string, ext: string): number => {
      let n = 0;
      for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
        const full = path.join(dir, e.name);
        const m = e.isDirectory() ? newest(full, ext) : e.name.endsWith(ext) ? fs.statSync(full).mtimeMs : 0;
        if (m > n) n = m;
      }
      return n;
    };
    assert.ok(newest(DIST_SRC, '.js') >= newest(SRC, '.ts'), 'code/dist is older than code/src — run just build');
  });
});
