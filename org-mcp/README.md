# TYDAL Org MCP Server

A [Model Context Protocol](https://modelcontextprotocol.io) server that exposes TYDAL workspace
intelligence and asset management to AI agents, scoped to one organization.

Compatible with **Claude Desktop**, **Claude Code**, **Cursor**, and any other MCP-capable client.

---

## Tools

| Tool | Type | Description |
|---|---|---|
| `list_workspaces` | read | List workspaces in the organisation |
| `browse_workspace` | read | Page through resources with facet filters |
| `search_workspace` | read | Hybrid semantic + keyword search within a workspace |
| `get_resource` | read | Full metadata for a resource in one call — files with direct URLs, tags, slug, inlined Vault links, chunk/embedding availability |
| `list_resource_chunks` | read | Raw extracted text in reading order, with page numbers — not synthesized |
| `get_vault_links` | read | Public Vault URLs for a resource and its files (standalone, if you don't need the rest of `get_resource`) |
| `ask_workspace` | read | RAG question-answering over indexed documents |
| `ask_vault` | read | AITY's vault-aware RAG: retrieval through the vault's own tier-gated operations (semantic chunks + identity cards + graph neighbors), resource-level citations |
| `create_workspace` | write | Create a new workspace |
| `add_resource_to_workspace` | write | Add a resource to a workspace |
| `remove_resource_from_workspace` | write | Remove a resource from a workspace |
| `update_resource_metadata` | write | Update name, description, metadata fields |
| `sync_tags` | write | Set semantic tags by label (creates missing tags automatically) |
| `upload_file` | write | Upload a local file and attach it to a resource |

---

## Installation

`org-mcp` is a member of the root npm workspace (`tydal/package.json`). It's
self-contained (plain `fetch`, no `@tydal/client` dependency) — the workspace
install just links deps, no build ordering to worry about:

```bash
# From the tydal/ repo root
npm install
npm run build -w @tydal/org-mcp
```

Or use [`tools/deploy/install_mcp.sh`](../tools/deploy/install_mcp.sh), which
does the above plus builds `@tydal/vault-mcp` in one step. Running `npm install
&& npm run build` directly inside `org-mcp/` still works too.

---

## Configuration

All configuration is via environment variables:

| Variable | Required | Description |
|---|---|---|
| `TYDAL_TOKEN` | ✅ | Sanctum personal access token |
| `TYDAL_ORG_ID` | ✅ | Organisation UUID |
| `TYDAL_BASE_URL` | optional | API base URL (default: `http://localhost:8000/api/v1`) |

### Generating a token

The recommended way is a **scoped API key on a dedicated machine user**, issued
from the backend:

```bash
# From tydal/backend — prints the key once plus a ready-to-paste env block
php artisan mcp:token                                  # read,ask on mcp@tydal.test (editor, first org)
php artisan mcp:token --abilities=read,ask,write       # also enable the write tools
php artisan mcp:token --org=tydal --days=90            # scope to an org, expire in 90 days
php artisan mcp:token --name=claude-desktop --abilities=read,ask,write --days=90   # named — see below
```

Abilities map to tool groups: `read` (list/browse/search/get/vault-links),
`ask` (RAG question-answering), `write` (create/add/remove/update/tags/upload).
Requests outside the key's abilities get a `403`.

Give it a `--name` matching the client or machine it's for (e.g.
`claude-desktop`) — re-issuing with the same `--name` **revokes the previous
token and issues a fresh one**, so rotating a compromised or expiring token is
just re-running the same command, instead of accumulating orphaned tokens
under generic names.

Keys can also be managed over the API with a session token:
`GET/POST /api/v1/tokens`, `DELETE /api/v1/tokens/{id}`.

Alternatively, any user token works: the token owner's permissions govern which
workspaces and resources the MCP server can access. Session tokens expire
hourly, so prefer issued API keys — they survive interactive logins and never
gain more than their granted abilities.

---

## Claude Desktop configuration

Copy the relevant snippet to your Claude Desktop config file:

- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

The same `env` block also works in Claude Code, Cursor, and any other
MCP client that supports local stdio servers — see
[`docs/CONNECTING_MCP_CLIENTS.md`](../docs/CONNECTING_MCP_CLIENTS.md) for the
exact file locations and the `claude mcp add` CLI form. (Not ChatGPT — it
requires a remote HTTPS server, which this isn't; see that doc for why.)

---

### Use case 1 — Exhibition / gallery / jury

```json
{
  "mcpServers": {
    "tydal-gallery": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/org-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-tydal-instance.com/api/v1",
        "TYDAL_TOKEN":    "your-sanctum-token",
        "TYDAL_ORG_ID":   "your-organisation-uuid"
      }
    }
  }
}
```

**Example agent workflow:**
1. `list_workspaces` → find the submission workspace
2. `browse_workspace` → show each photo to the jury
3. `get_vault_links` → surface full-resolution Vault URL per photo
4. `create_workspace` → "Final Exhibition" workspace based on jury votes
5. `add_resource_to_workspace` (× n) → populate the final workspace

---

### Use case 2 — Virtual tutor (RAG)

```json
{
  "mcpServers": {
    "tydal-tutor": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/org-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-tydal-instance.com/api/v1",
        "TYDAL_TOKEN":    "your-sanctum-token",
        "TYDAL_ORG_ID":   "your-organisation-uuid"
      }
    }
  }
}
```

**Example agent workflow:**
1. `ask_workspace` (workspace = course materials) → answer + source passages
2. `get_vault_links(source.resource_id)` → deep link to the exact PDF for the student
3. `get_resource(source.resource_id)` → show resource title alongside the answer

---

### Use case 3 — Repository administration (AI agent)

```json
{
  "mcpServers": {
    "tydal-admin": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/org-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-tydal-instance.com/api/v1",
        "TYDAL_TOKEN":    "your-admin-sanctum-token",
        "TYDAL_ORG_ID":   "your-organisation-uuid"
      }
    }
  }
}
```

**Example agent workflow:**
1. `search_workspace` → find candidate resources by topic
2. `create_workspace` → curated collection workspace
3. `add_resource_to_workspace` (× n) → populate it
4. `sync_tags` → classify each resource with relevant labels
5. `update_resource_metadata` → enrich name/description/metadata fields
6. `upload_file` → attach a generated summary PDF

---

## Development

```bash
# Run directly from TypeScript source (no build step)
TYDAL_TOKEN=xxx TYDAL_ORG_ID=yyy npm run dev
```
