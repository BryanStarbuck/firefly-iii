/**
 * The entry the `ffx` shim runs. Kept separate from main.ts so tests can
 * import main() without executing it.
 *
 * The first import installs the error file (pm/error_err.mdx §5.6, N11). The
 * catch is N12: a rejected top-level await is not a reliable
 * unhandledRejection, and loadConfig(), initLogger() and --help run outside
 * main()'s try blocks.
 */
import './error-file-install.js';
import { errorFileFor } from './vendor/error-file/index.js';
import { main } from './main.js';

const errors = errorFileFor('cli/code/src/index.ts', { net: 'top' });

/**
 * The runtime canary C13 (pm/error_err.mdx §13.4): `ffx __canary throw|reject` makes the process
 * net (N11) fire once. Not a verb: not in the registry, the help or the docs. It refuses to act —
 * and falls through to main(), which answers an unknown verb as usual — unless BOTH
 * FIREFLY_ERROR_FILE_CANARY=1 and a non-empty FIREFLY_ERROR_FILE are set, so it can never write the
 * real file.
 */
function runCanary(argv: string[]): boolean {
  if (argv[0] !== '__canary' || process.env.FIREFLY_ERROR_FILE_CANARY !== '1' || !process.env.FIREFLY_ERROR_FILE) {
    return false;
  }
  if (argv[1] === 'throw') {
    // an uncaught exception outside every try: uncaughtExceptionMonitor writes the FATAL, then Node crashes
    setImmediate(() => {
      throw new Error('Synthetic canary uncaught exception');
    });
    return true;
  }
  if (argv[1] === 'reject') {
    // an unhandled rejection: the N11 listener writes the FATAL and keeps Node's crash
    void Promise.reject(new Error('Synthetic canary unhandled rejection'));
    return true;
  }
  process.stderr.write('ffx: __canary takes throw or reject\n');
  process.exitCode = 2;
  return true;
}

if (!runCanary(process.argv.slice(2))) {
  process.exitCode = await main(process.argv.slice(2)).catch((err: unknown) => {
    errors.fatal('starting ffx', err);
    process.stderr.write(`ffx: internal error: ${(err as Error)?.message ?? String(err)}\n  The detail is in ~/T/firefly/error.err — ffx logs --errors\n`);
    return 1;
  });
}
