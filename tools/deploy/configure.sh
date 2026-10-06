#!/usr/bin/env bash
# TYDAL — set the AI tier + application tier in backend/.env + docker-compose.yml,
# and whether this installation runs its own infrastructure or shares another
# checkout's (--infra=own|shared, #23; shared also writes the root .env).
# Config only: no deps/build, nothing started. See tools/deploy/README.md for the
# three-tier model and DEPLOYMENT.md "Several installations on one server".
# Re-run any time to switch tiers without rebuilding.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEMPLATES="$SCRIPT_DIR/env.templates"
COMPOSE="$ROOT/docker-compose.yml"

usage() {
  cat <<'EOF'
Usage: configure.sh <cloud|ollama-host|ollama-docker> <host|docker>
                    [--infra=own|shared] [--name=NAME] [-f] [--no-force-env]
                    [--slot=N] [--infra-stack=STACK] [--infra-network=NET] [--no-provision]

Writes backend/.env and toggles docker-compose.yml to match two tiers (config
only — no build). Backing services (ES/Tika/MySQL/Redis) are always Docker.
Both tier args are REQUIRED.

  AI  tier   cloud | ollama-host (host, GPU) | ollama-docker (CPU only)
  APP tier   host (PHP/nginx + queue on the host) | docker (in containers)

Infrastructure (several installations on one server — DEPLOYMENT.md):
  --infra=own       (default) this checkout runs its own MySQL/ES/Kibana/Tika/
                    Redis under the usual names (tydal_mysql, …, app :8000).
  --infra=shared    use the infrastructure another checkout runs; requires
  --name=NAME       NAME: lowercase letters/digits/_ , starts with a letter or
                    digit, at most 16 chars. Derives database + user tydal_NAME
                    (test DB tydal_NAME_test), index prefix NAME_, Redis prefix
                    tydal_NAME_ + its own Redis DBs, containers tydal_NAME_*,
                    and offset ports. Writes the root .env (Compose's).
  --slot=N          shared only: 1–7, picks the port offset (app 8000+100·N,
                    Vite 3005+100·N) and Redis DBs (2N, 2N+1). Default: kept
                    from a previous run, else derived from NAME.
  --infra-stack=S   shared only: container-name prefix of the stack running the
                    infrastructure (default tydal → tydal_mysql, tydal_redis, …)
  --infra-network=N shared + docker tier: that stack's Docker network (default:
                    detected from S_mysql, else tydal_default)
  --no-provision    shared only: don't create the database/user now (run
                    tools/deploy/provision-shared.sh once the infra is up)

  -f, --force       skip the confirmation prompt (required in CI / non-interactive)
  --no-force-env    keep an existing backend/.env instead of rewriting it
  -h, --help        show this help

Rewrites backend/.env by default (backup saved, secrets kept) so it matches your
tiers, then toggles compose — hence the confirmation prompt.

e.g.  configure.sh cloud docker   ·   configure.sh ollama-host host -f
      configure.sh cloud docker --infra=shared --name=staging
EOF
}

# Tiers are REQUIRED (no defaults). AI → template + OLLAMA_PLACEMENT; INFRA → app tier.
AI=""
OLLAMA_PLACEMENT=""
INFRA=""
FORCE=false        # -f/--force : skip the confirmation prompt
FORCE_ENV=true     # rewrite backend/.env by default; --no-force-env opts out
INFRA_MODE="own"   # --infra=own|shared (#23)
NAME=""            # --name=NAME (shared only)
SLOT=""            # --slot=N (shared only; default: kept, else derived from NAME)
INFRA_STACK=""     # --infra-stack=S (shared only; default tydal)
INFRA_NETWORK=""   # --infra-network=N (shared + docker; default: detected)
PROVISION=true     # --no-provision skips provision-shared.sh
for arg in "$@"; do
  case "$arg" in
    -h|--help)          usage; exit 0 ;;
    -f|--force)         FORCE=true ;;
    --no-force-env)     FORCE_ENV=false ;;
    --force-env)        FORCE_ENV=true ;;   # explicit (already the default)
    ollama-host|ollama) AI="ollama"; OLLAMA_PLACEMENT="host" ;;   # bare 'ollama' = host (GPU)
    ollama-docker)      AI="ollama"; OLLAMA_PLACEMENT="docker" ;;
    cloud|aicloud)      AI="cloud";  OLLAMA_PLACEMENT="none" ;;
    host|native)        INFRA="host" ;;   # 'native' kept as a deprecated alias
    docker)             INFRA="docker" ;;
    --infra=*)          INFRA_MODE="${arg#--infra=}" ;;
    --name=*)           NAME="${arg#--name=}" ;;
    --slot=*)           SLOT="${arg#--slot=}" ;;
    --infra-stack=*)    INFRA_STACK="${arg#--infra-stack=}" ;;
    --infra-network=*)  INFRA_NETWORK="${arg#--infra-network=}" ;;
    --no-provision)     PROVISION=false ;;
    *) echo "configure.sh: unknown argument '$arg'" >&2; echo >&2; usage >&2; exit 1 ;;
  esac
done
if [ -z "$AI" ] || [ -z "$INFRA" ]; then
  echo "configure.sh: you must specify both an AI tier (cloud|ollama-host|ollama-docker)" >&2
  echo "and an application tier (host|docker). See 'configure.sh --help'." >&2
  echo >&2 
  usage >&2
  exit 1
fi

# --- infrastructure mode (#23) ---
die() { echo "configure.sh: $*" >&2; exit 1; }
case "$INFRA_MODE" in
  own)
    [ -z "$NAME" ] || die "--name is only for --infra=shared (an own-infrastructure installation keeps the default names)."
    [ -z "$SLOT$INFRA_STACK$INFRA_NETWORK" ] || die "--slot/--infra-stack/--infra-network are only for --infra=shared."
    ;;
  shared)
    [ -n "$NAME" ] || die "--infra=shared needs --name=NAME (e.g. --name=staging)."
    # NAME becomes a MySQL database/user (tydal_NAME, tydal_NAME_test), an
    # Elasticsearch prefix (NAME_), a Redis prefix and container names.
    printf '%s' "$NAME" | grep -Eq '^[a-z0-9][a-z0-9_]{0,15}$' \
      || die "--name='$NAME': use lowercase letters, digits and _, starting with a letter or digit, at most 16 characters."
    case "$NAME" in
      test|*_test) die "--name='$NAME' would collide with a test database (tydal_test / tydal_<name>_test)." ;;
      tydal|vault|tydal_*|vault_*|*_tydal|*_vault|*_tydal_*|*_vault_*)
        die "--name='$NAME': 'tydal'/'vault' segments would blur the index prefix (NAME_) with TYDAL's own index names." ;;
    esac
    if [ -n "$SLOT" ]; then
      case "$SLOT" in [1-7]) ;; *) die "--slot='$SLOT': use 1–7." ;; esac
    fi
    INFRA_STACK="${INFRA_STACK:-tydal}"
    printf '%s' "$INFRA_STACK" | grep -Eq '^[a-zA-Z0-9][a-zA-Z0-9_.-]*$' || die "--infra-stack='$INFRA_STACK' isn't a container-name prefix."
    [ "$INFRA_STACK" != "tydal_$NAME" ] || die "--infra-stack can't be this installation itself (tydal_$NAME)."
    ;;
  *) die "--infra='$INFRA_MODE': use own or shared." ;;
esac
if [ "$INFRA_MODE" = "shared" ] && [ "$FORCE_ENV" = false ]; then
  die "--infra=shared rewrites backend/.env with this installation's identity; drop --no-force-env (secrets are kept, a backup is saved)."
fi

# Human-readable label for messages, e.g. "ollama-host (GPU)".
case "$AI/$OLLAMA_PLACEMENT" in
  cloud/*)        AI_LABEL="cloud" ;;
  ollama/host)    AI_LABEL="ollama-host (Ollama on host, GPU)" ;;
  ollama/docker)  AI_LABEL="ollama-docker (Ollama in Docker, CPU)" ;;
esac

case "$AI" in
  ollama) BACKEND_TPL="$TEMPLATES/backend.env.ollama" ;;
  cloud)  BACKEND_TPL="$TEMPLATES/backend.env.aicloud" ;;
esac

# --- confirmation (skipped with -f/--force) ---
if [ "$FORCE" = false ]; then
  echo "configure.sh will set AI tier '$AI_LABEL' + application tier '$INFRA':"
  if [ "$FORCE_ENV" = true ] && [ -f "$ROOT/backend/.env" ]; then
    echo "  • REWRITE backend/.env from the '$AI' template (backup saved, secrets kept)"
  elif [ "$FORCE_ENV" = true ]; then
    echo "  • create backend/.env from the '$AI' template"
  else
    echo "  • leave an existing backend/.env untouched (--no-force-env)"
  fi
  echo "  • toggle the matching docker-compose.yml services"
  if [ "$INFRA_MODE" = "shared" ]; then
    echo "  • SHARED infrastructure from the '$INFRA_STACK' stack, as installation '$NAME':"
    echo "    write the root .env (containers tydal_${NAME}_*, offset ports), point"
    echo "    backend/.env at database/user tydal_$NAME, index prefix ${NAME}_ and its own"
    echo "    Redis DBs/prefix$( [ "$PROVISION" = true ] && echo ", and create that database + user in ${INFRA_STACK}_mysql")"
  elif [ -f "$ROOT/.env" ] && grep -q '^TYDAL_INFRA_MODE=shared' "$ROOT/.env"; then
    echo "  • back to OWN infrastructure: retire the root .env of the shared setup (backup saved)"
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

# Secrets/state carried over when --force-env replaces an existing backend/.env,
# so switching AI backend doesn't lose your keys (or regenerate APP_KEY and
# break existing encrypted data).
PRESERVE_VARS="APP_KEY TYDAL_SUPERADMIN_PASSWORD ANTHROPIC_AUTH_TOKEN ANTHROPIC_API_KEY ANTHROPIC_BASE_URL GEMINI_API_KEY JINA_API_KEY VOYAGE_API_KEY OPENAI_API_KEY ZAI_API_KEY ZAI_BASE_URL ZAI_MODEL"

# preserve_vars OLD NEW : for each PRESERVE_VARS key with a non-empty value in
# OLD, overwrite the matching line in NEW (in place). Values copied verbatim
# (quotes/URLs/base64 preserved). Reports which keys were carried over.
preserve_vars() {
  local old="$1" new="$2" tmp
  tmp="$(mktemp)"
  awk -v keylist="$PRESERVE_VARS" '
    BEGIN { n=split(keylist, ks, " "); for (i=1;i<=n;i++) want[ks[i]]=1 }
    FNR==NR {
      if (match($0, /^[A-Za-z_][A-Za-z0-9_]*=/)) {
        k=substr($0,1,RLENGTH-1); v=substr($0,RLENGTH+1)
        if ((k in want) && v!="" && v!="\"\"") oldval[k]=v
      }
      next
    }
    {
      if (match($0, /^[A-Za-z_][A-Za-z0-9_]*=/)) {
        k=substr($0,1,RLENGTH-1)
        if (k in oldval) { print k "=" oldval[k]; carried[k]=1; next }
      }
      print
    }
    END { for (k in carried) print k > "/dev/stderr" }
  ' "$old" "$new" >"$tmp" 2> >(sort | sed 's/^/  carried over: /' >&2)
  mv "$tmp" "$new"
}

# set_env_var KEY VALUE FILE : set KEY=VALUE in FILE (in place), preserving any
# inline "# comment" that followed the old value. No-op if KEY isn't present.
set_env_var() {
  local key="$1" val="$2" file="$3" tmp
  tmp="$(mktemp)"
  awk -v key="$key" -v val="$val" '
    index($0, key"=") == 1 {
      rest = substr($0, length(key) + 2)
      ci = index(rest, "#")
      if (ci > 0) print key "=" val "   " substr(rest, ci)
      else        print key "=" val
      next
    }
    { print }
  ' "$file" >"$tmp"
  mv "$tmp" "$file"
}

# wire_env_hosts FILE INFRA OLLAMA_PLACEMENT : DB/Redis/ES hosts follow the app
# topology (localhost vs docker service names). OLLAMA_HOST is a 2×2 of app ×
# ollama placement (only meaningful for the ollama backend; harmless for cloud).
wire_env_hosts() {
  local file="$1" infra="$2" ollp="$3" ollama_host
  if [ "$infra" = "docker" ]; then
    set_env_var DB_HOST            "mysql"                       "$file"
    set_env_var REDIS_HOST         "redis"                       "$file"
    set_env_var ELASTICSEARCH_HOST "http://elasticsearch:9200"   "$file"
    set_env_var TIKA_HOST          "http://tika:9998"            "$file"
    # app in Docker: reach Ollama in the docker network, or on the host gateway.
    if [ "$ollp" = "docker" ]; then
      ollama_host="http://ollama:11434"
    else
      ollama_host="http://host.docker.internal:11434"
    fi
  else
    set_env_var DB_HOST            "127.0.0.1"                    "$file"
    set_env_var REDIS_HOST         "127.0.0.1"                    "$file"
    set_env_var ELASTICSEARCH_HOST "http://localhost:9200"        "$file"
    set_env_var TIKA_HOST          "http://localhost:9998"        "$file"
    # app on host: host Ollama and the port-mapped docker Ollama are both at localhost.
    ollama_host="http://localhost:11434"
  fi
  set_env_var OLLAMA_HOST "$ollama_host" "$file"
  echo "  wired connection hosts for app=$infra, ollama=$ollp (OLLAMA_HOST=$ollama_host)"
}

# toggle_compose_region FILE NAME on|off : comment/uncomment the lines between
# the "# >>> TYDAL-TOGGLE: NAME >>>" and "# <<< TYDAL-TOGGLE: NAME <<<" markers.
# Toggled lines are stored "hash-first" (a single leading '#' before the fully
# indented YAML), so on=strip one '#', off=add one '#'. Idempotent.
toggle_compose_region() {
  local file="$1" name="$2" state="$3" tmp
  tmp="$(mktemp)"
  awk -v name="$name" -v state="$state" '
    $0 ~ ">>> TYDAL-TOGGLE: " name " >>>" { inregion=1; print; next }
    $0 ~ "<<< TYDAL-TOGGLE: " name " <<<" { inregion=0; print; next }
    inregion {
      if (state == "on") { sub(/^#/, ""); print; next }
      else { if ($0 !~ /^#/) $0 = "#" $0; print; next }
    }
    { print }
  ' "$file" >"$tmp"
  mv "$tmp" "$file"
}

# upsert_env_var KEY VALUE FILE : like set_env_var, but a key the template only
# carries commented out ("#KEY=…") is uncommented first, and a missing key is
# appended. For the shared-infrastructure keys, which templates leave commented.
upsert_env_var() {
  local key="$1" val="$2" file="$3" tmp
  if ! grep -q "^${key}=" "$file"; then
    if grep -q "^#${key}=" "$file"; then
      tmp="$(mktemp)"
      awk -v key="$key" '!done && index($0, "#" key "=") == 1 { $0 = substr($0, 2); done = 1 } { print }' "$file" >"$tmp"
      mv "$tmp" "$file"
    else
      printf '%s=%s\n' "$key" "$val" >>"$file"
      return
    fi
  fi
  set_env_var "$key" "$val" "$file"
}

# env_get KEY FILE : print KEY's value (inline comment + surrounding quotes
# stripped). Empty if KEY is absent or commented out.
env_get() {
  awk -v k="$1" 'index($0, k"=") == 1 {
    v = substr($0, length(k) + 2)
    sub(/[[:space:]]*#.*/, "", v)                 # strip inline comment
    gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)    # trim
    gsub(/^"|"$/, "", v)                          # strip surrounding quotes
    print v; exit
  }' "$2"
}

# embed_signature FILE : a fingerprint of the embedding identity. If this
# changes between two .env files, stored vectors are stale (different model,
# driver, or dimension count) and the search index must be rebuilt + re-embedded.
embed_signature() {
  local f="$1"
  printf '%s|%s|%s|%s|%s' \
    "$(env_get EMBEDDING_DRIVER "$f")" \
    "$(env_get EMBEDDING_DIMENSIONS "$f")" \
    "$(env_get OLLAMA_EMBED_MODEL "$f")" \
    "$(env_get JINA_EMBED_MODEL "$f")" \
    "$(env_get VOYAGE_EMBED_MODEL "$f")"
}

# Set CONFIGURED_NEW_ENV=true when a fresh backend/.env was written, so callers
# (install.sh) can prompt to fill in keys. Exported via the variable below.
CONFIGURED_NEW_ENV=false
# Set when --force-env rewrote .env with a different embedding model/driver/dims
# than before — old vectors are stale and the index must be rebuilt.
EMBED_CHANGED=false

# --- backend/.env (rewritten by default; --no-force-env keeps an existing one) ---
if [ -f "$ROOT/backend/.env" ] && [ "$FORCE_ENV" = false ]; then
  echo "backend/.env exists and --no-force-env was given — leaving it untouched."
  echo "  Re-run without --no-force-env to rewrite it for the '$AI' tier (secrets"
  echo "  kept, backup saved). The docker-compose.yml toggles below still apply."
else
  BACKUP=""
  if [ -f "$ROOT/backend/.env" ]; then
    BACKUP="$ROOT/backend/.env.bak.$(date +%Y%m%d-%H%M%S)"
    cp "$ROOT/backend/.env" "$BACKUP"
    echo "Backed up existing backend/.env → $(basename "$BACKUP")"
  fi
  cp "$BACKEND_TPL" "$ROOT/backend/.env"
  CONFIGURED_NEW_ENV=true
  echo "Wrote backend/.env from $(basename "$BACKEND_TPL") (AI backend: $AI_LABEL)."
  if [ -n "$BACKUP" ]; then
    preserve_vars "$BACKUP" "$ROOT/backend/.env"
    # Compare the embedding identity old↔new; if it changed, the stored vectors
    # are stale and we must remind the user to rebuild + re-embed the index.
    if [ "$(embed_signature "$BACKUP")" != "$(embed_signature "$ROOT/backend/.env")" ]; then
      EMBED_CHANGED=true
    fi
  fi
  wire_env_hosts "$ROOT/backend/.env" "$INFRA" "$OLLAMA_PLACEMENT"
fi

# --- shared infrastructure: this installation's identity (#23) ---
# Everything that must differ between installations sharing one MySQL / ES /
# Redis is derived from NAME and a small slot number (1–7):
#   MySQL   database + user tydal_NAME (test database tydal_NAME_test)
#   ES      ELASTICSEARCH_INDEX_PREFIX=NAME_
#   Redis   REDIS_PREFIX=tydal_NAME_ on every key (queues, cache, sessions,
#           locks) AND its own DB numbers REDIS_DB=2·slot, REDIS_CACHE_DB=2·slot+1
#           (an own-infrastructure install keeps 0/1)
#   ports   app 8000+100·slot, Vite 3005+100·slot
#   names   containers tydal_NAME_*, Compose project tydal_NAME
if [ "$INFRA_MODE" = "shared" ]; then
  ENV_OUT="$ROOT/backend/.env"
  SLOT_NOTE="from --slot"
  if [ -z "$SLOT" ] && [ -f "$ROOT/.env" ] && [ "$(env_get TYDAL_INSTALLATION "$ROOT/.env")" = "$NAME" ]; then
    SLOT="$(env_get TYDAL_SLOT "$ROOT/.env")"
    case "$SLOT" in [1-7]) SLOT_NOTE="kept from the previous configuration" ;; *) SLOT="" ;; esac
  fi
  if [ -z "$SLOT" ]; then
    SLOT=$(( $(printf '%s' "$NAME" | cksum | cut -d' ' -f1) % 7 + 1 ))
    SLOT_NOTE="derived from the name — pass --slot=N if it clashes with another installation"
  fi
  HTTP_PORT=$((8000 + 100 * SLOT))
  VITE_PORT=$((3005 + 100 * SLOT))
  STACK="tydal_$NAME"

  # Keep the database password across re-runs (the user already exists in the
  # shared MySQL with it); generate one the first time.
  DB_PASS=""
  if [ -n "${BACKUP:-}" ] && [ "$(env_get DB_USERNAME "$BACKUP")" = "$STACK" ]; then
    DB_PASS="$(env_get DB_PASSWORD "$BACKUP")"
    printf '%s' "$DB_PASS" | grep -Eq '^[A-Za-z0-9]{12,}$' || DB_PASS=""
  fi
  [ -n "$DB_PASS" ] || DB_PASS="$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')"

  upsert_env_var DB_DATABASE                "$STACK"                 "$ENV_OUT"
  upsert_env_var DB_USERNAME                "$STACK"                 "$ENV_OUT"
  upsert_env_var DB_PASSWORD                "$DB_PASS"               "$ENV_OUT"
  upsert_env_var ELASTICSEARCH_INDEX_PREFIX "${NAME}_"               "$ENV_OUT"
  upsert_env_var REDIS_PREFIX               "${STACK}_"              "$ENV_OUT"
  upsert_env_var REDIS_DB                   "$((2 * SLOT))"          "$ENV_OUT"
  upsert_env_var REDIS_CACHE_DB             "$((2 * SLOT + 1))"      "$ENV_OUT"
  upsert_env_var SESSION_COOKIE             "${STACK}_session"       "$ENV_OUT"
  upsert_env_var APP_URL                    "http://localhost:$HTTP_PORT" "$ENV_OUT"
  upsert_env_var SANCTUM_STATEFUL_DOMAINS   "localhost:$HTTP_PORT,127.0.0.1:$HTTP_PORT" "$ENV_OUT"
  echo "  shared installation '$NAME' (slot $SLOT, $SLOT_NOTE): database/user $STACK,"
  echo "  index prefix ${NAME}_, Redis prefix ${STACK}_ in DBs $((2 * SLOT))/$((2 * SLOT + 1)), app :$HTTP_PORT, Vite :$VITE_PORT"

  # The infrastructure stack's Docker network (docker tier only: host-tier
  # processes reach the infrastructure on its published localhost ports).
  if [ "$INFRA" = "docker" ] && [ -z "$INFRA_NETWORK" ]; then
    INFRA_NETWORK="$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "${INFRA_STACK}_mysql" 2>/dev/null | awk '{print $1}' || true)"
    if [ -n "$INFRA_NETWORK" ]; then
      echo "  infrastructure network: $INFRA_NETWORK (detected from ${INFRA_STACK}_mysql)"
    else
      INFRA_NETWORK="tydal_default"
      echo "  infrastructure network: $INFRA_NETWORK (default — ${INFRA_STACK}_mysql isn't running;"
      echo "    pass --infra-network=NET if that stack's Compose project isn't 'tydal')"
    fi
  fi

  # Root .env — Compose's project env file. docker-compose.yml interpolates it;
  # tools/lib/stack.lib.sh reads it for the scripts.
  if [ -f "$ROOT/.env" ]; then
    ROOT_BACKUP="$ROOT/.env.bak.$(date +%Y%m%d-%H%M%S)"
    cp "$ROOT/.env" "$ROOT_BACKUP"
    echo "Backed up existing root .env → $(basename "$ROOT_BACKUP")"
  fi
  {
    echo "# Written by tools/deploy/configure.sh — shared-infrastructure installation (#23)."
    echo "# Compose reads it for docker-compose.yml; the tools/ scripts read it through"
    echo "# tools/lib/stack.lib.sh. Re-run configure.sh to change it."
    echo "#"
    echo "# Own Compose project, so this checkout's containers never mix with another's."
    echo "COMPOSE_PROJECT_NAME=$STACK"
    echo "# own | shared — shared: the infrastructure runs in another stack."
    echo "TYDAL_INFRA_MODE=shared"
    echo "# The --name: derives the database/user, index prefix and Redis prefix."
    echo "TYDAL_INSTALLATION=$NAME"
    echo "# 1–7: port offset (100·slot) and Redis DBs (2·slot, 2·slot+1)."
    echo "TYDAL_SLOT=$SLOT"
    echo "# Container-name prefix of this installation (\${TYDAL_STACK}_app, _queue, _scheduler, _vite)."
    echo "TYDAL_STACK=$STACK"
    echo "# Host ports: app (artisan serve or the app container) and the Vite dev server."
    echo "TYDAL_HTTP_PORT=$HTTP_PORT"
    echo "TYDAL_VITE_PORT=$VITE_PORT"
    echo "# Container-name prefix of the stack running MySQL/ES/Kibana/Tika/Redis."
    echo "TYDAL_INFRA_STACK=$INFRA_STACK"
    echo "# Infrastructure services only start under this profile — never, here."
    echo "TYDAL_INFRA_PROFILE=infra"
    echo "# depends_on on the infrastructure isn't required (it lives in the other project)."
    echo "TYDAL_INFRA_REQUIRED=false"
    if [ "$INFRA" = "docker" ]; then
      echo "# Join the infrastructure stack's network, so mysql, elasticsearch, redis and"
      echo "# tika resolve by service name from the app containers."
      echo "TYDAL_NETWORK=$INFRA_NETWORK"
      echo "TYDAL_NETWORK_EXTERNAL=true"
    fi
  } >"$ROOT/.env"
  echo "Wrote the root .env (Compose project $STACK, containers ${STACK}_*)."
elif [ -f "$ROOT/.env" ] && grep -q '^TYDAL_INFRA_MODE=shared' "$ROOT/.env"; then
  # Back to own infrastructure: the shared setup's root .env would keep the
  # infrastructure services switched off and the names/ports offset.
  ROOT_BACKUP="$ROOT/.env.bak.$(date +%Y%m%d-%H%M%S)"
  mv "$ROOT/.env" "$ROOT_BACKUP"
  echo "Retired the shared-infrastructure root .env → $(basename "$ROOT_BACKUP") (own infrastructure, default names)."
  RETIRED_SHARED=true
fi

# --- frontend/.env (copy-if-missing; backend-agnostic) ---
if [ -f "$ROOT/frontend/.env" ]; then
  echo "frontend/.env exists — leaving it untouched$( [ "$INFRA_MODE" = shared ] || [ "${RETIRED_SHARED:-false}" = true ] && echo ' (apart from the API port)')."
else
  cp "$TEMPLATES/frontend.env" "$ROOT/frontend/.env"
  echo "Created frontend/.env."
fi
# The SPA calls the API on the app's host port, which a shared installation offsets.
if [ "$INFRA_MODE" = "shared" ]; then
  upsert_env_var VITE_API_BASE_URL "http://localhost:$HTTP_PORT/api/v1" "$ROOT/frontend/.env"
elif [ "${RETIRED_SHARED:-false}" = true ]; then
  upsert_env_var VITE_API_BASE_URL "http://localhost:8000/api/v1" "$ROOT/frontend/.env"
fi

# --- docker-compose.yml service toggles ---
# The app/queue/scheduler services follow the app topology; the ollama service
# follows the AI arg (ollama-docker) — the two are independent, so e.g.
# app=docker with ollama on the host enables app/queue/scheduler but NOT the
# ollama container.
ENABLED=()
if [ "$INFRA" = "docker" ]; then
  toggle_compose_region "$COMPOSE" "docker-app" on
  ENABLED+=("app + queue + scheduler")
else
  toggle_compose_region "$COMPOSE" "docker-app" off
fi
if [ "$OLLAMA_PLACEMENT" = "docker" ]; then
  toggle_compose_region "$COMPOSE" "ollama"        on
  toggle_compose_region "$COMPOSE" "ollama-volume" on
  ENABLED+=("ollama")
else
  toggle_compose_region "$COMPOSE" "ollama"        off
  toggle_compose_region "$COMPOSE" "ollama-volume" off
fi
if [ "${#ENABLED[@]}" -eq 0 ]; then
  echo "docker-compose.yml: backing services only (app/queue/scheduler/ollama disabled)."
else
  joined="$(printf '%s, ' "${ENABLED[@]}")"; joined="${joined%, }"
  echo "docker-compose.yml: enabled $joined (+ backing services)."
fi
if [ "$INFRA_MODE" = "shared" ]; then
  echo "  (shared infrastructure: the backing services$( [ "$OLLAMA_PLACEMENT" = docker ] && echo ' and ollama') come from the '$INFRA_STACK' stack —"
  echo "   this checkout's own copies stay off via TYDAL_INFRA_PROFILE in the root .env)"
fi

# --- provision the shared MySQL (database, test database, user) ---
if [ "$INFRA_MODE" = "shared" ]; then
  echo
  if [ "$PROVISION" = false ]; then
    echo "Skipped provisioning (--no-provision). Once ${INFRA_STACK}_mysql is up:"
    echo "  tools/deploy/provision-shared.sh"
  elif [ "$(docker inspect -f '{{.State.Running}}' "${INFRA_STACK}_mysql" 2>/dev/null)" = "true" ]; then
    "$SCRIPT_DIR/provision-shared.sh" \
      || echo "⚠️  Provisioning failed — fix the cause above, then re-run tools/deploy/provision-shared.sh" >&2
  else
    echo "⚠️  ${INFRA_STACK}_mysql isn't running, so the database and user weren't created yet."
    echo "   Start the infrastructure stack (in its checkout: tools/deploy/start.sh), then:"
    echo "     tools/deploy/provision-shared.sh"
  fi
fi

# --- next-step hint (per application tier) ---
echo
echo "Next — start.sh owns startup, so run it FIRST (then the rest in another terminal):"
if [ "$INFRA_MODE" = "shared" ]; then
  APP_C="tydal_${NAME}_app"; SHOW_HTTP="$HTTP_PORT"; SHOW_VITE="$VITE_PORT"
else
  APP_C="tydal_app"; SHOW_HTTP=8000; SHOW_VITE=3005
fi
if [ "$INFRA" = "docker" ]; then
  echo "  tools/deploy/start.sh      # bring up the stack + serve (app :$SHOW_HTTP in a container + Vite :$SHOW_VITE)"
  echo "  tools/deploy/reload.sh     # if the stack was already up, to apply this .env change"
  if [ "$CONFIGURED_NEW_ENV" = true ]; then
    echo "  # first run without install.sh? install deps in the container once it's up:"
    echo "  #   docker exec -it $APP_C composer install && docker exec -it $APP_C php artisan key:generate"
  fi
else
  echo "  tools/deploy/start.sh      # bring up backing services + serve (app :$SHOW_HTTP + queue + scheduler + Vite :$SHOW_VITE on host)"
  echo "  tools/deploy/reload.sh     # if the stack was already up, to apply this .env change"
fi

# --- ollama placement reminder ---
if [ "$OLLAMA_PLACEMENT" = "host" ]; then
  echo "  Ollama runs on the host (GPU) — make sure it's up: brew services start ollama"
elif [ "$OLLAMA_PLACEMENT" = "docker" ]; then
  OLLAMA_C="$( [ "$INFRA_MODE" = shared ] && echo "${INFRA_STACK}_ollama" || echo tydal_ollama )"
  echo "  Ollama runs in Docker (CPU) — pull models once it's up:"
  echo "    docker exec $OLLAMA_C ollama pull mxbai-embed-large && \\"
  echo "    docker exec $OLLAMA_C ollama pull llama3.2 && \\"
  echo "    docker exec $OLLAMA_C ollama pull llama3.2-vision"
fi

# --- embedding-changed warning (stale vectors) ---
if [ "$EMBED_CHANGED" = true ]; then
  echo
  echo "⚠️  Embedding model/driver/dimensions changed — existing vectors are now"
  echo "    stale (a different vector space). Restart the workers so they reload"
  echo "    the new config, then rebuild + re-embed the search index:"
  if [ "$INFRA" = "docker" ]; then
    echo "      docker compose down --remove-orphans && docker compose up -d"
  else
    echo "      # restart tools/deploy/start.sh (Ctrl-C, re-run) so the worker reloads"
  fi
  echo "      tools/deploy/reindex.sh    # NON-destructive: recreate index + reindex + embed"
  echo "    (Your database is untouched. Use first_install.sh only if you also want to"
  echo "     reset + reseed the DB — that is destructive.)"
fi

# When sourced by install.sh, expose whether a fresh backend/.env was written.
export CONFIGURED_NEW_ENV
