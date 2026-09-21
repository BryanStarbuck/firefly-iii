/**
 * Static guarantees: the canaries against the BUILT dist/ and their parity
 * with the §7.0 threat table, the credentials module's byte-identity with the
 * CLI's, and the instructions pipeline (§3.4, §8.3, §17).
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, it } from 'node:test';

import { CANARIES } from '../src/canary/index.js';
import { INSTRUCTIONS } from '../src/instructions.js';
import { findTool, TOOLS } from '../src/tools/registry.js';
import { DIST, MCP_ROOT, REPO_ROOT } from './helpers.js';

const MCP_MDX = fs.readFileSync(path.join(REPO_ROOT, 'pm', 'mcp.mdx'), 'utf8');

describe('canaries (run against the built dist/)', () => {
  for (const c of CANARIES) {
    it(`${c.name} — ${c.summary}`, () => {
      assert.deepEqual(c.check({ distDir: DIST, tools: TOOLS }), []);
    });
  }

  it('the §7.0 Canary column and src/canary/ agree, both directions', () => {
    const table = MCP_MDX.slice(MCP_MDX.indexOf('### 7.0 Threat model'), MCP_MDX.indexOf('### 7.1'));
    const named = new Set<string>();
    for (const line of table.split('\n').filter((l) => /^\|\s*T\d+/.test(l))) {
      const lastCell = line.split('|').at(-2) ?? '';
      for (const m of lastCell.matchAll(/`([a-z-]+)`/g)) named.add(m[1] as string);
    }
    const files = fs
      .readdirSync(path.join(MCP_ROOT, 'src', 'canary'))
      .filter((f) => f.endsWith('.canary.ts'))
      .map((f) => f.replace(/\.canary\.ts$/, ''));
    assert.deepEqual([...named].sort(), files.sort());
    assert.deepEqual(CANARIES.map((c) => c.name).sort(), files.sort());
  });

  it('a canary catches a planted violation (they are not vacuous)', () => {
    const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ffmcp-canary-'));
    fs.cpSync(DIST, tmp, { recursive: true });
    fs.appendFileSync(path.join(tmp, 'server.js'), '\nconsole.log("oops"); fetch("https://evil.example.test/x"); require("child_process");\n');
    const by = (n: string) => CANARIES.find((c) => c.name === n)!;
    assert.ok(by('stdout-purity').check({ distDir: tmp, tools: TOOLS }).length > 0);
    assert.ok(by('no-network').check({ distDir: tmp, tools: TOOLS }).length > 0);
    assert.ok(by('no-shell').check({ distDir: tmp, tools: TOOLS }).length > 0);
    fs.rmSync(tmp, { recursive: true, force: true });
  });
});

describe('credentials.ts is the CLI\'s module, byte for byte below the import block', () => {
  const body = (file: string): string => {
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    let last = -1;
    lines.forEach((l, i) => {
      if (/^import\b.*;\s*$/.test(l)) last = i;
    });
    assert.ok(last > 0, `${file}: no import block`);
    // The error-file handle names the file's OWN repo-relative path (pm/error_err.mdx R14), so that
    // one literal is the only permitted difference; everything else stays byte for byte.
    return lines
      .slice(last + 1)
      .join('\n')
      .replace(/errorFileFor\('(?:cli\/code\/src|mcp\/src)\/credentials\.ts'\)/, "errorFileFor('<this file>')");
  };

  it('is identical', () => {
    const cli = path.join(REPO_ROOT, 'cli', 'code', 'src', 'credentials.ts');
    const mcp = path.join(MCP_ROOT, 'src', 'credentials.ts');
    assert.equal(body(mcp), body(cli));
  });
});

describe('the instructions pipeline', () => {
  const gen = path.join(MCP_ROOT, 'scripts', 'build-instructions.ts');
  const run = (env: NodeJS.ProcessEnv, args: string[] = []) =>
    spawnSync(process.execPath, [gen, ...args], { env: { PATH: process.env.PATH, ...env }, encoding: 'utf8' });

  it('the checked-in src/instructions.ts matches ai/mcp_prompt_firefly.md', () => {
    const r = run({}, ['--check']);
    assert.equal(r.status, 0, r.stderr);
  });

  it('a missing prompt FAILS the build', () => {
    const out = path.join(os.tmpdir(), `ffmcp-instr-${process.pid}.ts`);
    const r = run({ FFMCP_BUILD_PROMPT: '/nonexistent/mcp_prompt_firefly.md', FFMCP_BUILD_OUT: out });
    assert.equal(r.status, 1);
    assert.match(r.stderr, /cannot read the prompt/);
    assert.ok(!fs.existsSync(out));
  });

  it('an unknown {TOKEN} FAILS the build', () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ffmcp-instr-'));
    const prompt = path.join(dir, 'p.md');
    fs.writeFileSync(prompt, 'Hello {TOOL_PERFIX}whoami\n');
    const r = run({ FFMCP_BUILD_PROMPT: prompt, FFMCP_BUILD_OUT: path.join(dir, 'out.ts') });
    assert.equal(r.status, 1);
    assert.match(r.stderr, /unknown token\(s\).*\{TOOL_PERFIX\}/);
  });

  it('every ff_ name the prompt mentions exists', () => {
    // `ff_something` is the prompt's own placeholder for "a tool name", not a tool.
    const names = new Set((INSTRUCTIONS.match(/\bff_[a-z_]+/g) ?? []).filter((n) => n !== 'ff_something'));
    assert.ok(names.size >= 20);
    for (const n of names) assert.ok(findTool(n), `the prompt names ${n}, which is not a tool`);
  });

  it('no token is left unsubstituted, and the payload holds no private facts', () => {
    assert.doesNotMatch(INSTRUCTIONS, /\{[A-Z_]+\}/);
    assert.doesNotMatch(INSTRUCTIONS, /\/Users\//);
    assert.doesNotMatch(INSTRUCTIONS, /\b[0-9a-f]{32,}\b/i);
    assert.match(INSTRUCTIONS, /There are 79 of them: 60 read and 19 write\./);
    assert.match(INSTRUCTIONS, /~\/\.credentials\/firefly_iii\.json/);
  });
});

describe('the vendored error file has not drifted (pm/error_err.mdx §5.4)', () => {
  const HEADER = '// GENERATED from errorfile/src — run node scripts/sync-error-file.mjs\n';
  const FILES = ['index.ts', 'core.ts', 'describe.ts', 'redact.ts', 'fold.ts', 'format.ts', 'node.ts', 'rolling-file-writer.ts'];
  const VENDOR = path.join(MCP_ROOT, 'src', 'vendor', 'error-file');

  it('the sync script vendors exactly these files into this directory under this header', () => {
    const script = fs.readFileSync(path.join(REPO_ROOT, 'scripts', 'sync-error-file.mjs'), 'utf8');
    for (const file of FILES) assert.ok(script.includes(`'${file}'`), `sync-error-file.mjs does not vendor ${file}`);
    assert.ok(script.includes("'mcp/src/vendor/error-file'"), 'sync-error-file.mjs does not target mcp/src/vendor/error-file');
    assert.ok(script.includes(HEADER.trimEnd()), 'sync-error-file.mjs writes a different header');
    assert.deepEqual(fs.readdirSync(VENDOR).sort(), [...FILES].sort(), 'mcp/src/vendor/error-file holds a file the sync does not own');
  });

  for (const file of FILES) {
    it(`vendor/error-file/${file} is errorfile/src/${file}, byte for byte after the header`, () => {
      const copy = path.join(VENDOR, file);
      assert.ok(fs.existsSync(copy), `${copy} is missing — run node scripts/sync-error-file.mjs`);
      const text = fs.readFileSync(copy, 'utf8');
      assert.ok(text.startsWith(HEADER), `${file} lacks the GENERATED header`);
      const source = fs.readFileSync(path.join(REPO_ROOT, 'errorfile', 'src', file), 'utf8');
      assert.ok(text.slice(HEADER.length) === source, `mcp/src/vendor/error-file/${file} has drifted — run node scripts/sync-error-file.mjs`);
    });
  }
});

describe('tests never install the error file without an explicit file (pm/error_err.mdx §5.5, R13)', () => {
  it('every installNodeErrorFile call under mcp/test/ passes file:', () => {
    const TEST_DIR = path.join(MCP_ROOT, 'test');
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
