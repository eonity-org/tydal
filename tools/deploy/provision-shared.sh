#!/usr/bin/env bash
# TYDAL — provision a shared installation's MySQL database, test database and
# user in the shared infrastructure's MySQL (#23). Idempotent: safe to re-run
# (it also re-syncs the user's password with backend/.env).
#
# Reads what to create from this checkout:
#   backend/.env  DB_DATABASE, DB_USERNAME, DB_PASSWORD  (written by configure.sh)
#   .env          TYDAL_INFRA_STACK → the infra MySQL container, <stack>_mysql
# and authenticates as root with the infra container's own MYSQL_ROOT_PASSWORD
# (set by the infra checkout's docker-compose.yml), so no root secret is copied
# into this checkout. configure.sh --infra=shared runs this for you; run it by
# hand when the infrastructure wasn't up at configure time.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
ENV_FILE="$ROOT/backend/.env"

usage() {
  cat <<'EOF'
Usage: provision-shared.sh [--dry-run] [--show-secrets] [-h|--help]

Create, in the shared infrastructure's MySQL, this installation's database
(DB_DATABASE), its test database (DB_DATABASE + "_test", used by test.sh) and
its user (DB_USERNAME / DB_PASSWORD) with privileges on both — and nothing
else. Values come from backend/.env (written by configure.sh --infra=shared);
the MySQL container is <TYDAL_INFRA_STACK>_mysql from the root .env, root
password = that container's MYSQL_ROOT_PASSWORD. Idempotent.

  --dry-run        print the SQL instead of running it (password masked)
  --show-secrets   with --dry-run, print the real password
  -h, --help       show this help and exit
EOF
}

DRY_RUN=false
SHOW_SECRETS=false
for arg in "$@"; do
  case "$arg" in
    -h|--help)      usage; exit 0 ;;
    --dry-run)      DRY_RUN=true ;;
    --show-secrets) SHOW_SECRETS=true ;;
    *) echo "provision-shared.sh: unknown argument '$arg'" >&2; usage >&2; exit 1 ;;
  esac
done

. "$SCRIPT_DIR/../lib/stack.lib.sh"
stack_load "$ROOT"

if [ "$TYDAL_INFRA_MODE" != "shared" ]; then
  echo "provision-shared.sh: this checkout isn't a shared installation (root .env" >&2
  echo "has no TYDAL_INFRA_MODE=shared). Its own MySQL creates its database itself." >&2
  exit 1
fi

DB="$(stack_env_get DB_DATABASE "$ENV_FILE")"
USER_NAME="$(stack_env_get DB_USERNAME "$ENV_FILE")"
PASS="$(stack_env_get DB_PASSWORD "$ENV_FILE")"
TEST_DB="${DB}_test"

# Identifiers and the password are interpolated into SQL: accept only the
# shapes configure.sh generates, so nothing here can be quoted out of place.
valid_ident() { printf '%s' "$1" | grep -Eq '^[a-z0-9_]{1,59}$'; }
if ! valid_ident "$DB" || ! valid_ident "$USER_NAME"; then
  echo "provision-shared.sh: DB_DATABASE='$DB' / DB_USERNAME='$USER_NAME' in backend/.env" >&2
  echo "must be lowercase [a-z0-9_] (re-run configure.sh --infra=shared --name=NAME)." >&2
  exit 1
fi
if ! printf '%s' "$PASS" | grep -Eq '^[A-Za-z0-9]{12,}$'; then
  echo "provision-shared.sh: DB_PASSWORD in backend/.env must be 12+ letters/digits" >&2
  echo "(configure.sh generates one)." >&2
  exit 1
fi

sql() {
  local pw="$1"
  cat <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB\`;
CREATE DATABASE IF NOT EXISTS \`$TEST_DB\`;
CREATE USER IF NOT EXISTS '$USER_NAME'@'%' IDENTIFIED BY '$pw';
ALTER USER '$USER_NAME'@'%' IDENTIFIED BY '$pw';
GRANT ALL PRIVILEGES ON \`$DB\`.* TO '$USER_NAME'@'%';
GRANT ALL PRIVILEGES ON \`$TEST_DB\`.* TO '$USER_NAME'@'%';
FLUSH PRIVILEGES;
SQL
}

MYSQL_CONTAINER="$(infra_container mysql)"
if [ "$DRY_RUN" = true ]; then
  echo "-- would run as root in container $MYSQL_CONTAINER:"
  if [ "$SHOW_SECRETS" = true ]; then sql "$PASS"; else sql '********'; fi
  exit 0
fi

if ! infra_running; then
  echo "provision-shared.sh: the infrastructure's MySQL ($MYSQL_CONTAINER) isn't running." >&2
  echo "Start the infrastructure stack first (in its checkout: tools/deploy/start.sh)," >&2
  echo "or point TYDAL_INFRA_STACK in the root .env at the right stack, then re-run." >&2
  exit 1
fi
_t=0
until infra_mysql_ping; do
  _t=$((_t+1))
  if [ "$_t" -ge 30 ]; then
    echo "provision-shared.sh: $MYSQL_CONTAINER didn't answer within 30s." >&2
    exit 1
  fi
  sleep 1
done

sql "$PASS" | infra_mysql_root
echo "Provisioned in $MYSQL_CONTAINER: databases $DB + $TEST_DB, user $USER_NAME (privileges on both only)."
