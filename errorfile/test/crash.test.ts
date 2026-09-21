// A fault survives the crash that caused it — pm/error_err.mdx R9, §5.5, §15. Each case spawns a
// child against the BUILT library (.build/src/node.js) with an explicit sandbox path (R13).
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, it } from 'node:test';

const SRC = new URL('../src/', import.meta.url).href;

function childScript(body: string): string {
  return `
const { installNodeErrorFile } = await import('${SRC}node.js');
const { errorFileFor } = await import('${SRC}core.js');
installNodeErrorFile({ app: 'ffx', where: 'cli/code/src/index.ts', file: process.env.FIREFLY_ERROR_FILE, echo: false });
const errors = errorFileFor('errorfile/test/crash.test.ts');
errors.caught('reporting the first fault', new Error('one'));
errors.caught('reporting the second fault', new Error('two'));
errors.warn('reporting the third fault', new Error('three'));
${body}
`;
}

let dir = '';
beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'firefly-errorfile-crash-'));
});
afterEach(() => fs.rmSync(dir, { recursive: true, force: true }));

function run(body: string): { status: number | null; signal: NodeJS.Signals | null; text: string; stdout: string } {
  const script = path.join(dir, 'child.mjs');
  const file = path.join(dir, 'error.err');
  fs.writeFileSync(script, childScript(body));
  const result = spawnSync(process.execPath, [script], {
    env: { PATH: process.env.PATH, HOME: dir, FIREFLY_ERROR_FILE: file },
    encoding: 'utf8',
    timeout: 20_000,
  });
  let text = '';
  try {
    text = fs.readFileSync(file, 'utf8');
  } catch {
    text = `(no file) stderr: ${result.stderr}`;
  }
  return { status: result.status, signal: result.signal, text, stdout: result.stdout };
}

describe('crash (R9)', () => {
  it('a synchronous throw: 3 records + FATAL on disk, and the process still dies', () => {
    const { status, text } = run(`throw new Error('synchronous crash');`);
    assert.notEqual(status, 0);
    assert.ok(text.includes('reporting the first fault — Error: one'), text);
    assert.ok(text.includes('reporting the second fault — Error: two'));
    assert.ok(text.includes('[WARN] [ffx] [errorfile/test/crash.test.ts] reporting the third fault'));
    assert.match(text, /\[FATAL\] \[ffx\] \[cli\/code\/src\/index\.ts\] an uncaught exception — Error: synchronous crash \{net=process pid=\d+\}/);
  });

  it('an unhandled rejection: 3 records + one FATAL on disk, and Node still crashes', () => {
    const { status, text } = run(`Promise.reject(new Error('rejected crash'));`);
    assert.notEqual(status, 0);
    assert.ok(text.includes('reporting the first fault — Error: one'), text);
    assert.match(text, /\[FATAL\] \[ffx\] .* an unhandled promise rejection — Error: rejected crash/);
    assert.equal(text.match(/rejected crash/g)?.length, 1);
  });

  it('a signal with no host listener: flushed, then re-raised, so the process dies of it', () => {
    const { signal, text } = run(`process.kill(process.pid, 'SIGTERM'); setTimeout(() => {}, 5000);`);
    assert.equal(signal, 'SIGTERM');
    assert.ok(text.includes('reporting the third fault'), text);
  });

  it('a signal with a host listener is NOT re-raised: the host keeps control', () => {
    const { status, signal, text, stdout } = run(`
process.on('SIGTERM', () => { process.stdout.write('host handled\\n'); });
process.kill(process.pid, 'SIGTERM');
setTimeout(() => { process.stdout.write('still alive\\n'); }, 300);`);
    assert.equal(signal, null);
    assert.equal(status, 0);
    assert.equal(stdout, 'host handled\nstill alive\n');
    assert.ok(text.includes('reporting the third fault'), text);
  });

  it('beforeExit flushes a buffered record at a normal exit', () => {
    const { status, text } = run(`errors.caught('reporting at the very end', new Error('last'));`);
    assert.equal(status, 0);
    assert.ok(text.includes('reporting at the very end — Error: last'), text);
  });
});
