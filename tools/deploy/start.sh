#!/usr/bin/env bash
# TYDAL — serve a dev instance. Brings the stack up, then runs the application
# tier: on `host` topology `artisan serve` + queue + Vite on the host; on
# `docker` topology only Vite (app/queue are containers). Ctrl-C stops the host
# processes (Docker keeps running). Re-running the script reloads already-running
# Docker app/queue containers so they pick up code and .env changes. Does not
# manage Ollama or seed the DB. See README.md.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: start.sh [-h|--help]

Serve a dev instance (topology-aware, no config args). Brings the stack up; on
host runs artisan serve + queue + Vite, on docker runs only Vite. Ctrl-C stops
host processes but leaves Docker running. Re-running this script reloads an
already-running Docker app + queue so code and .env changes take effect. Run
first_install.sh first to seed. See tools/deploy/README.md.

  -h, --help   show this help and exit
EOF
}

for arg in "$@"; do
  case "$arg" in -h|--help) usage; exit 0 ;; esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
COMPOSE="$ROOT/docker-compose.yml"

# --- ensure Docker is running (best effort, portable) ---
if ! docker info >/dev/null 2>&1; then
  if [ "$(uname)" = "Darwin" ] && command -v open >/dev/null 2>&1; then
    echo "Starting Docker Desktop..."
    open -a Docker
    until docker info >/dev/null 2>&1; do sleep 1; done
  else
    echo "Docker does not appear to be running. Start the Docker daemon and re-run." >&2
    exit 1
  fi
fi

# Remember whether the long-running Docker application processes already exist.
# `docker compose up -d` does not restart unchanged containers, so without this
# check a start → Ctrl-C → edit .env → start cycle leaves php-fpm and the
# queue worker holding stale configuration.
RUNNING_SERVICES="$(docker compose -f "$COMPOSE" ps --status running --services 2>/dev/null || true)"
DOCKER_APP_WAS_RUNNING=false
if printf '%s\n' "$RUNNING_SERVICES" | grep -qx 'app' || \
   printf '%s\n' "$RUNNING_SERVICES" | grep -qx 'queue'; then
  DOCKER_APP_WAS_RUNNING=true
fi

docker compose -f "$COMPOSE" up -d --remove-orphans

# In the `docker` topology the app + queue run in containers, so running artisan
# serve / queue:work on the host would clash on port 8000 and fail to resolve
# DB_HOST=mysql. Detect the tier from backend/.env DB_HOST (see tier.lib.sh) and
# skip the host backend processes — only the Vite dev server stays on the host.
. "$SCRIPT_DIR/tier.lib.sh"
INFRA="$(detect_infra "$ROOT/backend/.env" "$COMPOSE")"

# --- stop backgrounded host processes on exit (no orphaned workers/serve) ---
pids=()
cleaned=0
cleanup() {
  # trap fires on INT/TERM and then again on the ensuing EXIT — run once.
  [ "$cleaned" -eq 1 ] && return
  cleaned=1
  echo
  echo "Stopping host processes..."
  # Guard the expansion: on the docker tier `pids` stays empty, and bash 3.2
  # (macOS default) treats "${pids[@]}" on an empty array as an unbound variable
  # under `set -u`, which would make the trap itself fail.
  if [ "${#pids[@]}" -gt 0 ]; then
    for pid in "${pids[@]}"; do
      kill "$pid" 2>/dev/null || true
    done
  fi
  # Vite fallback container (Docker-only hosts) — remove if it survived Ctrl-C.
  docker rm -f tydal_vite >/dev/null 2>&1 || true
}
trap cleanup EXIT INT TERM

if [ "$INFRA" = "docker" ]; then
  if [ "$DOCKER_APP_WAS_RUNNING" = true ]; then
    echo "Reloading app + queue containers so code and .env changes take effect…"
    docker compose -f "$COMPOSE" restart app queue
  fi
  echo "Application tier: docker — backend (app + queue) runs in containers at http://localhost:8000."
  echo "Starting only the Vite dev server on the host (Ctrl-C stops it; containers keep running)."
else
  cd "$ROOT/backend"
  php -d upload_max_filesize=300M -d post_max_size=310M artisan serve &
  pids+=($!)
  php artisan queue:work --timeout=300 &
  pids+=($!)
fi

# Vite runs in the foreground; Ctrl-C here triggers the cleanup trap above.
# Docker-only host (no npm): run Vite in a disposable node container instead.
# Plain port mapping (works on every Docker install — no host-networking
# feature needed); the dev proxy's backend target moves to host.docker.internal
# because the container's own localhost:8000 is not the backend
# (frontend/vite.config.ts reads TYDAL_BACKEND_URL).
if command -v npm >/dev/null 2>&1; then
  cd "$ROOT/frontend"
  npm run dev
else
  echo "npm not found on host — running Vite in a ${TYDAL_NODE_IMAGE:-node:22} container (Ctrl-C stops it)."
  # Clear any leftover container from a previous run that didn't clean up (e.g. a
  # hard kill) — otherwise `docker run --name tydal_vite` fails with a name conflict.
  docker rm -f tydal_vite >/dev/null 2>&1 || true
  docker run --rm --name tydal_vite -p 3005:3005 \
    -e TYDAL_BACKEND_URL="${TYDAL_BACKEND_URL:-http://host.docker.internal:8000}" \
    -v "$ROOT":/app -v tydal_npm_cache:/root/.npm -w /app/frontend \
    "${TYDAL_NODE_IMAGE:-node:22}" npm run dev
fi
