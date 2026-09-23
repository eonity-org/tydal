/**
 * search_* — ranked retrieval (Tier 0 for resources; Tier 1 for chunk
 * content). §7.1. The RAG loop: search_* → read_chunks → link_* only if the
 * task truly needs the artifact. Both tools take mode "keyword" (literal,
 * default) or "semantic" (k-NN over the embeddings, Epic 4.1); the response's
 * `mode` reports which one actually answered — semantic degrades to keyword
 * when the vault index or the embedder is unavailable.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

export const searchResources = {
  definition: {
    name: 'search_resources',
    description:
      'Search over the vault\'s identity fields (names, descriptions, tags). '
      + 'mode "semantic" ranks by meaning (k-NN over resource embeddings — best for '
      + 'conceptual queries and paraphrases); default "keyword" matches literally. '
      + 'Returns paged Tier 0 identity cards; the response reports the mode that '
      + 'actually answered.',
    inputSchema: {
      type: 'object',
      required: ['q'],
      properties: {
        q: { type: 'string', description: 'Search query' },
        mode: { type: 'string', enum: ['keyword', 'semantic'], description: 'Ranking mode (default keyword)' },
        page: { type: 'number', description: 'Page number (default 1)' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    try {
      const res = await client.get(vaultPath('search'), { params: args });
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const searchChunks = {
  definition: {
    name: 'search_chunks',
    description:
      'Search over chunk CONTENT across the vault (Tier 1 — requires a vault that '
      + 'exposes chunks). mode "semantic" ranks passages by meaning (k-NN over chunk '
      + 'vectors — best for questions and paraphrases); default "keyword" matches '
      + 'literally. Returns matching text passages with their resource, position and '
      + 'relevance score, ready to cite as evidence. Hits are raw — no server-side '
      + 'relevance cutoff — so apply your own score threshold when assembling context.',
    inputSchema: {
      type: 'object',
      required: ['q'],
      properties: {
        q: { type: 'string', description: 'Search query' },
        mode: { type: 'string', enum: ['keyword', 'semantic'], description: 'Ranking mode (default keyword)' },
        limit: { type: 'number', description: 'Max passages (default 20, max 100)' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    try {
      const res = await client.get(vaultPath('search'), {
        params: { ...args, scope: 'chunks' },
      });
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
