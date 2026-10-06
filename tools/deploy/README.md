# TYDAL deploy scripts (development)

Convenience scripts to run a **development** instance of TYDAL. This is a *dev*
setup: your code is live — PHP changes apply on the next request and the React
frontend hot-reloads (Vite), so you don't rebuild to see edits. The one
exception is the queue worker, which caches code in memory; `reload.sh` restarts
it.

This folder holds only the **lifecycle of the stack**. Vault apps and the Full
Frame provisioning live in [`../clients/`](../README.md#clients--the-border), and
test data and load smoke live in [`../dev/`](../README.md#dev--data-and-smoke). See the
[tools index](../README.md) and the one-line
[quick reference](../../QUICK_REFERENCE.md).

## How a TYDAL deployment is organized

TYDAL runs as **three service tiers**. You choose where the first two run; the
third is always Docker. The scripts then generate a `backend/.env` that points
at the right hosts for your choices.

1. **AI** — embeddings, RAG and vision. Either **cloud** providers (Anthropic,
   OpenAI, Gemini, Jina, …) or local **Ollama**. Ollama runs on the **host**
   (uses the GPU — Metal on macOS) or in **Docker** (CPU only).
2. **Application** — PHP/nginx + the queue worker. Runs on the **host**
   (`artisan serve`) or in **Docker**.
3. **Backing services** — Elasticsearch (search/index), Tika (text extraction),
   MySQL, Redis. **Always Docker.**

Two arguments pick tiers 1 and 2; tier 3 is fixed:

```
configure.sh  <cloud | ollama-host | ollama-docker>   <host | docker>   [--infra=own|shared] [--name=NAME] [--url=URL]
              └────────── AI tier ──────────┘         └─ application ─┘ └──── backing services ────┘
```

The optional third choice is whose backing services these are: `--infra=own`
(default — this checkout runs them, as always) or `--infra=shared --name=NAME`
(use the ones another checkout on the same machine runs; NAME keeps the
database, indices, Redis keys, containers and ports apart). See
[DEPLOYMENT.md — Several installations on one server](../../DEPLOYMENT.md#several-installations-on-one-server).

For production or any layout the scripts don't cover, edit `docker-compose.yml`
and `backend/.env` directly — they remain the source of truth. The scripts only
automate the common dev cases; for production follow
[`DEPLOYMENT.md`](../../DEPLOYMENT.md).

## Quick start

```bash
tools/deploy/install.sh ollama-host host   # deps + build + write .env   <AI> <app>
tools/deploy/start.sh                       # terminal 1: bring up services + serve (blocks)
tools/deploy/first_install.sh               # terminal 2: seed DB + build search index
```

Frontend → <http://localhost:3005> · API → <http://localhost:8000> (a shared
installation: the offset ports `configure.sh` printed). The seeded
superadmin login is printed at the end of `first_install.sh`.

> **`start.sh` is the single entry point that brings the stack up** (it starts the
> Docker daemon + all services, then serves in the foreground). Run it **first**;
> everything else — `first_install`, `reindex`, `reload` — assumes the stack is up and
> just connects. Because `start` blocks, run `first_install` in a **second terminal**.
> Both tier args are **required** by `install.sh` and `configure.sh`.

## The two choices

| AI tier | What it uses |
|---|---|
| `cloud` | Cloud providers (Claude / Gemini / Jina / …) — fill the API keys in `backend/.env`. |
| `ollama-host` *(default; `ollama` alias)* | Local Ollama on the host — **GPU**. Recommended on macOS. |
| `ollama-docker` | Local Ollama in a container — **CPU only**. |

| Application tier | Where the app runs |
|---|---|
| `host` *(default)* | App + queue on the host (`artisan serve`); `.env` → `localhost`. |
| `docker` | App + queue in containers; `.env` → Docker network (`DB_HOST=mysql`, …). |

All six combinations work — e.g. `ollama-host docker` runs the app in Docker but
keeps Ollama on the host for GPU. `OLLAMA_HOST` is wired automatically for each
combination (host vs `host.docker.internal` vs the `ollama` service).

> ⚠️ **`ollama-docker` has no GPU** and is bounded by Docker Desktop's VM RAM, so
> vision models (`llama3.2-vision`, ~11 GiB) may not fit. Prefer `ollama-host`
> for local models — you can still keep the app in Docker (`ollama-host docker`).

### Docker-only hosts (no PHP/Node installed)

With the `docker` application tier the scripts need **only Docker** on the host:
`install.sh` runs composer/artisan inside the `app` container (bringing the
containers up first), and every npm step (`install.sh`, `start.sh`'s Vite,
`test.sh`'s JS suites) falls back to a disposable `node:22` container when the
host has no npm (`run_npm` in `../lib/tier.lib.sh`; override the image with
`TYDAL_NODE_IMAGE`). The Vite fallback publishes `-p 3005:3005` and points the
dev proxy at the backend via `TYDAL_BACKEND_URL` (default
`http://host.docker.internal:8000`). Note the container branch writes
Linux-native `node_modules` into the checkout; if you later install Node on
the host, `rm -rf node_modules` and reinstall.

## Switching later (no rebuild)

`configure.sh` only edits config — `backend/.env` plus the `docker-compose.yml`
service toggles — so you can change tiers any time without re-running
composer/npm. Like `install.sh`, **both tier args are required** and it **asks to
continue** unless you pass `-f` (it rewrites `.env` by default — backup saved,
secrets carried over; `--no-force-env` keeps an existing `.env` and just toggles
compose). After switching:

- run **`reload.sh`** (or re-run `start.sh`) so the worker picks up the new `.env`;
- if you changed the **embedding model/driver/dimensions**, run **`reindex.sh`** —
  old vectors are a different vector space. It's non-destructive (no DB reset);
  `configure.sh` prints this reminder when it detects the change.

## Scripts

Every script accepts `--help` and is path-independent (run from anywhere, or via
the root wrappers `./install.sh` / `./configure.sh` / `./start.sh`).

| Script | Purpose |
|---|---|
| `install.sh <ai> <app> [--infra=own\|shared] [--name=NAME] [--url=URL] [-f] [--no-force-env] [--fresh]` | One-time setup: install deps, build frontend, write `backend/.env` to match your tiers, recreate containers. Both tier args **required**; the infrastructure flags and `--url` are passed to `configure.sh`. Asks to continue (rewrites `.env`, restarts services) — `-f` skips the prompt. |
| `configure.sh <ai> <app> [--infra=own\|shared] [--name=NAME] [-f] [--no-force-env]` | Switch tiers without rebuilding: rewrites `backend/.env` (default; backup + secrets kept) and toggles compose services. Config only. Both tier args **required**; asks to continue unless `-f`. `--infra=shared --name=NAME` (#23) also writes the root `.env` (Compose project `tydal_NAME`, containers `tydal_NAME_*`, offset ports, the infrastructure stack's network), points `backend/.env` at database/user `tydal_NAME`, index prefix `NAME_` and its own Redis prefix/DBs, and runs `provision-shared.sh`. Extra shared flags: `--slot=N` (1–7: ports `8000/3005 + 100·N`, Redis DBs `2N/2N+1`; default: kept, else the lowest free one; a taken slot is refused), `--infra-stack=S` (default `tydal`), `--infra-network=NET`, `--no-provision`. `--infra=own` on a formerly shared checkout retires the root `.env`. `--name` alone implies `--infra=shared`; `--check` validates (flags, slot, a clash with another checkout's `tydal_*` containers in own mode), prints the plan and writes nothing — `install.sh` runs it before its prompt. `--url=URL` (own or shared): the public address — `APP_URL`, `SANCTUM_STATEFUL_DOMAINS`, `VITE_API_BASE_URL`, kept as `TYDAL_PUBLIC_URL` on later runs; `--url=` drops it. |
| `provision-shared.sh [--dry-run]` | Shared installations only: create `tydal_NAME`, `tydal_NAME_test` and user `tydal_NAME` (privileges on those two only) in the infrastructure's MySQL (`<infra-stack>_mysql`, root password from that container's `MYSQL_ROOT_PASSWORD`). Idempotent — also re-syncs the password with `backend/.env`. `--dry-run` prints the SQL. Run by `configure.sh`; run it by hand if the infrastructure wasn't up then. |
| `first_install.sh [-f] [--collections=LIST]` | **DESTRUCTIVE, dev only.** Wipes every `{prefix}tydal_*`/`{prefix}vault_*` Elasticsearch index — prefix = `ELASTICSEARCH_INDEX_PREFIX`; with it empty, that includes any other unprefixed installation on the same cluster (`search:wipe-indices` — `migrate:fresh` never touches ES, so this is what makes the reset actually complete), then `migrate:fresh` (drops the DB) and unconditionally seeds the minimal usable baseline (superadmin, organization, default workspace, system collection schemes) — TYDAL works with zero collections at that point. Then, optionally, asks which starter collection(s) to also create (menu built from `schema:starter-options`, default "0) None" — see [`docs/CLI.md`](../../docs/CLI.md)); each one provisions its own ES index the moment it's created. `--collections=multimedia,documents` skips the prompt and creates those. Requires the stack up (run `start.sh` first); does not start services. Asks to continue — `-f` skips (defaults to no starter collection, same minimal baseline). On a shared installation it resets only that installation: database `tydal_NAME`, indices `NAME_*`, and the `tydal_NAME_*` keys in its Redis DBs. |
| `start.sh` | *Serve.* Brings the stack up; on `host` runs `artisan serve` + queue + scheduler (`schedule:work`) + Vite, on `docker` runs only Vite (app/queue/scheduler are containers). Prints a notice that the scheduler daemon must be running for the scheduled maintenance, and warns if the `tydal_scheduler` container (`tydal_NAME_scheduler` on a shared installation) isn't. Ctrl-C stops host processes; re-running it reloads already-running Docker app/queue containers so code and `.env` changes take effect. Names and ports come from the root `.env` (default `tydal_*`, `:8000`, Vite `tydal_vite` `:3005`); a shared installation starts only its app services and refuses to run until the infrastructure stack is up. |
| `reload.sh` | Apply code/`.env` changes by restarting the worker (`docker compose restart` / `queue:restart`). Non-destructive. |
| `reindex.sh` | Rebuild the Elasticsearch index + embeddings only; leaves the DB intact. Requires the stack up (run `start.sh` first). Use after an embedding-model change. |
| `install_mcp.sh` | Build `@tydal/org-mcp` and `@tydal/vault-mcp` (`dist/index.js`) for use with an MCP client (Claude Desktop, Claude Code, Cursor, …) — see `mcp.example.json` / `mcp.docker.example.json` and `docs/CONNECTING_MCP_CLIENTS.md`. Not run by `install.sh` (most devs don't need either MCP server); run this once you do, and again after pulling changes to `org-mcp/` or `vault-mcp/`. |
| `test.sh [--backend-only] [-- PEST_ARGS…]` | Run the backend Pest suite against the separate **`tydal_test`** database (created on first run; your dev `tydal` database is **not** touched; a shared installation uses `tydal_NAME_test` and index prefix `NAME_test_`, passed to `tests/bootstrap.php` as `TYDAL_TEST_DB_DATABASE` / `TYDAL_TEST_INDEX_PREFIX`), then the `@tydal/client`, `@tydal/org-mcp` and `@tydal/vault-mcp` Vitest suites. Every suite runs; the script exits 1 if any failed. `-- --filter=Name` narrows Pest. Requires the stack up. |

`-f/--force` skips the confirmation in `install.sh`/`first_install.sh`; non-interactive
shells (CI) must pass it.

## AI setup notes

- **`ollama-host`** — you run Ollama, the scripts don't:
  ```bash
  brew install ollama && brew services start ollama
  ollama pull mxbai-embed-large    # embeddings (1024-dim, matches EMBEDDING_DIMENSIONS)
  ollama pull llama3.2 && ollama pull llama3.2-vision   # chat + vision
  ```
- **`ollama-docker`** — `configure.sh` enables the `ollama` service. Once it is
  running, pull the embedding, text, and vision models (`tydal_ollama` by
  default; a shared installation uses the infrastructure stack's, e.g.
  `tydal_ollama` of the main checkout):
  ```bash
  docker exec tydal_ollama ollama pull mxbai-embed-large
  docker exec tydal_ollama ollama pull llama3.2
  docker exec tydal_ollama ollama pull llama3.2-vision
  ```
- **`cloud`** — fill the keys in `backend/.env` (`ANTHROPIC_AUTH_TOKEN`,
  `GEMINI_API_KEY`, `JINA_API_KEY`, …).

The two templates live in [`env.templates/`](env.templates) (`backend.env.ollama`,
`backend.env.aicloud`). **Embedding dimensions are coupled to the model** — if you
change it, update `EMBEDDING_DIMENSIONS` and run `reindex.sh`.
