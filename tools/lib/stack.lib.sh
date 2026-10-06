#!/usr/bin/env bash
# Installation identity for the deploy scripts (#23). SOURCED, not run.
#
# One checkout = one installation. By default (infra mode `own`) it runs its own
# infrastructure (MySQL, Elasticsearch, Kibana, Tika, Redis) under the historical
# names: tydal_app, tydal_mysql, …, app on :8000, Vite on :3005. A `shared`
# installation (configure.sh --infra=shared --name=NAME) runs only its app
# services and uses another checkout's infrastructure. configure.sh records the
# difference in the root `.env` (Compose's project env file); this library reads
# it back so the scripts follow the configured names instead of literals.
#
# stack_load ROOT   sets, from ROOT/.env (a variable already exported in the
#                   shell wins, as it does for Compose), with today's defaults:
#     TYDAL_STACK         container-name prefix of THIS installation  (tydal)
#     TYDAL_INFRA_MODE    own | shared                                (own)
#     TYDAL_INSTALLATION  the --name of a shared installation         (empty)
#     TYDAL_INFRA_STACK   container-name prefix of the stack running the
#                         infrastructure (= TYDAL_STACK in own mode)  (tydal)
#     TYDAL_HTTP_PORT     app host port (artisan serve / app container) (8000)
#     TYDAL_VITE_PORT     Vite dev server port                         (3005)
#
# stack_container SVC   → this installation's container, e.g. tydal_app
# infra_container SVC   → the infrastructure's container, e.g. tydal_mysql
# infra_mysql_root ARGS → run the `mysql` client as root inside the infra MySQL
#                         container. The password is the container's own
#                         MYSQL_ROOT_PASSWORD (set by the infra stack's
#                         docker-compose.yml), so no secret lives in the scripts.
# infra_mysql_ping      → succeed when the infra MySQL answers
# infra_redis_del_prefix DB PREFIX → delete PREFIX* keys in one Redis DB

# stack_env_get KEY FILE : KEY's value in a dotenv FILE (inline comment and
# surrounding quotes stripped); empty when absent or commented out.
stack_env_get() {
  [ -f "$2" ] || return 0
  awk -v k="$1" 'index($0, k"=") == 1 {
    v = substr($0, length(k) + 2)
    sub(/[[:space:]]+#.*/, "", v)
    gsub(/^[[:space:]]+|[[:space:]]+$/, "", v)
    gsub(/^"|"$/, "", v)
    print v; exit
  }' "$2"
}

stack_load() {
  local env_file="$1/.env" key val
  for key in TYDAL_STACK TYDAL_INFRA_MODE TYDAL_INSTALLATION TYDAL_INFRA_STACK \
             TYDAL_HTTP_PORT TYDAL_VITE_PORT; do
    if [ -z "${!key:-}" ]; then
      val="$(stack_env_get "$key" "$env_file")"
      [ -n "$val" ] && printf -v "$key" '%s' "$val"
    fi
  done
  TYDAL_STACK="${TYDAL_STACK:-tydal}"
  TYDAL_INFRA_MODE="${TYDAL_INFRA_MODE:-own}"
  TYDAL_INSTALLATION="${TYDAL_INSTALLATION:-}"
  TYDAL_HTTP_PORT="${TYDAL_HTTP_PORT:-8000}"
  TYDAL_VITE_PORT="${TYDAL_VITE_PORT:-3005}"
  if [ "$TYDAL_INFRA_MODE" = "shared" ]; then
    TYDAL_INFRA_STACK="${TYDAL_INFRA_STACK:-tydal}"
  else
    TYDAL_INFRA_STACK="$TYDAL_STACK"
  fi
}

stack_container() { printf '%s_%s\n' "$TYDAL_STACK" "$1"; }
infra_container() { printf '%s_%s\n' "$TYDAL_INFRA_STACK" "$1"; }

infra_mysql_root() {
  # MYSQL_PWD instead of -p: no "password on the command line" warning.
  docker exec -i "$(infra_container mysql)" \
    sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$@"' mysql "$@"
}

infra_mysql_ping() {
  docker exec "$(infra_container mysql)" \
    sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqladmin ping -h localhost -uroot --silent' \
    >/dev/null 2>&1
}

# infra_redis_del_prefix DB PREFIX : delete every key under PREFIX in Redis DB
# (SCAN + DEL inside the container, so nothing else is matched) and print how
# many there were.
infra_redis_del_prefix() {
  docker exec "$(infra_container redis)" sh -c '
    n=$(redis-cli -n "$1" --scan --pattern "$2*" | wc -l)
    redis-cli -n "$1" --scan --pattern "$2*" | xargs -r -n 100 redis-cli -n "$1" DEL >/dev/null
    echo $n' sh "$1" "$2"
}

# infra_running : succeed when the infrastructure's MySQL container is running
# (the cheapest "is the shared stack up?" probe).
infra_running() {
  [ "$(docker inspect -f '{{.State.Running}}' "$(infra_container mysql)" 2>/dev/null)" = "true" ]
}
