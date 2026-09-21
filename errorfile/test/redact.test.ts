// redact — pm/error_err.mdx §12, R12, AC 10. The corpus is synthetic only (§12 "Open-source safety"):
// 4211.08, Synthetic Payee, someone@example.test, the GB82 example IBAN and all-0 keys.
import assert from 'node:assert/strict';
import { afterEach, describe, it } from 'node:test';

import { errorFileFor, resetErrorFileForTests, setErrorSink } from '../src/core.js';
import { LEDGER_REFUSED, redactData, REDACTED, scrubText, useRepoBasePathForTests } from '../src/redact.js';
import { memorySink } from '../src/test-sink.js';

const SECRET_KEYS = ['password', 'pass', 'clientSecret', 'accessToken', 'Authorization', 'cookie', 'sessionId', 'apiKey', 'signature', 'credentials', 'bearer', 'csrf', 'xsrf_token'];
const LEDGER_KEYS = [
  'amount',
  'balance',
  'payee',
  'notes',
  'note',
  'memo',
  'account_name',
  'accountName',
  'category_name',
  'description',
  'imported_payee',
  'statementText',
  'iban',
  'bic',
  'account_number',
  'source_name',
  'destination_name',
  'opening_balance',
  'virtual_balance',
  'foreign_amount',
  'internal_reference',
  'sepa_ct_id',
];
const SYNTHETIC = 'SYNTHETIC-VALUE-9f3a';

const KEY64 = '0'.repeat(64);
const KEY32 = '0'.repeat(32);
const IBAN = 'GB82WEST12345698765432';
const EMAIL = 'someone@example.test';

afterEach(() => {
  useRepoBasePathForTests(null);
  resetErrorFileForTests();
});

describe('data keys (§12.3)', () => {
  it('redacts every secret key', () => {
    const out = redactData(Object.fromEntries(SECRET_KEYS.map((k) => [k, SYNTHETIC])));
    for (const key of SECRET_KEYS) assert.equal(out?.[key], REDACTED, key);
  });

  it('refuses every ledger key, even when the value is null', () => {
    const out = redactData(Object.fromEntries(LEDGER_KEYS.map((k) => [k, SYNTHETIC])));
    for (const key of LEDGER_KEYS) assert.equal(out?.[key], LEDGER_REFUSED, key);
    assert.equal(redactData({ note: null })?.note, LEDGER_REFUSED);
  });

  it('lets opaque ids, counts, booleans, codes and statuses through', () => {
    assert.deepEqual(redactData({ journal_id: 'j-1', count: 3, ok: true, status: 500, code: 'internal', gone: undefined, none: null }), {
      journal_id: 'j-1',
      count: '3',
      ok: 'true',
      status: '500',
      code: 'internal',
      none: 'null',
    });
  });

  it('cleans keys, caps them at 60, caps values at 300 and keys at 40, and refuses objects', () => {
    const out = redactData({ 'a b=c{d}': 'x', ['k'.repeat(80)]: 'y', long: 'v'.repeat(400), obj: { a: 1 }, arr: [1] });
    assert.equal(out?.['a_b_c_d_'], 'x');
    assert.equal(out?.['k'.repeat(60)], 'y');
    assert.equal(out?.long?.length, 300);
    assert.equal(out?.obj, '[object not allowed]');
    assert.equal(out?.arr, '[array not allowed]');
    const many: Record<string, number> = {};
    for (let i = 0; i < 50; i++) many[`k${i}`] = i;
    assert.equal(Object.keys(redactData(many) ?? {}).length, 40);
  });
});

describe('text scrubs (§12.4), in order', () => {
  it('the SQL text of a QueryException is cut at " (Connection: ", keeping a code suffix and later causes', () => {
    assert.equal(
      scrubText('SQLSTATE[HY000]: General error: 5 database is locked (Connection: sqlite, SQL: update x set amount = 4211.08) (code=HY000) | cause: PDOException: busy'),
      'SQLSTATE[HY000]: General error: 5 database is locked (code=HY000) | cause: PDOException: busy',
    );
  });

  it('URL query values for the sensitive names, keeping the names', () => {
    assert.equal(
      scrubText('GET /cb?code=abc&state=xyz&page=2&sig=zz&api_key=q&access_token=t#frag failed'),
      'GET /cb?code=[redacted]&state=[redacted]&page=2&sig=[redacted]&api_key=[redacted]&access_token=[redacted]#frag failed',
    );
  });

  it('≥32 hex, IBAN, email, amount, 9–17 digits', () => {
    assert.equal(scrubText(`key ${KEY64} and ${KEY32} but not ${'0'.repeat(31)}g`), `key [REDACTED-KEY] and [REDACTED-KEY] but not ${'0'.repeat(31)}g`);
    assert.equal(scrubText(`to ${IBAN} or GB82 WEST 1234 5698 7654 32.`), 'to [iban] or [iban].');
    assert.equal(scrubText(`mail ${EMAIL}, done`), 'mail [email], done');
    assert.equal(scrubText('paid 4211.08 and -12.50 in 6.1.20 at 0.25s'), 'paid [amount] and [amount] in 6.1.20 at 0.25s');
    assert.equal(scrubText('Allowed memory size of 134217728 bytes exhausted (tried to allocate 20480 bytes)'), 'Allowed memory size of [number] bytes exhausted (tried to allocate 20480 bytes)');
    assert.equal(scrubText('card 4111111111111111 and 123456789012345678'), 'card [number] and 123456789012345678');
  });

  it('the repo base path → nothing, then a home directory → ~', () => {
    const home = '/' + 'home/someone';
    useRepoBasePathForTests(`${home}/BGit/firefly-iii`);
    assert.equal(scrubText(`open '${home}/BGit/firefly-iii/cli/code/x.json' and '${home}/.credentials/y.json'`), "open 'cli/code/x.json' and '~/.credentials/y.json'");
    assert.equal(scrubText(`${'/'}Users/synthetic/T/x`), '~/T/x');
  });

  it('the real base path is found from the module location', async () => {
    const { repoBasePath } = await import('../src/redact.js');
    useRepoBasePathForTests(null);
    const base = repoBasePath();
    assert.ok(base.length > 1);
    assert.ok(!base.endsWith('/errorfile'), base);
  });
});

describe('no secret or ledger value reaches a record (AC 10)', () => {
  it('the corpus leaves none of its needles in the formatted line', () => {
    const sink = memorySink();
    setErrorSink(sink);
    const data = Object.fromEntries([...SECRET_KEYS, ...LEDGER_KEYS].map((k) => [k, SYNTHETIC]));
    const cause = Object.assign(new Error(`inner for Synthetic Payee (Connection: sqlite, SQL: insert 4211.08, 'Synthetic Payee')`), { code: 'HY000' });
    errorFileFor('errorfile/test/redact.test.ts').caught(
      'posting a synthetic request',
      new Error(`POST /x?token=${SYNTHETIC} failed with ${KEY64}, ${KEY32}, ${IBAN}, ${EMAIL} and 4211.08`, { cause }),
      { ...data, detail: `paid 4211.08 to ${IBAN}` },
    );
    const text = sink.lines().join('');
    for (const needle of [SYNTHETIC, KEY64, KEY32, IBAN, EMAIL, '4211.08', "'Synthetic Payee'"]) {
      assert.ok(!text.includes(needle), `${needle} leaked: ${text}`);
    }
  });
});
