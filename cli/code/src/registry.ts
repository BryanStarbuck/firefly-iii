/**
 * THE one array of verbs. The parser, `ffx help`, and the tests read it;
 * there is no second list (pm/cli.mdx §7.3).
 */
import { analyticsVerbs } from './commands/analytics.js';
import { ledgerVerbs } from './commands/ledger.js';
import { orientationVerbs } from './commands/orientation.js';
import { statementsVerbs } from './commands/statements.js';
import { writeVerbs } from './commands/writes.js';
import type { VerbDef } from './verbs.js';
import { verbName } from './verbs.js';

/** Verbs that never need the machine plane (and so never trigger bring-up or a key read). */
export const LOCAL_VERBS = new Set(['', 'status', 'doctor', 'up', 'stop', 'logs', 'key init', 'key show', 'key rotate', 'help', 'install-path']);

export const REGISTRY: readonly VerbDef[] = Object.freeze([
  ...orientationVerbs,
  ...ledgerVerbs,
  ...analyticsVerbs,
  ...statementsVerbs,
  ...writeVerbs,
]);

/** Fails loudly at startup (and in tests) if two verbs claim the same path. */
export function assertUniquePaths(registry: readonly VerbDef[] = REGISTRY): void {
  const seen = new Set<string>();
  for (const v of registry) {
    const n = verbName(v);
    if (seen.has(n)) throw new Error(`duplicate verb path: "${n}"`);
    seen.add(n);
  }
}
