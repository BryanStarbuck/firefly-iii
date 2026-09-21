# firefly_iii — the MCP server for this install of Firefly III

The spec is [`../pm/mcp.mdx`](../pm/mcp.mdx). The API it calls is [`../pm/apis.mdx`](../pm/apis.mdx). The
instructions it sends at `initialize` come from [`../ai/mcp_prompt_firefly.md`](../ai/mcp_prompt_firefly.md).

This is a stdio MCP server that exposes the operator's own Firefly III books to Claude Code as
**77 typed tools**, all named `ff_*`. **59 of them read** and **18 write**. It computes nothing.
Each tool validates its arguments, makes one authenticated call to the machine plane at
`http://127.0.0.1:7373/machine/v1/*`, and returns Firefly's answer inside a fixed envelope.

## Register it

```bash
cd ~/BGit/Bryan_git/firefly-iii/mcp && just build
claude mcp add --scope user firefly_iii -- "$HOME/BGit/Bryan_git/firefly-iii/mcp/dist/index.js" serve
```

The bare `--` matters. Everything after it is the command and its arguments. Run the command in a
shell so that `$HOME` expands before `claude mcp add` sees it. The registration must hold a literal
absolute path: when a bare name does not resolve, the server fails silently and its tools are simply
missing.

The registration block contains **no secret**. The key comes from `~/.credentials/firefly_iii.json`
(mode `0600`), which the Firefly web app creates on its first run. The server refuses to start if
that key is missing. It also refuses when the file is readable by others, is a symlink, or belongs
to another user. In each case it prints one line on stderr and exits with code 2.

## Writes are off by default

A write needs all of the following:

1. The plane's write tier: `FIREFLY_MACHINE_ALLOW_WRITE=1` in the Firefly `.env`, then `ffx stop && ffx up`.
2. This server's write tier: `"env": { "FFMCP_ALLOW_WRITE": "1" }` in the registration.
3. A dry run first. Sixteen of the write tools default to `dry_run: true`. The dry run returns a
   `confirm_token`.
4. The apply call itself: `dry_run: false` plus `confirm: <that token>`. The token lasts ten
   minutes, can be used once, and is tied to the exact change set.

Every write also runs under `max_changes` (default 200). A write over that ceiling is refused and
the refusal reports the real count. `ff_undo` and `ff_trigger_recurrence` have no dry run.
`ff_undo` takes the token from `ff_preview_undo`. There is no delete tool of anything.

## Build, test, probe

| `just …`           | does                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------ |
| `build`            | generates `src/instructions.ts` from the prompt (and fails on a missing prompt or an unknown `{TOKEN}`), runs `tsc`, then adds the shebang to `dist/index.js` and makes it executable |
| `test`             | build, then every test (catalogue, gates, money, routing, envelope, audit, canaries on `dist/`, process probes) |
| `probe`            | pipes a hand-written `initialize` into the built server, using a throwaway fake key   |
| `sync-credentials` | copies the body of the CLI's `credentials.ts` again. The file is duplicated on purpose, and a test fails if the two drift apart |
| `register`         | prints the `claude mcp add` line                                                     |

## Configuration (all `FFMCP_*`, read once at startup)

| Variable                 | Default                           | Meaning                                                  |
| ------------------------ | --------------------------------- | -------------------------------------------------------- |
| `FFMCP_API_URL`          | `http://127.0.0.1:7373`           | the plane. A non-loopback URL needs `FFMCP_ALLOW_REMOTE=1` and `https:`, and is read-only |
| `FFMCP_MACHINE_KEY`      | —                                 | the key inline (never in a checked-in `.mcp.json`)       |
| `FFMCP_MACHINE_KEY_FILE` | —                                 | a file holding only the key                              |
| `FFMCP_CREDENTIALS_FILE` | `~/.credentials/firefly_iii.json` | the credentials file                                     |
| `FFMCP_ALLOW_WRITE`      | `0`                               | this side's write switch                                 |
| `FFMCP_ALLOW_REMOTE`     | `0`                               | permit a remote `https:` target (read-only)              |
| `FFMCP_MAX_CHANGES`      | `200`                             | the default write ceiling                                |
| `FFMCP_MAX_ROWS`         | `1000`                            | the row cap. A `limit` over it is clamped, never rejected, and the clamp is reported |
| `FFMCP_MAX_BYTES`        | `1048576`                         | the response cap. Over it, the longest list is cut and `truncated: true` is set |
| `FFMCP_TIMEOUT_MS`       | `30000`                           | per call. Ingest apply and extract are exempt            |
| `FFMCP_PROMPT_FILE`      | —                                 | dev override for the instructions prose (same substitution) |
| `FFMCP_LOG_LEVEL`        | `info`                            | `error` · `warn` · `info` · `debug`                      |
| `FFMCP_LOG_DIR`          | `~/T/_firefly_iii`                | where `mcp.info` and `mcp.err` go (mode `0600`, rotated at 8 MB) |
| `FIREFLY_ERROR_FILE`     | `~/T/firefly/error.err`           | every fault from every runtime (pm/error_err.mdx); one variable shared with PHP and `ffx`, no `FFMCP_` twin |
| `FIREFLY_ERROR_FILE_VERBOSE` | `0` | `1` also writes EXPECTED records to the error file (pm/error_err.mdx §4.11); shared with PHP and `ffx` |
| `FIREFLY_ERROR_FILE_CANARY`  | `0` | `1` enables the `__canary` branches; refused unless `FIREFLY_ERROR_FILE` is non-empty (pm/error_err.mdx §4.11) |
| `FIREFLY_ERROR_FILE_ECHO`    | —   | ignored by `mcp`; in PHP and `ffx`, `1` echoes each written record to stderr |

## What an operator asks, and the tools that answer

| The operator says                                  | The model calls                                                                            |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| "How much did I spend on groceries in Q2?"          | `ff_list_categories` → `ff_spending_by_category` (one figure per currency; the model never adds them up) |
| "Did I budget for groceries in September?"          | `ff_get_budget_period`. `limit: null` means no limit was set, which is different from a limit of zero |
| "What haven't I categorised?"                       | `ff_get_uncategorized_summary` → `ff_list_uncategorized`                                    |
| "Where is my money going?" / "Who got paid most?"   | `ff_spending_by_category`, `ff_payee_leaderboard`, `ff_income_vs_expense`                   |
| "What's my net worth? Show it as a chart"           | `ff_net_worth`; `ff_get_chart` `name: "net-worth"` → an artifact draws the series           |
| "How long could I last?"                            | `ff_get_runway` (it names its basis)                                                        |
| "What subscriptions haven't I told you about?"      | `ff_list_recurring` (with its evidence) → `ff_list_subscriptions`                            |
| "Build next month's budget from the last six"       | `ff_get_budget_period` → `ff_set_budget_to_average` dry run → operator says yes → apply     |
| "Every Whole Foods row is Groceries"                | `ff_preview_rule` → stop → `ff_add_rule` → `ff_run_rules` dry run → apply                    |
| "Load my ten years of statements"                   | `ff_get_statement_manifest` → `ff_plan_accounts` → stop (ambiguous rows, liabilities) → `ff_apply_accounts` → `ff_plan_statement_import` → stop → `ff_apply_statement_import` |
| "Import this one OFX file into checking"            | `ff_plan_file_import` → `ff_apply_file_import`                                              |
| "Which months am I missing? Which scans are dupes?" | `ff_list_missing_statements`, `ff_list_statement_duplicates`, `ff_scan_statements`          |
| "Reconcile checking to the statement's 4,211.08"    | `ff_plan_reconcile` → find the difference with the operator → `ff_apply_reconcile`           |
| "Add yesterday's coffee"                            | `ff_add_transaction` dry run → apply (Firefly's rules and duplicate check run)              |
| "That was a transfer, not a purchase"               | `ff_convert_transaction`                                                                     |
| "Put $200 in the vacation piggy bank"               | `ff_get_piggy_progress` → `ff_move_piggy_bank_money` `direction: "add"`                       |
| "Undo that"                                          | `ff_preview_undo` → `ff_undo`, and the model reports what was undone                         |
| "Delete that transaction" / "merge the duplicates"  | no tool, by design. The model says where to do it in the Firefly UI                           |

## Layout

```
mcp/
├── scripts/build-instructions.ts   ../ai/mcp_prompt_firefly.md → src/instructions.ts ({VARIABLE} map in src/prompt-tokens.ts)
├── scripts/stamp.ts                shebang + chmod +x on dist/index.js
├── scripts/sync-credentials.ts     re-copies the CLI's credentials module body
├── src/index.ts                    Main.run(): config, key, banner (stderr), StdioServerTransport
├── src/server.ts                   McpServerHost: handleListTools(), handleCallTool(), the envelope
├── src/gates.ts                    wrong_server, the mode gate, input, confirm echo, ceiling
├── src/wire.ts                     arguments → one request (names sent as *_name for the plane to resolve)
├── src/client.ts                   the ONE socket module: node:http, loopback-or-https, no Origin/Sec-Fetch
├── src/credentials.ts              the CLI's module, byte for byte below the imports
├── src/logger.ts, src/audit.ts     mcp.info / mcp.err; hashed-argument audit line, no amounts
├── src/vendor/error-file/          the error-file library, copied from errorfile/src by scripts/sync-error-file.mjs
│                                   (never edited here); faults go to ~/T/firefly/error.err as [mcp]
├── src/tools/*.ts                  const TOOLS — the one array (tools/list and dispatch)
├── src/canary/*.canary.ts          one per name in the §7.0 Canary column
└── test/*.test.ts                  node:test against a fake plane (node:http) and the built dist/
```
