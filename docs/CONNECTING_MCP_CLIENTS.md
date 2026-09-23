# Connecting an AI Client to TYDAL

How to wire `org-mcp` or `vault-mcp` into an AI client (Claude Desktop, Claude
Code, Cursor, …). This is for **connecting your own AI**, not for deploying
TYDAL — if you're setting up a TYDAL instance itself, see
[`DEPLOYMENT.md`](../DEPLOYMENT.md) instead.

Two ready-to-copy example files live at the repo root:
[`mcp.example.json`](../mcp.example.json) (native Node on your host) and
[`mcp.docker.example.json`](../mcp.docker.example.json) (no Node installed —
runs each server in a disposable container instead). Both cover Claude
Desktop's config shape directly; adapt the same `command`/`args`/`env` into
Claude Code or Cursor's own file per the sections below.

## Before you connect

**You do not run the TYDAL configurator (`install.sh`/`configure.sh`).** Those
set up and configure a TYDAL *instance* — a separate concern from connecting
a client to one that's already running. You need:

1. **A running TYDAL instance you can reach** — its base URL. Someone already
   deployed it (you, your org's admin, or a vendor); you're not standing one
   up here.
2. **Credentials**, scoped to which server you're connecting:
   - `org-mcp` (full org read/write) — a Sanctum token. If you administer the
     TYDAL org yourself, mint one with `php artisan mcp:token` (see
     [`org-mcp/README.md`](../org-mcp/README.md#generating-a-token)); otherwise
     ask whoever does for one scoped to you.
   - `vault-mcp` (one curated, read-mostly projection) — nothing at all if the
     vault is published (keyless); a **vault key** (`tvk_…`) if it's private,
     minted by the org's admin in Platform Administration and handed to you.
3. **The server binary, built.** Neither `org-mcp` nor `vault-mcp` is published
   to npm yet, so there's no `npx @tydal/org-mcp` shortcut today — build from
   source:
   ```bash
   git clone <the tydal repo>
   cd tydal
   npm install
   tools/deploy/install_mcp.sh   # builds both dist/index.js entry points
   ```
   You need Node 18+ for this step; you do **not** need PHP, Docker, or the
   rest of the backend toolchain — only the built `.js` file matters to your
   client. No Node on your machine at all? `install_mcp.sh` still works (it
   falls back to a disposable container for the build), and you can run the
   servers themselves the same way — see
   [No Node.js on your host?](#no-nodejs-on-your-host-run-via-docker-instead)
   below.

**No service to keep running, either.** `org-mcp` and `vault-mcp` speak the
MCP **stdio** transport: your AI client spawns the process itself, on demand,
each time you use it, and stops it when the session ends. The only thing that
has to already be up is the TYDAL instance itself, at the base URL you point
the client at — nothing runs persistently on your machine between sessions.

## Which clients can connect

| Client | Works? | Notes |
|---|---|---|
| Claude Desktop | ✅ | Spawns the process locally over stdio. |
| Claude Code | ✅ | Same — CLI or config file, see below. |
| Cursor | ✅ | Same `mcpServers` shape as Claude Desktop. |
| ChatGPT | ❌ | ChatGPT's connectors require a **remote HTTPS** MCP server (Streamable HTTP/SSE) — it cannot spawn a local process the way desktop/CLI clients can. `org-mcp`/`vault-mcp` only implement the stdio transport today, so they aren't reachable from ChatGPT as built. Adding an HTTP transport would be new work, not a config change. |
| Any other MCP client that supports local stdio servers | Likely ✅ | Same `mcpServers` JSON shape is a de facto convention across most desktop/CLI MCP clients. |

The exact `command`/`args`/`env` block for each server — `org-mcp`'s three
use-case examples, `vault-mcp`'s read/write-key setup — lives in each
server's own README:
[`org-mcp/README.md`](../org-mcp/README.md#claude-desktop-configuration) ·
[`vault-mcp/README.md`](../vault-mcp/README.md#connection). What follows is
how each *client* wants that same block wired in.

## Claude Desktop

Edit the config file directly:

- **macOS**: `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows**: `%APPDATA%\Claude\claude_desktop_config.json`

```jsonc
{
  "mcpServers": {
    "tydal-vault": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/vault-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-tydal-instance.example.com",
        "TYDAL_VAULT": "acme/press-kit",
        "TYDAL_VAULT_KEY": "tvk_… (private vaults only)"
      }
    }
  }
}
```

Restart Claude Desktop after editing. Swap in `org-mcp`'s block (see its
README) for the org-wide management surface instead.

## Claude Code

Two equivalent ways — a config file or the CLI.

**Config file** — `.mcp.json` in your project root (shared if you commit it),
or `~/.claude.json` for a machine-wide server available in every project
(project-local `.mcp.json` takes precedence when both exist):

```jsonc
{
  "mcpServers": {
    "tydal-vault": {
      "type": "stdio",
      "command": "node",
      "args": ["/absolute/path/to/tydal/vault-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-tydal-instance.example.com",
        "TYDAL_VAULT": "acme/press-kit",
        "TYDAL_VAULT_KEY": "tvk_…"
      }
    }
  }
}
```

**CLI** — `claude mcp add`, with `--` separating Claude Code's own flags from
the server's command:

```bash
claude mcp add --transport stdio tydal-vault \
  --env TYDAL_BASE_URL=https://your-tydal-instance.example.com \
  --env TYDAL_VAULT=acme/press-kit \
  --env TYDAL_VAULT_KEY=tvk_… \
  -- node /absolute/path/to/tydal/vault-mcp/dist/index.js
```

Use `--scope user` to make it available in every project instead of just this
one. Run `claude mcp add --help` for the full flag set. Use an absolute path
for `command`/`args` — relative paths resolve against Claude Code's working
directory, which changes depending on where you launch it from.

## Cursor

Same `mcpServers` shape as Claude Desktop, in `.cursor/mcp.json` (project) or
`~/.cursor/mcp.json` (global):

```jsonc
{
  "mcpServers": {
    "tydal-vault": {
      "command": "node",
      "args": ["/absolute/path/to/tydal/vault-mcp/dist/index.js"],
      "env": {
        "TYDAL_BASE_URL": "https://your-tydal-instance.example.com",
        "TYDAL_VAULT": "acme/press-kit",
        "TYDAL_VAULT_KEY": "tvk_…"
      }
    }
  }
}
```

Cursor also supports an `envFile` field (stdio servers only) if you'd rather
keep the vault key out of the JSON file itself.

## No Node.js on your host? Run via Docker instead

You don't need to install Node — any client above spawns `docker` instead of
`node`, running the built server inside a disposable `node:22` container.
Same stdio contract, nothing persists between sessions, exactly like the
native form. Full example:
[`mcp.docker.example.json`](../mcp.docker.example.json).

```jsonc
{
  "mcpServers": {
    "tydal-vault": {
      "command": "docker",
      "args": [
        "run", "--rm", "-i",
        "-v", "/absolute/path/to/tydal:/app",
        "-w", "/app/vault-mcp",
        "-e", "TYDAL_BASE_URL=http://host.docker.internal:8000",
        "-e", "TYDAL_VAULT=acme/press-kit",
        "-e", "TYDAL_VAULT_KEY=tvk_…",
        "node:22", "node", "dist/index.js"
      ]
    }
  }
}
```

Two details here matter and are easy to get wrong (both broke a real
connection before this doc was written):

- **Mount the whole repo, not just `vault-mcp/`/`org-mcp/`.** `tools/`,
  `client/`, `org-mcp/`, and `vault-mcp/` all share one npm workspace, so
  `vault-mcp`'s only dependency (`@modelcontextprotocol/sdk`) lives in the
  **repo root's** `node_modules`, not `vault-mcp/node_modules`. Mount just
  the subpackage and Node can't find it
  (`ERR_MODULE_NOT_FOUND: @modelcontextprotocol/sdk`). Mount the repo root
  (`-v /path/to/tydal:/app`) and set the working directory to the subpackage
  inside it (`-w /app/vault-mcp`) instead.
- **Use `host.docker.internal`, not `localhost`, for `TYDAL_BASE_URL`.**
  The server now runs *inside* the container, so `localhost` would mean
  "inside this container," not your host machine. `host.docker.internal` is
  Docker's DNS name for "the host that's running me" — resolved automatically
  by Docker Desktop (macOS/Windows) with no extra flags; add
  `--add-host host.docker.internal:host-gateway` to the `args` array if
  you're on native Linux Docker, which doesn't provide it by default.

### On OrbStack, prefer `--net host` over `host.docker.internal`

If your Docker runtime is [OrbStack](https://orbstack.dev), add `"--net",
"host"` to `args` and use plain `http://localhost:8000` for
`TYDAL_BASE_URL` instead of `host.docker.internal:8000`:

```jsonc
"args": [
  "run", "--rm", "-i", "--net", "host",
  "-v", "/absolute/path/to/tydal:/app",
  "-w", "/app/vault-mcp",
  "-e", "TYDAL_BASE_URL=http://localhost:8000",
  "-e", "TYDAL_VAULT=acme/press-kit",
  "-e", "TYDAL_VAULT_KEY=tvk_…",
  "node:22", "node", "dist/index.js"
]
```

`--net host` puts the container directly on the host's own network
namespace, so `localhost` inside the container means the same thing as
`localhost` on your Mac. That's not just a shorter URL — it fixes a real
inconsistency: the TYDAL **backend** builds resource URLs in its API
responses from its own `APP_URL` (`http://localhost:8000` by default), while
`vault-mcp` builds its own URLs from `TYDAL_BASE_URL`. With
`host.docker.internal` those two disagree — the same audit response ends up
with some `url` fields on `localhost:8000` and others on
`host.docker.internal:8000`, which is confusing and would need reconciling
before exposing anything outside a dev machine. Point both at `localhost`
via `--net host` and every URL the API returns is consistent, because it's
now the literal same hostname on both sides.

This is genuinely OrbStack-specific, not a general Docker trick — Docker
Desktop for Mac's VM networking does not expose host ports to a
`--net host` container as `localhost` the same way, so keep
`host.docker.internal` there. (Verified directly in this environment before
being documented: a real MCP `list_resources` call through `--net host` came
back with every `url` on `localhost:8000`, none on
`host.docker.internal:8000`.)

Build first, same as the native path — `tools/deploy/install_mcp.sh` (it
falls back to a disposable container for the build step too when there's no
Node on the host, so this whole path needs nothing but Docker).

## After connecting

Rebuild and restart your client's server process after any change to the
`org-mcp`/`vault-mcp` source (`tools/deploy/install_mcp.sh` again) or to the
env block (restart the client so it respawns the process with the new
values) — there's no hot-reload for a stdio server.

## Troubleshooting

**`Cannot find package '@modelcontextprotocol/sdk'`.** You're on the Docker
path and mounted only the subpackage (`-v .../vault-mcp:/app`) instead of the
whole repo. See [No Node.js on your host?](#no-nodejs-on-your-host-run-via-docker-instead)
above — mount the repo root and set `-w /app/vault-mcp` (or `/app/org-mcp`)
instead.

**Client reports it can't connect / the process seems to die immediately.**
Almost always means the spawned process never started, not a network
problem. Check, in order:
1. Does `dist/index.js` actually exist at the path you configured? It's
   never published to npm, so it has to be built — `tools/deploy/install_mcp.sh`.
2. Is that path *actually* the repo you're working in? It's easy to
   accumulate stale clones over time — a config pointing at an old checkout
   builds and runs fine, just the wrong (possibly months-stale) code.
   `git log -1 --format='%ci %s' -- vault-mcp` in that directory tells you
   how old it is.
3. Mixed up `localhost` and `host.docker.internal`? `localhost` is correct
   only when `command` is `node` (runs natively on your host); switch to
   `host.docker.internal` only when `command` is `docker` (runs inside a
   container) — using the wrong one for your setup fails silently from the
   server's side (connection refused / can't resolve), not with a clear
   "wrong hostname" error.

**It connects, but `get_vault`/`list_workspaces` 404s or errors.** The
server started fine — the vault or org you configured doesn't exist *on this
instance*. Easy to hit right after a fresh reinstall/reset
(`tools/deploy/first_install.sh` wipes the DB) if your client config still
has vault slugs or org IDs from a previous install. Recreate them, or point
at ones that exist.

**Changed the `.env` block but nothing happened.** Restart your client (or
re-run `claude mcp add`/edit the config) so it respawns the process — a
stdio server has no hot-reload; the environment is fixed at spawn time.

**It connects fine, but `url`/`preview`/`download_url` fields in responses
alternate between `localhost:8000` and `host.docker.internal:8000`.** Not a
bug — two different things build URLs from two different env vars. The
TYDAL backend builds URLs server-side from its own `APP_URL` (usually
`localhost:8000`); `vault-mcp` builds its own client-side from
`TYDAL_BASE_URL` (`host.docker.internal:8000`, if you're on the Docker path).
Both are individually correct, they just don't agree with each other. On
OrbStack, [`--net host`](#on-orbstack-prefer---net-host-over-hostdockerinternal)
fixes this properly by letting both sides use the same `localhost` URL. On
Docker Desktop, either live with the split in dev, or set the vault's own
`base_url` (`vaults.base_url` column) to match `TYDAL_BASE_URL` explicitly.
