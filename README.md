# TYDAL

**TYDAL — the typed Digital Asset Layer**  
*The semantic layer between your files and your AI.*  
*Schema-driven semantic vaults for AI agents.*

**From files to typed semantic resources.**

TYDAL is a multi-tenant, schema-driven semantic knowledge system that structures, enriches, and projects digital resources through contextual Vaults. It combines a Laravel API, a React frontend, file storage, semantic metadata, workspace-based catalogues, and AI-native enrichment.

The **TY** signature, inherited from EONI**TY**, connects TYDAL to the **EONITY** product family.


## Repository Layout

```text
backend/    Laravel 12 API, database migrations, jobs, services, tests
frontend/   Vite + React + TypeScript web application
org-mcp/    Org-wide MCP server (write-capable management surface)
vault-mcp/  Vault-scoped read-only MCP server (customer AI consumer surface)
tools/      Utility scripts, including the bulk uploader
docs/       Public documentation index and archived design notes
```

## Stack

- Backend: PHP 8.3, Laravel 12, Sanctum, MySQL, Redis queues/cache
- Frontend: React 18, TypeScript, Vite 6, Material UI 6, React Router 7
- Search and processing: Elasticsearch 9, Apache Tika, optional embedding/LLM providers
- Storage: local disk or S3-compatible storage such as MinIO

## Getting Started

All install/run commands live in one place, kept in sync with the actual
scripts: **[DEPLOYMENT.md](DEPLOYMENT.md)**. It walks development
(install → configure `.env` → start the stack → seed → optionally plug in the
two MCP servers, plus the commands you'll run again later and the common
gotchas) and [production deployment](DEPLOYMENT.md#production-deployment)
(nginx + PHP-FPM) alike.

## Documentation

- [Deployment Guide](DEPLOYMENT.md) — development & production setup, configuration reference, troubleshooting
- [Backend README](backend/README.md)
- [Frontend README](frontend/README.md)
- [Documentation index](docs/README.md)
- [OpenAPI specification](backend/docs/openapi.yaml)
- [Org MCP server README](org-mcp/README.md)
- [Vault MCP server README](vault-mcp/README.md)
- [Bulk uploader README](tools/bulk_uploader/README.md)

Historical design notes, migration notes, and planning documents have been moved into `docs/archive/`, `backend/docs/archive/`, and `frontend/docs/archive/` so the public documentation stays short.

## Testing

See [`backend/README.md`](backend/README.md) and
[`frontend/README.md`](frontend/README.md) for the test commands for each
subproject.

## License

TYDAL is released under the [Apache License 2.0](LICENSE). Copyright 2026 John Pree (eonity.org).
See the [NOTICE](NOTICE) file for attribution and trademark information.
