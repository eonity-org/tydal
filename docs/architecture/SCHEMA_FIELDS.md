# 🧾 Collection Scheme Fields — the Field Contract

`collection_schemes.fields` is the **single source of truth** for resource
metadata structure. Every consumer below derives its behavior from this one
JSON array — change a field definition and forms, search mappings, facets,
validation, and upload gating all follow. `php artisan schema:validate`
enforces this contract (CI-able, exits non-zero on violations).

> **Governance & trust.** Collection schemes and search indexes are
> **platform-level** — neither carries an `organization_id` — and shared by
> default. Since the visibility mechanism they can also be offered to a subset:
> see [Visibility](#visibility-who-may-use-a-scheme-or-an-index) below. Reads are
> open to any authenticated user (scoped to what their organization is offered);
> **writes (`POST`/`PUT`/`DELETE /collection-schemes` and `/search-indexes`) are
> superadmin-only**, because mutating a shared object is inherently a
> platform-operator action. Field `name` is charset-constrained
> (`^[a-z][a-z0-9_]*$`) **at the write API**, not only in `schema:validate`:
> names flow into raw SQL in the catalogue's DB-facet path, so the boundary
> must never accept SQL metacharacters. The vocabulary itself (types, es_types,
> storages, validator keys, facetable types) lives in `App\Support\SchemaContract`
> and is read by both the write endpoint and `schema:validate`, so the form and
> the validator cannot disagree about what a valid field is.

## Field shape

```jsonc
{
  "name": "language",              // required · ^[a-z][a-z0-9_]*$ · unique per scheme
  "display_name": "Language",      // human label (falls back to name)
  "type": "select",                // widget + validation type, see below
  "storage": "metadata",           // metadata | column | index_only
  "required": false,               // boolean
  "display_in_form": true,         // boolean — render in dynamic forms
  "order": 3,                      // integer — form ordering
  "is_facet": true,                // boolean — aggregate in /catalogue
  "facet_label": "Language",       // facet display label
  "facet_order": 1,                // facet ordering
  "validators": {                  // see validator keys below
    "in": ["es", "en", "fr"]
  },
  "es_type": "keyword",            // ES mapping type for metadata.<name>
  "es_fields": {                   // optional ES subfields (e.g. keyword for
    "keyword": { "type": "keyword" }  // faceting a text field)
  }
}
```

### `type` — widget + validation semantics

`string` · `text` · `select` · `integer` · `boolean` · `array` · `email` ·
`url` · `date`

- `select` **requires** `validators.in` — the frontend renders the option
  list from it (`CarouselResourceEditor`); without it the dropdown is empty.
- `type` → Laravel rule mapping lives in `CollectionSchemaService`.

### `storage` — where the value lives

| storage | Stored in | Validated by | ES-mapped |
|---|---|---|---|
| `metadata` | `resources.metadata` JSON | `CollectionSchemaService` | yes (`metadata.<name>`) |
| `column` | a real `resources` column | `StoreResourceRequest` | via base mapping |
| `index_only` | nowhere in MySQL | — | yes |

### `es_type` — ES mapping for the field

`text` · `keyword` · `integer` · `long` · `float` · `double` · `boolean` ·
`date` · `object`. Compatibility constraints (enforced by `schema:validate`):
`type: integer` → `integer|long` · `boolean` → `boolean` · `date` →
`date|keyword` · `array` → `keyword|text`.

⚠️ ES cannot change an existing field's type — after changing an `es_type`,
run `php artisan search:setup-indices --recreate` and `search:reindex`.
Additive changes (new fields) apply idempotently without `--recreate`.

### `is_facet` — catalogue aggregation

Requires an **aggregatable** mapping: `es_type` ∈ keyword/numeric/boolean/date,
or a `text` field with an `es_fields.keyword` subfield.

### `validators` — declarative validation

`min_length` · `max_length` · `min_value` · `max_value` · `in` (array).
Mapped to Laravel rules by `CollectionSchemaService::buildValidationRules()`;
mins must not exceed maxes.

### `vault_roles` — semantic presentation mapping (Epic 3.1)

What this field *means* inside each vault purpose — the scheme author's
suggestion, overridable per vault (`vault_schema_overlays`):

```jsonc
"vault_roles": { "gallery": "badge", "obsidian": "property", "ai": "facet" }
```

Field names are free; the **role vocabulary is the fixed contract**
(`VaultSchemaResolver::ROLE_VOCABULARY`): gallery `caption·subcaption·image·
badge·detail·credit·hidden` · obsidian `node_label·body·property·link_source·
hidden` · ai `identity·retrieval_text·facet·context·hidden`. Resolution:
structural preset (from `is_facet`/`display_in_form`) ← `vault_roles` ←
per-vault overlay; resource built-ins (name/description/snapshot/tags) fill
the core slots for every scheme. The resolved map ships in the vault
self-description (`/meta` → `presentation`), so decoupled clients never
hardcode field names.

### `ai_fill` — AI extraction contract (Epic 3.3 seed)

Opt a field into AITY suggestion filling:

```jsonc
"ai_fill": { "enabled": true, "hint": "the painting technique used" }
```

The field's own contract constrains the extraction: `select` passes its
`validators.in` as a **closed option list**, `integer`/`date`/`boolean` shape
the requested value. Suggestions land as `AI_SUGGESTED_METADATA` SystemFiles
(per source file), follow the same contributor rules as everything else
(canonical wins / components first-by-position), and are applied by
auto-approve **through the scheme's own validation**
(`validatePartialResourceData`) — filling only empty keys, never clobbering
curator input.

### Scheme-level: `accepted_mimetypes`

Array of `type/subtype` patterns (wildcard subtype allowed, e.g. `image/*`).
Gates every file upload for collections using the scheme
(`ResourceController::uploadFile`).

## Consumer audit (who reads what)

| Consumer | Reads | Purpose |
|---|---|---|
| `CollectionSchemaService` (backend) | `storage`, `type`, `required`, `validators`, `display_name` | metadata validation on create/update |
| `ElasticsearchService::buildMappings` | `storage`, `es_type`, `es_fields` | index mapping generation (`search:setup-indices`) |
| `CatalogueController` | `is_facet`, `name`, `facet_label`, `facet_order` | facet aggregation list |
| `ResourceController::uploadFile` | scheme `accepted_mimetypes` | MIME gating |
| `WorkspaceController` | facet fields | workspace catalogue facets |
| `CarouselResourceEditor` (frontend) | `display_in_form`, `order`, `type`, `required`, `display_name`, `validators.in` | dynamic form widgets |
| `CollectionsTab` (frontend) | full shape | schema editor |
| `schema:validate` (command) | full shape | contract enforcement |

All consumers tolerate missing optional keys (they default) — the contract
violations that break things silently are the ones `schema:validate` checks:
duplicate/invalid names, unknown types, non-aggregatable facets, `select`
without options, and `type`/`es_type` mismatches.

## Editing a scheme: locked once in use

A field definition drives **five** things at once — the dynamic form, the
Elasticsearch mapping, the facet list, validation, and where the value is stored.
Change one under collections that already hold resources and the mapping stops
agreeing with the documents; only `search:setup-indices --recreate` plus a full
reindex puts that right.

So:

| Scheme state | `fields` | `display_name` / `description` | `name` |
|---|---|---|---|
| No collections | editable | editable | editable |
| In use by ≥1 collection | **refused (422)** | editable | editable |
| `is_system` | as above | editable | **refused (422)** |

`name` is the machine identifier — the platform looks the `multimedia` system
scheme up by it as the default when none is given — so renaming a system scheme
would silently break collection creation. `display_name` is a label and always
moves.

**Clone instead of edit.** `POST /collection-schemes/{id}/clone` with an
`organization_id` copies the fields into a new scheme restricted to that
organization. Because the copy has no collections, its fields stay editable — the
way to vary a contract for one customer without disturbing anybody else's
mapping. The copy is never `is_system`.

The admin UI (`Schemes & Indexes` tab → Edit) renders the same table as the
schemes reference panel: a locked scheme looks exactly like the read-only viewer,
an unused one swaps the cells for inline controls. It catches the facet/text trap
before the round trip — a facet is an aggregation and analysed `text` cannot be
aggregated.

## Visibility: who may use a scheme or an index

`collection_schemes.visibility` and `search_indexes.visibility` are
`global | restricted` (`App\Enums\Visibility`), with an organization pivot each
(`collection_scheme_organization`, `organization_search_index`). **"One
organization only" is `restricted` with a single pivot row** — not a third case
to keep consistent.

One mechanism, two very different weights:

- **Scheme visibility curates a menu.** A scheme is a definition; nothing leaks
  if the list is wrong.
- **Index visibility decides data co-residency** — whose documents are physically
  stored together. An index restricted to one organization is an isolation claim.

Because of that second point, `searchResources` and `searchByWorkspace` now filter
`organization_id` **explicitly**. A collection and a workspace each belong to one
organization, so those queries were already scoped transitively — fine while index
sharing was incidental, but an advertised isolation guarantee should be enforced
rather than inherited from a join two tables away. (Chunk documents carry
`collection_id` but no `organization_id`, so chunk queries remain transitively
scoped; changing that mapping would need a full reindex.)

### The default index

A collection with `index_id = null` silently falls back to **database search** —
keyword only, no facets, no semantic retrieval, no indication. Every collection
created through the admin form landed that way, because the form never sent the
field.

`CollectionService::defaultIndexIdFor()` now picks the **most specific active
index the organization is entitled to** (its own before a shared one), mirroring
the default `scheme_id` already had. Both the platform and organization creation
paths use it. Existing collections are unaffected — attach an index and reindex to
fix one.

### The index is a shared field vocabulary

An ES index holds exactly **one** mapping — a field path binds to one type for
every document in it. Since several collections (and so several schemes) share
an index by default, the index is a *contract*: a field name means one thing
there, and every scheme on it either agrees or belongs somewhere else.

The mapping is therefore the **union of every scheme on the index**
(`ElasticsearchService::vocabularyFor` → `App\Support\IndexVocabulary`), not the
first collection's. Two disagreements are distinguished:

- **conflict** — same field, incompatible `es_type`/`es_fields`. Unrepresentable
  in ES, so `search:setup-indices` refuses the index instead of resolving it by
  row order.
- **divergence** — same field and type, different `is_facet`, `facet_label` or
  `validators.in`. Legal in ES, but the consumers that dedupe by field name
  (`vaultFacetFields`) then resolve it by scheme order. Reported as a warning.

`php artisan search:indexes` shows the merged vocabulary and both classes of
problem; `--check` is the CI gate. See [CLI.md](../CLI.md) § `search:indexes`.

### Organization-side creation & quota

`POST /collections` is usable by an organization's own owners/admins:

- the scheme must be one the organization is offered (403 otherwise) — otherwise
  the visibility list is a menu, not a rule;
- the index is derived, never asked for;
- `organizations.collection_quota` caps how many they may create, falling back to
  `config('tydal.collection_quota')` (5). Platform administrators are exempt.

Tests: `backend/tests/Feature/SchemeAndIndexVisibilityTest.php`.
