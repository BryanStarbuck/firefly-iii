/**
 * Entry point — pm/mcp.mdx §6.3, §8, §14, §15.
 *
 *   dist/index.js serve
 *
 * Key, config and target are resolved BEFORE the transport attaches, so a
 * misconfiguration is one stderr line and exit 2 — a server absent from the
 * catalogue with a reason — rather than a server that connects and then fails
 * every call. stdout is the JSON-RPC wire and nothing in this file touches it.
 */
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';

import { PlaneClient } from './client.js';
import { ConfigError, credentialsConfig, loadConfig, mcpWording, SERVER_KEY } from './config.js';
import type { McpConfig } from './config.js';
import { CliError } from './credentials-support.js';
import { fingerprint, loadMachineKey } from './credentials.js';
import { resolveInstructions } from './instructions.js';
import { Logger } from './logger.js';
import { McpServerHost, SERVER_VERSION } from './server.js';

function refuse(message: string, fix: string): never {
  // One line, stderr. stdout is the wire (§8.1).
  process.stderr.write(`${SERVER_KEY}: ${message} — fix: ${fix}\n`);
  process.exit(2);
}

export class Main {
  static async run(argv: string[]): Promise<void> {
    if (argv[0] !== 'serve') {
      process.stderr.write(
        `usage: ${SERVER_KEY} serve\n` +
          '  register it with:\n' +
          `    claude mcp add --scope user ${SERVER_KEY} -- "$HOME/BGit/Bryan_git/firefly-iii/mcp/dist/index.js" serve\n` +
          '  (the bare -- is load-bearing: everything after it is the command and its argv)\n',
      );
      process.exit(2);
    }

    // Gate 3 — the target — and every FFMCP_* value, frozen.
    let config: McpConfig;
    try {
      config = loadConfig();
    } catch (err) {
      if (err instanceof ConfigError) refuse(err.message, err.fix);
      throw err;
    }

    // Gate 2 — the key. Fail closed, harder than the CLI: no key, a loose file,
    // a symlink or another owner, and the server does not start (§6.3).
    let key: string;
    try {
      key = loadMachineKey(credentialsConfig(config)).key;
    } catch (err) {
      if (err instanceof CliError) {
        const fix = err.hint ?? 'start Firefly III once (`ffx up`) so the web app mints the key, or run `ffx key init`';
        refuse(mcpWording(err.message), mcpWording(fix));
      }
      throw err;
    }
    const keyFingerprint = fingerprint(key);

    const logger = new Logger({ dir: config.logDir, level: config.logLevel });
    const instructions = resolveInstructions(config.promptFile, (m) => logger.warn(m));
    const host = new McpServerHost({
      config,
      transport: new PlaneClient(config, key, keyFingerprint),
      logger,
      keyFingerprint,
      instructions,
    });

    const writes = config.writeForcedOffByRemote
      ? 'off (remote target is read-only)'
      : config.allowWrite
        ? 'ENABLED here (the plane needs FIREFLY_MACHINE_ALLOW_WRITE=1 too)'
        : 'off (FFMCP_ALLOW_WRITE=0)';
    logger.banner(`v${SERVER_VERSION} -> ${config.apiUrl} (${config.target}) key ${keyFingerprint} writes ${writes}`);

    const transport = new StdioServerTransport();
    await host.server.connect(transport);

    const shutdown = (signal: string): void => {
      logger.info(`shutdown signal=${signal}`);
      void host.server.close().finally(() => process.exit(0));
    };
    process.on('SIGINT', () => shutdown('SIGINT'));
    process.on('SIGTERM', () => shutdown('SIGTERM'));
    process.stdin.on('end', () => shutdown('stdin-closed'));
    // An unhandled rejection must not take the server down mid-conversation, nor reach stdout.
    process.on('unhandledRejection', (reason) => logger.error(`unhandledRejection: ${String(reason)}`));
  }
}

await Main.run(process.argv.slice(2));
