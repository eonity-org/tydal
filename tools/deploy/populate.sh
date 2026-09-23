#!/usr/bin/env bash
# TYDAL — demo: log in, list entities, create a resource, optionally upload a file.
#
# Configuration (env vars, with sensible dev defaults):
#   TYDAL_API_BASE_URL   default http://localhost:8000/api/v1
#   TYDAL_SUPERADMIN_EMAIL     default superadmin@tydal.test
#   TYDAL_SUPERADMIN_PASSWORD  default 123super  (match your backend/.env!)
#
# Usage:
#   tools/deploy/populate.sh [path/to/image]
#   The optional file path is uploaded to the created resource; if omitted,
#   the upload step is skipped.
#
# Requires: curl, jq, and a running backend (+ queue worker for extraction).
set -euo pipefail

BASE_URL="${TYDAL_API_BASE_URL:-http://localhost:8000/api/v1}"
EMAIL="${TYDAL_SUPERADMIN_EMAIL:-superadmin@tydal.test}"
PASSWORD="${TYDAL_SUPERADMIN_PASSWORD:-123super}"
IMAGE="${1:-}"

echo "=== TYDAL Populate Script ==="
echo ""

# Login
echo "1️⃣  Logging in as $EMAIL..."
LOGIN_RESPONSE=$(curl -s -X POST "$BASE_URL/login" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d "{\"email\": \"$EMAIL\", \"password\": \"$PASSWORD\"}")

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.data.token // empty')
ORG_ID=$(echo "$LOGIN_RESPONSE" | jq -r '.data.user.current_organization_id // .data.user.organizations[0].id // empty')

if [ -z "$TOKEN" ] || [ "$TOKEN" = "null" ]; then
  echo "❌ Login failed (check TYDAL_SUPERADMIN_PASSWORD matches backend/.env)"
  echo "$LOGIN_RESPONSE" | jq '.'
  exit 1
fi

echo "✅ Login successful"
echo "   Token : ${TOKEN:0:50}"
echo "   Org ID: $ORG_ID"
echo ""

AUTH=(-H "Authorization: Bearer $TOKEN" -H "Accept: application/json")

echo "2️⃣  Getting current user..."
curl -s -X GET "$BASE_URL/me" "${AUTH[@]}" | jq '.data.user | {id, name, email, is_superadmin}'
echo ""

echo "3️⃣  Listing organizations..."
ORGS_RESPONSE=$(curl -s -X GET "$BASE_URL/organizations" "${AUTH[@]}")
ORG_COUNT=$(echo "$ORGS_RESPONSE" | jq -r '(.data.organizations | length) // 0')
echo "Found $ORG_COUNT organization(s)"
echo ""

echo "4️⃣  Switching to organization $ORG_ID..."
curl -s -X POST "$BASE_URL/organizations/$ORG_ID/switch" "${AUTH[@]}" | jq '{success, message}'
echo ""

echo "5️⃣  Listing collections..."
COLLECTIONS_RESPONSE=$(curl -s -X GET "$BASE_URL/collections" "${AUTH[@]}")
COLLECTION_ID=$(echo "$COLLECTIONS_RESPONSE" | jq -r '.data.collections[0].id // empty')
echo "$COLLECTIONS_RESPONSE" | jq '.data.collections[] | {id, name, scheme_id, index_id}'
echo "Using collection: $COLLECTION_ID"
echo ""

echo "6️⃣  Listing resources..."
RESOURCES_RESPONSE=$(curl -s -X GET "$BASE_URL/resources" "${AUTH[@]}")
RESOURCE_COUNT=$(echo "$RESOURCES_RESPONSE" | jq -r '(.data.resources | length) // 0')
echo "Found $RESOURCE_COUNT resource(s)"
echo ""

echo "7️⃣  Listing categories..."
curl -s -X GET "$BASE_URL/categories" "${AUTH[@]}" | jq '{total: (.data.categories | length)}'
echo ""

echo "8️⃣  Listing collection schemes..."
curl -s -X GET "$BASE_URL/collection-schemes" "${AUTH[@]}" | jq '.data.schemes[] | {id, name, display_name, accepted_mimetypes}'
echo ""

echo "9️⃣  Listing search indexes (Elasticsearch)..."
curl -s -X GET "$BASE_URL/search-indexes" "${AUTH[@]}" | jq '.data.indexes[] | {id, index_name, display_name, is_active}'
echo ""

echo "🔟  Creating new resource..."
RESOURCE=$(curl -s -X POST "$BASE_URL/resources" \
    "${AUTH[@]}" \
    -H "Content-Type: application/json" \
    -d '{
      "collection_id": "'"$COLLECTION_ID"'",
      "name": "My Image Resource",
      "type": "image",
      "visibility": "organization",
      "description": "A sample image resource"
    }')

echo "$RESOURCE" | jq '.data.resource | {id, name, type, visibility}'
RESOURCE_ID=$(echo "$RESOURCE" | jq -r '.data.resource.id // empty')

if [ -z "$RESOURCE_ID" ] || [ "$RESOURCE_ID" = "null" ]; then
  echo "❌ Resource creation failed"
  echo "$RESOURCE" | jq '.'
  exit 1
fi
echo "✅ Resource created: $RESOURCE_ID"
echo ""

# Optional file upload
if [ -n "$IMAGE" ]; then
  if [ ! -f "$IMAGE" ]; then
    echo "⚠️  File not found: $IMAGE — skipping upload"
  else
    echo "1️⃣1️⃣  Uploading file: $IMAGE ..."
    UPLOAD=$(curl -s -X POST "$BASE_URL/resources/$RESOURCE_ID/files" \
        "${AUTH[@]}" \
        -F "File=@$IMAGE" \
        -F "role=canonical")
    echo "$UPLOAD" | jq '.data.file | {id, filename, mime_type, role, size}'
    echo ""
  fi
else
  echo "ℹ️  No file path given — skipping upload. Pass one as the first argument."
  echo ""
fi

echo "=== Populate Complete ==="
echo "   Resource ID : $RESOURCE_ID"
echo "   Queue worker must be running for extraction + embedding to proceed:"
echo "   php artisan queue:work --timeout=180"
