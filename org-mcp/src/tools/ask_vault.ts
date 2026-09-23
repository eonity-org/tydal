import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'ask_vault',
  description:
    'Answer a natural-language question over ONE vault — a curated projection of resources. '
    + 'AITY retrieves through the vault\'s own tier-gated operations (semantic chunk search, '
    + 'identity cards, knowledge-graph neighbors) and synthesises an answer with '
    + 'resource-level citations. Use list_workspaces/get_vault_links to discover vault ids.',
  inputSchema: {
    type: 'object',
    required: ['vault_id', 'question'],
    properties: {
      vault_id: { type: 'string', description: 'Vault UUID' },
      question: { type: 'string', description: 'The question to answer' },
      k: { type: 'integer', description: 'Passages/cards to retrieve (1–20, default 5)' },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { vault_id, question, k } = args as {
    vault_id: string;
    question: string;
    k?: number;
  };
  try {
    const res = await client.post(`/vaults/${vault_id}/ask`, {
      question,
      k: k ?? 5,
    });
    return res.data?.data ?? res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
