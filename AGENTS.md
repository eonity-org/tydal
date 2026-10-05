# AGENTS.md

Instructions for AI coding agents working **on** this repository (Claude Code,
Codex, Cursor and the like). If you are an agent that wants to **use** a running
TYDAL (search it, curate it, read a vault), read
[docs/AGENT_GUIDE.md](docs/AGENT_GUIDE.md) instead.

## What this is

TYDAL, the typed Digital Asset Layer: a multi-tenant, schema-driven asset
platform. Resources are stored once and projected outward through **vaults**,
each with a purpose, a policy and its own addressing. Canonical architecture:
[docs/architecture/ARCHITECTURE_AND_ROADMAP.md](docs/architecture/ARCHITECTURE_AND_ROADMAP.md).

| Path | What |
|---|---|
| `backend/` | Laravel 12 REST API: routes → controllers → services → repositories → models |
| `frontend/` | React 18 + TypeScript + MUI v6 SPA |
| `client/` | `@tydal/client`, the TypeScript SDK; the only HTTP layer for the SPA and `vaults/*` |
| `org-mcp/` | `@tydal/org-mcp`, organization-wide MCP server (agent works as a user) |
| `vault-mcp/` | `@tydal/vault-mcp`, one-vault MCP server (bring-your-own-AI) |
| `vaults/*` | Vault renderer apps (gallery, obsidian, aity) |
| `tools/` | Tier-aware dev scripts; index in [tools/README.md](tools/README.md) |
| `docs/` | Public docs; index in [docs/README.md](docs/README.md) |

The JS packages are an npm workspace rooted here (`npm install` at the root links
`@tydal/client`). `org-mcp` and `vault-mcp` deliberately use plain `fetch`, not
the SDK: customers run them on their own upgrade schedule.

## Running things

The stack runs in Docker (`docker-compose.yml`; tiers switched with
`./configure.sh <ai> <app>`). On the docker tier every artisan command runs
inside the app container, **with the working directory set**:

```bash
docker exec -w /var/www/html tydal_app php artisan <command>
```

Without `-w` you get `Could not open input file: artisan`. Every command is listed
in [QUICK_REFERENCE.md](QUICK_REFERENCE.md); artisan is documented in
[docs/CLI.md](docs/CLI.md).

| Task | Command |
|---|---|
| All tests (Pest on a separate `tydal_test` DB, then JS suites) | `tools/deploy/test.sh` |
| Backend only, one test | `tools/deploy/test.sh --backend-only -- --filter=Name` |
| PHP style / static analysis | `composer pint`, `composer phpstan` (in the container) |
| JS | `npm run test`, `npm run build` in the package |

After changing `client/`, run its `build`: the SPA and `vaults/*` import its
`dist/`.

## Rules of the codebase

- **Authorization has one source of truth**: `backend/config/permissions.php`.
  Policies only add context (same organization, ownership). Two axes: platform
  admin (`users.is_superadmin`) and organization role
  (owner 100 / admin 75 / editor 50 / viewer 25). See
  [docs/architecture/ROLES_AND_PERMISSIONS.md](docs/architecture/ROLES_AND_PERMISSIONS.md).
  The SPA renders write controls from `/me` permissions; keep UI and policy in
  step.
- **Schemes drive everything**: `collection_schemes.fields` defines forms,
  validation, facets and the Elasticsearch mapping
  ([SCHEMA_FIELDS.md](docs/architecture/SCHEMA_FIELDS.md)). A scheme in use is
  locked.
- **Two state enums, two axes**: `resources.state` (draft · live · archived)
  is the lifecycle; `vaults.state` (disabled · private · public) is the
  boundary. Don't reintroduce `active`/`visibility`-style flags.
- **The vault boundary leaks nothing internal**: the public surface exposes
  vault-scoped link hashes, never resource UUIDs or internal ids. Writes through
  a vault are purpose-defined ops (`activate/open/close`,
  `ingest/update/withdraw`) with a declarative document payload
  ([VAULT_WRITE_METHODS.md](docs/architecture/VAULT_WRITE_METHODS.md)).
- **IDs**: public entities (users, organizations, resources) are UUID v7;
  private ones (workspaces, collections, categories) are integers.
- Frontend: MUI `sx` or styled components, no inline styles. All HTTP goes
  through `@tydal/client`.
- A new environment variable goes into **every** template for its component
  (backend `.env.example`, deploy templates, `mcp*.example.json`), with a
  comment.
- The repository is public. Never commit keys, tokens, real emails or
  customer data; screenshots must not show credentials.

## Working agreements

- **Don't commit or push** unless the human asks you to.
- Planning documents can lag behind the code. **Verify against the code** before
  trusting a ✅ or ⬜ in a task list.
- Before building something, check whether TYDAL or a sibling product already
  has it (vault write ops, the SDK, the CLI). Several detours came from missing
  that.
- Run the affected test suite before reporting a change as done, and say so if
  you couldn't.
