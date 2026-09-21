/**
 * T3 — the ledger read as another's. Every tool carries the ff_ prefix and
 * the which-server clause, and a foreign-shaped id is refused with a redirect
 * naming the right neighbour (§3.4 layers 3, 4 and 6).
 */
import { findForeign } from '../gates.js';
import { WHICH_SERVER } from '../tools/tool.js';
import type { Canary } from './canary.js';

const SAMPLES: Array<[unknown, 'actual_budget' | 'quickbooks' | undefined]> = [
  [{ id: '6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f' }, 'actual_budget'],
  [{ account_id: '9130347596842356' }, 'quickbooks'],
  [{ group_id: 'INV-1001' }, 'quickbooks'],
  [{ realm_id: '4620816365' }, 'quickbooks'],
  [{ id: 12 }, undefined],
  [{ account_id: 'Northbank Checking ••4021' }, undefined],
  [{ transactions: [{ external_id: 'ofx:4021:6f1c2d3e-4a5b-4c6d-8e9f-0a1b2c3d4e5f' }] }, undefined],
];

export const canary: Canary = {
  name: 'routing',
  threats: ['T3'],
  summary: 'ff_ on every tool, the which-server clause on every description, foreign ids redirected',
  check(ctx) {
    const problems: string[] = [];
    for (const t of ctx.tools) {
      if (!/^ff_[a-z_]+$/.test(t.name)) problems.push(`${t.name}: not ^ff_[a-z_]+$`);
      if (!t.description.includes(WHICH_SERVER)) problems.push(`${t.name}: missing the which-server clause`);
    }
    for (const [args, want] of SAMPLES) {
      const got = findForeign(args)?.server;
      if (got !== want) problems.push(`${JSON.stringify(args)}: expected ${want ?? 'no redirect'}, got ${got ?? 'no redirect'}`);
    }
    return problems;
  },
};
