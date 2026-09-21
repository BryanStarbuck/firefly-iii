# Firefly III — this fork's local lifecycle (pm/cli.mdx §2.3, pm/apis.mdx §3.3).
# Upstream ships no justfile; this one is ours. Run `just` to list recipes.
#
#   One process serves everything on http://127.0.0.1:7373/ :
#   the web UI, upstream's /api/v1, Laravel's /up health route, and /machine/v1.
#   Sister forks use other ports so all three can run at once:
#   Actual Budget 3001 (+ sync server 5006), ezBookkeeping 8080, Firefly III 7373.
#
#   `just build`  compiles the CLI + MCP server and registers the MCP server with Claude Code.
#   `just run`    builds, starts the web app detached (terminal returns), prints the URL.
#   `just dev`    foreground server (Ctrl-C stops).   `just stop` / `just status` / `just logs` / `just errors`.
#
#   Private data never lives in this repo: the SQLite database, the logs and the
#   pid file live under ~/T/_firefly_iii/, the machine key in
#   ~/.credentials/firefly_iii.json (minted by the web app on its first run),
#   and the error file is ~/T/firefly/error.err (pm/error_err.mdx).

set shell := ["bash", "-euo", "pipefail", "-c"]

root  := justfile_directory()
state := env_var("HOME") + "/T/_firefly_iii"
host  := "127.0.0.1"
port  := "7373"
mcp_name := "firefly_iii"

default:
    @just --list

# First-time setup: Composer deps, a .env whose SQLite database lives OUTSIDE the repo, migrations, the machine key, the CLI toolchain.
setup:
    #!/usr/bin/env bash
    set -euo pipefail
    cd "{{root}}"
    command -v php >/dev/null      || { echo "php is not installed — brew install php" >&2; exit 1; }
    command -v composer >/dev/null || { echo "composer is not installed — brew install composer" >&2; exit 1; }
    php -r 'exit(version_compare(PHP_VERSION, "8.5.0", ">=") ? 0 : 1);' || { echo "Firefly III needs PHP 8.5+ (have $(php -r 'echo PHP_VERSION;'))" >&2; exit 1; }
    mkdir -p "{{state}}/db" && chmod 700 "{{state}}" "{{state}}/db"
    db="{{state}}/db/firefly.sqlite"
    [ -f "$db" ] || { touch "$db"; chmod 600 "$db"; }
    fresh_env=0
    # .env FIRST: composer's post-install script boots artisan, which needs APP_KEY.
    if [ ! -f .env ]; then
      cp .env.example .env
      fresh_env=1
      # SQLite outside the repo tree; bind to loopback; local environment.
      perl -pi -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/; s/^DB_HOST=.*/# DB_HOST not used with sqlite/; s{^DB_DATABASE=.*}{DB_DATABASE='"$db"'}; s{^APP_URL=.*}{APP_URL=http://{{host}}:{{port}}}; s/^APP_ENV=.*/APP_ENV=local/' .env
      printf '\n# ---- machine plane (pm/apis.mdx) ----\n# FIREFLY_MACHINE_ALLOW_WRITE=1\n# FIREFLY_MACHINE_ALLOW_ADMIN=1\n# FIREFLY_MACHINE_OPERATOR=you@example.com\n# FIREFLY_MACHINE_ADMINISTRATION=1\n' >> .env
      appkey="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
      perl -pi -e 's{^APP_KEY=.*}{APP_KEY='"$appkey"'}' .env
      chmod 600 .env
    fi
    composer install --no-interaction --prefer-dist
    case "$(grep -E '^DB_DATABASE=' .env | cut -d= -f2-)" in
      "{{root}}"/*|storage/*|"") echo "refusing: DB_DATABASE in .env points inside the repo — private data must live outside it" >&2; exit 1 ;;
    esac
    php artisan migrate --seed --force
    php artisan firefly-iii:upgrade-database
    php artisan firefly-iii:laravel-passport-keys || true
    if php artisan list --raw 2>/dev/null | grep -q '^firefly-machine:key'; then
      php artisan firefly-machine:key --init
    else
      ./cli/ffx key init
    fi
    # The web UI's Vite assets (public/build is git-ignored upstream; without it every page 500s).
    if [ ! -f public/build/manifest.json ]; then
      npm install --no-audit --no-fund
      (cd resources/assets/v3 && npm run build)
    fi
    php artisan firefly-machine:json-translations
    (cd cli && npm install --no-audit --no-fund)
    [ -d mcp ] && [ -f mcp/package.json ] && (cd mcp && npm install --no-audit --no-fund) || true
    [ "$fresh_env" = 1 ] && echo "wrote .env — set FIREFLY_MACHINE_OPERATOR there if this install has more than one user" || true
    echo "setup done. Next: just run   (or: just server-bg)"

# After this, `firefly_iii` (ff_* tools) is usable from Claude Code — restart Claude Code, or /mcp, to pick it up.
# Build the CLI and the MCP server, then register the MCP server with Claude Code.
build: sync-error-file build-cli build-mcp build-i18n build-web mcp-register

# The v3 web UI's i18next strings (public/v3/i18n/*.json, git-ignored upstream). Without them every
# chart fails with "firefly.could_not_load_chart RangeError: … unescaped latin alphabet character `n`".
build-i18n:
    cd "{{root}}" && php artisan firefly-machine:json-translations >/dev/null && echo "  i18n         public/v3/i18n/*.json generated"

# Compile the CLI (cli/code/src → cli/code/dist). The CLI must never be stale relative to the app.
build-cli:
    cd "{{root}}/cli" && ./node_modules/.bin/tsc -p code/tsconfig.json

# Compile the MCP server (mcp/src → mcp/dist, instructions from ai/mcp_prompt_firefly.md).
build-mcp:
    if [ -f "{{root}}/mcp/package.json" ]; then cd "{{root}}/mcp" && npm run --silent build; fi

# Copy errorfile/src into cli/code/src/vendor/error-file and mcp/src/vendor/error-file (pm/error_err.mdx §5.4).
# Writes a file only when it differs. Runs from `just build` and by hand — never from `just test`.
sync-error-file:
    node "{{root}}/scripts/sync-error-file.mjs"

# Fail (exit 1) when a committed vendored error-file copy differs from errorfile/src. Writes nothing.
sync-error-file-check:
    node "{{root}}/scripts/sync-error-file.mjs" --check

# Compile the error-file library and its tests (errorfile/src + test → errorfile/.build) with the CLI's tsc.
build-errorfile:
    cd "{{root}}" && cli/node_modules/.bin/tsc -p errorfile/tsconfig.json

# Read-only by default. For writes: just mcp-register 1  (the app's .env needs FIREFLY_MACHINE_ALLOW_WRITE=1 too).
# Register the MCP server with Claude Code (user scope) if not registered yet. Idempotent.
mcp-register write="0":
    #!/usr/bin/env bash
    set -euo pipefail
    entry="{{root}}/mcp/dist/index.js"
    if ! command -v claude >/dev/null 2>&1; then
      echo "  mcp          claude CLI not on PATH — not registered. Later: just mcp-register"; exit 0
    fi
    [ -f "$entry" ] || { echo "  mcp          $entry missing — run: just build-mcp" >&2; exit 1; }
    if claude mcp get {{mcp_name}} >/dev/null 2>&1 && ! claude mcp get {{mcp_name}} 2>&1 | grep -q '^No MCP server'; then
      if [ "{{write}}" = "1" ] && ! claude mcp get {{mcp_name}} 2>&1 | grep -q 'FFMCP_ALLOW_WRITE=1'; then
        claude mcp remove --scope user {{mcp_name}} >/dev/null 2>&1 || claude mcp remove {{mcp_name}} >/dev/null 2>&1 || true
      else
        echo "  mcp          {{mcp_name}} already registered with Claude Code (claude mcp get {{mcp_name}})"; exit 0
      fi
    fi
    if [ "{{write}}" = "1" ]; then
      claude mcp add --scope user {{mcp_name}} -e FFMCP_ALLOW_WRITE=1 -- "$entry" serve >/dev/null
      echo "  mcp          {{mcp_name}} registered with Claude Code (user scope, WRITES ENABLED)"
    else
      claude mcp add --scope user {{mcp_name}} -- "$entry" serve >/dev/null
      echo "  mcp          {{mcp_name}} registered with Claude Code (user scope, read-only)"
    fi
    echo "               restart Claude Code (or run /mcp) so it starts the server"

# Remove the MCP server from Claude Code.
mcp-unregister:
    claude mcp remove --scope user {{mcp_name}} 2>/dev/null || claude mcp remove {{mcp_name}}

# Web UI + /api/v1 + /machine/v1 all on one port. Logs → ~/T/_firefly_iii/server.log; faults → ~/T/firefly/error.err. Same code path as `ffx up`.
# Build everything, start the web app DETACHED (the terminal comes back), print the URL.
run: build
    #!/usr/bin/env bash
    set -euo pipefail
    "{{root}}/cli/ffx" up >/dev/null
    just --justfile "{{root}}/justfile" url

# Alias of `run` (kept for the spec's name; pm/cli.mdx §3).
server-bg: run

# Serve the app in the FOREGROUND (Ctrl-C stops it). Use `just run` to get the terminal back.
dev: build-cli
    @echo "  Firefly III (foreground):  http://{{host}}:{{port}}/   Ctrl-C stops it"
    cd "{{root}}" && PHP_CLI_SERVER_WORKERS=4 php artisan serve --host={{host}} --port={{port}}

# Print the URLs: web app, health, machine plane, plus MCP registration state.
url:
    #!/usr/bin/env bash
    set -uo pipefail
    up=down; pid=""
    if curl -fsS -m 2 "http://{{host}}:{{port}}/up" >/dev/null 2>&1; then up=UP; fi
    [ -f "{{state}}/server.pid" ] && pid="$(cat "{{state}}/server.pid" 2>/dev/null)"
    mcp=not\ registered
    if command -v claude >/dev/null 2>&1 && ! claude mcp get {{mcp_name}} 2>&1 | grep -q '^No MCP server'; then mcp=registered; fi
    echo
    echo "  ============================================================"
    echo "  Firefly III is $up${pid:+  (pid $pid)}"
    echo "  web app       http://{{host}}:{{port}}/"
    echo "  also          http://localhost:{{port}}/"
    echo "  health        http://{{host}}:{{port}}/up"
    echo "  machine API   http://{{host}}:{{port}}/machine/v1   (loopback + key only)"
    echo "  MCP server    {{mcp_name}}  $mcp in Claude Code   (ff_* tools)"
    echo "  log           ~/T/_firefly_iii/server.log"
    echo "  errors        ~/T/firefly/error.err"
    echo "  stop          just stop        status  just status        open  just open"
    echo "  ============================================================"
    echo

# Open the web app in the default browser (starts it first if it is down).
open:
    #!/usr/bin/env bash
    set -euo pipefail
    curl -fsS -m 2 "http://{{host}}:{{port}}/up" >/dev/null 2>&1 || "{{root}}/cli/ffx" up >/dev/null
    open "http://{{host}}:{{port}}/"

# Stop OUR instance (the recorded pid tree) — never a foreign process on the port.
stop:
    "{{root}}/cli/ffx" stop

# Ports, pid, /up, the machine-plane ping, the key fingerprint, operator and tiers.
status:
    "{{root}}/cli/ffx" status --format table || true

# Is this environment sane?
doctor:
    "{{root}}/cli/ffx" doctor --format table

# Follow the server log.
logs:
    "{{root}}/cli/ffx" logs --follow

# Follow the fault trail ~/T/firefly/error.err (pm/error_err.mdx), then any owed burst counts.
errors:
    "{{root}}/cli/ffx" logs --errors --follow

# Upstream-merge guard (pm/error_err.mdx §17): fail when an upstream file outside scripts/upstream-delta.allow
# differs from `git merge-base HEAD upstream/develop`; added files print as class B. No `upstream` remote → a note, exit 0.
check-delta:
    node "{{root}}/scripts/upstream-delta.mjs"

# pm/error_err.mdx §13: the PHP catch sites (php-parser), the TS/JS catch sites and the upstream v3 ceiling (the
# TypeScript compiler API), the wired runtimes, and the R17 hint check (§18); upstream PHP's silent catches print as
# informational with their delta. The report goes to error_file_coverage.json next to the error file, never into
# the repo. `--canary` rebuilds the CLI and the MCP server, then runs C1–C15 against a temp error file.
# Error-file coverage gate: every catch reports, every runtime is wired (--canary: prove every net fires; --quiet)
check-errors *FLAGS:
    #!/usr/bin/env bash
    set -uo pipefail
    cd "{{root}}"
    flags=" {{FLAGS}} "
    quiet=""; [[ "$flags" == *" --quiet "* ]] && quiet="--quiet"
    status=0
    php scripts/error-file-coverage.php $quiet || status=1
    echo
    node scripts/error-file-coverage.mjs $quiet || status=1
    echo
    # R17 (§18): the canonical hint H in every file of a hint row that carries it, at least once per row.
    hint='The detail is in ~/T/firefly/error.err — ffx logs --errors'
    r17=0
    for row in app/Machine/MachineException.php:1 app/Machine/Envelope.php:1 app/Machine/Http/Controllers/AdminController.php:2 \
               cli/code/src/client.ts:2 cli/code/src/main.ts:1 mcp/src/client.ts:3 mcp/src/server.ts:1; do
      file="${row%%:*}"; want="${row##*:}"
      have="$(rg -F -c -- "$hint" "$file" 2>/dev/null || echo 0)"
      if [ "$have" -lt "$want" ]; then echo "    VIOLATING  $file  R17 — the hint appears $have time(s), its §18 rows need $want"; r17=1; fi
    done
    if [ "$r17" -eq 0 ]; then echo "R17 hint check (pm/error_err.mdx §18): every hint row names ~/T/firefly/error.err"; else echo "R17 hint check: FAILED"; status=1; fi
    if [[ "$flags" == *" --canary "* ]]; then
      echo
      just --justfile "{{justfile()}}" build-cli build-mcp >/dev/null || status=1
      node scripts/error-file-canary.mjs || status=1
    fi
    exit $status

# The v3 Vite bundle (public/build, git-ignored) — rebuilt only when resources/assets/v3/{js,sass} is newer
# than public/build/manifest.json, so the browser error net (pm/error_err.mdx §6.6) ships and `just build` stays fast.
build-web:
    cd "{{root}}" && (node scripts/web-bundle-fresh.mjs || (cd resources/assets/v3 && npm run build))

# The error-file library's suite, the CLI's tests and canaries, the machine plane's PHPUnit suite, the MCP suite.
# pm/error_err.mdx §5.4, §15: the vendored error-file copies are CHECKED first (never rewritten here), then built;
# every line runs with FIREFLY_ERROR_FILE on a fresh canary path, and the run fails if anything wrote there (AC 11).
test: sync-error-file-check build-cli build-mcp build-errorfile
    #!/usr/bin/env bash
    set -euo pipefail
    canary_root="$(mktemp -d "${TMPDIR:-/tmp}/firefly-test-canary.XXXXXX")"
    trap 'rm -rf "$canary_root"' EXIT
    export FIREFLY_ERROR_FILE="$canary_root/firefly-test-canary/error.err"
    cd "{{root}}"
    node --test errorfile/.build/test/ errorfile/test/browser-core.test.mjs
    (cd cli && node --test code/dist/test/)
    if [ -d tests/Machine ] && [ -x vendor/bin/phpunit ]; then php vendor/bin/phpunit -c phpunit.machine.xml; fi
    if [ -f mcp/package.json ]; then (cd mcp && npm test --silent); fi
    if [ -e "$FIREFLY_ERROR_FILE" ]; then
      echo "FAIL: a test process wrote $FIREFLY_ERROR_FILE — the environment's error file, not its sandbox (pm/error_err.mdx §15, AC 11)" >&2
      exit 1
    fi

# Print the PATH line for ~/.zshrc (never edits your profile).
install-cli:
    @echo 'export PATH="{{root}}/cli:$PATH"   # add to ~/.zshrc'
