# Agent guide: working with TYDAL

For AI agents, and the people who set them up, that **use** a TYDAL
installation: to search it, describe and organize its resources, or consume what
it publishes. If you're an agent changing TYDAL's own code, read
[AGENTS.md](../AGENTS.md) instead.

This guide says which door to use, how to get through it, and how to behave once
inside. For connection recipes per client (Claude Desktop, Claude Code, Cursor),
see [Connecting an AI Client](CONNECTING_MCP_CLIENTS.md). The design rationale is
in [AI Surfaces](architecture/AI_SURFACES.md).

## Three rings, four doors

AI meets TYDAL at three levels. Each ring narrows **who you are**, **what you
can reach** and **what words you see**:

| Ring | Door | Who drives | Sees | Writes |
|---|---|---|---|---|
| **0 · Installation** | shell, CLI, repository | an operator's agent | everything on the server | anything (it's the server) |
| **1 · Organization** | **AiTy** (TYDAL calls the AI) | TYDAL itself, on upload | one file at a time | *suggestions*, pending human review |
| **1 · Organization** | **org MCP** (the AI calls TYDAL) | an agent acting as a member | the whole organization, as that member's role allows, with internal ids | final values, attributed to that member |
| **2 · Vault** | **vault MCP** / vault HTTP | anyone's agent, from outside | one vault's projection only, with vault-scoped ids | only the vault's purpose-defined ops |

Two things worth keeping straight:

- **AiTy and org MCP share a ring, not a direction.** With AiTy, TYDAL invites
  the AI in: unattended, server-configured, and limited to proposing values a
  human accepts. With org MCP, the agent comes in as a user: on demand, it
  writes final values, and it's trusted exactly as far as its member's role.
- **The vault ring is the only one meant for strangers.** Rings 0 and 1 are for
  *your* agents. When the AI belongs to a partner, a customer or the public, give
  it a vault, never an organization token.

## Ring 0 · The installation

TYDAL is open source, so an agent can install, upgrade and operate it like any
server. Start from [AGENTS.md](../AGENTS.md) (layout, commands, conventions),
the [Deployment Guide](../DEPLOYMENT.md) and the [CLI Guide](CLI.md). Integrity
commands exit non-zero on findings, so they make good agent checks:
`schema:validate`, `search:indexes --check`, `resources:audit-roles`,
`vault:validate-policy`, `search:reconcile`.

**Rules:** don't echo secrets (`.env`, keys printed once by `mcp:token` or
`exhibitions:create`) into logs or chat. Ask before anything destructive
(`first_install.sh`, `--recreate`, `--fix`, purges).

## Ring 1 · The organization

### AiTy: not a door you open

AiTy runs inside TYDAL when files are uploaded. You don't call it. What you
should know is what it leaves behind: `ai_suggested_*` values awaiting review,
and each field's **origin** (written by a user, or applied from an AiTy
suggestion). Treat a user-authored name or description as the owner's words:
don't overwrite it unless you were asked to.

### Org MCP: an agent that works like a member

[`@tydal/org-mcp`](../org-mcp/README.md) speaks the same API as the web app,
with an API key bound to one organization and one member.

**Getting a key** (an administrator, on the server):

```bash
php artisan mcp:token --org=SLUG --abilities=read,ask,write --days=90
```

Give the key to a **dedicated member** (for example *MCP Service*, created with
`user:create`) whose role is the lowest that does the job: **viewer** to read,
**editor** to describe and tag what it uploads, **admin** only if it must edit
everyone's resources or manage workspaces. The key's abilities narrow what the
member can do; they never widen it.

**Tools**

| Ability | Tools |
|---|---|
| `read` | `list_workspaces`, `browse_workspace`, `search_workspace`, `get_resource`, `list_resource_chunks`, `get_vault_links` |
| `ask` | `ask_workspace`, `ask_vault` (answers with citations) |
| `write` | `create_workspace`, `add_resource_to_workspace`, `remove_resource_from_workspace`, `update_resource_metadata`, `sync_tags`, `upload_file` |

**Rules for org-MCP agents**

1. **Read before you write.** `get_resource` first; know the scheme's fields
   and the current values.
2. **Writes are final and attributed** to your member. There's no review
   queue. Propose changes to the human, and ask before applying them, unless
   you were told to act on your own.
3. **Use the scheme's vocabulary.** Field names come from the collection's
   scheme; required fields must be filled.
4. **Reuse tags.** Search the existing tags before inventing one; give new
   tags the right type (person, organization, place, thing, tag).
5. **`sync_tags` replaces the set** it's given. Send the full list you want,
   not only the additions.
6. **You can't see images here.** org MCP returns metadata and extracted text,
   not pixels. To look at a photograph, use `read_image` through a vault
   (ring 2).
7. **Expect partial success.** Bulk-style operations report what was applied
   and what was skipped (usually for permission reasons). Report the skips;
   don't retry them blindly.

## Ring 2 · The vault

[`@tydal/vault-mcp`](../vault-mcp/README.md) is scoped to **one vault**. A
public vault needs no key; a private one needs a **read key**; write tools only
appear when a **write key** is configured too. Keys are minted by a platform
administrator (Platform administration → Vault Sharing → Access keys).

**Call `get_vault` first.** The vault describes itself: its purpose, what it
contains, and which of the tiers below it answers. A well-behaved agent never
probes a tier the vault said it denies.

| Verb | Tier | Tools | Gives |
|---|---|---|---|
| `get_`, `list_`, `resolve` | 0 · identity | `get_vault`, `get_resource`, `get_file`, `list_resources`, `list_files`, `list_related`, `resolve` | names, descriptions, tags, field values |
| `search_` | 0/1 | `search_resources`, `search_chunks` (`keyword` or `semantic`) | matching resources or passages |
| `read_` | 1/2 | `read_chunks` (text), `read_image` (pixels the model can see) | content |
| `link_` | 2 · binary | `link_resource`, `link_file` | URLs to the files, never the bytes |
| `embed_query` | — | | a vector in the vault's space |
| `ingest` | write | | a new resource in the vault's landing place |

A good loop: `search_*` (tier 0) → `read_chunks` (tier 1, the evidence) →
`link_*` (tier 2) only if the task really needs the file.

**Rules for vault agents**

1. **Ids are the vault's own.** Resources are addressed by vault-scoped link
   hashes. TYDAL's internal UUIDs never appear here, so don't ask for them or
   invent them.
2. **The purpose sets the limits.** A *gallery* vault shows images and
   captions but no document text; an *ai* vault shows text, and files only if
   allowed. Respect a denial; don't work around it.
3. **Writes are ops, not edits.** You can't PATCH a resource through a vault.
   You can call the purpose's operations (`ingest` / `update` / `withdraw`, and
   for galleries `activate` / `open` / `close`), each with a declarative
   document. *Where* a written resource lands is the vault's decision, not yours.
4. **Cite.** When you answer from a vault, name the resources you used, by title
   and link, so a human can check.

**Bring your own AI.** Point any MCP-capable model at an `ai` vault with a read
key and a write key, and it can read the sources (`list_resources` →
`read_image` / `read_chunks`), transform them with its own model and prompt, and
write the results back with `ingest`. No TYDAL-side credentials for the model,
no glue code.

## Which door?

| You want to… | Use |
|---|---|
| install, upgrade, check or repair an installation | ring 0: shell + [CLI](CLI.md) |
| get titles, descriptions and tags proposed on upload, with a human gate | AiTy (configured by the platform admin) |
| have your own assistant curate, tag, upload or answer questions across the organization | org MCP |
| let a partner's or customer's AI work on a curated set | a vault + vault MCP |
| let an AI look at photographs | vault MCP (`read_image`) |
| build an app on published content | a vault + [`@tydal/client`](../client/README.md) |

## See also

- [Connecting an AI Client](CONNECTING_MCP_CLIENTS.md): step-by-step client
  configuration.
- [AI Surfaces](architecture/AI_SURFACES.md): the three AI surfaces in depth,
  including AiTy's drivers.
- [Vault System](architecture/VAULT_SYSTEM.md) and
  [Vault Write Methods](architecture/VAULT_WRITE_METHODS.md): the boundary's
  contract.
- [User guide](user-guide/README.md): what the humans see, chapter by chapter.
