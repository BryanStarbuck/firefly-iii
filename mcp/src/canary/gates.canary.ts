/**
 * T1 / T2 / T16 — money moved, a silent large write, admin from an agent.
 * The write tier's shape, checked on the one array: 19 writes; 17 dry-run by
 * default with a confirm echo and a ceiling; ff_undo and ff_trigger_recurrence
 * with no dry_run; no delete, purge, destroy, rotate or admin tool of anything.
 */
import type { Canary } from './canary.js';

const NO_DRY_RUN = new Set(['ff_undo', 'ff_trigger_recurrence']);
const FORBIDDEN_NAME = /(delete|destroy|purge|remove_|rotate|_admin(_|$)|webhook|password|token|_users?(_|$)|switch_|mark_duplicate|merge)/;

export const canary: Canary = {
  name: 'gates',
  threats: ['T1', 'T2', 'T16'],
  summary: 'every write tool is gated (dry_run default, confirm echo, ceiling); no admin or delete tool exists',
  check(ctx) {
    const problems: string[] = [];
    const writes = ctx.tools.filter((t) => t.tier === 'write');
    if (writes.length !== 19) problems.push(`expected 19 write tools, found ${writes.length}`);
    for (const t of ctx.tools) {
      if (FORBIDDEN_NAME.test(t.name)) problems.push(`${t.name}: a name this catalogue must never have`);
      if (t.tier === 'write' && !t.write) problems.push(`${t.name}: write tier with no write spec`);
      if (t.tier === 'read' && t.write) problems.push(`${t.name}: read tier with a write spec`);
    }
    for (const t of writes) {
      const props = (t.inputSchema.properties ?? {}) as Record<string, Record<string, unknown>>;
      if (NO_DRY_RUN.has(t.name)) {
        if (props.dry_run) problems.push(`${t.name}: must NOT offer dry_run`);
      } else {
        if (!props.dry_run) problems.push(`${t.name}: must offer dry_run`);
        if (!t.write?.dryRun) problems.push(`${t.name}: dry run must be the default`);
        if (!props.confirm) problems.push(`${t.name}: must take a confirm echo`);
        if (!props.max_changes) problems.push(`${t.name}: must take max_changes`);
      }
      if (t.name === 'ff_undo' && !props.confirm) problems.push('ff_undo: must take the confirm token from ff_preview_undo');
    }
    return problems;
  },
};
