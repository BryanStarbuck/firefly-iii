/**
 * The canaries — one module per name in pm/mcp.mdx §7.0's "Canary" column,
 * each a cheap, always-run check that a control is still in place. They are
 * TEST-ONLY modules: nothing on the server's import graph reaches this
 * directory (the no-shell canary asserts it), so the static canaries that grep
 * dist/ skip dist/canary/ — the patterns they search for live here.
 */
import fs from 'node:fs';
import path from 'node:path';

import type { ToolDef } from '../tools/tool.js';

export interface BuiltFile {
  /** Path relative to dist/, with forward slashes. */
  name: string;
  /** The file's code with comments stripped. */
  code: string;
}

export interface CanaryContext {
  distDir: string;
  tools: readonly ToolDef[];
}

export interface Canary {
  name: string;
  threats: string[];
  summary: string;
  /** Returns the problems found; empty means the control holds. */
  check(ctx: CanaryContext): string[];
}

/** Strip comments so documentation that NAMES a banned call does not trip a canary. */
export function stripComments(text: string): string {
  return text.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:"'`\\])\/\/.*$/gm, '$1');
}

/** Every built .js file outside dist/canary/, comments stripped. */
export function builtFiles(distDir: string): BuiltFile[] {
  const out: BuiltFile[] = [];
  const walk = (dir: string): void => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
      const full = path.join(dir, e.name);
      if (e.isDirectory()) {
        if (e.name !== 'canary') walk(full);
      } else if (e.name.endsWith('.js')) {
        out.push({ name: path.relative(distDir, full).split(path.sep).join('/'), code: stripComments(fs.readFileSync(full, 'utf8')) });
      }
    }
  };
  walk(distDir);
  return out;
}
