#!/usr/bin/env bash
# TYDAL — run the backend + MCP test suites against a fresh dev database.
# Dev only: this resets the database (migrate:fresh --seed). Tier-aware: on the
# `docker` application tier artisan runs inside the app container, and npm falls
# back to a node container when the host has none (run_npm, tier.lib.sh).
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE="$ROOT/docker-compose.yml"
BACKEND_DIR="$ROOT/backend"

. "$SCRIPT_DIR/tier.lib.sh"
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

rm -rf "$BACKEND_DIR"/storage/app/public/*
artisan migrate:fresh --seed
artisan test

run_npm "$ROOT" mcp test
