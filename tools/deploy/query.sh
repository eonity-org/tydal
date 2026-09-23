#!/usr/bin/env bash
# TYDAL — demo/smoke test: log in and exercise the read endpoints, then trigger
# extraction + autotag and dump per-file Tika metadata for every resource.
#
# Configuration (env vars, with dev defaults):
#   TYDAL_API_BASE_URL         default http://localhost:8000/api/v1
#   TYDAL_SUPERADMIN_EMAIL     default superadmin@tydal.test
#   TYDAL_SUPERADMIN_PASSWORD  default 123super  (match your backend/.env!)
#
# Requires: curl, jq, a running backend (+ queue worker + Tika for extraction).
set -euo pipefail

BASE_URL="${TYDAL_API_BASE_URL:-http://localhost:8000/api/v1}"
EMAIL="${TYDAL_SUPERADMIN_EMAIL:-superadmin@tydal.test}"
PASSWORD="${TYDAL_SUPERADMIN_PASSWORD:-123super}"

echo "=== TYDAL Backend API Simple Test ==="
echo ""

# Pre-flight: Tika server reachable?
echo "🔍 Checking Tika server (localhost:9998)..."
TIKA_STATUS=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 http://localhost:9998)
if [ "$TIKA_STATUS" = "200" ] || [ "$TIKA_STATUS" = "302" ]; then
  echo "✅ Tika reachable (HTTP $TIKA_STATUS)"
else
  echo "⚠️  Tika not reachable (HTTP $TIKA_STATUS) — extraction jobs will fail"
fi
echo ""

# Login
echo "1️⃣  Logging in as $EMAIL..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"email\": \"$EMAIL\", \"password\": \"$PASSWORD\"}")

echo "$LOGIN_RESPONSE" | jq '.'

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.token // empty')
ORG_ID=$(echo "$LOGIN_RESPONSE" | jq -r '.data.user.current_organization_id // .data.user.organizations[0].id // empty')

if [ -z "$TOKEN" ] || [ "$TOKEN" = "null" ]; then
  echo "❌ Login failed (check TYDAL_SUPERADMIN_PASSWORD matches backend/.env)"
  exit 1
fi

echo "✅ Login successful"
echo "   Token : ${TOKEN:0:50}..."
echo "   Org ID: $ORG_ID"
echo ""

AUTH=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")

echo "2️⃣  Getting current user..."
curl -s -X GET "$BASE_URL/me" "${AUTH[@]}" | jq '.'
echo ""

echo "3️⃣  Listing organizations..."
ORGS_RESPONSE=$(curl -s -X GET "$BASE_URL/organizations" "${AUTH[@]}")
ORG_COUNT=$(echo "$ORGS_RESPONSE" | jq -r '(.data.organizations | length) // 0')
echo "Found $ORG_COUNT organization(s)"
echo ""

echo "4️⃣  Switching to organization $ORG_ID..."
curl -s -X POST "$BASE_URL/organizations/$ORG_ID/switch" "${AUTH[@]}" | jq '.'
echo ""

echo "5️⃣  Listing collections..."
COLLECTIONS_RESPONSE=$(curl -s -X GET "$BASE_URL/collections" "${AUTH[@]}")
echo "$COLLECTIONS_RESPONSE" | jq '.'
COLLECTION_ID=$(echo "$COLLECTIONS_RESPONSE" | jq -r '.data.collections[0].id // empty')
echo "Using collection: $COLLECTION_ID"
echo ""

echo "6️⃣  Listing resources..."
RESOURCES_RESPONSE=$(curl -s -X GET "$BASE_URL/resources?limit=200" "${AUTH[@]}")
RESOURCE_IDS=$(echo "$RESOURCES_RESPONSE" | jq -r '.data.resources[]?.id // empty')
RESOURCE_COUNT=$(echo "$RESOURCES_RESPONSE" | jq -r '(.data.resources | length) // 0')
echo "Found $RESOURCE_COUNT resource(s)"
echo ""

echo "7️⃣  Listing categories..."
curl -s -X GET "$BASE_URL/categories" "${AUTH[@]}" | jq '.'
echo ""

echo "8️⃣  Listing collection schemes..."
curl -s -X GET "$BASE_URL/collection-schemes" "${AUTH[@]}" | jq '.'
echo ""

echo "9️⃣  Listing search indexes..."
curl -s -X GET "$BASE_URL/search-indexes" "${AUTH[@]}" | jq '.'
echo ""

echo "🔄 Triggering Tika extraction + autotag for all resources..."
if [ -z "$RESOURCE_IDS" ]; then
  echo "⚠️  No resources — skipping"
else
  while IFS= read -r RID; do
    [ -z "$RID" ] && continue
    S_EXTRACT=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/resources/$RID/extract" "${AUTH[@]}")
    S_AUTOTAG=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE_URL/resources/$RID/autotag" "${AUTH[@]}")
    echo "  $RID → extract: $S_EXTRACT  autotag: $S_AUTOTAG"
  done <<< "$RESOURCE_IDS"
fi
echo "  ℹ️  Jobs queued — run 'php artisan queue:work' if worker is not running"
echo ""

echo "🔟  Per-file Tika metadata — all resources..."
if [ -z "$RESOURCE_IDS" ]; then
  echo "⚠️  No resources found — skipping"
else
  while IFS= read -r RID; do
    [ -z "$RID" ] && continue
    RDATA=$(curl -s -X GET "$BASE_URL/resources/$RID" "${AUTH[@]}")
    RNAME=$(echo "$RDATA" | jq -r '.data.resource.name // "unnamed"')
    echo "── Resource: $RNAME ($RID)"
    echo "$RDATA" | jq -r '
      .data.resource.files[]?
      | "  File: \(.filename // "unnamed")  mime=\(.mime_type // "?")  promoting=\(.is_promoting // false)\n"
      + if .latest_tika_system_file == null
        then "    ⚠ no tika system file (extraction not run)"
        elif (.latest_tika_system_file.metadata.tika_metadata | (type == "object" and length > 0))
        then (
          .latest_tika_system_file.metadata.tika_metadata
          | to_entries
          | map("    \(.key): \(.value | if type == "array" then join(", ") else tostring end)")
          | join("\n")
        )
        else "    ⚠ tika system file exists but metadata is empty"
        end
    '
    echo ""
  done <<< "$RESOURCE_IDS"
fi
echo "=== Test Complete ==="
