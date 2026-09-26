# 🔐 TYDAL Vault System — Specification

Canonical specification of the **Vault** entity, its addressing grammar,
projection rules, and MCP surface. Refines
[`ARCHITECTURE_AND_ROADMAP.md`](ARCHITECTURE_AND_ROADMAP.md) §3.3/§9 and drives
[Milestone 2](../planning/ROADMAP_MILESTONES.md). Status: **implemented (2026-07-03)** —
all four epics are done: the physical rename, the Epic 2.1 schema delta (§8),
the Epic 2.2 link engine (§4.1/§4.2), the Epic 2.3 human namespace + operation
grammar (§4.3/§5/§6), and the Epic 2.4 vault MCP server + VaultKey (§7,
`vault-mcp/`). Milestone 3 added the per-vault projected index and overlays;
Milestone 4 added the semantic surface (Epic 4.1, 2026-07-07): `mode=semantic`
on `/search` (k-NN over the resource/chunk embeddings) and the `/embed`
compute operation (`embed_query` tool) — the resource graph (Epic 4.3,
same day): `/related` now serves curated/tag/semantic relation edges,
projected into the vault, before falling back to tag overlap — and
auto-created cluster vaults (Epic 4.5): graph clusters materialize as
`purpose=mixed` vaults marked `generated_from='clusters'` (provenance is a
column, deliberately not a purpose — purpose stays the consumption promise),
LLM-named, slug/hash-stable across rebuilds via member-overlap matching.

---

## 1. Definition

A **Vault** is a *contextual projection* of Resources: a semantic view + policy
+ addressing configuration — **never a data copy**. It is the system's single
**external boundary**: everything outside TYDAL (a browser, a CDN consumer, a
customer's AI agent) reaches resources *through* a vault, and only sees what the
vault's purpose and policy expose.

> 🎯 **The Vault *is* the extended CDN entity.** The CDN already implements the
> projection facet — scoping (`workspace_vault`), delivery policy, and a
> deterministic per-resource link map (`vault_links`). v2 does **not** add a
> vault mode to the CDN; it makes the classic CDN **one purpose of Vault**. One
> entity, no parallel pivot/link tables.

```
vaults.purpose ENUM('delivery', 'gallery', 'obsidian', 'ai', 'mixed')  DEFAULT 'delivery'
```

- `purpose = 'delivery'` — exactly today's CDN. Existing rows backfill with the
  default; nothing changes for them.
- Any other purpose — a Vault proper. **Converting a CDN into a Vault is an
  `UPDATE` of `purpose`** (Admin-only). Changing purpose is a *hash-domain
  change* (§4), so it purges and regenerates links — same path as the existing
  salt rotation (`purgeLinksForVault`).
- `state` — how open the boundary is: `disabled` (off — every address 404s,
  keys and grants included), `private` (live, but a vault key or signed grant
  is required, §7), `public` (the address alone is enough). Replaces the old
  `is_active` + `is_published` pair, which was never orthogonal: the policy
  gate short-circuits on activity, so "inactive but published" could not be
  observed.

## 2. Tenancy & naming

Vaults are **organization-scoped** (new: `organization_id` on the entity —
`vaults` has none today, a missing tenancy boundary this migration closes).

- **Slug uniqueness is per-organization**: the global `vaults_slug_unique` becomes
  `UNIQUE (organization_id, slug)`. Human URLs therefore carry the org slug (§4).
- Every vault keeps the **machine/human name pair**: `slug` (machine — routing,
  AI, indexing) + `name` (human — display). LLM auto-naming for auto-created
  cluster vaults lands in Epic 4.5.
- Every vault additionally gets its own **short opaque `hash`** (globally
  unique, generated at creation) — the machine-facing identifier used in hash
  URLs, revealing nothing about org or content.
- The **`organization_id` defaults from the caller's org context** (the org
  selected in the header, via `currentOrganizationId()`), so the create UI
  never asks for it; an explicit value is still honored for API callers, and it
  is **immutable after creation** (the tenancy boundary is set once).
- The **`salt`** is a cryptographic secret — it seeds every link hash (§4) and
  grant signature (§6.4). It is **generated at creation** (`Vault::creating`,
  32 chars), never human-picked, and rotated only via the rotate-salt action
  (§6.4), never by typing a new value.

> ⚠️ Migration note: existing CDN rows backfill `organization_id` from their
> attached workspaces' org. `has_public_workspace` CDNs currently resolve the
> default workspace from the *resource's* org at link time — i.e. a public CDN
> can serve resources **across orgs** today. Org pinning closes that hole; a
> cross-org showcase, if ever wanted, must be an explicit platform-level vault
> type, not a schema accident.

### 2.1 Where the org boundary is enforced (2026-07-24)

Membership is a chain — resource → workspace → vault — and each edge is pinned
to one organization on the way **in**, then pinned again on the way **out**, so
a bad row can never become a cross-org projection:

| Edge / gate | Enforcement |
|---|---|
| workspace → vault (`POST /workspaces/{id}/vaults`) | 422 on org mismatch |
| resource → workspace (`POST /workspaces/{id}/resources`) | 422 on org mismatch |
| projection query (`vaultResourceQuery`) | `organization_id = vault.organization_id` on **both** branches, not just `has_public_workspace` |
| address resolution (`validateLink`) | same pin, on the vault-purpose and delivery branches alike |
| `buildDeliveryPairs` | `has_public_workspace` delivery vaults are selected within the resource's own org |
| vault listing (`GET /vaults`) | org-scoped, and never selects `salt` |
| `GET /resources/{id}/vault-links` | 404 unless the caller can access the resource's org — link hashes are public addresses, so minting them is an org-member action, not merely an authenticated one |

The last two closed real leaks: the listing exposed every org's vaults *with
their salt*, and the links endpoint would mint and reveal any resource's public
addresses to any logged-in user, including one belonging to no organization.

## 3. Membership & permissions

Resources enter a vault **via workspaces** — the existing `workspace_vault`
pivot, or the org's default workspace for
`has_public_workspace` vaults. No new membership entity.

| Action | Who | Why |
|---|---|---|
| Create vault, set purpose, publish, rotate salt/keys, attach/detach workspaces | **Admin (75)+** | changes what the outside world can reach |
| Populate a vault (add resources to an attached workspace) | **Editor (50)+** | ordinary curation; no new permission surface |

A direct `resource_vault` cherry-pick pivot is **deliberately deferred** — a
dedicated single-purpose workspace covers that case. If added later it becomes
an *additional* membership source, not a replacement.

## 4. Addressing

Two address forms resolve to the same `(vault, resource, file?)` triple; after
resolution **one shared operation grammar** applies (§5).

```
Machine (opaque):  /h/{vaultHash}/{linkHash}[/{operation}]
Human (semantic):  /v/{orgSlug}/{vaultSlug}[/{resourceSlug}[/{fileSlug}]][/{operation}]
```

**Humans navigate the hierarchy** (org → vault → resource → file, all slugs);
**machines jump directly** (two opaque tokens; neither org nor content names
leak).

### 4.1 Hash resolution — vault first

1. Resolve `vaultHash` → vault (one lookup, Redis-cached). Policy, purpose,
   `state`, IP allowlist are known **before** any link
   query — a disabled vault short-circuits immediately.
2. Resolve `linkHash` **scoped to that vault**: `WHERE vault_id = ? AND hash = ?`.

This fixes a latent defect: the current resolver does a *global*
`WHERE hash = ?`, but each CDN mints 8-char Hashids from its **own salt** over
small auto-increment IDs — nothing structurally prevents cross-CDN collisions.
Vault-scoped lookup makes link hashes only need per-vault uniqueness, which the
salt already guarantees. The flat `/vault/{hash}` route (renamed from
`/cdn/{hash}` — no legacy path kept, TYDAL is pre-release with no minted URLs
in the wild) keeps serving `delivery` links; vault purposes are born on `/h/`.

### 4.2 Link keys — purpose decides whether the workspace is in the key

| purpose | `link_key` | resolution check |
|---|---|---|
| `delivery` | `sha256(vault : workspace : resource : file)` — **unchanged** | resource still in *that* workspace; workspace still linked to CDN (current logic) |
| vault purposes | `sha256(vault : resource : file)` | resource still in **any** workspace linked to the vault (or vault `has_public_workspace`) |

A vault is *one* stable projection: the same resource reached via two
workspaces must have **one** address, so the workspace leaves the key.
`vault_links.workspace_id` becomes **nullable** (`NULL` = vault link). Revocation
stays projection-pure: removing a resource from the last vault-linked workspace
silently kills its addresses — no cleanup jobs.

### 4.3 Resource & file slugs

`vault_links` carries a `slug` generated from the resource name (resource
links) or the filename (file links) — LLM-polished later, per the naming
canon: slug = machine, name = human. This is the "Vault Indexing / Slug Map"
of the architecture diagram — the human address form of the same link row the
hash addresses.

**Scoping follows the hierarchy** (settled 2026-07-03): resource slugs are
unique per **vault**; file slugs are unique per **resource** (siblings only).
So `album-one/cover` and `album-two/cover` coexist, and a file may carry its
resource's name (`/doc/doc`). MySQL has no partial indexes, so each rule is
enforced through a conditional generated column
(`resource_slug_key`/`file_slug_key`) with its own unique index. Grammar
words (§5 operations) are reserved in both scopes.

## 5. Operation grammar

Extends the pattern the CDN already started with `/info` and `/download`. Every
operation is **tier-gated** (§6.2) by the vault's policy, so the same grammar
serves a public gallery and a keyed RAG vault.

| Resolves to | default `GET` returns | operations |
|---|---|---|
| **vault** | index (Tier 0 listing) | `/meta` · `/tags` · `/resources` (paged) · `/search?q=` · `/embed?q=` |
| **resource** | binary if exactly one exposed file, else **manifest** | `/meta` · `/tags` · `/files` · `/chunks` · `/links` · `/related` · `/preview` |
| **file** | binary (if Tier 2 allows) | `/meta` · `/chunks` · `/download` · `/renditions` |

Resource cards advertise `preview_renditions` entries for the original preview,
already-generated media conversions, and on-demand `ai-prepared` for JPEG, PNG,
WebP and GIF sources. These refer to the resource's designated snapshot (or its
rendered system preview), not an arbitrary exposed file. All URLs remain inside
`/h/{vaultHash}/{resourceHash}/preview`; clients select an advertised URL rather
than construct a storage address. Entries have `name` and `url`; `ai-prepared`
also includes `on_demand: true` and `max_bytes: 5242880` (the default limit).

`/preview?rendition={name}` streams the selected version inline under the same
vault, resource-membership and binary-tier checks as `/preview`. It does not
require `is_downloadable`. Unknown, ungenerated or missing conversions return
404 instead of silently returning a large original. Omitting `rendition`, or
selecting `original`, returns the unchanged designated snapshot/rendered preview.
Rendition entries are empty when the binary tier is denied.

Image conversion presets are `thumbnail` (up to 200×200), `small` (400 px wide),
`medium` (800 px wide), and `large` (1600 px wide), in WebP. Only completed
conversions are advertised; clients must handle absent sizes. Generated system
previews can offer `ai-prepared` even when no media conversions exist.

For AI vision, request the advertised `ai-prepared` URL, optionally adding
`&max_bytes=5000000`. The default is **5 MiB (5,242,880 bytes)**; integer limits
from 65,536 to 20,971,520 bytes are accepted. This measures the binary image,
**before base64 encoding** (which adds roughly one third), not the complete
provider request. Clients choose a budget suitable for their provider. `max_bytes`
is only valid with `ai-prepared`; invalid budgets or unsupported/undecodable
images return JSON 422. There is no silent fallback to an oversized original.

Preparation reuses AITY's `VisionImagePreparer`: fitting bytes are preserved
(except PNG orientation normalization); otherwise JPEG quality 95 is attempted
at full resolution, then dimensions decrease gradually only as needed to fit.
JPEG EXIF orientation is applied before re-encoding; PNG orientation is also
normalized. Transparency is composited onto white when converting to JPEG;
animated sources are reduced to a still frame when conversion is needed.
The original is never replaced. Preparation is in memory, on demand, with
`Cache-Control: private, no-store`; persistent rendition caching is deferred.

Preview responses identify the actual rendition in `X-Tydal-Image-Rendition`,
format in `Content-Type`, and byte size in `Content-Length`. AI-prepared responses
also report `X-Tydal-Image-Width`, `X-Tydal-Image-Height`, and
`X-Tydal-Image-Max-Bytes`. Ordinary previews report encoded pixel dimensions when
a bounded header read can determine them; those optional headers can be absent.
The vault MCP's `read_image` defaults to `ai-prepared` and returns image content
plus JSON metadata (`rendition`, `mime_type`, `bytes`, `width`, `height`, and the
AI byte limit). Missing dimensions are reported as `null`.

- `/search` takes `mode=keyword` (default, literal) or `mode=semantic` (k-NN
  over the resource mean embeddings; with `scope=chunks`, over the chunk
  vectors). Semantic needs the per-vault index (resources) and a live
  embedder; when either is missing it degrades to keyword and the payload's
  `mode` reports what actually answered.
- `/embed?q=` is pure **compute**: it returns the query's vector in the
  vault's own embedding space (`{model, dimensions, vector}`) so a consumer
  AI can run its own similarity math. No vault data leaves — gated only by
  vault resolution, 503 when the embedder is down.

- **Manifest rule:** a resource-level address never makes the consumer think
  about files. One exposed file → the binary directly. Multiple exposed files →
  an **ordered manifest** (shared metadata + per-file addresses). Ordering
  requires a `position` on `files` (or reuse of media `order_column` via
  `media_id`) — tracks 1–12, letters in sequence.
- `/links` is what turns metadata-first consumption into binary access: it
  returns minted hash URLs, never bytes.
- `/related` rides the **resource graph** (Epic 4.3) when the resource has
  edges — heaviest first, each hit carrying `relation {type, origin, weight}`
  — always **projected into this vault** (a neighbor outside the vault's
  resource set never leaks). No edges yet → shared-tag heuristic; the payload's
  `source` (`graph` | `tags`) says which answered. Vault membership itself is
  never a stored edge: it *is* the projection.

**Identity is vault-scoped.** Every card's `id` is the resource's **link hash**
(the `{linkHash}` token in `/h/{vaultHash}/{linkHash}`), **never the internal
resource UUID**. The boundary anonymizes: a leaked UUID would let a consumer
correlate the same resource across vaults or probe the private API. The same
vault-scoped id is used by graph edges (`source`/`target`), ask citations
(`resource_id`), and the projected search index (the ES document's in-body
`id`; its `_id` stays the UUID for upkeep). It re-randomizes on salt rotation.
The legacy `/info` alias (from the CDN era) is boundary-safe on the same terms —
link-hash ids, vault-scoped file URLs, never raw storage URLs. All vault JSON is
emitted with unescaped slashes and unicode (`JSON_UNESCAPED_SLASHES|UNICODE`) —
valid JSON either way, cleaner for a URL-heavy, human-facing surface.

## 6. Projection: file roles & consumption tiers

### 6.1 Roles → exposure (two independent filters)

File **roles** (§3.2 of the architecture doc: `canonical` sole metadata
contributor · `component` equal contributors · `supporting` pure ancillary) are
resource-level truth about *metadata contribution*. The vault adds an
orthogonal axis — **exposure** — with two independent role filters:

| Filter | Governs | Default |
|---|---|---|
| **address exposure** (Tier 2) | which roles get minted links / appear in manifests | `canonical` + `component`; `supporting` hidden |
| **chunk contribution** (Tier 1) | which roles feed `read_chunks` / `/chunks` | all text-bearing roles, **including `supporting`**, in `ai` vaults |

Canonical examples (consistent with §3.2 / Epic 1.1):

- **CD** (canonical + supporting): tracklist file = `canonical` (drives
  metadata: title, composer, track count); audio tracks, lyrics, cover =
  `supporting`. In a `delivery`/`gallery` vault the tracks are addressable
  extras (manifest); in an `ai` vault their binaries stay unaddressed but the
  **lyrics' text feeds Tier 1 chunks** — this activates, *per vault*, the
  "supporting content searchable" behavior §3.2 reserves for future use.
  Chunk exposure is a projection knob only: it does **not** change resource
  indexing/embedding (where `supporting` still contributes nothing).
- **Letters bundle** (multi-component): each letter = `component`; the resource
  is the correspondence as a whole. Resource address → ordered manifest; every
  letter contributes chunks and metadata equally.

Compositions are **emergent from per-file roles and mixes are normal** —
"canonical+supporting" and "multi-component" are not resource types.

### 6.2 Consumption tiers (progressive disclosure)

The access-control core for AI consumers. The AI consumes *curation first*:

- **Tier 0 — Identity (always):** name, description, tags, categories,
  mimetype, facet values. What search results and the vault index are made of.
- **Tier 1 — Content (chunks):** `file_chunks` rows, addressed **through the
  resource** (resource address + `sequence`/`page_number` range) — chunks are
  never separately hashed or slugged, keeping the link table small and
  revocation at the resource level.
- **Tier 2 — Binary (on demand):** the hash URL, handed out only when
  explicitly requested (`/links`, `link_*`), and only if the vault allows it.

The RAG loop falls out: `search_*` (Tier 0) → `read_chunks` (Tier 1 evidence)
→ `link_*` (Tier 2) only if the task truly needs the artifact.

### 6.3 Purpose presets and the capability matrix

Purpose = a preset over the exposure knobs; Admin can override any of them.

| Purpose | Tier 0 | Chunks (T1) | Binary (T2) | Files exposed | Auth default |
|---|---|---|---|---|---|
| `delivery` | minimal (`/info`) | no | yes — that's the point | current behavior | hash is the credential |
| `gallery` | yes (captions via overlay, M3) | no | renditions; original iff `is_downloadable` | canonical + components (images) | public when published |
| `ai` (RAG) | yes | **yes** (incl. supporting text) | link on request, off by default | text-bearing roles + transcripts | vault key |
| `obsidian` | yes + relations graph | yes | link on request | canonical | key or published |
| `mixed` | union — explicit config | — | — | — | — |

**The presets are not a ladder.** `gallery` is Tier 0 + Tier 2 (binary, no
chunks); `ai` is Tier 0 + Tier 1 (chunks, binary *off* by default, so a vault
key cannot be farmed for bytes). Any UI that presents these as cumulative
levels — "links, plus metadata, plus binary" — misrepresents the model, because
the top rung would force binary on for the one purpose that deliberately denies
it. Writes are not a ladder either: `gallery` → activate/open/close and every content-taking purpose →
ingest/update/withdraw are vocabularies, not accumulating tiers.

#### The capability vocabulary

`vaults.exposure_policy` is a **closed, validated document** — `VaultCapability`
declares the legal keys and their types, and the form requests reject anything
else. (Before it was declared, a misspelt key was accepted and then ignored,
leaving the vault silently on its preset with no signal.) A key that is absent
*or null* falls back to the preset, which is what makes "reset to preset" a
natural gesture in the admin UI.

| Key | Type | Preset source | What it changes |
|---|---|---|---|
| `allow_chunks` | level | per purpose | Tier 1 — may consumers read chunk text |
| `allow_binary` | level | per purpose | Tier 2 — may consumers reach binaries |
| `allow_ask` | level | per purpose | whether `/ask` answers at the boundary |
| `address_roles` | `FileRole[]` | canonical + component | which file roles get minted addresses |
| `chunk_roles` | `FileRole[]` | +supporting on `ai` | which file roles feed Tier 1 chunks |
| `write_methods` | `string[]` | `VaultPurpose::writeMethods()` | widen the accepted write ops |
| `ingest` | `{workspace_id, collection_id}` | none | the `ai` purpose's landing target |
| `rag_min_score` | 0–1 | instance default | relevance floor for this vault's `/ask` |

`rag_min_score` never affects raw retrieval (`/search?scope=chunks` serves every
scored hit; consumers apply their own cutoff). Internal twin:
`organizations.settings.aity.rag_min_score`.

#### Access levels — openness per capability

The three gated capabilities take an **access level**, not just on/off, because
openness used to be vault-wide: `state` decided the whole boundary, so "list
resources publicly but keep the binaries behind a key" could not be said about a
single vault.

| Level | Meaning | Legacy form |
|---|---|---|
| `denied` | never answers, whatever credential is presented | `false` |
| `key` | requires a vault key **even while the vault is `public`** | — |
| `inherit` | follows `state` — open when public, key when private | `true` |

The booleans remain valid on the wire and coerce (`true` → inherit, `false` →
denied), so policies written before levels keep resolving identically.

A level can only ever **narrow** what `state` already permits, never widen it —
effective access is the stricter of the two. A signed grant does *not* satisfy
`key`: a grant is the time-limited publish form, so honouring it there would let
a share link reopen exactly what the level exists to close.

The check needs to know how the caller got in, so `VaultLinkService` records the
credential (`open` | `key` | `grant`) on the resolved vault instance and
`Vault::allowsBinary()` and friends consult it. A vault loaded *outside* the
boundary — admin API, jobs — carries no credential and is treated as `open`,
which is right: those callers are already authorized by org membership.

Because the answer now depends on the caller, `/meta`'s `tiers` block is
**per-request**: the same public vault reports `tiers.binary: false` to a
keyless agent and `true` to one holding a key. That is the intended reading of
self-description — it tells an agent what *it* may do, so a well-behaved model
still never has to probe.

Resolution lives in one place — `App\Values\VaultPolicy`, which holds the preset
table and overlays the vault's overrides. `Vault::allowsChunks()` and friends
read through it, so the matrix has exactly one definition. `/meta` publishes it
as a `capabilities` block with per-key provenance (`preset` | `override`)
alongside the unchanged `tiers` block, and the admin UI renders the same matrix
from `GET /platform/vaults/capabilities`.

**Which changes cost what.** Purpose and salt are hash-domain changes: both
purge every minted link. A capability change is not — links a consumer already
holds stay valid. The exception is the two role filters (`address_roles`,
`chunk_roles`): they decide what the projected index contains, so they trigger a
rebuild without a purge.

#### Recipe: an `ai` vault that hands out pixels

The `ai` preset denies Tier 2 so a vault key cannot be used to bulk-download the
corpus. A *transforming* AI consumer — one that reads source images, derives
something, and writes the result back through `ingest` — needs the bytes:

```jsonc
// vaults.exposure_policy
{
  "allow_binary": true,                                  // override the ai preset
  "ingest": { "workspace_id": 12, "collection_id": 5 }   // where output lands
}
```

This is the ImageLab shape. It is a deliberate widening of the preset, not a
misconfiguration — the vault still denies everything else `ai` denies, and the
`w:ingest` ability is still required to write. Prefer it over inventing a new
purpose: purpose is the consumption promise, and this vault is still consumed
as an `ai` vault.

### 6.4 Access forms: published · keys · signed grants (Epic 5.4)

Three ways through the boundary, checked in `passesVaultPolicy` (active + IP
allowlist are never bypassed by any credential):

1. **`state = public`** — the standing public form; anyone may address the vault.
2. **`VaultKey`** (`X-Vault-Key: tvk_…`) — a standing private credential,
   individually revocable.
3. **Signed grant** (`?sig=&exp=`) — the *time-limited publish* form: an
   HMAC-SHA256 over `tydal-grant:v2:{vaultHash}:{scope}:{exp}:{grant_epoch}`
   keyed by the **vault salt**, so nothing is stored server-side. Scope `*`
   opens the whole vault surface (both address forms); a link hash opens
   exactly that address — and a **resource** grant also covers the resource's
   own file addresses, so the shared thing renders whole. Minted by
   `POST /platform/vaults/{id}/signed-urls` `{expires_in_hours, link_hash?}`;
   the vault apps accept `?sig=&exp=` and thread it through every request
   (`@tydal/client`'s `grant` config).

**Revocation — three levers, weakest to strongest** (grants and links are
otherwise stateless: nothing to list or delete individually). The key insight is
that *what cancels a link is deleting its row*, not changing the salt — a
regenerated link gets a new primary key and therefore a new hash even under the
same salt. So the secret only truly needs to change if the **secret itself**
leaked:

1. **Revoke grants** — bump `grant_epoch` (`POST /platform/vaults/{id}/revoke-grants`).
   Every outstanding grant's HMAC stops verifying; **every link stays intact**.
   The everyday "un-share the grants" button.
2. **Cancel all links** — purge the link rows (`purgeLinksForVault`). Every
   shared `/v`, `/h`, and delivery URL 404s; new addresses regenerate lazily.
   Vault-wide grants *survive* (they name no link); link-scoped grants die with
   their link. *(Today this is bundled into salt rotation; a standalone action
   is a natural future split.)*
3. **Rotate salt** — the full reset (`POST /platform/vaults/{id}/rotate-salt`):
   a fresh generated secret **and** a link purge, so all links *and* all grants
   die. Reserve it for a genuinely compromised salt, not routine un-sharing.

Salt rotation and purpose change are the two *hash-domain changes* (§1/§4) that
purge links; the salt is generated, never entered (§2).

### 6.5 Write methods — the inbound boundary (2026-07-24)

The boundary is no longer read-only. A vault also accepts a small, **purpose-
defined** set of write methods, gated by a **write-capable vault key** — the
symmetric counterpart to the read tiers. Full spec: [[VAULT_WRITE_METHODS.md]].

- **Keys carry abilities.** `vault_keys.abilities` is `["read"]` for a read key
  (the read gate requires it) or `["w:activate", …]` / `["w:ingest"]` for a write
  key. A key authorizes exactly the methods it lists.
- **Purpose defines the verbs.** `VaultPurpose::writeMethods()`, mirroring
  `allowsChunks/allowsBinary/allowsAsk`:
  - `gallery` → `activate` (declaratively set the projected works), `open`/`close`
    (publish / un-publish + restore the pre-selection projection).
  - `ai` → `ingest` (2026-07-25): a derived image + a JSON descriptor document →
    one output resource (canonical `descriptor.json` + a `component` translated
    image) in the vault's configured `exposure_policy.ingest` target. This is the
    first op to carry **binary** (multipart). Powers the image-processing product
    ([[imagelab-ai-ingest]]).
- **Op + document model.** The op is the unit of permission/audit/discovery; its
  payload is a declarative document the purpose's writer maps. `@tydal/client`
  exposes a single generic `write(op, payload)` (multipart when the document
  holds a `Blob`/`File`) — **no** per-purpose namespaces; behaviors grow
  server-side.
- **Endpoint & gate.** `POST /h/{vaultHash}/w/{method}` and its human twin
  `POST /v/{orgSlug}/{vaultSlug}/w/{method}` (CSRF-exempt, like `/ask`), gated by
  a dedicated `authorizeWrite` — **not** the read policy, since writes must reach
  a *private* vault (that is what `open` is for). It requires: reachable vault,
  method ∈ purpose, key carries `w:{method}`, IP allowlist. Refusals are the same
  silent 404/403.
- **Org-pinned & audited.** `activate` resolves link hashes to resources scoped
  to the vault; the serve-time org pin (§2.1) makes cross-org projection
  structurally impossible. Every accepted call writes a `vault_writes` row.

This replaces the org-admin service token Full Frame used for the opening: the
opening is now `activate` + `open` on the vault's own write key — one vault, no
org token, no management API.

#### The legacy flat address and the publish gate (2026-07-24)

`/vault/{hash}` predates `is_published`: for a `delivery` vault the hash **is**
the whole credential, and existing CDN URLs must keep working. It used to skip
the publish gate for *every* purpose, so a `private` gallery's works were one
flat URL away from anyone who had seen a link hash — the pre-opening privacy
model of an exhibition, bypassed.

`resolveHash()` now applies the gate to the projection purposes and threads the
key/grant through, while `delivery` keeps its CDN semantics. Nothing TYDAL
emits changes shape: `buildUrl()` only ever advertises `/vault/{hash}` for
delivery vaults, everything else gets `/h/{vaultHash}/{linkHash}`.

> The public link routes carry **no rate limit** (only `/ask` is throttled).
> For a `public` vault that is the design — the URL is the credential. For a
> `private` one the key/grant now protects it. A throttle on
> `/h/{vaultHash}/{linkHash}` would still be worth adding: link hashes are
> 8-char Hashids over sequential ids, which is obscure, not unguessable.

## 7. MCP surface

A vault-scoped MCP server — the machine face of the same boundary,
**read-only by default**. Connections are scoped to **one vault** (private:
vault key; published: vault slug/hash, keyless), so tool names carry no vault
noun. Supply `TYDAL_VAULT_WRITE_KEY` and the vault's purpose-exposed write ops
appear as tools too — `ingest` on an `ai` vault (§6.5) — so **any** MCP-capable
AI can read, transform, and write back through one connection (bring-your-own-AI
image processing); omit it and the write tools are not even listed.

The existing `@tydal/org-mcp` (upload, tag sync, workspace writes, and — Epic 4.4 —
`ask_vault`, AITY's vault-scoped RAG) remains the *org-wide management surface*;
the two are distinct servers, not modes — the vault MCP's write ops are the small
purpose-gated set, not general CRUD. Reasoning lives ONLY on the org-mcp side:
AITY's loop retrieves through this same operation grammar (scope and tier policy
enforced by the boundary itself), while the vault-scoped server stays data + compute
+ the purpose's write ops.

### 7.1 Verb taxonomy — the verb *is* the tier

| Verb | Meaning | Tier | Tools |
|---|---|---|---|
| `get_` | one entity's identity card | 0 | `get_vault` · `get_resource` · `get_file` |
| `list_` | enumerate children, paged, identity-only | 0 | `list_resources` · `list_files` · `list_related` · `list_chunks` (index only) |
| `search_` | ranked retrieval (keyword + semantic `mode`) | 0 | `search_resources` · `search_chunks` |
| `read_` | actual content payload | 1 | `read_chunks` (resource + sequence/page range) |
| `resolve_` | address ↔ entity translation | 0 | `resolve` (accepts slug or hash) |
| `embed_` | compute — query → vector, no data out | 0 | `embed_query` |
| `link_` | mint Tier 2 URLs — **never streams binary** | 2 | `link_resource` · `link_file` |

- `get_vault` doubles as **self-description**: purpose, exposure policy,
  available facets, `search_modes` — an agent discovers what it may do
  before trying.
- The diagram's camelCase names map 1:1 (`searchVault → search_resources`,
  `resolveSlug → resolve`, `getRelated → list_related`,
  `embedQuery → embed_query`). `summarizeVault` is deliberately **not**
  built: summarizing is reasoning, and reasoning belongs to the consumer's
  AI — TYDAL exposes data and compute tools only (§10).

### 7.2 REST ↔ MCP invariant

Every operation exists in both surfaces, 1:1, and the verb alone tells the
policy engine which tier to check:

| REST operation | MCP tool |
|---|---|
| `/meta` | `get_*` |
| `/resources`, `/files`, `/related` | `list_*` |
| `/search` | `search_*` |
| `/embed` | `embed_query` |
| `/chunks` | `read_chunks` (`list_chunks` for the index) |
| `/links` | `link_*` |
| slug/hash path itself | `resolve` |
| `POST /w/{method}` | write ops — `ingest` (only with a write key, §6.5) |

## 8. Schema delta

| Table | Change |
|---|---|
| `vaults` | ✅ 2026-07-03: + `organization_id` FK (NOT NULL, backfilled) · + `purpose` enum (default `delivery`) · + `is_published` bool · + `hash` (12-char opaque, globally unique) · + `exposure_policy` JSON · slug unique became `(organization_id, slug)` · ✅ 2026-07-24: `is_active` + `is_published` collapsed into `state` (`disabled\|private\|public`) · + `selection_snapshot` JSON (gallery `close` restore, §6.5) |
| `vault_links` | ✅ 2026-07-03: `workspace_id` **nullable** (NULL = vault link) · + `slug` (unique per vault) · purpose-aware `link_key` (§4.2) |
| `vault_keys` | ✅ 2026-07-24: + `abilities` JSON (default `["read"]`; write keys list `w:{method}`, §6.5) |
| `vault_writes` | ✅ 2026-07-24: **new** — append-only audit of accepted boundary writes (`vault_id`, `vault_key_id`, `method`, `summary`, `client_ip`) |
| `workspace_vault` | ✅ rename only (2026-07-03) |
| `files` | ✅ 2026-07-03: + `position` (component/manifest ordering) |

## 9. Lessons from the abandoned first attempt (`tydal.boveda/`)

A first implementation (the sibling `tydal.boveda/` tree, git "refactor III–V")
took the **rename-in-place** route: `cdns → vaults` as a hard rename with no
CDN concept left. It was **abandoned** after continuous test/deploy friction —
the big-bang rename made every consumer (backend, frontend, MCP, seeds, CI)
move at once. **This spec supersedes it.** The decisive correction: the CDN
concept is not renamed away, it is **subsumed** — `purpose = 'delivery'` keeps
the entire existing behavior surface while vault purposes grow *beside* it, so
each Milestone 2 epic ships independently against a green suite. The *physical*
rename itself was executed 2026-07-03 — but as one isolated, purely mechanical
change (zero behavior delta, suite green before and after), which is exactly
the isolation boveda never had. Being pre-release with no deployed users, the
rename was folded directly into the consolidated initial migration and no
legacy `/cdn/` surface was kept (public links now mint as `/vault/{hash}`).

The attempt still validated several ideas this spec keeps (independently
converged): org-scoped slugs with the org in the human URL · workspace dropped
from the vault link key · per-vault resource `slug` on links · vault-first
hash resolution · both-tier auth (published public / private key, a `VaultKey`
entity) · read-only consumer surface.

Ideas from the attempt worth **conscious consideration** during implementation
(options, not decisions):
- **Deterministic link hash** — `hash(vault:resource:file)` (no PK, no salt)
  instead of Hashids-over-PK; makes hashes reproducible and removes the
  post-insert update. Weigh against the salt's rotation-as-revocation semantics.
- **the publish gate covering only the named/human routes**, with the opaque hash
  itself acting as the secret for machine routes.
- Slug follows a resource rename while the hash stays stable (needs an
  old-slug redirect, which the attempt never built).

Traps to avoid (why it failed): big-bang rename of tables/models/routes/
consumers in one motion; editing the consolidated migration in place
(`migrate:fresh` required at every step, killing test/deploy stability); no
back-compat surface during the transition.

## 10. Design invariants

1. **Vault is a projection, never a copy** — membership is derived, links are
   minted lazily, revocation is implicit.
2. **The resource is the addressable unit** — files appear only through role
   composition (manifest); chunks only through their resource.
3. **The system knows where it is before what it serves** — vault resolution
   precedes link resolution; policy applies before content.
4. **Curation before content, content before bytes** — Tier 0 → 1 → 2, and the
   MCP verb names the tier.
5. **One grammar, two address forms** — slug for humans (hierarchical), hash
   for machines (opaque); identical operations after resolution.
6. **TYDAL exposes data; it does not reason** — the vault MCP is read-only by
   default, and where a purpose exposes a write op it is a narrow, key-gated,
   document-mapped verb (`ingest`), never general CRUD or reasoning. The customer
   plugs their own AI against it — reads through the tiers, writes back through
   the op.
