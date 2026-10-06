#!/usr/bin/env bash
# TYDAL — run the backend (Pest) + JS package test suites.
# NON-destructive for your dev data: the backend suite runs against the
# separate `tydal_test` database (phpunit.xml; each test file refreshes it), so
# the dev `tydal` database is never touched. The test database is created on
# first run. A shared-infrastructure installation (configure.sh --infra=shared
# --name=NAME, #23) uses its own `tydal_NAME_test` database and `NAME_test_`
# index prefix instead (TYDAL_TEST_* → tests/bootstrap.php). Tier-aware: on the `docker` application tier artisan runs inside
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

A shared-infrastructure installation (configure.sh --infra=shared --name=NAME)
tests against its own `tydal_NAME_test` database and `NAME_test_` index prefix,
so it never collides with another installation's test run.

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
. "$SCRIPT_DIR/../lib/stack.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"
stack_load "$ROOT"
echo "Application tier: $INFRA"

# Per-installation test namespace (#23). Own infrastructure: phpunit.xml's
# defaults (tydal_test, test_) — nothing is passed. Shared: tydal_NAME_test and
# NAME_test_, handed to tests/bootstrap.php through TYDAL_TEST_* (dedicated
# names: phpunit.xml pins DB_DATABASE and forces the index prefix).
TEST_ENV=()
if [ "$TYDAL_INFRA_MODE" = "shared" ]; then
  TEST_ENV=("TYDAL_TEST_DB_DATABASE=tydal_${TYDAL_INSTALLATION}_test"
            "TYDAL_TEST_INDEX_PREFIX=${TYDAL_INSTALLATION}_test_")
  echo "Shared installation '$TYDAL_INSTALLATION': test database tydal_${TYDAL_INSTALLATION}_test, index prefix ${TYDAL_INSTALLATION}_test_"
fi

# artisan ... : run a Laravel command in the right place for the tier
# (same wrapper as first_install.sh / reindex.sh).
artisan() {
  local e exec_env=()
  for e in ${TEST_ENV[@]+"${TEST_ENV[@]}"}; do exec_env+=(-e "$e"); done
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html ${exec_env[@]+"${exec_env[@]}"} app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && env ${TEST_ENV[@]+"${TEST_ENV[@]}"} php artisan "$@" )
  fi
}

if [ "$TYDAL_INFRA_MODE" = "shared" ]; then
  # The test database lives in the shared MySQL: provision-shared.sh creates it
  # (with the installation's database and user). Idempotent.
  "$SCRIPT_DIR/provision-shared.sh" >/dev/null \
    || { echo "Could not provision the test database in the shared MySQL — is the infrastructure stack up?" >&2; exit 1; }
else
  # The compose MySQL only creates `tydal`; phpunit.xml points at `tydal_test`
  # (CI provisions it in its own service). Idempotent.
  docker compose -f "$COMPOSE" exec -T mysql mysql -uroot -psecret -e \
    "CREATE DATABASE IF NOT EXISTS tydal_test; GRANT ALL PRIVILEGES ON tydal_test.* TO 'tydal'@'%';" 2>/dev/null \
    || { echo "Could not reach MySQL — is the stack up? Run tools/deploy/start.sh first." >&2; exit 1; }
fi

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
