# 🧺 The Basket & Bulk Actions

## The Basket

The dashboard's **one and only** multi-select. Ticking a card in the grid or a row
in the list puts a resource in the basket, and it stays there through paging,
sorting, filters, the grid/list toggle, and a change of collection or workspace.

That persistence is only safe because the basket is **inspectable**: `/basket`
lists what is in it, so "23 selected" is an inventory you can open rather than a
number you have to trust. A nameless selection that quietly survived a scope
change would be a trap. This is also why cross-scope persistence is offered at
all — inspectability is what earns it.

**Not called a Collection.** That word is a first-class TYDAL entity (scheme + ES
index); reusing it in the UI would be genuinely confusing.

### Where it lives

`localStorage`, keyed per organization **and** user, capped at 200 to match the
server-side cap on every bulk endpoint (`BulkResourceIdsRequest::MAX_IDS`).

Deliberately **not** a server-side workspace row, despite `workspaces.purpose`
already modelling a non-editorial working set for AITY review: every write to
`dam_resource_workspace` fires an inline Elasticsearch reindex, and that pivot is
what `workspace_vault` projects publicly. Ticking a checkbox must not touch the
graph that drives vault exposure.

Graduating a basket into a real workspace is an explicit act instead — **Save as
workspace** on `/basket`. The basket is the personal, ephemeral precursor; the
workspace is the shared, persisted, projectable one.

### Surfaces

| | |
|---|---|
| Tick | grid card (hover-revealed, pinned once anything is in the basket) and list row |
| Selection bar | above the results — `N in basket · M on this page`, Select page, Clear, Review |
| `/basket` | gallery laid out like the trash can: per-item remove, Empty basket, Save as workspace |
| Header | badge with the count, on every page |

The **"M on this page"** figure is the honesty requirement of a selection that
outlives the page: nobody should archive twenty-three things believing they picked
the twelve they can see.

## Bulk endpoints

| Method | Path | Permission |
|---|---|---|
| `POST` | `/workspaces/{id}/resources/bulk-attach` | `workspaces.manage-resources` |
| `POST` | `/workspaces/{id}/resources/bulk-detach` | `workspaces.manage-resources` |
| `POST` | `/resources/bulk/state` | `resources.update` |
| `POST` | `/resources/bulk/semantic-tags` | `resources.update` |

All take `resource_ids[]` (1–200). `POST` for the detach and removal variants
because a request body on `DELETE` travels badly through proxies and `fetch`.

Delete deliberately has **no** bulk endpoint: it is soft, reversible, and lands in
the reviewable trash — the one action with a safety net already — so it stays a
client-side fan-out reported the same way.

### Partial success is a report, not an error

Authorization is per-resource, so acting on a subset is the normal outcome: a
basket gathered across collections routinely holds a few resources the caller
cannot touch, and refusing all forty because of two would be useless.

```json
{
  "success": true,
  "requested": 20,
  "applied": 18,
  "skipped": [{ "id": "…", "reason": "forbidden" }],
  "indexing": "immediate"
}
```

`200` for any partial success; `403` only when **not one** id could be applied.
The UI keeps the skipped ids in the basket so a retry is one click.

### Tags are additive, not a replacement

`PUT /resources/{id}/semantic-tags` takes a whole set and `sync()`s it — right
when a human edits one resource's tags in a panel, wrong for "add *Barcelona* to
these forty", which must not wipe the tags each of them already carries. The bulk
endpoint uses `syncWithoutDetaching` for `mode=add` and `detach` for
`mode=remove`.

The per-resource endpoint's suggestion-provenance matching is deliberately not
run: it exists to decide whether a human's committed tag *set* matches an AITY
proposal, and adding one tag to forty resources is not a commit of anyone's set.
Authorship is still recorded.

## Index timing — `indexing: immediate | queued`

Every other write in TYDAL indexes synchronously (the model boot hooks use
`dispatchSync`), so the dashboard is built to refetch straight away. Queuing every
bulk reindex broke that contract: the grid and the facets came back showing
pre-change data.

`SyncResourcesToElasticsearch::run()` therefore reindexes **inline at or below 25
resources** (`SYNC_THRESHOLD`) and queues above it, reporting which in the
response. The common basket keeps read-your-writes; genuinely large batches say
*"The list will catch up in a moment."*

Two more details that matter:

- **The job syncs rather than indexes.** A state change to `archived` must
  *remove* documents, so the direction is decided per resource from its state,
  not by the caller.
- **It refreshes the indices it touched.** Elasticsearch is near-real-time and
  nothing in the indexing path asks for a refresh, so even a synchronous write is
  invisible to a search for up to a second. Refreshing only what the run touched
  closes that window without forcing a refresh on every single-document write.

Bulk actions need a running `queue:work` for batches over the threshold — see the
dev setup in the root `CLAUDE.md`.

## Vault index reconciliation

`ElasticsearchService::indexResourceIntoVaults` used to only *add*: it indexed
into vaults a resource was currently reachable in and never removed it from vaults
it no longer belonged to. Losing a workspace therefore left the resource **still
publicly projected** in that workspace's vault — on any detach, not just deletion.

It now prunes first (`pruneResourceFromUnreachableVaultIndexes`), then indexes.
An empty reachable set correctly sweeps the resource from every vault index.

Note that an organization-wide vault (`has_public_workspace = true`) keeps
projecting a resource regardless of workspace membership — losing a workspace does
not unpublish from those.

## Workspace deletion

`dam_resource_workspace` and `workspace_vault` both cascade at the **database**
level, which bypasses Eloquent entirely: no pivot events, no model events on the
resources that just lost a membership, and therefore no reindex. Members kept a
stale `workspace_ids` entry and their documents stayed in the vault indexes that
membership used to justify.

`WorkspaceService::deleteWorkspace` now captures its members, detaches
**explicitly** rather than trusting the cascade, deletes, then reindexes.

The confirmation is **one informative prompt, not two** — a second identical "are
you sure?" only teaches people to click through both. It states the member count,
that the resources are **not** deleted, and names any vault that will stop
projecting them.
