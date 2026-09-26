# TYDAL

**TYDAL — the typed Digital Asset Layer**

TYDAL is an open-source Semantic Asset Platform for structuring, enriching and
sharing digital resources through contextual Vaults for people, applications
and AI agents.

## From files to structured resources

Documents, images, datasets and code become resources that can contain one file
or several related files. **Typed** means schemas define resource fields,
validation and search behavior: the same structure guides forms, filters and
indexing.

Collections organize resources under schemas. Workspaces curate selections.
**Vaults** expose contextual views of those resources with their own access
rules and capabilities, without duplicating the underlying assets. The same
content can support a gallery, an application or an AI workflow.

AI enrichment can propose metadata for review or configured automatic approval.
Organization MCP lets authorized agents manage resources and workspaces; Vault
MCP connects an external AI client to one curated context, read-only by default
with supported writes enabled through a write key. See [AI surfaces](docs/architecture/AI_SURFACES.md).

The **TY** signature, inherited from EONI**TY**, connects TYDAL to the **EONITY**
product family. **DAL** stands for **Digital Asset Layer**.

## Repository Layout

```text
backend/    Laravel 12 API, database migrations, jobs, services, tests
frontend/   Vite + React + TypeScript web application
client/     TypeScript SDK for applications and integrations
vaults/     Gallery, knowledge and AI-chat clients of the Vault interfaces
org-mcp/    Org-wide MCP server (write-capable management surface)
vault-mcp/  Vault-scoped MCP server (read-only by default; supported writes with a write key)
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
- [Tools index](tools/README.md) — dev scripts by category: stack lifecycle, clients at the border (vault apps, Full Frame), dev data
- [Quick reference](QUICK_REFERENCE.md) — one line per script/command, docker and native forms, with preconditions

Architecture specifications live in `docs/architecture/`; migration and milestone
records live in `docs/planning/`. Earlier private archive material is summarized
in the [historic documentation digest](docs/planning/historic.md).

## Contributing

Run your own instance, inspect the code and help shape the project. See the
[contribution guide](docs/CONTRIBUTING.md) for development standards and pull requests.

## Testing

See [`backend/README.md`](backend/README.md) and
[`frontend/README.md`](frontend/README.md) for the test commands for each
subproject.

## License

TYDAL is released under the [Apache License 2.0](LICENSE). Copyright 2026 John Pree (eonity.org).
See the [NOTICE](NOTICE) file for attribution and trademark information.
