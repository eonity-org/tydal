#!/usr/bin/env bash
# TYDAL — reload the backend so it picks up code / .env changes (NON-destructive).
#
# The queue worker (and php-fpm) boot Laravel once and hold code + config in
# memory, so changes to queued-job / pipeline code (or .env) aren't seen until
# they restart. This does the right restart for your topology. Nothing is lost:
# the DB / ES / Redis volumes are untouched.
#
#   docker topology — restarts the app + queue containers.
#   host topology   — signals the queue worker to reload (queue:restart). The
#                     web tier (`artisan serve`) hot-reloads code per request,
#                     so only the worker needs this.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT/backend"
COMPOSE="$ROOT/docker-compose.yml"

usage() {
  cat <<'EOF'
Usage: reload.sh [-h|--help]

Reload the backend after editing code or .env (NON-destructive — no data loss).
Topology-aware:
  docker — docker compose restart app queue
  host   — php artisan queue:restart (web hot-reloads on its own)

Only the queue worker (and php-fpm config) need this; controllers/routes/views
and the Vite frontend already hot-reload. See tools/deploy/README.md.

  -h, --help   show this help and exit
EOF
}

for arg in "$@"; do
  case "$arg" in -h|--help) usage; exit 0 ;; esac
done

# Detect topology from backend/.env DB_HOST (see tier.lib.sh).
. "$SCRIPT_DIR/../lib/tier.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"
echo "Application tier: $INFRA"

if [ "$INFRA" = "docker" ]; then
  echo "Restarting app + queue containers…"
  docker compose -f "$COMPOSE" restart app queue
  echo "Done — containers reloaded code + .env. (Data volumes untouched.)"
else
  echo "Signaling the queue worker to reload (php artisan queue:restart)…"
  ( cd "$BACKEND_DIR" && php artisan queue:restart ) || true
  echo "Done. The web server (artisan serve) hot-reloads code on its own."
  echo "Note: start.sh launches the worker without a supervisor, so it has now"
  echo "      exited — re-run tools/deploy/start.sh to bring the worker back"
  echo "      (or rely on your own supervisor if you run one)."
fi
