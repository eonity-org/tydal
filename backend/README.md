# TYDAL Backend

*Schema-driven semantic vaults for AI agents.*


Laravel API for TYDAL (**the typed Digital Asset Layer**), a multi-tenant, schema-driven semantic knowledge system — schema-driven semantic vaults for AI agents.


## What It Provides

- Token authentication with Laravel Sanctum
- Organization-scoped users, roles, workspaces, collections, categories, resources, files, semantic tags, and Vaults
- Platform superadmin endpoints for cross-organization administration
- Resource upload and file management with local or S3-compatible storage
- Search indexing with Elasticsearch
- Text extraction with Apache Tika
- Optional AI enrichment, embeddings, RAG, and auto-approval workflows
- Pest/PHPUnit test coverage for API, jobs, services, and search behavior

## Requirements

- PHP 8.3+
- Composer
- MySQL 8+
- Redis 7+
- Elasticsearch 9+
- Apache Tika
- Optional: MinIO or AWS S3, Ollama, Voyage, Claude, Gemini, Z.ai, or OpenAI credentials depending on enabled AI features

The root `docker-compose.yml` starts MySQL, Redis, Elasticsearch, Kibana, and Tika for local development.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan search:setup-indices --recreate
php artisan search:reindex
php artisan serve
```

The default API URL is `http://localhost:8000/api/v1`.

Seeded local account:

- Email: `superadmin@tydal.test` (override with `TYDAL_SUPERADMIN_EMAIL`)
- Password: from `TYDAL_SUPERADMIN_PASSWORD`. Leave it blank and the seeder
  generates a strong random password, printed once in the seed output.

## Useful Commands

```bash
composer test
composer pint
composer phpstan
php artisan queue:work
php artisan search:setup-indices --recreate
php artisan search:reindex
php artisan search:embed
php artisan search:reconcile --fix        # repair MySQL ↔ ES drift
php artisan schema:validate               # scheme field-contract check (CI-able)
php artisan resources:audit-roles --fix   # composition invariant audit
```

The full command reference with options and operational recipes lives in
[`docs/CLI.md`](../docs/CLI.md).

The test suite includes integration checks for Tika, Elasticsearch, and the configured embedding provider. Embedding-provider tests skip themselves when the selected provider is not configured.

## API Routes

All API routes are registered under `/api/v1`.

- `POST /login`, `POST /register`, `GET /me`, `POST /logout`, `POST /refresh`
- `/organizations` and organization user management
- `/workspaces`, workspace catalogues, workspace Q&A, workspace vault links
- `/collections`, `/collection-schemes`, `/search-indexes`
- `/categories`, `/semantic-tags`
- `/resources`, resource files, trash, restore, force delete, vault links, AITY enrichment, AI suggestions, activity logs
- `/catalogue/{id}` and `/catalogue/{id}/ask`
- `/notifications`
- `/aity/chat`, `/aity/auto-approve`, and related dispatch/stream endpoints
- `/platform/*` superadmin endpoints for organizations, users, collections, Vaults, AI connectivity checks, stats, and settings

See [docs/openapi.yaml](docs/openapi.yaml) for the machine-readable API specification.

## Project Structure

```text
app/Console/Commands/       Artisan maintenance, search, and cleanup commands
app/Enums/                  Typed enum values used by models and migrations
app/Http/Controllers/API/   REST API controllers
app/Http/Requests/          Request validation
app/Jobs/                   Async extraction, indexing, embedding, and AI jobs
app/Models/                 Eloquent models
app/Policies/               Authorization policies
app/Repositories/           Data access layer
app/Services/               Domain, search, storage, processing, LLM, and RAG services
database/migrations/        Schema history
database/seeders/           Local/demo seed data
routes/api/v1/              Modular API route files
tests/                      Feature, integration, and unit tests
```

## Configuration

Use `.env.example` as the provider-neutral baseline for manual local setup. For
an installer-managed setup, use the Ollama or cloud profile under
`tools/deploy/env.templates`; `tools/deploy/configure.sh` copies the selected
profile and rewires service hosts for the chosen application topology.

## Migrations

The public repository starts from a single baseline migration that creates the current schema directly. Pre-release migration history is preserved under `database/migrations/archive/` for reference and is not loaded by Laravel during normal migration runs.
