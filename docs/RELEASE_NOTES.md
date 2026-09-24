# TYDAL 1.0.0

**Schema-Driven Semantic Vaults for AI Agents** — first public release.

TYDAL exposes shared **Resources** through contextual **Vaults** that define
meaning, indexing behavior, and AI-interaction surfaces. One vault, one grammar,
many faces: the same tier-gated boundary serves a human gallery, an
Obsidian-style graph, a grounded AI chat, plain file delivery, and Vault-scoped MCP
consumers — each seeing exactly what the vault's purpose and policy expose, and
nothing more.

## Highlights

- **Vaults over Workspaces** — five purposes (`delivery`, `gallery`, `obsidian`,
  `ai`, `mixed`) with a tiered exposure model (identity → chunks → binary → ask),
  a dual human/machine addressing grammar, and boundary integrity (only opaque,
  vault-scoped link hashes ever leave the system).
- **Schema-driven, per-vault indexing** — one field contract drives forms, ES
  mappings, facets, and validation; each vault gets its own presentation-mapped
  index.
- **AI-native surfaces** — provider-pluggable embeddings, semantic search over
  resources and chunks, a projected knowledge graph, and a streaming, citation-
  bearing ask head. Vault MCP exposes data for external agents to reason over;
  it is read-only by default, with supported purpose-defined writes enabled
  through a write key.
- **An app suite over one SDK** — `@tydal/client` serves the management SPA,
  Vault apps and external integrations. Gallery, obsidian and AI-chat apps are
  independent builds on the public grammar; both MCP adapters use their own
  HTTP wrappers.
- **Three access forms** — published, vault keys, and time-limited signed grants
  with one-shot revocation.

## Access model

Published vaults are open; private vaults require a `tvk_…` key or a signed
`?sig=&exp=` grant. Unpublished vaults are existence-hidden (404). Reasoning at
the boundary is gated to AI-facing purposes so a public gallery can't be farmed
for LLM tokens.

## Upgrade / install

This is the initial release — there is no prior published version to migrate
from. The CDN → Vault change was a pre-release rename; see
[`docs/planning/MIGRATION_V1_V2.md`](planning/MIGRATION_V1_V2.md) for the concepts and the
one physical step for older development databases. Setup lives in
[`DEPLOYMENT.md`](../DEPLOYMENT.md); a full feature inventory is in
[`CHANGELOG.md`](../CHANGELOG.md).

## Notes

- Backend: Laravel 12 · MySQL · Elasticsearch 9+ · Redis/Horizon · S3/MinIO.
- JS workspace: `@tydal/client`, `@tydal/org-mcp`, `@tydal/vault-mcp`, and the vault
  apps.
- Test coverage: 700+ backend tests; O(1) query budgets and embedding-cache
  behavior are pinned by regression tests.

---

*Internally this is the "v2" architecture (the CDN delivery layer promoted to the
Vault projection layer). As a shipped product, it is 1.0.0.*
