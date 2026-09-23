#!/usr/bin/env node

/**
 * TYDAL MCP Server — TypeScript entry point.
 *
 * Registers all workspace and resource tools with the MCP SDK and connects
 * to the stdio transport so Claude Desktop, Claude Code, Cursor, and other
 * MCP-compatible clients can call TYDAL without direct REST API knowledge.
 *
 * Required environment variables:
 *   TYDAL_TOKEN      Sanctum personal access token
 *   TYDAL_ORG_ID     Organisation UUID (sets X-Organization-ID on every request)
 *
 * Optional:
 *   TYDAL_BASE_URL   REST API base URL (default: http://localhost:8000/api/v1)
 */

import { Server }              from '@modelcontextprotocol/sdk/server/index.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

// ── Tool imports ──────────────────────────────────────────────────────────────

import * as listWorkspaces           from './tools/list_workspaces.js';
import * as browseWorkspace          from './tools/browse_workspace.js';
import * as searchWorkspace          from './tools/search_workspace.js';
import * as getResource              from './tools/get_resource.js';
import * as listResourceChunks       from './tools/list_resource_chunks.js';
import * as getVaultLinks              from './tools/get_vault_links.js';
import * as askWorkspace             from './tools/ask_workspace.js';
import * as askVault                 from './tools/ask_vault.js';
import * as createWorkspace          from './tools/create_workspace.js';
import * as addResourceToWorkspace   from './tools/add_resource_to_workspace.js';
import * as removeResourceFromWorkspace from './tools/remove_resource_from_workspace.js';
import * as updateResourceMetadata   from './tools/update_resource_metadata.js';
import * as syncTags                 from './tools/sync_tags.js';
import * as uploadFile               from './tools/upload_file.js';

// ── Registry ──────────────────────────────────────────────────────────────────

const TOOLS = [
  listWorkspaces,
  browseWorkspace,
  searchWorkspace,
  getResource,
  listResourceChunks,
  getVaultLinks,
  askWorkspace,
  askVault,
  createWorkspace,
  addResourceToWorkspace,
  removeResourceFromWorkspace,
  updateResourceMetadata,
  syncTags,
  uploadFile,
];

const toolHandlers = new Map(
  TOOLS.map((t) => [t.definition.name, t.handler]),
);

// ── Server setup ──────────────────────────────────────────────────────────────

const server = new Server(
  { name: 'tydal-mcp', version: '1.0.0' },
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

  const result  = await handler(args as Record<string, unknown>);
  const isError = typeof result === 'object' && result !== null && 'error' in result;

  return {
    content: [{ type: 'text', text: JSON.stringify(result, null, 2) }],
    isError,
  };
});

// ── Start ─────────────────────────────────────────────────────────────────────

const transport = new StdioServerTransport();
await server.connect(transport);
