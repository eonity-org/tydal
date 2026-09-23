# 📜 TYDAL — Historic Documentation Digest

This file is a **summary index of the project's historical design notes**. The
raw archive documents (≈30 files) are kept **outside this repository** to keep
the open-source project clean; they describe earlier iterations and v1 design decisions.

For the **current, authoritative** picture always use:

- [`ARCHITECTURE_AND_ROADMAP.md`](../architecture/ARCHITECTURE_AND_ROADMAP.md) — canonical architecture & current → v2 roadmap
- [`SYSTEM_DIAGRAM.md`](../architecture/SYSTEM_DIAGRAM.md) — layered architecture & data-flow diagrams
- [`ROADMAP_MILESTONES.md`](ROADMAP_MILESTONES.md) — milestones, epics & issues
- the per-subproject READMEs and the backend OpenAPI spec

> Where a historic note conflicts with the canonical docs above, the canonical
> docs win. The summaries below exist so the *intent and history* survive even
> though the source files live privately.

Legend: 🟢 still broadly accurate · 🟡 partially superseded · 🔴 historic / v1-era only

---

## General docs (`docs/archive/`)

| File | What it covered | Status |
|------|-----------------|--------|
| `01-ARCHITECTURE.md` | First architecture overview: vision, core principles, layered backend (routes→controllers→services→repositories), schema model, AI pipeline, search, multi-tenancy strategy, Docker/queue infra, dev phases. | 🟡 superseded by `ARCHITECTURE_AND_ROADMAP.md` |
| `02-ENTITIES.md` | v1 entity definitions for all 14 core entities (Organization, User, Collection, Resource, File, Workspace, Category, SemanticTag, SystemFile, FileChunk, CdnLink, CollectionScheme, SearchIndex) plus deprecated MetadataScheme/ValidationScheme and entity behaviors (soft deletes, UUIDs, multilingual). | 🟡 model is largely current; naming pre-Vault |
| `03-API-SPECIFICATION.md` | Full v1 REST API spec (SWY-inspired): auth via Sanctum, response/error envelope format, cursor pagination, filtering/sorting, rate limiting, status codes. | 🟡 see live `backend/docs/openapi.yaml` |
| `04-DATABASE-SCHEMA.md` | v1 DB schema design: migration ordering by phase, Laravel 12 migration format, per-table column specs (organizations, users, workspaces, collections, resources, files), indexing strategy, FK constraints. | 🟡 see real migration `…create_initial_tydal_schema.php` |
| `06-TECHNICAL-REQUIREMENTS.md` | System/software requirements, MySQL & Elasticsearch tuning, storage strategy (MinIO dev / S3 prod), security requirements. | 🟡 |
| `07-GOOD-PRACTICES-FROM-SWY.md` | Engineering patterns borrowed from the SWY project: layered architecture, DI, multi-tenancy, modular routes, DTOs, observers, service interfaces, repository pattern, consistent API envelope, Pest testing, UUID PKs. | 🟢 patterns still in force |
| `08-S3-STORAGE-GUIDE.md` | File storage setup: filesystem config, MinIO (dev) & AWS S3 (prod) setup, store/retrieve/signed-URL/delete usage, recommended path structure, CORS & IAM. | 🟢 still accurate |
| `AI_ROADMAP.md` | Original "AI Knowledge Repository" roadmap: SOLR→Elasticsearch decision, Tika sidecar, Voyage AI embeddings, Redis queue, new tables (`resource_chunks`, `ai_collection_configs`), Claude integration, phased plan. | 🔴 superseded by v2 AI layer plan |
| `AITY_FLOW2.md` | Refined resource-creation & metadata flow: `purpose` drives `is_promoting`, preview as first-class upload slot, file→extraction chain, ES indexing triggers, AITY-assisted creation wizard, audio/transcription handling, system-workspace + notification pattern. | 🟡 informs current AITY UX |
| `dam_asset_model_specification.md` | DAM-era asset model: `role`/`relation` attributes, composition constraints, snapshot strategy, caching/dedup, metadata↔dependency integration, enterprise-DAM market positioning. | 🔴 DAM framing, pre-vault vision |
| `GUIDELINES.md` | Earliest brief: list of entities + "CRUD via API, UI later" directive. | 🔴 origin note |
| `IMPLEMENTATION_STATUS.md` | Phase-by-phase build status: Foundation+search (schema redesign, catalogue API, ES), file provenance + extracted text, Tika text-extraction pipeline, vector embeddings + hybrid (RRF) search, RAG Q&A, LLM auto-tagging, file purpose/promoting, preview extraction. | 🟡 status snapshot |
| `QUICK-START.md` | v1 tech stack + quick start, SWY-derived architecture, key features, default creds, API endpoints, project structure. | 🔴 duplicate of below; see live `docs/README.md` |
| `QUICKSTART.md` | Condensed dev setup (infra→backend→frontend), common commands, ports, troubleshooting (catalogue not updating, ES mapping errors, embedding dimension mismatch). | 🟡 see live `CLAUDE.md` / `docs/README.md` |
| `SEARCHING_TYDAL.md` | Search architecture deep-dive: entry points, catalogue endpoint, ES-vs-DB dual backend, search modes (prefix/contains/exact), field-scoped query language, faceted filtering, ES index structure (core + schema fields, parent-child annotations, chunk vectors), CollectionScheme→ES mapping, metadata propagation. | 🟢 mostly current |

## Backend docs (`backend/docs/archive/`)

| File | What it covered | Status |
|------|-----------------|--------|
| `ENUMS.md` | Database enum reference: Organization/Resource/Collection types, File Purpose, Visibility, Active Status, Payload; PHP-enum rationale, helper functions, best practices, how to add values. | 🟢 |
| `FILE_MEDIA_ARCHITECTURE.md` | `files` vs Spatie `media` split: why two tables, schemas, media-processing vs non-processing files, model relationships, conversions config, API response format. | 🟢 |
| `FILE_STORAGE_STRUCTURE.md` | Resource-based storage layout: directory structure (`archives/` vs `media/`), S3 layout, path generation, DB records, frontend URL construction, migration from old structure. | 🟢 |
| `MIGRATION_ENUM_CHANGES.md` | Record of centralizing enum definitions across organizations/collections/resources/files tables via helper functions. | 🔴 historic migration note |
| `PLATFORM_SUPERADMIN.md` | Superadmin implementation: role hierarchy (999/100/75/50/25), schema, API endpoints (org mgmt, platform users/stats/settings), default superadmin, middleware, security. | 🟢 |
| `TEST_SUMMARY.md` | Snapshot of the Pest test suite: passing unit tests, feature-test environment issues (route loading 404s, SQLite enum casting), coverage, recommendations. | 🔴 dated snapshot |
| `UUID_OPTIMIZATION.md` | UUID-strategy rationale: UUID v7 for public/security tables, auto-increment for private tables; phased conversion, API impact, storage/insert/join performance, rollback. | 🟢 explains current ID strategy |

## Frontend docs (`frontend/docs/archive/`)

| File | What it covered | Status |
|------|-----------------|--------|
| `01-pages-and-routes.md` | Page/route map: Login, Home, Admin, AityReview; auth flow; resource-creation entry points. | 🟡 |
| `02-features-and-components.md` | Component tree + key components (ResourceWizard, AityReviewPage, ResourceDetailModal, `useAiSuggestionsPoller`, Header), state mgmt, API services. | 🟡 |
| `03-redux-state-management.md` | State approach: local React state + prop drilling, component state ownership, hooks; Redux Toolkit noted as future. | 🟢 still the approach |
| `04-api-services.md` | API service layer: base config, token management, all endpoint groups (auth, resources, files, collections/workspaces, catalogue/search, AITY, CDN), error handling, request/response examples. | 🟡 |
| `05-design-system.md` | MUI v6 design system: primitive components (Button, IconButton, TextField, Checkbox, Radio, Badge, Card, FacetItem, FilterTag, FacetCard), theme + color palette, ESLint no-inline-styles, migration notes from odam-front. | 🟢 brand palette current |
| `DASHBOARD_ARCHITECTURE.md` | Home dashboard architecture: container/layout/content hierarchy, data-flow sequences (load, collection select, search, filter add/remove), state lifting, MUI+Emotion styling, props reference. | 🟡 |
| `PREVIEW_IMAGE_ANALYSIS.md` | Preview-image storage strategy: per-resource-type preview approach, Spatie MediaLibrary as store, API/frontend changes, phased implementation. | 🟡 design analysis |
| `TYDAL_DATA_STRUCTURE.md` | Frontend's view of the resource data shape: core fields, metadata/payload objects, relationships, flat-vs-nested contrast with old ODAM structure, API response format. | 🟡 |

---

*Archive moved out of the public repo on first open-source commit. This digest
is the public breadcrumb; the full files are retained privately for project
history.*
