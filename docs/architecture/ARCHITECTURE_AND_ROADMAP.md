# 📘 TYDAL — Architecture & Roadmap

**TYDAL — the typed Digital Asset Layer**

This document describes the internal v2 architecture. Product release numbers
are recorded separately in the [changelog](../../CHANGELOG.md).

> This is the canonical architecture + roadmap reference for the project. It
> grounds every section in what exists in the codebase.
>
> **Status (2026-07-10):** the v2 build is complete — Milestones 1–5 are closed
> (vault system, schema-driven indexing, AI layer + MCP, embeddings, graph, the
> `@tydal/client` SDK, and the vault renderer apps all shipped). Milestone 6 is
> release hardening (perf, security review, migration docs, tag). Sections
> below marking a feature 🔜 predate the build and are being reconciled to ✅;
> the authoritative per-epic status lives in
> [`ROADMAP_MILESTONES.md`](../planning/ROADMAP_MILESTONES.md).
>
> Status legend: ✅ built · 🟡 partial / foundation present · 🔜 future (post-v2)
>
> **Companion docs:** [`SYSTEM_DIAGRAM.md`](SYSTEM_DIAGRAM.md) (visual architecture
> & data-flow diagrams) · [`ROADMAP_MILESTONES.md`](../planning/ROADMAP_MILESTONES.md)
> (issue-ready milestones, epics & tasks).

---

## 1. 🧭 Vision Summary

TYDAL evolves from a tagged DAM + workspace system into:

> A **schema-driven semantic knowledge system** where **Vaults** define
> contextual projections over shared **Resources**, enabling AI-native
> interaction via **MCP**, **embeddings**, and **graph** navigation — presented
> through a graphical UI for Obsidian-style and AI vaults.

---

## 2. 🏷️ Product naming and terminology

Use the same entity names in engineering documentation and product explanations.
Plain-language descriptions can simplify a concept without changing its meaning.

### 2.1 Resource model

| Name | Meaning |
|------|---------|
| **Resource** | A semantic object with metadata and relationships; it may contain one file, multiple components, or a canonical file with supporting files |
| **Collection** | Organizes resources with configured schemas and indexing |
| **Schema** | Defines resource fields, validation, forms and search behavior (`collection_schemes`) |
| **Workspace** | An organization-scoped curated selection of resources |
| **Vault** | A contextual view of selected resources, with policy and addressing; it can draw on workspaces without copying the underlying assets |
| **Experience** | A gallery, knowledge view, AI chat or other application consuming a Vault |

A Collection is not a gallery application, and a Vault is not simply a published
Workspace. Workspaces curate membership; Vaults define the external context and
access rules. Vaults can be public, private or disabled.

Resources are shared across Vault contexts, not immutable records. Authorized
operations can update their metadata, files and lifecycle state. Projection does
not create an independently edited copy of the underlying resource. See the
[resource model](RESOURCE_MODEL.md) and [Vault specification](VAULT_SYSTEM.md).

### 2.2 Brand and message hierarchy

- **TYDAL / Tydal:** the product; use Tydal in website prose and retain TYDAL in
  repository titles and technical documentation.
- **Eonity:** the umbrella for the open-source product family.
- **Tydalia:** the existing social identity; the website is published at eonity.org.
- **Headline:** Control your knowledge flow.
- **Category:** Open-source Semantic Asset Platform.
- **Product definition:** The typed Digital Asset Layer.
- **Developer explanation:** The semantic layer between your files and your AI.
- **AI integration description:** Schema-driven semantic Vaults for AI agents.

Use these at different levels rather than stacking them as competing slogans.
“Typed” refers to schema-defined fields, validation and search behavior, not only
to file types or the SDK's programming language. People and applications remain
consumers alongside AI agents.

The **TY** signature comes from EONI**TY**; **DAL** means **Digital Asset Layer**.
Earlier naming proposals such as “Trusted Yield” or “Digital Asset Library” are
superseded. TYDAM belongs to historical DAM terminology, not the current product
hierarchy.

### 2.3 AI interfaces

Server-side AITY enrichment produces suggestions for review or configured
automatic approval. Organization MCP lets authorized agents perform management
operations, applying final values directly. Vault MCP exposes one Vault and is
read-only by default, with supported writes enabled by a write key. The Aity
Vault app provides an Ask AI interface. See [AI surfaces](AI_SURFACES.md) for the
scope and behavior of each interface.

### 2.4 Source, license and website

- **Public product repository:** [eonity-org/tydal](https://github.com/eonity-org/tydal).
- **License:** [Apache-2.0](../../LICENSE); the current product has no proprietary-core split.
- **Confirmed website domain:** `eonity.org`.
- **Application SDK:** `@tydal/client` supports the management frontend, Vault
  apps and external integrations. The `org-mcp` and `vault-mcp` adapters use
  their own HTTP wrappers; see [the SDK README](../../client/README.md).


---

## 3. 🧱 System Layers

TYDAL is structured into four core architectural layers, mapped to **Data →
Knowledge → Intelligence**, plus a fifth **Experience** layer that presents them
(§3.5). The four core layers are the engine; the Experience layer is a suite of
independent apps that render Vaults over a shared client.

### 🟣 3.1 Physical Layer — Storage & Media

Purpose: raw data storage and delivery.

| Component | Status | Backed by |
|-----------|--------|-----------|
| Files (binary objects) | ✅ | `files`, `system_files` |
| Media storage (image/video/audio) | ✅ | `media` (Spatie MediaLibrary v11) |
| File chunks (processing) | ✅ | `file_chunks` |
| Vault storage & links (classic CDN) | ✅ | `vaults`, `vault_links`, `workspace_vault` (renamed from `cdns`/`cdn_links`/`workspace_cdn`, 2026-07-03) |

### 🔵 3.2 Semantic Layer — Resource System

Purpose: canonical representation of knowledge objects.

A **Resource** is a schema-driven metadata container that may be single-file or
composite. Each file carries a **role** (`FileRole`) defining its contribution
to the resource's metadata (the source for indexing/embeddings):

| Role | Metadata contribution | Example |
|------|-----------------------|---------|
| `canonical` | **sole** contributor — exactly one per resource drives metadata/embedding | a CD's tracklist file (the audio tracks are `supporting`) |
| `component` | **all** components contribute **equally** (`mean` aggregation, §8) | a scanned multi-page document where each page's text contributes |
| `supporting` | **pure ancillary** — attachment / alternate representation, **not** indexed or embedded | the CD's audio tracks · a movie's subtitles, as downloadable extras |

**Example resources:**

- **Canonical (single contributor)** — one file drives metadata; extras are
  `supporting`:
  - a PDF report or article · a photograph · a video · a 3D model.
  - a **CD** modeled as a **tracklist file (canonical)** + the **audio tracks
    (supporting)** — the resource is found via the tracklist; the tracks are
    downloadable but not indexed/embedded.
- **Multi-component (equal contributors)** — the resource *is* the set, and every
  part counts equally toward the aggregated (`mean`) searchable text/embedding:
  - a **scanned multi-page document** (each page's OCR text) · a **photo series**
    or contact sheet · a **dataset** split across files · a **comic issue**
    (pages).

> The **preview/snapshot** flag (`usage:['snapshot']` — the image identifying the
> resource) is **orthogonal** to role: any file may be the snapshot regardless of
> its role or contribution.

> Metadata promotion for `canonical` exists today (`promoted_file_metadata`);
> `component` aggregation is planned (Milestone 1, Epic 1.1). Multi-file resources
> are a **niche** case — supported, not a priority.
>
> ℹ️ The `supporting` role is reserved for **future use** (e.g. optionally making
> supporting content searchable). That behavior is **not on the roadmap yet** —
> today `supporting` is strictly pure ancillary.

| Component | Status | Backed by |
|-----------|--------|-----------|
| Resources | ✅ | `resources` |
| Categories (hierarchical, org-scoped) | ✅ | `categories`, `category_resource` |
| Semantic tags | ✅ | `semantic_tags`, `semantic_tag_resource` |
| Resource ↔ Workspace mapping | ✅ | `dam_resource_workspace` |
| Resource events (event-driven hooks) | 🟡 | `resource_events` |
| AI-generated summaries | 🟡 | (AITY enrichment) |
| Embedding association per resource | ✅ | (see §8) |

### 🟢 3.3 Context Layer — Vault System ✅ **NEW CORE**

Purpose: a contextual **projection** layer over Resources. A Vault is a
*semantic view + policy + indexing configuration*, **never a data copy**.

Responsibilities: scope resources · define visibility rules · generate
contextual slugs · define CDN namespace · configure AITY scope · define search
behavior.

> 🎯 **Decision (v2, spec settled — see [`VAULT_SYSTEM.md`](VAULT_SYSTEM.md)):
> the Vault *is* the extended CDN entity, and the classic CDN becomes one
> `purpose` of Vault.** The existing CDN system already implements the
> publishing/projection facet — scoping (`workspace_vault`), policy
> (`has_public_workspace`, `allowed_ips`, `is_downloadable`, `hash_ttl_hours`,
> `base_url`), and a per-resource contextual link map (`vault_links`), all as
> projections (never copies). v2 renames and extends — rather than building a
> new entity from scratch. **The physical rename is done (2026-07-03):**
> `cdns → vaults`, `workspace_cdn → workspace_vault`,
> `cdn_links → vault_links`, plus code (`Cdn* → Vault*`), API routes
> (`/cdns → /vaults`), public link routes (`/cdn/{hash} → /vault/{hash}` — no
> legacy path kept; pre-release, no minted URLs in the wild), and consumers,
> as one pure mechanical change with no behavior delta, folded into the
> consolidated initial migration.

Settled design (full spec in [`VAULT_SYSTEM.md`](VAULT_SYSTEM.md)):
- **`purpose` enum** `delivery` · `gallery` · `obsidian` · `ai` · `mixed`,
  default `delivery` = today's CDN unchanged. Converting a CDN into a Vault is
  an Admin-only `purpose` update (a hash-domain change → links regenerate).
- **Org-scoped:** vaults gain `organization_id` (missing tenancy boundary
  today); slug uniqueness becomes per-org `(organization_id, slug)`.
- **Dual addressing, one operation grammar:** machine
  `/h/{vaultHash}/{linkHash}` (vault gets its own short opaque `hash`; vault
  resolves *before* link — policy known first, no global hash lookup) · human
  `/v/{orgSlug}/{vaultSlug}/{resourceSlug}` (org in path since slugs are
  org-unique). Same tier-gated operations (`/meta`, `/chunks`, `/links`, …)
  after resolution.
- **Workspace-free vault hashes:** vault `link_key` =
  `sha256(vault:resource:file)` (no workspace — one stable address per
  resource); `delivery` keeps the workspace-scoped key. Resolution checks the
  resource is in *any* vault-linked workspace.
- **Membership via workspaces** (existing pivot): Admin (75)+ administers
  vaults; Editors populate them through workspaces. No new membership entity.
- **Projection tiers:** Tier 0 identity (name/description/tags) → Tier 1
  chunks → Tier 2 binary-on-request; file **roles** (§3.2) filter address
  exposure and chunk contribution independently; multi-file resources resolve
  to an ordered **manifest**.
- **Boundary state** (`state`): `disabled` (off) · `private` (vault key or
  signed grant required) · `public` (the address alone). One axis, because the
  old `is_active`/`is_published` pair was never orthogonal. The name Vault
  applies to all three states (§2.1).
- **Write boundary** (2026-07-24, [`VAULT_WRITE_METHODS.md`](VAULT_WRITE_METHODS.md)):
  the vault also accepts a purpose-defined set of *write* ops (`gallery` →
  activate/open/close; `ai` → `ingest`, 2026-07-25) gated by a **write-capable
  vault key** (`vault_keys.abilities`) — symmetric with the read tiers. The op is
  the permission/audit unit; its payload is a declarative document the purpose's
  writer maps, so `@tydal/client` exposes one generic `write(op, payload)` (no
  per-purpose namespaces). Consumers declare intent (`POST /{h|v}/…/w/{method}`)
  and TYDAL does the bookkeeping; this replaced the org-admin service token for
  Full Frame's opening, and lets an `ai` vault accept an AI's transformed output.
- **Naming by consumer:** `slug` (machine) + `name` (human) + `hash` (opaque
  machine id). LLM naming of auto-created cluster Vaults in Phase 4 / Epic 4.5;
  the same split applies per-resource via overlay roles (§5.2).

> Today the projection/publishing role is served by the **CDN** tables and the
> scoping role partially by **Workspaces** (`workspaces`, `workspace_user`,
> `dam_resource_workspace`). v2 promotes the CDN entity to **Vault**. The scope
> stays **many-to-many** (a Vault serves multiple workspaces) — membership is
> derived through workspaces, deliberately looser than the strict
> `Workspace → Vault → Resource` hierarchy sketched in §4; a direct
> resource↔vault pivot is deferred (see `VAULT_SYSTEM.md` §3).

### 🟡 3.4 Cognitive Layer — AI + MCP + AITY

Purpose: AI-native reasoning over Vaults.

| Component | Status | Notes |
|-----------|--------|-------|
| MCP tool server | 🟡 | `org-mcp/` package exists (`@tydal/org-mcp`) |
| AITY agent (vault-aware runtime) | ✅ | tool-based reasoning + graph traversal |
| Embedding retrieval | ✅ | vault-scoped |
| Graph traversal | ✅ | see §7 |

Planned MCP tools follow the **verb = tier** taxonomy of
[`VAULT_SYSTEM.md`](VAULT_SYSTEM.md) §7 — `get_` / `list_` / `search_` /
`resolve` (Tier 0 identity) · `read_chunks` (Tier 1 content) · `link_` (Tier 2
binary URLs, never streamed). The diagram's names map 1:1
(`searchVault → search_resources`, `resolveSlug → resolve`,
`getRelated → list_related`; `summarizeVault` / `embedQuery` slot in later).
Connections are scoped to **one vault** (vault key, or keyless when published);
the vault MCP is **read-only by default** — supply a write key and the vault's
purpose write ops (`ingest` on an `ai` vault) become tools, so a customer's own
MCP AI can read, transform, and write back through one connection. The existing
`@tydal/org-mcp` remains the org-wide management (general-CRUD) surface.

**Constraint:** AITY never touches the raw DB — it operates **only through
Vault-scoped MCP tools**, using an embeddings + graph hybrid.

### 🟠 3.5 Experience Layer — Vault Renderer Apps & Shared Client ✅

Purpose: present Vaults to humans. This layer is **not one monolithic SPA** — it
is a **suite of independent frontend apps, each rendering one Vault mode**, all
talking to the engine through a **single shared client library**.

> **One engine, many faces.** A Vault declares a `mode`; an app is the renderer
> for that mode. Style is per-app; transport is shared.

**Two distinct concerns, deliberately separated:**

| Concern | Where it lives | Rule |
|---------|----------------|------|
| **Data / transport** | `@tydal/client` (shared SDK) ✅ | one implementation, framework-agnostic |
| **Presentation / style** | each app | fully independent theme & design system |

**`@tydal/client` — the shared client (✅):** a framework-agnostic TypeScript
SDK wrapping the REST API: auth/token handling, catalogue & search, resources,
**Vault resolution + slug map**, CDN link construction. Published on public
npmjs (`@tydal/client`), it is the intended transport for **any client
building typed product/application code against TYDAL** — a real, versioned
package boundary (semver, deliberate upgrades), not a workspace link. That
covers both surfaces TYDAL builds and ships together on one release — the SPA
and the `vaults/*` renderer apps — and external product integrators outside
this repo, e.g. **Full Frame**, which consumes it from the public registry
exactly as any other npm dependency.

> **Rule of thumb for a new client:** if it renders typed UI or business logic
> against the API/vault grammar, use `@tydal/client` — that's what the SDK's
> envelope-unwrapping, typed methods, and auth/refresh handling are for. If
> it's a thin pass-through adapter that never inspects the response shape (an
> MCP server is the case in point — every tool call today just forwards raw
> JSON to an LLM), a dependency on `@tydal/client` buys nothing and costs a
> version-coupling liability; write a small `fetch` wrapper instead.

> ℹ️ `org-mcp` and `vault-mcp` (renamed from `mcp` — 2026-09-22) are exactly
> that second case, and both were moved onto plain `fetch` with no
> TYDAL-internal dependency (dropping `@tydal/client` from `org-mcp`, and
> `axios`/`form-data` from `vault-mcp`). Both servers are run by *customers*,
> on their own machines and their own upgrade schedule — `mcp` was never
> TYDAL-internal tooling despite the old name. Concretely, `org-mcp`'s prior
> dependency was `"@tydal/client": "*"` — an npm-workspace link with **no
> version boundary at all** (always tracks the monorepo's HEAD), unlike Full
> Frame's real semver pin; even a properly pinned dependency wouldn't have
> been worth it, since both servers already bypass the SDK's
> envelope-unwrapping (`raw` mode) and never used its typed
> resource/workspace/vault methods. Spec: `docs/architecture/VAULT_WRITE_METHODS.md`,
> `docs/architecture/VAULT_SYSTEM.md` §7. `frontend`'s service layer
> (`frontend/src/api/*Service.ts`) is the one remaining gap in "single
> transport for every surface TYDAL builds and ships itself" — it still goes
> through thin wrappers rather than the SDK directly in a few places.

**App suite — one app per Vault mode (✅):** each is its own build with its own
theme, consuming `@tydal/client`. The app reads Vault config + the **Vault
Schema Overlay** (§5.2) from the API and renders per the mode's field roles:

| Vault mode | App (external) | Reads field role |
|------------|----------------|------------------|
| `gallery` | Gallery app | `gallery_role` (e.g. `caption`) |
| `obsidian` | Obsidian/graph app | `obsidian_role` (e.g. `heading`) |
| `ai` | AI chat app (AITY surface) | `ai_role` (e.g. `semantic_anchor`) |
| `mixed` | composite shell | combines the above |

The existing `tydal/frontend` MUI SPA becomes **one app in the suite** (its
TYDAL theme — primary `#911A2C`, secondary `#214F61` — is one app's style, not a
global constraint), and the MCP server (§3.4) becomes another `@tydal/client`
consumer.

**External consuming products (built the same way, one per vault purpose):**

| Product | Repo | Vault purpose | What it adds |
|---------|------|---------------|--------------|
| **Full Frame** | `fullframe/` | `gallery` | photo exhibition + jury; opens via `activate`/`open` on a write key |
| **ImageLab** | `imagelab/` | `ai` | image→descriptor pipeline; writes back via `ingest` (2026-07-25) |

Both go through `@tydal/client` only, hold per-binding read + write vault keys
(encrypted at rest), and never touch the management API. The bring-your-own-AI
variant needs **no product at all** — a customer points any MCP AI at the vault
MCP with a write key and uses `ingest` directly (§3.4, [`VAULT_WRITE_METHODS.md`](VAULT_WRITE_METHODS.md)).

---

## 4. 🧠 Data Model Evolution

**Current**

```
Organization → Workspace → Collection → Resource → File(s)
                                  │
                          (search_indexes via collections)
```

**TYDAL v2**

The structural spine is unchanged; the **Vault is a *projection* entity (the
renamed CDN, §3.3/§9), not a container in the hierarchy.** It scopes Workspaces
**many-to-many**, carries a `purpose`/mode + publish state, and projects each
Resource as a contextual slug (`vault_links`) — never copying data.

```
            Organization
                 │
                 ▼
            Workspace ───────────────┐ (structural container)
                 │                    │
                 ▼                    │ M2M: workspace_vault
            Collection                │
            (scheme + index)          ▼
                 │              ┌──────────────┐
                 ▼              │    VAULT      │  = renamed CDN
            Resource ◀─────────│  purpose/mode │  projection, never a copy
             │   ▲   projects  │  publish state│
             │   │  (vault_    │  policy       │
             ▼   │   links)    └──────────────┘
           File(s)                   │
             │                       ▼
   role: canonical          /vault/{vaultSlug}/{resourceSlug}
       | component                (published URL)
       | supporting
```

- **Workspace** = structural container (org-scoped); **Vault** = published
  semantic projection layered *over* workspaces via the `workspace_vault` (ex
  `workspace_cdn`) pivot — so one Vault may span several workspaces, and a
  workspace may be exposed by several Vaults.
- **Resource → File(s)** carries the role model (`canonical` / `component` /
  `supporting`, §3.2) that determines what the Vault projects and indexes.

| Before | After |
|--------|-------|
| file-centric | resource-centric |
| workspace/global search | vault-scoped search |
| static indexing | schema-driven indexing |
| embedding per file | embedding per resource |
| UI-driven structure | schema-driven semantics |
| CDN = delivery afterthought | **Vault = CDN promoted to projection layer** |

---

## 5. 🧩 Schema-Driven System (Core of v2)

### 5.1 Collection Schemes ✅ (existing foundation)

`collection_schemes.fields` is already the single source of truth for: dynamic
forms, ES mapping generation (`es_type`, `es_fields`), facets (`is_facet`),
validation (`validators`, `required`), and MIME gating (`accepted_mimetypes`).

### 5.2 Vault Schema Overlay ✅ (Epic 3.1, 2026-07-04)

A contextual transformation layer over the base scheme — what a field *means*
inside each vault purpose. Field names are free; the **role vocabulary is the
fixed contract** (`VaultSchemaResolver::ROLE_VOCABULARY`). Resolution is
layered: structural preset (contract flags) ← scheme `vault_roles` (author
intent) ← `vault_schema_overlays` row (per-vault override, drift-tolerant):

```jsonc
// inside a field of collection_schemes.fields
"vault_roles": { "gallery": "badge", "obsidian": "property", "ai": "facet" }
```

The resolved map ships pre-computed in the vault self-description
(`/meta` → `presentation`) and compiles the per-vault ES index (§6) — the
slot, not the field name, decides indexing. Contract details:
[`SCHEMA_FIELDS.md`](SCHEMA_FIELDS.md).

### 5.3 Semantic Field System ✅ (realized as the extended field contract)

The "validation_schema → semantic_schema" evolution landed **in place**: the
field contract itself became the semantic contract, no rename or parallel
structure needed. One field definition now carries all four rule families —
**meaning/UI** (`type`, `display_in_form`, `vault_roles` presentation slots),
**indexing** (`es_type`, `is_facet`, slot-driven per-vault mappings),
**AI interpretation** (`ai_fill` extraction contract: hints + closed option
lists from `validators.in`, applied through the scheme's own validation), and
**validation** (`validators`, `required`) — all enforced by
`php artisan schema:validate`.

---

## 6. 🔍 Search & Indexing Architecture

**Current** ✅ — Elasticsearch 9 global/collection indices via `search_indexes`.
`CatalogueController` serves `GET /catalogue/{id}` from ES when a `SearchIndex`
is configured, falling back to DB automatically. Indexing is async via
`IndexResourceToElasticsearch` / `DeleteResourceFromElasticsearch` jobs.

**TYDAL v2** ✅ — vault-scoped indexing:

```
Resource → Schema → Vault Overlay → Search Index
```

Features: per-vault index projection · semantic role mapping · AI-driven ranking
signals · dynamic index recomposition.

---

## 7. 🌐 Graph System ✅ (Navigation Layer)

Property graph model:

```
(Resource) --RELATED-->     (Resource)
(Resource) --IN_VAULT-->    (Vault)
(Resource) --DERIVED_FROM-> (File)
```

Features: Obsidian-like backlinking · semantic clustering · multi-hop traversal ·
AI-guided expansion. **Graph defines meaning; embeddings rank it.**

> **Clusters → Vaults:** semantic clustering (tag co-occurrence connected
> components, or k-means on resource vectors) can **materialize each discovered
> cluster as an auto-created Vault** (`purpose = cluster`), LLM-named from its
> dominant tags (§3.3 naming-by-consumer). Reuses the whole vault stack —
> catalogue, vault-scoped search, `/vault/{id}/ask`, MCP — for free. M2M scope
> allows **overlapping** clusters (a resource in several cluster-vaults). Planned
> in Phase 4 (Epic 4.5).

---

## 8. 🧬 Embedding Architecture ✅

| Level | Role |
|-------|------|
| File embeddings (raw) | ingestion only |
| **Resource embeddings (primary)** | canonical semantic vector |
| Vault embeddings (cached projection) | scoped AI context index |

Aggregation strategies: `mean` · `weighted` · `ai-aggregated`.

---

## 9. 📦 Vault Publishing System (formerly CDN)

**Core idea:** the same Resource → different Vault views → different URLs. This is
**already implemented** by the CDN system, which v2 promotes to the **Vault**
entity (§3.3).

Current ✅: `vaults`, `vault_links`, `workspace_vault` — named channels with
delivery policy, per-(channel, workspace, resource) deterministic slugs/hashes,
signed + expiring links, downloadable/public flags, IP allow-lists. This *is*
the projection layer. (Renamed from `cdns`/`cdn_links`/`workspace_cdn` on
2026-07-03 — tables, code, and API routes — as a pure mechanical change.)

v2 ✅ (extend, not rebuild — full spec in
[`VAULT_SYSTEM.md`](VAULT_SYSTEM.md)):
- Add `organization_id`, `purpose` (`delivery`·`gallery`·`obsidian`·`ai`·`mixed`,
  default `delivery` = classic CDN), publish state, and a per-vault opaque `hash`.
- Dual addressing with one tier-gated operation grammar:

```
/h/{vaultHash}/{linkHash}[/{op}]                                  (machines — opaque)
/v/{orgSlug}/{vaultSlug}[/{resourceSlug}[/{fileSlug}]][/{op}]     (humans — semantic)
/vault/{hash}                                                     (flat delivery links, current)
```

---

## 10. 🧪 Technology Stack (actual)

**Backend** ✅
- Laravel **12**, PHP **8.3**
- MySQL (primary DB), Redis (`predis`) for cache / queues / slug map
- Elasticsearch **9.3** (`elasticsearch/elasticsearch`) — search + facets
- Spatie **MediaLibrary v11** — file storage (S3 / MinIO)
- Laravel **Sanctum v4** — token auth
- Apache Tika / Whisper — text & media extraction (queued jobs)
- Pattern: **Service + Repository** layered; policy-based authz resolved through
  `config/permissions.php` on two separate axes — platform administration
  (`users.is_superadmin`) and an organization role on the `organization_user`
  pivot (`owner` / `admin` / `editor` / `viewer`, ordered 100 / 75 / 50 / 25).
  See [Roles & Permissions](ROLES_AND_PERMISSIONS.md)
- IDs: UUID v7 for public entities (users, orgs, resources); auto-increment for
  private (workspaces, collections, categories)

**AI layer**
- MCP tool server (`org-mcp/`, `@tydal/org-mcp`) 🟡
- Embedding model (provider-pluggable) ✅ · vector store (ES, optionally
  pgvector later) 🔜

**Frontend** ✅
- React **18** + **Vite 6** + TypeScript **5.6**
- Material-UI **v6** (TYDAL theme: primary `#911A2C`, secondary `#214F61`),
  no inline styles — this is **one app's** style, not a global constraint (§3.5)
- **Shared client** `@tydal/client` ✅: framework-agnostic SDK; single transport
  layer for every TYDAL-operated surface (consolidates `frontend/src/api/*` and
  the `vaults/*` renderer apps — `org-mcp`/`vault-mcp` deliberately stay on
  plain `fetch` instead, see §3.5)
- Vault renderer apps ✅: independent apps per Vault mode — Gallery · Obsidian ·
  AI Chat — each with its own theme, all consuming `@tydal/client` (§3.5)

**Infrastructure**
- S3 / MinIO storage · Cloudflare/S3 CDN · Laravel queues (Redis) ·
  event system (`resource_events`)

---

## 11. 🚀 Roadmap: TYDAL → TYDAL v2

### Phase 1 — Stabilization (current system)
- Finalize the Resource **composition + file-role model** (`canonical` sole
  contributor / `component` equal `mean` / `supporting` pure ancillary, §3.2);
  move role/cardinality invariants into the backend service layer.
- Finalize `collection_schemes` as the schema source of truth.
- Stabilize ES index mappings and the file → resource mapping.

### Phase 2 — Vault Introduction (critical step)
- **Promote the CDN system to the Vault entity** (rename + extend, §3.3/§9,
  spec: `VAULT_SYSTEM.md`). ✅ Rename done 2026-07-03: `cdns→vaults`,
  `workspace_cdn→workspace_vault`, `cdn_links→vault_links` + code/API/consumers.
- Schema delta: `organization_id` (org-scoped slugs), `purpose` (default
  `delivery`), `state` (2026-07-24; `is_published` as originally shipped),
  per-vault opaque `hash`, nullable
  `workspace_id` + per-vault `slug` on links, `files.position`.
- Workspace-free vault link keys (`sha256(vault:resource:file)`) with
  vault-first resolution (`/h/{vaultHash}/{linkHash}`) + Redis cache.
- Human namespace `/v/{orgSlug}/{vaultSlug}/{resourceSlug}` with the tier-gated
  operation grammar (`/meta` · `/files` · `/chunks` · `/links` · …) and the
  ordered **manifest** for multi-file resources.
- Vault MCP server, read-only by default (verb = tier taxonomy, Vault-key / public access; supported writes require a write key).

### Phase 3 — Schema-Driven Indexing (v2 core)
- `VaultSchemaOverlay` engine.
- Per-vault Elasticsearch indexing.
- AI field-role mapping + `semantic_schema` refactor.

### Phase 4 — AI Layer (AITY + MCP)
- Implement MCP tools (§3.4).
- AITY reasoning engine + embedding retrieval layer + graph traversal API.
- **Clusters → auto-created Vaults** (§7): materialize discovered clusters as
  LLM-named cluster Vaults (Epic 4.5).

### Phase 5 — Experience Layer
- **Extract `@tydal/client`** — framework-agnostic SDK; migrate the existing
  `frontend` services and the MCP server onto it (kill the duplicate clients).
- Build the **app suite** — independent apps per Vault mode (Gallery · Obsidian ·
  AI), each with its own theme, all on `@tydal/client` (§3.5).
- Publish system.

### Phase 6 — TYDAL v2 Release
- Fully schema-driven semantic indexing system with AI-native Vault navigation.

---

## 12. 🧭 Key Design Principles

1. **Resource-centric** — everything is a Resource.
2. **Vault is a projection, not storage** — never duplicate data per Vault.
3. **Schema defines meaning, not just validation** — schemas are semantic contracts.
4. **AI access follows its interface** — organization agents use granted
   management permissions; Vault consumers use the content and operations
   exposed by that Vault, including binary content when permitted.
5. **Embeddings are signals, not structure** — the graph defines meaning,
   embeddings rank it.
6. **Consistent terminology** — Resource, Collection, Workspace and Vault
   retain the same meaning in engineering and product documentation (§2).

---

## 13. 🧠 Final Definition

> **TYDAL is the typed Digital Asset Layer:** an open-source Semantic Asset
> Platform that structures, enriches and shares digital resources through
> contextual Vaults for people, applications and AI agents.

---

*Supersedes the earlier short `tydal_v2_roadmap.md`. Phase breakdown lives in
[`ROADMAP_MILESTONES.md`](../planning/ROADMAP_MILESTONES.md); diagrams in
[`SYSTEM_DIAGRAM.md`](SYSTEM_DIAGRAM.md).*
