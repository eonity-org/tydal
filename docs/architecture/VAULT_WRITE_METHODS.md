# Vault write methods — capability-scoped writes through the vault boundary

**Status:** Implemented (2026-07-24)
**Author:** design session 2026-07-24
**Supersedes:** the "per-exhibition org-admin service token" approach to the Full Frame opening (E4.2)
**Related:** [[VAULT_SYSTEM.md]] (the boundary + read tiers), [[RESOURCE_MODEL.md]] (state)

> **As built** — matches this design; the concrete deltas worth noting:
> - Two migrations: `vault_keys.abilities` + the `vault_writes` audit table, and
>   `vaults.selection_snapshot` (so `close` restores the pre-selection projection
>   without the caller remembering anything).
> - Both address forms carry writes: `POST /h/{vaultHash}/w/{method}` and
>   `POST /v/{orgSlug}/{vaultSlug}/w/{method}`, sharing one `authorizeWrite` gate.
> - Those routes are **CSRF-exempt** (bootstrap/app.php), like `/ask` — a
>   sessionless machine surface gated by the write key, not a session.
> - Abilities are granular (`w:activate`/`w:open`/`w:close`); every accepted call
>   writes a `vault_writes` audit row (method + summary + key + ip).
> - The selection lives in an internal, system-owned `vault-{id}-selection`
>   workspace whose owner is inherited from an existing org workspace.
> - Full Frame stores a read + write vault key per exhibition, encrypted
>   (AES-256-GCM, `src/lib/crypto.ts`); `writeback.ts` no longer touches
>   workspaces, org tokens, or the management API.

## 1. Problem

The vault is a one-way boundary today: resources are projected **out** through
purpose-gated read tiers (`allowsChunks` / `allowsBinary` / `allowsAsk`), each
unlocked by a vault key (`tvk_…`) scoped to exactly one vault. Reads are
beautifully least-privilege.

Writes are not. The one write a consumer needs — Full Frame's "open the
exhibition", which swaps *which resources a gallery vault projects* — has no
vault-scoped path. It goes through the org-wide management API
(`POST /workspaces`, `addResource`, attach/detach vault, publish), authorized by
a **Sanctum user token scoped to the whole organization**. To flip one gallery's
selected set, Full Frame must hold an org-admin credential. The blast radius of
a leak is every vault, workspace, and resource in that org.

That asymmetry is the actual source of every problem in the opening flow:
org-token pinning, `X-Organization-ID`, the shared `mcp@tydal.test` machine
user, org-membership juggling. All of it exists only because the write goes the
long way around the boundary.

## 2. Solution

Make the vault a **write boundary too**: it accepts a small, fixed set of
**purpose-defined write methods**, each gated by a **write-capable vault key**.
The consumer declares intent ("project exactly these works"); TYDAL performs the
internal bookkeeping (workspaces, attach/detach, projection rebuild) on its
behalf. The consumer never sees a workspace, an org, or a resource UUID.

Symmetry restored:

| Side  | Credential           | Scope       | Surface                          |
|-------|----------------------|-------------|----------------------------------|
| Read  | vault key `['read']` | one vault   | read tiers (chunks/binary/ask)   |
| Write | vault key `['w:*']`  | one vault   | purpose's write methods          |

An exhibition stores **two vault keys** — one read, one write — and nothing
else. No Sanctum token, no org context, no machine user.

## 3. What each purpose exposes

Per-purpose write vocabulary, declared the same way read tiers already are
(`VaultPurpose` → behavior). `gallery` and `ai` are defined; the registry is open
for the rest.

```
VaultPurpose::writeMethods(): string[]
  gallery  → ['activate', 'open', 'close', 'ingest', 'update', 'withdraw']
  ai       → ['ingest', 'update', 'withdraw']
  delivery → []          // future: ['mintLink', 'revokeLink']
  obsidian → []
  mixed    → []          // union of member purposes, later
```

**Op + document model.** An op name is the unit of *permission* (`w:{op}`),
*audit* (`vault_writes.method`), and *discovery* (`writeCapabilities()`). Its
**payload is a declarative document** the purpose's writer maps to
workspaces/resources — so new behavior grows server-side, and the SDK exposes a
single generic `write(op, payload)` rather than a namespace per purpose (§8).

### Gallery methods

- **`activate`** — *declarative replace.* Body: `{ "resources": [<linkHash>, …] }`.
  Sets the vault's projected set to **exactly** those works. Idempotent. Works
  not listed simply stop resolving (projection-pure revocation — no cleanup
  calls). Hashes that don't resolve to a resource **inside this vault's org** are
  rejected, all-or-nothing (the request fails, nothing changes).
- **`open`** — flip vault `state` `private → public`. No body.
- **`close`** — flip `state` `public → private` **and** revert the projection to
  the full submission set (reverses a prior `activate`). No body.

`open(selection)` as a single combined call was considered; kept orthogonal so
`activate` can be re-run (re-cut the selection) without touching state, and so a
dry-run/preview stays a pure read.

### Inbound ops — `ingest` / `update` / `withdraw` (every purpose that takes content)

Added 2026-09-25 for Full Frame's curator uploads, and deliberately
purpose-agnostic: the names say what happens to the vault's content, not which
kind of vault it is. `VaultIngest` implements everything shared; a purpose's
writer decides only **which files an `ingest` carries and how they're stored**.

- **The ingest target.** New resources land in
  `exposure_policy.ingest = { workspace_id, collection_id }` — vault-configured,
  never consumer-supplied (the consumer declares *what*; the vault decides
  *where*). No target → the op is refused with a 400.
  The platform vault form picks it by name, and also sets which workspaces the
  vault reads from (`workspace_ids`) — the same links the workspace selector
  edits. Both sides refuse the links the vault controls
  (`VaultService::associationLock`, 409): removing the ingest target while the
  vault accepts `ingest`, touching a system workspace, and any change while a
  gallery's published selection decides what it shows. The selector shows those
  links locked, with the reason.
- **The metadata document.** One flat JSON object, `metadata`. Keys naming a
  resource column (`name`, `description`) are **lifted** onto the resource;
  every other key is stored in `resources.metadata` **as given**. Values are
  text, numbers or booleans (≤ 50 keys, ≤ 5000 chars; `name` ≤ 255); keys are
  `lower_snake_case`. No AI and no scheme check rewrites it — the collection's
  scheme only decides which keys are indexed, faceted and shown on vault cards
  (undeclared keys are still returned by `…/meta`). Each fact lives once: the
  title is the resource's `name`, never also a metadata copy.
- **`ingest`** — multipart: the purpose's files + `metadata`. Returns
  `{ hash, … }` — the new resource's vault link hash.
  - `gallery`: one `image` (canonical, its own preview); `metadata.name` is
    **required** (the author owns the title). The stored filename is slugged
    from the title, so the uploader's filename (often a person's name) never
    reaches TYDAL. Refused when the collection's scheme doesn't accept the MIME
    type, or when the vault doesn't project the target workspace right now
    (e.g. during an active selection) — the photo would be stored but unseen.
  - `ai`: an `image` (a derived/translated image, stored as a `component`,
    `relation: translation`) + a `descriptor` JSON field (tables/graphs/
    formulae, stored as the canonical `descriptor.json`). `name` is optional;
    provenance such as `source_hash` is just metadata. A companion `delivery`
    vault over the target workspace then exports the JSON to a renderer.
  - No AI enrichment runs on ingested content.
- **`update`** — `{ resource: <hash>, metadata: {…} }`, merged per key: a
  value replaces, `null` removes; `name` can change but not be removed.
- **`withdraw`** — `{ resource: <hash> }`. Soft delete (TYDAL's trash,
  recoverable by an org admin until `resource:prune`). Refused while a gallery
  selection is active.
- **Provenance.** `update`/`withdraw` act **only on resources this vault's
  `ingest` created** — the `vault_writes` audit is the record, so a write key
  can never touch a work that reached the vault another way. The write probe
  (`GET …/w`) lists them as `ingested` when the key holds `w:update` or
  `w:withdraw`, so a consumer offers those actions on the right items with no
  bookkeeping of its own.

A new purpose that takes content only defines its file composition; the target,
the metadata document, `update`, `withdraw` and provenance come with it.

This was the first op family to carry **binary**; the SDK's generic `write()`
sends multipart automatically when the document holds a `Blob`/`File` (§8).

## 4. Credential model

`vault_keys` grows one column:

```
abilities  json  NOT NULL  default '["read"]'
```

- Existing keys backfill to `["read"]` — read paths keep working unchanged.
- A write key carries method-scoped abilities, e.g. `["w:activate","w:open","w:close"]`
  or a narrower subset. **The key allows exactly the methods it lists** — this is
  the "writing key that unlocks this method only" property.
- Read gate (`passesVaultPolicy`) additionally requires the presented key to
  include `"read"`.
- Write gate requires the key to include `"w:{method}"` **and** the method to be
  in `vault.purpose.writeMethods()`.

`VaultKey`:

```php
protected $casts = ['abilities' => 'array', /* … */];

/** Hash match + not revoked + ability present. */
public static function verifyWithAbility(Vault $vault, string $plaintext, string $ability): bool
```

Read keys and write keys are separate credentials so they rotate and revoke
independently (the jury proxy holds only the read key; the opening holds only
the write key).

## 5. Endpoint contract

```
POST /h/{vaultHash}/w/{method}
  Headers: X-Vault-Key: <plaintext write key>   (or ?vault_key= as today)
           Content-Type: application/json
  Body:    method-specific JSON (see §3)

  200 { "ok": true,  "result": { … } }
  400 { "ok": false, "error": "…" }   malformed body / unknown ref
  403 { "ok": false, "error": "…" }   key lacks ability, or method not in purpose
  404                                  vault not found / disabled (indistinguishable)
```

Lives beside the existing machine surface in `VaultNamespaceController`
(`/h/{vaultHash}/ask`, `/h/{vaultHash}/{op}`), reusing its
`vaultKey($request)` resolver. Distinguished from the GET operation/link routes
by verb + the `/w/` segment.

### Authorization (dedicated, not the read gate)

Writes must work on a **private** vault (that's the point of `open()`), so the
write path does **not** call `passesVaultPolicy` with `requirePublished`. It runs:

```
authorizeWrite(vaultHash, method, key, ip):
  vault = find by hash
  reject 404  if !vault->isReachable()            // disabled
  reject 403  if method ∉ vault->purpose->writeMethods()
  reject 403  if !VaultKey::verifyWithAbility(vault, key, "w:$method")
  reject 404  if allowed_ips set and ip ∉ allowed_ips
  → vault
```

`state` is deliberately *not* a precondition beyond "not disabled": a private
gallery must accept `activate`/`open` from its holder.

## 6. Internal implementation

The methods are thin intent; the mechanics stay TYDAL-internal and reuse what
`writeback.ts` does today, moved server-side into a `GalleryVaultWriter` (one
class per purpose that exposes writes), all in a DB transaction:

- **`activate(hashes)`** — resolve each `linkHash → VaultLink → resource` scoped
  to `vault_id` (reject unknown/foreign). Materialize/replace the vault's
  internal "selected" workspace with exactly that resource set; attach it; detach
  the others. Dispatch `RebuildVaultIndex`. Because `vaultResourceQuery` already
  pins projection to `vault.organization_id`, a foreign resource is structurally
  impossible to inject even if a hash slipped through.
- **`open` / `close`** — set `vault.state` (`VaultState::PUBLIC` / `PRIVATE`);
  `close` also re-attaches the full submission workspace(s). Same publish/rebuild
  path the current `VaultController::publish` uses.

Full Frame's `writeback.ts` workspace choreography is **deleted** — that
knowledge moves behind the boundary where it belongs.

## 7. Reference space: vault link hashes

Payloads speak **link hashes** — the only ids a consumer ever sees (the boundary
hides resource UUIDs; see [[vault-boundary-id-encoding]]). Full Frame passes back
the very hashes it rendered to the jury. TYDAL maps hash→resource internally and
refuses any hash not belonging to this vault. No UUID or slug ever crosses the
boundary, in either direction.

Selection transfer is **push** (Full Frame `POST`s the set with its write key),
not pull (TYDAL fetching a Full Frame URL): keeps the dependency one-directional
and needs no callback contract.

## 8. SDK (`@tydal/client`)

The vault consumer exposes **one generic write**, symmetric with its read
methods — the client stays purpose-agnostic (no `vault.gallery.*` / `vault.ai.*`
namespaces; behaviors grow server-side, not in the SDK):

```ts
consumer.write(op: string, payload?: unknown): Promise<{ ok: boolean; result?: unknown }>
consumer.writeCapabilities(): Promise<{ ok: boolean; methods: string[] }>  // non-destructive probe

await consumer.write('activate', { resources: linkHashes })
await consumer.write('open')
await consumer.write('close')
await consumer.write('ingest', { descriptor, image })   // image: Blob/File → multipart, automatically
```

A plain object is sent as JSON; a document carrying a `Blob`/`File` value is sent
as **multipart** (non-file object fields JSON-encoded per part), so binary ops
like `ingest` need no special call. It sends `X-Vault-Key` exactly like the read
path (`vault.ts` sets that header from `config.key`); the write key is just a
second configured consumer.

> The earlier `gallery.{activate,open,close}` sugar is **deprecated** to thin
> shims over `write()` — kept so existing callers keep working, not extended.

**Three callers, one op.** The write boundary is the same whichever front hits it:
`@tydal/client`'s `write(op, payload)` (products like Full Frame and ImageLab),
the **`vault-mcp` `ingest` tool** (a customer's own MCP AI, when
`TYDAL_VAULT_WRITE_KEY` is set — read-only otherwise), or a raw multipart
`POST /{h|v}/…/w/{method}`. All carry the write key and pass the same document.

## 9. Full Frame impact

- Store `readVaultKey` + `writeVaultKey` **per exhibition**, encrypted at rest
  (AES-GCM with a key from Full Frame env — Next has no Laravel `Crypt`; small
  helper). Set at exhibition setup by that vault's admin.
- `vaultFor(exhibition)` uses the stored **read** key (replaces global
  `VAULT_KEY` env). `openExhibition` uses the stored **write** key.
- Delete the workspace logic in `writeback.ts`; the opening becomes
  `consumer.gallery.activate(selected)` then `consumer.gallery.open()`;
  reopening is `consumer.gallery.close()`.
- Drop `serviceToken()` / `TYDAL_SERVICE_TOKEN` and the `mcp:token` step entirely
  for Full Frame.

## 10. What this removes

The entire org-token apparatus for the opening: `TYDAL_SERVICE_TOKEN`,
`X-Organization-ID` threading, the shared `mcp@tydal.test` user, org-membership
management, `last_organization_id` fragility. Multi-org "just works" because each
exhibition is authorized only by its own vault's keys — the org is never named on
this path.

## 11. Security properties

- **Blast radius = one vault.** A leaked write key can only invoke the whitelisted
  methods on that single vault; it cannot touch other vaults, the org, or raw
  resources outside the projection.
- **Ability-scoped & revocable.** A key holds exactly the methods it needs;
  revoke is a single `revoked_at` stamp.
- **Org-pinned by construction.** `activate` can only project resources already in
  the vault's org (the serve-time pin).
- **No UUIDs cross the boundary**, inbound or outbound.

## 12. Rollout

Nobody is on the system yet, so **no backward-compat layer**: build the clean
model directly and reseed `first-frame` with its own read + write keys. No env
fallback, no dual-path.

## 13. Test plan

Backend (Pest):
- `verifyWithAbility` — hash/revocation/ability matrix.
- Endpoint gate — unknown method, method not in purpose, read key on a write
  route, write key missing the specific ability, disabled vault, IP allowlist.
- `activate` — happy path sets exact projection; foreign/unknown hash rejects
  all-or-nothing; idempotent re-run; org pin holds even with a crafted hash.
- `open`/`close` — state transitions + projection revert; index rebuild dispatched.

SDK (Vitest): write call sends `X-Vault-Key`, parses envelope, surfaces errors.

Full Frame: opening calls the methods; reopening reverses; no workspace calls
remain.

## 14. Effort

Backend: migration + `VaultKey` ability + `VaultPurpose::writeMethods()` +
`authorizeWrite` + endpoint + `GalleryVaultWriter` + key-mint UI (abilities
selector in VaultsTab) + tests. SDK: one write method + tests. Full Frame:
schema field + crypto helper + setup field + rewire + delete `writeback.ts`
internals. Meaningful but self-contained; the read boundary is untouched.

## 15. Decisions locked (this design)

- Transfer: **push** (not pull).
- Reference space: **link hashes** (not slugs/UUIDs).
- Gallery vocabulary: **`activate` + `open`/`close`** (orthogonal, not combined).
- Credential: **write-capable vault keys**, separate from read keys, method-scoped
  abilities (not a new key type, not a Sanctum token).

## 16. Open questions

- Method-ability naming: `w:activate` vs a coarse `write` that grants all of the
  purpose's methods. (Leaning granular per the "this method only" goal.)
- Do we want an audit row per write (who/when/what set) on the vault? Cheap to add
  and useful for the exhibition record.
- `mixed` purpose: union of member write vocabularies, or none until specced.
