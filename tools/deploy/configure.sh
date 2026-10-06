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
                    [--infra=own|shared] [--name=NAME] [--url=URL] [-f] [--no-force-env]
                    [--prod|--dev] [--data-dir=DIR]
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
  --name=NAME       (--name alone implies --infra=shared). NAME: lowercase letters/digits/_ , starts with a letter or
                    digit, at most 16 chars. Derives database + user tydal_NAME
                    (test DB tydal_NAME_test), index prefix NAME_, Redis prefix
                    tydal_NAME_ + its own Redis DBs, containers tydal_NAME_*,
                    and offset ports. Writes the root .env (Compose's).
  --slot=N          shared only: 1–7, picks the port offset (app 8000+100·N,
                    Vite 3005+100·N) and Redis DBs (2N, 2N+1). Default: kept
                    from a previous run, else the lowest free slot. A slot whose
                    ports or Redis DBs another installation uses is refused.
                    Pass it explicitly for installations you keep, so their
                    ports never depend on what else runs.
  --infra-stack=S   shared only: container-name prefix of the stack running the
                    infrastructure (default tydal → tydal_mysql, tydal_redis, …)
  --infra-network=N shared + docker tier: that stack's Docker network (default:
                    detected from S_mysql, else tydal_default)
  --no-provision    shared only: don't create the database/user now (run
                    tools/deploy/provision-shared.sh once the infra is up)

Public URL (own or shared):
  --url=URL         the address people use for this installation, e.g.
                    https://staging.example.org (scheme + host[:port], no path).
                    Sets APP_URL, SANCTUM_STATEFUL_DOMAINS and the SPA's
                    VITE_API_BASE_URL (URL/api/v1; rebuild the frontend). Kept
                    in backend/.env as TYDAL_PUBLIC_URL, so later runs keep it;
                    --url= (empty) goes back to localhost. A web server must
                    serve the built SPA there and route /api, /v, /vault to
                    the app — nginx/TLS stay yours (DEPLOYMENT.md). Not for
                    the Vite dev server, which doesn't proxy /api.

Production (docker tier; DEPLOYMENT.md "Production with the deploy scripts"):
  --prod            a server installation: every port published on 127.0.0.1
                    only (nginx on the host is the way in), APP_ENV=production
                    and debug off, TRUSTED_PROXIES=*, generated MySQL/Redis
                    passwords (own infrastructure), Kibana off, queue and
                    scheduler as uid 1000. Needs a public URL (--url, or one
                    kept from before). Writes nginx-site.conf for the host's
                    nginx. Kept on later runs; --dev goes back.
  --data-dir=DIR    with --prod: keep the data in host directories under DIR
                    instead of Docker volumes — DIR/mysql, DIR/elasticsearch,
                    DIR/redis (own infrastructure) and DIR/<stack>/storage
                    (uploads, logs). Created with the right owners (sudo when
                    not root). Kept on later runs.

  -f, --force       skip the confirmation prompt (required in CI / non-interactive)
  --no-force-env    keep an existing backend/.env instead of rewriting it
  --check           only validate the arguments and the machine (name, slot,
                    a clash with another installation's containers), print
                    the plan and exit — nothing is written (install.sh runs
                    it before asking anything)
  -h, --help        show this help

Rewrites backend/.env by default (backup saved, secrets kept) so it matches your
tiers, then toggles compose — hence the confirmation prompt.

e.g.  configure.sh cloud docker   ·   configure.sh ollama-host host -f
      configure.sh cloud docker --infra=shared --name=staging
      configure.sh cloud docker --infra=shared --name=staging --url=https://staging.example.org
      configure.sh cloud docker --prod --url=https://tydal.example.org --data-dir=/srv/tydal-data
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
SLOT=""            # --slot=N (shared only; default: kept, else the lowest free one)
INFRA_STACK=""     # --infra-stack=S (shared only; default tydal)
INFRA_NETWORK=""   # --infra-network=N (shared + docker; default: detected)
PROVISION=true     # --no-provision skips provision-shared.sh
URL_SET=false      # --url given (even empty: --url= clears a kept one)
URL_ARG=""         # --url=URL
INFRA_GIVEN=false  # --infra= given explicitly (else --name implies shared)
CHECK=false        # --check: validate + resolve, print the plan, change nothing
PROD_FLAG=""       # --prod → on, --dev → off, neither → kept from the root .env
DATA_DIR_SET=false # --data-dir given
DATA_DIR_ARG=""    # --data-dir=DIR
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
    --infra=*)          INFRA_MODE="${arg#--infra=}"; INFRA_GIVEN=true ;;
    --name=*)           NAME="${arg#--name=}" ;;
    --slot=*)           SLOT="${arg#--slot=}" ;;
    --infra-stack=*)    INFRA_STACK="${arg#--infra-stack=}" ;;
    --infra-network=*)  INFRA_NETWORK="${arg#--infra-network=}" ;;
    --no-provision)     PROVISION=false ;;
    --url=*)            URL_SET=true; URL_ARG="${arg#--url=}" ;;
    --check)            CHECK=true ;;
    --prod)             PROD_FLAG=on ;;
    --dev)              PROD_FLAG=off ;;
    --data-dir=*)       DATA_DIR_SET=true; DATA_DIR_ARG="${arg#--data-dir=}" ;;
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
# A name only means something for a shared installation: --name=NAME alone
# implies --infra=shared (an explicit --infra=own with a name stays an error).
if [ -n "$NAME" ] && [ "$INFRA_GIVEN" = false ]; then
  INFRA_MODE="shared"
fi
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
# --url: scheme + host[:port]; a trailing slash is dropped, a path refused (the
# API lives at URL/api/v1 and the SPA at the root).
URL_ARG="${URL_ARG%/}"
if [ -n "$URL_ARG" ]; then
  printf '%s' "$URL_ARG" | grep -Eq '^https?://[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?(:[0-9]{1,5})?$' \
    || die "--url='$URL_ARG': use scheme + host[:port] with no path, e.g. https://staging.example.org"
fi
if [ "$INFRA_MODE" = "shared" ] && [ "$FORCE_ENV" = false ]; then
  die "--infra=shared rewrites backend/.env with this installation's identity; drop --no-force-env (secrets are kept, a backup is saved)."
fi

# --- production (--prod / --dev, kept as TYDAL_ENV in the root .env) ---
PREV_ROOT_ENV=""
[ -f "$ROOT/.env" ] && PREV_ROOT_ENV="$ROOT/.env"
prev_root() { [ -n "$PREV_ROOT_ENV" ] && env_get "$1" "$PREV_ROOT_ENV" || true; }
case "$PROD_FLAG" in
  on)  PROD=true ;;
  off) PROD=false ;;
  *)   PROD=false; [ "$(prev_root TYDAL_ENV)" = "production" ] && PROD=true ;;
esac
DATA_DIR=""
if [ "$DATA_DIR_SET" = true ]; then
  DATA_DIR="${DATA_DIR_ARG%/}"
elif [ "$PROD" = true ]; then
  DATA_DIR="$(prev_root TYDAL_DATA_DIR)"
fi
if [ "$PROD" = true ]; then
  [ "$INFRA" = "docker" ] || die "--prod runs the application in containers: use the docker application tier (configure.sh <ai> docker --prod)."
  [ "$FORCE_ENV" = true ] || die "--prod rewrites backend/.env for production; drop --no-force-env (secrets are kept, a backup is saved)."
  if [ "$URL_SET" = true ]; then
    [ -n "$URL_ARG" ] || die "--prod needs a public URL; --url= would drop it."
  elif [ -z "$( [ -f "$ROOT/backend/.env" ] && env_get TYDAL_PUBLIC_URL "$ROOT/backend/.env" )" ]; then
    die "--prod needs the public URL people will use: add --url=https://your-host.example.org"
  fi
  if [ -n "$DATA_DIR" ]; then
    case "$DATA_DIR" in /*) ;; *) die "--data-dir='$DATA_DIR': use an absolute path." ;; esac
  fi
else
  [ -z "$DATA_DIR" ] || die "--data-dir is for --prod (development keeps its data in Docker volumes)."
fi

# --- shared: the slot (ports + Redis DBs), resolved before anything is written ---
# Order: --slot=N, else the slot this installation already had (root .env),
# else the lowest free one. "Free" means no other container publishes its
# ports, nothing else listens on them, and its Redis DBs hold no other
# installation's keys. An explicit or new slot that's taken is refused; a kept
# one only warns (it's this installation's own, the other one moved in).
if [ "$INFRA_MODE" = "shared" ]; then
  STACK="tydal_$NAME"

  # slot_taken N : print who uses slot N (empty = free). Docker and Redis are
  # asked when reachable; without Docker only the port probe runs.
  slot_taken() {
    local n="$1" http=$((8000 + 100 * $1)) vite=$((3005 + 100 * $1)) port line who db key
    if [ "$DOCKER_OK" = true ]; then
      # Containers of other Compose projects publishing either port (stopped
      # ones too: they get the port back on start).
      while IFS='|' read -r who proj ports; do
        [ "$proj" = "$STACK" ] && continue
        for port in $ports; do
          if [ "$port" = "$http" ] || [ "$port" = "$vite" ]; then
            echo "port $port is published by container ${who#/}"; return
          fi
        done
      done < <(docker ps -aq | xargs docker inspect -f '{{.Name}}|{{index .Config.Labels "com.docker.compose.project"}}|{{range $p, $b := .HostConfig.PortBindings}}{{range $b}}{{.HostPort}} {{end}}{{end}}' 2>/dev/null || true)
      # Another installation's keys in this slot's Redis DBs.
      if [ "$REDIS_OK" = true ]; then
        for db in $((2 * n)) $((2 * n + 1)); do
          key="$(docker exec "${INFRA_STACK}_redis" sh -c '[ -n "${REDIS_PASSWORD:-}" ] && export REDISCLI_AUTH="$REDIS_PASSWORD"; exec redis-cli -n "$1" --scan --count 1000' sh "$db" 2>/dev/null \
                   | grep -v "^${STACK}_" | head -1 || true)"
          if [ -n "$key" ]; then echo "Redis DB $db holds another installation's keys (e.g. '$key')"; return; fi
        done
      fi
    fi
    # Something else (artisan serve / Vite on the host, any other program).
    if [ "$PORTS_MINE" != "$n" ]; then
      for port in "$http" "$vite"; do
        if (exec 3<>"/dev/tcp/127.0.0.1/$port") 2>/dev/null; then
          echo "port $port is in use on this machine"; return
        fi
      done
    fi
  }

  DOCKER_OK=false; REDIS_OK=false
  if docker info >/dev/null 2>&1; then
    DOCKER_OK=true
    [ "$(docker inspect -f '{{.State.Running}}' "${INFRA_STACK}_redis" 2>/dev/null)" = "true" ] && REDIS_OK=true
  fi
  KEPT_SLOT=""
  if [ -f "$ROOT/.env" ] && [ "$(env_get TYDAL_INSTALLATION "$ROOT/.env")" = "$NAME" ]; then
    KEPT_SLOT="$(env_get TYDAL_SLOT "$ROOT/.env")"
    case "$KEPT_SLOT" in [1-7]) ;; *) KEPT_SLOT="" ;; esac
  fi
  # This installation's own processes may hold its kept slot's ports (a re-run
  # while it's up): the port probe doesn't count them as someone else.
  PORTS_MINE="$KEPT_SLOT"

  if [ -n "$SLOT" ]; then
    SLOT_NOTE="from --slot"
    TAKEN="$(slot_taken "$SLOT")"
    [ -z "$TAKEN" ] || die "--slot=$SLOT is taken: $TAKEN. Pick another slot (1–7) or stop that installation."
  elif [ -n "$KEPT_SLOT" ]; then
    SLOT="$KEPT_SLOT"; SLOT_NOTE="kept from the previous configuration"
    TAKEN="$(slot_taken "$SLOT")"
    [ -z "$TAKEN" ] || echo "⚠️  slot $SLOT (kept) is also used elsewhere: $TAKEN — pass --slot=N to move." >&2
  else
    for n in 1 2 3 4 5 6 7; do
      if [ -z "$(slot_taken "$n")" ]; then SLOT="$n"; break; fi
    done
    [ -n "$SLOT" ] || die "no free slot (1–7): every slot's ports or Redis DBs are in use. Stop an installation or pass --slot=N."
    SLOT_NOTE="the lowest free slot; kept on later runs"
  fi
  [ "$DOCKER_OK" = true ] || SLOT_NOTE="$SLOT_NOTE; Docker unreachable, only the ports were checked"
  [ "$DOCKER_OK" = false ] || [ "$REDIS_OK" = true ] || SLOT_NOTE="$SLOT_NOTE; ${INFRA_STACK}_redis not running, its DBs weren't checked"
  HTTP_PORT=$((8000 + 100 * SLOT))
  VITE_PORT=$((3005 + 100 * SLOT))
fi

# --- own: the fixed names must be free (or this checkout's own) ---
# An own-infrastructure installation uses tydal_mysql, tydal_app, … — if another
# Compose project already holds one, `docker compose up` would fail halfway
# with a name conflict. Stop here instead and say whose they are.
if [ "$INFRA_MODE" = "own" ] && docker info >/dev/null 2>&1; then
  for svc in mysql elasticsearch redis kibana tika app queue scheduler vite ollama; do
    c="tydal_$svc"
    dir="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project.working_dir"}}' "$c" 2>/dev/null || true)"
    [ -n "$(docker ps -aq --filter "name=^${c}\$")" ] || continue
    [ "$dir" = "$ROOT" ] && continue
    proj="$(docker inspect -f '{{index .Config.Labels "com.docker.compose.project"}}' "$c" 2>/dev/null || true)"
    {
      echo "configure.sh: container $c already exists$( [ -n "$proj" ] && echo " — Compose project '$proj'")$( [ -n "$dir" ] && echo " in $dir")."
      echo "  Only one installation per machine can run its own infrastructure under the"
      echo "  default names. Either join it as a shared installation:"
      echo "    --infra=shared --name=NAME     (e.g. --name=dev)"
      echo "  or stop it first$( [ -n "$dir" ] && echo ": (cd $dir && docker compose down)")."
    } >&2
    exit 1
  done
fi

if [ "$CHECK" = true ]; then
  if [ "$INFRA_MODE" = "shared" ]; then
    echo "Check OK: shared installation '$NAME' on the '$INFRA_STACK' stack — slot $SLOT ($SLOT_NOTE): app :$HTTP_PORT, Vite :$VITE_PORT."
  else
    echo "Check OK: own infrastructure (tydal_* containers, app :8000, Vite :3005)."
  fi
  [ "$PROD" = true ] && echo "  production$( [ -n "$DATA_DIR" ] && echo ", data in $DATA_DIR")"
  exit 0
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
  if [ "$PROD" = true ]; then
    echo "  • PRODUCTION: ports on 127.0.0.1 only, APP_ENV=production, debug off$( [ "$INFRA_MODE" = own ] && echo ', generated MySQL/Redis passwords, Kibana off')"
    [ -n "$DATA_DIR" ] && echo "    data in $DATA_DIR (created with the right owners; sudo if needed)"
    echo "    and nginx-site.conf for the host's nginx"
  elif [ "$(prev_root TYDAL_ENV)" = "production" ]; then
    echo "  • back to DEVELOPMENT (--dev): the production root .env is retired (backup saved)"
  fi
  if [ "$URL_SET" = true ] && [ -n "$URL_ARG" ]; then
    echo "  • public URL $URL_ARG (APP_URL, Sanctum domain, the SPA's API base URL)"
  elif [ "$URL_SET" = true ]; then
    echo "  • drop the public URL, back to localhost"
  fi
  if [ "$INFRA_MODE" = "shared" ]; then
    echo "  • SHARED infrastructure from the '$INFRA_STACK' stack, as installation '$NAME':"
    echo "    write the root .env (containers tydal_${NAME}_*, slot $SLOT: app :$HTTP_PORT, Vite :$VITE_PORT), point"
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
ROOT_ENV_NEW="$(mktemp)"   # the root .env being assembled (shared and/or --prod)
if [ "$INFRA_MODE" = "shared" ]; then
  ENV_OUT="$ROOT/backend/.env"
  # SLOT, SLOT_NOTE, HTTP_PORT, VITE_PORT and STACK were resolved (and checked
  # against the other installations) before the confirmation prompt.

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

  # The infrastructure's Redis password (production infra has one; read from
  # its container, so no copy of the other checkout's secrets is needed).
  INFRA_REDIS_PASS="$(docker exec "${INFRA_STACK}_redis" sh -c 'printf %s "${REDIS_PASSWORD:-}"' 2>/dev/null || true)"
  if [ -n "$INFRA_REDIS_PASS" ]; then
    upsert_env_var REDIS_PASSWORD "$INFRA_REDIS_PASS" "$ENV_OUT"
    echo "  Redis password: taken from ${INFRA_STACK}_redis"
  fi

  # Root .env — Compose's project env file. docker-compose.yml interpolates it;
  # tools/lib/stack.lib.sh reads it for the scripts. Assembled here, written
  # (with a backup of the old one) after the production settings below.
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
  } >"$ROOT_ENV_NEW"
fi

# --- production settings (--prod) ---
# Own infrastructure: generated passwords (kept across runs — MySQL and Redis
# only read them when their data is first created), loopback-only ports, data
# directories, Kibana off. Every installation: production .env, uid-1000
# workers, its storage directory.
if [ "$PROD" = true ]; then
  [ "$INFRA_MODE" = "shared" ] || STACK="tydal"
  gen_secret() { od -An -N18 -tx1 /dev/urandom | tr -d ' \n'; }
  if [ "$INFRA_MODE" = "own" ]; then
    MYSQL_ROOT_PASS="$(prev_root TYDAL_MYSQL_ROOT_PASSWORD)"; [ -n "$MYSQL_ROOT_PASS" ] || MYSQL_ROOT_PASS="$(gen_secret)"
    MYSQL_APP_PASS="$(prev_root TYDAL_MYSQL_PASSWORD)";       [ -n "$MYSQL_APP_PASS" ]  || MYSQL_APP_PASS="$(gen_secret)"
    REDIS_PASS="$(prev_root TYDAL_REDIS_PASSWORD)";           [ -n "$REDIS_PASS" ]      || REDIS_PASS="$(gen_secret)"
    ES_HEAP="$(prev_root TYDAL_ES_HEAP)";                     [ -n "$ES_HEAP" ]         || ES_HEAP="768m"
    {
      echo "# Written by tools/deploy/configure.sh — production installation running its own"
      echo "# infrastructure (MySQL/ES/Redis/Tika). Compose reads it for docker-compose.yml;"
      echo "# the tools/ scripts read it through tools/lib/stack.lib.sh. Re-run configure.sh"
      echo "# to change it. Holds secrets: keep it 600."
      echo "TYDAL_INFRA_MODE=own"
    } >"$ROOT_ENV_NEW"
  fi
  STORAGE_DIR=""; [ -n "$DATA_DIR" ] && STORAGE_DIR="$DATA_DIR/$STACK/storage"
  {
    echo "#"
    echo "# --- production (--prod) ---"
    echo "TYDAL_ENV=production"
    echo "# Every published port on loopback only: the host's nginx is the way in."
    echo "TYDAL_PORT_BIND=127.0.0.1:"
    echo "# Queue and scheduler run as the image's uid-1000 user, not root."
    echo "TYDAL_WORKER_EXEC=gosu application"
    if [ -n "$DATA_DIR" ]; then
      echo "# --data-dir: host directories instead of Docker volumes."
      echo "TYDAL_DATA_DIR=$DATA_DIR"
      echo "TYDAL_STORAGE=$STORAGE_DIR"
    fi
    if [ "$INFRA_MODE" = "own" ]; then
      echo "# Infrastructure secrets, generated once (MySQL/Redis only read them when"
      echo "# their data is first created — changing them here later has no effect)."
      echo "TYDAL_MYSQL_ROOT_PASSWORD=$MYSQL_ROOT_PASS"
      echo "TYDAL_MYSQL_PASSWORD=$MYSQL_APP_PASS"
      echo "TYDAL_REDIS_PASSWORD=$REDIS_PASS"
      echo "TYDAL_ES_HEAP=$ES_HEAP"
      echo "# Kibana only on request: docker compose --profile kibana up -d kibana"
      echo "TYDAL_KIBANA_PROFILE=kibana"
      if [ -n "$DATA_DIR" ]; then
        echo "TYDAL_MYSQL_DATA=$DATA_DIR/mysql"
        echo "TYDAL_ES_DATA=$DATA_DIR/elasticsearch"
        echo "TYDAL_REDIS_DATA=$DATA_DIR/redis"
      fi
    fi
  } >>"$ROOT_ENV_NEW"

  ENV_OUT="$ROOT/backend/.env"
  set_env_var    APP_ENV         production "$ENV_OUT"
  set_env_var    APP_DEBUG       false      "$ENV_OUT"
  set_env_var    LOG_LEVEL       info       "$ENV_OUT"
  set_env_var    AI_DEBUG        false      "$ENV_OUT"
  # The app port is published on 127.0.0.1 only, so the one proxy in front
  # (the host's nginx) is the only peer: trust it for https/host headers.
  upsert_env_var TRUSTED_PROXIES '*'        "$ENV_OUT"
  if [ "$INFRA_MODE" = "own" ]; then
    set_env_var DB_PASSWORD    "$MYSQL_APP_PASS" "$ENV_OUT"
    set_env_var REDIS_PASSWORD "$REDIS_PASS"     "$ENV_OUT"
  fi
  echo "  production: APP_ENV=production, debug off, TRUSTED_PROXIES=*, ports on 127.0.0.1$( [ "$INFRA_MODE" = own ] && echo ', generated MySQL/Redis passwords, Kibana off')"

  # Data directories, owned as the containers expect: Elasticsearch and PHP
  # (php-fpm, queue, scheduler) run as uid 1000; MySQL and Redis fix their own.
  if [ -n "$DATA_DIR" ]; then
    as_root() { if [ "$(id -u)" = 0 ]; then "$@"; else sudo "$@"; fi; }
    DIRS=("$STORAGE_DIR/app/public" "$STORAGE_DIR/framework/cache/data" "$STORAGE_DIR/framework/sessions"
          "$STORAGE_DIR/framework/views" "$STORAGE_DIR/logs")
    [ "$INFRA_MODE" = "own" ] && DIRS+=("$DATA_DIR/mysql" "$DATA_DIR/elasticsearch" "$DATA_DIR/redis")
    if mkdir -p "${DIRS[@]}" 2>/dev/null || as_root mkdir -p "${DIRS[@]}"; then
      as_root chown -R 1000:1000 "$DATA_DIR/$STACK"
      [ "$INFRA_MODE" = "own" ] && as_root chown 1000:0 "$DATA_DIR/elasticsearch"
      echo "  data: $DATA_DIR$( [ "$INFRA_MODE" = own ] && echo ' (mysql, elasticsearch, redis)'), storage $STORAGE_DIR"
    else
      die "couldn't create the data directories under $DATA_DIR (needs root or sudo)."
    fi
  fi
fi

# --- root .env: write what was assembled, or retire a configure-written one ---
if [ -s "$ROOT_ENV_NEW" ]; then
  if [ -f "$ROOT/.env" ]; then
    ROOT_BACKUP="$ROOT/.env.bak.$(date +%Y%m%d-%H%M%S)"
    cp "$ROOT/.env" "$ROOT_BACKUP"
    chmod 600 "$ROOT_BACKUP"
    echo "Backed up existing root .env → $(basename "$ROOT_BACKUP")"
  fi
  mv "$ROOT_ENV_NEW" "$ROOT/.env"
  chmod 600 "$ROOT/.env"
  if [ "$INFRA_MODE" = "shared" ]; then
    echo "Wrote the root .env (Compose project $STACK, containers ${STACK}_*$( [ "$PROD" = true ] && echo ', production'))."
  else
    echo "Wrote the root .env (own infrastructure, production; holds secrets — mode 600)."
  fi
else
  rm -f "$ROOT_ENV_NEW"
  if [ -f "$ROOT/.env" ] && grep -q '^TYDAL_INFRA_MODE=\|^TYDAL_ENV=' "$ROOT/.env"; then
    # Back to own infrastructure and/or development: the old root .env would keep
    # the infrastructure off, the names/ports offset, or production settings.
    grep -q '^TYDAL_INFRA_MODE=shared' "$ROOT/.env" && RETIRED_SHARED=true
    ROOT_BACKUP="$ROOT/.env.bak.$(date +%Y%m%d-%H%M%S)"
    mv "$ROOT/.env" "$ROOT_BACKUP"
    chmod 600 "$ROOT_BACKUP"
    echo "Retired the configure-written root .env → $(basename "$ROOT_BACKUP") (own infrastructure, development defaults)."
  fi
fi

# --- public URL (--url, kept as TYDAL_PUBLIC_URL in backend/.env) ---
# Without --url, a URL from the previous backend/.env is kept (the template
# rewrite would otherwise reset APP_URL to localhost on every re-run).
PREV_URL=""
if [ -n "${BACKUP:-}" ]; then
  PREV_URL="$(env_get TYDAL_PUBLIC_URL "$BACKUP")"
elif [ -f "$ROOT/backend/.env" ]; then
  PREV_URL="$(env_get TYDAL_PUBLIC_URL "$ROOT/backend/.env")"
fi
if [ "$URL_SET" = true ]; then
  PUBLIC_URL="$URL_ARG"; URL_NOTE="from --url"
else
  PUBLIC_URL="$PREV_URL"; URL_NOTE="kept from the previous configuration"
fi
URL_CLEARED=false
[ -z "$PUBLIC_URL" ] && [ -n "$PREV_URL" ] && URL_CLEARED=true
if [ -n "$PUBLIC_URL" ]; then
  URL_HOSTPORT="${PUBLIC_URL#*://}"
  upsert_env_var TYDAL_PUBLIC_URL         "$PUBLIC_URL"     "$ROOT/backend/.env"
  upsert_env_var APP_URL                  "$PUBLIC_URL"     "$ROOT/backend/.env"
  upsert_env_var SANCTUM_STATEFUL_DOMAINS "$URL_HOSTPORT"   "$ROOT/backend/.env"
  echo "  public URL $PUBLIC_URL ($URL_NOTE): APP_URL, SANCTUM_STATEFUL_DOMAINS=$URL_HOSTPORT"
elif [ "$URL_CLEARED" = true ]; then
  # Back to localhost. A rewritten backend/.env already has it (template or the
  # shared block); with --no-force-env, reset the values here.
  LOCAL_PORT="$( [ "$INFRA_MODE" = shared ] && echo "$HTTP_PORT" || echo 8000 )"
  set_env_var TYDAL_PUBLIC_URL         ""                                          "$ROOT/backend/.env"
  set_env_var APP_URL                  "http://localhost:$LOCAL_PORT"              "$ROOT/backend/.env"
  set_env_var SANCTUM_STATEFUL_DOMAINS "localhost:$LOCAL_PORT,127.0.0.1:$LOCAL_PORT" "$ROOT/backend/.env"
  echo "  public URL dropped: APP_URL back to http://localhost:$LOCAL_PORT"
fi

# --- frontend/.env (copy-if-missing; backend-agnostic) ---
if [ -f "$ROOT/frontend/.env" ]; then
  echo "frontend/.env exists — leaving it untouched$( [ "$INFRA_MODE" = shared ] || [ "${RETIRED_SHARED:-false}" = true ] || [ -n "$PUBLIC_URL" ] || [ "$URL_CLEARED" = true ] && echo ' (apart from the API base URL)')."
else
  cp "$TEMPLATES/frontend.env" "$ROOT/frontend/.env"
  echo "Created frontend/.env."
fi
# The SPA calls the API on the app's host port, which a shared installation
# offsets. Its auth cookie gets the installation's name too: cookies are scoped
# by host, not port, so two installations on one host would share a `JWT`
# cookie and log each other out.
if [ "$INFRA_MODE" = "shared" ]; then
  upsert_env_var VITE_API_BASE_URL "http://localhost:$HTTP_PORT/api/v1" "$ROOT/frontend/.env"
  upsert_env_var VITE_AUTH_COOKIE  "${STACK}_jwt"                       "$ROOT/frontend/.env"
elif [ "${RETIRED_SHARED:-false}" = true ]; then
  upsert_env_var VITE_API_BASE_URL "http://localhost:8000/api/v1" "$ROOT/frontend/.env"
  upsert_env_var VITE_AUTH_COOKIE  "JWT"                                 "$ROOT/frontend/.env"
elif [ "$URL_CLEARED" = true ]; then
  upsert_env_var VITE_API_BASE_URL "http://localhost:8000/api/v1" "$ROOT/frontend/.env"
fi
# A public URL wins over the localhost port: the SPA calls the API where people
# reach the installation (the build bakes it in).
if [ -n "$PUBLIC_URL" ]; then
  upsert_env_var VITE_API_BASE_URL "$PUBLIC_URL/api/v1" "$ROOT/frontend/.env"
fi

# --- production: a server block for the host's nginx (nginx-site.conf) ---
# nginx serves the built SPA from frontend/build and passes the API, the vault
# grammar (/h, /v, /vault) and the health check to the app container on
# 127.0.0.1. HTTP only: certbot adds TLS (certbot --nginx -d HOST).
if [ "$PROD" = true ] && [ -n "$PUBLIC_URL" ]; then
  SITE_HOST="${PUBLIC_URL#*://}"; SITE_HOST="${SITE_HOST%%:*}"
  SITE_PORT="$( [ "$INFRA_MODE" = shared ] && echo "$HTTP_PORT" || echo 8000 )"
  cat >"$ROOT/nginx-site.conf" <<NGINX
# Written by tools/deploy/configure.sh --prod for $PUBLIC_URL.
# Install:  sudo cp nginx-site.conf /etc/nginx/sites-available/$SITE_HOST.conf
#           sudo ln -s /etc/nginx/sites-available/$SITE_HOST.conf /etc/nginx/sites-enabled/
#           sudo nginx -t && sudo systemctl reload nginx
#           sudo certbot --nginx -d $SITE_HOST
server {
    listen 80;
    listen [::]:80;
    server_name $SITE_HOST;

    # The SPA (npm run build). Unknown paths fall back to index.html (client routing).
    root $ROOT/frontend/build;
    index index.html;
    location / {
        try_files \$uri /index.html;
    }

    # Uploads up to the app's PHP_POST_MAX_SIZE.
    client_max_body_size 310m;

    # The app container: API, vault surfaces, health check.
    location ~ ^/(api|h|v|vault|up)(/|\$) {
        proxy_pass http://127.0.0.1:$SITE_PORT;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Port \$server_port;
        proxy_read_timeout 360s;   # AI analysis and streamed answers
        proxy_buffering off;       # streamed responses (ask, auto-approve)
    }
}
NGINX
  echo "Wrote nginx-site.conf ($SITE_HOST → frontend/build + app 127.0.0.1:$SITE_PORT)."
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
  if [ "$INFRA_MODE" = "shared" ]; then
    echo "docker-compose.yml: enabled $joined."
  else
    echo "docker-compose.yml: enabled $joined (+ backing services)."
  fi
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
# install.sh prints its own summary at the very end (TYDAL_FROM_INSTALL=1), so
# this hint would only scroll away under the dependency output there.
if [ "${TYDAL_FROM_INSTALL:-}" != 1 ] && [ "$PROD" = true ]; then
  echo
  echo "Next (production):"
  echo "  tools/deploy/reload.sh     # if the stack is up: recreate the containers with this configuration"
  echo "  tools/deploy/install.sh …  # first time: dependencies (no dev packages), SPA build, config cache"
  echo "  nginx-site.conf            # install it in the host's nginx (instructions at its top) + certbot"
elif [ "${TYDAL_FROM_INSTALL:-}" != 1 ]; then
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

  if [ -n "$PUBLIC_URL" ]; then
    echo "  Public URL $PUBLIC_URL: point your web server for that host at app :$SHOW_HTTP"
    echo "  (or PHP-FPM) and serve the built SPA (npm run build — VITE_API_BASE_URL is"
    echo "  baked in); DEPLOYMENT.md, \"Production deployment\"."
  fi
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
