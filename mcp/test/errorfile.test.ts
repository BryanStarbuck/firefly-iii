/**
 * The MCP server and the error file — pm/error_err.mdx §5.6, §5.7, §15 ("mcp node --test"), R7,
 * R13, R17. In-process hosts write to an explicit sandbox `file:`; spawned servers get
 * FIREFLY_ERROR_FILE from the sandbox env. Nothing here reaches ~/T/firefly/.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { after, describe, it } from 'node:test';

import { PlaneClient } from '../src/client.js';
import { loadConfig } from '../src/config.js';
import { Logger } from '../src/logger.js';
import { McpServerHost } from '../src/server.js';
import { resetNodeErrorFileForTests } from '../src/vendor/error-file/node.js';
import { call, errorOf, errorRecords, INITIALIZE, installSandboxErrorFile, runToExit, sandbox, spawnServer, TEST_KEY } from './helpers.js';

const H = 'The detail is in ~/T/firefly/error.err — ffx logs --errors';

after(() => {
  resetNodeErrorFileForTests();
});

describe('a plane-call timeout (§5.7, §18.1 H13)', () => {
  it('is an upstream_error whose hint ends with H, one [WARN] [mcp], and a second within 10 minutes writes no second record', async () => {
    const server = http.createServer(() => {
      /* never answers */
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const sb = sandbox({ apiUrl: `http://127.0.0.1:${(server.address() as AddressInfo).port}`, env: { FFMCP_TIMEOUT_MS: '100' } });
    installSandboxErrorFile(sb);
    const config = loadConfig(sb.env);
    const h = new McpServerHost({
      config,
      transport: new PlaneClient(config, TEST_KEY, 'fp'),
      logger: new Logger({ dir: sb.logDir, level: 'error', stderr: () => undefined }),
      keyFingerprint: 'fp',
      instructions: 'x',
    });
    try {
      for (let i = 0; i < 2; i++) {
        const r = await call(h, 'ff_list_accounts', {});
        assert.equal(errorOf(r).code, 'upstream_error');
        assert.ok(errorOf(r).hint?.endsWith(H), String(errorOf(r).hint));
        assert.equal(errorOf(r).hint, `narrow the request (a shorter date range, a lower limit). ${H}`);
      }
      // One WARN; the second timeout is only counted, and a flush writes the count as the window's
      // summary line — never a second record (§5.2, R10).
      const lines = errorRecords(sb.errorFile);
      assert.equal(lines.length, 2, lines.join('\n'));
      assert.match(lines[1] as string, /\[WARN\] \[mcp\] \[mcp\/src\/server\.ts\] running an MCP tool — ×1 more in the 10m window from /);
      assert.match(
        lines[0] as string,
        /\[WARN\] \[mcp\] \[mcp\/src\/server\.ts\] running an MCP tool — ToolError: Firefly III did not answer within 100 ms\. \(code=upstream_error\) \{tool=ff_list_accounts gate=plane code=upstream_error rid=[0-9a-f]{8} net=top pid=\d+\} \| cause: Error: timeout \(code=ETIMEDOUT\)$/,
      );
    } finally {
      server.closeAllConnections();
      await new Promise<void>((resolve) => server.close(() => resolve()));
    }
  });
});

describe('the spawned server (N15, N16)', () => {
  it('refuse-to-start is still exactly one stderr line, empty stdout, exit 2 — and no record (a refusal is an answer)', async () => {
    const sb = sandbox({ key: null });
    const r = await runToExit(sb.env);
    assert.equal(r.code, 2);
    assert.equal(r.stdout, '');
    assert.equal(r.stderr.trim().split('\n').length, 1, r.stderr);
    assert.equal(fs.existsSync(sb.errorFile), false);
  });

  it('a clean session writes nothing to the error file and nothing but JSON-RPC to stdout', async () => {
    const sb = sandbox();
    const s = spawnServer(sb.env);
    const init = await s.request('initialize', INITIALIZE);
    assert.ok(init.result);
    await s.close();
    for (const line of s.stdoutLines.filter((l) => l !== '')) assert.doesNotThrow(() => JSON.parse(line), line);
    assert.equal(fs.existsSync(sb.errorFile), false);
  });
});
