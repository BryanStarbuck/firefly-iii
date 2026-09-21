/**
 * The error file's node sink for the MCP server — pm/error_err.mdx §5.6, net N15.
 *
 * A side-effect module, imported as the FIRST import of index.ts: ESM evaluates imports in order,
 * depth first, so the process nets exist before any other module of the server evaluates, which
 * covers a fault thrown at import time. Echo is always off (stdout is the JSON-RPC wire and the
 * refusal tests assert exactly one stderr line); an unhandled rejection does not take the server
 * down mid-conversation.
 */
import { installNodeErrorFile } from './vendor/error-file/node.js';
installNodeErrorFile({ app: 'mcp', where: 'mcp/src/index.ts', echo: false, crashOnUnhandledRejection: false });
