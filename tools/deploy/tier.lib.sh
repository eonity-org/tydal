#!/usr/bin/env bash
# Shared application-tier detection for the deploy scripts. SOURCED, not run.
#
# detect_infra ENV_FILE COMPOSE_FILE  → echoes "docker" or "host".
#
# The application tier decides WHERE the backend (artisan, the queue worker) runs
# — inside the `app` container or on the host. The DECISIVE signal is DB_HOST in
# backend/.env: the address artisan actually dials. A docker-network name (e.g.
# "mysql") only resolves inside the container, so artisan MUST run there;
# localhost / 127.0.0.1 means run on the host. Keying the tier off DB_HOST keeps
# it in lockstep with the .env used for the connection, so the two can never
# disagree. (The old probe read the compose file separately and, on any hiccup,
# silently fell back to "host" while .env pointed DB_HOST at "mysql" — producing
# the "getaddrinfo for mysql failed" crash when artisan then ran on the host.)
#
# Fallback: if DB_HOST is missing/unreadable, probe the compose file — the `app`
# service is enabled only in the docker tier.
detect_infra() {
  local env_file="$1" compose="$2" db_host=""
  if [ -f "$env_file" ]; then
    db_host="$(awk -F= '/^[[:space:]]*DB_HOST[[:space:]]*=/{
      v=$2; sub(/#.*/, "", v); gsub(/[[:space:]"]/, "", v); print v; exit
    }' "$env_file")"
  fi
  if [ -n "$db_host" ]; then
    case "$db_host" in
      localhost|127.0.0.1|::1) echo host ;;
      *)                       echo docker ;;
    esac
    return
  fi
  if docker compose -f "$compose" config --services 2>/dev/null | grep -qx app; then
    echo docker
  else
    echo host
  fi
}

# run_npm ROOT REL_DIR NPM_ARGS…  → run `npm NPM_ARGS` in ROOT/REL_DIR.
#
# Uses the host npm when installed; otherwise (a Docker-only machine — no Node
# toolchain on the host) falls back to a disposable node container with the
# whole npm workspace bind-mounted, so workspace links (@tydal/client) resolve.
# The container branch writes Linux-native node_modules into the checkout — if
# you later install Node on the host, `rm -rf node_modules` and reinstall.
# A named volume caches the npm download cache across runs.
run_npm() {
  local root="$1" rel="$2"; shift 2
  if command -v npm >/dev/null 2>&1; then
    ( cd "$root/$rel" && npm "$@" )
  else
    echo "npm not found on host — running 'npm $*' in a ${TYDAL_NODE_IMAGE:-node:22} container ($rel)…"
    docker run --rm -v "$root":/app -v tydal_npm_cache:/root/.npm \
      -w "/app/$rel" "${TYDAL_NODE_IMAGE:-node:22}" npm "$@"
  fi
}
