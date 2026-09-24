# Contributing to TYDAL

Thanks for your interest in contributing to **TYDAL** — *the typed Digital Asset Layer*. This document explains how to set up your environment,
the standards we hold code to, and how to propose changes.

By participating, you agree to abide by our [Code of Conduct](CODE_OF_CONDUCT.md).

## Repository Layout

```text
backend/    Laravel 12 REST API
frontend/   Vite + React + TypeScript web application
org-mcp/    Org-wide MCP server (write-capable management surface)
vault-mcp/  Vault-scoped MCP server (read-only by default; supported writes with a write key)
tools/      Supporting tooling (e.g. bulk uploader)
docs/       Documentation
```

## Getting Started

The full local setup — install → configure → start → seed, command by
command — is documented in [`DEPLOYMENT.md`](../DEPLOYMENT.md#development-setup).

Never commit a real `.env`, credentials, or API keys. The `.env.example` files
are the source of truth for required configuration.

## Quality Gates

All contributions must pass the same checks CI runs.

**Backend (`backend/`)**

```bash
composer pint        # Code formatting (Laravel Pint)
composer phpstan     # Static analysis
composer test        # Pest test suite
```

**Frontend (`frontend/`)**

```bash
npm run lint         # ESLint (no inline styles)
npm run test:run     # Vitest
npm run build        # TypeScript check + production build
```

Please add or update tests for any behavior you change.

## Branch & Commit Conventions

- Branch from the default branch; do not commit directly to it.
- Use focused commits with clear messages. Conventional Commits
  (`feat:`, `fix:`, `docs:`, `refactor:`, `test:`, `chore:`) are encouraged.
- Keep pull requests scoped to a single concern where possible.

## Pull Request Process

1. Fork the repository and create a feature branch.
2. Make your change, including tests and documentation updates.
3. Ensure all quality gates above pass locally.
4. Open a pull request describing **what** changed and **why**, linking any
   related issues.
5. A maintainer will review; please be responsive to feedback.

## Reporting Bugs & Requesting Features

- **Bugs:** open an issue with reproduction steps, expected vs. actual behavior,
  and environment details.
- **Security issues:** do **not** open a public issue — follow
  [SECURITY.md](SECURITY.md).
- **Features:** open an issue describing the use case before large changes, so
  we can align on direction.

## License of Contributions

TYDAL is licensed under the [Apache License 2.0](../LICENSE). By submitting a
contribution, you agree that it is provided under the terms of that license
(per Section 5 of the Apache License), and that you have the right to submit it.
