#!/usr/bin/env bash
# TYDAL — run the vault client apps (vaults/*) as containers. Each app is an
# independent consumer of the vault boundary: a node container mounts the repo,
# builds @tydal/client, and serves the app's Vite dev server (gallery :3010,
# obsidian :3011, aity :3012, proxying /v /h /vault to the backend). Containers
# are named (tydal_gallery, …) and persistent: `up` re-starts them re-running
# npm install + the client build, so SDK/app changes are picked up. Hosts with
# npm can skip this and run `npm run dev -w @tydal/<app>` directly (see each
# app's README). Assumes the stack is already up (start.sh). Root wrapper:
# ./clients.sh. See tools/README.md.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: vault-apps.sh [up|stop|down|status] [gallery] [obsidian] [aity]
       (or the root wrapper: ./clients.sh …)

Run the vault client apps in node containers (Docker-only hosts; with host npm
you can run the workspaces directly). Default action: up. Default apps: all.

  up       create or restart the containers, wait until each dev server responds
  stop     stop the containers (fast to `up` again — node_modules persist)
  down     remove the containers (next `up` reinstalls from scratch)
  status   show container state and app URLs

Apps serve at http://localhost:3010 (gallery), :3011 (obsidian), :3012 (aity);
open ?vault=<org>/<slug> (or ?hash=…, &key=tvk_…). Environment overrides:
TYDAL_NODE_IMAGE (default node:22), TYDAL_BACKEND_URL (default
http://host.docker.internal:8000).

  -h, --help   show this help and exit
EOF
}

ACTION="up"
APPS=()
for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    up|stop|down|status) ACTION="$arg" ;;
    gallery|obsidian|aity) APPS+=("$arg") ;;
    *) echo "Unknown argument: $arg" >&2; usage; exit 1 ;;
  esac
done
[ "${#APPS[@]}" -eq 0 ] && APPS=(gallery obsidian aity)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
NODE_IMAGE="${TYDAL_NODE_IMAGE:-node:22}"
BACKEND_URL="${TYDAL_BACKEND_URL:-http://host.docker.internal:8000}"

port_of() {
  case "$1" in
    gallery) echo 3010 ;;
    obsidian) echo 3011 ;;
    aity) echo 3012 ;;
  esac
}

if ! docker info >/dev/null 2>&1; then
  echo "Docker does not appear to be running. Start the Docker daemon and re-run." >&2
  exit 1
fi

case "$ACTION" in
  status)
    for app in "${APPS[@]}"; do
      state="$(docker inspect -f '{{.State.Status}}' "tydal_$app" 2>/dev/null || echo "absent")"
      echo "tydal_$app: $state — http://localhost:$(port_of "$app")/?vault=<org>/<slug>"
    done
    exit 0
    ;;
  stop)
    for app in "${APPS[@]}"; do
      docker stop "tydal_$app" >/dev/null 2>&1 && echo "stopped tydal_$app" || echo "tydal_$app not running"
    done
    exit 0
    ;;
  down)
    for app in "${APPS[@]}"; do
      docker rm -f "tydal_$app" >/dev/null 2>&1 && echo "removed tydal_$app" || echo "tydal_$app absent"
    done
    exit 0
    ;;
esac

# --- up ---
for app in "${APPS[@]}"; do
  name="tydal_$app"
  port="$(port_of "$app")"

  if docker inspect "$name" >/dev/null 2>&1; then
    # Restart re-runs the container command below (npm install + client build +
    # vite), so a warm container still picks up SDK and app changes.
    docker restart "$name" >/dev/null
    echo "restarted $name"
  else
    # Anonymous volumes shield every workspace node_modules dir so the Linux
    # install never leaks into the host checkout through the repo bind.
    docker run -d --name "$name" -p "$port:$port" -w /app \
      -v "$ROOT":/app \
      -v tydal_npm_cache:/root/.npm \
      -v /app/node_modules \
      -v /app/frontend/node_modules \
      -v /app/client/node_modules \
      -v /app/org-mcp/node_modules \
      -v /app/vault-mcp/node_modules \
      -v /app/vaults/gallery/node_modules \
      -v /app/vaults/obsidian/node_modules \
      -v /app/vaults/aity/node_modules \
      -e TYDAL_BACKEND_URL="$BACKEND_URL" \
      "$NODE_IMAGE" \
      sh -c "npm install --no-audit --no-fund && npm run build -w @tydal/client && npm run dev -w @tydal/$app -- --host 0.0.0.0" \
      >/dev/null
    echo "created $name (first start runs a full npm install — can take a few minutes)"
  fi
done

echo "Waiting for the dev servers to respond..."
DEADLINE=$(( $(date +%s) + 600 ))
for app in "${APPS[@]}"; do
  port="$(port_of "$app")"
  until curl -sf -o /dev/null --max-time 2 "http://localhost:$port/"; do
    if [ "$(date +%s)" -ge "$DEADLINE" ]; then
      echo "tydal_$app did not respond on :$port — check: docker logs tydal_$app" >&2
      exit 1
    fi
    if [ "$(docker inspect -f '{{.State.Status}}' "tydal_$app" 2>/dev/null)" != "running" ]; then
      echo "tydal_$app exited — check: docker logs tydal_$app" >&2
      exit 1
    fi
    sleep 2
  done
  echo "  $app → http://localhost:$port/?vault=<org>/<slug>"
done
echo "Clients up. Stop with: ./clients.sh stop"
