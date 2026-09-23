/**
 * `ffx help` — generated from the verb declarations, so the help can never
 * describe a flag the parser does not have (pm/cli.mdx §7.3).
 */
import type { FlagDef, VerbDef } from './verbs.js';
import { UNIVERSAL_FLAGS, verbName } from './verbs.js';

const GROUP_ORDER = [
  'Orientation',
  'Reading the ledger',
  'Analytics, charts and reports',
  'The statements pipeline',
  'Writing (dry run unless --write)',
  'Sign-in accounts (dry run unless --write)',
];

function argSpec(def: FlagDef): string {
  switch (def.value) {
    case undefined:
      return '';
    case 'choice':
      return ` <${(def.choices ?? []).join('|')}>`;
    case 'amount':
      return ' <"12.50">';
    case 'date':
      return ' <date>';
    case 'month':
      return ' <YYYY-MM>';
    case 'int':
      return ' <n>';
    case 'path':
      return ' <path>';
    default:
      return ' <value>';
  }
}

export function usageLine(v: VerbDef): string {
  const pos = (v.positionals ?? []).map((p) => (p.required ? `<${p.name}>` : `[${p.name}]`) + (p.rest ? '…' : '')).join(' ');
  return `ffx ${verbName(v)}${pos ? ` ${pos}` : ''}`.trimEnd();
}

export function catalogue(registry: readonly VerbDef[]): string {
  const lines: string[] = ['ffx — the operator CLI for this install of Firefly III (a thin client of /machine/v1)', ''];
  const groups = [...new Set([...GROUP_ORDER, ...registry.map((v) => v.group)])];
  for (const g of groups) {
    const verbs = registry.filter((v) => v.group === g);
    if (!verbs.length) continue;
    lines.push(g.toUpperCase());
    const width = Math.min(48, Math.max(...verbs.map((v) => usageLine(v).length)));
    for (const v of verbs) {
      const u = usageLine(v);
      lines.push(`  ${u.padEnd(width)}  ${v.summary}`);
    }
    lines.push('');
  }
  lines.push('Every verb accepts: --format json|table|csv  --write  --token <t>  --max-changes <n>  --api <url>');
  lines.push('                    --timeout <ms>  --no-bringup  --quiet  --verbose  --json-errors  --help');
  lines.push('');
  lines.push('ffx help <verb> shows a verb\'s flags and the machine-plane route it calls. Spec: pm/cli.mdx');
  return lines.join('\n');
}

function flagLines(flags: Readonly<Record<string, FlagDef>>): string[] {
  const entries = Object.entries(flags);
  const specs = entries.map(([n, d]) => `--${n}${argSpec(d)}${d.repeat ? ' …' : ''}`);
  const width = Math.min(34, Math.max(0, ...specs.map((s) => s.length)));
  return entries.map(([, d], i) => `  ${(specs[i] ?? '').padEnd(width)}  ${d.help}`);
}

export function verbHelp(v: VerbDef): string {
  const lines = [`usage: ${usageLine(v)} [flags]`, '', v.summary];
  if (v.writeNote) {
    lines.push('', ...v.writeNote);
  } else if (v.writes) {
    lines.push('', 'WRITES to the ledger. Without --write this is a dry run that prints the plan and a confirm token;',
      'apply with --write --token <token>. The server\'s write tier must also be on (FIREFLY_MACHINE_ALLOW_WRITE=1).');
  }
  if (v.route) lines.push('', `machine-plane route: ${v.route}`);
  if (v.positionals?.length) {
    lines.push('', 'arguments:');
    for (const p of v.positionals) lines.push(`  <${p.name}>${p.required ? '' : ' (optional)'}  ${p.help}`);
  }
  if (v.flags && Object.keys(v.flags).length) {
    lines.push('', 'flags:', ...flagLines(v.flags));
  }
  lines.push('', 'universal flags:', ...flagLines(UNIVERSAL_FLAGS));
  if (v.examples?.length) lines.push('', 'examples:', ...v.examples.map((e) => `  ${e}`));
  return lines.join('\n');
}
