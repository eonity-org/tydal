# 🧱 Resource Composition Model

The settled three-role model (Milestone 1, Epic 1.1) — how files compose a
resource and what each role contributes. Enforced in the service layer
(`ResourceService::applyFileRoleInvariants`), audited by
`php artisan resources:audit-roles`.

## Roles

| Role | Meaning | Metadata / text | Chunks (k-NN) | Vault addressing |
|---|---|---|---|---|
| `canonical` | THE authoritative file — sole source of truth | **sole contributor** | yes | addressed by default |
| `component` | equal parts of a whole — each represents the entire resource | **all contribute equally** | yes | addressed by default |
| `supporting` | pure ancillary — attachment / alternate representation | **never** | yes (searchable, see below) | unaddressed by default |

Two legal compositions, **emergent from per-file roles**:
- **canonical + supporting** — a CD: tracklist file = canonical (drives title,
  composer, track count); audio tracks, lyrics, cover = supporting.
- **multi-component** — a letters bundle: each letter = component; the
  resource is the correspondence as a whole. *A multi-component resource is
  everything representing the resource* — every component speaks for the
  whole, equally.

Mixing `component` beside a `canonical` is invalid — uploading a canonical
demotes existing components to supporting; uploading a component is rejected
while a canonical exists. The preview/snapshot flag (`usage: ["snapshot"]`)
is **orthogonal** to roles: any file can carry it, at most one does.

## Contribution semantics

### Metadata promotion (`promoted_file_metadata`)

`ResourceService::recalculatePromotedMetadata()`:
- canonical present → its Tika metadata alone.
- no canonical → **all active components**, merged in manifest order
  (`files.position` ascending, nulls last, then upload order). On key
  conflicts **the first contributor wins** (Decision A, 2026-07-03) — the
  manifest order is the authority. Within one file, later extractions refine
  earlier ones.
- supporting files never contribute; no contributors → `null`.

### Indexed text (`extracted_text` in the resource ES document)

Same contributor rule; component texts are concatenated in manifest order, so
a multi-component resource reads as one continuous document.

### Resource embedding (`resources.embedding`, §8 of the architecture doc)

The resource's canonical semantic vector = the **element-wise mean** of its
contributing files' chunk vectors (`mean` aggregation) — canonical's chunks
alone, or all components' chunks equally. Recomputed after every
`EmbedFileChunks` run and on canonical promotion; persisted in MySQL and
carried on the resource ES document as a `dense_vector` (`embedding`), so it
survives reindexing. Supporting chunks never shape it.

### Supporting chunks stay searchable (Decision B, 2026-07-03)

Supporting files' chunks **are** extracted and embedded — they remain k-NN
searchable in the internal catalogue (trusted curators), and they power the
per-vault `chunk_roles` projection (`ai` vaults exposing lyrics/transcripts,
VAULT_SYSTEM.md §6.1). This is deliberate: exclusion of `supporting` is a
**resource-level** rule (metadata, indexed text, embedding), not a chunk
ingestion rule.

## Invariants (service layer)

1. At most one active canonical per resource (newest wins on conflict).
2. No active component beside an active canonical.
3. Canonical files carry no `relation`.
4. At most one snapshot flag.

`resources:audit-roles` reports violations (exit 1); `--fix` applies the
demotion rules above. Resources with zero active files are legal
(metadata-only resources).

## Lifecycle state (`resources.state`, 2026-07-24)

One field answers "is this thing real, and should anyone see it?":

| State | Listed / searched / projected | Addressable at a vault | Prunable |
|---|---|---|---|
| `draft` | no | no | yes — `resources:purge-drafts` |
| `live` | yes | yes | no |
| `archived` | no | no | no |

It replaces three overlapping fields:

- `active` (bool) — filtered in the vault projection but **not** in link
  validation, so deactivating a resource removed it from listings while its
  public URL kept serving;
- `visibility` (5-value enum) — only `draft` was ever enforced, and only in the
  internal catalogue, so a draft sitting in a vault-linked workspace was
  projected publicly. `private`/`organization`/`workspace`/`public` were never
  branched on anywhere;
- `published_at` (timestamp) — no code path read it.

Two fields meaning "not visible", filtered in different places, is why neither
was applied consistently. With one field there is one filter
(`Resource::scopeVisible()` / `ResourceState::isVisible()`), applied at both
the catalogue and the boundary.

> What this deliberately does **not** model is whether the outside world can
> reach the resource. One resource is projected through many vaults, each with
> its own openness — the same photograph is private through a jury's vault and
> public through the opened exhibition's, with nothing about the photograph
> changing. That answer belongs to the projection: see `VaultState` in
> [`VAULT_SYSTEM.md`](VAULT_SYSTEM.md) §1.
