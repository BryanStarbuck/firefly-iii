/**
 * The CLI end to end, as a subprocess, against the fake machine plane.
 * pm/cli.mdx §18 (Client, Write protocol, stdout purity, exit codes).
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { after, before, describe, it } from 'node:test';

import type { FakePlane } from './fakeplane.js';
import { fail, startFakePlane, TEST_KEY, TEST_TOKEN } from './fakeplane.js';
import { runCli, sandbox } from './helpers.js';

let plane: FakePlane;

before(async () => {
  plane = await startFakePlane();
});

after(async () => {
  await plane.close();
});

function env(extra: Record<string, string> = {}): NodeJS.ProcessEnv {
  return { ...sandbox({ key: TEST_KEY, apiUrl: plane.url }).env, ...extra };
}

describe('reads', () => {
  it('stdout is exactly the envelope when piped, and the key rides in a header only', async () => {
    const r = await runCli(['accounts', 'list'], env());
    assert.equal(r.code, 0, r.stderr);
    const parsed = JSON.parse(r.stdout);
    assert.equal(parsed.ok, true);
    assert.equal(parsed.data.accounts[1].current_balance, '1234567.891'); // byte-identical string, never a number
    const call = plane.calls.at(-1);
    assert.equal(call?.path, '/accounts');
    assert.equal(call?.headers['x-firefly-machine-key'], TEST_KEY);
    assert.equal(call?.headers['x-firefly-client'], 'ffx');
    assert.equal(call?.headers.origin, undefined);
    assert.equal(call?.headers['sec-fetch-site'], undefined);
    assert.equal(call?.headers['sec-fetch-mode'], undefined);
    assert.ok(!JSON.stringify(call?.query).includes(TEST_KEY));
    assert.equal(call?.query.type, 'asset');
    assert.equal(call?.query.active, 'true');
  });

  it('the JSON round trip is byte-identical to the server\'s amounts', async () => {
    const r = await runCli(['accounts', 'list', '--format', 'json'], env());
    assert.ok(r.stdout.includes('"1234567.891"'));
    assert.ok(r.stdout.includes('"-5000"'));
  });

  it('a table shows separators and currency, and "no limit" as —', async () => {
    const r = await runCli(['budget', 'period', '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /Dining[^\n]*—/);
    assert.match(r.stdout, /Gifts[^\n]*0\.00 USD/);
    assert.match(r.stderr, /with NO limit/);
    const acc = await runCli(['accounts', 'list', '--format', 'table'], env());
    assert.match(acc.stdout, /1,234,567\.891 USD/);
    assert.match(acc.stdout, /-5,000/);
  });

  it('csv keeps the server strings and leaves "no limit" empty', async () => {
    const r = await runCli(['budget', 'period', '--format', 'csv'], env());
    assert.match(r.stdout, /Dining,USD,,84\.00,/);
  });

  it('splits are shown one row per journal and marked', async () => {
    const r = await runCli(['transactions', 'list', '--without-category', '--month', '2026-09', '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /1\/2/);
    assert.match(r.stderr, /splits of one transaction group/);
    const call = plane.calls.at(-1);
    assert.equal(call?.query.without_category, 'true');
    assert.equal(call?.query.start, '2026-09-01');
    assert.equal(call?.query.end, '2026-09-30');
  });

  it('categories tree prints the server\'s YAML document as sent — even when piped — and --format json the envelope', async () => {
    const yaml = 'app: firefly_iii\ngenerated_at: 2026-09-21T22:00:00Z\ncounts:\n  groups: 1\n  subcategories: 1\ngroups:\n  - name: Food\n    id: "12"\n    subcategories:\n      - name: Groceries\n        full_name: Food > Groceries\n        id: "13"\n';
    plane.routes.set('GET /categories/tree', (c) =>
      c.query.format === 'yaml'
        ? [200, { ok: true, data: { app: 'firefly_iii', format: 'yaml', counts: { groups: 1, subcategories: 1 }, yaml }, meta: {} }]
        : [200, { ok: true, data: { app: 'firefly_iii', counts: { groups: 1, subcategories: 1 }, groups: [{ name: 'Food', id: '12', subcategories: [{ name: 'Groceries', full_name: 'Food > Groceries', id: '13' }] }], yaml }, meta: {} }],
    );
    const r = await runCli(['categories', 'tree'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.equal(r.stdout, yaml, 'byte for byte, nothing assembled client-side');
    assert.equal(plane.calls.at(-1)?.query.format, 'yaml');

    const j = await runCli(['categories', 'tree', '--format', 'json'], env());
    assert.equal(j.code, 0, j.stderr);
    const parsed = JSON.parse(j.stdout);
    assert.equal(parsed.data.groups[0].subcategories[0].full_name, 'Food > Groceries');
    assert.equal(parsed.data.yaml, yaml);
    assert.equal(plane.calls.at(-1)?.query.format, 'json');
  });

  it('names become *_name references, ids *_id — the server resolves them', async () => {
    await runCli(['transactions', 'list', '--account', 'Checking', '--account', '7', '--category', 'Groceries'], env());
    const call = plane.calls.at(-1);
    assert.deepEqual(call?.query['account_names[]'], 'Checking');
    assert.deepEqual(call?.query['account_ids[]'], '7');
    assert.equal(call?.query.category_name, 'Groceries');
  });

  it('statements missing prints plain lines, and exits 3 when nothing is missing', async () => {
    const root = fs.mkdtempSync(path.join(sandbox().dir, 'stmts-'));
    const r = await runCli(['statements', 'missing', '--root', root, '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.equal(r.stdout, 'household/Northbank/Checking_x4021 2025-02\nacme_llc/Northbank/Checking_x7734 2026-01\n');
    plane.routes.set('GET /ingest/coverage', () => [200, { ok: true, data: { missing: [] }, meta: {} }]);
    const none = await runCli(['statements', 'missing', '--root', root, '--format', 'table'], env());
    assert.equal(none.code, 3);
    assert.equal(none.stdout, '');
  });

  it('refuses a statements path outside the root before any call', async () => {
    const root = fs.mkdtempSync(path.join(sandbox().dir, 'stmts-'));
    const n = plane.calls.length;
    const r = await runCli(['statements', 'import-file', '/etc/hosts', '--root', root, '--account', 'Checking'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /outside the statements root/);
    assert.equal(plane.calls.length, n);
  });

  it('charts keep gaps as gaps and print a sparkline', async () => {
    const r = await runCli(['chart', 'net-worth', '--format', 'table', '--start', '2026-07-01', '--end', '2026-09-30'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stderr, /Net worth \(USD\)\s+▁ █/);
    assert.match(r.stdout, /2026-08[^\n]*—/);
  });

  it('capabilities shows live and planned routes', async () => {
    const r = await runCli(['capabilities', '--planned', '--format', 'table'], env());
    assert.match(r.stdout, /\/ingest\/apply[^\n]*planned/);
    assert.ok(!r.stdout.includes('/accounts '));
  });
});

describe('errors map to exit codes and hints', () => {
  it('a wrong key is exit 6 with the fingerprint, never the key', async () => {
    const e = { ...sandbox({ key: 'c'.repeat(64), apiUrl: plane.url }).env };
    const r = await runCli(['accounts', 'list'], e);
    assert.equal(r.code, 6);
    assert.match(r.stderr, /refused the machine key \(cccc…\/sha256:/);
    assert.ok(!r.stderr.includes('c'.repeat(64)));
  });

  it('no key is exit 2 with the remedy, and nothing is sent', async () => {
    const n = plane.calls.length;
    const r = await runCli(['accounts', 'list'], { ...sandbox({ apiUrl: plane.url }).env });
    assert.equal(r.code, 2);
    assert.match(r.stderr, /ffx key init/);
    assert.equal(plane.calls.length, n);
  });

  it('a 0644 credentials file is refused with the chmod fix', async () => {
    const r = await runCli(['accounts', 'list'], { ...sandbox({ key: TEST_KEY, apiUrl: plane.url, mode: 0o644 }).env });
    assert.equal(r.code, 2);
    assert.match(r.stderr, /chmod 600/);
  });

  it('not_found is exit 3 and the server hint is shown', async () => {
    const r = await runCli(['accounts', 'show', 'Nope'], env());
    assert.equal(r.code, 3);
    assert.match(r.stderr, /fix: GET \/machine\/v1\/capabilities/);
  });

  it('write_disabled is exit 2 and names the switch', async () => {
    plane.routes.set('POST /transactions/set-budget', () => fail(403, 'write_disabled', 'The write tier is off.', 'Set FIREFLY_MACHINE_ALLOW_WRITE=1 in the app\'s .env and restart it'));
    const r = await runCli(['transactions', 'set-budget', '--ids', '1', '--to', 'Dining'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /FIREFLY_MACHINE_ALLOW_WRITE/);
  });

  it('--json-errors prints one JSON object on stderr', async () => {
    const r = await runCli(['accounts', 'show', 'Nope', '--json-errors'], env());
    const line = JSON.parse(r.stderr.trim());
    assert.equal(line.error.code, 'not_found');
    assert.equal(line.exit, 3);
  });

  it('the app being down is exit 5 with --no-bringup', async () => {
    const r = await runCli(['accounts', 'list'], { ...sandbox({ key: TEST_KEY, apiUrl: 'http://127.0.0.1:1' }).env });
    assert.equal(r.code, 5);
  });

  it('plain http to a non-loopback host is refused before connecting (R3)', async () => {
    const r = await runCli(['accounts', 'list'], { ...sandbox({ key: TEST_KEY, apiUrl: 'http://books.example.com:7373' }).env });
    assert.equal(r.code, 2);
    assert.match(r.stderr, /cleartext/);
  });

  it('an unknown verb is exit 2 with a suggestion', async () => {
    const r = await runCli(['acounts'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /did you mean: ffx accounts/);
  });
});

describe('the write protocol', () => {
  it('without --write it is a dry run: plan, token, and the exact apply command', async () => {
    const r = await runCli(['transactions', 'add', '--type', 'withdrawal', '--amount', '12.50', '--from', 'Checking', '--to', 'Blue Bottle', '--description', 'Coffee', '--date', '2026-09-21', '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stderr, /DRY RUN — nothing was changed/);
    assert.match(r.stderr, new RegExp(`confirm token  ${TEST_TOKEN}`));
    assert.match(r.stderr, /apply with:\s+ffx transactions add .* --write --token cf_test_token_1/);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.equal(body.dry_run, true);
    const split = (body.transactions as Record<string, unknown>[])[0];
    assert.equal(split?.amount, '12.50'); // the string the operator typed, untouched
    assert.equal(split?.source_name, 'Checking');
    assert.equal(split?.destination_name, 'Blue Bottle');
    assert.equal(body.apply_rules, true);
  });

  it('--write --token applies with dry_run:false and the token', async () => {
    const r = await runCli(['transactions', 'categorize', '--ids', '101,103', '--to', 'Groceries', '--write', '--token', TEST_TOKEN, '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stderr, /APPLIED/);
    assert.match(r.stderr, /operation #417 — reverse it with: ffx undo/);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.equal(body.dry_run, false);
    assert.equal(body.confirm_token, TEST_TOKEN);
    assert.deepEqual(body.journal_ids, ['101', '103']);
    assert.equal(body.category_name, 'Groceries');
  });

  it('a stale token is exit 4 (conflict)', async () => {
    const r = await runCli(['transactions', 'categorize', '--ids', '101', '--to', 'Groceries', '--write', '--token', 'cf_old'], env());
    assert.equal(r.code, 4);
    assert.match(r.stderr, /Re-plan/);
  });

  it('--write without --token when piped refuses with exit 2 and names the token', async () => {
    const r = await runCli(['transactions', 'categorize', '--ids', '101', '--to', 'Groceries', '--write'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /--write without --token/);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.equal(body.dry_run, true); // only the plan was fetched
  });

  it('a selection needs --ids or filters, never neither', async () => {
    const r = await runCli(['transactions', 'categorize', '--to', 'Groceries'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /which transactions/);
  });

  it('budget set sends the period and the amount string, and --max-changes', async () => {
    const r = await runCli(['budget', 'set', 'Groceries', '--amount', '650.00', '--month', '2026-10', '--max-changes', '5', '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.deepEqual({ start: body.start, end: body.end, amount: body.amount, max: body.max_changes }, { start: '2026-10-01', end: '2026-10-31', amount: '650.00', max: 5 });
    assert.match(r.stdout, /650\.00 USD/);
  });

  it('reconcile plans first and shows the difference', async () => {
    const r = await runCli(['reconcile', 'Checking', '--balance', '4211.08', '--end', '2026-08-31', '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /difference[^\n]*0\.00 USD/);
    assert.match(r.stderr, /apply with:\s+ffx reconcile Checking .* --write --token/);
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.equal(body.target_balance, '4211.08');
    assert.equal(body.end, '2026-08-31');
  });

  it('undo previews, then applies only with the token', async () => {
    const p = await runCli(['undo', '--format', 'table'], env());
    assert.equal(p.code, 0, p.stderr);
    assert.match(p.stderr, /created transaction group #90/);
    const a = await runCli(['undo', '--write', '--token', TEST_TOKEN, '--format', 'table'], env());
    assert.equal(a.code, 0, a.stderr);
    assert.match(a.stderr, /deleted transaction group #90/);
    assert.equal(plane.calls.at(-1)?.path, '/undo');
  });
});

describe('local verbs', () => {
  it('bare ffx never fails when the app is down and prints the report on stdout', async () => {
    const r = await runCli(['--format', 'table'], { ...sandbox({ apiUrl: 'http://127.0.0.1:1' }).env });
    assert.equal(r.code, 0);
    assert.match(r.stdout, /Firefly III — this machine/);
    assert.match(r.stdout, /down/);
  });

  it('bare ffx against a live plane shows the operator and administration', async () => {
    const r = await runCli(['--format', 'table'], env());
    assert.match(r.stdout, /ops@local · administration "Household" \(#1\)/);
    assert.match(r.stdout, /writes\s+DISABLED/);
  });

  it('help is plain text in every format', async () => {
    const r = await runCli(['help', 'transactions', 'add'], env());
    assert.equal(r.code, 0);
    assert.match(r.stdout, /^usage: ffx transactions add/);
    assert.match(r.stdout, /WRITES to the ledger/);
    assert.match(r.stdout, /machine-plane route: POST \/transactions/);
  });

  it('key init mints once, key show never prints the key', async () => {
    const sb = sandbox({ apiUrl: plane.url });
    const a = await runCli(['key', 'init', '--format', 'table'], sb.env);
    assert.equal(a.code, 0, a.stderr);
    assert.match(a.stdout, /minted a new machine key/);
    const key = JSON.parse(fs.readFileSync(sb.credentialsFile, 'utf8')).firefly_iii.machine.api_key as string;
    const b = await runCli(['key', 'init', '--format', 'table'], sb.env);
    assert.match(b.stdout, /already exists/);
    const s = await runCli(['key', 'show'], sb.env);
    assert.equal(s.code, 0);
    assert.ok(!s.stdout.includes(key));
    assert.ok(s.stdout.includes(key.slice(0, 4)));
  });

  it('key rotate needs --yes', async () => {
    const r = await runCli(['key', 'rotate'], env());
    assert.equal(r.code, 2);
    assert.match(r.stderr, /--yes/);
  });

  it('logs never leak the key into cli.info', async () => {
    const sb = sandbox({ key: TEST_KEY, apiUrl: plane.url });
    await runCli(['accounts', 'list'], sb.env);
    const info = fs.readFileSync(path.join(sb.stateDir, 'cli.info'), 'utf8');
    assert.match(info, /API_CALL\] GET \/accounts status=200/);
    assert.ok(!info.includes(TEST_KEY));
    assert.ok(!info.includes('4211.08'), 'no amounts in the log');
    assert.equal(fs.statSync(path.join(sb.stateDir, 'cli.info')).mode & 0o777, 0o600);
  });
});

describe('sign-in accounts — ffx admin (cli.mdx §12.4)', () => {
  it('a dry run prints the plan, never asks for a password, and calls nothing', async () => {
    const before = plane.calls.length;
    const r = await runCli(['admin', 'set-password', '--email', 'ops@local'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /Would replace the password of sign-in account ops@local/);
    assert.match(r.stdout, /cannot be undone/);
    assert.match(r.stdout, /ffx admin set-password --email ops@local --write/);
    assert.equal(plane.calls.length, before, 'a dry run must not reach the plane at all');
    assert.doesNotMatch(r.stderr, /New password/);
  });

  it('--password-stdin sends the piped password and nothing else', async () => {
    const r = await runCli(['admin', 'set-password', '--email', 'ops@local', '--password-stdin', '--write'], env(), 'a-long-enough-passphrase\n');
    assert.equal(r.code, 0, r.stderr);
    const call = plane.calls.at(-1);
    assert.equal(call?.method, 'POST');
    assert.equal(call?.path, '/admin/users/ops%40local/password');
    const body = call?.body as Record<string, unknown>;
    assert.deepEqual(Object.keys(body).sort(), ['password']);
    assert.equal(body.password, 'a-long-enough-passphrase', 'the trailing newline is stripped, nothing else');
    // No dry_run / confirm_token: these routes have none (apis.mdx §7.2).
    assert.equal(body.dry_run, undefined);
    assert.equal(body.confirm_token, undefined);
  });

  it('--clear-mfa and --unblock travel only when asked', async () => {
    await runCli(['admin', 'set-password', '--id', '1', '--clear-mfa', '--unblock', '--password-stdin', '--write'], env(), 'a-long-enough-passphrase');
    const body = plane.calls.at(-1)?.body as Record<string, unknown>;
    assert.equal(plane.calls.at(-1)?.path, '/admin/users/1/password');
    assert.equal(body.clear_mfa, true);
    assert.equal(body.unblock, true);
  });

  it('--password works and warns that argv is readable', async () => {
    const r = await runCli(['admin', 'create-user', '--email', 'second@example.com', '--password', 'a-long-enough-passphrase', '--write'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stderr, /--password puts the password in argv/);
    assert.equal((plane.calls.at(-1)?.body as Record<string, unknown>).email, 'second@example.com');
  });

  it('refuses a too-short and an over-long password before any call', async () => {
    const before = plane.calls.length;
    const short = await runCli(['admin', 'set-password', '--email', 'ops@local', '--password-stdin', '--write'], env(), 'short');
    assert.equal(short.code, 2);
    assert.match(short.stderr, /at least 16/);
    const long = await runCli(['admin', 'set-password', '--email', 'ops@local', '--password-stdin', '--write'], env(), 'x'.repeat(73));
    assert.equal(long.code, 2);
    assert.match(long.stderr, /72 bytes/);
    assert.equal(plane.calls.length, before, 'neither may reach the plane');
  });

  it('--write with no password and no terminal refuses with the stdin recipe', async () => {
    const r = await runCli(['admin', 'set-password', '--email', 'ops@local', '--write'], env(), '');
    assert.equal(r.code, 2);
    assert.match(r.stderr, /no terminal to ask at/);
    assert.match(r.stderr, /--password-stdin/);
  });

  it('refuses something that is not an email, and refuses naming an account twice', async () => {
    const notEmail = await runCli(['admin', 'set-password', '--email', 'not-an-email', '--write'], env());
    assert.equal(notEmail.code, 2);
    assert.match(notEmail.stderr, /signs in by email, not by username/);
    const both = await runCli(['admin', 'set-password', '--email', 'ops@local', '--id', '1', '--write'], env());
    assert.equal(both.code, 2);
    assert.match(both.stderr, /either --email or --id/);
  });

  it('lists accounts as a table, blocked ones included', async () => {
    const r = await runCli(['admin', 'users', '--format', 'table'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /locked@local/);
    assert.match(r.stdout, /email_changed/);
    assert.match(r.stderr, /FIREFLY_MACHINE_OPERATOR/);
  });

  it('help says admin tier and never promises a confirm token', async () => {
    const r = await runCli(['help', 'admin', 'set-password'], env());
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /ADMIN TIER/);
    assert.match(r.stdout, /no dry run and no confirm token/);
    assert.doesNotMatch(r.stdout, /apply with --write --token/);
  });

  it('the applied answer shows the account and the login URL, never a secret', async () => {
    const r = await runCli(['admin', 'set-password', '--email', 'ops@local', '--password-stdin', '--write', '--format', 'table'], env(), 'a-long-enough-passphrase');
    assert.equal(r.code, 0, r.stderr);
    assert.match(r.stdout, /ops@local/);
    assert.match(r.stderr, /sign in at http:\/\/127\.0\.0\.1:7373\/login/);
    assert.doesNotMatch(r.stdout + r.stderr, /a-long-enough-passphrase/);
  });
});
