# TYDAL tools

Shell scripts that run, seed, test and connect a **development** TYDAL. Every
script is path-independent (run it from anywhere), accepts `--help`, and is
**tier-aware**: it reads `DB_HOST` from `backend/.env` and runs artisan inside
the `app` container (docker tier) or on the host (host tier). So you never have to type
`docker exec -w /var/www/html tydal_app …` yourself.

For a one-line-per-command cheat sheet (scripts and artisan, docker and native
forms, preconditions), see **[docs/QUICK_REFERENCE.md](../docs/QUICK_REFERENCE.md)**.

## Layout

| Folder | What belongs here | Scripts |
|---|---|---|
| [`deploy/`](deploy/README.md) | **Lifecycle of the TYDAL stack itself**: install, choose tiers, start, reset, reload, reindex, build the MCP servers, test. | `install.sh` `configure.sh` `start.sh` `first_install.sh` `reload.sh` `reindex.sh` `install_mcp.sh` `test.sh` |
| [`clients/`](#clients--the-border) | **The border**: things that run *outside* TYDAL and consume it through a vault (vault renderer apps, products such as Full Frame), plus provisioning shortcuts for them. | `vault-apps.sh` `fullframe.sh` |
| [`dev/`](#dev--data-and-smoke) | **Dev data and ops smoke**: fill a vault with real files, load-test the boundary. | `seed-vault.sh` `loadtest.sh` |
| `lib/` | Shared shell helpers, *sourced* and never run directly: `tier.lib.sh` (`detect_infra`, `run_npm`). | — |

Root wrappers (`tydal/*.sh`) keep the short forms working:
`./install.sh` `./configure.sh` `./start.sh` → `deploy/`, `./clients.sh` →
`clients/vault-apps.sh`, `./seed-vault.sh` → `dev/seed-vault.sh`.

**Rule of thumb for a new script.** If it manages TYDAL's own services or data
lifecycle, it goes in `deploy/`. If it serves or provisions something that talks
to TYDAL through a vault, it goes in `clients/`. If it produces test data or measures
the running system, it goes in `dev/`. Artisan commands stay in
`backend/app/Console/Commands` and are documented in [docs/CLI.md](../docs/CLI.md).
A script here only wraps them when the wrapper saves real friction (for example
tier-awareness).

## Everyday order

```bash
./install.sh <cloud|ollama-host|ollama-docker> <host|docker>   # once
./start.sh                                   # terminal 1 — brings the stack up (blocks on host tier)
tools/deploy/first_install.sh                # terminal 2 — DESTRUCTIVE reset + minimal seed
tools/deploy/install_mcp.sh                  # only if you use an MCP client
```

Details, flags and tier explanations: [deploy/README.md](deploy/README.md).

## clients — the border

TYDAL's products and renderers are separate programs that reach TYDAL only
through a vault (`/v/{org}/{slug}` or `/h/{hash}`, with vault keys). The scripts
here start them or provision what they need. Nothing in this folder changes how TYDAL
itself runs.

| Script | Purpose | Needs |
|---|---|---|
| `vault-apps.sh [up\|stop\|down\|status] [gallery] [obsidian] [aity]` (root: `./clients.sh`) | Run the `vaults/*` renderer apps in node containers: gallery :3010, obsidian :3011, aity :3012. `up` creates or restarts them and waits until each answers; a restart re-runs the `@tydal/client` build. | Docker, stack up |
| `fullframe.sh setup --org=SLUG [--index=tydal_multimedia]` | Once per organization: `photo_exhibition` scheme, its index (or a shared one), and the **Photos** collection. Safe to re-run. | Stack up, existing org |
| `fullframe.sh create --org=SLUG --name="…" [--curator=EMAIL …]` | Once per exhibition: workspace, private gallery vault, read + write keys, and optionally the curator's TYDAL account. Prints the URL, keys and new password **once**. | `setup` done for the org |

`fullframe.sh` passes every option straight to `artisan exhibitions:setup|create`
(full contract: [docs/CLI.md → Photo exhibitions](../docs/CLI.md#photo-exhibitions-full-frame)).
Full Frame itself lives in its own repo (`../fullframe`, `docker compose up -d`).
After `create`, the curator signs into its studio (`http://localhost:3020/admin`)
with their TYDAL account and pastes the URL and keys into *Connect an exhibition*.

## dev — data and smoke

| Script | Purpose | Needs |
|---|---|---|
| `seed-vault.sh <folder> [slug]` (root: `./seed-vault.sh`) | Go through the **real API pipeline** as superadmin. For each PDF or image in `<folder>` it creates a resource (`state: live`) and a canonical upload, then a `<slug>-ws` workspace and a **public**, mixed-purpose vault over it. It waits for enrichment and rebuilds the vault index. | Stack + queue worker, `jq`, `TYDAL_SUPERADMIN_PASSWORD` (read from `backend/.env`), at least one collection |
| `loadtest.sh <org/vault> [-n 200] [-c 20] [-k tvk_…]` | Concurrent load smoke on the public vault boundary (meta, resources, keyword and semantic search): error rate, req/s, p50/p95/p99. On-demand, not a CI gate. The CI perf guard is `VaultIndexQueryBudgetTest`. | Stack up, a public (or keyed) vault |

## Removed

These predated the current resource/vault model and had broken against the API
(`visibility` instead of the required `state`, calls to endpoints that no longer
exist). They are gone; git history keeps them.

| Path | Use instead |
|---|---|
| `deploy/populate.sh` (demo resource) | `dev/seed-vault.sh` |
| `deploy/query.sh` (read-endpoint dump) | the SPA, `artisan search:reconcile`, `debug:*` |
| `bulk_uploader/` (Python folder uploader) | `dev/seed-vault.sh` (dev); vault `ingest` via `@tydal/client` or `vault-mcp` (products). Its accept/tags phases live server-side in `AutoApprovalService` (`POST /aity/auto-approve`). |
