# 🗺️ TYDAL → v2 — Milestones, Epics & Issues

> **Reading this historical plan (2026-09-24):** Milestone entries retain the
> terminology and integration choices used when written. Current Vault MCP is
> read-only by default, with supported writes enabled through a write key. Both
> MCP adapters use their own HTTP wrappers; the earlier shared-SDK migration
> was reversed (see Epic 5.0). For current names and product positioning, see
> [the architecture terminology](../architecture/ARCHITECTURE_AND_ROADMAP.md).

Issue-ready breakdown of the 6 roadmap phases from
[`ARCHITECTURE_AND_ROADMAP.md`](../architecture/ARCHITECTURE_AND_ROADMAP.md). Each **Milestone**
maps to a GitHub milestone; each **Epic** to a tracking issue; each checkbox to a
work issue. Suggested labels are in `[brackets]`.

Status legend: ✅ done · 🟡 in progress · ⬜ todo
Suggested labels: `[backend]` `[frontend]` `[ai]` `[mcp]` `[infra]` `[schema]`
`[vault]` `[graph]` `[search]` `[docs]` `[breaking]` `[client]`

---

## Milestone 1 — Stabilization (current system) ✅ CLOSED 2026-07-03

**Goal:** lock the Resource/Schema/Search foundations before introducing Vaults.
**Exit criteria (met):** Resource composition model documented
([`RESOURCE_MODEL.md`](../architecture/RESOURCE_MODEL.md)) & enforced in the service layer;
schema is the single source of truth (contract in
[`SCHEMA_FIELDS.md`](../architecture/SCHEMA_FIELDS.md), `schema:validate` in CI reach); ES
mappings reproducible from schema (`search:setup-indices` idempotent,
`search:reconcile` for drift); green CI. Bonus pulled in from §8:
resource-level mean embeddings.

### Epic 1.1 — Resource composition model `[backend][schema]`

**Three-role model (settled):**
- `canonical` — the **sole** metadata contributor (1 file drives metadata/embedding).
- `component` — **all** components contribute **equally** (`mean` aggregation, §8).
- `supporting` — **pure ancillary**: attachment / alternate representation, **not** indexed or embedded.
- Preview/snapshot (`usage:['snapshot']`) is an **orthogonal** per-file flag — any file can be it, independent of role.

- ✅ Cardinality enforced in the frontend SPA (1 canonical, N supporting/component).
- ✅ Preview/snapshot flag — role-independent, working.
- ✅ Metadata promotion for the **canonical** file (`promoted_file_metadata`, single contributor).
- ✅ **Component-aggregation metadata promotion** (2026-07-03): without a canonical, all active components contribute equally — merged in manifest order (`files.position`, then upload order), **first-by-position wins conflicts** (Decision A); indexed `extracted_text` concatenates component texts in the same order. A multi-component resource is everything representing the resource.
- ✅ **Resource-level mean embedding (§8, pulled into M1)**: `resources.embedding` = element-wise mean of the contributing files' chunk vectors (canonical alone / components equally, supporting excluded); recomputed after `EmbedFileChunks` + on canonical promotion; persisted in MySQL and mapped as `dense_vector` on the resource ES doc.
- ✅ `supporting` = pure ancillary **at the resource level** (metadata, indexed text, embedding — enforced). Supporting *chunks* stay k-NN searchable by design (Decision B): they serve the internal catalogue and the per-vault `chunk_roles` projection. See `docs/RESOURCE_MODEL.md`.
- ✅ Cardinality + role invariants moved into the backend service layer (`ResourceService::applyFileRoleInvariants`, `InvalidResourceComposition`); controller is a thin caller, MCP/SDK paths share it.
- ✅ Role model + contribution semantics documented: [`RESOURCE_MODEL.md`](../architecture/RESOURCE_MODEL.md).
- ✅ Backfill audit: `php artisan resources:audit-roles [--fix]` (multi-canonical, component-beside-canonical, canonical relations, snapshot duplicates, orphan files on trashed resources) + invariant tests for canonical/component/supporting/orphan cases.
- ℹ️ Multi-file resources are **niche** — a supported edge case, not a v2 priority. Examples: a CD → canonical tracklist + `supporting` audio tracks; a movie + `supporting` subtitles; a scanned multi-page doc → `component` pages.

### Epic 1.2 — Schema source-of-truth hardening `[schema][backend]`
- ✅ Consumer audit of `collection_schemes.fields` (forms, ES mapping, facets, validators, MIME gating) — table in [`SCHEMA_FIELDS.md`](../architecture/SCHEMA_FIELDS.md).
- ✅ `php artisan schema:validate` (2026-07-03): name format/uniqueness, type/es_type compatibility, facet aggregatability, validator coherence, `select` option lists, mimetype patterns; CI-able exit code. (First run caught a real seeded inconsistency: `language` select without options.)
- ✅ Field contract documented: [`SCHEMA_FIELDS.md`](../architecture/SCHEMA_FIELDS.md).

### Epic 1.3 — Search & file→resource mapping stability `[search][backend]`
- ✅ ES mappings fully derivable from schema; `search:setup-indices` idempotent (existing index → additive `putMapping`; type changes need `--recreate`); covered by an integration test and exercised in anger by the additive `embedding` field.
- ✅ Async indexing verified under failure/retry: `IndexResourceToElasticsearch` and `DeleteResourceFromElasticsearch` `tries=3, backoff=10`; `ExtractFileText`/`EmbedFileChunks` `tries=2` + `failed()` handlers.
- ✅ `php artisan search:reconcile [--collection=] [--fix]` (2026-07-03): detects missing / stale / orphaned ES documents per index; `--fix` reindexes and purges (orphans deleted directly by index — their MySQL row may be gone); CI-able exit code.
- ✅ CI job running `pint` + `phpstan` + `pest` + frontend `lint`/`test`/`build`.
  - ✅ GitHub Actions workflow (`.github/workflows/ci.yml`): pint `--test` + phpstan + pest (MySQL 8.0 + ES 9.0.0 service containers) + frontend lint/test/build. Triggers: push to `develop`, PR to `develop`/`main`.
  - ✅ `phpstan.neon` (larastan, level 5, scans app/config/database/routes) + `phpstan-baseline.neon` (579 existing errors baselined so CI is green; new code held to level 5). Raise level / shrink baseline incrementally.

---

## Milestone 2 — Vault Introduction (critical step)

**Goal:** promote the existing **CDN** system to the first-class **Vault** entity
per the settled spec ([`VAULT_SYSTEM.md`](../architecture/VAULT_SYSTEM.md)) — rename + extend,
*not* a new build. The classic CDN becomes `purpose = 'delivery'`; vault
purposes get org scoping, workspace-free hashes, dual addressing (opaque hash /
semantic slug) with one tier-gated operation grammar, and a read-only MCP
surface.
**Exit criteria:** a CDN can be converted to a Vault by setting `purpose`;
vault resources resolve via `/h/{vaultHash}/{linkHash}` and
`/v/{orgSlug}/{vaultSlug}/{resourceSlug}` with tier-gated operations; a
vault-scoped read-only MCP server serves Tier 0/1/2 per policy; existing
`delivery` CDN behavior (flat `/vault/{hash}` links) preserved.
**Depends on:** Milestone 1 (file roles, Epic 1.1 — exposure filters build on them).

> 🏗️ **Build strategy (lesson from the abandoned `tydal.boveda/` first attempt,
> spec §9):** the boveda traps were mixing the rename with behavior changes and
> editing the consolidated migration in place (which forced `migrate:fresh` on
> every step and broke test/deploy stability) — not the rename itself. So the
> `cdns → vaults` physical rename was executed **first** (2026-07-03) as one
> **pure mechanical change** — zero behavior delta, full suite green — and
> every following epic builds on vault-named code. Being pre-release (no
> deployed users), the rename was folded directly into the consolidated
> initial migration, one deliberate exception to the incremental rule.
> `purpose = 'delivery'` keeps every existing behavior, test, and consumer
> untouched while vault purposes grow beside it. Each epic ships independently
> against a green suite; from here on, always incremental migrations.

### Epic 2.1 — Entity promotion & schema delta `[vault][backend][breaking]`
*(Incremental migrations on the `vaults` tables — never edit the consolidated
migration in place.)*
- ✅ **Rename (done first, 2026-07-03):** `cdns → vaults`, `workspace_cdn → workspace_vault`, `cdn_links → vault_links` (+ `cdn_id → vault_id`, indexes, FK constraint names — folded into the consolidated initial migration; pre-release, no users) + models/services/policies (`Cdn → Vault`) + API routes (`/cdns` → `/vaults`, `/resources/{id}/vault-links`) + consumers (frontend `vaultService`; MCP `get_vault_links`) + public link routes `/cdn/{hash}` → `/vault/{hash}` (no legacy path kept).
- ✅ Add on `vaults` (2026-07-03): `organization_id` FK, NOT NULL (backfilled from the oldest attached workspace's org, falling back to the oldest org for workspace-less `has_public_workspace` vaults) · `purpose` enum (`delivery`·`gallery`·`obsidian`·`ai`·`mixed`, default `delivery`, `VaultPurpose` enum + cast) · `is_published` (default false) · `hash` (12-char opaque, globally unique, generated at creation + backfilled) · `exposure_policy` JSON (null = defaults).
- ✅ Slug uniqueness per-org: dropped global `vaults_slug_unique`, added `UNIQUE (organization_id, slug)`; Store/Update validation scoped accordingly; `organization_id` immutable after creation.
- ✅ On `vault_links`: `workspace_id` nullable (NULL = vault link) · `slug` (unique per vault, nullable until Epic 2.3 generates them).
- ✅ On `files`: `position` (unsigned smallint, nullable — component/manifest ordering).
- ✅ Purpose change = hash-domain change: purge + regenerate links (same path as salt rotation, `VaultController::update`). Vault administration remains superadmin-only via `/platform` routes; the org-side Admin (75+) surface arrives with the vault admin UI epics.
- ✅ Seed/dev factory for vaults: `VaultFactory` `purpose()`/`published()` states; `VaultDemoSeeder` seeds one vault per purpose.

### Epic 2.2 — Link engine & hash resolution `[vault][backend]`
- ✅ Deterministic link map exists (`link_key` = `sha256(vault:workspace:resource:file)` + rotating `hash`, lazy minting).
- ✅ Purpose-aware `link_key` (2026-07-03): vault purposes use `sha256(vault:resource:file)` (workspace-free — one stable address per resource, `workspace_id` NULL on the row); `delivery` unchanged.
- ✅ Vault-first resolution for `/h/{vaultHash}/{linkHash}` (+ `/info`, `/download`): vault resolved first (active/**published**/IP policy known before any link query — private-vault access via VaultKey lands in Epic 2.4), then link scoped `WHERE vault_id + hash` — fixes the latent global-hash collision (spec §4.1). `buildUrl` mints `/h/…` for vault purposes, flat `/vault/{hash}` for delivery.
- ✅ Vault-link validity check: resource ∈ **any** vault-linked workspace, or `has_public_workspace` **same-org only** (org pinning, spec §2); revocation stays implicit.
- ✅ Role/policy-filtered minting: vault purposes mint file links only for roles in `exposure_policy.address_roles` (default `canonical`+`component`; `supporting` unaddressed); `delivery` minting untouched.
- ✅ Cache for vault hash resolution (`Vault::findByHashCached`, 300s TTL, hit/miss counters, DB fallback); invalidated on vault save/delete. Slug resolution cache follows the slug map in Epic 2.3.

### Epic 2.3 — Human namespace & operation grammar `[vault][backend][infra]`
*(All ✅ 2026-07-03 — `VaultOperationService` + `VaultNamespaceController`, one
grammar for both address forms; slugs minted lazily by listings/manifests,
like the link map always was.)*
- ✅ Slug generation on vault-purpose links (resource → from name, file → from filename; `-N` dedupe; grammar words reserved). Scoping refined 2026-07-03: resource slugs unique per **vault**, file slugs unique per **resource** (hierarchical — `album-one/cover` and `album-two/cover` coexist; `/doc/doc` legal), enforced via conditional generated columns.
- ✅ Routes `/v/{orgSlug}/{vaultSlug}[/{resourceSlug}[/{fileSlug}]]` → same resolved triple as the hash form (cached org+vault slug resolution, same policy gate).
- ✅ Operation grammar (spec §5): vault `/meta`·`/tags`·`/resources`·`/search` · resource `/meta`·`/tags`·`/files`·`/chunks`·`/links`·`/related`·`/download` · file `/meta`·`/chunks`·`/download`·`/renditions` — shared dispatch for `/v/` and `/h/…/{op}`.
- ✅ **Manifest rule:** resource address → binary iff exactly one exposed file AND Tier 2 allowed, else ordered manifest (`files.position`, then upload order; binary URLs omitted when Tier 2 is off).
- ✅ Tier gating (spec §6.2/§6.3): purpose presets on the Vault model (`allowsChunks`/`allowsBinary`/`chunkRoles`) with `exposure_policy` overrides (`allow_chunks`/`allow_binary`/`chunk_roles`/`address_roles`); 403 on denied tiers.
- ✅ Chunk exposure filter: `/chunks` filters ES chunks by file role; `ai` vaults include `supporting` text by default (projection-only — resource indexing/embedding untouched).
- ✅ Vault index (Tier 0 listing, JSON): paged identity cards, `?tag=` / `?category=` navigation, plus `/tags` aggregation and `/search?q=` keyword search (per-vault ES search lands with Milestone 3).

### Epic 2.4 — Vault MCP server (read-only) `[vault][mcp]`
*(All ✅ 2026-07-03 — new `vault-mcp/` package, `@tydal/vault-mcp`. Note:
`@tydal/client` does not exist yet, so the server uses its own thin axios
client over the public consumer surface — migrate when the SDK lands.)*
- ✅ Vault-scoped MCP server: connection = one vault via `TYDAL_VAULT`
  ("org/vault" slugs) or `TYDAL_VAULT_HASH`, optional `TYDAL_VAULT_KEY`.
  **VaultKey** entity shipped with it (`vault_keys` table, hash-stored,
  `tvk_…` plaintext shown once, revocable, `last_used_at`): published vaults
  keyless, private vaults key-gated on the whole consumer surface
  (`X-Vault-Key` header / `?vault_key=`); platform CRUD
  `/platform/vaults/{id}/keys`. Vault-level hash addresses added
  (`/h/{vaultHash}` + `meta/tags/resources/search`) as the keyless-by-hash
  connection point.
- ✅ Tools per verb-taxonomy (spec §7): `get_vault` (self-description) · `get_resource` · `get_file` · `list_resources` · `list_files` · `list_related` · `list_chunks` (index, content stripped) · `search_resources` · `search_chunks` (new `search?scope=chunks` REST op — ES keyword over chunk content, vault-scoped + role-filtered; semantic lands in M3) · `read_chunks` (sequence range) · `resolve` (URL/path/slug, rejects out-of-vault addresses) · `link_resource` · `link_file`.
- ✅ REST ↔ MCP 1:1 invariant: every tool is a thin call onto the `/v/`–`/h/` operation grammar; verb decides the tier checked; `link_*` mints URLs, never streams binary.
- ✅ Tool tests (axios-mocked, per existing `mcp/` pattern — 16 tests) + backend `VaultKeyTest` (10 tests).

---

## Milestone 3 — Schema-Driven Indexing (v2 core)

**Goal:** contextual, per-vault indexing driven by schema overlays.
**Exit criteria:** a field's role/index behavior varies per vault mode; ES index
projected per vault.
**Depends on:** Milestone 2.

### Epic 3.1 — VaultSchemaOverlay engine `[schema][vault]`
*(All ✅ 2026-07-04 — `VaultSchemaResolver` + `vault_schema_overlays`.)*
- ✅ Overlay format: fixed **role vocabulary** per purpose (the contract clients depend on) mapped from free field names — `vault_roles` key in the field contract ([`SCHEMA_FIELDS.md`](../architecture/SCHEMA_FIELDS.md)); resource built-ins fill core slots for any scheme.
- ✅ Storage + resolution: layered — structural preset (contract flags, never field names) ← scheme `vault_roles` ← `vault_schema_overlays` row (per vault × scheme, drift-tolerant: unknown fields ignored). Resolved `presentation` block ships in the vault self-description (`/meta`, `get_vault`).
- ✅ Overlay editor API (`GET/PUT /platform/vaults/{id}/overlays[/{schemeId}]`) validating field existence + vocabulary; `schema:validate` enforces `vault_roles` and `ai_fill` in schemes.

### Epic 3.2 — Per-vault Elasticsearch indexing `[search][vault]`
*(All ✅ 2026-07-04.)*
- ✅ Index projection `Resource → Schema → Vault Overlay → Index`: per-vault index `vault_{uuid}`, mappings compiled from the resolved presentation (**the slot, not the field name, decides indexing**: badge/facet/property → keyword aggregations, text-ish slots → full-text; `hidden`/`image` unindexed; `dynamic:false` ignores drift); documents = Tier 0 identity card + slot-mapped metadata + the resource mean embedding (`dense_vector`, ready for k-NN in M4).
- ✅ Dynamic recomposition: overlay PUT, vault purpose change, and scheme `fields` edits null `vaults.indexed_at` and queue `RebuildVaultIndex` (drop + recreate + reindex + stamp); scheme edits fan out to every affected vault (overlays + projections + public vaults). Ordinary resource edits/deletes keep fresh vault indexes **live** via the base indexing jobs.
- ✅ Vault `/search` rides the projected index when `indexed_at` is set (multi_match over name/description/tags/`metadata.*` + **facet aggregations** from aggregating slots), with the DB keyword path as automatic fallback — same rebuildable-cache philosophy as the catalogue.
- ✅ `search:reindex --vault={uuid|slug|all}`; vault deletion drops its index.

### Epic 3.3 — Semantic schema refactor `[schema][breaking]`
*(All ✅ 2026-07-04 — the refactor landed **in place**, never breaking.)*
- ✅ The field contract became the semantic contract: one field definition carries meaning/UI (`type`, `display_in_form`, `vault_roles`), indexing (`es_type`, `is_facet`, slot-driven per-vault mappings), AI interpretation (`ai_fill` extraction: hints + closed option lists, applied through `validatePartialResourceData`), and validation (`validators`, `required`) — all enforced by `schema:validate`.
- ✅ Naming consolidated at the docs level (ARCH §5.2/§5.3): no `semantic_schema` rename or parallel structure needed — existing schemes are valid as-is (`schema:validate` passes without migration), so the back-compat shim became unnecessary.
- ✅ Per-field suggestion review: `AI_SUGGESTED_METADATA` values surface in the per-file AITY card (`suggestions.metadata` in aity-status), one row per scheme field, alongside name/description/tags.

---

## Milestone 4 — AI Layer (AITY + MCP) ✅ CLOSED 2026-07-07

**Goal:** vault-aware AI reasoning via MCP tools, embeddings, and graph — and
turn discovered structure into Vaults (clusters → auto-created, LLM-named Vaults).
**Exit criteria (met):** AITY answers vault-scoped queries through the vault
operation layer only — `VaultAskService` retrieves exclusively via
`VaultOperationService` (the REST↔MCP 1:1 surface), no raw DB access, exposed
as `POST /vaults/{id}/ask` + internal-MCP `ask_vault` (Epic 4.4); clustering
materializes auto-named cluster Vaults on demand (`graph:materialize` /
`POST /platform/vaults/clusters/rebuild`, Epic 4.5).
**Depends on:** Milestone 3 (+ Milestone 2 for the Vault entity that Epic 4.5 writes to).

### Epic 4.1 — MCP tool layer `[mcp][ai]`
- ✅ `@tydal/mcp` internal surface built out organically — 14 tools incl. `ask_vault` (Epic 4.4); the read-only vault MCP shipped in Epic 2.4 (14 tools). Both migrate onto `@tydal/client` in Epic 5.0.
- ✅ Semantic tools on the vault surface (2026-07-07), verb taxonomy per `VAULT_SYSTEM.md` §7: `mode=semantic` on `/search` — resources ride k-NN over the per-vault index embeddings (Epic 3.2), `scope=chunks` rides the chunk vectors, both degrade to keyword when index/embedder is missing (answered `mode` reported) — plus the `/embed?q=` compute op → MCP `embed_query` (diagram `embedQuery`). `summarize` deliberately **not** built: reasoning belongs to the consumer's AI, TYDAL exposes data + compute only (client framing, §10 invariants).
- ✅ Vault-scope enforcement + authz in every tool (inherited by construction: every tool is a thin call onto the `/v/`–`/h/` grammar, resolved+gated server-side; `embed`/semantic gated like any Tier 0/1 op).
- ✅ Tool tests: backend `VaultSemanticSearchTest` (11) + vault-mcp suite (19).

### Epic 4.2 — Embedding pipeline `[ai][backend]`
*(✅ CLOSED 2026-07-07 — realized incrementally across M1–M3 rather than as one build; audit below.)*
- ✅ File embeddings at ingestion (`EmbedFileChunks`, chunk vectors in `*_chunks` indices) · Resource embeddings = the M1 mean (`resources.embedding` + `dense_vector` on the resource doc) · Vault embeddings = the per-vault index's `dense_vector` copies (Epic 3.2) — a rebuildable cached projection, exactly as sketched.
- ✅ Pluggable provider interface (`EmbeddingServiceInterface`: ollama / voyage / jina); vector store = ES `dense_vector` (pgvector still optional-later).
- ✅→⬜ Aggregation strategies: `mean` shipped (canonical-only or equal components); `weighted` / `ai-aggregated` **deferred until a consumer needs them** — nothing in the current surfaces ranks differently by strategy.
- ✅ Re-embed on resource change: implemented through the job pipeline + composition hooks (`EmbedFileChunks` completion, canonical promotion/demotion via `refreshDerivedArtifacts`) rather than a `resource_events` table — same guarantee, no event log entity.

### Epic 4.3 — Graph system `[graph][backend]`
*(All ✅ 2026-07-07 — `resource_relations` + `ResourceGraphService`.)*
- ✅ Relationship model: `related` (symmetric, one row per pair, subject/object normalized by UUID order) and `derived_from` (directed) on `resource_relations` — typed, weighted, org-scoped, with `origin` as the rebuild domain (`manual` curator edges vs rebuildable `tags`/`semantic`). `IN_VAULT` deliberately **not stored**: vault membership *is* the projection (`vault_links`/`vaultResourceQuery`) — materializing it would only drift.
- ✅ Backlinking + multi-hop traversal API: `GET /resources/{id}/graph?depth=1..3&types=` (BFS, node-capped, hop distance per node) · `GET/POST/DELETE /resources/{id}/relations` (curator edges, policy-gated; symmetric dedupe, self-loop/cross-org rejected) · `ResourceGraphService::backlinks`. Vault `/related` (and MCP `list_related`) now rides graph edges first — heaviest first, **projected into the vault** (out-of-vault neighbors never leak), each hit carrying `relation {type, origin, weight}` — with the shared-tag heuristic as fallback (`source: graph|tags`).
- ✅ Auto-relation builders + clustering (feeds Epic 4.5): `graph:rebuild [--org=] [--tags] [--semantic]` — tag co-occurrence (≥ min-shared shared tags, Jaccard weight, hub-tag cap) and embedding k-NN (`ElasticsearchService::knnSearchResources` over the resource mean embeddings, min-score threshold); each origin drops/recreates only itself. `graph:clusters [--json]` — union-find connected components over `related` edges with dominant tags per cluster. Tests: `ResourceGraphTest` (13).

### Epic 4.4 — AITY reasoning engine `[ai]`
*(All ✅ 2026-07-07 — `VaultAskService`; INTERNAL surface — the external `/v` `/h` boundary never reasons, spec §10.)*
- ✅ RAG loop exactly as diagrammed: detect vault context (`vaultMeta` incl. the resolved overlay/presentation — field→slot semantics ship in the system context so the LLM can interpret metadata) → retrieve embeddings (semantic `vaultSearchChunks` for Tier 1 passages + semantic `vaultSearch` for Tier 0 identity cards) → expand graph (`resourceRelated` on the top hits — graph-source only, projected into the vault) → respond (LLM + resource-level citations). Surfaces: `POST /vaults/{id}/ask` (org members; works on unpublished vaults — internal) and internal-MCP **`ask_vault`**.
- ✅ Hybrid ranking: chunk passages (content evidence) + identity cards with slot-mapped metadata (metadata evidence) merged into one context; each retrieval degrades semantic→keyword→DB independently.
- ✅ Guardrails **by construction**: every retrieval step goes through `VaultOperationService` — the same REST↔MCP 1:1 operation layer external clients use — so scope (vault projection) and tier policy (a chunk-less gallery vault is answered from Tier 0 cards alone) are enforced by the boundary itself, no raw SQL; citations are resource slug + pages, never file identifiers. Tests: `AityVaultAskTest` (6).

### Epic 4.5 — Clusters → auto-created Vaults `[vault][ai][graph]`
*(All ✅ 2026-07-07 — `ClusterVaultService`, org-scoped.)*
- ✅ `POST /platform/vaults/clusters/rebuild` (+ `php artisan graph:materialize [--org=] [--min-size=]`) — runs the Epic 4.3 clustering and **upserts one Vault per cluster**, projected through a dedicated **system workspace** (`is_system`, `purpose='cluster'`) holding the members. Org-scoped rather than the originally sketched collection scope (the graph and vaults are org entities).
- ✅ Provenance, not purpose: cluster vaults ship as **`purpose = mixed`** with a new **`generated_from = 'clusters'`** column — per the ratified client framing, purpose is the *consumption promise* (which kind of client reads the vault) and "cluster" is *provenance*; a discovered grouping is still consumed as gallery/AI/whatever. `generated_from` is the rebuild domain: the rebuild only ever creates/updates/retires its own vaults, curator vaults are untouchable by construction.
- ✅ **Auto-naming by consumer:** machine `slug` computed (deduped per org) and **immutable across rebuilds** — matching new clusters to existing cluster vaults by member-set overlap (greedy Jaccard ≥ 0.5) keeps slug + hash stable while membership and the human `name` refresh; `name` is an LLM call over the cluster's top SemanticTag labels + sample resource names, degrading to a tag-derived title (then `Cluster N`) when the LLM is down.
- ✅ On-demand rebuild; stale cluster vaults retired **with their system workspaces** (a human-attached workspace survives). New/updated vaults null `indexed_at` and queue `RebuildVaultIndex`, so the whole existing vault stack — `/v` `/h` grammar, per-vault index + facets, semantic search, vault-mcp — serves them with zero new surface. Born unpublished (key-gated until a curator publishes).
- ℹ️ Connected-components clustering yields disjoint clusters today; the M2M projection supports overlapping cluster vaults whenever a fuzzier clusterer (k-means on embeddings) lands.
- Tests: `ClusterVaultTest` (8).

---

## Milestone 5 — Experience Layer

**Goal:** productize Vaults as a **suite of independent apps** (one per Vault
mode), all on a **shared client SDK**, plus publishing. See
[`ARCHITECTURE_AND_ROADMAP.md`](../architecture/ARCHITECTURE_AND_ROADMAP.md) §3.5.
**Exit criteria:** `@tydal/client` is the single transport for every surface
(frontend + MCP migrated onto it); a vault can be viewed as gallery, Obsidian
graph, or AI chat — each a separately-themed app — and published via signed
vault-scoped URLs.
**Depends on:** Milestone 4 (AI UI), Milestone 2 (SDK + gallery/obsidian can
start after M3). **Epic 5.0 is a prerequisite for 5.1–5.3.**

### Epic 5.0 — Shared client `@tydal/client` `[client][frontend]`
*(Core ✅ 2026-07-07 — built + verified on the earlier `boveda` line, ported onto `vaulting` in one pass; details in `client/README.md`.)*
- ✅ Framework-agnostic TS SDK at `tydal/client/` (`@tydal/client`): `createTydalClient({ baseUrl, auth, orgId?, fetch? })`, pluggable `TokenProvider` (reactive 401 refresh-retry), `success`-gated envelope unwrap with `raw` opt-out, `TydalApiError` (status + field errors), XHR upload progress with fetch fallback, SSE via `http.stream`, zero runtime deps (global `fetch`, late-bound so MSW-style mocks work). npm workspace rooted at `tydal/package.json` (`client`, `frontend`, `mcp`, future `vaults/*`; one root lockfile).
- ✅ Both hand-rolled clients consolidated: every `frontend/src/api/*Service.ts` is a thin wrapper over the SDK (duplicate `API_BASE_URL`/auth-header logic gone; `authService` runs on its own SDK instance, `authenticatedFetch` deleted) and `mcp/src/client.ts` is an axios-compatible shim over `tydal.http` (tools unchanged; `axios`/`form-data`/`axios-mock-adapter` dropped, tests on a fetch-mock).
- ✅ Frontend migrated onto `@tydal/client` — incl. the vaulting-era `vaultService` (platform CRUD, keys, workspace attach, links) and `+ src/utils/apiError.ts` for consistent error UX. tsc clean, 54/54 tests, build + lint green.
- ✅ `@tydal/mcp` migrated (single transport for both surfaces) — 21/21 tests incl. the M4-era `ask_vault`/`list_resource_chunks` tools, untouched by the swap.
- ✅ Versioning + publish (2026-07-11): **`@tydal/client@1.0.0` on public npmjs**, driven by the first out-of-repo consumer (Full Frame). The `tydal` npm org holds the scope; package carries LICENSE/`publishConfig.access=public`/`repository.directory`. Smoke-tested from an empty project against a live vault. CI publish-on-tag still manual (tracked in the Full Frame roadmap E0.1).
- ℹ️ **Reversed later (2026-09-22):** `mcp/` was renamed `org-mcp/` and deliberately moved *back* off `@tydal/client` onto its own `fetch` client — both MCP servers are run by customers on their own upgrade schedule, not deployed by TYDAL, so depending on a TYDAL-internal package was a real compatibility burden with no offsetting benefit (the tools never used the SDK's envelope-unwrapping or typed methods anyway, `raw: true` everywhere). `@tydal/client` remains the single transport for `frontend/` and `vaults/*`, the surfaces TYDAL deploys itself. See `client/README.md` and `docs/architecture/ARCHITECTURE_AND_ROADMAP.md` §3.5.
- ℹ️ `vault-mcp/` deliberately stays on its own thin client for now: it speaks the *public* `/v`–`/h` surface with `X-Vault-Key` auth, not the authenticated API the SDK models. Revisit if the SDK grows a vault-consumer namespace.

### Epic 5.1 — Gallery vault app `[frontend][client]`
*(✅ 2026-07-08 — `vaults/gallery/` + `createVaultConsumer` in the SDK; verified
end-to-end against a live vault: wall, facets, keyword search, lightbox +
related works, key-gated private vaults, `?hash=` machine addresses, load-more.
Closeout fixes: Lightbox now shares ArtCard's `/files` image fallback
(`useCardImage`), and vault creation dispatches `RebuildVaultIndex` so a new
vault gets facets/semantic without waiting for a purpose/overlay change.)*
- ✅ Independent themed app consuming `@tydal/client` (own design system, not bound to the MUI TYDAL theme) — `vaults/gallery/`, dev on :3010, static build hostable anywhere.
- ✅ Grid/gallery renderer using the resolved presentation slots (`/meta` → caption/subcaption/image/badge/detail/credit; slot engine in `src/presentation.ts`).
- ✅ Facet/filter integration with per-vault index (`facet[field]=value` chips, re-aggregated per query; semantic toggle when the vault advertises the mode).
- ✅ Semantic mode verified with real document embeddings (PDF → Tika chunks → Jina vectors → resource mean → vault-index KNN; "by meaning" returns the document for a natural-language query).
- ✅ Resource `preview` op + card `preview` field (2026-07-08 follow-up): the designated snapshot / rendered `PREVIEW_SNAPSHOT` serves through the vault boundary (`…/{resource}/preview`), cards carry the address, and the gallery prefers it over probing. Rendition URLs de-leaked in the same pass (`download?rendition=` instead of raw storage/media URLs). Existing vault indexes need one rebuild to pick up the card field.

### Epic 5.2 — Obsidian vault app `[frontend][graph][client]`
*(✅ 2026-07-08 — `vaults/obsidian/` (`@tydal/obsidian`, dev on :3011) + a new
vault-level `graph` op (`/v|/h …/graph`: node cards + both-ends-projected
edges, cross-vault edges never leak, node cap + `truncated` flag; advertised in
`/meta`, `graph()` in the SDK). Verified live against a 137-note / 997-edge
tag-materialized graph: force layout, node → note, backlink walking, filter,
error states.)*
- ✅ Independent themed app consuming `@tydal/client` (own design system — ink-dark notebook, violet accent; zero runtime deps beyond React + SDK).
- ✅ Graph view (deterministic force layout over `/graph`, degree-sized nodes, origin-styled edges, active-neighborhood highlighting) + backlinks panel (incoming/outgoing from the projected edges, edge origin shown).
- ✅ Note rendering from the obsidian slots (`node_label` / `property` / `body` / `hidden`) + extracted document text via `/chunks`, through a sanitizing zero-dep markdown renderer. ⚠️ body/chunks paths are unit-tested but not yet exercised against a vault with real markdown fields — worth one pass when such a scheme exists.

### Epic 5.3 — AI vault app `[frontend][ai][client]`
*(✅ 2026-07-08 — `vaults/aity/` (`@tydal/aity`, dev on :3012) + the boundary
form of the M4 AITY loop: `POST /v|/h …/ask`, gated by the new
`Vault::allowsAsk()` policy (ai|mixed answer by default; others 403 unless
`exposure_policy.allow_ask`), throttled 20/min/IP, CSRF-exempt (sessionless
machine surface), advertised as `tiers.ask` in `/meta`; `ask()` in the SDK.
Verified live: grounded answer from the Andalusia PDF via local llama3.2 +
mxbai retrieval, sources footer with page numbers, purpose gate 403s a
gallery vault and the app disables its composer.)*
- ✅ Independent themed app consuming `@tydal/client` (own design system — archive reading room, teal on charcoal; zero runtime deps beyond React + SDK).
- ✅ Chat surface bound to AITY, vault-scoped by construction (retrieval runs through the same tier-gated grammar; sources are vault addresses).
- ✅ Inline citations (cited names woven into links via `src/citations.ts`; sources footer always) + "Explore related" follow-up chips from the top source's `/related`.
- ✅ SSE streaming (2026-07-08 follow-up): the same `/ask` route streams token-by-token when the client sends `Accept: text/event-stream` (`token` frames → `done` frame with sources), JSON otherwise — one route, one gate. Capability-gated at the driver (`StreamingLlmInterface`, only Ollama implements it; others degrade to a single chunk); `askStream()` in the SDK; the chat app renders the answer live with a blinking cursor. Verified end-to-end: 93 token frames through the container, first-token+1s vs done+2s (nginx buffering off), UI answer grew 10→1087 chars incrementally.
- ℹ️ Small local models sometimes answer without naming resources, in which case only the sources footer carries the citation (not the inline links).

### Epic 5.4 — Publish system `[infra][vault]`
*(✅ 2026-07-08 — signed grants complete the access triad (spec §6.4):
published / key / time-limited signed URL. HMAC keyed by the vault salt (salt
rotation = mass revocation, nothing stored server-side), vault-scope or
link-scope (resource grants cover their file addresses), minted via
`POST /platform/vaults/{id}/signed-urls`, accepted on both address forms,
threaded through the SDK (`grant` config) and all three vault apps
(`?sig=&exp=`). Verified live: private vault 404s bare, opens fully through
the signed URL in the notes app; link-scope share opens one resource + its
image while siblings and the vault surface stay hidden.)*
- ✅ Vault-scoped public URLs + signed links (grants above; standing forms — `is_published`, keys, opaque `/h` addresses, `base_url`, link TTL + IP allowlist — predate this epic).
- ✅ Contextual rendering / multiple representations per resource — covered by the manifest rule (JSON vs binary by address), per-purpose presentation slots, `/preview` (the resource's face) and `download?rendition=` conversions (Epic 5.1 follow-ups).

---

## Milestone 6 — TYDAL v2 Release

**Goal:** ship the fully schema-driven semantic indexing system.
**Exit criteria:** all above green; migration guide; tagged release.
**Depends on:** Milestones 1–5.

### Epic 6.1 — Hardening `[infra]`
- 🔄 Perf pass (slug cache, per-vault index, embedding retrieval). **2026-07-10**: profiled the boundary path — vault-level resolution already Redis-cached (hash/slug→vault, 2 queries warm). Fixed the **N+1 in `vaultIndex`/`vaultGraph`**: each card ran 3 queries (link mint + 2 snapshot lookups), so a page cost O(3N) — a 100-item page ≈ 300 queries. Now batched (`VaultLinkService::getOrCreateResourceLinks` one SELECT + eager-loaded snapshot relations): **flat 7 queries regardless of page size** (verified 5 and 100 resources both = 7q, ~4ms). `VaultIndexQueryBudgetTest` pins O(1). **Embedding retrieval**: the ask pipeline embedded the same question twice (chunk search + card search) → two provider round-trips per ask; `embedQueryVector` now caches the vector in Redis (30 min, keyed by driver/model/dims + query hash — deterministic per model), so it embeds **once** (live-verified 2→1 calls; `VaultSemanticSearchTest` pins it). Popular queries also share across requests. Perf pass **done**.
- 🔄 Security review (vault isolation, signed URLs, MCP authz). **2026-07-24**: second pass, driven by the Full Frame integration. Five real defects, all fixed with regression tests. **Tenancy**: (1) `GET /vaults` listed every organization's vaults **including the signing salt** — now org-scoped, safe columns only, and `salt` is `$hidden` on the model; (2) `POST /workspaces/{id}/resources` never checked the resource's org, so a foreign resource dropped into a workspace would project through that org's public boundary — now 422, matching the vault-attach rule; (3) `GET /resources/{id}/vault-links` had **no authorization beyond being logged in** — any user, including one in zero organizations, could mint and read any resource's public link hashes (the four existing tests passed with an org-less user, which is how open it was) — now 404 unless the caller can access the resource's org. **Boundary**: (4) org pinning applied only to the `has_public_workspace` branch of `vaultResourceQuery`/`validateLink` — hoisted so both branches are pinned and a stray pivot row can never cross orgs; `buildDeliveryPairs` also selected public delivery vaults across **all** orgs; (5) the legacy flat address `/vault/{hash}` skipped the publish gate for every purpose, so a `private` gallery's works were one URL away from anyone holding a link hash — the gate now applies to projection purposes (key/grant threaded through) while `delivery` keeps its CDN semantics. Also fixed a pagination determinism bug (missing `id` tiebreaker) and a stale-vault-index bug (attach/detach now rebuild). Remaining: no rate limit on the public link routes.
- 🔄 Security review (vault isolation, signed URLs, MCP authz). **2026-07-10**: full-backend pass. Vault boundary (grants HMAC+epoch, key hashing, tenant-context header validation, token abilities) sound. **Fixed**: (1) collection-scheme + search-index **writes** were gated only by `auth:sanctum` though schemes are global/cross-tenant infra — now `superadmin`-only (reads stay open); (2) scheme field `name` now charset-validated (`^[a-z][a-z0-9_]*$`) at the write API — it flows into raw SQL in the catalogue DB-facet path (`CatalogueController::buildDbFacets`). `CollectionSchemeAuthorizationTest` covers both. Remaining: perf + load below.
- ⬜ Per-vault / per-org RAG floor (`exposure_policy.rag_min_score`, `settings.aity.rag_min_score`) — **done 2026-07-10**.
- ⬜ `search:reconcile` chunk-drift coverage (stale generations, missing/orphaned meta chunks) — **done 2026-07-10**.
- ✅ Load/regression tests. **2026-07-10**: profiling caught two more N+1s beyond `vaultIndex` — `resourceRelated` (15→9q) and the DB-fallback `vaultSearch` — both routed through a shared `buildCards` helper (batched links + eager snapshot loads), so every listing (index, search, related, graph) is now O(1) in result size. `VaultIndexQueryBudgetTest` asserts the O(1) contract across all four. Added `tools/deploy/loadtest.sh` — on-demand concurrent load smoke on the boundary (throughput/error-rate/p50-p99); a healthy baseline run showed 0 errors at c=20, p95 ~100-125ms.

- ✅ **State-model simplification** (**2026-07-24**, `[breaking]`). Two families of overlapping flags collapsed into one field each: `resources.active` + `visibility` + `published_at` → `resources.state` (`draft`·`live`·`archived`), and `vaults.is_active` + `is_published` → `vaults.state` (`disabled`·`private`·`public`). The resource trio overlapped — `visibility` only ever enforced `draft` and only in the catalogue, `active` only in the vault projection, `published_at` was never read — which is why neither "hidden" flag was applied consistently: an archived resource still resolved at its vault address and a draft was projected publicly. Both are fixed structurally by having one filter. The vault pair was never orthogonal (the policy gate short-circuits on activity, so "inactive but published" was unobservable). One migration backfills and drops; `down()` is deliberately lossy. Touches every surface: ES mapping + documents, `@tydal/client` `VaultMeta.state`, `@tydal/mcp` tool schema, the admin UI (two switches → one State select), and Full Frame's opening flow (`POST /vaults/{id}/publish` now takes `{state}`).

- ✅ **Vault write methods** (**2026-07-24**, `[breaking]` for Full Frame). Made the vault a *write* boundary, not just a read one: it accepts a purpose-defined set of methods (`gallery` → `activate`/`open`/`close`) gated by a **write-capable vault key**, symmetric with the read tiers. Vault keys grew an `abilities` set (`["read"]` vs `["w:activate", …]`); `VaultPurpose::writeMethods()` mirrors `allowsChunks/…`; a dedicated `authorizeWrite` gate (not the read policy — writes must reach a *private* vault) serves `POST /{h|v}/…/w/{method}` (CSRF-exempt); every accepted call writes a `vault_writes` audit row; `activate` is org-pinned and reversible via a `vaults.selection_snapshot`. This **deletes the org-admin service-token path** for Full Frame's opening: it's now `activate` + `open` on the vault's own write key — one vault, no org token, no management API, no `X-Organization-ID`. Full Frame stores a read + write vault key per exhibition, encrypted at rest (AES-256-GCM); `writeback.ts` no longer touches workspaces. Spec: `docs/VAULT_WRITE_METHODS.md`. Verified: 13 new Pest tests + SDK/frontend green + live gate smoke (which caught a CSRF-exemption gap, now fixed).

### Epic 6.2 — Docs & migration `[docs]`
- ✅ v1 → v2 migration guide — `docs/MIGRATION_V1_V2.md` (CDN→Vault concepts + terminology map; it's a pre-release rename with no live URLs, so a concepts/mapping guide, not a data-cutover runbook). **2026-07-10**.
- ✅ Update architecture doc statuses (🔜 → ✅) — `ARCHITECTURE_AND_ROADMAP.md` reconciled: intro now states the v2 build is complete; all shipped-feature markers flipped (vault system, embeddings, graph, `@tydal/client`, apps, AITY, vault-scoped indexing). Only `pgvector` stays 🔜 (genuinely future). **2026-07-10**.
- 🔄 API/MCP reference refresh — docs index (`docs/README.md`) now lists vault-mcp + client SDK and drops stale "CDN links" wording; OpenAPI schema-write ops annotated superadmin (Epic 6.1). Remaining: broader OpenAPI sweep for vault-grammar endpoints if desired.

### Epic 6.3 — Release `[infra]`
- 🔄 Versioning + CHANGELOG. **2026-07-10**: first **published** release is **1.0.0** (the "v2" is internal architecture lineage, not a public version). `CHANGELOG.md` written (Keep a Changelog, capability-organized initial release); all package.json + `backend/.version` bumped to 1.0.0.
- ✅ Tag `v1.0.0` — tagged **2026-07-31** at `338d4b4`. Remaining: publish the release notes (draft in `RELEASE_NOTES.md`) on GitHub via the UI, any time.

---

## Dependency overview

```
M1 Stabilization  (resource roles + schema + ES stability)
   └─> M2 Vault Introduction  (CDN → Vault: rename + extend; name human / slug machine)
          └─> M3 Schema-Driven Indexing  (VaultSchemaOverlay, per-vault ES)
                 └─> M4 AI Layer  (MCP + embeddings + graph + Epic 4.5 clusters→Vaults)
                        └─> M5 Experience Layer  (@tydal/client + per-mode apps)
                               └─> M6 Release
   Notes:
   • Epic 4.5 (clusters → auto-named Vaults) needs M2 (Vault entity) + M4 (clustering).
   • M5: extract @tydal/client first [5.0] — prerequisite for all vault apps;
     gallery/obsidian may begin after M3, AI vault app needs M4.
   • @tydal/client [5.0] is transport-only and MAY be pulled forward to run
     parallel to M1/M2 (pays down the duplicate-client debt early).
```
