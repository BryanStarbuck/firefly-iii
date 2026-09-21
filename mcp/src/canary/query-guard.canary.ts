/**
 * T4 — the escape hatches used to reach withheld data. ff_search and
 * ff_get_upstream are GET-only reads; the mirror path is relative and
 * denylisted before the plane's own denylist sees it; results are row-capped.
 */
import { checkMirrorPath } from '../tools/statements.js';
import type { Canary } from './canary.js';

const REFUSED = ['users', 'users/1', 'configuration', 'cron/abc', 'data/export/transactions', '../machine/v1/admin', '/api/v1/accounts', 'https://example.test/x', 'accounts//x'];
const ALLOWED = ['accounts', 'insight/expense/category', 'autocomplete/accounts', 'about/user'];

export const canary: Canary = {
  name: 'query-guard',
  threats: ['T4'],
  summary: 'the search and mirror tools are GET-only, capped, and refuse denylisted or climbing paths',
  check(ctx) {
    const problems: string[] = [];
    for (const name of ['ff_search', 'ff_get_upstream']) {
      const t = ctx.tools.find((x) => x.name === name);
      if (!t) problems.push(`${name}: missing`);
      else if (t.route.method !== 'GET' || t.tier !== 'read') problems.push(`${name}: must be a GET read`);
    }
    const search = ctx.tools.find((x) => x.name === 'ff_search');
    if (search && search.params.limit?.kind !== 'limit') problems.push('ff_search: limit is not row-capped');
    for (const p of REFUSED) {
      try {
        checkMirrorPath(p);
        problems.push(`mirror path "${p}" was allowed`);
      } catch {
        /* refused, as it must be */
      }
    }
    for (const p of ALLOWED) {
      try {
        checkMirrorPath(p);
      } catch {
        problems.push(`mirror path "${p}" was refused`);
      }
    }
    return problems;
  },
};
