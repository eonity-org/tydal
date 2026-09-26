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
Usage: fullframe.sh setup  --org=SLUG [--index=INDEX] [--collection=Photos]
       fullframe.sh create --org=SLUG --name="Exhibition name" [--slug=SLUG]
                           [--curator=EMAIL [--curator-name="Name"] [--role=editor]]

  setup    once per organization (safe to re-run): photo_exhibition scheme,
           its index (or --index=tydal_multimedia to share one), and the
           Photos collection  → artisan exhibitions:setup
  create   once per exhibition: workspace, private gallery vault, read + write
           keys, and optionally the curator's TYDAL account + membership
           → artisan exhibitions:create
           Prints the vault URL, both keys and a new curator's password ONCE —
           paste them into Full Frame's studio → "Connect an exhibition".

Tier-aware (runs artisan in the app container on the docker tier). Requires
the stack up — run tools/deploy/start.sh first.

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

# artisan ... : run a Laravel command in the right place for the tier
# (same wrapper as first_install.sh / reindex.sh).
artisan() {
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && php artisan "$@" )
  fi
}

artisan "exhibitions:$ACTION" "$@"
