/**
 * The argument contract — pm/cli.mdx §7.
 *
 *   - Positional first, flags anywhere. --flag value and --flag=value both work.
 *   - A flag takes a value ONLY if the resolved verb (or the universal set)
 *     declares it. There is no hand-maintained list of value-taking flags.
 *   - An unknown flag is exit 2 with the nearest match; a missing value is exit 2.
 *   - Values are validated by kind: dates, months, amounts, integers, choices.
 */
import { resolveDate } from './dates.js';
import { CliError, EXIT } from './errors.js';
import { amountInputProblem } from './money.js';
import type { FlagDef, FlagValue, Universal, VerbDef } from './verbs.js';
import { UNIVERSAL_FLAGS, verbName } from './verbs.js';

export interface Parsed {
  verb: VerbDef;
  positionals: string[];
  flags: Record<string, FlagValue>;
  universal: Universal;
}

export function editDistance(a: string, b: string): number {
  const dp: number[] = Array.from({ length: b.length + 1 }, (_, j) => j);
  for (let i = 1; i <= a.length; i++) {
    let prev = dp[0] ?? 0;
    dp[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const tmp = dp[j] ?? 0;
      dp[j] = Math.min((dp[j] ?? 0) + 1, (dp[j - 1] ?? 0) + 1, prev + (a[i - 1] === b[j - 1] ? 0 : 1));
      prev = tmp;
    }
  }
  return dp[b.length] ?? 0;
}

export function nearest(word: string, candidates: readonly string[]): string | undefined {
  let best: string | undefined;
  let bestD = Infinity;
  for (const c of candidates) {
    const d = editDistance(word, c);
    if (d < bestD) {
      bestD = d;
      best = c;
    }
  }
  return best !== undefined && bestD <= Math.max(2, Math.floor(word.length / 3)) ? best : undefined;
}

function isFlagToken(t: string): boolean {
  return t.startsWith('-') && t !== '-' && !/^-\d/.test(t);
}

function flagName(token: string): { name: string; inline: string | undefined } {
  if (token === '-h') return { name: 'help', inline: undefined };
  const body = token.replace(/^--?/, '');
  const eq = body.indexOf('=');
  return eq === -1 ? { name: body, inline: undefined } : { name: body.slice(0, eq), inline: body.slice(eq + 1) };
}

/** The non-flag words of argv, skipping the value of every flag `defs` declares as value-taking. */
function wordsOf(argv: readonly string[], defs: Readonly<Record<string, FlagDef>>): string[] {
  const words: string[] = [];
  for (let i = 0; i < argv.length; i++) {
    const t = argv[i] as string;
    if (t === '--') break;
    if (isFlagToken(t)) {
      const { name, inline } = flagName(t);
      if (inline === undefined && defs[name]?.value) i++;
      continue;
    }
    words.push(t);
  }
  return words;
}

/**
 * Find the verb: the longest registry path that prefixes the non-flag words.
 *
 * "Flags anywhere" (cli.mdx §7) means a verb's own value flag may come BEFORE
 * the verb's words (`ffx transactions --month 2026-09 list`). Which tokens are
 * flag values depends on the verb, so each candidate is tried with its own
 * declarations, longest path first; the first that matches wins.
 */
export function resolveVerb(argv: readonly string[], registry: readonly VerbDef[]): { verb: VerbDef | undefined; words: string[] } {
  const universalWords = wordsOf(argv, UNIVERSAL_FLAGS);
  const byLength = [...registry].sort((a, b) => b.path.length - a.path.length);
  for (const v of byLength) {
    const words = v.flags ? wordsOf(argv, { ...UNIVERSAL_FLAGS, ...v.flags }) : universalWords;
    if (v.path.length > words.length) continue;
    if (v.path.length === 0 && words.length > 0) continue; // bare `ffx` only when there are no words
    if (v.path.every((p, i) => p === words[i])) return { verb: v, words };
  }
  return { verb: undefined, words: universalWords };
}

/** A token that looks like a negative amount ("-12.50", "-.5") — taken as a value so the amount check can explain it. */
function looksNegativeNumber(t: string): boolean {
  return /^-(\d|\.\d)/.test(t);
}

function validate(name: string, def: FlagDef, raw: string): string {
  switch (def.value) {
    case 'int':
      if (!/^\d+$/.test(raw)) throw new CliError(EXIT.USAGE, `--${name} takes a whole number (got "${raw}")`);
      return raw;
    case 'amount': {
      const problem = amountInputProblem(raw);
      if (problem) throw new CliError(EXIT.USAGE, `--${name}: ${problem}`);
      return raw;
    }
    case 'date': {
      const side = name === 'end' || name === 'as-of' || name === 'until' ? 'end' : 'start';
      const resolved = resolveDate(raw, side);
      if (!resolved) {
        throw new CliError(EXIT.USAGE, `--${name} takes YYYY-MM-DD, YYYY-MM, or one of today, yesterday, this-month, last-month, this-year, last-year, ytd (got "${raw}")`);
      }
      return resolved;
    }
    case 'month': {
      if (/^\d{4}-(0[1-9]|1[0-2])$/.test(raw)) return raw;
      if (raw === 'this-month' || raw === 'last-month' || raw === 'next-month') return raw;
      throw new CliError(EXIT.USAGE, `--${name} takes YYYY-MM, this-month, last-month or next-month (got "${raw}")`);
    }
    case 'choice':
      if (def.choices && !def.choices.includes(raw)) {
        throw new CliError(EXIT.USAGE, `--${name} must be one of ${def.choices.join(', ')} (got "${raw}")`);
      }
      return raw;
    default:
      return raw;
  }
}

function universalFrom(flags: Record<string, FlagValue>): Universal {
  const s = (k: string): string | undefined => (typeof flags[k] === 'string' ? (flags[k] as string) : undefined);
  const b = (k: string): boolean => flags[k] === true;
  return {
    format: s('format') as Universal['format'],
    write: b('write'),
    token: s('token'),
    maxChanges: s('max-changes'),
    api: s('api'),
    timeout: s('timeout'),
    noBringup: b('no-bringup'),
    quiet: b('quiet'),
    verbose: b('verbose'),
    jsonErrors: b('json-errors'),
    help: b('help'),
    yes: b('yes'),
  };
}

export function parseArgs(argv: readonly string[], registry: readonly VerbDef[]): Parsed {
  const { verb, words } = resolveVerb(argv, registry);
  if (!verb) {
    const first = words[0];
    const tops = [...new Set(registry.map((v) => v.path[0]).filter((p): p is string => !!p))];
    const suggestion = first ? nearest(first, tops) : undefined;
    throw new CliError(EXIT.USAGE, first ? `unknown verb: ${words.join(' ')}` : 'no verb given', {
      hint: suggestion ? `did you mean: ffx ${suggestion}   (ffx help lists every verb)` : 'ffx help',
    });
  }
  const verbFlags = verb.flags ?? {};
  const defs: Record<string, FlagDef> = { ...UNIVERSAL_FLAGS, ...verbFlags };
  const flags: Record<string, FlagValue> = {};
  const positionals: string[] = [];
  let pathLeft = verb.path.length;
  let afterDashDash = false;

  for (let i = 0; i < argv.length; i++) {
    const t = argv[i] as string;
    if (afterDashDash) {
      positionals.push(t);
      continue;
    }
    if (t === '--') {
      afterDashDash = true;
      continue;
    }
    if (!isFlagToken(t)) {
      if (pathLeft > 0) pathLeft--;
      else positionals.push(t);
      continue;
    }
    const { name, inline } = flagName(t);
    let def = defs[name];
    let negated = false;
    if (!def && name.startsWith('no-') && defs[name.slice(3)]) {
      if (defs[name.slice(3)]?.value) {
        throw new CliError(EXIT.USAGE, `--${name}: --${name.slice(3)} takes a value, so it cannot be negated with --no-`, {
          hint: `leave --${name.slice(3)} out instead   (ffx help ${verbName(verb)})`,
        });
      }
      def = defs[name.slice(3)];
      negated = true;
    }
    if (!def) {
      const suggestion = nearest(name, Object.keys(defs));
      throw new CliError(EXIT.USAGE, `unknown flag --${name} for "ffx ${verbName(verb)}"`, {
        hint: suggestion ? `did you mean --${suggestion}?   (ffx help ${verbName(verb)})` : `ffx help ${verbName(verb)}`,
      });
    }
    const key = negated ? name.slice(3) : name;
    if (!def.value) {
      if (inline !== undefined) throw new CliError(EXIT.USAGE, `--${name} is a switch and takes no value`);
      flags[key] = !negated;
      continue;
    }
    let raw = inline;
    if (raw === undefined) {
      const next = argv[i + 1];
      if (next === undefined || next === '--' || (isFlagToken(next) && !(def.value === 'amount' && looksNegativeNumber(next)))) {
        throw new CliError(EXIT.USAGE, `--${name} needs a value`, { hint: `ffx help ${verbName(verb)}` });
      }
      raw = next;
      i++;
    }
    const values = def.repeat ? raw.split(',').map((s) => s.trim()).filter(Boolean) : [raw];
    const checked = values.map((v) => validate(name, def as FlagDef, v));
    if (def.repeat) {
      const prev = flags[key];
      flags[key] = [...(Array.isArray(prev) ? prev : []), ...checked];
    } else {
      if (flags[key] !== undefined) throw new CliError(EXIT.USAGE, `--${name} given twice`);
      flags[key] = checked[0] as string;
    }
  }

  const universal = universalFrom(flags);
  if (!universal.help) {
    const posDefs = verb.positionals ?? [];
    const required = posDefs.filter((p) => p.required).length;
    if (positionals.length < required) {
      const missing = posDefs[positionals.length];
      throw new CliError(EXIT.USAGE, `missing <${missing?.name ?? 'argument'}> for "ffx ${verbName(verb)}"`, {
        hint: `ffx help ${verbName(verb)}`,
      });
    }
    const takesRest = posDefs.some((p) => p.rest);
    if (!takesRest && positionals.length > posDefs.length) {
      const stray = positionals[posDefs.length];
      throw new CliError(EXIT.USAGE, `unexpected argument "${stray}" for "ffx ${verbName(verb)}"`, {
        hint: `a switch cannot take a value — ffx help ${verbName(verb)}`,
      });
    }
  }
  return { verb, positionals, flags, universal };
}
