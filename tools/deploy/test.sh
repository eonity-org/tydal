#!/usr/bin/env bash
# TYDAL — run the backend (Pest) + JS package test suites.
# NON-destructive for your dev data: the backend suite runs against the
# separate `tydal_test` database (phpunit.xml; each test file refreshes it), so
# the dev `tydal` database is never touched. The test database is created on
# first run. Tier-aware: on the `docker` application tier artisan runs inside
# the app container, and npm falls back to a node container when the host has
# none (run_npm, tier.lib.sh). Requires the stack up — run start.sh first.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE="$ROOT/docker-compose.yml"
BACKEND_DIR="$ROOT/backend"

usage() {
  cat <<'EOF'
Usage: test.sh [--backend-only] [-h|--help] [-- PEST_ARGS…]

Run the test suites: backend (Pest, against the `tydal_test` database — created
if missing; your dev database is untouched), then @tydal/client, @tydal/org-mcp
and @tydal/vault-mcp (Vitest). Requires the stack up (start.sh).

  --backend-only   skip the JS suites
  -- PEST_ARGS     passed to `artisan test`, e.g.  test.sh -- --filter=VaultWrite
  -h, --help       show this help and exit
EOF
}

BACKEND_ONLY=false
PEST_ARGS=()
while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --backend-only) BACKEND_ONLY=true; shift ;;
    --) shift; PEST_ARGS=("$@"); break ;;
    *) echo "test.sh: unknown argument '$1'" >&2; usage >&2; exit 1 ;;
  esac
done

. "$SCRIPT_DIR/../lib/tier.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"
echo "Application tier: $INFRA"

# artisan ... : run a Laravel command in the right place for the tier
# (same wrapper as first_install.sh / reindex.sh).
artisan() {
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && php artisan "$@" )
  fi
}

# The compose MySQL only creates `tydal`; phpunit.xml points at `tydal_test`
# (CI provisions it in its own service). Idempotent.
docker compose -f "$COMPOSE" exec -T mysql mysql -uroot -psecret -e \
  "CREATE DATABASE IF NOT EXISTS tydal_test; GRANT ALL PRIVILEGES ON tydal_test.* TO 'tydal'@'%';" 2>/dev/null \
  || { echo "Could not reach MySQL — is the stack up? Run tools/deploy/start.sh first." >&2; exit 1; }

# Every suite runs even if an earlier one fails; the exit status reports any.
FAILED=""

# bash 3.2 (macOS) errors on "${arr[@]}" for an empty array under set -u
artisan test ${PEST_ARGS[@]+"${PEST_ARGS[@]}"} || FAILED="$FAILED backend"

if [ "$BACKEND_ONLY" = false ]; then
  for pkg in client org-mcp vault-mcp; do
    echo
    echo "── @tydal/$pkg ──"
    run_npm "$ROOT" "$pkg" test || FAILED="$FAILED $pkg"
  done
fi

echo
if [ -n "$FAILED" ]; then
  echo "✗ Failed suite(s):$FAILED" >&2
  exit 1
fi
echo "✓ All suites passed."
