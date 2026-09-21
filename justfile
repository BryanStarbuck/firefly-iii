# Firefly III — this fork's local lifecycle (pm/cli.mdx §2.3, pm/apis.mdx §3.3).
# Upstream ships no justfile; this one is ours. Run `just` to list recipes.
#
#   One process serves everything on http://127.0.0.1:7373/ :
#   the web UI, upstream's /api/v1, Laravel's /up health route, and /machine/v1.
#
#   Private data never lives in this repo: the SQLite database, the logs and the
#   pid file live under ~/T/_firefly_iii/, and the machine key in
#   ~/.credentials/firefly_iii.json (minted by the web app on its first run).

set shell := ["bash", "-euo", "pipefail", "-c"]

root  := justfile_directory()
state := env_var("HOME") + "/T/_firefly_iii"
host  := "127.0.0.1"
port  := "7373"

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
    composer install --no-interaction --prefer-dist
    fresh_env=0
    if [ ! -f .env ]; then
      cp .env.example .env
      fresh_env=1
      # SQLite outside the repo tree; bind to loopback; local environment.
      perl -pi -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/; s/^DB_HOST=.*/# DB_HOST not used with sqlite/; s{^DB_DATABASE=.*}{DB_DATABASE='"$db"'}; s{^APP_URL=.*}{APP_URL=http://{{host}}:{{port}}}; s/^APP_ENV=.*/APP_ENV=local/' .env
      printf '\n# ---- machine plane (pm/apis.mdx) ----\n# FIREFLY_MACHINE_ALLOW_WRITE=1\n# FIREFLY_MACHINE_ALLOW_ADMIN=1\n# FIREFLY_MACHINE_OPERATOR=you@example.com\n# FIREFLY_MACHINE_ADMINISTRATION=1\n' >> .env
      php artisan key:generate --force
    fi
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
    (cd cli && npm install --no-audit --no-fund)
    [ -d mcp ] && [ -f mcp/package.json ] && (cd mcp && npm install --no-audit --no-fund) || true
    [ "$fresh_env" = 1 ] && echo "wrote .env — set FIREFLY_MACHINE_OPERATOR there if this install has more than one user" || true
    echo "setup done. Next: just run   (or: just server-bg)"

# Build the CLI (and the MCP server when present). The CLI must never be stale relative to the app.
build:
    cd "{{root}}/cli" && ./node_modules/.bin/tsc -p code/tsconfig.json
    if [ -f "{{root}}/mcp/package.json" ]; then cd "{{root}}/mcp" && npm run --silent build; fi

# Serve the app in the FOREGROUND on http://127.0.0.1:7373/ (Ctrl-C stops it).
run: build
    cd "{{root}}" && PHP_CLI_SERVER_WORKERS=4 php artisan serve --host={{host}} --port={{port}}

# Serve the app DETACHED — logs to ~/T/_firefly_iii/server.log, pid in server.pid. Same code path as `ffx up`.
server-bg: build
    "{{root}}/cli/ffx" up

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
test: build
    cd "{{root}}/cli" && node --test code/dist/test/
    if [ -d "{{root}}/tests/Machine" ] && [ -x "{{root}}/vendor/bin/phpunit" ]; then cd "{{root}}" && vendor/bin/phpunit tests/Machine; fi
    if [ -f "{{root}}/mcp/package.json" ]; then cd "{{root}}/mcp" && npm test --silent; fi

# Print the PATH line for ~/.zshrc (never edits your profile).
install-cli:
    @echo 'export PATH="{{root}}/cli:$PATH"   # add to ~/.zshrc'
