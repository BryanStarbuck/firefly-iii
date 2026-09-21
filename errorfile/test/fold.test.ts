// fold — pm/error_err.mdx R10, §3.5 (the normaliser), §5.3.
import assert from 'node:assert/strict';
import { afterEach, beforeEach, describe, it, mock } from 'node:test';

import { errorFileFor, resetErrorFileForTests, setErrorSink } from '../src/core.js';
import { createBurstFolder, normalizeMessage } from '../src/fold.js';
import type { FoldSummary } from '../src/fold.js';
import { memorySink } from '../src/test-sink.js';

const errors = errorFileFor('errorfile/test/fold.test.ts');

beforeEach(() => resetErrorFileForTests());
afterEach(() => {
  mock.timers.reset();
  resetErrorFileForTests();
});

describe('fold (R10)', () => {
  it('500 identical faults in 10 s → 1 line + 1 summary', () => {
    mock.timers.enable({ apis: ['setTimeout', 'Date'], now: Date.UTC(2026, 8, 21, 18, 41, 2, 118) });
    const sink = memorySink();
    setErrorSink(sink);
    for (let i = 0; i < 500; i++) {
      errors.caught('importing a statement row', new Error(`timeout after ${i} ms`));
      mock.timers.tick(20);
    }
    assert.equal(sink.records.length, 1);
    mock.timers.tick(61_000);
    assert.equal(sink.records.length, 2);
    assert.equal(sink.records[1]?.error, '×499 more in the 60s window from 18:41:02: Error: timeout after 499 ms');
    assert.equal(sink.records[1]?.level, 'ERROR');
    assert.equal(sink.records[1]?.doing, 'importing a statement row');
  });

  it('a different doing, where or class is a different key', () => {
    const sink = memorySink();
    setErrorSink(sink);
    errors.caught('doing one', new Error('same'));
    errors.caught('doing two', new Error('same'));
    errorFileFor('errorfile/test/other.ts').caught('doing one', new Error('same'));
    errors.caught('doing one', new TypeError('same'));
    assert.equal(sink.records.length, 4);
  });

  it('normalises digits, hex, uuids and paths so `id 123` folds with `id 456` (the Normalizer rules)', () => {
    assert.equal(normalizeMessage('id 123'), normalizeMessage('id 456'));
    assert.equal(normalizeMessage('blob deadbeefcafe0001'), 'blob <hex>');
    assert.equal(normalizeMessage('row 0b6e5d9a-2a8a-4d4b-9f5e-6a0c7d1f2e3b'), 'row <uuid>');
    assert.equal(normalizeMessage('open /home/someone/a.db failed'), 'open <path> failed');
    assert.equal(normalizeMessage('x'.repeat(400)).length, 300);
  });

  it('the key cap evicts least-recently-seen and still writes owed summaries', () => {
    const summaries: FoldSummary[] = [];
    const folder = createBurstFolder({ maxKeys: 2, emit: (s) => summaries.push(s) });
    assert.equal(folder.admit('a', 60_000, null, 0), true);
    assert.equal(folder.admit('a', 60_000, null, 1), false); // a owes 1
    assert.equal(folder.admit('b', 60_000, null, 2), true);
    assert.equal(folder.admit('c', 60_000, null, 3), true); // evicts a (least recently seen)
    assert.equal(folder.size(), 2);
    assert.deepEqual(summaries, [{ key: 'a', count: 1, windowMs: 60_000, firstAt: 0 }]);
    folder.reset();
  });

  it('flushAll writes every owed summary (exit, fatal)', () => {
    const summaries: FoldSummary[] = [];
    const folder = createBurstFolder({ maxKeys: 10, emit: (s) => summaries.push(s) });
    folder.admit('k', 60_000, null, 0);
    folder.admit('k', 60_000, null, 5);
    folder.admit('k', 60_000, null, 6);
    folder.flushAll();
    assert.equal(summaries[0]?.count, 2);
    folder.reset();
  });

  it('the stack is never part of the key', () => {
    const sink = memorySink();
    setErrorSink(sink);
    const a = new Error('same');
    a.stack = 'Error: same\n    at one (cli/code/src/a.ts:1:1)';
    const b = new Error('same');
    b.stack = 'Error: same\n    at two (cli/code/src/b.ts:2:2)';
    errors.caught('doing', a);
    errors.caught('doing', b);
    assert.equal(sink.records.length, 1);
  });
});
