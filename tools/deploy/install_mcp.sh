#!/usr/bin/env bash
# TYDAL — build the two MCP servers (@tydal/org-mcp, @tydal/vault-mcp) so their
# dist/index.js entry points exist. Both are consumed by external MCP clients
# (Claude Desktop, Claude Code, Cursor, …) that point straight at that built
# file — see mcp.example.json and vault-mcp/README.md — so, unlike the
# frontend (hot-reloaded by Vite), they don't work until built.
#
# Separate from install.sh on purpose: most devs never touch the MCP surfaces,
# so install.sh's critical path stays install deps + build frontend + write
# .env + recreate containers. Run this whenever you want to use either MCP
# server, and again after pulling changes to org-mcp/vault-mcp.
set -euo pipefail

usage() {
  cat <<'EOF'
Usage: install_mcp.sh [-h|--help]

Installs workspace deps (if needed) and builds @tydal/org-mcp and
@tydal/vault-mcp. Both are self-contained (plain fetch, no @tydal/client
dependency) — they're built here only because they live in the same npm
workspace as the rest of the JS packages.

Prints the resulting entry points to point your MCP client config at.
EOF
}

for arg in "$@"; do
  case "$arg" in
    -h|--help) usage; exit 0 ;;
    *) echo "install_mcp.sh: unknown argument '$arg'" >&2; usage >&2; exit 1 ;;
  esac
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
. "$SCRIPT_DIR/../lib/tier.lib.sh"

echo "Installing workspace dependencies…"
run_npm "$ROOT" . install

echo
echo "Building @tydal/org-mcp, @tydal/vault-mcp…"
run_npm "$ROOT" org-mcp run build
run_npm "$ROOT" vault-mcp run build

echo
echo "================================================================================"
echo "Built:"
echo "  $ROOT/org-mcp/dist/index.js"
echo "  $ROOT/vault-mcp/dist/index.js"
echo
echo "Point your MCP client config at these — see mcp.example.json (org-wide"
echo "server) and vault-mcp/README.md (vault-scoped server, needs a vault + key)."
echo "Rebuild + restart your MCP client after any change to org-mcp/vault-mcp."
echo "================================================================================"
