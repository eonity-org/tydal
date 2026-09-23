# @tydal/obsidian — knowledge-graph vault app

An **independent client of the TYDAL vault boundary** (Epic 5.2): point it at
any `obsidian`-purpose (or `mixed`) vault and it renders a linked notebook —
force graph of every note, a reading view with frontmatter-style properties
and document text, and a backlinks panel.

It knows **nothing about TYDAL internals or schemes**. Everything comes from
the public vault grammar via `@tydal/client`'s `createVaultConsumer`:

- `/meta` → title, tiers, and the resolved **presentation** (field → slot).
  The slot engine (`src/presentation.ts`) is the only place meaning is
  resolved: node_label / body / property / link_source — `hidden` fields
  never render.
- `/graph` → the whole projected graph in one call: node cards + edges whose
  **both endpoints** are projected (cross-vault relations never leak). The
  force layout (`src/graph.ts`) is deterministic — same vault, same picture.
- `/{slug}/meta` + `/{slug}/chunks` → the open note: metadata for the
  property/body slots, extracted document text when the vault exposes the
  chunk tier.
- Backlinks/outlinks are derived client-side from the graph's edges, with
  each edge's origin (manual / tags / semantic) shown so curation reads
  differently from inference.

## Run

```bash
# from tydal/ (workspace root)
npm install
npm run build -w @tydal/client

# dev server on :3011 (proxies /v /h /vault to the backend, default :8000)
npm run dev -w @tydal/obsidian
```

Then open, for example:

```
http://localhost:3011/?vault=acme/notes           # published vault, human address
http://localhost:3011/?hash=AbC123XyZ012          # machine address
http://localhost:3011/?vault=acme/notes&key=tvk_… # private vault
```

Build-time defaults: `VITE_VAULT` (`org/slug`), `VITE_VAULT_KEY`,
`VITE_TYDAL_URL` (web root; empty = same origin). URL params override env.

```bash
npm run build -w @tydal/obsidian   # static bundle in dist/ — host it anywhere
npm test -w @tydal/obsidian        # slot engine + graph + markdown unit tests
```
