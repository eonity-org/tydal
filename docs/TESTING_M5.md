# 🧪 Testing Milestone 5 — the full vault walkthrough

A hands-on script to exercise everything M5 shipped: the shared SDK, the three
vault apps (gallery `:3010` · notes `:3011` · ask `:3012`), the new vault
operations (`graph`, `preview`, `ask`, renditions), and the publish system
(keys + signed URLs). Follow it top to bottom — later steps reuse what earlier
steps create.

> **Where things run right now (dev, Docker-only host):** backend `:8000`
> (`tydal_app` container), SPA `:3005` (via `tools/deploy/start.sh`), and the
> three vault apps in containers `tydal_gallery` / `tydal_obsidian` /
> `tydal_aity`. `php artisan …` in this guide means:
>
> ```bash
> docker compose -f docker-compose.yml exec -w /var/www/html app php artisan …
> ```
>
> The **queue worker must be running** (`tydal_queue` container) — extraction,
> embeddings, previews, AI descriptions and vault-index builds are all async.
> Watch it live with `docker logs -f tydal_queue`.

Login: `superadmin@tydal.test` / the `TYDAL_SUPERADMIN_PASSWORD` in
`backend/.env`.

---

## 1 · Foundation: workspace + a real PDF

1. **Create a workspace** — SPA (`http://localhost:3005`) → sidebar →
   workspaces → create e.g. `field-docs`. (Or keep using the default
   "All Resources" workspace — vaults can also project the whole org via
   *has public workspace*.)
2. **Upload a PDF** — create a resource, attach a text-bearing PDF (your
   Andalusia declaration is perfect), and optionally an image or two as extra
   files. Assign the resource to `field-docs`.
3. **Watch the pipeline** (`docker logs -f tydal_queue`) — you should see, in
   order: `ExtractFileText` (Tika) → `EmbedFileChunks` (mxbai via Ollama) →
   `IndexResourceToElasticsearch`, plus `AnalyzeImageContent` /
   `AutoTagResource` if AI vision/tagging is on, and `ExtractEmbeddedPreview`
   (PDF first page → preview image) when no image snapshot exists.
4. **Check composition** — with multiple files, mind the roles (1 `canonical`
   OR N `component`s; `supporting` never indexes). Verify + repair:

   ```bash
   php artisan resources:audit-roles          # report
   php artisan resources:audit-roles --fix    # keep newest canonical, demote rest
   ```

   ⚠️ Known quirk: `--fix` keeps the **newest** canonical — if you upload a
   PDF and then images, it may crown an image. For "PDF is the content,
   images illustrate": set the PDF `canonical` (or everything `component`)
   in the resource's file panel.
5. **Sanity**: SPA catalogue shows the resource; keyword search finds it;
   semantic search ("by meaning") finds it by paraphrase — if semantic returns
   nothing, see Troubleshooting (embedding space).

## 2 · CDN (delivery vault) — the classic file links

1. SPA → Admin → **Vault Sharing** → create vault, purpose **delivery**,
   attach the `field-docs` workspace (delivery vaults are hash-credential:
   no publish flag needed — the link *is* the secret).
2. Open your resource → **Vault tab** → links are minted lazily per
   (vault × workspace): copy the file URL (`/vault/{hash}`) and open it in a
   private browser window — the binary streams with no auth.
3. Optional: set `hash_ttl_hours` on the vault (edit dialog) — newly minted
   links then carry an expiry; expired links 404.

## 3 · Gallery vault + app (`:3010`)

1. Vault Sharing → create vault: purpose **gallery**, **published** ✓, attach
   `field-docs` (or *has public workspace* ✓ to project the whole org).
   The projected search index now **builds automatically on create** — no
   purpose-change dance needed (watch `RebuildVaultIndex` in the queue log).
2. Open `http://localhost:3010/?vault=<org-slug>/<vault-slug>` and check:
   - **wall**: cards render; the PDF resource shows its **preview** (snapshot
     image or rendered first page) instead of a placeholder;
   - **facets**: chips appear for facet-slot fields and re-aggregate as you
     search/filter;
   - **search**: keyword box; the *by meaning* toggle appears (semantic) and
     returns your document for a paraphrased query;
   - **lightbox**: click the card — same image, museum plate, related works.
3. Grammar spot-checks (curl, no auth needed on a published vault):

   ```bash
   curl http://localhost:8000/v/<org>/<vault>/meta      # presentation, tiers, operations
   curl http://localhost:8000/v/<org>/<vault>/search?q=…
   curl http://localhost:8000/v/<org>/<vault>/<resource-slug>/preview -o face.jpg
   curl "http://localhost:8000/v/<org>/<vault>/<resource>/<file>/renditions"
   #   → rendition URLs are vault-scoped (…/download?rendition=thumbnail)
   ```

## 4 · Obsidian vault + app (`:3011`)

1. **Materialize graph edges** (tag co-occurrence; needs resources sharing ≥2
   tags — upload a few more docs or let auto-tagging run):

   ```bash
   php artisan graph:rebuild --org=<org-slug> --tags
   ```

2. Vault Sharing → create vault: purpose **obsidian**, published, attach
   workspace (or public workspace).
3. Open `http://localhost:3011/?vault=<org>/<vault>`:
   - **graph**: force layout, node size = degree, dashed edges = semantic
     origin, solid = manual/tags;
   - click a node → **note view**: title, properties (property-slot fields),
     document text from `/chunks` (obsidian vaults expose the chunk tier),
     mini-graph with the neighborhood highlighted;
   - **backlinks panel**: *Linked from / Links to* with edge origins — walk
     between notes;
   - sidebar filter narrows the list.
4. Grammar: `curl http://localhost:8000/v/<org>/<vault>/graph` → nodes +
   edges (both endpoints always inside the projection).

## 5 · AI vault + app (`:3012`)

1. Vault Sharing → create vault: purpose **ai** (or **mixed**), published,
   project the workspace with your PDF. Note in `/meta`: `tiers.ask: true` —
   only `ai`/`mixed` purposes answer at the boundary (a gallery vault 403s,
   so nobody farms your LLM through a public exhibition).
2. Open `http://localhost:3012/?vault=<org>/<vault>` and **ask something the
   PDF answers**. The answer **streams in live** token-by-token (blinking
   cursor) rather than waiting for the full ~30 s local-model latency; on
   completion it settles into the grounded answer → **Sources** footer with
   page numbers linking into the vault → *Explore related* chips if the
   resource has graph neighbors. (Streaming works on the Ollama driver; other
   LLM backends deliver the answer in one chunk. `curl -N -H 'Accept:
   text/event-stream' …/ask` shows the raw SSE `token`/`done` frames.)
3. Try a question the vault *can't* answer — AITY should say so rather than
   invent.
4. Boundary rules to verify: asking on the gallery vault → the app disables
   its composer; >20 questions/min from one IP → 429.

## 6 · Publish system: private vaults, keys, signed URLs

1. **Unpublish** a vault (Vault Sharing → edit → published ✗). Its app URL
   and every `curl` now 404 — existence is hidden, not just denied.
2. **Vault key** (standing credential): Vault Sharing → edit → keys → mint.
   Copy the plaintext (shown once!). Open any app with
   `…?vault=<org>/<slug>&key=tvk_…` — full access; revoke the key → 404.
3. **Signed URL** (time-limited publish grant): Vault Sharing → edit the vault →
   **Signed URLs** → pick a validity (1 h / 24 h / 7 d / 30 d) → **Mint signed
   URL**. Copy the URL from the green banner. Paste it into a vault app —
   either use it directly (`…/h/<hash>?sig=…&exp=…` is the machine root) or
   append `&sig=…&exp=…` to an app URL like
   `http://localhost:3011/?hash=<vault-hash>&sig=…&exp=…`. It opens the
   **private** vault with no key, until `exp`.

   The vault-wide grant opens the whole surface. A **link-scoped** grant
   (curl-only for now — pass `"link_hash": "<hash from the Vault tab URL>"`
   to `POST /platform/vaults/{id}/signed-urls`) opens only that resource plus
   its own files — sibling addresses and the vault surface stay 404.

   API form, for scripting:

   ```bash
   TOKEN=$(curl -s -X POST http://localhost:8000/api/v1/login \
     -H 'Content-Type: application/json' -H 'Accept: application/json' \
     -d '{"email":"superadmin@tydal.test","password":"<your password>"}' \
     | python3 -c 'import sys,json; print(json.load(sys.stdin)["data"]["token"])')

   curl -s -X POST http://localhost:8000/api/v1/platform/vaults/<VAULT-ID>/signed-urls \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -H 'Accept: application/json' -d '{"expires_in_hours":168}'
   #   add  "link_hash": "<hash>"  for a single-resource grant
   ```
4. **Revocation** (edit dialog — two buttons, pick the blast radius):
   - **Revoke all grants** (Signed URLs section) — invalidates every outstanding
     grant at once; **all links keep working**. Verify: an open grant now 404s,
     but the same vault opened with a key (or, if published, bare) still works.
   - **Rotate salt** (Salt section) — the nuclear reset: fresh generated secret
     **and** every link purged, so all shared URLs *and* all grants die. Reserve
     it for a leaked salt. (API: `POST …/vaults/{id}/revoke-grants` and
     `POST …/vaults/{id}/rotate-salt`.)

> Note: the **New Vault** form has no *Organization* selector and no *Salt*
> field — the vault is created in the org selected in the header, and the salt
> is generated automatically. Rotating the salt is the edit-dialog button above,
> not a text field.

## 7 · Cross-purpose (one vault, three faces)

Any app opens any vault — purpose only decides slot vocabulary and tier
defaults. Best demo: create ONE **mixed** vault over your workspace and open
the same address in all three apps:

```
http://localhost:3010/?vault=<org>/<slug>   # exhibition
http://localhost:3011/?vault=<org>/<slug>   # knowledge graph
http://localhost:3012/?vault=<org>/<slug>   # ask (mixed answers!)
```

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| New resource never appears anywhere | Queue worker not running — `docker compose restart queue`, then `php artisan search:reindex` for anything missed. |
| Semantic search / ask retrieval returns nothing | Embedding space mismatch after switching AI tiers (jina↔mxbai). Run `tools/deploy/reindex.sh` (non-destructive: recreate index + reindex + re-embed). |
| Gallery has no facets / no "by meaning" toggle | Vault's projected index missing (`indexed_at` NULL). Should build on create now; force via saving any overlay or purpose in the edit dialog, then check the queue log. |
| `/ask` → 403 | Purpose isn't `ai`/`mixed` and `exposure_policy.allow_ask` not set. By design. |
| `/ask` → 503 | LLM unreachable — is `tydal_ollama` up and `llama3.2` pulled? `docker exec tydal_ollama ollama list`. |
| Platform API → 401 mid-session | Superadmin Sanctum tokens go stale quickly (worth investigating) — just log in again. |
| App URL 404s after a DB reseed | Org slugs are regenerated by the seeders — re-check the slug in Admin → Orgs. |
| Vault app port dead | Containers: `docker ps | grep tydal_` → restart the app container, or run `npm run dev -w @tydal/<app>` if you have host Node. |

## What each app's URL accepts

`?vault=org/slug` (human) · `?hash=…` (machine) · `&key=tvk_…` (vault key) ·
`&sig=…&exp=…` (signed grant) · `&base=…` (backend web root if not
same-origin/proxied).
