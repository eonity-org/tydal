# @tydal/vault-mcp

**The machine face of one vault.** A vault-scoped Model Context Protocol server:
a customer's AI agent consumes exactly what the connected vault's purpose and
policy expose — identity (Tier 0), chunk content (Tier 1), minted binary URLs
(Tier 2) — and nothing else. **Read-only by default;** supply a *write key* and
the vault's purpose-exposed write ops (e.g. `ingest` on an `ai` vault) appear as
tools too — so **any** MCP-capable AI can read, transform, and write back
through one connection.

Narrower than [`@tydal/org-mcp`](../org-mcp/README.md), the org-wide management
surface (uploads, tagging, workspace CRUD): every op here is scoped to the one
vault and gated by the vault key. Spec: [`docs/architecture/VAULT_SYSTEM.md`](../docs/architecture/VAULT_SYSTEM.md) §7,
[`docs/architecture/VAULT_WRITE_METHODS.md`](../docs/architecture/VAULT_WRITE_METHODS.md).

## Connection

The connection is scoped to **one vault**. Published vaults connect keyless;
private vaults need a read key; write ops need a **write** key (both minted in
Platform Administration).

```jsonc
// claude_desktop_config.json / .mcp.json
{
  "mcpServers": {
    "expo-test-vault": {
      "command": "node",
      "args": ["/path/to/tydal/vault-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "http://localhost:8000",
        "TYDAL_VAULT": "acme/press-kit",              // or TYDAL_VAULT_HASH
        "TYDAL_VAULT_KEY": "tvk_…",                   // read: private vaults only
        "TYDAL_VAULT_WRITE_KEY": "tvk_…"              // write: turns on ingest (ai vaults)
      }
    }
  }
}
```

The same `env` block also works in Claude Code, Cursor, and any other MCP
client that supports local stdio servers — see
[`docs/CONNECTING_MCP_CLIENTS.md`](../docs/CONNECTING_MCP_CLIENTS.md) for the
exact file locations and the `claude mcp add` CLI form. (Not ChatGPT — it
requires a remote HTTPS server, which this isn't; see that doc for why.)

Omit `TYDAL_VAULT_WRITE_KEY` and the connection is strictly read-only — the write
tools are not even listed.

### Bring-your-own-AI (image transform)

Point any MCP-capable model at an `ai` vault with **both** keys: it reads the
source images (`get_`/`link_`/`read_`), transforms them however it likes, and
writes the result back with **`ingest`** (a translated image + a JSON descriptor
of tables/graphs/formulae). No product-side orchestrator, no hard-coded model —
the vault is the boundary, the AI is the customer's choice.

## Tools — the verb *is* the tier

| Verb | Tier | Tools |
|---|---|---|
| `get_` | 0 | `get_vault` (self-description — call it first) · `get_resource` · `get_file` |
| `list_` | 0 | `list_resources` · `list_files` · `list_related` · `list_chunks` (index only) |
| `search_` | 0 / 1 | `search_resources` · `search_chunks` — both take `mode: "keyword"` (default) or `"semantic"` (k-NN over the embeddings; degrades to keyword if unavailable, the response's `mode` says which answered) |
| `embed_` | 0 (compute) | `embed_query` (text → vector in the vault's embedding space; no data out) |
| `read_` | 1 / 2 | `read_chunks` (text, sequence range) · `read_image` (**streams a resource's pixels back as an image you can see** — Tier 2 inline preview, fetched server-side; requires the vault to expose binary) |
| `resolve` | 0 | `resolve` (URL / path / slug → identity card) |
| `link_` | 2 | `link_resource` · `link_file` (mint URLs — never stream binary; the caller must fetch the address) |
| `ingest` | write | `ingest` — write a derived image + JSON descriptor back (ai vaults). **Only listed when `TYDAL_VAULT_WRITE_KEY` is set.** |

The RAG loop falls out of the taxonomy: `search_*` (semantic for questions,
keyword for exact terms) → `read_chunks` for evidence → `link_*` only if the
task truly needs the artifact. Whether a tier answers at all is the vault's
policy (`ai` vaults deny Tier 2 by default; `gallery` vaults deny Tier 1) —
`get_vault` tells the agent up front, and when binary is exposed its response
adds a `binary_access` hint naming which tool is for you (`read_image`) and
which mints a URL for someone else (`link_resource`) — so that choice doesn't
have to be discovered the hard way, from a "Connection refused" after
guessing wrong.

## Image choices

`read_image` accepts `resource_slug` (the resource reference within the connected
vault), optional `rendition`, and optional `max_bytes`:

```json
{ "resource_slug": "RESOURCE_REFERENCE" }
{ "resource_slug": "RESOURCE_REFERENCE", "max_bytes": 5000000 }
{ "resource_slug": "RESOURCE_REFERENCE", "rendition": "original" }
{ "resource_slug": "RESOURCE_REFERENCE", "rendition": "medium" }
```

The default rendition is `ai-prepared`, with a **5 MiB** binary limit. Fitting
originals retain their bytes except PNG orientation normalization. Larger images
use JPEG quality 95, reducing dimensions only if needed. `max_bytes` accepts
65,536–20,971,520, only for `ai-prepared`. The budget excludes base64 overhead
and the rest of the provider request. Set a smaller budget when your provider
limits the whole payload. Preparation runs on demand; no cached copy is saved.

Explicit alternatives are `original`, `thumbnail`, `small`, `medium`, and `large`.
Use available versions from the resource card's `preview_renditions`; an absent
conversion returns an error, never an implicit original. Each result includes
an image block and a text block with JSON describing the actual rendition,
format, bytes, width/height (nullable), and AI byte limit. Original means the
resource's designated snapshot or rendered preview; other attached originals
remain accessible through the vault's file operations and policies.

All choices require binary exposure and retain the current vault access and
workspace membership checks. Existing `/preview` consumers keep their original
behavior; only MCP `read_image` changes its default. Rebuild and restart deployed
vault MCP servers to activate the updated tool definition.

## Development

`vault-mcp` is a member of the root npm workspace (`tydal/package.json`), so a
root `npm install` links its deps too. From `tydal/`:

```bash
npm install
npm test -w @tydal/vault-mcp        # Vitest, fetch-mocked — no backend needed
npm run build -w @tydal/vault-mcp   # tsc → dist/
```

Or, to build this alongside `@tydal/org-mcp` in one step, use
[`tools/deploy/install_mcp.sh`](../tools/deploy/install_mcp.sh) from the repo
root. Running the commands above directly inside `vault-mcp/` still works too.
