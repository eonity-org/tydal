#!/usr/bin/env bash
# TYDAL — seed a test vault from a folder of real archives (PDFs + images).
# Goes through the REAL API pipeline (not direct DB inserts): each file becomes
# a resource + canonical file upload, so Tika extraction, chunking, embedding
# and auto-tagging run exactly as they would for a user upload. Then a
# workspace groups the resources, a public mixed-purpose vault projects the
# workspace, and the vault's ES index is rebuilt once processing settles.
# Requires the stack up (start.sh) AND the queue worker running. See
# tools/README.md.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: seed-vault.sh <folder> [vault-slug]

Seed a test vault from a folder of PDFs and images (top level of <folder>;
.pdf .jpg .jpeg .png .gif .webp). Creates, via the API as superadmin:

  1. one resource + canonical file upload per archive (full enrichment pipeline)
  2. a workspace "<slug>-ws" holding all of them
  3. a public, downloadable, mixed-purpose vault "<slug>" projecting the
     workspace, then waits for extraction/embedding and rebuilds the vault index

vault-slug defaults to the folder name. Re-running with the same slug fails on
the vault create (slugs are unique) — pick a new slug or delete the vault first.

Environment: TYDAL_API_BASE_URL (default http://localhost:8000/api/v1),
TYDAL_SUPERADMIN_EMAIL, TYDAL_SUPERADMIN_PASSWORD (default: read from
backend/.env), TYDAL_COLLECTION_ID (default: first collection).

Requires: curl, jq, running backend + queue worker (extraction/embedding are
queued jobs — without the worker the wait step never completes).

  -h, --help   show this help and exit
EOF
}

for arg in "$@"; do
  case "$arg" in -h|--help) usage; exit 0 ;; esac
done

if [ $# -lt 1 ]; then usage; exit 1; fi
FOLDER="$1"
[ -d "$FOLDER" ] || { echo "Not a folder: $FOLDER" >&2; exit 1; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
BACKEND_DIR="$ROOT/backend"
COMPOSE="$ROOT/docker-compose.yml"

BASE_URL="${TYDAL_API_BASE_URL:-http://localhost:8000/api/v1}"
EMAIL="${TYDAL_SUPERADMIN_EMAIL:-superadmin@tydal.test}"
PASSWORD="${TYDAL_SUPERADMIN_PASSWORD:-$(grep '^TYDAL_SUPERADMIN_PASSWORD=' "$BACKEND_DIR/.env" 2>/dev/null | cut -d= -f2 || true)}"
[ -n "$PASSWORD" ] || { echo "Set TYDAL_SUPERADMIN_PASSWORD (backend/.env not readable)." >&2; exit 1; }

# Slugify the folder name: lowercase, non-alphanumerics → hyphens
DEFAULT_SLUG="$(basename "$FOLDER" | tr '[:upper:]' '[:lower:]' | sed -e 's/[^a-z0-9]\{1,\}/-/g' -e 's/^-//' -e 's/-$//')"
SLUG="${2:-$DEFAULT_SLUG}"

command -v jq >/dev/null || { echo "jq is required." >&2; exit 1; }

# ── Collect archives ─────────────────────────────────────────────────────────
FILES=()
while IFS= read -r f; do FILES+=("$f"); done < <(
  find "$FOLDER" -maxdepth 1 -type f \
    \( -iname '*.pdf' -o -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' \
       -o -iname '*.gif' -o -iname '*.webp' \) | sort
)
[ "${#FILES[@]}" -gt 0 ] || { echo "No PDFs or images found in $FOLDER" >&2; exit 1; }
echo "Seeding vault '$SLUG' from ${#FILES[@]} archive(s) in $FOLDER"

# ── Login ────────────────────────────────────────────────────────────────────
LOGIN=$(curl -s -X POST "$BASE_URL/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"email\": \"$EMAIL\", \"password\": \"$PASSWORD\"}")
TOKEN=$(echo "$LOGIN" | jq -r '.data.token // empty')
ORG_ID=$(echo "$LOGIN" | jq -r '.data.user.current_organization_id // .data.user.organizations[0].id // empty')
[ -n "$TOKEN" ] || { echo "Login failed:" >&2; echo "$LOGIN" | jq '.' >&2; exit 1; }
AUTH=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")
curl -s -X POST "$BASE_URL/organizations/$ORG_ID/switch" "${AUTH[@]}" >/dev/null
ORG_SLUG=$(curl -s "$BASE_URL/organizations/$ORG_ID" "${AUTH[@]}" | jq -r '.data.organization.slug')
echo "✓ Logged in ($EMAIL, org: $ORG_SLUG)"

# ── Collection ───────────────────────────────────────────────────────────────
COLLECTION_ID="${TYDAL_COLLECTION_ID:-$(curl -s "$BASE_URL/collections" "${AUTH[@]}" | jq -r '.data.collections[0].id // empty')}"
[ -n "$COLLECTION_ID" ] || { echo "No collection found — seed the base data first (first_install.sh)." >&2; exit 1; }
echo "✓ Collection: $COLLECTION_ID"

# ── Workspace ────────────────────────────────────────────────────────────────
WS=$(curl -s -X POST "$BASE_URL/workspaces" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d "{\"name\": \"$SLUG ws\", \"slug\": \"$SLUG-ws\", \"description\": \"Seeded from $(basename "$FOLDER")\"}")
WS_ID=$(echo "$WS" | jq -r '.data.workspace.id // empty')
[ -n "$WS_ID" ] || { echo "Workspace creation failed:" >&2; echo "$WS" | jq '.' >&2; exit 1; }
echo "✓ Workspace: $SLUG-ws (id: $WS_ID)"

# ── Resources + uploads ──────────────────────────────────────────────────────
for f in "${FILES[@]}"; do
  base="$(basename "$f")"
  name="${base%.*}"
  case "$(echo "${base##*.}" | tr '[:upper:]' '[:lower:]')" in
    pdf) type="document" ;;
    *)   type="image" ;;
  esac

  RES=$(curl -s -X POST "$BASE_URL/resources" "${AUTH[@]}" -H 'Content-Type: application/json' \
    -d "{\"collection_id\": \"$COLLECTION_ID\", \"name\": \"$name\", \"type\": \"$type\", \"state\": \"live\"}")
  RES_ID=$(echo "$RES" | jq -r '.data.resource.id // empty')
  if [ -z "$RES_ID" ]; then
    echo "  ✗ $base — resource creation failed: $(echo "$RES" | jq -c '.message // .errors // .')" >&2
    continue
  fi

  UPLOAD=$(curl -s -X POST "$BASE_URL/resources/$RES_ID/files" "${AUTH[@]}" \
    -F "File=@$f" -F "role=canonical")
  FILE_ID=$(echo "$UPLOAD" | jq -r '.data.file.id // empty')
  if [ -z "$FILE_ID" ]; then
    echo "  ✗ $base — upload failed: $(echo "$UPLOAD" | jq -c '.message // .errors // .')" >&2
    continue
  fi

  curl -s -X POST "$BASE_URL/workspaces/$WS_ID/resources" "${AUTH[@]}" -H 'Content-Type: application/json' \
    -d "{\"resource_id\": \"$RES_ID\"}" >/dev/null
  echo "  ✓ $base → resource $RES_ID ($type)"
done

# ── Vault ────────────────────────────────────────────────────────────────────
VAULT=$(curl -s -X POST "$BASE_URL/platform/vaults" "${AUTH[@]}" -H 'Content-Type: application/json' \
  -d "{\"organization_id\": \"$ORG_ID\", \"name\": \"$SLUG\", \"slug\": \"$SLUG\", \"purpose\": \"mixed\", \"state\": \"public\", \"is_downloadable\": true, \"workspace_ids\": [$WS_ID], \"description\": \"Seeded test vault\"}")
VAULT_ID=$(echo "$VAULT" | jq -r '.data.vault.id // empty')
VAULT_HASH=$(echo "$VAULT" | jq -r '.data.vault.hash // empty')
[ -n "$VAULT_ID" ] || { echo "Vault creation failed:" >&2; echo "$VAULT" | jq '.' >&2; exit 1; }
echo "✓ Vault: $SLUG (hash: $VAULT_HASH, purpose: mixed, public) ← workspace $SLUG-ws"

# ── Wait for the enrichment pipeline (queue worker) ──────────────────────────
echo "Waiting for extraction/embedding (queue worker must be running)..."
DEADLINE=$(( $(date +%s) + 1800 ))
while :; do
  STATUS=$(curl -s "$BASE_URL/workspaces/$WS_ID/aity-status" "${AUTH[@]}")
  PROCESSING=$(echo "$STATUS" | jq -r '.data.processing // 0')
  TOTAL=$(echo "$STATUS" | jq -r '.data.total // 0')
  if [ "$PROCESSING" = "0" ]; then break; fi
  if [ "$(date +%s)" -ge "$DEADLINE" ]; then
    echo "  still processing after 30 min — continuing anyway (re-run 'search:reindex --vault=$SLUG' later)" >&2
    break
  fi
  printf '  %s/%s resources still processing...\r' "$PROCESSING" "$TOTAL"
  sleep 5
done
echo ""

# ── Rebuild the vault's projected index (tier-aware artisan) ─────────────────
. "$SCRIPT_DIR/../lib/tier.lib.sh"
INFRA="$(detect_infra "$BACKEND_DIR/.env" "$COMPOSE")"
artisan() {
  if [ "$INFRA" = "docker" ]; then
    docker compose -f "$COMPOSE" exec -T -w /var/www/html app php artisan "$@"
  else
    ( cd "$BACKEND_DIR" && php artisan "$@" )
  fi
}
artisan search:reindex --vault="$SLUG"

# ── Summary ──────────────────────────────────────────────────────────────────
WEB_ROOT="${BASE_URL%/api/v1}"
cat <<EOF

Done. Vault '$SLUG' is live:
  boundary : $WEB_ROOT/v/$ORG_SLUG/$SLUG/meta
  machine  : $WEB_ROOT/h/$VAULT_HASH
  gallery  : http://localhost:3010/?vault=$ORG_SLUG/$SLUG
  obsidian : http://localhost:3011/?vault=$ORG_SLUG/$SLUG
  aity     : http://localhost:3012/?vault=$ORG_SLUG/$SLUG
(clients not running? → ./clients.sh)
EOF
