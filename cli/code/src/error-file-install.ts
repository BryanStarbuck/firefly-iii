/**
 * The error file's node sink for ffx — pm/error_err.mdx §5.6, net N11.
 *
 * A side-effect module, imported as the FIRST import of index.ts: ESM evaluates imports in order,
 * depth first, so the process nets exist before any other module of the CLI evaluates, which covers
 * a fault thrown at import time. Echo is off (ffx already prints the error on stderr) unless
 * FIREFLY_ERROR_FILE_ECHO=1; an unhandled rejection keeps Node's crash.
 */
import { installNodeErrorFile } from './vendor/error-file/node.js';
installNodeErrorFile({ app: 'ffx', where: 'cli/code/src/index.ts' });
