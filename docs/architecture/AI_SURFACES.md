# AI Surfaces — the three ways to work with AI in TYDAL

TYDAL does not have *one* AI integration. It has three, and they differ in **who
runs the model**, **what the AI is allowed to touch**, and **who owns the
result**. Picking the wrong one is the usual source of "why is this so hard" —
each of the three is easy for its own job.

| | **1 · AITY** | **2 · Org MCP** | **3 · Vault MCP** |
|---|---|---|---|
| Who runs the model | TYDAL (server-side, on your behalf) | The user's AI client | The customer's AI client |
| Who chose the model | The operator, in `.env` | Whoever configured the client | The consumer |
| Trigger | Automatic, on upload | Conversational, on demand | Conversational, on demand |
| Auth | Server config | Sanctum API key (`mcp:token`) scoped to an org + abilities | Vault key, scoped to **one** vault |
| Scope | One file's own content | Everything the token owner can reach in the org | Exactly what the vault's purpose and policy expose |
| Writes | `ai_suggested_*`, pending review | Final values, immediately | Purpose's write ops (`ingest`), into a vault-configured landing spot |
| Package | `backend/app/Services/LLM/` + jobs | [`@tydal/org-mcp`](../../org-mcp/README.md) | [`@tydal/vault-mcp`](../../vault-mcp/README.md) |

They compose. A resource can be enriched by AITY on upload, corrected by an
agent over the org MCP, and then consumed by a customer's own model through a
vault — the three surfaces write to the same resources through different doors.

---

## 1 · AITY — server-side enrichment with suggestions

**Use it when** you upload a lot and want name/description/tags proposed for you
without anyone opening a chat client. It is the only surface that runs
*unattended*.

AITY is TYDAL's own pipeline, but the model behind it is **not** TYDAL's — every
driver is an external service you choose and configure. "Internal" here means
*the orchestration is internal*, not the intelligence.

### Drivers

Configured in `config/llm.php`, resolved by `App\Services\LLM\LlmDriverFactory`
against the `LlmServiceInterface` contract (`chat`, `chatWithVision`,
`getModel`; `StreamingLlmInterface` is opt-in and degrades to a single `chat()`
where unsupported).

| Driver | `LLM_TEXT_DRIVER` | Notes |
|---|---|---|
| Anthropic | `claude` | `ANTHROPIC_BASE_URL` override → any Anthropic-compatible endpoint |
| OpenAI | `openai` | `OPENAI_BASE_URL` override → Azure, LiteLLM, vLLM, OpenRouter, any OpenAI-compatible proxy |
| Google | `gemini` | |
| Z.ai | `zai` | |
| Ollama | `ollama` | Local; separate `OLLAMA_LLM_MODEL` / `OLLAMA_VISION_MODEL`, longer timeouts |

Because `claude` and `openai` both honour a base-URL override, most "can we use
*X*?" questions are answered by two env vars and no code. Adding a genuinely
new protocol means one class implementing `LlmServiceInterface` plus one arm in
`LlmDriverFactory::make()`.

`LLM_VISION_DRIVER` selects a **separate** driver for image analysis — the point
being you can run chat on a local Ollama and still send images to a cloud vision
model (or the reverse, for cost or privacy).

Embeddings are configured independently in `config/embedding.php`
(`EMBEDDING_DRIVER`: `ollama` · `voyage` · `jina`) — chunk vectors for semantic
search, not part of the suggestion path.

### The pipeline

```
upload → AityEnrichmentService::enrich(File)
       → ExtractFileText            (Tika: text + metadata)
       → AutoTagResource            (LLM ← extracted text)
         AnalyzeImageContent        (vision LLM ← image pixels)
         EmbedFileChunks            (embedding driver)
       → ai_suggested_{name,description,tags,metadata}   ← SystemFiles, not fields
       → AutoApprovalService  or  human review
       → applied to the resource; AityStatus advances
```

The distinguishing feature is the **suggestion buffer**. AITY never writes the
resource directly: output lands as `ai_suggested_*` SystemFiles that a human (or
`AutoApprovalService`) promotes. That gives you a review queue, provenance
(`applied_by_aity` vs. a person), and a per-resource state machine — `AityStatus`:
`not_applicable` → `queued` → `aity_in_progress` → `suggestions_made` →
`automatic_review_done` | `user_review_done`.

`AITY_RAG_STRICT_MODE` (default `true`, overridable per organization via
`organizations.settings.aity.rag_strict_mode`) decides whether *unreviewed*
suggestions are allowed into RAG metadata chunks. Strict keeps retrieval
human-confirmed; non-strict makes fresh uploads searchable before anyone reviews
them, accepting occasional hallucinated metadata.

### Configuration

```dotenv
LLM_TEXT_DRIVER=claude               # claude | openai | gemini | zai | ollama
LLM_VISION_DRIVER=              # optional; defaults to LLM_TEXT_DRIVER
AUTOTAGGING_ENABLED=true        # off by default
AI_VISION_ENABLED=true          # off by default — separate cost switch
AUTOTAGGING_MAX_CHARS=6000      # text budget sent to the model
AUTOTAGGING_MAX_TAGS=10
AI_DEBUG=false                  # storage/logs/ai-activity.log — logs full prompts; dev only
```

Both feature flags default **off**: a fresh install extracts text and indexes it
but makes no LLM calls until you opt in.

Requires a running queue worker (`php artisan queue:work --timeout=360`) —
without one, uploads sit at `queued` forever.

### Surface

- `POST /resources/{id}/aity-enrich` · `…/files/{fid}/aity-enrich` — full pipeline
- `POST /resources/{id}/aity-retag` — LLM only, reuses existing extraction
- `GET /resources/{id}/files/{fid}/aity-status` — poll stage + suggestions
- `GET /resources/aity-status` — bulk status for a list of resource ids
- `POST /resources/{id}/aity-approve` — promote suggestions to fields
- `GET /workspaces/{id}/aity-status` · `GET /workspaces/aity-batches`
- `POST /aity/chat` · `POST /aity/auto-approve[/stream|/dispatch]`
- Platform (superadmin): `GET /platform/ai/config`, `POST /platform/ai/test/{chat,vision,embedding}`

---

## 2 · Organization MCP — an agent that works like a person

**Use it when** you want an AI to *do the job of a user*: curate workspaces,
write titles and descriptions, tag, upload. This is the general-purpose answer,
and it fully covers what AITY produces — an agent with `write` can set name,
description and tags itself, so AITY is a convenience, not a prerequisite.

[`@tydal/org-mcp`](../../org-mcp/README.md) is a stdio MCP server that any MCP-capable
client (Claude Desktop, Claude Code, Cursor, …) connects to. It holds an org
API key and speaks the same REST API the SPA does.

### Tools

| Tool | Group | |
|---|---|---|
| `list_workspaces` · `browse_workspace` · `search_workspace` | read | inventory, faceted paging, hybrid search |
| `get_resource` · `list_resource_chunks` · `get_vault_links` | read | full metadata, raw extracted text in reading order, public URLs |
| `ask_workspace` · `ask_vault` | ask | RAG over indexed documents; `ask_vault` retrieves through the vault's own tier-gated ops with resource-level citations |
| `create_workspace` · `add_resource_to_workspace` · `remove_resource_from_workspace` | write | curation |
| `update_resource_metadata` · `sync_tags` | write | **name, description, scheme fields, language, state; tags by label** |
| `upload_file` | write | attach a local file to a resource |

> ⚠️ No tool here fetches binary bytes server-side and returns them inline —
> unlike `vault-mcp`'s `read_image` (§3 below), which exists specifically
> for that. `get_resource`/`browse_workspace`/`get_vault_links` only ever
> return `storage/`-path URLs; **the calling AI can never actually see an
> image through `org-mcp`**, only fetch metadata and extracted text about it
> (`list_resource_chunks`, `ask_workspace`/`ask_vault`). That matches this
> surface's documented use cases (curate, tag, RAG over text) — none require
> visual inspection — but a workflow that does ("look at this image and
> improve its description") needs a separate `vault-mcp` connection to the
> same data today.

`update_resource_metadata` + `sync_tags` are the direct substitute for an AITY
pass — with the difference that they write **final values**, not suggestions.
There is no review queue and no `ai_suggested_*` record: the change is
indistinguishable from a human edit, because that is exactly what this surface
models. Choose accordingly — AITY when you want a human gate, org MCP when you
want the agent to be trusted like a user.

### Connecting

```bash
# From tydal/backend — prints the key once, plus a ready-to-paste env block
php artisan mcp:token --org=SLUG --abilities=read,ask,write --days=90
```

Abilities map to the tool groups above; calls outside the key's abilities get a
`403`. Keys can also be managed over the API (`GET/POST /api/v1/tokens`,
`DELETE /api/v1/tokens/{id}`).

```jsonc
{
  "mcpServers": {
    "tydal-org": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/org-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-instance.example.com/api/v1",
        "TYDAL_TOKEN":    "…",
        "TYDAL_ORG_ID":   "…"
      }
    }
  }
}
```

Prefer an issued API key on a dedicated machine user over a personal session
token: session tokens expire hourly, and an issued key never gains more than its
granted abilities. The token owner's permissions still govern which workspaces
and resources are reachable — the key narrows, it never widens.

**Boundary:** the whole organization, filtered by the token owner's role. This
surface is for *your* agents. It is not what you hand to a customer.

---

## 3 · Vault MCP — publish a projection, let their AI work on it

**Use it when** the AI belongs to someone else, or when you want a model to
operate on a curated slice with a contract you control. Publish resources
through a vault; the vault — not the caller — decides what is legible and where
output may land.

[`@tydal/vault-mcp`](../../vault-mcp/README.md) is scoped to **one vault**.
Published vaults connect keyless; private vaults need a read key. It is
**read-only by default** — write tools are not even listed until a write key is
supplied.

### Tools — the verb is the tier

| Verb | Tier | Tools |
|---|---|---|
| `get_` | 0 | `get_vault` (self-description — call it first) · `get_resource` · `get_file` |
| `list_` | 0 | `list_resources` · `list_files` · `list_related` · `list_chunks` |
| `search_` | 0/1 | `search_resources` · `search_chunks` — `mode: keyword \| semantic` |
| `embed_` | 0 | `embed_query` — text → vector in the vault's space; no data leaves |
| `read_` | 1/2 | `read_chunks` (text) · `read_image` (streams pixels the model can actually see) |
| `resolve` | 0 | URL / path / slug → identity card |
| `link_` | 2 | `link_resource` · `link_file` — mint URLs, never stream binary |
| `ingest` | write | derived image + JSON descriptor back into the vault (`ai` vaults) |

Whether a tier answers at all is vault policy: `ai` vaults deny Tier 2 by
default, `gallery` vaults deny Tier 1. `get_vault` tells the agent up front, so
a well-behaved model never probes.

Those defaults are a **preset**, not a ceiling — every knob is overridable per
vault via `exposure_policy`, and the admin UI shows the resulting matrix with
each value marked `preset` or `override`. A transforming AI consumer typically
needs `allow_binary: true` on top of the `ai` preset; see the recipe in
[Vault System](VAULT_SYSTEM.md) §6.3.

`read_image` defaults to `ai-prepared` (5 MiB binary limit before base64), with
optional `max_bytes` from 65,536 to 20,971,520. It preserves fitting source bytes
except PNG orientation normalization, then uses high-quality JPEG and gradual
dimension reduction when needed. Clients can explicitly request `original` or
an advertised `thumbnail`, `small`, `medium`, or `large` rendition instead.
Results include actual rendition, format, byte count and available dimensions.
Preparation is on demand without a persisted cache; access remains Tier 2.
See [image choices](../../vault-mcp/README.md#image-choices) for calls and
[preview renditions](VAULT_SYSTEM.md) for the HTTP contract.

### Writing back

Writes at the vault boundary are **purpose-defined ops**, not general CRUD:
`gallery` → `activate` / `open` / `close`; `ai` → `ingest`. Each op is the
permission and audit unit, gated by a write-capable key
(`vault_keys.abilities`, e.g. `["w:ingest"]`), posted to
`POST /{h|v}/…/w/{method}`.

The landing spot is **vault-configured, never caller-supplied**:

```jsonc
// vaults.exposure_policy
{ "ingest": { "workspace_id": 12, "collection_id": 5 } }
```

The consumer declares *what*; the vault decides *where*. `AiVaultWriter::ingest()`
materializes one output resource transactionally — the JSON descriptor as the
canonical file, the translated image as a `translation` component marked
`snapshot`.

### Bring-your-own-AI

This is the loop ImageLab runs, and it needs no product-side orchestrator:

1. Point any MCP-capable model at an `ai` vault with **both** keys.
2. It reads the source images (`list_resources` → `read_image`).
3. It transforms them however it likes — its model, its prompt, its choice.
4. It writes the result back with `ingest`.

No hard-coded model, no glue code, no TYDAL-side credentials for a third-party
provider. The vault is the boundary; the AI is the customer's problem.

```jsonc
{
  "mcpServers": {
    "acme-figures": {
      "command": "node",
      "args": ["/path/to/tydal/vault-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-instance.example.com",
        "TYDAL_VAULT": "acme/figures",
        "TYDAL_VAULT_KEY": "tvk_…",         // read — private vaults only
        "TYDAL_VAULT_WRITE_KEY": "tvk_…"    // write — turns on ingest
      }
    }
  }
}
```

---

## Choosing

- **Unattended, on every upload, with a human gate** → AITY. Only surface that
  runs without anyone present, and the only one with a review queue.
- **An agent doing a user's work across the org** → org MCP. Broadest powers,
  writes final values, trusted like a person.
- **Someone else's AI, or a curated slice with a contract** → vault MCP. Narrow
  by construction, read-only until you say otherwise, output lands where the
  vault says.

If the question is "which one replaces AITY?" — the org MCP does, for the same
outputs. What you give up is the suggestion buffer and its provenance; what you
gain is an agent that can also curate, upload, and answer questions in the same
session.

---

## See also

- [Vault System Specification](VAULT_SYSTEM.md) §7 — the MCP surface of a vault
- [Vault Write Methods](VAULT_WRITE_METHODS.md) — write ops, keys, abilities, `ingest`
- [Resource Composition Model](RESOURCE_MODEL.md) — file roles, contribution semantics
- [Schema Field Contract](SCHEMA_FIELDS.md) — the `ai_fill` contract behind `ai_suggested_metadata`
- [CLI Guide](../CLI.md) — `mcp:token`, `search:embed`, and the rest
- [Org MCP README](../../org-mcp/README.md) · [Vault MCP README](../../vault-mcp/README.md)
