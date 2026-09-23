/**
 * get_* — one entity's identity card (Tier 0). VAULT_SYSTEM.md §7.1.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

export const getVault = {
  definition: {
    name: 'get_vault',
    description:
      'Self-description of the connected vault: name, purpose, organization, resource count, '
      + 'which tiers this vault exposes (identity / chunks / binary), which file roles are '
      + 'addressed, and the available operations. Call this first — it tells you what you may do.',
    inputSchema: { type: 'object', properties: {} },
  } satisfies Tool,
  async handler(): Promise<unknown> {
    try {
      const res = await client.get(vaultPath('meta'));
      const data = res.data as { tiers?: { binary?: boolean } } | null;

      // The backend's capability grammar is generic (any consumer, not just
      // this MCP surface); this tool-name mapping is specific to vault-mcp,
      // so it's added here rather than baked into the backend response.
      // Discoverability, not documentation — an agent that never has reason
      // to load read_image's own description otherwise won't learn it's the
      // AI-appropriate path for binary content until link_resource already
      // failed for it.
      if (data && typeof data === 'object' && data.tiers?.binary) {
        return {
          ...data,
          binary_access: {
            read_image: 'inline fetch of actual bytes — use this if you need to see/process the content yourself',
            link_resource: 'mints a URL for a downstream consumer with network access to the server (e.g. a human, a browser) — not for you to fetch',
          },
        };
      }

      return data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const getResource = {
  definition: {
    name: 'get_resource',
    description:
      'Identity card of one resource in the vault: name, description, tags, addresses, '
      + 'file count, and whether chunk content is available. Slugs come from list_resources '
      + 'or search_resources.',
    inputSchema: {
      type: 'object',
      required: ['slug'],
      properties: {
        slug: { type: 'string', description: 'Resource slug within this vault' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { slug } = args as { slug: string };
    try {
      const res = await client.get(vaultPath(slug, 'meta'));
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const getFile = {
  definition: {
    name: 'get_file',
    description:
      'Identity card of one file of a resource: filename, mime type, size, role, and its '
      + 'position in the manifest. File slugs come from list_files or the resource manifest.',
    inputSchema: {
      type: 'object',
      required: ['resource_slug', 'file_slug'],
      properties: {
        resource_slug: { type: 'string', description: 'Resource slug within this vault' },
        file_slug: { type: 'string', description: 'File slug within the resource' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { resource_slug, file_slug } = args as { resource_slug: string; file_slug: string };
    try {
      const res = await client.get(vaultPath(resource_slug, file_slug, 'meta'));
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
