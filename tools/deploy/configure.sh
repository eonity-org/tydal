#!/usr/bin/env bash
# TYDAL — set the AI tier + application tier in backend/.env + docker-compose.yml.
# Config only: no deps/build, nothing started. See tools/deploy/README.md for the
# three-tier model. Re-run any time to switch tiers without rebuilding.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
TEMPLATES="$SCRIPT_DIR/env.templates"
COMPOSE="$ROOT/docker-compose.yml"

usage() {
  cat <<'EOF'
Usage: configure.sh <cloud|ollama-host|ollama-docker> <host|docker> [-f] [--no-force-env]

Writes backend/.env and toggles docker-compose.yml to match two tiers (config
only — no build). Backing services (ES/Tika/MySQL/Redis) are always Docker.
Both tier args are REQUIRED.

  AI  tier   cloud | ollama-host (host, GPU) | ollama-docker (CPU only)
  APP tier   host (PHP/nginx + queue on the host) | docker (in containers)

  -f, --force       skip the confirmation prompt (required in CI / non-interactive)
  --no-force-env    keep an existing backend/.env instead of rewriting it
  -h, --help        show this help

Rewrites backend/.env by default (backup saved, secrets kept) so it matches your
tiers, then toggles compose — hence the confirmation prompt.

e.g.  configure.sh cloud docker   ·   configure.sh ollama-host host -f
EOF
}

# Tiers are REQUIRED (no defaults). AI → template + OLLAMA_PLACEMENT; INFRA → app tier.
AI=""
OLLAMA_PLACEMENT=""
INFRA=""
FORCE=false        # -f/--force : skip the confirmation prompt
FORCE_ENV=true     # rewrite backend/.env by default; --no-force-env opts out
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
    *) echo "configure.sh: unknown argument '$arg'" >&2; echo >&2; usage >&2; exit 1 ;;
  esac
done
if [ -z "$AI" ] || [ -z "$INFRA" ]; then
  echo "configure.sh: you must specify both an AI tier (cloud|ollama-host|ollama-docker)" >&2
  echo "and an application tier (host|docker). See 'configure.sh --help'." >&2
  echo >&2 
  #usage >&2 
  exit 1
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

# --- frontend/.env (copy-if-missing; backend-agnostic) ---
if [ -f "$ROOT/frontend/.env" ]; then
  echo "frontend/.env exists — leaving it untouched."
else
  cp "$TEMPLATES/frontend.env" "$ROOT/frontend/.env"
  echo "Created frontend/.env."
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

# --- next-step hint (per application tier) ---
echo
echo "Next — start.sh owns startup, so run it FIRST (then the rest in another terminal):"
if [ "$INFRA" = "docker" ]; then
  echo "  tools/deploy/start.sh      # bring up the stack + serve (app :8000 in a container + Vite)"
  echo "  tools/deploy/reload.sh     # if the stack was already up, to apply this .env change"
  if [ "$CONFIGURED_NEW_ENV" = true ]; then
    echo "  # first run without install.sh? install deps in the container once it's up:"
    echo "  #   docker exec -it tydal_app composer install && docker exec -it tydal_app php artisan key:generate"
  fi
else
  echo "  tools/deploy/start.sh      # bring up backing services + serve (app + queue + scheduler + Vite on host)"
  echo "  tools/deploy/reload.sh     # if the stack was already up, to apply this .env change"
fi

# --- ollama placement reminder ---
if [ "$OLLAMA_PLACEMENT" = "host" ]; then
  echo "  Ollama runs on the host (GPU) — make sure it's up: brew services start ollama"
elif [ "$OLLAMA_PLACEMENT" = "docker" ]; then
  echo "  Ollama runs in Docker (CPU) — pull models once it's up:"
  echo "    docker exec tydal_ollama ollama pull mxbai-embed-large && \\"
  echo "    docker exec tydal_ollama ollama pull llama3.2 && \\"
  echo "    docker exec tydal_ollama ollama pull llama3.2-vision"
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
