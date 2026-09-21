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

describe('the vendored error file has not drifted (pm/error_err.mdx §5.4)', () => {
  const REPO = path.resolve(CLI_ROOT, '..');
  const HEADER = '// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs\n';
  const FILES = ['index.ts', 'core.ts', 'describe.ts', 'redact.ts', 'fold.ts', 'format.ts', 'node.ts', 'rolling-file-writer.ts'];

  it('the sync script vendors exactly these files into this directory under this header', () => {
    const script = fs.readFileSync(path.join(REPO, 'scripts', 'sync-error-file.mjs'), 'utf8');
    for (const file of FILES) assert.ok(script.includes(`'${file}'`), `sync-error-file.mjs does not vendor ${file}`);
    assert.ok(script.includes("'cli/code/src/vendor/error-file'"), 'sync-error-file.mjs does not target cli/code/src/vendor/error-file');
    assert.ok(script.includes(HEADER.trimEnd()), 'sync-error-file.mjs writes a different header');
    const vendored = fs.readdirSync(path.join(SRC, 'vendor', 'error-file')).sort();
    assert.deepEqual(vendored, [...FILES].sort(), 'cli/code/src/vendor/error-file holds a file the sync does not own');
  });

  for (const file of FILES) {
    it(`vendor/error-file/${file} is errorfile/src/${file}, byte for byte after the header`, () => {
      const copy = path.join(SRC, 'vendor', 'error-file', file);
      assert.ok(fs.existsSync(copy), `${copy} is missing — run node scripts/sync-error-file.mjs`);
      const text = fs.readFileSync(copy, 'utf8');
      assert.ok(text.startsWith(HEADER), `${file} lacks the GENERATED header`);
      const source = fs.readFileSync(path.join(REPO, 'errorfile', 'src', file), 'utf8');
      assert.ok(text.slice(HEADER.length) === source, `cli/code/src/vendor/error-file/${file} has drifted — run node scripts/sync-error-file.mjs`);
    });
  }
});

describe('tests never install the error file without an explicit file (pm/error_err.mdx §5.5, R13)', () => {
  it('every installNodeErrorFile call under cli/code/test/ passes file:', () => {
    const TEST_DIR = path.join(SRC, '..', 'test');
    const needle = 'installNodeError' + 'File(';
    for (const name of fs.readdirSync(TEST_DIR).filter((f) => f.endsWith('.ts'))) {
      const text = fs.readFileSync(path.join(TEST_DIR, name), 'utf8');
      let at = text.indexOf(needle);
      while (at !== -1) {
        const close = text.indexOf('})', at);
        const args = text.slice(at + needle.length, close === -1 ? undefined : close);
        assert.ok(/\bfile\s*[:,]/.test(args), `${name}: installNodeErrorFile without file: — it would resolve process.env and could reach the real file`);
        at = text.indexOf(needle, at + needle.length);
      }
    }
  });
});
