/**
 * The verb contract — pm/cli.mdx §6, §7.3.
 *
 * Each verb declares its own flags, with arity, next to its handler. The
 * parser, `ffx help`, and the tests all read these declarations; there is no
 * second list of flags anywhere.
 */
import type { PlaneClient } from './client.js';
import type { Config } from './config.js';
import type { Spinner } from './progress.js';
import type { Format, Outcome } from './render.js';

export type FlagKind = 'string' | 'date' | 'month' | 'amount' | 'int' | 'path' | 'choice';

export interface FlagDef {
  /** Absent → a boolean flag. */
  value?: FlagKind;
  choices?: readonly string[];
  /** May be given more than once; collected into a list. Comma-separated values are split too. */
  repeat?: boolean;
  help: string;
}

export interface PositionalDef {
  name: string;
  required?: boolean;
  /** Collects every remaining positional. */
  rest?: boolean;
  help: string;
}

export type FlagValue = string | string[] | boolean;

export interface Universal {
  format: Format | undefined;
  write: boolean;
  token: string | undefined;
  maxChanges: string | undefined;
  api: string | undefined;
  timeout: string | undefined;
  noBringup: boolean;
  quiet: boolean;
  verbose: boolean;
  jsonErrors: boolean;
  help: boolean;
  yes: boolean;
}

export interface Ctx {
  verb: VerbDef;
  positionals: string[];
  flags: Record<string, FlagValue>;
  universal: Universal;
  cfg: Config;
  format: Format;
  spinner: Spinner;
  stdoutTTY: boolean;
  stdinTTY: boolean;
  registry: readonly VerbDef[];
  /** The authenticated client. Brings the app up first when needed. */
  plane(): Promise<PlaneClient>;
  /** stderr, suppressed by --quiet. */
  note(text: string): void;
  str(name: string): string | undefined;
  bool(name: string): boolean;
  list(name: string): string[];
}

export interface VerbDef {
  path: readonly string[];
  group: string;
  summary: string;
  /** The machine-plane route(s) this verb calls — shown in `ffx help <verb>`. */
  route?: string;
  positionals?: readonly PositionalDef[];
  flags?: Readonly<Record<string, FlagDef>>;
  /** This verb changes the ledger (dry run unless --write). */
  writes?: boolean;
  /**
   * Replaces the generic write blurb in `ffx help <verb>`. For the writes that have no dry run
   * and so no confirm token (the sign-in account verbs): --write does it, and there is nothing
   * to hold a token over (pm/cli.mdx §13).
   */
  writeNote?: readonly string[];
  examples?: readonly string[];
  run(ctx: Ctx): Promise<Outcome>;
}

/** The universal flags — pm/cli.mdx §7.1. Accepted by every verb. */
export const UNIVERSAL_FLAGS: Readonly<Record<string, FlagDef>> = {
  format: { value: 'choice', choices: ['json', 'table', 'csv'], help: 'stdout format; table on a TTY, json when piped' },
  write: { help: 'actually write; without it every write verb is a dry run' },
  token: { value: 'string', help: 'the confirm token from the dry run you just read' },
  'max-changes': { value: 'int', help: 'the write ceiling for this run (default 200)' },
  api: { value: 'string', help: 'a different install; loopback or https: only' },
  timeout: { value: 'int', help: 'per-call timeout in ms (default 30000; ignored by long ingest verbs)' },
  'no-bringup': { help: 'never start the app; if it is down, exit 5' },
  quiet: { help: 'no spinner, no informational stderr; errors still print' },
  verbose: { help: 'informational stderr, the resolved target and key fingerprint' },
  'json-errors': { help: 'errors on stderr as one JSON object per line' },
  help: { help: 'usage for this verb' },
  yes: { help: 'confirm an operation that asks (key rotate)' },
};

export function verbName(v: VerbDef): string {
  return v.path.join(' ');
}
