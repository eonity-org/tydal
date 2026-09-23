# Security Policy

## Supported Versions

TYDAL is under active development. Security fixes are applied to the latest
release on the default branch. Until a stable `1.0` is tagged, only the most
recent commit on the main branch is officially supported.

| Version            | Supported |
| ------------------ | --------- |
| main (latest)      | ✅        |
| older commits/tags | ❌        |

## Reporting a Vulnerability

**Please do not open public issues for security vulnerabilities.**

Report privately through one of:

1. **GitHub Security Advisories** — use the repository's
   "Report a vulnerability" button under the *Security* tab
   (Private Vulnerability Reporting). This is the preferred channel.
2. **Email** — `tydal@eonity.org`

When reporting, please include:

- A description of the issue and its impact.
- Steps to reproduce (proof-of-concept if possible).
- Affected component(s): backend API, frontend, MCP server, or tooling.
- Any suggested remediation.

## What to Expect

- **Acknowledgement** within 3 business days.
- An initial assessment and severity rating within 10 business days.
- Coordinated disclosure: we will agree on a timeline with you and credit you
  in the release notes unless you prefer to remain anonymous.

## Scope

This project handles authentication tokens, multi-tenant data isolation, file
storage (S3/MinIO), and third-party AI provider keys. Issues in any of these
areas — auth bypass, tenant data leakage, SSRF, injection, insecure direct
object references, or secret exposure — are in scope and especially valued.

Configuration mistakes in a deployer's own environment (e.g. committing their
`.env`, using default seeded credentials in production) are out of scope, but
we welcome documentation improvements that help users avoid them.
