#!/usr/bin/env bash
# TYDAL — reset the database and (re)build a minimal dev dataset.
#
# Wipes local media + ai logs, runs a fresh migration, and unconditionally
# seeds the minimal usable baseline: superadmin, organization, default
# workspace, and the system collection schemes (schema metadata only — no
# Elasticsearch index is physically built until a collection uses it). TYDAL
# is usable at that point with zero collections. Then optionally, by prompt or
# --collections, creates starter collection(s) on top of that baseline.
# Repeatable.
#
# WARNING: this DROPS and recreates the database (migrate:fresh) AND wipes
# every tydal_*/vault_* Elasticsearch index. Dev only. It asks for confirmation
# first unless you pass -f/--force.
#
# Assumes the stack is already up — run tools/deploy/start.sh first (it owns
# service startup). first_install only connects; it does NOT start Docker or services.
# Tier-aware: in the `docker` tier the artisan commands run INSIDE the app
# container (the host can't resolve DB_HOST=mysql); in `host` they run on the host.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT/backend"
COMPOSE="$ROOT/docker-compose.yml"

usage() {
  cat <<'EOF'
Usage: first_install.sh [-f|--force] [--collections=LIST] [-h|--help]

DESTRUCTIVE dev reset: wipes every tydal_*/vault_* Elasticsearch index, then
migrate:fresh (DROPS the database) + seeds minimal data. Tier-aware (runs
artisan in the app container for the docker tier, on the host otherwise).
Requires the stack to be up — run start.sh first; first_install does not start
services.

Once the schema seeder has run, interactively asks which starter collection(s)
to also create — optional, TYDAL works with none. The menu is built from
`php artisan schema:starter-options` (every is_system collection scheme), so
adding a scheme there is enough to make it selectable here. Skipped (defaults
to no starter collection — minimal install) when --collections is given or the
shell is non-interactive.

  -f, --force          skip the confirmation prompt (for scripts / CI)
  --collections=LIST   comma-separated scheme names to seed as starter
                        collections (e.g. "multimedia,documents"); skips the prompt
  -h, --help           show this help and exit
EOF
}

FORCE=false
COLLECTIONS=""
for arg in "$@"; do
  case "$arg" in
    -h|--help)  usage; exit 0 ;;
    -f|--force) FORCE=true ;;
    --collections=*) COLLECTIONS="${arg#--collections=}" ;;
    *) echo "first_install.sh: unknown argument '$arg'" >&2; echo >&2; usage >&2; exit 1 ;;
  esac
done

# --- confirmation (skipped with -f/--force): this DROPS the database ---
if [ "$FORCE" = false ]; then
  echo "⚠️  first_install.sh will wipe every tydal_*/vault_* Elasticsearch index, DROP"
  echo "    and recreate the database (migrate:fresh), and reseed minimal data —"
  echo "    all current resources/users/search data are lost."
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

# Detect the application tier from backend/.env DB_HOST (see tier.lib.sh): the
# value artisan dials decides whether it must run inside the app container.
. "$SCRIPT_DIR/../lib/tier.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"
echo "Application tier: $INFRA"

# Set before first use so `set -u` doesn't choke on early artisan calls (the
# menu below fills it in once the schema seeder has run).
TYDAL_INITIAL_COLLECTIONS=""

# artisan ... : run a Laravel command in the right place for the tier.
artisan() {
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html \
      -e TYDAL_INITIAL_COLLECTIONS="$TYDAL_INITIAL_COLLECTIONS" \
      app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && TYDAL_INITIAL_COLLECTIONS="$TYDAL_INITIAL_COLLECTIONS" php artisan "$@" )
  fi
}

# Precondition: the stack must be up (start.sh owns startup). Fail fast with a
# hint rather than a raw connection stack trace.
stack_up() {
  docker compose -f "$COMPOSE" exec -T mysql \
    mysqladmin ping -h localhost -u root -psecret --silent >/dev/null 2>&1 || return 1
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app php -v >/dev/null 2>&1 || return 1
  fi
  return 0
}
if ! stack_up; then
  echo "The stack isn't up. Start it first (in another terminal), then re-run first_install:" >&2
  echo "  tools/deploy/start.sh" >&2
  exit 1
fi

cd "$BACKEND_DIR"

mkdir -p storage/app/public storage/logs
rm -rf storage/app/public/*
find storage/app -mindepth 1 -maxdepth 1 -type d ! -name public -exec rm -rf {} +
rm -f storage/logs/ai*.log
if [ -L public/storage ]; then
  rm public/storage
fi

# migrate:fresh only touches MySQL — Elasticsearch is a separate service, so
# indices for anything not re-provisioned by THIS run (a scheme you don't pick
# this time, a vault whose row just got dropped) would otherwise survive,
# orphaned, pointing at MySQL rows that no longer exist. Wipe ES first so the
# reset is actually complete.
artisan search:wipe-indices --force
artisan migrate:fresh
artisan storage:link
artisan db:seed --class=CollectionSchemaSeeder

# --- which starter collection(s) to seed ---
# Built from schema:starter-options (every is_system collection scheme), so
# this menu can never drift from what CollectionSchemaSeeder actually defines
# — add a scheme there and it shows up here with no edit to this script.
if [ -n "$COLLECTIONS" ]; then
  TYDAL_INITIAL_COLLECTIONS="$COLLECTIONS"
elif [ "$FORCE" = false ] && [ -t 0 ]; then
  OPTION_NAMES=()
  OPTION_LABELS=()
  while IFS='|' read -r opt_name opt_display opt_desc; do
    [ -z "$opt_name" ] && continue
    OPTION_NAMES+=("$opt_name")
    OPTION_LABELS+=("$opt_display — $opt_desc")
  done < <(artisan schema:starter-options)

  if [ "${#OPTION_NAMES[@]}" -eq 0 ]; then
    echo "No starter-eligible schemes found — nothing to seed as a starter collection." >&2
    TYDAL_INITIAL_COLLECTIONS=""
  else
    # TYDAL is fully usable with none — superadmin/org/workspace/schemes are
    # already seeded unconditionally above this prompt. "0" (also the default
    # on bare Enter) means stay at that minimal baseline.
    echo
    echo "Which starter collection(s) should be created? (optional — TYDAL works"
    echo "with none; create one later from the UI, or re-run with --collections=...)"
    echo "  0) None — minimal install only"
    for i in "${!OPTION_NAMES[@]}"; do
      echo "  $((i + 1))) ${OPTION_LABELS[$i]}"
    done
    _choice=""; read -r -p "Choice(s), comma/space-separated [0]: " _choice || true
    _choice="${_choice:-0}"

    # Built as a plain comma string, not an array: macOS ships bash 3.2, where
    # `set -u` + expanding an EMPTY array (`${arr[@]}`/`${arr[*]}`) throws
    # "unbound variable" — a real risk here since "0"/none must be able to
    # leave this genuinely empty (fixed in bash 4.4+, but don't rely on that).
    TYDAL_INITIAL_COLLECTIONS=""
    if [ "$_choice" != "0" ]; then
      for n in $(echo "$_choice" | tr ',' ' '); do
        case "$n" in
          ''|*[!0-9]*) continue ;;  # not a plain positive integer — skip
        esac
        idx=$((n - 1))
        if [ "$idx" -ge 0 ] && [ -n "${OPTION_NAMES[$idx]:-}" ]; then
          if [ -z "$TYDAL_INITIAL_COLLECTIONS" ]; then
            TYDAL_INITIAL_COLLECTIONS="${OPTION_NAMES[$idx]}"
          else
            TYDAL_INITIAL_COLLECTIONS="$TYDAL_INITIAL_COLLECTIONS,${OPTION_NAMES[$idx]}"
          fi
        fi
      done
      if [ -z "$TYDAL_INITIAL_COLLECTIONS" ]; then
        echo "No valid choice recognized — defaulting to none (minimal install)." >&2
      fi
    fi
  fi
else
  TYDAL_INITIAL_COLLECTIONS=""
fi
if [ -n "$TYDAL_INITIAL_COLLECTIONS" ]; then
  echo "Starter collection(s): $TYDAL_INITIAL_COLLECTIONS"
else
  echo "Starter collection(s): none (minimal install — superadmin/org/schemes only)"
fi

# Capture the seed output so the superadmin password (random when
# TYDAL_SUPERADMIN_PASSWORD is blank) can be re-surfaced at the very end,
# after the reindex/embed steps have scrolled past.
SEED_LOG="$(mktemp -t tydal-seed.XXXXXX)"
artisan db:seed --class=MinimalSeeder | tee "$SEED_LOG"

# No standalone search:setup-indices here: MinimalSeeder/CollectionSchemaSeeder
# provision each starter collection's own ES index the moment it's created
# (CollectionService::createCollection does the same for collections made
# later through the UI) — a scheme nobody picked never gets an index built at
# all. reindex/embed are harmless no-ops on a fresh install (zero resources)
# but stay as a safety net.
artisan search:reindex
artisan search:embed

# Re-surface the seeded superadmin credentials (the password may have been
# randomly generated above and is only shown once).
echo
echo "====================================="
echo "Seeded superadmin login:"
grep -E "Email:|Password:" "$SEED_LOG" || true
echo "====================================="
rm -f "$SEED_LOG"
