#!/usr/bin/env bash
# TYDAL — one-time dev setup: install deps, build the frontend, write backend/.env
# for the chosen tiers, and recreate the containers. The AI + Application tiers
# are REQUIRED (no defaults). See tools/deploy/README.md for the three-tier model.
# Optional --infra=own|shared --name=NAME (passed to configure.sh): run this
# checkout's own backing services (default) or share another checkout's
# (DEPLOYMENT.md, "Several installations on one server").
# Next: tools/deploy/start.sh (serve — terminal 1) then tools/deploy/first_install.sh
# (seed — terminal 2). start.sh owns service startup; first_install assumes it's up.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: install.sh <cloud|ollama-host|ollama-docker> <host|docker>
                  [--infra=own|shared] [--name=NAME] [--url=URL] [-f] [--no-force-env] [--fresh]
                  [--prod|--dev] [--data-dir=DIR]

One-time DEV setup (or a server one with --prod): install deps + build frontend + write backend/.env + recreate
containers. 

A dev instance has three tiers. The backing services (Elasticsearch, Tika, MySQL,
Redis) ALWAYS run in Docker; you only choose where these two tiers run:

  AI  tier   cloud | ollama-host (host, GPU) | ollama-docker (CPU only)
  APP tier   host (PHP/nginx + queue on the host) | docker (in containers)

  --infra=own       (default) run this checkout's own backing services
  --infra=shared --name=NAME   (--name alone implies --infra=shared)
                    use the backing services another checkout already runs
                    (several installations on one server — DEPLOYMENT.md);
                    NAME derives the database, index/Redis prefixes, container
                    names and ports. --slot/--infra-stack/--infra-network/
                    --no-provision are passed to configure.sh as well
                    (see 'configure.sh --help').
  --url=URL         public address of this installation (e.g.
                    https://staging.example.org): APP_URL and the SPA's API base
                    URL, built into the frontend; kept on later runs
                    (configure.sh --help). nginx/TLS stay yours.
  --prod            a server installation (docker tier, needs --url): ports on
                    127.0.0.1, production .env, composer without dev packages,
                    config cache, nginx-site.conf for the host's nginx.
                    --data-dir=DIR keeps the data in host directories.
                    Details: 'configure.sh --help', DEPLOYMENT.md.
  -f, --force       skip the confirmation prompt (required in CI / non-interactive)
  --no-force-env    keep an existing backend/.env instead of rewriting it
  --fresh           DELETE existing data volumes (mysql/es/redis/ollama) for a
                    clean slate. Without it, install detects leftover volumes and
                    asks (default: keep). Use this when a fresh clone is still
                    serving an old DB / superadmin password from a prior install.
  -h, --help        show this help

Rewrites backend/.env by default (backup saved, secrets kept) so it matches your
tiers, then recreates containers — hence the confirmation prompt.

By default data volumes are PRESERVED across re-runs (so switching tiers doesn't
lose data). A clean clone can therefore still serve a previous install's database
— pass --fresh (or answer the volume prompt) to wipe it.

install.sh does NOT seed the DB (run first_install.sh for a minimal starting dataset).

e.g.  install.sh ollama-host docker   ·   install.sh cloud host -f
      install.sh cloud docker --infra=shared --name=staging
      install.sh cloud docker --infra=shared --name=staging --url=https://staging.example.org

EOF
}

# --- parse args: AI backend + topology (required), plus install's own flags ---
AI_ARG=""
TOPO_ARG=""
FORCE=false        # -f/--force : skip the confirmation prompt
FORCE_ENV=true     # rewrite backend/.env by default; --no-force-env opts out
FRESH=false        # --fresh : delete data volumes (down -v) instead of preserving
INFRA_ARGS=()      # --infra/--name/--slot/--infra-stack/--infra-network/--no-provision/--url → configure.sh
for arg in "$@"; do
  case "$arg" in
    -h|--help)        usage; exit 0 ;;
    -f|--force)       FORCE=true ;;
    --no-force-env)   FORCE_ENV=false ;;
    --force-env)      FORCE_ENV=true ;;   # explicit (already the default)
    --fresh)          FRESH=true ;;
    --infra=*|--name=*|--slot=*|--infra-stack=*|--infra-network=*|--no-provision|--url=*|--prod|--dev|--data-dir=*)
                      INFRA_ARGS+=("$arg") ;;
    cloud|aicloud|ollama|ollama-host|ollama-docker) AI_ARG="$arg" ;;
    host|native|docker)                              TOPO_ARG="$arg" ;;
    *) echo "install.sh: unknown argument '$arg'" >&2; echo >&2; usage >&2; exit 1 ;;
  esac
done
if [ -z "$AI_ARG" ] || [ -z "$TOPO_ARG" ]; then
  echo "install.sh: you must specify both an AI tier (cloud|ollama-host|ollama-docker)" >&2
  echo "and an application tier (host|docker). E.g.: 'install.sh ollama-docker docker'. See 'install.sh --help'." >&2
  echo >&2
  usage >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

echo "TYDAL setup ..."
echo ""

# Validate everything configure.sh would refuse (flags, name, slot, a clash with
# another installation's containers) BEFORE asking anything or installing.
"$SCRIPT_DIR/configure.sh" "$AI_ARG" "$TOPO_ARG" --check ${INFRA_ARGS[@]+"${INFRA_ARGS[@]}"} \
  $( [ "$FORCE_ENV" = false ] && echo --no-force-env ) || exit 1
echo ""

# --- confirmation (skipped with -f/--force) ---
if [ "$FORCE" = false ]; then
  echo "One-time DEV setup... install.sh will:"
  echo "  • Install backend + frontend dependencies and build the frontend"
  if [ "$FORCE_ENV" = true ] && [ -f "$ROOT/backend/.env" ]; then
    echo "  • REWRITE backend/.env from the '$AI_ARG' template (a timestamped backup"
    echo "    is saved and your secrets are carried over)"
  elif [ "$FORCE_ENV" = true ]; then
    echo "  • Create backend/.env from the '$AI_ARG' template"
  else
    echo "  • Leave an existing backend/.env untouched (--no-force-env)"
  fi
  if [ "$FRESH" = true ]; then
    echo "  • Recreate the Docker containers AND DELETE data volumes (down -v;"
    echo "    --fresh) — the database, search index and Redis cache are WIPED"
  else
    echo "  • Recreate the Docker containers (down --remove-orphans && up -d;"
    echo "    data volumes are preserved)"
  fi
  echo
  if [ -t 0 ]; then
    _ans=""; read -r -p "Continue? [y/N] " _ans || true
    case "$_ans" in
      y|Y|yes|YES) ;;
      *) echo "Aborted."; exit 1 ;;
    esac
  else
    echo "Non-interactive shell and no -f/--force — aborting. Pass -f to proceed." >&2
    exit 1
  fi
fi

# --- detect leftover data volumes (the "fresh clone, old database" trap) -------
# `docker compose down` (no -v) and deleting containers in the Docker GUI both
# PRESERVE named volumes, so a clean clone can keep serving a prior install's DB
# (and superadmin password). If --fresh wasn't passed, warn when such volumes
# exist and offer to wipe them. Compose derives the project name from the dir
# holding the compose file, so its data volumes are "<project>_<name>".
COMPOSE="$ROOT/docker-compose.yml"
# A shared-infrastructure installation has no data volumes of its own (its data
# lives in the shared MySQL/ES/Redis, kept apart by name), so skip the check.
SHARED_INSTALL=false
case " ${INFRA_ARGS[*]-} " in
  *" --infra=shared "*) SHARED_INSTALL=true ;;
  *" --infra=own "*)    ;;
  *" --name="*)         SHARED_INSTALL=true ;;   # --name implies shared
esac
if [ "$FRESH" = false ] && [ "$SHARED_INSTALL" = false ] && docker info >/dev/null 2>&1; then
  PROJECT="$(basename "$ROOT" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9_-')"
  _vols="$(docker volume ls -q 2>/dev/null \
            | grep -E "^${PROJECT}_(mysql_data|elasticsearch_data|redis_data|ollama_data)$" || true)"
  if [ -n "$_vols" ]; then
    echo
    echo "⚠️  Existing data volume(s) found — your PREVIOUS database (including the"
    echo "    superadmin password), search index and cache will be REUSED:"
    echo "$_vols" | sed 's/^/        /'
    echo "    A fresh clone can therefore still serve an old DB / old login."
    if [ -t 0 ]; then
      _ans=""; read -r -p "    Wipe these volumes for a clean start? [y/N] " _ans || true
      case "$_ans" in
        y|Y|yes|YES) FRESH=true; echo "    → will wipe data volumes." ;;
        *)           echo "    → keeping existing data (re-run with --fresh to wipe)." ;;
      esac
    else
      echo "    Non-interactive: keeping data. Pass --fresh to wipe."
    fi
  fi
fi

# --- configure .env files (fast; no deps) ---
# Pass -f so configure.sh doesn't prompt again (install already confirmed above).
cfg_args=("$AI_ARG" "$TOPO_ARG" -f ${INFRA_ARGS[@]+"${INFRA_ARGS[@]}"})
[ "$FORCE_ENV" = false ] && cfg_args+=(--no-force-env)
TYDAL_FROM_INSTALL=1 "$SCRIPT_DIR/configure.sh" "${cfg_args[@]}"

# Installation names/ports as configure.sh just recorded them (root .env).
. "$SCRIPT_DIR/../lib/stack.lib.sh"
stack_load "$ROOT"
OLLAMA_CONTAINER="$(infra_container ollama)"

# --- tier-aware execution helpers ---
# On the `docker` application tier the host may have NO PHP/Composer/Node at
# all: PHP steps run inside the app container and npm falls back to a node
# container (run_npm, tier.lib.sh). On `host`, everything runs as before.
. "$SCRIPT_DIR/../lib/tier.lib.sh"
case "$TOPO_ARG" in docker) TOPO="docker" ;; *) TOPO="host" ;; esac

# app_exec CMD… : run a command (composer/php artisan) inside the app container.
# In production as the image's uid-1000 user, so vendor/, the .env key and the
# caches belong to the app (php-fpm and the workers run as that user too).
app_exec() {
  if [ "$TYDAL_ENV" = "production" ]; then
    docker compose -f "$COMPOSE" exec -T -u application -w /var/www/html app "$@"
  else
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app "$@"
  fi
}
COMPOSER_FLAGS=(--no-interaction --no-progress --quiet)
[ "$TYDAL_ENV" = "production" ] && COMPOSER_FLAGS+=(--no-dev --optimize-autoloader)

# recreate_containers: down (+ optional volume wipe) && up so containers pick up
# the new .env / compose toggles. Long-running containers (php-fpm, the queue
# worker) cache config in memory and don't see .env changes until recreated.
# Data volumes (mysql/es/redis) survive `down` (no -v) UNLESS --fresh was passed
# (or the volume prompt was accepted), in which case `down -v` wipes them.
# Afterwards, pulls the Ollama models for the ollama-docker AI tier.
recreate_containers() {
  echo
  if docker info >/dev/null 2>&1; then
    if [ "$FRESH" = true ]; then
      echo "Recreating containers + WIPING data volumes (docker compose down -v && up -d)…"
      docker compose -f "$COMPOSE" down -v --remove-orphans
    else
      echo "Recreating containers (docker compose down --remove-orphans && up -d)…"
      docker compose -f "$COMPOSE" down --remove-orphans
    fi
    # A shared installation on the host tier has no Compose services of its own
    # (its backing services run in the infrastructure stack): nothing to start.
    if [ "$TYDAL_INFRA_MODE" != "shared" ] || \
       [ -n "$(docker compose -f "$COMPOSE" config --services 2>/dev/null)" ]; then
      docker compose -f "$COMPOSE" up -d --remove-orphans
    fi

    # --- pull Ollama models for the ollama-docker tier ------------------------
    # A fresh install (the ollama_data volume was wiped, or this is a clean clone)
    # starts Ollama EMPTY, so the UI reports every engine "not accessible" until
    # models exist. Pull the models configured in backend/.env (embed + chat +
    # vision) now. Idempotent: models already present are skipped, so re-runs on a
    # preserved volume cost nothing. Vision (~8 GB) is CPU-only here and may be
    # slow / tight on RAM — prefer ollama-host if vision performance matters.
    if [ "$AI_ARG" = "ollama-docker" ]; then
      env_model() {  # print KEY's value from backend/.env (inline comment + quotes stripped)
        awk -v k="$1" 'index($0,k"=")==1{v=substr($0,length(k)+2);sub(/[[:space:]]*#.*/,"",v);gsub(/^[[:space:]]+|[[:space:]]+$/,"",v);gsub(/^"|"$/,"",v);print v;exit}' "$ROOT/backend/.env"
      }
      MODELS=""
      for _k in OLLAMA_EMBED_MODEL OLLAMA_LLM_MODEL OLLAMA_VISION_MODEL; do
        _m="$(env_model "$_k")"; [ -n "$_m" ] && MODELS="$MODELS $_m"
      done
      if [ -n "$MODELS" ]; then
        echo
        echo "Waiting for the Ollama container ($OLLAMA_CONTAINER) to be ready…"
        _t=0
        until docker exec "$OLLAMA_CONTAINER" ollama list >/dev/null 2>&1; do
          _t=$((_t+1))
          if [ "$_t" -ge 60 ]; then
            echo "  Ollama not ready after 60s — skipping model pull."
            echo "  Pull manually later: docker exec $OLLAMA_CONTAINER ollama pull <model>"
            break
          fi
          sleep 1
        done
        if docker exec "$OLLAMA_CONTAINER" ollama list >/dev/null 2>&1; then
          _have="$(docker exec "$OLLAMA_CONTAINER" ollama list 2>/dev/null | awk 'NR>1{print $1}')"
          for _m in $MODELS; do
            if printf '%s\n' "$_have" | grep -qxE "$_m(:latest)?"; then
              echo "  ✓ $_m already present"
            else
              echo "  ↓ pulling $_m …"
              docker exec "$OLLAMA_CONTAINER" ollama pull "$_m" \
                || echo "    ⚠️  failed to pull $_m — pull it manually later."
            fi
          done
        fi
      fi
    fi
  else
    echo "Docker isn't running — skipped container recreate."
    echo "  Run 'docker compose down --remove-orphans && docker compose up -d' once Docker is up."
    if [ "$AI_ARG" = "ollama-docker" ]; then
      echo "  Then pull the Ollama models, e.g.:"
      echo "    docker exec $OLLAMA_CONTAINER ollama pull mxbai-embed-large"
      echo "    docker exec $OLLAMA_CONTAINER ollama pull llama3.2"
      echo "    docker exec $OLLAMA_CONTAINER ollama pull llama3.2-vision"
    fi
  fi
}

# --- backend dependencies (tier-aware) ---
mkdir -p "$ROOT/backend/bootstrap/cache" \
         "$ROOT/backend/storage/framework/views" \
         "$ROOT/backend/storage/framework/cache" \
         "$ROOT/backend/storage/framework/sessions"

if [ "$TOPO" = "docker" ]; then
  # PHP deps install INSIDE the app container, so the containers must come up
  # BEFORE the dependency step (the reverse of the host tier's order).
  if ! docker info >/dev/null 2>&1; then
    echo "install.sh: the docker application tier needs the Docker daemon running." >&2
    exit 1
  fi
  recreate_containers
  echo "Waiting for the app container…"
  _t=0
  until app_exec php -v >/dev/null 2>&1; do
    _t=$((_t+1))
    if [ "$_t" -ge 60 ]; then
      echo "install.sh: app container ($(stack_container app)) not ready after 60s — check 'docker compose logs app'." >&2
      exit 1
    fi
    sleep 1
  done
  echo "Installing PHP dependencies (composer, in $(stack_container app))…"
  app_exec composer install "${COMPOSER_FLAGS[@]}"
  grep -q '^APP_KEY=base64:' "$ROOT/backend/.env" || app_exec php artisan key:generate --force
  app_exec php artisan storage:link || true
  # Production reads a cached config (rebuilt by reload.sh after .env edits).
  [ "$TYDAL_ENV" = "production" ] && app_exec php artisan config:cache
else
  if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1; then
    echo "install.sh: the host application tier needs php + composer on the host." >&2
    echo "On a Docker-only machine re-run with the 'docker' app tier instead." >&2
    exit 1
  fi
  cd "$ROOT/backend"
  echo "Installing PHP dependencies (composer)…"
  composer install "${COMPOSER_FLAGS[@]}"
  grep -q '^APP_KEY=base64:' .env || php artisan key:generate
  php artisan storage:link || true
fi

# --- frontend dependencies ---
# One npm-workspace install at the root links @tydal/client into every consumer
# (frontend, mcp, vault-mcp, vaults/*). The SDK must be BUILT before the frontend
# compiles — the SPA imports client/dist/, which is not committed. mcp/ and
# vault-mcp/ aren't built here (most devs never touch either MCP server) — see
# install_mcp.sh.
echo "Installing JS dependencies (npm workspace)…"
run_npm "$ROOT" . install --no-audit --no-fund --loglevel=error
run_npm "$ROOT" client run build
run_npm "$ROOT" frontend run build

# --- recreate containers (host tier only — the docker tier did it above) ---
if [ "$TOPO" != "docker" ]; then
  recreate_containers
else
  # The queue container crash-looped while vendor/ was incomplete; nudge it now
  # that dependencies exist rather than waiting out its restart backoff.
  docker compose -f "$COMPOSE" restart queue >/dev/null 2>&1 || true
fi

# --- final summary: what was installed and where it answers ---------------------
# Everything above scrolls (composer, npm, vite); this is what to read.
benv() { awk -v k="$1" 'index($0, k"=") == 1 { v = substr($0, length(k) + 2); gsub(/^"|"$/, "", v); print v; exit }' "$ROOT/backend/.env" 2>/dev/null; }
_slot="$(stack_env_get TYDAL_SLOT "$ROOT/.env" 2>/dev/null || true)"
_db="$(benv DB_DATABASE)"; _prefix="$(benv ELASTICSEARCH_INDEX_PREFIX)"; _url="$(benv TYDAL_PUBLIC_URL)"
_status() {
  local c="$1" st
  st="$(docker inspect -f '{{.State.Status}}' "$c" 2>/dev/null || echo 'not created')"
  printf '    %-28s %s\n' "$c" "$st"
}

echo
echo "================================================================================"
echo "Install complete."
echo
if [ "$TYDAL_INFRA_MODE" = "shared" ]; then
  echo "  Installation  '$TYDAL_INSTALLATION' — shared infrastructure from the '$TYDAL_INFRA_STACK' stack (slot ${_slot:-?})"
else
  echo "  Installation  own infrastructure (default names)"
fi
[ "$TYDAL_ENV" = "production" ] && echo "  Mode          PRODUCTION — ports on 127.0.0.1, config cached (reload.sh after .env edits)$( [ -n "$(stack_env_get TYDAL_DATA_DIR "$ROOT/.env" 2>/dev/null)" ] && echo "; data in $(stack_env_get TYDAL_DATA_DIR "$ROOT/.env")")"
echo "  Tiers         AI $AI_ARG · app $TOPO_ARG"
echo "  App / API     http://localhost:$TYDAL_HTTP_PORT   (API: /api/v1)"
if [ "$TYDAL_ENV" = "production" ]; then
  echo "  Public URL    ${_url:-?}   (install nginx-site.conf in the host's nginx, then certbot)"
else
  echo "  Web (Vite)    http://localhost:$TYDAL_VITE_PORT   (after start.sh)"
  [ -n "$_url" ] && echo "  Public URL    $_url   (your web server routes it to app :$TYDAL_HTTP_PORT)"
fi
echo "  Database      ${_db:-?}   · index prefix ${_prefix:-(none)}"
if docker info >/dev/null 2>&1; then
  echo "  Containers"
  if [ "$TOPO" = "docker" ]; then
    for _s in app queue scheduler; do _status "$(stack_container "$_s")"; done
  fi
  for _s in mysql elasticsearch redis tika; do _status "$(infra_container "$_s")"; done
fi
echo
echo ">>> Review backend/.env — fill the vars in its '# Min VARS to"
echo "    personalize' header (API keys, superadmin password) if you"
echo "    haven't already."
echo ">>> To reconfigure this dev setup, run configure.sh directly."
echo
echo "Next — start.sh owns service startup, so run it FIRST:"
echo "  tools/deploy/start.sh   # terminal 1: bring up services + serve (blocks)"
echo "  tools/deploy/first_install.sh # terminal 2: seed DB + build index (stack must be up)"
echo
echo "Want an MCP server (Claude Desktop, Claude Code, Cursor, …)? Not built above:"
echo "  tools/deploy/install_mcp.sh"
echo "================================================================================"
