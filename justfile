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
#   `just dev`    foreground server (Ctrl-C stops).   `just stop` / `just status` / `just logs`.
#
#   Private data never lives in this repo: the SQLite database, the logs and the
#   pid file live under ~/T/_firefly_iii/, and the machine key in
#   ~/.credentials/firefly_iii.json (minted by the web app on its first run).

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
    (cd cli && npm install --no-audit --no-fund)
    [ -d mcp ] && [ -f mcp/package.json ] && (cd mcp && npm install --no-audit --no-fund) || true
    [ "$fresh_env" = 1 ] && echo "wrote .env — set FIREFLY_MACHINE_OPERATOR there if this install has more than one user" || true
    echo "setup done. Next: just run   (or: just server-bg)"

# After this, `firefly_iii` (ff_* tools) is usable from Claude Code — restart Claude Code, or /mcp, to pick it up.
# Build the CLI and the MCP server, then register the MCP server with Claude Code.
build: build-cli build-mcp mcp-register

# Compile the CLI (cli/code/src → cli/code/dist). The CLI must never be stale relative to the app.
build-cli:
    cd "{{root}}/cli" && ./node_modules/.bin/tsc -p code/tsconfig.json

# Compile the MCP server (mcp/src → mcp/dist, instructions from ai/mcp_prompt_firefly.md).
build-mcp:
    if [ -f "{{root}}/mcp/package.json" ]; then cd "{{root}}/mcp" && npm run --silent build; fi

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

# Web UI + /api/v1 + /machine/v1 all on one port. Logs → ~/T/_firefly_iii/server.log. Same code path as `ffx up`.
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

# The CLI's tests and canaries; the machine plane's PHPUnit suite when it exists.
test: build-cli build-mcp
    cd "{{root}}/cli" && node --test code/dist/test/
    if [ -d "{{root}}/tests/Machine" ] && [ -x "{{root}}/vendor/bin/phpunit" ]; then cd "{{root}}" && php vendor/bin/phpunit -c phpunit.machine.xml; fi
    if [ -f "{{root}}/mcp/package.json" ]; then cd "{{root}}/mcp" && npm test --silent; fi

# Print the PATH line for ~/.zshrc (never edits your profile).
install-cli:
    @echo 'export PATH="{{root}}/cli:$PATH"   # add to ~/.zshrc'
