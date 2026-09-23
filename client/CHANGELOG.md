# Changelog — `@tydal/client`

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/);
this package adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The SDK versions independently of the TYDAL product release documented in
[`../CHANGELOG.md`](../CHANGELOG.md).

> **Note on published versions.** `1.0.1` is the last version published to npm;
> `1.1.0` and `1.2.0` were developed and consumed locally (Full Frame vendored a
> `1.1.0` tarball) but never released. `1.3.0` is the first release to carry
> them, so upgrading from `1.0.1` picks up the whole vault write surface.

## [1.3.0] — 2026-07-26

### Added
- `VaultMeta.capabilities` — the vault's full exposure matrix keyed by
  capability (`allow_binary`, `chunk_roles`, `write_methods`, …), each entry
  carrying the effective `value` and its `source` (`preset` when it comes from
  the vault's purpose, `override` when the vault set it itself). Lets a consumer
  explain *why* a tier answers as it does, not just whether it does.
- `VaultCapability` type export.

### Notes
- The field is **optional** — backends older than the capability matrix omit it,
  and consumers must tolerate its absence.
- `VaultMeta.tiers` is unchanged and remains the contract for "may I?" checks.
  Nothing in this release alters an existing shape, so `1.0.1` consumers upgrade
  without code changes.

## [1.2.0] — unreleased until 1.3.0

### Changed
- **`write(op, payload)` is now the only write surface.** An op name is the unit
  of permission (`w:{op}`), audit, and discovery; its payload is a declarative
  document the vault's purpose maps server-side. New behavior grows in the
  backend rather than as SDK namespaces.
- `write()` switches to **multipart automatically** when the document carries a
  `Blob`/`File` value (e.g. `ingest`'s image), JSON-encoding the non-file fields
  so the server sees one coherent document either way.

### Deprecated
- `vault.gallery.activate/open/close` — per-purpose sugar, superseded by
  `write('activate', { resources })` / `write('open')` / `write('close')`. Kept
  as thin shims so existing callers keep working; new code should not add
  namespaces like this.

## [1.1.0] — unreleased until 1.3.0

### Added
- The vault **write boundary**: `write(method, body)` posting to `POST /w/{method}`
  authorized by a write-capable vault key, returning `VaultWriteResult`.
- `writeCapabilities()` — a non-destructive `GET /w` probe reporting which write
  methods the configured key may invoke, performing none. Use it to validate a
  write key at bind/config time. A key that cannot write throws `TydalApiError`
  (403); a hidden vault 404s, matching the opacity of a real write.
- `VaultWriteResult` type export.

## [1.0.1] and earlier

See the repository history; `1.0.1` accompanied Milestone E1.
