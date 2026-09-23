/**
 * list_* — enumerate children, paged, identity-only (Tier 0). §7.1.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

export const listResources = {
  definition: {
    name: 'list_resources',
    description:
      'Paged Tier 0 index of the vault: identity cards (name, slug, description, tags, '
      + 'addresses). Navigable by tag or category. This is where resource slugs come from.',
    inputSchema: {
      type: 'object',
      properties: {
        page: { type: 'number', description: 'Page number (default 1)' },
        per_page: { type: 'number', description: 'Cards per page (default 20, max 100)' },
        tag: { type: 'string', description: 'Only resources carrying this semantic tag' },
        category: { type: 'string', description: 'Only resources in this category (slug or name)' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    try {
      const res = await client.get(vaultPath('resources'), { params: args });
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const listFiles = {
  definition: {
    name: 'list_files',
    description:
      'Ordered file entries of a resource — the manifest view: position, filename, slug, '
      + 'mime type, size, role. Binary URLs are included only if the vault exposes Tier 2.',
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
      const res = await client.get(vaultPath(slug, 'files'));
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const listRelated = {
  definition: {
    name: 'list_related',
    description:
      'Resources in the same vault sharing at least one semantic tag with the given resource '
      + '(Tier 0 identity cards).',
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
      const res = await client.get(vaultPath(slug, 'related'));
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const listChunks = {
  definition: {
    name: 'list_chunks',
    description:
      'Chunk INDEX of a resource — sequence numbers, page numbers, and source files, without '
      + 'the text (Tier 0). Use read_chunks to get the content of a range.',
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
      const res = await client.get(vaultPath(slug, 'chunks'));
      const items = (res.data?.items ?? []) as Array<Record<string, unknown>>;
      return {
        type: 'chunk-index',
        items: items.map(({ content: _content, ...rest }) => rest),
      };
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
