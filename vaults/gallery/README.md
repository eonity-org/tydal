# @tydal/gallery — exhibition vault app

An **independent client of the TYDAL vault boundary** (Epic 5.1): point it at
any `gallery`-purpose (or `mixed`) vault and it renders an exhibition — grid
wall, facet filters, keyword/semantic search, and a lightbox with museum-plate
details and graph-related works.

It knows **nothing about TYDAL internals or schemes**. Everything comes from
the public vault grammar via `@tydal/client`'s `createVaultConsumer`:

- `/meta` → title, tiers, search modes, and the resolved **presentation**
  (field → slot). The slot engine (`src/presentation.ts`) is the only place
  meaning is resolved: caption / subcaption / image / badge / detail /
  credit — `hidden` fields never render.
- `/search` → cards + facet aggregations; facet chips filter via
  `facet[field]=value`; the semantic toggle appears only when the vault
  advertises the mode.
- `/related` → the lightbox's "Related works" strip (graph edges, vault-projected).
- Card images are the cards' own addresses (`card.url`); multi-file resources
  fall back to their first image file via `/files`; non-visual works get a
  typographic placeholder.

## Run

```bash
# from tydal/ (workspace root)
npm install
npm run build -w @tydal/client

# dev server on :3010 (proxies /v /h /vault to the backend, default :8000)
npm run dev -w @tydal/gallery
```

Then open, for example:

```
http://localhost:3010/?vault=acme/expo            # published vault, human address
http://localhost:3010/?hash=AbC123XyZ012          # machine address
http://localhost:3010/?vault=acme/expo&key=tvk_…  # private vault
```

Build-time defaults: `VITE_VAULT` (`org/slug`), `VITE_VAULT_KEY`,
`VITE_TYDAL_URL` (web root; empty = same origin). URL params override env.

```bash
npm run build -w @tydal/gallery    # static bundle in dist/ — host it anywhere
npm test -w @tydal/gallery         # slot-engine unit tests
```
