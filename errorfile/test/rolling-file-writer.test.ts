// The writer and the node sink — pm/error_err.mdx §3.1, §5.5, §10 ("Node error path"), R13.
// Every file lives in a mkdtemp sandbox; nothing here resolves the environment's path.
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, it, mock } from 'node:test';

import { errorFileFor } from '../src/core.js';
import { installNodeErrorFile, resetNodeErrorFileForTests, resolveErrorFilePath } from '../src/node.js';
import { RollingFileWriter } from '../src/rolling-file-writer.js';

let dir = '';
const SYNC_APIS = ['appendFileSync', 'mkdirSync', 'statSync', 'existsSync', 'renameSync', 'rmSync', 'writeFileSync', 'openSync'] as const;

function spySync(): Map<string, { mock: { callCount(): number; restore(): void } }> {
  const spies = new Map<string, { mock: { callCount(): number; restore(): void } }>();
  for (const name of SYNC_APIS) spies.set(name, mock.method(fs, name));
  return spies;
}

function syncCalls(spies: Map<string, { mock: { callCount(): number } }>): string[] {
  return [...spies].filter(([, s]) => s.mock.callCount() > 0).map(([n]) => n);
}

/** Wait for the async drain (mkdir + stat + appendFile on the threadpool) to land. */
async function waitForDrain(file?: string): Promise<void> {
  const deadline = Date.now() + 3000;
  do {
    await new Promise((resolve) => setTimeout(resolve, 20));
  } while (file && !fs.existsSync(file) && Date.now() < deadline);
  await new Promise((resolve) => setTimeout(resolve, 40));
}

beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'firefly-errorfile-writer-'));
});
afterEach(() => {
  mock.restoreAll();
  resetNodeErrorFileForTests();
  fs.rmSync(dir, { recursive: true, force: true });
});

describe('RollingFileWriter', () => {
  it('does no synchronous fs work on the happy path, then drains async', async () => {
    const file = path.join(dir, 'nested', 'error.err');
    const spies = spySync();
    const writer = new RollingFileWriter({ filePath: file });
    for (let i = 0; i < 100; i++) writer.write(`line ${i}`);
    assert.deepEqual(syncCalls(spies), []);
    for (const s of spies.values()) s.mock.restore();
    await waitForDrain(file);
    assert.equal(fs.readFileSync(file, 'utf8').split('\n').length, 101);
  });

  it('creates the file 0600 inside a 0700 directory', async () => {
    const file = path.join(dir, 'd', 'error.err');
    const writer = new RollingFileWriter({ filePath: file });
    writer.write('x');
    await waitForDrain(file);
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
    assert.equal(fs.statSync(path.join(dir, 'd')).mode & 0o777, 0o700);
  });

  it('a burst over 256 KiB drains synchronously', () => {
    const file = path.join(dir, 'error.err');
    const writer = new RollingFileWriter({ filePath: file, maxBytes: 10 * 1024 * 1024 });
    const line = 'y'.repeat(1023);
    for (let i = 0; i < 300; i++) writer.write(line);
    assert.ok(fs.statSync(file).size >= 256 * 1024);
  });

  it('rotates at the cap and keeps at most N backups (5 by default; here 2, never a .3)', () => {
    const file = path.join(dir, 'error.err');
    const writer = new RollingFileWriter({ filePath: file, maxBytes: 2048, maxBackups: 2 });
    for (let round = 0; round < 6; round++) {
      for (let i = 0; i < 20; i++) writer.write('z'.repeat(99));
      writer.flush();
    }
    assert.ok(fs.statSync(file).size <= 2048);
    assert.ok(fs.existsSync(`${file}.1`));
    assert.ok(fs.existsSync(`${file}.2`));
    assert.ok(!fs.existsSync(`${file}.3`));
  });

  it('the default keeps .1 … .5 and never a .6', () => {
    const file = path.join(dir, 'error.err');
    const writer = new RollingFileWriter({ filePath: file, maxBytes: 1024 });
    for (let round = 0; round < 12; round++) {
      writer.write('q'.repeat(1000));
      writer.flush();
    }
    for (let i = 1; i <= 5; i++) assert.ok(fs.existsSync(`${file}.${i}`), `.${i}`);
    assert.ok(!fs.existsSync(`${file}.6`));
  });

  it('does not roll a file another process already rolled', () => {
    const file = path.join(dir, 'error.err');
    const writer = new RollingFileWriter({ filePath: file, maxBytes: 2048 });
    writer.write('a'.repeat(1500));
    writer.flush();
    // Another process rolled it: the file is now small.
    fs.writeFileSync(file, 'other\n');
    writer.write('b'.repeat(1000));
    writer.flush();
    assert.ok(!fs.existsSync(`${file}.1`));
    assert.ok(fs.readFileSync(file, 'utf8').startsWith('other\n'));
  });

  it('a roll step that throws never deletes the active file (a concurrent roller, §4.4 / §5.5)', () => {
    const file = path.join(dir, 'error.err');
    const history = Array.from({ length: 40 }, (_, i) => `[2026-01-01T00:00:00.000Z] [ERROR] [php-web] [app/x.php:1] synthetic history ${i}\n`).join('');
    fs.writeFileSync(file, history, { mode: 0o600 });
    // A non-empty directory at .5 makes both rmSync(.5) and renameSync(.4 → .5) throw.
    fs.mkdirSync(`${file}.5`);
    fs.writeFileSync(path.join(`${file}.5`, 'occupied'), 'x');
    fs.writeFileSync(`${file}.4`, 'gen4\n');
    const writer = new RollingFileWriter({ filePath: file, maxBytes: 1024, maxBackups: 5 });
    assert.doesNotThrow(() => {
      writer.write('[2026-01-01T00:00:01.000Z] [ERROR] [ffx] [cli/x.ts] synthetic node line');
      writer.flush();
    });
    const survived = [file, `${file}.1`, `${file}.2`, `${file}.3`, `${file}.4`]
      .filter((f) => fs.existsSync(f) && fs.statSync(f).isFile())
      .map((f) => fs.readFileSync(f, 'utf8'))
      .join('');
    for (let i = 0; i < 40; i++) assert.ok(survived.includes(`synthetic history ${i}\n`), `history line ${i} survived`);
    assert.ok(survived.includes('synthetic node line'));
    assert.ok(!fs.existsSync(`${file}.6`));
  });

  it('a generation that vanishes mid-chain (ENOENT) is skipped and the active file still rotates', () => {
    const file = path.join(dir, 'error.err');
    fs.writeFileSync(file, 'a'.repeat(1500) + '\n');
    fs.writeFileSync(`${file}.1`, 'gen1\n');
    const writer = new RollingFileWriter({ filePath: file, maxBytes: 2048, maxBackups: 5 });
    const realRename = fs.renameSync;
    let first = true;
    mock.method(fs, 'renameSync', (from: fs.PathLike, to: fs.PathLike) => {
      if (first && String(from).endsWith('.err.1')) {
        first = false;
        // Another roller moved .1 a moment before us.
        realRename(from, to);
        const err = Object.assign(new Error('ENOENT: synthetic race'), { code: 'ENOENT' });
        throw err;
      }
      realRename(from, to);
    });
    writer.write('b'.repeat(1000));
    writer.flush();
    mock.restoreAll();
    assert.ok(fs.readFileSync(`${file}.1`, 'utf8').startsWith('aaaa'), 'the active file rotated to .1');
    assert.equal(fs.readFileSync(`${file}.2`, 'utf8'), 'gen1\n');
    assert.ok(fs.readFileSync(file, 'utf8').startsWith('bbbb'));
  });

  it('a failing path falls back and does not throw; the next write retries', async () => {
    const blocker = path.join(dir, 'not-a-dir');
    fs.writeFileSync(blocker, 'file');
    const fallen: string[] = [];
    const writer = new RollingFileWriter({ filePath: path.join(blocker, 'error.err'), fallback: (data) => fallen.push(data) });
    assert.doesNotThrow(() => writer.write('lost?'));
    await waitForDrain();
    assert.doesNotThrow(() => writer.flush());
    assert.ok(fallen.join('').includes('lost?'));
  });
});

describe('the node sink (§5.5)', () => {
  it('1,000 caught() calls make no synchronous fs call, land on disk at 0600, and carry pid', async () => {
    const file = path.join(dir, 'firefly', 'error.err');
    installNodeErrorFile({ app: 'ffx', where: 'errorfile/test/rolling-file-writer.test.ts', file, handleProcessErrors: false, echo: false });
    const errors = errorFileFor('errorfile/test/rolling-file-writer.test.ts');
    const spies = spySync();
    for (let i = 0; i < 1000; i++) errors.caught(`doing thing ${i % 50}`, new Error('boom'));
    assert.deepEqual(syncCalls(spies), []);
    for (const s of spies.values()) s.mock.restore();
    await waitForDrain(file);
    const text = fs.readFileSync(file, 'utf8');
    assert.ok(text.includes(`[ERROR] [ffx] [errorfile/test/rolling-file-writer.test.ts] doing thing 0 — Error: boom {pid=${process.pid}}`), text.slice(0, 300));
    assert.equal(text.split('\n').filter((l) => l.startsWith('[')).length, 50, 'identical faults fold');
    assert.equal(fs.statSync(file).mode & 0o777, 0o600);
    assert.equal(fs.statSync(path.dirname(file)).mode & 0o777, 0o700);
  });

  it('fatal() flushes synchronously and is on disk when it returns', () => {
    const file = path.join(dir, 'error.err');
    installNodeErrorFile({ app: 'mcp', where: 'mcp/src/index.ts', file, handleProcessErrors: false });
    errorFileFor('mcp/src/index.ts', { net: 'top' }).fatal('starting the MCP server', new Error('synthetic startup crash'));
    assert.match(fs.readFileSync(file, 'utf8'), /^\[[^\]]+\] \[FATAL\] \[mcp\] \[mcp\/src\/index\.ts\] starting the MCP server — Error: synthetic startup crash \{net=top pid=\d+\}\n/);
  });

  it('installing twice returns the first install; reset forgets it and removes its process listeners', () => {
    const before = process.listenerCount('unhandledRejection');
    const first = installNodeErrorFile({ app: 'ffx', where: 'cli/code/src/index.ts', file: path.join(dir, 'a.err') });
    const second = installNodeErrorFile({ app: 'ffx', where: 'cli/code/src/index.ts', file: path.join(dir, 'b.err') });
    assert.equal(second.file, first.file);
    assert.equal(process.listenerCount('unhandledRejection'), before + 1);
    resetNodeErrorFileForTests();
    assert.equal(process.listenerCount('unhandledRejection'), before);
  });

  it('echo writes the line to stderr only when asked', () => {
    const file = path.join(dir, 'error.err');
    const seen: string[] = [];
    const stderr = mock.method(process.stderr, 'write', (chunk: unknown) => {
      seen.push(String(chunk));
      return true;
    });
    installNodeErrorFile({ app: 'ffx', where: 'cli/code/src/index.ts', file, handleProcessErrors: false, echo: true });
    errorFileFor('cli/code/src/x.ts').caught('echoing', new Error('shown'));
    stderr.mock.restore();
    assert.ok(seen.some((s) => s.includes('echoing — Error: shown')));
  });
});

describe('resolveErrorFilePath (§3.1)', () => {
  it('FIREFLY_ERROR_FILE wins, with ~/ expanded from HOME; empty means unset', () => {
    assert.equal(resolveErrorFilePath({ FIREFLY_ERROR_FILE: '/tmp/x/error.err', HOME: '/h' }), '/tmp/x/error.err');
    assert.equal(resolveErrorFilePath({ FIREFLY_ERROR_FILE: '~/y/error.err', HOME: '/h' }), '/h/y/error.err');
    assert.equal(resolveErrorFilePath({ FIREFLY_ERROR_FILE: '', HOME: '/h' }), '/h/T/firefly/error.err');
  });

  it('defaults to ~/T/firefly/error.err, and has no NODE_ENV branch', () => {
    assert.equal(resolveErrorFilePath({ HOME: '/h', NODE_ENV: 'test' }), '/h/T/firefly/error.err');
  });
});
