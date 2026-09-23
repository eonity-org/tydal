/**
 * read_* — actual content payload (Tier 1). §7.1. Chunks are addressed
 * through their resource — never separately hashed or slugged.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

export const readChunks = {
  definition: {
    name: 'read_chunks',
    description:
      'Read chunk content of a resource (Tier 1), optionally restricted to a sequence range. '
      + 'Use list_chunks first to see what exists, then read the range you need.',
    inputSchema: {
      type: 'object',
      required: ['slug'],
      properties: {
        slug: { type: 'string', description: 'Resource slug within this vault' },
        from: { type: 'number', description: 'First sequence number (inclusive)' },
        to: { type: 'number', description: 'Last sequence number (inclusive)' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { slug, ...range } = args as { slug: string; from?: number; to?: number };
    try {
      const res = await client.get(vaultPath(slug, 'chunks'), { params: range });
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
