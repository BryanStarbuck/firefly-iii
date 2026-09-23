/**
 * A fake machine plane for the CLI's tests — it speaks the apis.mdx contract:
 * the envelope, the key header, the constant 401 body, the origin gate, and a
 * confirm-token write protocol. It records every call so tests can assert on
 * exactly what the CLI sent.
 */
import http from 'node:http';
import type { AddressInfo } from 'node:net';

export interface RecordedCall {
  method: string;
  path: string;
  query: Record<string, string | string[]>;
  body: unknown;
  headers: http.IncomingHttpHeaders;
}

export interface FakePlane {
  url: string;
  calls: RecordedCall[];
  /** Override or add a route: key is "METHOD /route" (without /machine/v1). */
  routes: Map<string, (call: RecordedCall) => [number, unknown]>;
  close(): Promise<void>;
}

export const TEST_KEY = 'a'.repeat(8) + '0123456789abcdef'.repeat(3) + 'b'.repeat(8);
export const TEST_TOKEN = 'cf_test_token_1';

const META = {
  target: 'local',
  operator: 'ops@local',
  administrationId: 1,
  administrationName: 'Household',
  primaryCurrency: 'USD',
  asOf: '2026-09-21T18:41:02.118Z',
  tookMs: 3,
  truncated: false,
};

export function ok(data: unknown, meta: Record<string, unknown> = {}): [number, unknown] {
  return [200, { ok: true, data, meta: { ...META, ...meta } }];
}

export function fail(status: number, code: string, message: string, hint?: string): [number, unknown] {
  return [status, { ok: false, error: { code, message, ...(hint ? { hint } : {}) } }];
}

function parseQuery(search: URLSearchParams): Record<string, string | string[]> {
  const q: Record<string, string | string[]> = {};
  for (const [k, v] of search) {
    const prev = q[k];
    q[k] = prev === undefined ? v : Array.isArray(prev) ? [...prev, v] : [prev, v];
  }
  return q;
}

/** A write route with the dry-run + confirm-token protocol (apis.mdx §7). */
export function writeRoute(changes: Record<string, number>, extra: Record<string, unknown> = {}): (call: RecordedCall) => [number, unknown] {
  return (call) => {
    const body = (call.body ?? {}) as Record<string, unknown>;
    if (body.dry_run !== false) {
      return ok({ dry_run: true, changes, confirm_token: TEST_TOKEN, expires_at: '2026-09-21T19:11:02Z', fingerprint: 'sha256:7ab3', ...extra });
    }
    if (body.confirm_token !== TEST_TOKEN) return fail(409, 'conflict', 'The plan changed since the token was minted.', 'Re-plan: run the same command without --write.');
    return ok({ dry_run: false, changes, operation_id: 417, ...extra });
  };
}

function defaultRoutes(): Map<string, (call: RecordedCall) => [number, unknown]> {
  const r = new Map<string, (call: RecordedCall) => [number, unknown]>();
  r.set('GET /ping', () => ok({ pong: true, key: 'aaaa…/sha256:1234' }));
  r.set('GET /whoami', () =>
    ok({ operator: 'ops@local', administrationId: 1, administrationName: 'Household', primaryCurrency: 'USD', tiers: { read: true, write: false, admin: false } }),
  );
  r.set('GET /capabilities', () =>
    ok({
      apiVersion: 'v1',
      serverVersion: '6.4.2',
      tiers: { read: true, write: true, admin: false },
      routes: [
        { path: '/accounts', methods: ['GET', 'POST'], tier: { GET: 'read', POST: 'write' }, status: { GET: 'live', POST: 'live' }, summary: { GET: 'Accounts.', POST: 'Create.' } },
        { path: '/ingest/apply', methods: ['POST'], tier: { POST: 'write' }, status: { POST: 'planned' }, summary: { POST: 'NOT IMPLEMENTED YET (phase P4).' } },
      ],
      features: ['analytics', 'charts'],
    }),
  );
  r.set('GET /accounts', () =>
    ok({
      accounts: [
        { id: 1, name: 'Northbank Checking ••4021', type: 'asset', account_role: 'defaultAsset', current_balance: '4211.08', currency_code: 'USD', current_balance_date: '2026-09-21', active: true },
        { id: 2, name: 'Savings', type: 'asset', account_role: 'savingAsset', current_balance: '1234567.891', currency_code: 'USD', current_balance_date: '2026-09-21', active: true },
        { id: 3, name: 'Yen wallet', type: 'asset', account_role: 'cashWalletAsset', current_balance: '-5000', currency_code: 'JPY', current_balance_date: '2026-09-21', active: true },
      ],
    }),
  );
  r.set('GET /budget-period', () =>
    ok({
      start: '2026-09-01',
      end: '2026-09-30',
      budgets: [
        { id: 3, name: 'Groceries', currency_code: 'USD', limit: '650.00', spent: '412.10', left: '237.90' },
        { id: 4, name: 'Dining', currency_code: 'USD', limit: null, spent: '84.00', left: null },
        { id: 5, name: 'Gifts', currency_code: 'USD', limit: '0.00', spent: '0', left: '0.00' },
      ],
      totals: [{ currency_code: 'USD', available: '4000.00', budgeted_total: '650.00', spent_total: '496.10', left_to_spend: '3503.90' }],
      budgets_with_limit: 2,
      budgets_without_limit: 1,
    }),
  );
  r.set('GET /transactions', () =>
    ok({
      transactions: [
        {
          id: 88,
          group_title: 'Costco run',
          transactions: [
            { transaction_journal_id: 101, type: 'withdrawal', date: '2026-09-03T00:00:00-07:00', amount: '120.00', currency_code: 'USD', description: 'food', source_name: 'Checking', destination_name: 'Costco', category_name: 'Groceries' },
            { transaction_journal_id: 102, type: 'withdrawal', date: '2026-09-03T00:00:00-07:00', amount: '40.00', currency_code: 'USD', description: 'office', source_name: 'Checking', destination_name: 'Costco', category_name: null },
          ],
        },
        {
          id: 89,
          transactions: [
            { transaction_journal_id: 103, type: 'withdrawal', date: '2026-09-04', amount: '5.00', currency_code: 'USD', description: 'Coffee', source_name: 'Checking', destination_name: 'Blue Bottle', category_name: null },
          ],
        },
      ],
    }),
  );
  // Sign-in accounts (apis.mdx §8.11a): no dry run, no confirm token — the shape matters.
  r.set('GET /admin/users', () =>
    ok({
      users: [
        { id: 1, email: 'ops@local', is_owner: true, is_operator: true, blocked: false, blocked_code: null, has_mfa: false, administration_id: 1, created_at: '2026-01-01T00:00:00Z' },
        { id: 2, email: 'locked@local', is_owner: false, is_operator: false, blocked: true, blocked_code: 'email_changed', has_mfa: true, administration_id: 2, created_at: '2026-02-01T00:00:00Z' },
      ],
      operator_setting: 'FIREFLY_MACHINE_OPERATOR',
    }),
  );
  const account = (extra: Record<string, unknown>) => (call: RecordedCall): [number, unknown] => {
    const body = (call.body ?? {}) as Record<string, unknown>;
    return ok({
      user: { id: 1, email: body.email ?? 'ops@local', is_owner: true, blocked: false, blocked_code: null, has_mfa: false, administration_id: 1, administration_title: 'Household', created_at: '2026-01-01T00:00:00Z' },
      sign_in: 'http://127.0.0.1:7373/login',
      next: null,
      ...extra,
    });
  };
  r.set('POST /admin/first-user', account({ created: true, note: 'This account is the owner of the install.' }));
  r.set('POST /admin/users', account({ created: true }));
  r.set('POST /admin/users/ops@local/password', account({ password_set: true, undoable: false, notes: ['The old password stopped working now.'] }));
  r.set('POST /admin/users/1/password', account({ password_set: true, undoable: false, notes: ['The old password stopped working now.'] }));
  r.set('POST /transactions', writeRoute({ created: 1 }));
  r.set('POST /transactions/categorize', writeRoute({ updated: 46 }));
  r.set('PUT /budgets/Groceries/limits', writeRoute({ updated: 1 }, { limits: [{ name: 'Groceries', start: '2026-10-01', end: '2026-10-31', currency_code: 'USD', previous: null, amount: '650.00' }] }));
  r.set('GET /ingest/coverage', () =>
    ok({ missing: [{ entity: 'household', institution: 'Northbank', account: 'Checking_x4021', period: '2025-02' }, { entity: 'acme_llc', institution: 'Northbank', account: 'Checking_x7734', period: '2026-01' }] }),
  );
  r.set('GET /charts/net-worth', () =>
    ok({
      chart: 'net-worth',
      x: { kind: 'period', labels: ['2026-07', '2026-08', '2026-09'] },
      series: [{ key: 'net', label: 'Net worth', currency_code: 'USD', values: ['1000.00', null, '1200.50'] }],
      provenance: { start: '2026-07-01', end: '2026-09-30', transfers: 'excluded' },
    }),
  );
  r.set('POST /accounts/Checking/reconcile/plan', () =>
    ok({ start_balance: '4000.00', end_balance: '4211.08', selected_sum: '211.08', target_balance: '4211.08', difference: '0.00', currency_code: 'USD', uncleared_count: 3, confirm_token: TEST_TOKEN }),
  );
  r.set('GET /undo/last', () => ok({ operation_id: 417, route: '/transactions', at: '2026-09-21T18:00:00Z', description: 'created transaction group #90', confirm_token: TEST_TOKEN }));
  r.set('POST /undo', (call) => ((call.body as Record<string, unknown>)?.confirm_token === TEST_TOKEN ? ok({ operation_id: 417, description: 'deleted transaction group #90' }) : fail(409, 'conflict', 'stale token')));
  return r;
}

export async function startFakePlane(opts: { key?: string } = {}): Promise<FakePlane> {
  const key = opts.key ?? TEST_KEY;
  const calls: RecordedCall[] = [];
  const routes = defaultRoutes();
  const server = http.createServer((req, res) => {
    const url = new URL(req.url ?? '/', 'http://127.0.0.1');
    let raw = '';
    req.setEncoding('utf8');
    req.on('data', (c: string) => (raw += c));
    req.on('end', () => {
      const send = (status: number, payload: unknown, type = 'application/json'): void => {
        res.writeHead(status, { 'Content-Type': type });
        res.end(typeof payload === 'string' ? payload : JSON.stringify(payload));
      };
      if (url.pathname === '/up') return send(200, '<html>up</html>', 'text/html');
      if (!url.pathname.startsWith('/machine/v1')) return send(404, '<html>not found</html>', 'text/html');
      // Gate 2: a browser always sends Origin or Sec-Fetch-Site; the CLI must send neither.
      if (req.headers.origin || req.headers['sec-fetch-site']) return send(404, { ok: false, error: { code: 'not_found' } });
      if (url.searchParams.has('key')) return send(404, { ok: false, error: { code: 'not_found' } });
      if (req.headers['x-firefly-machine-key'] !== key) return send(401, { ok: false, error: { code: 'unauthorized' } });
      const route = url.pathname.slice('/machine/v1'.length);
      let body: unknown;
      try {
        body = raw ? JSON.parse(raw) : undefined;
      } catch {
        body = raw;
      }
      const call: RecordedCall = { method: req.method ?? 'GET', path: route, query: parseQuery(url.searchParams), body, headers: req.headers };
      calls.push(call);
      const handler = routes.get(`${call.method} ${decodeURIComponent(route)}`);
      if (!handler) return send(404, { ok: false, error: { code: 'not_found', message: `No route ${call.method} ${route}.`, hint: 'GET /machine/v1/capabilities' } });
      const [status, payload] = handler(call);
      return send(status, payload);
    });
  });
  await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
  const port = (server.address() as AddressInfo).port;
  return {
    url: `http://127.0.0.1:${port}`,
    calls,
    routes,
    close: () => new Promise<void>((resolve) => server.close(() => resolve())),
  };
}
