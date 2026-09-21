/**
 * FFMCP_PROMPT_FILE — the development seam of pm/mcp.mdx §8.3: read the
 * prompt at startup instead of the generated constant, with the same
 * substitution. Unreadable, empty, or carrying an unknown token → one stderr
 * warning and the generated constant. Never an empty instructions block.
 */
import fs from 'node:fs';

import { renderPrompt } from './prompt-tokens.js';
import { errorFileFor } from './vendor/error-file/index.js';

const errors = errorFileFor('mcp/src/prompt.ts');

export function resolvePrompt(generated: string, promptFile: string | undefined, warn: (message: string) => void): string {
  if (!promptFile) return generated;
  let source: string;
  try {
    source = fs.readFileSync(promptFile, 'utf8');
  } catch (err) {
    // A development seam: an unreadable file is answered by the stderr warning and the built-in text.
    errors.expected('reading the prompt file', err);
    warn(`FFMCP_PROMPT_FILE unreadable (${(err as NodeJS.ErrnoException).code ?? 'error'}); using the built-in instructions`);
    return generated;
  }
  const { text, unknown } = renderPrompt(source);
  if (unknown.length) {
    warn(`FFMCP_PROMPT_FILE has unknown token(s) ${unknown.map((u) => `{${u}}`).join(', ')}; using the built-in instructions`);
    return generated;
  }
  if (!text.trim()) {
    warn('FFMCP_PROMPT_FILE is empty; using the built-in instructions');
    return generated;
  }
  return text;
}
