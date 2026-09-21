# ffx — the operator CLI for this Firefly III install

A thin client of the loopback-only machine plane (`/machine/v1`) inside this Firefly III fork. It
parses arguments, brings the app up, calls one route, and renders the answer. It never computes a
number about money and never turns an amount string into a JavaScript number.

* Spec: [`../pm/cli.mdx`](../pm/cli.mdx) — the contract for this CLI.
* Wire contract: [`../pm/apis.mdx`](../pm/apis.mdx) — every route the verbs call.
* Sibling: [`../pm/mcp.mdx`](../pm/mcp.mdx) — the MCP server, the same key and the same routes.

## Install

```bash
cd cli && npm install        # TypeScript + @types/node only; there are no runtime dependencies
just build                   # or: the shim self-builds on first run
export PATH="$HOME/BGit/Bryan_git/firefly-iii/cli:$PATH"   # once, in ~/.zshrc
```

## Use

```bash
ffx                                   # what is running, and what needs doing
ffx doctor                            # is the environment sane?
ffx up                                # start Firefly III on http://127.0.0.1:7373/ (detached)
ffx accounts list
ffx transactions list --without-category --month last-month
ffx budget period                     # "—" means NO limit, which is not a limit of zero
ffx spending --by category --start ytd --interval month
ffx budget set Groceries --amount "650.00" --month 2026-10            # a dry run: prints a token
ffx budget set Groceries --amount "650.00" --month 2026-10 --write --token cf_…
ffx help <verb>
```

Output is a table on a terminal and the plane's JSON envelope when piped (`--format json|table|csv`
to choose). Only the answer goes to stdout; progress, hints and provenance go to stderr.

## The key

The Firefly web app mints a 256-bit machine key into `~/.credentials/firefly_iii.json` (mode 0600)
on its first run and reuses it after that. `ffx` reads it and sends it as `X-Firefly-Machine-Key`,
only to loopback or `https:`. `ffx key show` prints its fingerprint, never the key.

## Layout

```
cli/
├── ffx            POSIX shim (the callable)
├── ffx.mjs        self-building entry: compiles code/ when dist/ is stale
├── justfile       build | test | run | typecheck
└── code/
    ├── src/       main, args, client (the only socket), credentials, bringup, render (the only stdout writer),
    │              money (display only), dates, progress, logger, help, registry, commands/*
    └── test/      unit, CLI-against-a-fake-plane, and canary suites (node:test)
```

## Test

```bash
just test      # builds, then runs the unit tests, the fake-plane integration tests and the canaries
```

The canaries grep the built output for `console.log`, stdout writes outside `render.js`,
`parseFloat` / `Number(` / `toFixed`, sockets outside `client.js`, `child_process` outside
`bringup.js`, key-shaped literals, and private paths.

## Exit codes

`0` ok · `1` ran and failed · `2` usage or local refusal · `3` not found · `4` conflict ·
`5` app down / plane not mounted · `6` key refused by a reachable app · `69` not built.
