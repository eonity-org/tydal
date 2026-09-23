#!/usr/bin/env node

/**
 * TYDAL Vault MCP Server — the machine face of one vault (VAULT_SYSTEM.md §7).
 *
 * A vault-scoped Model Context Protocol surface: a customer's AI agent consumes
 * exactly what the connected vault's purpose and policy expose — identity
 * (Tier 0), chunk content (Tier 1), minted binary URLs (Tier 2) — and nothing
 * else. The verb of each tool names the tier it touches.
 *
 * Read-only by default. When a WRITE key is configured, the vault's
 * purpose-exposed write ops become tools too (e.g. `ingest` on an `ai` vault),
 * so any MCP-capable AI can read, transform, and write back through one
 * connection. Still narrower than @tydal/org-mcp (the org-wide management surface):
 * every op is vault-scoped and gated by the vault key.
 *
 * Environment:
 *   TYDAL_BASE_URL         Root URL of the TYDAL instance
 *   TYDAL_VAULT            "orgSlug/vaultSlug"  (or TYDAL_VAULT_HASH)
 *   TYDAL_VAULT_KEY        Optional read key — required for private vaults
 *   TYDAL_VAULT_WRITE_KEY  Optional write key — turns on the write ops (ingest)
 */

import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

import { getVault, getResource, getFile } from './tools/get.js';
import { listResources, listFiles, listRelated, listChunks } from './tools/list.js';
import { searchResources, searchChunks } from './tools/search.js';
import { embedQuery } from './tools/embed.js';
import { readChunks } from './tools/read.js';
import { readImage } from './tools/read-image.js';
import { resolve } from './tools/resolve.js';
import { linkResource, linkFile } from './tools/link.js';
import { ingest } from './tools/ingest.js';
import { config } from './config.js';

// ── Registry — the verb taxonomy (§7.1): get_/list_/search_ (T0) ·
//    read_chunks (T1) · read_image (T2 pixels, streamed inline) ·
//    resolve (T0) · embed_ (compute) · link_ (T2 URL) ·
//    ingest (write, only with a write key) ────────────────────────────────

const TOOLS = [
  getVault,
  getResource,
  getFile,
  listResources,
  listFiles,
  listRelated,
  listChunks,
  searchResources,
  searchChunks,
  embedQuery,
  readChunks,
  readImage,
  resolve,
  linkResource,
  linkFile,
  // Write ops appear only when a write key is present — keeps the default
  // connection read-only (VAULT_WRITE_METHODS.md §7).
  ...(config.writeKey ? [ingest] : []),
];

const toolHandlers = new Map(TOOLS.map((t) => [t.definition.name, t.handler]));

// ── Server setup ──────────────────────────────────────────────────────────────

const server = new Server(
  { name: 'tydal-vault-mcp', version: '1.0.0' },
  { capabilities: { tools: {} } },
);

server.setRequestHandler(ListToolsRequestSchema, async () => ({
  tools: TOOLS.map((t) => t.definition),
}));

server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args = {} } = request.params;

  const handler = toolHandlers.get(name);
  if (!handler) {
    return {
      content: [{ type: 'text', text: JSON.stringify({ error: `Unknown tool: ${name}` }) }],
      isError: true,
    };
  }

  try {
    const result = await handler(args);

    // A handler may return a ready-made MCP payload (e.g. read_image's image
    // block). Pass it through untouched instead of stringifying it as text.
    if (
      typeof result === 'object' && result !== null
      && Array.isArray((result as { content?: unknown }).content)
    ) {
      return result as { content: unknown[] };
    }

    const isError = typeof result === 'object' && result !== null && 'error' in result;

    return {
      content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
      ...(isError ? { isError: true } : {}),
    };
  } catch (err) {
    return {
      content: [{ type: 'text', text: JSON.stringify({ error: String(err) }) }],
      isError: true,
    };
  }
});

const transport = new StdioServerTransport();
await server.connect(transport);
console.error('TYDAL Vault MCP server running (stdio)');
