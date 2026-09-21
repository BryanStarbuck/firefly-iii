/**
 * The {VARIABLE} map for ai/mcp_prompt_firefly.md — pm/mcp.mdx §3.4, §8.3.
 *
 * Shared by the build-time generator (scripts/build-instructions.ts, which
 * imports this file directly) and the FFMCP_PROMPT_FILE dev override
 * (prompt.ts), so both substitute identically. Pure data and one pure
 * function: no imports, so the generator can load it without a build.
 *
 * An unknown token FAILS the build: a prompt that says "{TOOL_PERFIX}" to a
 * model is a prompt that has silently lost a sentence.
 */
export const PROMPT_TOKENS: Readonly<Record<string, string>> = Object.freeze({
  SERVER_KEY: 'firefly_iii',
  TOOL_PREFIX: 'ff_',
  TOTAL_TOOLS: '79',
  READ_TOOLS: '60',
  WRITE_TOOLS: '19',
  CLI_BINARY: 'ffx',
  API_URL: 'http://127.0.0.1:7373',
  CREDENTIALS_FILE: '~/.credentials/firefly_iii.json',
});

export interface Rendered {
  text: string;
  unknown: string[];
}

/** Substitute every {TOKEN}; report the ones the map does not know. */
export function renderPrompt(source: string, tokens: Readonly<Record<string, string>> = PROMPT_TOKENS): Rendered {
  const unknown: string[] = [];
  const text = source.replace(/\{([A-Z][A-Z0-9_]*)\}/g, (whole: string, name: string) => {
    const value = tokens[name];
    if (value === undefined) {
      unknown.push(name);
      return whole;
    }
    return value;
  });
  return { text: text.replace(/\s+$/, '') + '\n', unknown };
}
