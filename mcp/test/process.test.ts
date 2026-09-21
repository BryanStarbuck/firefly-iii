/**
 * The BUILT server as Claude Code runs it: dist/index.js serve over stdio.
 * The hand-piped probe (§17), -32601 for prompts/resources (§8), stdout
 * purity at runtime (§8.1), and every refuse-to-start path (§6.3, §7.3, §13).
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';

import { INITIALIZE, REPO_ROOT, ENTRY, runToExit, sandbox, spawnServer, startFakePlane } from './helpers.js';
import type { FakePlane } from './helpers.js';

const MCP_MDX = fs.readFileSync(path.join(REPO_ROOT, 'pm', 'mcp.mdx'), 'utf8');

/** §3.4 layer 5, verbatim from the spec: the first sentence of the instructions. */
function specFirstSentence(): string {
  const at = MCP_MDX.indexOf('**Layer 5, verbatim.**');
  const quote = MCP_MDX.slice(at, MCP_MDX.indexOf('**Layer 6', at));
  const text = quote
    .split('\n')
    .filter((l) => l.startsWith('>'))
    .map((l) => l.replace(/^>\s?/, ''))
    .join(' ')
    .replace(/^_|_$/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  return text.slice(0, text.indexOf('?') + 1);
}

describe('the built server over stdio', () => {
  let plane: FakePlane;
  before(async () => {
    plane = await startFakePlane();
  });
  after(async () => plane.close());

  it('dist/index.js has the shebang and the execute bit', () => {
    assert.ok(fs.readFileSync(ENTRY, 'utf8').startsWith('#!/usr/bin/env node\n'));
    assert.ok((fs.statSync(ENTRY).mode & 0o111) !== 0);
  });

  it('answers initialize with tools-only capabilities, serverInfo.name firefly_iii, and the routing sentence first', async () => {
    const sb = sandbox({ apiUrl: plane.url });
    const s = spawnServer(sb.env);
    try {
      const init = await s.request('initialize', INITIALIZE);
      const result = init.result as Record<string, unknown>;
      assert.deepEqual(result.capabilities, { tools: {} });
      assert.equal((result.serverInfo as Record<string, unknown>).name, 'firefly_iii');
      const first = specFirstSentence();
      assert.ok(first.length > 100, 'parsed the spec sentence');
      assert.ok(String(result.instructions).startsWith(first), 'instructions start with §3.4 layer 5 verbatim');
      s.notify('notifications/initialized');

      const list = await s.request('tools/list');
      const tools = (list.result as { tools: Array<{ name: string }> }).tools;
      assert.equal(tools.length, 77);

      for (const method of ['prompts/list', 'resources/list', 'resources/templates/list', 'prompts/get']) {
        const r = await s.request(method, method === 'prompts/get' ? { name: 'x' } : {});
        assert.equal((r.error as { code: number } | undefined)?.code, -32601, method);
      }

      const call = await s.request('tools/call', { name: 'ff_whoami', arguments: {} });
      const res = call.result as { content: Array<{ type: string; text: string }>; isError?: boolean };
      assert.equal(res.content.length, 1);
      assert.equal(res.content[0]?.type, 'text');
      const env = JSON.parse(res.content[0]?.text ?? '{}') as Record<string, unknown>;
      assert.equal(env.ok, true);
      assert.equal(env.tool, 'ff_whoami');
      assert.equal(plane.calls.at(-1)?.headers['x-firefly-client'], 'mcp');
      assert.equal(plane.calls.at(-1)?.headers.origin, undefined);

      const bad = await s.request('tools/call', { name: 'ff_get_account', arguments: { id: '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f' } });
      assert.equal(bad.error, undefined, 'a tool failure is never a JSON-RPC error');
      assert.equal((bad.result as { isError?: boolean }).isError, true);

      // stdout carried nothing but JSON-RPC.
      for (const line of s.stdoutLines) {
        const msg = JSON.parse(line) as Record<string, unknown>;
        assert.equal(msg.jsonrpc, '2.0');
      }
      assert.match(s.stderr(), /firefly_iii: v0\.1\.0 -> http:\/\/127\.0\.0\.1:\d+ \(local\) key 0123…\/sha256:[0-9a-f]{4} writes off/);
      assert.ok(!s.stderr().includes('0123456789abcdef0123456789abcdef'), 'the key never reaches stderr');
    } finally {
      await s.close();
    }
  });

  it('FFMCP_PROMPT_FILE overrides the instructions with the same substitution; an unreadable one falls back with a warning', async () => {
    const sb = sandbox({ apiUrl: plane.url });
    const prompt = path.join(sb.dir, 'p.md');
    fs.writeFileSync(prompt, 'Dev prompt for {SERVER_KEY} with {TOTAL_TOOLS} tools.\n');
    const s = spawnServer({ ...sb.env, FFMCP_PROMPT_FILE: prompt });
    try {
      const init = await s.request('initialize', INITIALIZE);
      assert.equal((init.result as Record<string, unknown>).instructions, 'Dev prompt for firefly_iii with 77 tools.\n');
    } finally {
      await s.close();
    }
    const s2 = spawnServer({ ...sb.env, FFMCP_PROMPT_FILE: path.join(sb.dir, 'missing.md') });
    try {
      const init = await s2.request('initialize', INITIALIZE);
      assert.match(String((init.result as Record<string, unknown>).instructions), /^Is this about the operator's own money/);
      assert.match(s2.stderr(), /FFMCP_PROMPT_FILE unreadable/);
    } finally {
      await s2.close();
    }
  });
});

describe('refusing to start (fail closed)', () => {
  const oneLine = (stderr: string): void => {
    assert.equal(stderr.trim().split('\n').length, 1, `one stderr line, got: ${stderr}`);
  };

  it('no key → exit 2, one stderr line, nothing on stdout', async () => {
    const sb = sandbox({ key: null });
    const r = await runToExit(sb.env);
    assert.equal(r.code, 2);
    assert.equal(r.stdout, '');
    oneLine(r.stderr);
    assert.match(r.stderr, /no machine key/);
    assert.match(r.stderr, /ffx (up|key init)/);
  });

  it('a 0644 credentials file → exit 2 with the chmod', async () => {
    const sb = sandbox({ mode: 0o644 });
    const r = await runToExit(sb.env);
    assert.equal(r.code, 2);
    oneLine(r.stderr);
    assert.match(r.stderr, /readable by others/);
    assert.match(r.stderr, /chmod 600/);
  });

  it('a symlinked credentials file → exit 2', async () => {
    const sb = sandbox();
    const link = path.join(sb.dir, 'link.json');
    fs.symlinkSync(sb.credentialsFile, link);
    const r = await runToExit({ ...sb.env, FFMCP_CREDENTIALS_FILE: link });
    assert.equal(r.code, 2);
    assert.match(r.stderr, /symlink/);
  });

  it('a malformed FFMCP_MACHINE_KEY → exit 2 naming FFMCP_MACHINE_KEY (not the CLI\'s variable)', async () => {
    const sb = sandbox();
    const r = await runToExit({ ...sb.env, FFMCP_MACHINE_KEY: 'not-a-key' });
    assert.equal(r.code, 2);
    assert.match(r.stderr, /FFMCP_MACHINE_KEY is set but/);
    assert.ok(!r.stderr.includes('FFX_'));
  });

  it('a non-loopback target without FFMCP_ALLOW_REMOTE → exit 2', async () => {
    const sb = sandbox({ apiUrl: 'https://books.example.test' });
    const r = await runToExit(sb.env);
    assert.equal(r.code, 2);
    oneLine(r.stderr);
    assert.match(r.stderr, /not loopback/);
  });

  it('a remote plain-http target → exit 2 even with FFMCP_ALLOW_REMOTE=1', async () => {
    const sb = sandbox({ apiUrl: 'http://books.example.test', env: { FFMCP_ALLOW_REMOTE: '1' } });
    const r = await runToExit(sb.env);
    assert.equal(r.code, 2);
    assert.match(r.stderr, /must be https/);
  });

  it('a missing subcommand → usage on stderr, exit 2', async () => {
    const sb = sandbox();
    const r = await runToExit(sb.env, []);
    assert.equal(r.code, 2);
    assert.equal(r.stdout, '');
    assert.match(r.stderr, /claude mcp add --scope user firefly_iii -- /);
  });

  it('never writes the credentials file (it only reads the key)', async () => {
    const sb = sandbox();
    const before = fs.readFileSync(sb.credentialsFile, 'utf8');
    const s = spawnServer(sb.env);
    await s.request('initialize', INITIALIZE);
    await s.close();
    assert.equal(fs.readFileSync(sb.credentialsFile, 'utf8'), before);
  });
});
