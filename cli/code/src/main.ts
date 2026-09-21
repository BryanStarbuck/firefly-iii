/**
 * ffx — the operator CLI for THIS install of Firefly III. pm/cli.mdx.
 *
 * parse → (bring the app up, read the key) → one verb → render → exit code.
 *
 * Every failure becomes a CliError with an exit code from §14 and a hint that
 * names the fix. Nothing but the answer ever reaches stdout.
 */
import { parseArgs } from './args.js';
import { PlaneClient } from './client.js';
import { ensureAppUp } from './commands/orientation.js';
import { loadConfig, tildify, unsafeTargetReason } from './config.js';
import { fingerprint, loadMachineKey } from './credentials.js';
import { CliError, EXIT } from './errors.js';
import { catalogue, verbHelp } from './help.js';
import { initLogger, log, scrub } from './logger.js';
import { Spinner } from './progress.js';
import { LOCAL_VERBS, REGISTRY, assertUniquePaths } from './registry.js';
import type { Format } from './render.js';
import { note, out, render } from './render.js';
import type { Ctx, FlagValue } from './verbs.js';
import { verbName } from './verbs.js';

function reportError(err: unknown, jsonErrors: boolean, verbose: boolean): number {
  if (err instanceof CliError) {
    log.error(`${err.code ?? 'cli'} exit=${err.exit} ${err.message}`);
    if (jsonErrors) {
      process.stderr.write(
        JSON.stringify({ ok: false, exit: err.exit, error: { code: err.code ?? 'cli', message: err.message, hint: err.hint ?? null, details: err.serverDetails ?? null } }) + '\n',
      );
      return err.exit;
    }
    const lines = [`ffx: ${err.message}`, ...err.details.map((d) => `  ${d}`)];
    if (verbose && err.serverDetails !== undefined) lines.push(`  details: ${scrub(JSON.stringify(err.serverDetails))}`);
    if (err.hint) lines.push(`  fix: ${err.hint}`);
    process.stderr.write(lines.join('\n') + '\n');
    return err.exit;
  }
  const e = err as Error;
  log.error(`uncaught: ${e?.stack ?? String(err)}`);
  if (jsonErrors) {
    process.stderr.write(JSON.stringify({ ok: false, exit: 1, error: { code: 'internal', message: e?.message ?? String(err) } }) + '\n');
  } else {
    process.stderr.write(`ffx: internal error: ${e?.message ?? String(err)}\n  the stack is in ~/T/_firefly_iii/cli.err\n`);
  }
  return EXIT.FAILED;
}

export async function main(argv: readonly string[]): Promise<number> {
  const early = {
    jsonErrors: argv.includes('--json-errors'),
    verbose: argv.includes('--verbose'),
  };
  assertUniquePaths();

  let parsed;
  try {
    parsed = parseArgs(argv, REGISTRY);
  } catch (err) {
    return reportError(err, early.jsonErrors, early.verbose);
  }
  const { verb, positionals, flags, universal } = parsed;
  const cfg = loadConfig(process.env, universal.api);
  initLogger(cfg.stateDir);
  log.info(`verb="${verbName(verb)}" target=${cfg.apiUrl}`);

  if (universal.help) {
    out(verb.path.length === 0 ? catalogue(REGISTRY) : verbHelp(verb));
    return EXIT.OK;
  }

  const stdoutTTY = process.stdout.isTTY === true;
  const format: Format = universal.format ?? (stdoutTTY ? 'table' : 'json');
  const spinner = new Spinner({ quiet: universal.quiet, isTTY: process.stderr.isTTY === true });
  let client: PlaneClient | undefined;

  const ctx: Ctx = {
    verb,
    positionals,
    flags: flags as Record<string, FlagValue>,
    universal,
    cfg,
    format,
    spinner,
    stdoutTTY,
    stdinTTY: process.stdin.isTTY === true,
    registry: REGISTRY,
    note: (text) => note(text, universal.quiet),
    str: (name) => (typeof ctx.flags[name] === 'string' ? (ctx.flags[name] as string) : undefined),
    bool: (name) => ctx.flags[name] === true,
    list: (name) => {
      const v = ctx.flags[name];
      return Array.isArray(v) ? v : typeof v === 'string' ? [v] : [];
    },
    async plane() {
      if (client) return client;
      const reason = unsafeTargetReason(cfg.apiUrl);
      if (reason) throw new CliError(EXIT.USAGE, `refused to use ${cfg.apiUrl}: ${reason}`, { hint: 'use a loopback URL or https:' });
      await ensureAppUp(ctx);
      const key = loadMachineKey(cfg);
      const timeoutMs = universal.timeout ? parseInt(universal.timeout, 10) : 30_000;
      client = new PlaneClient(cfg, key, { timeoutMs, verbose: universal.verbose });
      if (universal.verbose) {
        note(`target ${cfg.apiUrl} · key ${fingerprint(key.key)} from ${key.file ? tildify(key.file, cfg.home) : key.source}`);
      }
      return client;
    },
  };

  if (verb.writes && universal.write && !LOCAL_VERBS.has(verbName(verb))) {
    // A write always names its target before it happens (§7.2) — printed by the write flow once connected.
    log.info(`write requested: ${verbName(verb)} token=${universal.token ? 'yes' : 'no'}`);
  }

  try {
    const outcome = await verb.run(ctx);
    spinner.stop();
    render(outcome, { format, quiet: universal.quiet });
    return outcome.exit ?? EXIT.OK;
  } catch (err) {
    spinner.stop();
    return reportError(err, universal.jsonErrors, universal.verbose);
  }
}
