# @tydal/client

Shared TypeScript client for the TYDAL REST API — the intended transport for
**any client building typed product/application code** against TYDAL: the
management SPA (`frontend/`) and the vault renderer apps (`vaults/*`), which
ship together with the backend on one release, and external product
integrators outside this repo — e.g. **Full Frame**, which depends on a real,
versioned `@tydal/client` release from the public registry, not a workspace
link.

`org-mcp` and `vault-mcp` deliberately do **not** depend on this package. Both
are thin MCP adapters that forward raw JSON straight to an LLM — they never
touch the SDK's typed methods, envelope unwrapping, or auth refresh-retry, so
importing it buys nothing. Worse, `org-mcp`'s prior dependency was
`"@tydal/client": "*"` — an npm-workspace link with no version boundary at
all (unlike Full Frame's real semver pin), tying it to this package's HEAD
instead of the stable, documented HTTP contract
(`docs/architecture/VAULT_WRITE_METHODS.md`, `docs/architecture/VAULT_SYSTEM.md`).
They each carry their own small `fetch` wrapper instead.

It originally also replaced `mcp/src/client.ts`'s hand-rolled `axios` client
(Epic 5.0) — that package was later renamed `org-mcp` and, for the reason
above, deliberately moved back to a `fetch`-based client of its own.

See `tydal/docs/planning/ROADMAP_MILESTONES.md` → **Epic 5.0** and
`tydal/docs/architecture/ARCHITECTURE_AND_ROADMAP.md` §3.5 for where this fits.

## Status

Epic 5.0 complete: HTTP core, pluggable auth strategy, envelope unwrap,
`TydalApiError`, and the `resources`, `collections`, `workspaces`,
`organizations`, `notifications`, and `semantic-tags` namespaces, plus
`createVaultConsumer` for the public vault grammar. Published on public npmjs
as `@tydal/client`.

## Usage

```ts
import { createTydalClient, staticToken } from '@tydal/client'

// MCP / server-side: a static token + org header
const tydal = createTydalClient({
  baseUrl: 'https://api.example.com/api/v1',
  auth: staticToken(process.env.TYDAL_TOKEN!),
  orgId: process.env.TYDAL_ORG_ID,
})

const resource = await tydal.resources.get(resourceId)
const page = await tydal.resources.catalogue(collectionId, {
  search: 'paris',
  facets: { type: ['image'] },
})
```

### Auth strategy

`auth` is a pluggable `TokenProvider` — the seam that lets one client serve
different surfaces:

```ts
// Browser SPA: cookie token + refresh + redirect on 401
const auth: TokenProvider = {
  getToken: () => readTokenCookie(),
  refresh: () => refreshTokenCookie(),     // retried once on 401
  onUnauthorized: () => location.assign('/login'),
}
```

The core sends `Authorization: Bearer <token>` and, when `orgId` is set,
`X-Organization-ID`. On a `401` it calls `refresh()` once and retries, otherwise
`onUnauthorized()`.

### Vault consumer

`createVaultConsumer` speaks the public vault grammar (the web-root
`/v/{org}/{slug}` or `/h/{hash}` routes, **not** `/api/v1`). Auth is the vault's
own: published vaults are keyless, private vaults take a vault key
(`X-Vault-Key`), sent from `config.key`.

```ts
import { createVaultConsumer } from '@tydal/client'

const vault = createVaultConsumer({
  baseUrl: 'https://tydal.example.com',   // web root
  vault: { org: 'acme', slug: 'expo' },   // or { hash: 'AbC123XyZ012' }
  key: 'tvk_…',                           // read key for a private vault
})

const meta = await vault.meta()           // tiers, presentation, operations
const page = await vault.resources({ page: 1 })
const answer = await vault.ask({ question: '…' })   // if the ask tier is on
```

`meta().tiers` is the contract for "may I?" checks. `meta().capabilities` (when
the backend serves it) adds the full exposure matrix with provenance — each knob
reports whether its value came from the vault's purpose `preset` or from an
`override` on this vault:

```ts
meta.capabilities?.allow_binary   // { value: true, source: 'override' }
```

**Writes** (VAULT_WRITE_METHODS.md) — the inbound counterpart, authorized by a
**write** vault key (`config.key` on a consumer built for writing). One generic
op + document: the op name is the unit of permission (`w:{op}`), audit, and
discovery, and its payload is a declarative document the vault's purpose maps
server-side. A document carrying a `Blob`/`File` is sent as multipart
automatically.

```ts
const writer = createVaultConsumer({
  baseUrl, vault: { hash }, key: 'tvk_…',      // e.g. a w:activate key
})
await writer.write('activate', { resources: linkHashes })  // project these works
await writer.write('open')                                 // publish
await writer.write('close')                                // un-publish + restore

// Inbound content, on any purpose that takes it (gallery, ai). Multipart when
// the document holds a File; `metadata` is one flat object — `name` and
// `description` go onto the resource, every other key into its metadata.
const { result } = await writer.write('ingest', {
  image,
  metadata: { name: 'Morning at the Pier', author: 'Ana Ruiz', technique: 'Silver gelatin print' },
})                                                          // ai vaults also send `descriptor`
await writer.write('update', { resource: hash, metadata: { dimensions: '50 × 70 cm' } }) // null removes a key
await writer.write('withdraw', { resource: hash })         // to TYDAL's trash

// Which ops may this key invoke, and (with w:update/w:withdraw) on what?
const { methods, ingested } = await writer.writeCapabilities()
```

> `writer.gallery.activate/open/close` still work as thin shims but are
> **deprecated** — behavior grows server-side, not as SDK namespaces.

Read returns bare payloads; a refused write throws `TydalApiError` (4xx), like
any grammar error.

## Scripts

```bash
npm run build      # tsc → dist/
npm run typecheck  # tsc --noEmit
npm test           # vitest run
```
