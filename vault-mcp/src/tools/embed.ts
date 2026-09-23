/**
 * embed_query — the query-embedding compute tool (Epic 4.1; diagram
 * `embedQuery`). Puts the consumer's query into the vault's own vector space
 * so the consuming AI can do its own similarity work. Pure compute: no vault
 * data leaves, no reasoning happens — TYDAL exposes data and tools, never
 * answers.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

export const embedQuery = {
  definition: {
    name: 'embed_query',
    description:
      'Embed a text query into the vault\'s vector space. Returns the embedding model, '
      + 'its dimensions, and the vector — use it to run your own similarity math over '
      + 'chunk or resource vectors. For ranked retrieval, prefer search_resources / '
      + 'search_chunks with mode "semantic", which embed for you.',
    inputSchema: {
      type: 'object',
      required: ['text'],
      properties: {
        text: { type: 'string', description: 'Text to embed' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    try {
      const res = await client.get(vaultPath('embed'), { params: { q: args.text } });
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
