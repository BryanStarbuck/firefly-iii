/**
 * const TOOLS — THE one array (pm/mcp.mdx §9.3, LOCKED).
 *
 * tools/list maps over it; dispatch finds in it. There is no second list, so
 * a tool that is listed is a tool that dispatches, and the reverse.
 */
import { ANALYTICS_TOOLS } from './analytics.js';
import { LEDGER_TOOLS } from './ledger.js';
import { STATEMENT_TOOLS } from './statements.js';
import type { ToolDef } from './tool.js';
import { WRITE_TOOLS } from './writes.js';

export const TOOLS: readonly ToolDef[] = Object.freeze([...LEDGER_TOOLS, ...ANALYTICS_TOOLS, ...STATEMENT_TOOLS, ...WRITE_TOOLS]);

const BY_NAME = new Map(TOOLS.map((t) => [t.name, t]));

export function findTool(name: string): ToolDef | undefined {
  return BY_NAME.get(name);
}

export const TOTAL_TOOLS = TOOLS.length;
export const READ_TOOLS = TOOLS.filter((t) => t.tier === 'read').length;
export const WRITE_TOOL_COUNT = TOOLS.filter((t) => t.tier === 'write').length;
