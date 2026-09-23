# @tydal/aity — AI vault app

An **independent client of the TYDAL vault boundary** (Epic 5.3): point it at
any ask-enabled vault (`ai` or `mixed` purpose, or `exposure_policy.allow_ask`)
and it renders a grounded chat — questions answered only from the vault's own
projection, with resource-level citations and related follow-ups.

It knows **nothing about TYDAL internals or schemes**. Everything comes from
the public vault grammar via `@tydal/client`'s `createVaultConsumer`:

- `/meta` → identity + `tiers.ask` (whether this vault answers at all — the
  composer disables itself otherwise).
- `POST /ask` → the AITY loop at the boundary: retrieval through the same
  tier-gated grammar (chunks when exposed, identity cards always, graph
  expansion), one grounded answer + sources. Throttled per IP; reasoning is
  gated by vault purpose so a published gallery can't be farmed for tokens.
- Citations: AITY cites resources **by name** — `src/citations.ts` weaves
  those mentions into links to each source's vault address; the sources
  footer lists name + page numbers.
- `/related` on the top source → "Explore related" follow-up chips.

## Run

```bash
# from tydal/ (workspace root)
npm install
npm run build -w @tydal/client

# dev server on :3012 (proxies /v /h /vault to the backend, default :8000)
npm run dev -w @tydal/aity
```

Then open, for example:

```
http://localhost:3012/?vault=acme/brain          # published vault, human address
http://localhost:3012/?hash=AbC123XyZ012         # machine address
http://localhost:3012/?vault=acme/brain&key=tvk_… # private vault
```

Build-time defaults: `VITE_VAULT` (`org/slug`), `VITE_VAULT_KEY`,
`VITE_TYDAL_URL` (web root; empty = same origin). URL params override env.

```bash
npm run build -w @tydal/aity   # static bundle in dist/ — host it anywhere
npm test -w @tydal/aity        # citation-weaving unit tests
```
