# TYDAL Deployment Guide

This guide covers running TYDAL — **the typed Digital Asset Layer** — end to
end, in two modes:

- **[Development](#development-setup)** — local workstation, fast iteration,
  command by command: install → configure → start → seed → (optionally) plug
  in the two MCP servers, plus the commands you'll run again later and the
  common gotchas.
- **[Production](#production-deployment)** — nginx + PHP-FPM on a server/VM.

TYDAL is a monorepo with two independent subprojects, plus two MCP servers:

- `backend/` — Laravel 12 REST API (PHP 8.3+)
- `frontend/` — React 18 + TypeScript SPA (built with Vite)
- `org-mcp/` / `vault-mcp/` — the two MCP servers (see
  [Optional: the MCP servers](#optional-the-mcp-servers))

For the architecture behind these, see
[`ARCHITECTURE_AND_ROADMAP.md`](docs/architecture/ARCHITECTURE_AND_ROADMAP.md).

---

## Prerequisites

| Component | Version | Required | Notes |
|-----------|---------|----------|-------|
| Docker | — | ✅ | always required — it's the backing-services tier (MySQL/Elasticsearch/Redis/Tika) |
| PHP | 8.3+ | ⬜ | with `pdo_mysql`, `redis`/`predis`, `gd`/`imagick`, `zip`, `bcmath` — only if the app tier runs on the **host** |
| Composer | 2.x | ⬜ | same condition as PHP |
| Node.js | 20+ | ⬜ | for building the frontend — same condition |
| Ollama / Whisper | — | ⬜ | optional local LLM / audio transcription |

MySQL, Redis, Elasticsearch, and (optionally) Tika always run in Docker in
development. In production you typically run these as managed or
containerized services on dedicated hosts. On a **Docker-only host** with
neither PHP nor Node installed, the dev scripts still work — see
[Docker-only hosts](#docker-only-hosts-no-phpnode-installed) below.

---

## Configuration reference

Both subprojects are configured through `.env` files copied from templates.
The convenience scripts (below) write these for you from
[`tools/deploy/env.templates/`](tools/deploy/env.templates); this section is
the reference for what's in them.

### Backend (`backend/.env`)

Key groups (see the template for the full list):

- **App** — `APP_KEY` (generated, never commit), `APP_ENV`, `APP_DEBUG`, `APP_URL`.
- **Database** — `DB_HOST`, `DB_PORT` (3306), `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- **Redis** — `REDIS_HOST`, `REDIS_PORT` (6379); `QUEUE_CONNECTION=redis`, `CACHE_DRIVER=redis`, `SESSION_DRIVER=redis`.
- **Elasticsearch** — `ELASTICSEARCH_HOST` (`http://localhost:9200`), optional auth/shards/replicas.
- **Storage** — `FILESYSTEM_DISK`/`MEDIA_DISK`; local by default, S3/MinIO available via the `AWS_*` block.
- **Tika** — `TIKA_HOST` (`http://localhost:9998`).
- **LLM / embeddings** — `LLM_TEXT_DRIVER`, `EMBEDDING_DRIVER`, `EMBEDDING_DIMENSIONS`, plus the provider key blocks (Claude/Gemini/Jina/OpenAI/Ollama). All optional; features degrade gracefully when unset. See [Embedding dimensions](#embedding-dimensions-are-coupled-to-the-model) below before changing these on an existing install, and [Claude vs. Z.ai](#three-llm_text_driver-values-for-claude-and-zai) if you're using a Z.ai key.
- **Sanctum** — `SANCTUM_STATEFUL_DOMAINS`, `SANCTUM_EXPIRATION`.
- **Superadmin seeding** — see below.

#### Three LLM_TEXT_DRIVER values for Claude and Z.ai

Three ways to reach a Claude-family or Z.ai model, and they aren't
interchangeable by swapping just `ANTHROPIC_BASE_URL` — the request shape
differs between Anthropic's and Z.ai's native APIs, so the wrong pairing
404s.

| `LLM_TEXT_DRIVER` | Talks to | Model that actually answers |
|---|---|---|
| `claude` | Real Anthropic (`ANTHROPIC_BASE_URL=https://api.anthropic.com`, a real `sk-ant-…` key) | Genuine Claude |
| `anthropicproxy` | Z.ai's Anthropic-compatible endpoint (`ANTHROPIC_BASE_URL=https://api.z.ai/api/anthropic`, a Z.ai key in `ANTHROPIC_AUTH_TOKEN`) | Z.ai's own model (GLM) — `CLAUDE_MODEL`'s value is sent but doesn't select it |
| `zai` | Z.ai's native API (`ZAI_BASE_URL=https://api.z.ai/api/coding/paas/v4`, `ZAI_MODEL=<real id, e.g. glm-5.3>`) | Z.ai's own model (GLM), honestly named this time |

`claude` and `anthropicproxy` are literally the same driver class — it
auto-detects proxy-vs-native from the URL and adjusts headers/request
options accordingly. `anthropicproxy` exists purely so that fact is visible
in `LLM_TEXT_DRIVER` itself, instead of only discoverable by also reading
`ANTHROPIC_BASE_URL`. `zai` is a genuinely different driver (OpenAI-shaped
`/chat/completions`, not `/v1/messages`) — pointing `ANTHROPIC_BASE_URL` at
Z.ai's native endpoint while still on `LLM_TEXT_DRIVER=claude` (or
`anthropicproxy`) is the classic mistake here: `HTTP 404` at a path like
`/v4/v1/messages`, since the driver appends `/v1/messages` regardless of
what the base URL actually serves.

#### Superadmin credentials (`TYDAL_SUPERADMIN_*`)

The first superadmin account is created by the seeders, **never hardcoded**:

```env
TYDAL_SUPERADMIN_EMAIL=superadmin@tydal.test
TYDAL_SUPERADMIN_PASSWORD=
```

- Set `TYDAL_SUPERADMIN_PASSWORD` to choose the password explicitly.
- **Leave it blank** and the seeder generates a strong random password and
  **prints it once** in the seed output — copy it from there. It is not
  recoverable later (only the bcrypt hash is stored).

All three seeders that create the superadmin (`ProductionSeeder`,
`MinimalSeeder`, `SuperadminSeeder`) read these same variables.

> ⚠️ Never commit real secrets (API keys, passwords) into the `.env` templates.
> Treat the templates as blanks to be filled per environment.

### Frontend (`frontend/.env`)

```env
VITE_API_BASE_URL=http://localhost:8000/api/v1   # must point at the backend API
VITE_SHOW_DAM_ORGANIZATIONS=true
```

`VITE_*` values are **baked in at build time** — changing them requires a
rebuild (`npm run build`), not just a restart.

---

## Development setup

### 1. Install

A dev instance has **three service tiers**: **AI** (`cloud`, or
`ollama-host`/`ollama-docker` for local models), **application** (`host` or
`docker` — PHP/nginx + queue), and **backing services** (Elasticsearch, Tika,
MySQL, Redis — always Docker). You pick the first two; the scripts write the
matching `backend/.env`:

```bash
./install.sh <ai-tier> <app-tier>
```

| `<ai-tier>` | Meaning |
|---|---|
| `ollama-host` *(default, alias `ollama`)* | Local Ollama on the **host** — GPU (Metal on macOS). Recommended for local models. |
| `ollama-docker` | Local Ollama in a **container** — CPU only, bounded by Docker Desktop's VM RAM. Vision models (~11 GiB) may not fit. |
| `cloud` | Cloud providers (Anthropic/Gemini/Jina/OpenAI/…) — fill in API keys next. |

| `<app-tier>` | Meaning |
|---|---|
| `host` *(default)* | PHP/queue run on the host (`artisan serve`). |
| `docker` | PHP/queue run in containers. |

Example — cloud AI, app on the host: `./install.sh cloud host`. Both args are
**required**; run `./install.sh --help` for the rest.

This installs deps, builds the frontend, and **writes `backend/.env`** from a
template matching your tiers (a timestamped backup is kept; existing secrets
carry over on a re-run). Pass `-f` to skip the confirmation prompt (required
in CI/non-interactive shells); `--no-force-env` to keep an existing `.env` and
only toggle Docker Compose services. `frontend/.env` is created only when
missing.

(`./install.sh` / `./start.sh` / `./configure.sh` at the repo root are thin
wrappers over [`tools/deploy/`](tools/deploy/README.md); `./clients.sh` and
`./seed-vault.sh` wrap [`tools/clients/`](tools/README.md#clients--the-border)
and [`tools/dev/`](tools/README.md#dev--data-and-smoke). The
[tools index](tools/README.md) has the full contract for every script and flag,
and the [quick reference](docs/QUICK_REFERENCE.md) has one line per command.)

### 2. Fill in `.env`

`install.sh` already wrote `backend/.env` from a template — open it and check
two things (see [Configuration reference](#configuration-reference) above for
everything else): the [superadmin credentials](#superadmin-credentials-tydal_superadmin_) block,
and, **only if you chose `cloud`**, the provider keys:

```env
ANTHROPIC_AUTH_TOKEN=      # or ANTHROPIC_API_KEY — AUTH_TOKEN wins if both are set, and needs ANTHROPIC_BASE_URL
GEMINI_API_KEY=
JINA_API_KEY=              # if EMBEDDING_DRIVER=jina
OPENAI_API_KEY=            # optional: vision / Whisper fallback
```

If you chose `ollama-host`/`ollama-docker` instead, no keys are needed, but
you do need the models pulled — see [AI provider setup](#ai-provider-setup)
below, **before** step 4, or enrichment will just fail quietly on missing
models.

### 3. Start the stack

```bash
./start.sh
```

**This is the one entry point that brings everything up** — Docker daemon +
all backing services, then serves in the foreground (`artisan serve` + queue
worker + Vite on the `host` app tier; just Vite if you chose `docker`). It
**blocks** — leave it running in terminal 1. Everything below assumes it's up
and just connects.

### 4. First-time seed (terminal 2)

```bash
tools/deploy/first_install.sh
```

**Destructive, dev-only** — wipes every ES index, then `migrate:fresh`
(drops and recreates the DB), then seeds the minimal usable baseline:
superadmin, one organization, a default workspace, and the system collection
schemes (multimedia/documents/general). TYDAL is fully usable at that point
with **zero collections**.

It then interactively asks which starter collection(s) to also create
(default: none); each one it creates provisions its own ES index immediately.
Skip the prompt with:

```bash
tools/deploy/first_install.sh --collections=multimedia,documents
tools/deploy/first_install.sh -f                      # non-interactive, no starter collections
```

(`php artisan schema:starter-options` lists every choice available.) **The
superadmin login prints at the end of this command** — copy it now if you
left the password blank.

### 5. Verify

| Service | URL |
|---------|-----|
| Frontend | http://localhost:3005 |
| Backend API | http://localhost:8000/api/v1 |
| Elasticsearch | http://localhost:9200 |
| Kibana (index inspection) | http://localhost:5601 |
| Tika | http://localhost:9998 |

Log in with the credentials from step 4.

> The queue worker is **not optional** for a working catalogue: resources are
> indexed into Elasticsearch and have their text extracted asynchronously.
> Without a running worker, newly created/updated resources won't appear
> until you run `php artisan search:reindex` manually. `start.sh` already
> runs one on the `host` app tier; on `docker`, check `docker compose ps`.

### Switching tiers later (no rebuild)

**Configuration is split from installation.** `install.sh` delegates `.env`
setup to `configure.sh`, which only touches `.env` files and Docker Compose
service toggles — so you can switch AI backend (ollama ↔ cloud) **without**
re-running composer/npm/build:

```bash
./configure.sh cloud host -f              # switch to cloud, then re-run ./start.sh
./configure.sh ollama-host host -f        # switch back to local Ollama (GPU)
```

Both tier args are required; it carries over secrets (`APP_KEY`,
`TYDAL_SUPERADMIN_PASSWORD`, all API keys) and asks to continue unless you
pass `-f`. After switching, run `tools/deploy/reload.sh` (or re-run
`start.sh`) so the worker picks up the new `.env`; if you changed the
**embedding model/driver/dimensions**, also run `tools/deploy/reindex.sh` —
see [Embedding dimensions](#embedding-dimensions-are-coupled-to-the-model).

### AI provider setup

Only needed once, before seeding real content (uploads enrich asynchronously
via the queue worker — nothing crashes if you skip this, enrichment just
silently produces nothing).

**`ollama-host`** (you run Ollama; the scripts don't):
```bash
brew install ollama && brew services start ollama
ollama pull mxbai-embed-large              # embeddings — 1024-dim, matches EMBEDDING_DIMENSIONS
ollama pull llama3.2 llama3.2-vision       # chat + vision
```

**`ollama-docker`** (`configure.sh` already enabled the `ollama` service):
```bash
docker exec tydal_ollama ollama pull mxbai-embed-large
docker exec tydal_ollama ollama pull llama3.2
docker exec tydal_ollama ollama pull llama3.2-vision
```

**`cloud`** — just the `.env` keys from step 2; nothing to pull.

### Everyday commands (after first install)

```bash
tools/deploy/reload.sh                    # picked up an .env/code change: restart the queue worker
tools/deploy/reindex.sh                   # rebuild ES + embeddings only, DB untouched (after an embedding-model change)
tools/deploy/test.sh                      # Pest (on tydal_test — dev DB untouched) + JS suites
tools/clients/fullframe.sh setup --org=SLUG --index=tydal_multimedia   # once per org: ready for Full Frame exhibitions
tools/clients/fullframe.sh create --org=SLUG --name="…" --curator=EMAIL  # one exhibition: vault + keys + curator (printed once)

# From backend/ — the rest are artisan commands (full reference: docs/CLI.md).
# On the docker tier prefix them with: docker exec -w /var/www/html tydal_app
# One-line cheat sheet with both forms: docs/QUICK_REFERENCE.md
php artisan queue:work --timeout=360      # required for anything async: indexing, enrichment, embeddings
php artisan search:reconcile              # diagnose MySQL <-> ES drift (report only)
php artisan search:reconcile --fix        # repair it
php artisan search:embed                  # backfill chunk embeddings (needs the queue worker)
php artisan search:setup-indices          # additive mapping update, e.g. after a new scheme field
php artisan search:setup-indices --recreate && php artisan search:reindex   # after a field's es_type CHANGED
```

### Manual (understanding each step)

The scripts above automate exactly this:

```bash
# 1. Infrastructure (MySQL, Elasticsearch, Kibana, Tika, Redis)
docker compose -f docker-compose.yml up -d

# 2. Backend
cd backend
composer install
cp ../tools/deploy/env.templates/backend.env.ollama .env   # or backend.env.aicloud / .env.example
php artisan key:generate
php artisan storage:link

# 3. Database + minimal data
php artisan migrate:fresh
php artisan db:seed --class=CollectionSchemaSeeder
# creates superadmin + org + one starter collection per scheme name below
# (comma-separated; defaults to "multimedia" if unset) — see
# `php artisan schema:starter-options` for the full list of choices
TYDAL_INITIAL_COLLECTIONS=multimedia,documents php artisan db:seed --class=MinimalSeeder

# 4. Search index (Elasticsearch)
php artisan search:setup-indices --recreate
php artisan search:reindex
php artisan search:embed                          # vector embeddings (optional)

# 5. Run services (separate terminals)
php artisan serve                                 # API on :8000
php artisan queue:work --timeout=360              # REQUIRED for ES indexing + text extraction

# 6. Frontend
cd ../frontend
npm install
cp ../tools/deploy/env.templates/frontend.env .env   # or cp .env.example .env
npm run dev                                       # Vite dev server (default :3005)
```

### Docker-only hosts (no PHP/Node installed)

With the `docker` application tier the scripts need **only Docker** on the
host: `install.sh` runs composer/artisan inside the `app` container (bringing
the containers up first), and every npm step (`install.sh`, `start.sh`'s
Vite, `test.sh`'s JS suites) falls back to a disposable `node:22` container
when the host has no npm (override the image with `TYDAL_NODE_IMAGE`). Note
this branch writes Linux-native `node_modules` into the checkout; if you
later install Node on the host, `rm -rf node_modules` and reinstall. See
[`tools/deploy/README.md`](tools/deploy/README.md) for the full detail.

---

## Optional: the MCP servers

Two separate, purpose-built MCP servers — build either or both once you want
an AI agent talking to TYDAL. Neither is required for the web app. This
section covers building them; for wiring a specific client (Claude Desktop,
Claude Code, Cursor — not ChatGPT, see why) to one, see
[`docs/CONNECTING_MCP_CLIENTS.md`](docs/CONNECTING_MCP_CLIENTS.md).

```bash
tools/deploy/install_mcp.sh     # builds both @tydal/org-mcp and @tydal/vault-mcp dist/index.js
```

### `org-mcp` — org admins & curators (full read/write)

Full org-wide management surface: browse/search workspaces, upload files, set
metadata/tags, run RAG. Run by **whoever manages your TYDAL org** (an admin
or curator), pointed at their own org via a scoped API key — not by TYDAL
itself.

```bash
# From backend/ — prints the key once + a ready-to-paste env block
php artisan mcp:token --org=acme --abilities=read,ask,write --days=90
```

Point your MCP client (Claude Desktop, Claude Code, Cursor, …) at the built
binary — see [`mcp.example.json`](mcp.example.json) (native Node) or
[`mcp.docker.example.json`](mcp.docker.example.json) (no Node installed) for
the full config block:

```jsonc
{
  "mcpServers": {
    "tydal-org": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/org-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "http://localhost:8000/api/v1",
        "TYDAL_TOKEN": "<from mcp:token above>",
        "TYDAL_ORG_ID": "<your org uuid>"
      }
    }
  }
}
```

### `vault-mcp` — exposing one vault to the outside world

The **read-mostly, tier-gated** surface: one vault only, addressed by opaque
**link hash** (never an internal resource ID), scoped by the vault's own
`purpose` (`gallery`/`obsidian`/`ai`) and `exposure_policy` (which tiers —
identity/chunks/binary — it projects). This is what you hand to a customer's
own AI, a jury, a downstream consumer — anyone who should see *only* that
one curated projection, nothing else in the org.

Published vaults connect **keyless**; private vaults need a **vault key**
(no CLI — minted in Platform Administration, or `POST
/api/v1/platform/vaults/{id}/keys`; see
[`docs/architecture/VAULT_SYSTEM.md`](docs/architecture/VAULT_SYSTEM.md) §7). Add
`TYDAL_VAULT_WRITE_KEY` only if the vault's purpose exposes a write op (e.g.
`ingest` on an `ai` vault) and you want that tool available — omit it and the
connection is strictly read-only.

```jsonc
{
  "mcpServers": {
    "tydal-vault": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/vault-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "http://localhost:8000",
        "TYDAL_VAULT": "acme/press-kit",
        "TYDAL_VAULT_KEY": "tvk_… (private vaults only)",
        "TYDAL_VAULT_WRITE_KEY": "tvk_… (optional — enables ingest on ai vaults)"
      }
    }
  }
}
```

Rebuild + restart your MCP client after any change to `org-mcp/`/`vault-mcp/`
(`tools/deploy/install_mcp.sh` again).

---

## Production deployment

Target stack: **nginx + PHP-FPM** on a Linux server/VM, with the queue worker
supervised, and MySQL / Elasticsearch / Redis running as managed or
containerized services.

### 1. Build artifacts

On the server (or in CI, then ship the result):

```bash
# Backend
cd backend
composer install --no-dev --optimize-autoloader
cp /path/to/production.env .env       # production-specific values
php artisan key:generate              # only if APP_KEY is not already set
php artisan storage:link

# Frontend — produces a static bundle in frontend/build/
cd ../frontend
npm ci
npm run build                         # VITE_API_BASE_URL must be the prod API URL
```

Set production `.env` values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
```

### 2. Database & search

```bash
cd backend
php artisan migrate --force                       # --force runs migrations non-interactively
php artisan db:seed --class=ProductionSeeder      # superadmin + org, env-driven password
php artisan db:seed --class=CollectionSchemaSeeder
php artisan search:setup-indices --recreate
php artisan search:reindex
php artisan search:embed
```

Set `TYDAL_SUPERADMIN_PASSWORD` in the production `.env` **before** running
`ProductionSeeder`, or capture the random password it prints once. Rotate it
after first login.

### 3. Cache the framework config

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Re-run these after any `.env` or route change — cached config ignores `.env`.

### 4. nginx + PHP-FPM

Serve the **backend** API from `backend/public` and the **frontend** static
bundle from `frontend/build`. A single-server example:

```nginx
server {
    listen 443 ssl http2;
    server_name your-domain.example;

    ssl_certificate     /etc/letsencrypt/live/your-domain.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain.example/privkey.pem;

    client_max_body_size 310M;          # match MAX_MEDIA_FILE_SIZE + overhead

    # ---- Frontend SPA (static build) ----
    root /var/www/tydal/frontend/build;
    index index.html;

    location / {
        try_files $uri $uri/ /index.html;   # SPA fallback
    }

    # ---- Backend API (Laravel) ----
    location /api/ {
        root /var/www/tydal/backend/public;
        try_files $uri /index.php?$query_string;
    }

    location ~ ^/index\.php(/|$) {
        root /var/www/tydal/backend/public;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        internal;
    }
}
```

Point the frontend at this domain at build time:
`VITE_API_BASE_URL=https://your-domain.example/api/v1`.

> If you host the API and SPA on **separate** domains, configure Laravel CORS
> and `SANCTUM_STATEFUL_DOMAINS` accordingly.

Ensure file permissions: `backend/storage` and `backend/bootstrap/cache` must
be writable by the PHP-FPM user (e.g. `www-data`).

### 5. Queue worker (Supervisor)

`php artisan serve &` / `queue:work &` are **dev-only**. In production, supervise
the worker so it restarts on failure and on deploys.

`/etc/supervisor/conf.d/tydal-worker.conf`:

```ini
[program:tydal-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/tydal/backend/artisan queue:work --timeout=360 --tries=3
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/tydal/worker.log
stopwaitsecs=370
```

```bash
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start tydal-worker:*
```

After each deploy, run `php artisan queue:restart` so workers pick up new code.

#### Scheduler (cron)

The maintenance jobs in `bootstrap/app.php` (`resource:prune` daily 03:00,
`files:purge-uncommitted` daily 03:30, `resources:purge-drafts` hourly) run
only if something calls the scheduler every minute. In production, one crontab
entry for the web user does it (`sudo crontab -u www-data -e`):

```cron
* * * * * cd /var/www/tydal/backend && php artisan schedule:run >> /dev/null 2>&1
```

Check it with `php artisan schedule:list`. In dev, `start.sh` covers this. The
docker tier has a `tydal_scheduler` container, and the host tier runs
`schedule:work` in the background.

### 6. Backing services

- **MySQL 8.0**, **Redis 7**, **Elasticsearch 9** as managed services or
  containers on private networking. Do not expose 3306/6379/9200 publicly.
- **TLS** terminated at nginx (Let's Encrypt or your CA).
- **Backups** — MySQL dumps + a way to rebuild the ES index (`search:reindex`
  regenerates it from MySQL, so ES is reconstructible, not a backup target).
- **Storage** — for multi-node, switch `FILESYSTEM_DISK`/`MEDIA_DISK` to S3/MinIO
  (the `AWS_*` block) so uploads aren't tied to one host.

---

## Troubleshooting

#### Embedding dimensions are coupled to the model

`EMBEDDING_DIMENSIONS` must match both `OLLAMA_EMBED_MODEL` (or the cloud
embedder) *and* the Elasticsearch mapping:

| Model | `EMBEDDING_DIMENSIONS` |
|---|---|
| `mxbai-embed-large` (Ollama default) | `1024` |
| `nomic-embed-text` (Ollama, smaller) | `768` |

Change either the driver or the dimension count for an **existing** install
and old vectors are now a different vector space than the mapping expects —
you must:
```bash
php artisan search:setup-indices --recreate
php artisan search:reindex
```
`configure.sh` detects this and prints the reminder; it's easy to miss if you
edit `.env` by hand instead.

#### Elasticsearch mapping errors after changing field types

ES cannot change an existing field's **type** at all (not just embeddings) —
recreate the index: `php artisan search:setup-indices --recreate` then
`php artisan search:reindex`. A merely *new* field is additive and doesn't
need this.

#### Login returns "route … could not be found" / 404

Something other than the TYDAL backend is answering the API port (8000 in dev).
A stale `artisan serve` from another project — or an orphaned TYDAL worker — can
hold the port. Check with `lsof -nP -iTCP:8000 -sTCP:LISTEN`, stop the offending
process, and restart the TYDAL backend.

#### Login returns "Invalid credentials"

The superadmin password is env-driven, not a fixed default. Check
`TYDAL_SUPERADMIN_PASSWORD`; if it was blank at seed time, the password was
randomly generated and printed once in the seed output. Re-seeding does **not**
overwrite an existing user (`firstOrCreate`) — to apply a new password either
`migrate:fresh` + re-seed, or reset it for the existing user.

#### New resources don't appear in the catalogue

The queue worker isn't running. Start `php artisan queue:work --timeout=360`
(dev) or check Supervisor (prod), then `php artisan search:reindex` to backfill.

#### Config/env changes have no effect (production)

You cached the config. Run `php artisan config:clear` (or re-run
`config:cache` after the change).

#### `ollama-docker` runs out of memory / vision model won't load

No GPU in that tier, and it's bounded by Docker Desktop's VM RAM —
`llama3.2-vision` is ~11 GiB. Prefer `ollama-host` for local models; you can
still keep the *app* in Docker (`ollama-host docker`) and just run Ollama on
the host for GPU.
