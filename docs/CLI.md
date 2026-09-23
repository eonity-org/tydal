# 🛠️ TYDAL CLI Guide

Every custom artisan command, what it's for, and the operational recipes that
string them together. All commands run from `backend/`:
`php artisan <command>`. Commands that check integrity exit **non-zero on
findings**, so they slot into CI as-is.

## Search index lifecycle

### `search:setup-indices`

Create or update the Elasticsearch indexes (resource + companion `_chunks`
index per `search_indexes` row). Mappings are **fully derived from the
collection schemes** ([SCHEMA_FIELDS.md](architecture/SCHEMA_FIELDS.md)) — idempotent:
existing indexes receive additive `putMapping`, new ones are created.

```
php artisan search:setup-indices                # create/update all
php artisan search:setup-indices --index=NAME   # one index
php artisan search:setup-indices --recreate     # drop + recreate
```

An index's mapping is the **union of every scheme whose collections live in
it** — see [`search:indexes`](#searchindexes) below. If two of those schemes
declare the same field incompatibly, the command **refuses that index** (and
exits non-zero) rather than picking one; resolve it with `search:indexes
--check`.

⚠️ ES cannot change an existing field's **type** — after changing a field's
`es_type`, use `--recreate`, then `search:reindex`. Adding new fields never
needs it.

You rarely need to run this by hand for a *new* collection: `CollectionService::createCollection`
(used by both the admin UI and the seeders) already provisions the collection's
index — and its `_chunks` companion — the moment it's created, deriving the
mapping from whatever schemes already use that index. A scheme nobody has
created a collection for gets no index at all, so a fresh install only builds
what it actually seeded a collection for; run this command yourself for the
cases that path doesn't cover — bulk rebuilds, mapping changes on an existing
index, or a collection created by hand straight in the database.

### `search:indexes`

Inspect what actually lives in each index: its collections, the schemes behind
them, and the field vocabulary they merge into.

```
php artisan search:indexes                     # every index
php artisan search:indexes --index=NAME        # one, in detail
php artisan search:indexes --check             # problems only, exit 1 on conflicts
php artisan search:indexes --check --strict    # divergences fail too
```

```
kit_image_lab (Image Lab Index · global · 2 collection(s))
  collection image-lab-figures (#7) → kit_image_lab_source
  collection image-lab-output  (#8) → kit_image_lab_output
  vocabulary (5 term(s)):
    kind         keyword        facet     kit_image_lab_source
    license      keyword        facet ×2  kit_image_lab_source, kit_image_lab_output
    attribution  text+keyword         ×2  kit_image_lab_source, kit_image_lab_output
  ✓ no conflicts
```

**Why this exists.** An ES index holds exactly one mapping: a field path binds
to one type for every document in it. Several collections — and so several
schemes — routinely share an index, which makes the index a **contract**: a
field name means one thing there, and every scheme on it either agrees or
doesn't belong. Nothing made that visible before; the admin UI lists indexes
but only edits their visibility.

Two kinds of disagreement, reported separately:

| | Meaning | Effect |
|---|---|---|
| **conflict** (`✗`) | same field, incompatible `es_type`/`es_fields` | ES cannot represent both. `search:setup-indices` refuses the index; `--check` exits 1 |
| **divergence** (`~`) | same field and type, different `is_facet`, label, or option list | ES is fine; the consumers that dedupe by field name resolve it by scheme order. Warning only, unless `--strict` |

Fix a conflict by renaming one field, or by giving one scheme its own index —
mark a `SearchIndex` `restricted` and pivot it to the organization, then
repoint the collections (`index_id`). `CollectionService::defaultIndexIdFor()`
already prefers an organization's own index over a shared one, which is how
"give the big customer their own index" works without making per-organization
indexes the rule.

> The **vault** index is built separately (`buildVaultMappings`): it already
> unions across every scheme a vault projects, and types fields from the slot
> vocabulary rather than from `es_type`. `search:indexes` covers collection
> indexes only.

### `search:reindex`

Rebuild ES documents from MySQL (ES is a rebuildable cache — this is the
sledgehammer; prefer `search:reconcile` for targeted repair).

```
php artisan search:reindex                   # everything
php artisan search:reindex --collection=1    # one collection
php artisan search:reindex --vault=expo      # recompose one vault's projected index (uuid|slug|all)
```

Per-vault projected indexes (`vault_{uuid}`, Epic 3.2) normally rebuild
themselves — overlay, purpose, and scheme changes queue `RebuildVaultIndex`
automatically (needs a queue worker). `--vault=` is the manual/synchronous
form.

### `search:reconcile`

Detect (and optionally repair) MySQL ↔ ES drift per index — the surgical
alternative to a full reindex. Three drift classes: **missing** (a `live`
resource without a document), **stale** (`updated_at` mismatch), **orphaned**
(document whose resource is gone or no longer `live`).

```
php artisan search:reconcile                  # report only, exit 1 on drift
php artisan search:reconcile --fix            # reindex missing/stale, purge orphans
php artisan search:reconcile --collection=1   # scope to one collection
```

### `search:embed`

Dispatch `EmbedFileChunks` jobs for files that have extracted text — backfill
chunk vectors after enabling embeddings or changing the embedding model.

```
php artisan search:embed                    # all eligible files
php artisan search:embed --collection=1     # one collection
php artisan search:embed --file=UUID        # one file
```

Requires a running queue worker (`php artisan queue:work --timeout=360`).
Each completed job also refreshes the resource-level mean embedding
([RESOURCE_MODEL.md](architecture/RESOURCE_MODEL.md)).

### `search:wipe-indices`

**DEV ONLY.** Deletes every `tydal_*`/`vault_*` Elasticsearch index outright.
Exists because `migrate:fresh` only touches MySQL — Elasticsearch is a
separate service with no hook into Laravel's migrator, so an index for a
scheme/vault that isn't recreated by whatever runs *after* a `migrate:fresh`
(e.g. `tools/deploy/first_install.sh` re-provisions only the starter
collection(s) you pick) survives, orphaned, still pointing at MySQL rows that
no longer exist. `first_install.sh` calls this first, unconditionally, so its
"reset" is actually complete.

```
php artisan search:wipe-indices --force   # no prompt (dev env never prompts anyway)
```

## Integrity checks (CI-able)

### `schema:validate`

Validate every collection scheme's `fields` array against the field contract
([SCHEMA_FIELDS.md](architecture/SCHEMA_FIELDS.md)): name format/uniqueness, known types,
`type`/`es_type` compatibility, facet aggregatability, validator coherence,
`select` option lists, `accepted_mimetypes` patterns.

```
php artisan schema:validate                   # all schemes, exit 1 on violations
php artisan schema:validate --scheme=multimedia
```

### `resources:audit-roles`

Audit the resource composition invariants
([RESOURCE_MODEL.md](architecture/RESOURCE_MODEL.md)): single active canonical, no
component beside a canonical, canonical carries no relation, single snapshot
flag; reports metadata-only resources and files stranded on soft-deleted
resources.

```
php artisan resources:audit-roles         # report only, exit 1 on violations
php artisan resources:audit-roles --fix   # newest canonical wins, peers demote, relations cleared
```

### `vault:validate-policy`

Validate every vault's `exposure_policy` against the capability vocabulary
([VAULT_SYSTEM.md](architecture/VAULT_SYSTEM.md) §6.3). The API rejects unknown keys on
write, but rows created before that validation existed — or edited straight in
SQL — can still carry a misspelt key, which reads as "silently on the preset"
rather than as an error.

Two severities: **errors** (`-`) fail the run, **notices** (`~`) report a
deliberate widening and do not.

```
php artisan vault:validate-policy                  # all vaults, exit 1 on violations
php artisan vault:validate-policy --vault=figures  # one vault (id, hash or slug)
php artisan vault:validate-policy --quiet-notices  # errors only, for a terse CI log
```

Errors: unknown capability keys, wrong value types, an `ingest` target that
does not exist or belongs to another organization, and an `ai` vault that
accepts `ingest` with no target configured at all. Notices: an `ai` vault with
`allow_binary` on (the transforming-consumer recipe), a non-AI vault answering
`/ask`, and `write_methods` widened past the purpose vocabulary.

## Resource graph (Epic 4.3)

### `graph:rebuild`

Rebuild the auto-asserted layers of the resource relation graph. Each origin
is its own rebuild domain — `tags` (co-occurrence: resources sharing ≥
`--min-shared` active tags, weight = Jaccard of their tag sets) and
`semantic` (embedding k-NN over the resource mean embeddings, pairs scoring ≥
`--min-score`). Curator (`manual`) edges are never touched.

```
php artisan graph:rebuild                       # both layers, every org
php artisan graph:rebuild --org=acme --tags     # one org, tag edges only
php artisan graph:rebuild --semantic --k=8 --min-score=0.8
```

### `graph:clusters`

Read-only view of the graph's connected components (union-find over
`related` edges, all origins) — the raw material Epic 4.5 materializes into
auto-named cluster vaults. Run `graph:rebuild` first.

```
php artisan graph:clusters --org=acme           # human-readable
php artisan graph:clusters --json               # machine-readable
```

### `graph:materialize`

Materialize the clusters as **auto-created vaults** (Epic 4.5): one
`purpose=mixed`, `generated_from='clusters'` vault per cluster, projected
through a dedicated system workspace. Member-overlap matching keeps a
cluster vault's slug and hash stable across runs while its LLM-generated
human name refreshes; stale cluster vaults are retired. Curator vaults are
never touched. Same operation over HTTP:
`POST /api/v1/platform/vaults/clusters/rebuild` (superadmin).

```
php artisan graph:materialize --org=acme
php artisan graph:materialize --min-size=3      # ignore tiny clusters
```

## MCP access

### `mcp:token`

Issue a scoped Sanctum token on a dedicated machine user for the **org-wide**
MCP server (`org-mcp/`). Re-issuing with the same `--name` revokes the previous
token.

```
php artisan mcp:token                                      # defaults: editor, read+ask
php artisan mcp:token --org=acme --role=viewer \
    --abilities=read --name=claude-desktop --days=90
```

Options: `--email=` machine user (created if missing) · `--org=` slug or UUID
· `--role=` viewer|editor|admin · `--abilities=` read,ask,write · `--name=`
identifies the token (re-issuing with the same name revokes the old one —
name it after the client/machine, e.g. `claude-desktop`, so you can rotate it
later without accumulating orphaned tokens) · `--days=` lifetime (default: no
expiry).

The **vault** MCP server (`vault-mcp/`) does not use tokens — published
vaults connect keyless; private vaults use a **vault key** minted via
`POST /api/v1/platform/vaults/{id}/keys` (see
[VAULT_SYSTEM.md](architecture/VAULT_SYSTEM.md) §7). A key carries an `abilities` set —
`["read"]` (the consumer surface) or, for a vault whose purpose exposes write
methods, `["w:activate", …]` / `["w:ingest"]` (the write boundary, [VAULT_WRITE_METHODS.md](architecture/VAULT_WRITE_METHODS.md)).
Set `TYDAL_VAULT_WRITE_KEY` on the vault-MCP connection and the purpose's write
ops (`ingest` on an `ai` vault) appear as tools — read-only otherwise.
There is no CLI for vault keys — they are minted in the admin UI or via that endpoint.

## Housekeeping (scheduled — see `bootstrap/app.php`)

| Command | Schedule | Purpose |
|---|---|---|
| `resource:prune` | daily 03:00 | permanently delete abandoned drafts (`--draft-hours=24`) and expired soft-deletes (`--deleted-days=30`); `--dry-run` supported |
| `files:purge-uncommitted` | daily 03:30 | hard-delete files left by crashed edit sessions (`--older-than=24` hours) |
| `resources:purge-drafts` | hourly | hard-delete abandoned create-mode drafts (`--older-than=1` hour) |
| `aity:purge-stale` | manual | mark stale AITY file states failed and purge matching Redis jobs (`--hours=24 --queue=default --dry-run`) |

The scheduler needs `php artisan schedule:work` (dev) or a system cron
running `php artisan schedule:run` every minute (prod).

## Debug helpers

```
php artisan debug:collection {slug}      # collection info
php artisan debug:db {table?}            # table contents
php artisan debug:mappings {slug}        # scheme fields vs live ES mapping for a collection
php artisan schema:starter-options       # list is_system schemes as name|display_name|description
```

`schema:starter-options` is read-only — it lists nothing but what
`CollectionSchemaSeeder` already seeded. `tools/deploy/first_install.sh` reads
it to build its "which starter collection(s)" menu, so a new `is_system`
scheme becomes selectable there without editing the shell script. It does not
create anything itself — that's `db:seed --class=MinimalSeeder` (with
`TYDAL_INITIAL_COLLECTIONS=name[,name...]`), which also provisions each new
collection's ES index automatically (see [`search:setup-indices`](#searchsetup-indices)
above) — no separate index-build step needed. It's safe to re-run any time to
add another starter collection later without touching what's already there.

Starter collections are **optional** — `TYDAL_INITIAL_COLLECTIONS` unset/empty
creates none. `MinimalSeeder` always creates the usable baseline regardless
(superadmin, organization, default workspace); `CollectionSchemaSeeder` always
seeds the system collection schemes (schema metadata — no ES index is
physically built until a collection uses it). TYDAL is fully usable with just
that baseline and zero collections; `first_install.sh` defaults to none too
(prompt defaults to "0) None", `-f`/non-interactive skips collection creation
entirely) unless you pass `--collections=...`.

## Recipes

**First-time search setup**
```
php artisan search:setup-indices
php artisan search:reindex
php artisan queue:work --timeout=360     # keep running — ES indexing is async
```

**After editing a scheme's fields**
```
php artisan schema:validate
php artisan search:setup-indices             # additive change
# — or, if a field's es_type changed —
php artisan search:setup-indices --recreate
php artisan search:reindex
```

**Search results look wrong / out of date**
```
php artisan search:reconcile           # diagnose
php artisan search:reconcile --fix     # repair
```

**Enable embeddings on existing content**
```
php artisan search:embed               # with a queue worker running
```

**CI integrity gate**
```
php artisan schema:validate \
  && php artisan search:indexes --check \
  && php artisan resources:audit-roles \
  && php artisan vault:validate-policy
```
`schema:validate` checks each scheme in isolation; `search:indexes --check` is
the cross-scheme axis that only exists once an index is shared.
