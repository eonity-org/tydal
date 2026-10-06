# Quick reference — scripts & commands

One line per thing you actually run: the **docker-tier** form, the **native
(host-tier)** form, what it gives you, and what must already be true. Contracts
and flags live in [tools/README.md](tools/README.md) (scripts) and
[docs/CLI.md](docs/CLI.md) (artisan). Paths are relative to `tydal/`.

This is the **lookup** for a running dev install. For installing, configuring
`.env`, AI providers, production (nginx, Supervisor, cron) and troubleshooting,
read [DEPLOYMENT.md](DEPLOYMENT.md), the **walkthrough**.

**Which form?** Your tier is set by `DB_HOST` in `backend/.env`: `mysql` means
docker, `localhost` means native. Scripts under `tools/` detect it themselves, so
they're the same command on both tiers. For artisan, the docker form needs
`-w /var/www/html`; without it you get `Could not open input file: artisan`.

**Preconditions used below:** **stack** = services up (`./start.sh`) ·
**queue** = queue worker running (docker: the `tydal_queue` container; native:
started by `./start.sh`) · **scheduler** = scheduler daemon running (docker:
`tydal_scheduler`; native: started by `./start.sh`) · **seeded** = `first_install.sh` has run ·
**⚠** = destructive.

Tip for the docker tier: `alias art='docker exec -w /var/www/html tydal_app php artisan'`.

**Container names, ports, test DB.** `tydal_app`, `tydal_queue`,
`tydal_scheduler`, `tydal_vite`, app `:8000`, Vite `:3005` and the `tydal_test`
database are the defaults (own infrastructure). A shared-infrastructure
installation (`configure.sh … --infra=shared --name=NAME`) uses `tydal_NAME_app`
etc. (`TYDAL_STACK` in the root `.env`), its offset ports (`TYDAL_HTTP_PORT`,
`TYDAL_VITE_PORT`) and `tydal_NAME_test` — substitute them in the commands below.
See [DEPLOYMENT.md](DEPLOYMENT.md#several-installations-on-one-server).

## Stack lifecycle — scripts (same on both tiers)

| Command | Provides | Needs |
|---|---|---|
| `./install.sh <cloud\|ollama-host\|ollama-docker> <host\|docker> [--infra=own\|shared] [--name=NAME]` | One-time: deps, frontend build, `backend/.env` for your tiers, containers recreated | Docker (+ PHP/Node only on the host tier) |
| `./configure.sh <ai> <app> [--infra=own\|shared] [--name=NAME] [--url=URL]` | Switch tiers or AI backend: rewrites `.env` (backup kept), toggles compose services; no rebuild. `--infra=shared --name=NAME`: use another checkout's MySQL/ES/Redis/Tika (own DB, index prefix, Redis prefix, containers, ports). `--url=URL`: public address (APP_URL + SPA API base, kept on re-runs) | `install.sh` run once; shared: the infrastructure stack up |
| `tools/deploy/provision-shared.sh [--dry-run]` | Shared installation: its database, test database and user in the shared MySQL (idempotent; `configure.sh` runs it) | infrastructure stack up |
| `./start.sh` | Brings every service up. Host tier: serve + queue + Vite, blocking. Docker tier: Vite only. Re-run to reload containers | Docker running |
| `tools/deploy/first_install.sh [--collections=multimedia]` | ⚠ Wipes ES + DB, seeds superadmin, `tydal` org, default workspace, system schemes (+ starter collections); prints the superadmin login. Shared installation: only its own DB, `NAME_*` indices and Redis keys | stack |
| `tools/deploy/reload.sh` | Worker (and docker app) pick up code/`.env` changes; no data loss | stack |
| `tools/deploy/reindex.sh` | Recreate ES indexes + reindex + re-embed; DB untouched (after an embedding-model change) | stack, queue |
| `tools/deploy/install_mcp.sh` | Builds `org-mcp/dist` + `vault-mcp/dist` for an MCP client | npm or Docker |
| `tools/deploy/test.sh [--backend-only] [-- --filter=Name]` | Pest on `tydal_test` (shared: `tydal_NAME_test`; dev DB untouched) + client/org-mcp/vault-mcp Vitest; exit 1 on any failure | stack |

## Clients at the border (same on both tiers)

| Command | Provides | Needs |
|---|---|---|
| `./clients.sh [up\|stop\|down\|status] [gallery\|obsidian\|aity]` | Vault renderer apps at :3010 / :3011 / :3012 (`?vault=org/slug`) | stack |
| `tools/clients/fullframe.sh setup --org=SLUG --index=tydal_multimedia [--language=es]` | Once per org: `photo_exhibition` scheme + Photos collection on a shared index, in the language you pick (asked if omitted) | stack, org exists |
| `tools/clients/fullframe.sh create --org=SLUG --name="…" --curator=EMAIL` | Once per exhibition: workspace, private gallery vault, read + write keys, curator account (asks the new curator's password; empty = generated); **prints them once** | `setup` done |
| `cd ../fullframe && docker compose up -d --build` | FullFrame at :3020 (studio `/admin`); native dev: `./dev.sh dev` | `fullframe/.env` with `ADMIN_PASSWORD`, `FULLFRAME_ENCRYPTION_KEY` |

## Dev data & smoke (same on both tiers)

| Command | Provides | Needs |
|---|---|---|
| `./seed-vault.sh <folder> [slug]` | Real-pipeline import of a folder of PDFs/images into a public mixed vault | stack, queue, seeded + a collection, `jq` |
| `tools/dev/loadtest.sh <org/vault> [-n 200 -c 20] [-k tvk_…]` | Latency percentiles + error rate on the vault boundary | stack, a public or keyed vault |

## Search index (artisan)

| Docker | Native | Provides | Needs |
|---|---|---|---|
| `docker exec -w /var/www/html tydal_app php artisan search:setup-indices [--recreate]` | `cd backend && php artisan search:setup-indices [--recreate]` | Create/update ES mappings from the schemes (`--recreate` ⚠ drops first) | stack |
| `docker exec -w /var/www/html tydal_app php artisan search:reindex [--collection=ID\|--vault=SLUG\|all]` | `cd backend && php artisan search:reindex […]` | Rebuild ES docs from MySQL (or one vault's projection) | stack |
| `docker exec -w /var/www/html tydal_app php artisan search:reconcile [--fix]` | `cd backend && php artisan search:reconcile [--fix]` | Report (or repair) MySQL↔ES drift, including missing meta chunks | stack (+ queue for `--fix`) |
| `docker exec -w /var/www/html tydal_app php artisan search:embed [--collection=ID]` | `cd backend && php artisan search:embed […]` | Queue embedding jobs for extracted text | stack, queue, AI backend |
| `docker exec -w /var/www/html tydal_app php artisan search:indexes [--check]` | `cd backend && php artisan search:indexes [--check]` | Which schemes share each index + merged vocabulary (`--check`: exit 1 on conflict) | stack |
| `docker exec -w /var/www/html tydal_app php artisan search:wipe-indices --force` | `cd backend && php artisan search:wipe-indices --force` | ⚠ Delete every `tydal_*`/`vault_*` index (dev only) | stack |

## Integrity checks (artisan — exit 1 on findings, CI-able)

| Docker | Native | Provides | Needs |
|---|---|---|---|
| `docker exec -w /var/www/html tydal_app php artisan schema:validate` | `cd backend && php artisan schema:validate` | Scheme fields vs the field contract | stack |
| `docker exec -w /var/www/html tydal_app php artisan vault:validate-policy` | `cd backend && php artisan vault:validate-policy` | Vault exposure policies vs the capability vocabulary | stack |
| `docker exec -w /var/www/html tydal_app php artisan vault:reslug-files [--dry-run]` | `cd backend && php artisan vault:reslug-files [--dry-run]` | File-link slugs rewritten to the current rule (codes by default, no uploaded filenames in `/v/` addresses) | stack |
| `docker exec -w /var/www/html tydal_app php artisan resources:audit-roles [--fix]` | `cd backend && php artisan resources:audit-roles [--fix]` | File-role composition invariants (single canonical, snapshots) | stack |

## Users, organizations, exhibitions, MCP, graph (artisan)

| Docker | Native | Provides | Needs |
|---|---|---|---|
| `docker exec -it -w /var/www/html tydal_app php artisan user:create --email=… [--org=SLUG --role=editor] [--superadmin]` | `cd backend && php artisan user:create …` | A user account (password asked hidden, or generated and shown once) | stack |
| `docker exec -w /var/www/html tydal_app php artisan org:create --name="…" [--owner=EMAIL]` | `cd backend && php artisan org:create …` | An organization + its default workspace, owned by an existing user (default: oldest platform admin) | stack, the owner exists |
| `docker exec -w /var/www/html tydal_app php artisan exhibitions:setup --org=SLUG [--index=…]` | `cd backend && php artisan exhibitions:setup …` | Same as `fullframe.sh setup` | stack, org (`org:create`) |
| `docker exec -w /var/www/html tydal_app php artisan exhibitions:create --org=SLUG --name="…" [--curator=…]` | `cd backend && php artisan exhibitions:create …` | Same as `fullframe.sh create` | `setup` done |
| `docker exec -w /var/www/html tydal_app php artisan mcp:token --org=SLUG` | `cd backend && php artisan mcp:token --org=SLUG` | Scoped API key on a machine user for `org-mcp` | seeded |
| `docker exec -w /var/www/html tydal_app php artisan graph:rebuild --org=SLUG --tags --semantic` | `cd backend && php artisan graph:rebuild …` | `RELATED` edges from tag co-occurrence / embeddings | stack (embeddings for `--semantic`) |
| `docker exec -w /var/www/html tydal_app php artisan graph:clusters --org=SLUG` | `cd backend && php artisan graph:clusters --org=SLUG` | Read-only view of connected components | `graph:rebuild` |
| `docker exec -w /var/www/html tydal_app php artisan graph:materialize --org=SLUG` | `cd backend && php artisan graph:materialize --org=SLUG` | Clusters become auto-named `cluster` vaults | `graph:rebuild`, AI backend |

## Maintenance (artisan — all take `--dry-run`)

| Docker | Native | Provides | Needs |
|---|---|---|---|
| `docker compose up -d scheduler` (container `tydal_scheduler`; `docker logs -f tydal_scheduler`) | `cd backend && php artisan schedule:work` (`./start.sh` already runs it) | The scheduler daemon: runs the jobs below on time. `start.sh` starts it and warns if it isn't running | stack |
| `docker exec -w /var/www/html tydal_app php artisan schedule:list` | `cd backend && php artisan schedule:list` | What is scheduled and when it next runs | — |
| `docker exec -w /var/www/html tydal_app php artisan resource:prune` | `cd backend && php artisan resource:prune` | ⚠ Hard-delete drafts > 24 h and soft-deletes > 30 d (scheduled daily) | stack |
| `docker exec -w /var/www/html tydal_app php artisan resources:purge-drafts` | `cd backend && php artisan resources:purge-drafts` | ⚠ Hard-delete abandoned create-mode drafts > 1 h (scheduled hourly) | stack |
| `docker exec -w /var/www/html tydal_app php artisan files:purge-uncommitted` | `cd backend && php artisan files:purge-uncommitted` | ⚠ Hard-delete files from crashed edit sessions > 24 h (scheduled daily) | stack |
| `docker exec -w /var/www/html tydal_app php artisan aity:purge-batches [--days=N] [--dry-run]` | `cd backend && php artisan aity:purge-batches` | Delete AiTy Review batches reviewed/finished more than `AITY_BATCH_RETENTION_DAYS` (30) days ago; resources kept (scheduled daily) | stack |
| `docker exec -w /var/www/html tydal_app php artisan aity:purge-stale` | `cd backend && php artisan aity:purge-stale` | Mark stuck AITY states failed, purge their Redis jobs (manual) | stack |

## Debugging & inspection

| Docker | Native | Provides | Needs |
|---|---|---|---|
| `docker logs -f tydal_queue` | the `./start.sh` terminal | Queue worker output (extraction, embedding, AITY) | stack |
| `docker exec -w /var/www/html tydal_app tail -f storage/logs/laravel.log` | `tail -f backend/storage/logs/laravel.log` | Application log (AI calls: `storage/logs/ai-*.log`) | — |
| `docker exec -it -w /var/www/html tydal_app php artisan tinker` | `cd backend && php artisan tinker` | REPL with the app booted | stack |
| `docker exec -w /var/www/html tydal_app php artisan debug:mappings <collection-slug>` | `cd backend && php artisan debug:mappings <slug>` | A collection's scheme fields vs its ES mapping (`debug:collection`, `debug:db [table]` alike) | stack |
| `docker exec -w /var/www/html tydal_app php artisan route:list --path=api/v1` | `cd backend && php artisan route:list --path=api/v1` | The live API surface | — |
| `docker exec -it tydal_mysql mysql -utydal -p tydal` | same (MySQL is always a container) | SQL shell on the dev DB | stack |
| `curl -s 'localhost:9200/_cat/indices?v'` | same | ES indices, doc counts, health (Kibana: :5601) | stack |
