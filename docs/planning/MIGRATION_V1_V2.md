# TYDAL v1 → v2 — Concepts & Migration

**What changed, how the old model maps to the new one, and what an operator has
to do.** v2 (the Schema-Driven Semantic system) *extends* v1 rather than
replacing it — the classic CDN delivery path is preserved as one Vault
*purpose*, so nothing you relied on for file delivery is gone.

> **Scope note.** The CDN → Vault change shipped as a **pre-release rename** with
> a single consolidated initial migration — there are **no live v1 URLs in the
> wild** to preserve, and no online data migration to run. This guide is
> therefore a **concepts + mapping** reference (plus the one physical step for an
> existing dev database), not a production cutover runbook.

## 1. The one idea

v1's **CDN** was a delivery afterthought: mint a hash link to a file, serve it.
v2 promotes that same entity to a **Vault** — a *semantic projection* over a
Workspace that can expose identity, chunks, embeddings, a graph, and an ask
surface, gated per purpose. The classic behavior is now `purpose: delivery`,
unchanged.

> **CDN was "how do I hand out this file."
> Vault is "what does this collection mean, and how may an AI consume it."**

## 2. Terminology map

| v1 (CDN era) | v2 (Vault) | Notes |
|---|---|---|
| `cdns` table / `Cdn*` classes | `vaults` / `Vault*` | renamed 2026-07-03 |
| `cdn_links` | `vault_links` | per-vault link hashes |
| `workspace_cdn` | `workspace_vault` | a vault may span workspaces |
| `/cdns` API, `/cdn/{hash}` public | `/vaults` API, `/vault/{hash}` public | no legacy path kept |
| "a CDN" (delivery only) | a Vault with `purpose: delivery` | **behavior identical** |
| — (didn't exist) | `purpose: gallery \| obsidian \| ai \| mixed` | the new projection modes |
| — | `/v/{org}/{vault}` and `/h/{vaultHash}` grammar | human + machine address forms |
| — | `collection_schemes.fields` as the field contract | drives forms, ES mapping, facets, validation |
| — | per-vault ES index + resource/chunk embeddings | semantic search + ask |
| — | vault keys, signed grants, `exposure_policy` | the access + tier model |

Workspaces are unchanged structurally — they remain the org-scoped container.
What changed is that a Vault now *projects* one or more Workspaces rather than a
CDN merely linking their files.

## 3. What a delivery-only user keeps for free

If all you used v1 for was hash-served files, create your vault with (or leave it
at) `purpose: delivery`:

- `/vault/{hash}` serves the file inline; `/vault/{hash}/download` honors
  `is_downloadable` — same as the old CDN.
- The hash **is** the credential; no key required.
- Per-(vault, workspace) link minting is preserved.

No schema, no embeddings, no index required. You opt into the semantic surfaces
only by choosing a richer purpose.

## 4. Opting into the semantic layer

To turn a delivery vault into a projection (gallery / obsidian / ai / mixed):

1. **Attach a scheme** to the collection(s) whose resources you project —
   `collection_schemes.fields` defines the metadata contract
   (see [`SCHEMA_FIELDS.md`](../architecture/SCHEMA_FIELDS.md)). Field names must match
   `^[a-z][a-z0-9_]*$`.
2. **Change the vault purpose** (`PUT /platform/vaults/{id}` `{purpose}`). A
   purpose change re-derives the projected index and purges stale links (both
   are hash-domain changes).
3. **Build the index + embeddings** — the queue worker extracts text, chunks,
   embeds, and the vault's projected ES index is (re)built. On demand:
   `php artisan search:reindex --vault=<slug>`.
4. **Publish or issue access** — set `state` to `public`, or leave it
   `private` and mint a vault key
   (`POST /platform/vaults/{id}/keys`), or a signed grant
   (`POST /platform/vaults/{id}/signed-urls`). See [`VAULT_SYSTEM.md`](../architecture/VAULT_SYSTEM.md) §6.4.

## 5. The one physical step (existing dev databases only)

A fresh install needs nothing special — the consolidated initial migration
already creates the `vaults`/`vault_links`/`workspace_vault` schema. An **older
dev database still on the `cdns` tables** predates the consolidation; the
supported path is a clean rebuild:

```bash
php artisan migrate:fresh --seed        # or tools/deploy/first_install.sh (Docker)
php artisan search:setup-indices --recreate
php artisan search:reindex
```

There is intentionally **no online `cdns → vaults` data migration** — the rename
predates any released data, so preserving old rows was never a requirement.

## 6. API / client consumers

- **Public delivery** callers: change `/cdn/{hash}` → `/vault/{hash}`. That's the
  whole delivery-path change.
- **Everything richer** goes through the vault grammar (`/v`, `/h`) — consume it
  via [`@tydal/client`](../../client/README.md)'s `createVaultConsumer`, the
  [`vault-mcp`](../../vault-mcp/README.md) server (read-only, or with a write key its
  purpose write ops), or the org-wide management [`org-mcp`](../../org-mcp/README.md) server.
  Hand-rolled HTTP is not needed for product code built on `@tydal/client`.

## 7. See also

- [`ARCHITECTURE_AND_ROADMAP.md`](../architecture/ARCHITECTURE_AND_ROADMAP.md) — the canonical model and the CDN→Vault decision record (§3.3).
- [`VAULT_SYSTEM.md`](../architecture/VAULT_SYSTEM.md) — addressing grammar, tiers, purposes, access forms, `exposure_policy`.
- [`CLI.md`](../CLI.md) — `search:reindex --vault`, `search:reconcile`, `mcp:token`, and the rest of the operational surface.
