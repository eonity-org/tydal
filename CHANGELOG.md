# Changelog

All notable changes to TYDAL are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.0.0/); this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- **Collection creation provisions its own ES index.** `CollectionService::createCollection`
  now calls the same logic as `search:setup-indices` (mapping + `_chunks`
  companion) the moment a collection is created — through the admin UI, or
  the installer seeders — deriving the mapping from whatever schemes already
  use that index. A scheme with no collection gets no index built at all, so
  `first_install.sh` no longer eagerly builds every seeded scheme's index up
  front; picking "Documents" later provisions it then, not before. See
  [CLI Guide](docs/CLI.md#searchsetup-indices).
- **`documents` collection scheme** (Word, PDF, text, images) alongside
  `multimedia` (now video/image/audio only — PDF moved to `documents`).
  `tools/deploy/first_install.sh` (renamed from `cleanup.sh`) asks
  interactively which starter collection(s) to seed once the schema is
  seeded, built from `php artisan schema:starter-options` — a new
  `is_system` scheme becomes selectable there with no script edit. See
  [CLI Guide](docs/CLI.md).
- **The Basket** — one persistent, inspectable multi-select for the dashboard,
  identical in grid and list, surviving paging, filters and scope changes;
  reviewable at `/basket`, with **Save as workspace** to graduate it. Replaces the
  list-only transient selection. See [Basket & Bulk Actions](docs/architecture/BASKET_AND_BULK_ACTIONS.md).
- **Bulk actions** — `bulk-attach` / `bulk-detach` workspace membership, bulk
  lifecycle `state`, and additive/subtractive bulk semantic tagging, each capped
  at 200 ids with a partial-success report (`requested` / `applied` / `skipped`)
  and an `indexing: immediate | queued` hint so the UI knows whether its refetch
  will race the reindex.
- **Organization settings** (`/organization`) — member management and
  organization-scoped collection creation for owners/admins, sharing the platform
  panel's chrome via `SettingsShell`.
- **Scheme & index visibility** — `global | restricted` with an organization pivot,
  so one customer can be given a field contract, or a search index, of their own
  without per-organization indexes becoming the rule. Plus scheme **cloning**
  into an organization, a field editor that is live only while a scheme is unused,
  and a per-organization **collection quota**.
- **Permission-aware UI** — `/me` and `/login` now return the resolved permission
  list; controls the role will never grant are hidden, contextual blocks are
  disabled with a reason, and the server 403 remains the backstop.
- Docs: [Roles & Permissions](docs/architecture/ROLES_AND_PERMISSIONS.md),
  [Basket & Bulk Actions](docs/architecture/BASKET_AND_BULK_ACTIONS.md); visibility, cloning
  and editing rules added to [Schema Field Contract](docs/architecture/SCHEMA_FIELDS.md).

### Changed
- **Migrations re-consolidated to one baseline.** All migrations since the
  2026-06-01 baseline (vault org scoping, vault keys, resource embeddings,
  per-resource link slugs, schema overlays, per-vault indexing, the resource
  relation graph, vault provenance/grant epoch, the resources/vaults
  state-flag collapse, vault write abilities + audit, scheme/index
  visibility) are folded back into
  `2026_06_01_000000_create_initial_tydal_schema.php`. A fresh install now
  runs one migration instead of fourteen; future schema changes still land
  as normal incremental migrations after it.
- **Authorization resolves through `config/permissions.php` alone.** All five
  policies dropped their hand-written role lists for `can('area.action')`, keeping
  only contextual checks. `App\Support\Permissions` is the one matcher, shared by
  the Gate and the `/me` payload. `App\Enums\OrganizationRole` names the roles;
  `App\Support\SchemaContract` does the same for the field vocabulary, now read by
  both the write endpoint and `schema:validate`.
- A **platform administrator no longer needs to be a member of an organization**
  to work in it: `currentEffectiveRole()` resolves them to the config's
  `superadmin` block, and `CategoryPolicy` / `CollectionPolicy` gained the
  `before()` hook they alone were missing.
- **Administrators may act on resources they did not create.** `ResourcePolicy`
  `update`/`delete` previously required ownership unconditionally, while
  `restore`/`forceDelete` already allowed admin/owner — the two halves now agree.
- Editors no longer create or delete workspaces, nor create collections: both are
  structural acts (a workspace is what a vault projects; a collection pins a
  scheme and an index). Owners are uncapped so ownership can be transferred.
- New collections are given the most specific search index their organization is
  entitled to, instead of `null`.
- Bulk reindexing runs inline at or below 25 resources and queues above it, and
  refreshes the indices it touched — Elasticsearch is near-real-time, so even a
  synchronous write was invisible to the next search for up to a second.

### Fixed
- A scheme field with `storage: column` naming anything other than `name`,
  `description` or `type` had nowhere to be written (mass assignment silently
  dropped it) and never rendered in the resource form — a required field
  could be added to a scheme and simply never show up. `SchemaContract`,
  `schema:validate` and the scheme editor now reject/flag it and disable the
  `column` option for any other field name.
- `POST /organizations` and `POST /auth/register` (with an organization) failed
  with `SQLSTATE[42S22]`: both passed an `id` column `organization_user` does not
  have. Because they were the only writers of `org-admin`, that role could never
  exist — which made `OrganizationPolicy::update` permanently false and left **no
  organization owner able to invite or promote anyone**.
- `POST /collections` never generated a `slug` (`NOT NULL`, no default) and never
  authorized `create`.
- Resource and category creation were not authorized at all — a viewer could
  create both. Now authorized in the FormRequest, before validation.
- `GET /organizations/{id}/users` had no authorization: any authenticated user
  could read any organization's member list.
- `ResourcePolicy::restore` / `forceDelete` omitted the organization check, so an
  administrator of one organization could permanently destroy another's trashed
  resource given its id.
- Deleting a workspace left its members' search documents stale, and left them
  **publicly projected** in that workspace's vaults: the pivots cascade at the
  database level, bypassing Eloquent. `indexResourceIntoVaults` also only ever
  added, never pruning vaults a resource had stopped being reachable in — which
  affected any detach, not only deletion.
- Bulk semantic tagging no longer replaces a resource's whole tag set.
- System workspaces leaked into the catalogue facets; the workspace manager, which
  is designed to show them, never received them.
- Creating, renaming or deleting a workspace did not refresh the dashboard, so
  deleted workspaces kept showing as chips on resource cards.
- `/admin` was auth-only: any signed-in user reaching the URL got the full panel.
- The avatar menu's Profile and Settings entries did nothing; it now shows who you
  are signed in as, and where.
- `org-mcp`'s `sync_tags` tool always created a duplicate tag instead of
  reusing an existing one: it read the `/semantic-tags` list/create responses
  at the wrong envelope paths (expecting a nested key neither endpoint
  returns), so it never found a match and the final sync then sent `null`
  IDs, which the backend correctly rejected. Once any duplicate existed, a
  second bug compounded it — the label→id lookup had no defined tie-break
  when two tags shared a label, so which one a later sync reused was
  non-deterministic across calls. Both fixed; label resolution now always
  prefers the lowest (oldest) id for a given label.

### Removed
- `spatie/laravel-permission` — installed but entirely inert (no `HasRoles`, no
  config, no migrations, its `Gate::before` never registered).
- `CheckOrganizationRole`, `EnsureHasOrganization`, `SetCurrentOrganization` —
  middleware never aliased and never referenced by any route.
- The `org-admin` and `org-member` pivot roles, and `config/permissions.php`'s
  unread `resource_actions` block.

## [1.0.0] — 2026-07-10

First public release of TYDAL — a multi-tenant, schema-driven semantic knowledge
system that projects immutable **Resources** through contextual **Vaults** with
tiered AI-interaction surfaces (REST, MCP, embeddings, graph). Internally this is
the "v2" architecture (the CDN delivery layer promoted to the Vault projection
layer); as a shipped product it is version 1.0.0.

### Vault system — the projection boundary
- **Vault entity** projecting one or more Workspaces, with five purposes:
  `delivery` (the classic CDN behavior, unchanged), `gallery`, `obsidian`,
  `ai`, and `mixed`.
- **Dual addressing grammar** — human `/v/{org}/{vault}/…` and machine
  `/h/{vaultHash}/{linkHash}` — resolving the same (vault, resource, file)
  triple through one tier-gated operation grammar (`meta`, `tags`, `resources`,
  `search`, `embed`, `graph`, `ask`; per-resource `files`, `chunks`, `links`,
  `related`, `preview`).
- **Tiered exposure model** (identity → chunks → binary) gated per purpose, with
  `exposure_policy` overrides (`allow_chunks`, `allow_binary`, `allow_ask`,
  `chunk_roles`, `address_roles`, `rag_min_score`).
- **Boundary integrity** — only vault-scoped opaque link hashes cross the
  boundary; internal resource/file UUIDs never leak. Salt rotation re-randomizes
  every address.
- **Three access forms**: published (open), vault keys (`tvk_…`, SHA-256 at
  rest), and time-limited **signed grants** (`?sig=&exp=`, HMAC over the vault
  salt with an epoch for one-shot revocation). Existence-hiding 404s for
  unpublished vaults.

### Schema-driven indexing
- `collection_schemes.fields` as the single field contract driving dynamic
  forms, ES mappings, facets, validation, and MIME gating. Field names are
  charset-constrained (`^[a-z][a-z0-9_]*$`).
- **Per-vault Elasticsearch indexes** with schema-overlay-resolved presentation
  slots, so a field's meaning and index behavior vary per vault mode.
- Rebuildable-cache philosophy: ES is derived from MySQL; DB fallbacks answer
  keyword search when an index is absent.

### AI layer
- **Embeddings**: per-resource mean vectors and per-chunk vectors (provider-
  pluggable — Jina / Voyage / Ollama), with a synthetic metadata chunk so every
  resource (including images) is present in vector space.
- **Semantic search** (k-NN) over resources and chunks, plus a query-embedding
  compute tool (`/embed`).
- **Knowledge graph**: tag co-occurrence and embedding-similarity edges, vault-
  projected `/related` and `/graph` (cross-vault edges never leak), and
  cluster→auto-vault materialization.
- **Vault ask head** — grounded, citation-bearing Q&A over a vault's own
  projection, with SSE streaming; retrieval walks the same tier-gated grammar as
  any client. Relevance floor is owner-tunable per vault and per organization.
- **Two MCP servers**: the internal write-capable working surface (`@tydal/mcp`)
  and the vault-scoped read-only consumer surface (`@tydal/vault-mcp`).

### Experience layer
- **`@tydal/client`** — the framework-agnostic TypeScript SDK that is the single
  transport for every surface (SPA, MCP, apps); no hand-rolled HTTP elsewhere.
- **Three vault renderer apps**, each an independent build over the public vault
  grammar: **gallery** (exhibition wall), **obsidian** (graph + notes), and the
  **AI chat** app (streaming ask).
- React SPA admin surface for organizations, workspaces, collections, resources,
  vaults, keys, and signed-URL minting.

### Resource & media model
- Three file roles (`canonical` / `component` / `supporting`) with defined
  metadata/text/embedding contribution semantics and audit tooling
  (`resources:audit-roles`).
- Text extraction (Tika), chunking, preview/snapshot generation, and AI
  auto-tagging/enrichment through an async queue.

### Operations & tooling
- Artisan lifecycle: `search:setup-indices`, `search:reindex [--vault]`,
  `search:reconcile [--fix]` (resource **and** chunk drift), `search:embed`,
  `schema:validate`, `graph:rebuild`, `mcp:token`.
- Docker-first dev stack with deploy scripts: `start.sh`, `clients.sh`,
  `seed-vault.sh`, `reindex.sh`, `loadtest.sh`.

### Security
- Policy-based authorization with numeric roles; multi-tenant org context
  validated on the `X-Organization-ID` header.
- Scoped API-key abilities (`read` / `ask` / `write`) with a token-management
  privilege guard.
- Global schema/index configuration writes restricted to superadmin; auth cookie
  hardened with `Secure` over HTTPS. (See the security review notes in
  `ROADMAP_MILESTONES.md` §6.1.)

### Performance
- O(1) query budgets on all vault listing endpoints (index, search, related,
  graph) via batched link resolution and eager snapshot loading — pinned by
  regression tests.
- Cached query embeddings (deterministic per model) — one provider round-trip
  per ask instead of two.

### Documentation
- Canonical [`ARCHITECTURE_AND_ROADMAP.md`](docs/architecture/ARCHITECTURE_AND_ROADMAP.md),
  [`VAULT_SYSTEM.md`](docs/architecture/VAULT_SYSTEM.md),
  [`SCHEMA_FIELDS.md`](docs/architecture/SCHEMA_FIELDS.md),
  [`MIGRATION_V1_V2.md`](docs/planning/MIGRATION_V1_V2.md), CLI guide, and OpenAPI 3.0
  spec.

[1.0.0]: https://github.com/eonity-org/tydal/releases/tag/v1.0.0
