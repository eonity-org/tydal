#!/usr/bin/env bash
# TYDAL — rebuild the Elasticsearch index and embeddings (NON-destructive).
#
# Use this after switching the embedding model/driver/dimensions: it recreates
# the ES index with the correct mapping, re-pushes resources from MySQL, and
# re-embeds the chunks with the current model. The database is NOT touched —
# unlike first_install.sh, there is no migrate:fresh / reseed here.
#
# Assumes the stack is already up — run tools/deploy/start.sh first. reindex only
# connects; it does NOT start Docker or services.
# Tier-aware: in the `docker` tier the artisan commands run INSIDE the app
# container (the host can't resolve DB_HOST=mysql); in `host` they run on the host.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT/backend"
COMPOSE="$ROOT/docker-compose.yml"

usage() {
  cat <<'EOF'
Usage: reindex.sh [-h|--help]

Rebuild the Elasticsearch index + embeddings after switching the embedding
model. NON-destructive: runs only
  search:setup-indices --recreate → search:reindex → search:embed
It does NOT migrate or reseed the database (that's first_install.sh). Tier-aware:
artisan runs inside the app container (docker) or on the host (host). Requires
the stack to be up — run start.sh first; reindex does not start services.

  -h, --help   show this help and exit
EOF
}

for arg in "$@"; do
  case "$arg" in -h|--help) usage; exit 0 ;; esac
done

# Detect the application tier from backend/.env DB_HOST (see tier.lib.sh).
. "$SCRIPT_DIR/../lib/tier.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"
echo "Application tier: $INFRA"

# artisan ... : run a Laravel command in the right place for the tier.
artisan() {
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && php artisan "$@" )
  fi
}

# Precondition: the stack must be up (start.sh owns startup).
stack_up() {
  docker compose -f "$COMPOSE" exec -T mysql \
    mysqladmin ping -h localhost -u root -psecret --silent >/dev/null 2>&1 || return 1
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app php -v >/dev/null 2>&1 || return 1
  fi
  return 0
}
if ! stack_up; then
  echo "The stack isn't up. Start it first (in another terminal), then re-run reindex:" >&2
  echo "  tools/deploy/start.sh" >&2
  exit 1
fi

artisan search:setup-indices --recreate
artisan search:reindex
artisan search:embed

echo
echo "Elasticsearch index rebuilt and re-embedded (database left untouched)."
