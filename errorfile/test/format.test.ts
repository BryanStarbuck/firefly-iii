// format — pm/error_err.mdx §3.2, §3.4, R16. The golden lines are produced by PHP's LineFormat from
// errorfile/fixtures/golden-records.json; this file asserts format.ts produces the same bytes.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';

import { capMiddle, describeError, stripControlChars, trimStack, utf8Length } from '../src/describe.js';
import { formatRecord, mergeContext, summaryText } from '../src/format.js';
import type { ErrorLevel, ErrorRecord } from '../src/format.js';
import { redactData, scrubText } from '../src/redact.js';

const FIXTURES = new URL('../../fixtures/', import.meta.url);

type GoldenRecord = {
  name: string;
  kind: 'record' | 'summary';
  ts?: string;
  level: ErrorLevel;
  app: string;
  where: string;
  doing: string;
  error?: string;
  cause?: string;
  stack?: string[];
  data?: Record<string, string | number | boolean | null>;
  count?: number;
  first_ms?: number;
  headline?: string;
  now_ms?: number;
  window_s?: number;
};

/** The golden pipeline, exactly as the fixture's _about describes it for PHP. */
function render(r: GoldenRecord): string {
  if (r.kind === 'summary') {
    return formatRecord({
      ts: new Date(r.now_ms ?? 0).toISOString(),
      level: r.level,
      app: r.app,
      where: r.where,
      doing: r.doing,
      error: summaryText(r.count ?? 0, (r.window_s ?? 60) * 1000, r.first_ms ?? 0, r.headline ?? ''),
      cause: '',
      stack: [],
      data: null,
    });
  }
  return formatRecord({
    ts: r.ts ?? '',
    level: r.level,
    app: r.app,
    where: r.where,
    doing: r.doing,
    error: scrubText(r.error ?? ''),
    cause: scrubText(r.cause ?? ''),
    stack: r.stack ?? [],
    data: redactData(r.data ?? null),
  });
}

function record(overrides: Partial<ErrorRecord> = {}): ErrorRecord {
  return {
    ts: '2026-09-21T16:04:11.902Z',
    level: 'ERROR',
    app: 'ffx',
    where: 'cli/code/src/main.ts',
    doing: 'running an ffx verb',
    error: "TypeError: Cannot read properties of undefined (reading 'id')",
    cause: ' | cause: Error: synthetic inner (code=ESYNTH)',
    stack: ['at run (cli/code/src/main.ts:812:19)'],
    data: { verb: 'batch' },
    ...overrides,
  };
}

describe('golden lines (R16)', () => {
  const doc = JSON.parse(readFileSync(new URL('golden-records.json', FIXTURES), 'utf8')) as { records: GoldenRecord[] };
  const golden = readFileSync(new URL('golden-lines.txt', FIXTURES), 'utf8');

  it('the fixture holds 12 synthetic records covering every level and a summary', () => {
    assert.equal(doc.records.length, 12);
    const levels = new Set(doc.records.map((r) => r.level));
    for (const level of ['WARN', 'ERROR', 'FATAL', 'EXPECTED']) assert.ok(levels.has(level as ErrorLevel), `no ${level} record`);
    assert.ok(doc.records.some((r) => r.kind === 'summary'));
  });

  it('format.ts renders golden-records.json to golden-lines.txt byte for byte', () => {
    const produced = doc.records.map(render).join('');
    if (produced !== golden) {
      const a = produced.split('\n');
      const b = golden.split('\n');
      const at = a.findIndex((line, i) => line !== b[i]);
      assert.fail(`line ${at + 1} differs:\n  ts:     ${JSON.stringify(a[at])}\n  golden: ${JSON.stringify(b[at])}`);
    }
  });

  for (const r of doc.records) {
    it(`record "${r.name}" is one header line, at most 8,000 UTF-8 bytes`, () => {
      const text = render(r);
      assert.ok(utf8Length(text) <= 8000, `${utf8Length(text)} bytes`);
      assert.ok(text.endsWith('\n'));
      const lines = text.slice(0, -1).split('\n');
      assert.match(lines[0] ?? '', /^\[\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z\] \[(WARN|ERROR|FATAL|EXPECTED)\] \[/);
      for (const frame of lines.slice(1)) assert.ok(frame.startsWith('    at '), `a stack line is not a frame: ${frame.slice(0, 60)}`);
    });
  }
});

describe('format (§3.2)', () => {
  it('renders the line shape', () => {
    assert.equal(
      formatRecord(record()),
      "[2026-09-21T16:04:11.902Z] [ERROR] [ffx] [cli/code/src/main.ts] running an ffx verb — TypeError: Cannot read properties of undefined (reading 'id') {verb=batch} | cause: Error: synthetic inner (code=ESYNTH)\n" +
        '    at run (cli/code/src/main.ts:812:19)\n',
    );
  });

  it('omits an empty field together with its separator', () => {
    assert.equal(
      formatRecord(record({ error: '', cause: '', stack: [], data: { status: '502' } })),
      '[2026-09-21T16:04:11.902Z] [ERROR] [ffx] [cli/code/src/main.ts] running an ffx verb {status=502}\n',
    );
    assert.equal(formatRecord(record({ app: '', where: '', doing: '', error: '', cause: '', stack: [], data: null })), '[2026-09-21T16:04:11.902Z] [ERROR] [?]\n');
  });

  it('quotes a value holding a space, =, {, } or ", escaping the quote', () => {
    const line = formatRecord(record({ data: { during: 'running artisan firefly-iii:cron', q: 'say "hi"', pair: 'a=b', brace: '{x}', empty: '', plain: 'x' }, cause: '', stack: [] }));
    assert.ok(line.includes('{during="running artisan firefly-iii:cron" q="say \\"hi\\"" pair="a=b" brace="{x}" empty= plain=x}'), line);
  });

  it('context keys follow call-site keys, only where absent, in the fixed order', () => {
    const data = mergeContext({ verb: 'batch', code: 'internal' }, { pid: 5301, code: 'ignored', net: 'top', rid: '7f3a9c01' });
    assert.deepEqual(Object.keys(data ?? {}), ['verb', 'code', 'net', 'rid', 'pid']);
    assert.equal(data?.code, 'internal');
    assert.equal(mergeContext(null, {}), null);
  });

  it('walks the cause chain, codes appended, and stops a circular chain', () => {
    const root = Object.assign(new Error('SQLITE_BUSY'), { code: 'SQLITE_BUSY' });
    root.name = 'SqliteError';
    assert.equal(describeError(new TypeError('outer', { cause: root })), 'TypeError: outer | cause: SqliteError: SQLITE_BUSY (code=SQLITE_BUSY)');
    const a: Error & { cause?: unknown } = new Error('a');
    const b = new Error('b', { cause: a });
    a.cause = b;
    assert.equal(describeError(a), 'Error: a | cause: Error: b');
  });

  it('trims the stack: drops node_modules, node internals and library frames, shortens repo paths, caps at 12', () => {
    const root = '/' + 'home/someone/BGit/firefly-iii';
    const frames = [
      'Error: boom',
      `    at ours (${root}/cli/code/dist/src/main.js:1:2)`,
      `    at dep (${root}/cli/node_modules/some-dep/index.js:3:4)`,
      `    at lib (${root}/cli/code/dist/src/vendor/error-file/core.js:5:6)`,
      `    at lib2 (${root}/errorfile/src/core.ts:5:6)`,
      '    at process.processTicksAndRejections (node:internal/process/task_queues:95:5)',
      ...Array.from({ length: 20 }, (_, i) => `    at f${i} (file://${root}/mcp/dist/server.js:${i}:1)`),
    ].join('\n');
    const out = trimStack(frames);
    assert.equal(out[0], 'at ours (cli/code/dist/src/main.js:1:2)');
    assert.equal(out[1], 'at f0 (mcp/dist/server.js:0:1)');
    assert.ok(!out.some((l) => l.includes('node_modules') || l.includes('error-file') || l.includes('node:internal')));
    assert.equal(out.length, 12);
  });

  it('a message cannot forge a second header line', () => {
    const text = formatRecord(record({ error: 'Error: bad\n[2026-01-01T00:00:00.000Z] [ERROR] [web] forged\r\u2028x\u2029y\u0000z\u007f', cause: '', stack: [], data: null }));
    assert.equal(text.split('\n').length, 2);
    assert.equal(stripControlChars('a\u2028b\u2029c\x7fd\te'), 'a b c d e');
  });

  it('caps the message in the middle (code points), the data block, and the whole record in bytes at a frame boundary', () => {
    const described = describeError(new Error('x'.repeat(5000) + 'END'));
    assert.ok(described.length <= 2000);
    assert.ok(described.endsWith('END'));
    assert.equal(capMiddle('😀'.repeat(10), 5), '😀 … 😀');
    const data: Record<string, string> = {};
    for (let i = 0; i < 60; i++) data[`k${i}`] = 'v'.repeat(100);
    const line = formatRecord(record({ data, stack: [], cause: '' }));
    const block = line.slice(line.indexOf('{'), line.lastIndexOf('}') + 1);
    assert.ok(block.length <= 1002, `${block.length}`);
    const frames = Array.from({ length: 400 }, (_, i) => `at f${i} (cli/code/src/€-${i}.ts:1:1)`);
    const huge = formatRecord(record({ stack: frames }));
    assert.ok(utf8Length(huge) <= 8000);
    assert.ok(utf8Length(huge) > 7900, 'the cap is used, not undercut');
    assert.match(huge, /\.ts:1:1\)\n$/);
  });

  it('a header that alone passes 8,000 bytes has its message middle-cut until it fits', () => {
    const text = formatRecord(record({ error: `Error: ${'x'.repeat(9000)}END`, cause: '', stack: ['at x (a.ts:1:1)'] }));
    assert.ok(utf8Length(text) <= 8000, `${utf8Length(text)}`);
    assert.ok(text.includes(' … '));
    assert.ok(text.split('\n')[0]?.includes('END {verb=batch}'));
    // Multi-byte text: LineFormat's shrink subtracts the byte overage from the code-point count, so
    // the cut is deeper than strictly needed — the record still fits, in both languages alike.
    const wide = formatRecord(record({ error: `Error: ${'€'.repeat(6000)}END`, cause: '', stack: [] }));
    assert.ok(utf8Length(wide) <= 8000, `${utf8Length(wide)}`);
    const both = formatRecord(record({ error: 'E'.repeat(9000), cause: ` | cause: ${'C'.repeat(9000)}`, stack: [] }));
    assert.ok(utf8Length(both) <= 8000);
  });

  it('describes primitives and throwing values without throwing', () => {
    assert.equal(describeError('plain string'), 'Thrown string: plain string');
    assert.equal(describeError(42), 'Thrown number: 42');
    const hostile = {
      get message(): string {
        throw new Error('getter');
      },
      toString(): string {
        throw new Error('toString');
      },
    };
    assert.doesNotThrow(() => describeError(hostile));
  });

  it('the summary text names the window by its start (§3.4)', () => {
    const first = Date.UTC(2026, 8, 21, 18, 41, 2, 118);
    assert.equal(summaryText(37, 60_000, first, 'RuntimeException: Synthetic row failure'), '×37 more in the 60s window from 18:41:02: RuntimeException: Synthetic row failure');
    assert.equal(summaryText(2, 600_000, first, ''), '×2 more in the 10m window from 18:41:02');
  });
});
