// core — pm/error_err.mdx §5.3, §7.2, R4, R5, R6, R7, R11, §15 "errorfile node --test".
import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it, mock } from 'node:test';

import {
  errorFileFor,
  flushErrorFile,
  guard,
  isReported,
  PRE_INSTALL_CAP,
  reportRejection,
  resetErrorFileForTests,
  setErrorSink,
  tryOr,
  tryOrAsync,
} from '../src/core.js';
import { memorySink } from '../src/test-sink.js';

const errors = errorFileFor('errorfile/test/core.test.ts');

/** The shapes of the two runtimes' own error classes, synthetic (cli/code/src/errors.ts, mcp/src/errors.ts). */
class CliError extends Error {
  override name = 'CliError';
  constructor(
    message: string,
    readonly exit: number,
    readonly code?: string,
    options?: { cause?: unknown },
  ) {
    super(message, options);
  }
}
class ToolError extends Error {
  override name = 'ToolError';
  constructor(
    readonly code: string,
    message: string,
  ) {
    super(message);
  }
}

let savedVerbose: string | undefined;
beforeEach(() => {
  resetErrorFileForTests();
  savedVerbose = process.env.FIREFLY_ERROR_FILE_VERBOSE;
  delete process.env.FIREFLY_ERROR_FILE_VERBOSE;
});
afterEach(() => {
  if (savedVerbose === undefined) delete process.env.FIREFLY_ERROR_FILE_VERBOSE;
  else process.env.FIREFLY_ERROR_FILE_VERBOSE = savedVerbose;
  mock.timers.reset();
  resetErrorFileForTests();
});

describe('dedupe (R4)', () => {
  it('rethrow ×3, then caught, then the process net → 1 record', () => {
    const sink = memorySink();
    setErrorSink(sink);
    const err = new Error('deep failure');
    const layer = (n: number): void => {
      try {
        if (n === 0) throw err;
        layer(n - 1);
      } catch (e) {
        errors.rethrow(`running layer ${n}`, e);
      }
    };
    assert.throws(() => layer(2), (e) => e === err);
    errors.caught('capturing an exception', err);
    errorFileFor('cli/code/src/index.ts', { net: 'process' }).fatal('an uncaught exception', err);
    assert.equal(sink.records.length, 1);
    assert.equal(sink.records[0]?.doing, 'running layer 0');
    assert.ok(isReported(err));
  });

  it('a thrown primitive cannot be marked, so the fold handles it', () => {
    const sink = memorySink();
    setErrorSink(sink);
    errors.caught('reading a primitive', 'boom');
    errors.caught('reading a primitive', 'boom');
    assert.equal(sink.records.length, 1);
    assert.equal(sink.records[0]?.error, 'Thrown string: boom');
  });
});

describe('rethrow keeps the object (R5)', () => {
  it('a CliError keeps .exit and a ToolError keeps .code, and each writes exactly one record', () => {
    const sink = memorySink();
    setErrorSink(sink);
    const cli = new CliError('Firefly III failed on the server.', 1, 'internal');
    let caught: unknown;
    try {
      errors.rethrow('running an ffx verb', cli, { exit: 1 });
    } catch (e) {
      caught = e;
    }
    assert.equal(caught, cli);
    assert.ok(caught instanceof CliError);
    assert.equal((caught as CliError).exit, 1);
    const tool = new ToolError('internal', 'Firefly III failed on the server.');
    try {
      errors.rethrow('running an MCP tool', tool);
    } catch (e) {
      caught = e;
    }
    assert.equal(caught, tool);
    assert.equal((caught as ToolError).code, 'internal');
    assert.equal(sink.records.length, 2);
    assert.equal(sink.records[0]?.error, 'CliError: Firefly III failed on the server. (code=internal)');
  });
});

describe('expected (R6)', () => {
  it('writes nothing by default', () => {
    const sink = memorySink();
    setErrorSink(sink);
    errors.expected('reading the optional config file', new Error('ENOENT'));
    flushErrorFile();
    assert.equal(sink.records.length, 0);
  });

  it('writes EXPECTED under FIREFLY_ERROR_FILE_VERBOSE=1', () => {
    process.env.FIREFLY_ERROR_FILE_VERBOSE = '1';
    const sink = memorySink();
    setErrorSink(sink);
    errors.expected('reading the optional config file', new Error('ENOENT'));
    assert.equal(sink.records[0]?.level, 'EXPECTED');
  });
});

describe('the pre-install queue (§5.3)', () => {
  it('holds records reported before install and writes them on install, stamped with the app', () => {
    errors.caught('booting before the sink', new Error('early'));
    const sink = memorySink('mcp');
    setErrorSink(sink);
    assert.equal(sink.records.length, 1);
    assert.equal(sink.records[0]?.app, 'mcp');
  });

  it('is capped at 200; the overflow is counted and written as one WARN', () => {
    for (let i = 0; i < PRE_INSTALL_CAP + 7; i++) {
      errorFileFor(`cli/code/src/where-${i}.ts`).caught('booting', new Error(`e${i}`));
    }
    const sink = memorySink();
    setErrorSink(sink);
    assert.equal(PRE_INSTALL_CAP, 200);
    assert.equal(sink.records.length, PRE_INSTALL_CAP + 1);
    assert.equal(sink.records.at(-1)?.error, '7 records were dropped before the error file was installed');
    assert.equal(sink.records.at(-1)?.level, 'WARN');
  });
});

describe('the record', () => {
  it('call-site keys first, then the net; data is redacted; the error is scrubbed', () => {
    const sink = memorySink();
    setErrorSink(sink);
    const top = errorFileFor('cli/code/src/main.ts', { net: 'top' });
    top.caught('running an ffx verb', new CliError('Firefly III failed on the server.', 1), {
      verb: 'batch',
      exit: 1,
      code: 'internal',
      rid: '7f3a9c01',
      amount: '4211.08',
      apiKey: 'synthetic',
      skipped: undefined,
    });
    const r = sink.records[0];
    assert.deepEqual(r?.data, {
      verb: 'batch',
      exit: '1',
      code: 'internal',
      rid: '7f3a9c01',
      amount: '[ledger-field refused]',
      apiKey: '[redacted]',
      net: 'top',
    });
    errors.caught('reading a synthetic mail', new Error('no route to someone@example.test'));
    assert.equal(sink.records[1]?.error, 'Error: no route to [email]');
    assert.equal(sink.records[1]?.data, null, 'an explicit site carries no net');
  });

  it('a WARN with no error has an empty error field', () => {
    const sink = memorySink();
    setErrorSink(sink);
    errors.warn('reading the app answer', undefined, { status: 502 });
    assert.equal(sink.records[0]?.level, 'WARN');
    assert.equal(sink.records[0]?.error, '');
    assert.deepEqual(sink.records[0]?.stack, []);
  });

  it('a FATAL is never folded', () => {
    const sink = memorySink();
    setErrorSink(sink);
    for (let i = 0; i < 3; i++) errors.fatal('dying', new Error('same'));
    assert.equal(sink.records.length, 3);
  });
});

describe('a transient network fault is a folded WARN (§5.2, R7)', () => {
  const timeout = (): CliError => {
    const errno = Object.assign(new Error('connect ETIMEDOUT 127.0.0.1:7373'), { code: 'ETIMEDOUT', errno: -60, syscall: 'connect' });
    return new CliError('The app did not answer in time.', 5, 'unavailable', { cause: errno });
  };

  it('ETIMEDOUT in the cause chain → one WARN; a second within 10 minutes → none; after → one more', () => {
    mock.timers.enable({ apis: ['setTimeout', 'Date'], now: Date.UTC(2026, 8, 21, 18, 0, 0) });
    const sink = memorySink();
    setErrorSink(sink);
    const top = errorFileFor('cli/code/src/main.ts', { net: 'top' });
    top.warn('running an ffx verb', timeout(), { verb: 'accounts', exit: 5 });
    mock.timers.tick(5 * 60_000);
    top.warn('running an ffx verb', timeout(), { verb: 'accounts', exit: 5 });
    assert.equal(sink.records.length, 1);
    assert.equal(sink.records[0]?.level, 'WARN');
    mock.timers.tick(5 * 60_000 + 1);
    assert.equal(sink.records.length, 2, 'the window closed with one summary');
    assert.match(sink.records[1]?.error ?? '', /^×1 more in the 10m window from 18:00:00: CliError: /);
  });

  it('caught() of a transient fault is downgraded to WARN too', () => {
    const sink = memorySink();
    setErrorSink(sink);
    errors.caught('calling the plane', Object.assign(new Error('socket hang up'), { code: 'ECONNRESET' }));
    assert.equal(sink.records[0]?.level, 'WARN');
  });
});

describe('totality (R11)', () => {
  it('a throwing sink returns normally, with one stderr line per process', () => {
    const sink = memorySink();
    sink.write = () => {
      throw new Error('sink broke');
    };
    setErrorSink(sink);
    const writes: string[] = [];
    const spy = mock.method(process.stderr, 'write', (chunk: unknown) => {
      writes.push(String(chunk));
      return true;
    });
    try {
      assert.doesNotThrow(() => errors.caught('doing one', new Error('x')));
      assert.doesNotThrow(() => errors.caught('doing two', new Error('y')));
    } finally {
      spy.mock.restore();
    }
    assert.equal(writes.length, 1);
    assert.match(writes[0] ?? '', /^\[error-file\] the error sink threw/);
  });

  it('a throwing getter, a throwing toString and a circular data value return normally', () => {
    const sink = memorySink();
    setErrorSink(sink);
    const hostile = {
      get message(): string {
        throw new Error('getter');
      },
      toString(): string {
        throw new Error('nope');
      },
    };
    const circular: Record<string, unknown> = {};
    circular.self = circular;
    assert.doesNotThrow(() => errors.caught('doing', hostile, circular as unknown as Record<string, string>));
    assert.equal(sink.records.length, 1);
    assert.equal(sink.records[0]?.data?.self, '[object not allowed]');
  });

  it('re-entrancy (busy) drops the inner record instead of looping', () => {
    const sink = memorySink();
    const inner = errorFileFor('errorfile/test/inner.ts');
    sink.write = (record) => {
      sink.records.push(record);
      inner.caught('reporting from inside the sink', new Error('loop'));
    };
    setErrorSink(sink);
    errors.caught('outer', new Error('first'));
    assert.equal(sink.records.length, 1);
  });

  it('never calls console.*', () => {
    const calls: string[] = [];
    const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => mock.method(console, m, () => calls.push(m)));
    try {
      const sink = memorySink();
      sink.echo = true;
      sink.write = () => {
        throw new Error('broken');
      };
      setErrorSink(sink);
      const stderr = mock.method(process.stderr, 'write', () => true);
      try {
        errors.caught('echoing', new Error('x'));
      } finally {
        stderr.mock.restore();
      }
    } finally {
      for (const s of spies) s.mock.restore();
    }
    assert.deepEqual(calls, []);
  });
});

describe('the wrappers (§7.2)', () => {
  it('tryOr returns the fallback and reports', () => {
    const sink = memorySink();
    setErrorSink(sink);
    assert.equal(tryOr(errors, 'parsing the settings', () => JSON.parse('{') as unknown, null), null);
    assert.equal(tryOr(errors, 'parsing', () => 5, 0), 5);
    assert.equal(sink.records.length, 1);
  });

  it('tryOrAsync resolves the fallback and reports', async () => {
    const sink = memorySink();
    setErrorSink(sink);
    assert.deepEqual(await tryOrAsync(errors, 'fetching the capabilities', () => Promise.reject(new Error('no')), []), []);
    assert.equal(sink.records.length, 1);
  });

  it('reportRejection reports a fire-and-forget rejection', async () => {
    const sink = memorySink();
    setErrorSink(sink);
    reportRejection(errors, 'refreshing the list', Promise.reject(new Error('gone')));
    await new Promise((r) => setImmediate(r));
    assert.equal(sink.records[0]?.doing, 'refreshing the list');
  });

  it('guard reports sync throws and async rejections and returns void', async () => {
    const sink = memorySink();
    setErrorSink(sink);
    const sync = guard(errors, 'handling a line', () => {
      throw new Error('sync');
    });
    const later = guard(errors, 'handling a message', async () => {
      throw new Error('async');
    });
    assert.equal(sync(), undefined);
    assert.equal(later(), undefined);
    await new Promise((r) => setImmediate(r));
    assert.deepEqual(
      sink.records.map((r) => r.error),
      ['Error: sync', 'Error: async'],
    );
  });
});
