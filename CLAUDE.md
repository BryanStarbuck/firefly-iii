@agents.md

~/BGit/Bryan_git/firefly-iii/
This directory above is our web app (our fork of Firefly III). It's going to be made open source, so we never put private data in this directory or anywhere under there.

Over here is where we read in the bank statements. Sometimes they start off as PDFs.
~/BGit/Bryan_git/Bryan_Arindom/bank_statements/

The directory below is a staging area that you can go process and create bank statements inside of. You can read the PDFs in the hierarchy for the parent bank_statements from Recursive. Find bank statements. You might find them in PDF or other formats. You might well create them in whatever kind of import format Firefly III (and its Data Importer) or other software likes to use, and create them in the CSVs and their import formats and things like that.

We can make sure we don't prevent dupes. The bank statements might have dupes: multiple PDFs of the same month. We can make sure the directory imports below remove any dupes, so the data is unified and not duplicated.
~/BGit/Bryan_git/Bryan_Arindom/bank_statements/import/

How the Firefly pipeline uses those two directories (pm/apis.mdx §11, pm/cli.mdx §10): `import/` holds the
archive's own prepared, de-duplicated import files and its manifest (`import/accounts.csv`), which the
pipeline READS. Firefly's pipeline WRITES its own working files only to a separate directory, so it can never
overwrite the archive's files:
~/BGit/Bryan_git/Bryan_Arindom/bank_statements/.firefly-staging/
(The sister Actual Budget fork writes to `.actual-staging/`; the two never share a directory.) The statements
root is configuration (`firefly_iii.statements.root` in ~/.credentials/firefly_iii.json), never a constant in code.

The directory below is for part of the management specification files on how everything is going to work.
~/BGit/Bryan_git/firefly-iii/pm/
apis.mdx
cli.mdx
mcp.mdx

The directory below is where the source code goes for a CLI (command-line interface), where we can interface with this web app by using the CLI.
~/BGit/Bryan_git/firefly-iii/cli/

Below is an MCP you can use. We'll have that as a node TypeScript MCP to work with Claude Code, so that way, from Claude Code, we can interact with that.
~/BGit/Bryan_git/firefly-iii/mcp/

This is the prompt file our MCP server should use to know about our APIs and how to interact with the user:
~/BGit/Bryan_git/firefly-iii/ai/mcp_prompt_firefly.md



## Private Data Boundary: Never Leak Into the Open Source Repo

These two directory hierarchies, and everything under them recursively, hold Bryan's very private personal and financial data:
* ~/BGit/Bryan_git/Bryan_Arindom/bank_statements/
* ~/BGit/Bryan_git/Bryan_Arindom/bank_statements/import/

The directory below is an open source project that we own and publish:
* ~/BGit/Bryan_git/firefly-iii/

HARD REQUIREMENT: Bryan's personal data must never end up anywhere in the ~/BGit/Bryan_git/firefly-iii/ hierarchy. No exceptions.
* Never copy, move, symlink, or write any file from the private directories above into firefly-iii/.
* Never put real data in code, tests, fixtures, sample files, docs, logs, commit messages, or comments there. That includes account numbers, balances, transactions, payees, statement text, names, and addresses.
* When firefly-iii/ needs example data, make up synthetic data. Never derive it from the real statements.
* Scripts in firefly-iii/ may read private files at runtime through a path the user supplies, but they must write their output outside that repo (for example, into bank_statements/import/), never inside it.
* Before committing anything in firefly-iii/, check the staged diff for private data. If anything looks real, stop and ask Bryan.
* Never have Chase statements or Fidelity statements or financial data get into the git repo. But they will get into the database running on localhost that never gets into the git repo.
* The data will get in. It is okay for the data to get into the database when we're on localhost, just not into the git repo or the directory hierarchy, because it may accidentally get into the public repo.


## What this fork adds beyond the upstream open source baseline

* Fork: https://github.com/BryanStarbuck/firefly-iii.git (`origin`, branch `main`)
* Upstream: https://github.com/firefly-iii/firefly-iii (their integration branch is `develop`)
* License: GNU AGPL v3, for upstream's code and for everything we add.
* Stack: PHP 8.5 + Laravel 13, Laravel Passport for upstream's OAuth API, bcmath money
  (`bcscale(12)` in `bootstrap/app.php`), Blade + Vue/Alpine front end under `resources/`.

Upstream Firefly III is a complete web app with a large REST API (`/api/v1`, about 250 routes, in
`routes/api.php`) built for OAuth clients: the mobile apps, the separate Data Importer app,
integrations. We run it for Bryan's own money on this machine, driven from the terminal and from
Claude Code, so this fork grows:

1. **A machine-plane API** — `/machine/v1`, loopback-only, mounted inside the same Laravel app and
   calling Firefly's own repositories, factories and rule engine. Every write has a dry run (the real
   write inside a rolled-back DB transaction), a confirm token, a ceiling, and an undo log.
   Spec: `pm/apis.mdx`. Code: `app/Machine/` (`MachinePlaneServiceProvider`, `Http/Middleware/` = the
   gate ladder, `Http/Controllers/` one per family, `Routes/` one `RouteFamily` per family feeding
   `RouteTable` — the ONE array behind both the router and `/capabilities`, `Ingest/`, `Analytics/`,
   `Undo/`), `routes/machine.php`, `config/machine.php`, `tests/Machine/`. The only upstream file
   touched is `bootstrap/providers.php` (one line).
2. **A CLI, `ffx`** — a thin Node + TypeScript client of that API. Spec: `pm/cli.mdx`.
   Code: `cli/` (shim `cli/ffx`, implementation under `cli/code/`).
3. **An MCP server, `firefly_iii`** (every tool prefixed `ff_`) — a thin Node + TypeScript client of
   the same API for Claude Code. Spec: `pm/mcp.mdx`. Code: `mcp/`. Its instructions come from
   `ai/mcp_prompt_firefly.md`.
4. **A bank-statement import pipeline** — the statements archive into Firefly with two layers of
   de-duplication, where Firefly's own `import_hash_v2` duplicate check is the authority.
   Spec: `pm/apis.mdx` §11, `pm/cli.mdx` §10.
5. **More analytics, and eventually more charting**, than upstream ships. Every total and every chart
   series is a machine-plane route first (`pm/apis.mdx` §10), so the browser, the CLI and the MCP can
   never disagree about a number.

Upstream's `/api/v1`, web UI and Passport auth stay **untouched**. The upstream delta for the plane is
meant to be one line in `bootstrap/providers.php` plus new files (`app/Machine/`, `routes/machine.php`,
one migration). Keep it that way so upstream merges stay trivial.

Where specs disagree: `pm/apis.mdx` is the contract and wins on anything about the wire;
`pm/cli.mdx` and `pm/mcp.mdx` win on their own surfaces. `pm/` holds specs only, never code.

Upstream code worth knowing when working on the plane:
* `routes/api.php` and `app/Api/V1/Controllers/` — upstream's REST API
* `app/Factory/TransactionJournalFactory.php` — `hashArray()` / `errorIfDuplicate()`: the duplicate
  authority. It uses `withTrashed()`, so a transaction deleted in the UI stays deleted on re-import.
* `app/Http/Controllers/Account/ReconcileController.php` — reconciliation (web-only upstream)
* `app/Support/Search/OperatorQuerySearch.php`, `config/search.php` — Firefly's search language
* `bootstrap/app.php` — global middleware (TrustProxies, HandleCors), the `api` group, `/up` health


## Running it locally

* One process serves everything at **http://127.0.0.1:7373/**: the web UI, `/api/v1`, `/up` (health)
  and `/machine/v1`.
* Runtime: `php artisan serve --host=127.0.0.1 --port=7373` with `PHP_CLI_SERVER_WORKERS=4`. Needs
  PHP 8.5 and Composer on the host (`brew install php composer`). Docker is not supported for the
  machine plane: requests arrive from the bridge IP, so the loopback gate fails closed.
* Root `justfile` (ours; upstream has none): `just setup`, `just build`, `just run`, `just dev`, `just url`,
  `just open`, `just stop`, `just status`, `just doctor`, `just logs`, `just test`, `just mcp-register`.
  * `just build` compiles the CLI and the MCP server AND registers the MCP server with Claude Code
    (user scope, read-only, idempotent). `just mcp-register 1` re-registers it with writes on.
  * `just run` builds, starts the web app DETACHED via `ffx up` (the terminal comes back), and prints
    the URL block: `http://127.0.0.1:7373/`, `/up`, `/machine/v1`, MCP registration state, log path.
    `just dev` is the foreground server (Ctrl-C stops it).
  * Ports across the sister forks never collide: Actual Budget 3001 (+5006 sync), ezBookkeeping 8080,
    Firefly III 7373.
* `just setup` also builds the web UI's Vite assets (`public/build/` is git-ignored upstream; without it
  every page 500s) and mints the machine key.
* Tests: `just test` runs the CLI suite (`cli/`), the plane suite (`php vendor/bin/phpunit -c
  phpunit.machine.xml` — our config: only `tests/Machine`, no coverage block, 2G memory, does not stop on
  the first failure) and the MCP suite (`mcp/`). Upstream's `phpunit.xml` is left as is.
* Register the MCP server in Claude Code:
  `claude mcp add --scope user firefly_iii -- "$HOME/BGit/Bryan_git/firefly-iii/mcp/dist/index.js" serve`
  (add `"env": {"FFMCP_ALLOW_WRITE": "1"}` to let it write; the app's `.env` needs
  `FIREFLY_MACHINE_ALLOW_WRITE=1` too).
* State and logs: `~/T/_firefly_iii/` — `server.log`, `server.pid`, `cli.info`, `cli.err`,
  `mcp.info`, `mcp.err`, `machine.audit`. Laravel's own log stays at `storage/logs/laravel.log`.
* Database: SQLite **outside the repo** at `~/T/_firefly_iii/db/firefly.sqlite`, set as `DB_DATABASE`
  in the git-ignored `.env`. Never use upstream's default `storage/database/database.sqlite`, which is
  inside this public repo's tree.


## The API secret key (machine key)

* File: `~/.credentials/firefly_iii.json`, mode 0600, unique to this app. The key is at
  `firefly_iii.machine.api_key`; the statements root is at `firefly_iii.statements.root`.
* 32 bytes from a CSPRNG (`random_bytes` in PHP, `crypto.randomBytes` in Node), written as 64 hex
  characters, which is longer than a UUID.
* **The web app creates it automatically on its first run if it does not exist, and reuses the
  existing one otherwise.** The CLI and MCP read the same file and send it as the
  `X-Firefly-Machine-Key` header on every `/machine/v1` call. Nothing to type, nothing in the repo.
* The plane only answers on loopback, only with that key, and never logs it (fingerprint only).
* Write switches, in the app's `.env`: `FIREFLY_MACHINE_ALLOW_WRITE=1` and `FIREFLY_MACHINE_ALLOW_ADMIN=1`.
  Which user and which set of books the plane acts as: `FIREFLY_MACHINE_OPERATOR` (email) and
  `FIREFLY_MACHINE_ADMINISTRATION` (id). Client switches: `ffx --write`, `FFMCP_ALLOW_WRITE=1`.
* `~/.credentials/firefly_iii.json` and `~/T/_firefly_iii/` are private in the same way as the bank
  statements above: never copy anything from them into this repo.


## Upstream's conventions

* `agents.md` (imported at the top of this file) is upstream's request for AI-assisted contributions
  **sent upstream**: an `Assisted-by:` commit footer and a marker in PR titles. It applies to PRs to
  `firefly-iii/firefly-iii`.
* Upstream code style lives in `.ci/` (php-cs-fixer, phpstan, phpmd, rector). Every PHP file starts
  with the AGPL header and `declare(strict_types=1);`.
* PHP tests: `phpunit.xml`, `tests/`. The machine plane's tests go in `tests/Machine/`.


## Sister apps

The same machine-plane + CLI + MCP design exists, or is being built, in the sibling finance forks.
Learn from them and keep them consistent:
* `~/BGit/Bryan_git/actual_budget_bryan/` — Actual Budget fork (`abx` CLI, `actual_budget` MCP, specs
  in `pm/`, prompt in `ai/mcp_prompt.md`). The reference implementation of this design.
* `~/BGit/Bryan_git/ezbookkeeping/` — ezBookkeeping fork, same plan.
