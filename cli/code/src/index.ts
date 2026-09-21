/**
 * The entry the `ffx` shim runs. Kept separate from main.ts so tests can
 * import main() without executing it.
 */
import { main } from './main.js';

process.exitCode = await main(process.argv.slice(2));
