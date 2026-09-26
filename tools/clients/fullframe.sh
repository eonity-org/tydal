#!/usr/bin/env bash
# TYDAL — provision photo exhibitions for Full Frame (a product at TYDAL's
# border: it consumes one gallery vault per exhibition through @tydal/client).
# A tier-aware front for the two artisan commands, so you never have to
# remember where artisan runs: inside the app container (docker tier) or on the
# host. Options pass straight through — see docs/CLI.md "Photo exhibitions".
# Requires the stack up (start.sh) and an existing organization.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT/backend"
COMPOSE="$ROOT/docker-compose.yml"

usage() {
  cat <<'EOF'
Usage: fullframe.sh setup  --org=SLUG [--index=INDEX] [--collection=Photos] [--language=es]
       fullframe.sh create --org=SLUG --name="Exhibition name" [--slug=SLUG]
                           [--curator=EMAIL [--curator-name="Name"] [--role=editor]
                            [--curator-password=…]]

  setup    once per organization (safe to re-run): photo_exhibition scheme,
           its index (or --index=tydal_multimedia to share one), and the
           Photos collection in the language you pick (asked when omitted;
           default en; a re-run with --language corrects it)
           → artisan exhibitions:setup
  create   once per exhibition: workspace, private gallery vault, read + write
           keys, and optionally the curator's TYDAL account + membership.
           A NEW curator's password is asked (hidden, typed twice; empty =
           generated) unless --curator-password is given; an existing
           account keeps its own.  → artisan exhibitions:create
           Prints the vault URL, both keys (and a generated password) ONCE —
           paste them into Full Frame's studio → "Connect an exhibition".

Interactive from a terminal (prompts for what you left out); from a pipe or
script it never prompts and uses the defaults. Tier-aware (runs artisan in
the app container on the docker tier). Requires the stack up — run
tools/deploy/start.sh first.

  -h, --help   show this help and exit
EOF
}

case "${1:-}" in
  setup|create) ACTION="$1"; shift ;;
  -h|--help) usage; exit 0 ;;
  "") usage >&2; exit 1 ;;
  *) echo "fullframe.sh: unknown action '$1'" >&2; echo >&2; usage >&2; exit 1 ;;
esac

. "$SCRIPT_DIR/../lib/tier.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"

# From a terminal the commands may ask (language, curator password), so the
# container gets a TTY; from a pipe/script they must not, so -T and
# --no-interaction make them fall back to the defaults.
if [ -t 0 ] && [ -t 1 ]; then
  TTY_FLAG=""; NO_INTERACTION=()
else
  TTY_FLAG="-T"; NO_INTERACTION=(--no-interaction)
fi

# artisan ... : run a Laravel command in the right place for the tier.
# TYDAL_VIA_FULLFRAME_SH makes `setup` suggest `fullframe.sh create` next.
artisan() {
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec $TTY_FLAG -e TYDAL_VIA_FULLFRAME_SH=1 \
      -w /var/www/html app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && TYDAL_VIA_FULLFRAME_SH=1 php artisan "$@" )
  fi
}

# bash 3.2 (macOS) errors on "${arr[@]}" for an empty array under set -u
artisan "exhibitions:$ACTION" ${NO_INTERACTION[@]+"${NO_INTERACTION[@]}"} "$@"
