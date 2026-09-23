# TYDAL Documentation

This folder keeps the public documentation index intentionally small. Start with:

- [Deployment Guide](../DEPLOYMENT.md) — command-oriented install → configure → start → seed → (optional) plug in org-mcp/vault-mcp, everyday commands and common gotchas, plus production (nginx + PHP-FPM) setup
- [Connecting an AI Client](CONNECTING_MCP_CLIENTS.md) — for someone connecting *their own* AI (Claude Desktop, Claude Code, Cursor, …) to an already-running TYDAL instance via org-mcp/vault-mcp; no TYDAL deployment steps needed
- [Architecture & Roadmap](architecture/ARCHITECTURE_AND_ROADMAP.md) — **canonical** architecture, dual naming system, current → v2 roadmap
- [Vault System Specification](architecture/VAULT_SYSTEM.md) — the Vault entity, boundary `state`, tenancy enforcement points, addressing grammar (hash/slug), projection tiers, and MCP surface (drives Milestone 2)
- [Vault Write Methods](architecture/VAULT_WRITE_METHODS.md) — the *inbound* boundary: purpose-defined write ops (`gallery` → activate/open/close; `ai` → ingest) gated by write-capable vault keys, one generic `write(op, payload)`; replaces the org-admin token for the Full Frame opening and powers ImageLab / bring-your-own-AI ingest
- [AI Surfaces](architecture/AI_SURFACES.md) — the three ways to work with AI: server-side AITY enrichment (pluggable LLM drivers, suggestions + review), the org-level MCP agent (works like a user), and vault MCP (publish a projection, bring your own AI)
- [Resource Composition Model](architecture/RESOURCE_MODEL.md) — the three file roles, contribution semantics (metadata / text / embeddings), lifecycle `state`, invariants & audit tooling
- [Roles & Permissions](architecture/ROLES_AND_PERMISSIONS.md) — the two authorization axes (platform administration vs organization role), `config/permissions.php` as the single source of truth, the two administration surfaces, permission-aware UI, and quotas
- [Schema Field Contract](architecture/SCHEMA_FIELDS.md) — the `collection_schemes.fields` contract, its consumers, `schema:validate`, scheme editing/cloning rules, and scheme/index **visibility**
- [Basket & Bulk Actions](architecture/BASKET_AND_BULK_ACTIONS.md) — the dashboard's persistent multi-select, the four bulk endpoints and their partial-success contract, index timing (`immediate` vs `queued`), and vault index reconciliation
- [System Diagrams](architecture/SYSTEM_DIAGRAM.md) — layered architecture & data-flow (Mermaid)
- [CLI Guide](CLI.md) — every artisan command (search lifecycle, integrity checks, MCP tokens, housekeeping) with operational recipes
- [Testing Milestone 5](TESTING_M5.md) — hands-on walkthrough of the vault suite: upload → workspace → vault links → gallery/notes/ask apps → keys & signed URLs
- [v1 → v2 Migration](planning/MIGRATION_V1_V2.md) — CDN→Vault concepts, terminology map, what delivery users keep, and how to opt into the semantic layer
- [Roadmap Milestones](planning/ROADMAP_MILESTONES.md) — issue-ready milestones, epics & tasks
- [Historic Documentation Digest](planning/historic.md) — summary index of earlier design notes
- [Changelog](../CHANGELOG.md) — release history (starts at 1.0.0) · [Release Notes](RELEASE_NOTES.md)
- [Contributing](CONTRIBUTING.md) · [Code of Conduct](CODE_OF_CONDUCT.md) · [Security Policy](SECURITY.md)
- [Project README](../README.md)
- [Backend README](../backend/README.md)
- [Frontend README](../frontend/README.md)
- [Backend OpenAPI specification](../backend/docs/openapi.yaml)
- [Org MCP server README](../org-mcp/README.md) — write-capable management surface (uploads, tagging, workspaces)
- [Vault MCP server README](../vault-mcp/README.md) — vault-scoped consumer surface for external AI; read-only by default, `ingest` write tool with a write key (bring-your-own-AI)
- [Shared client SDK README](../client/README.md) — `@tydal/client`, the single transport for every surface
- [Bulk uploader README](../tools/bulk_uploader/README.md)

## Setup & Deployment

Every install/run command — development (install → configure → start → seed,
superadmin/AI credentials, the optional MCP servers, everyday commands and
gotchas) and production (nginx + PHP-FPM) alike — lives in the
[Deployment Guide](../DEPLOYMENT.md).

Test commands live in [`backend/README.md`](../backend/README.md) and
[`frontend/README.md`](../frontend/README.md).

## Historic Notes

Older architecture drafts, migration notes, implementation status reports, and detailed planning documents are summarized in [`historic.md`](planning/historic.md). The raw source files are retained privately, outside this repository, to keep the public project focused. The READMEs, the canonical docs above, and the OpenAPI file are the current public documentation.
