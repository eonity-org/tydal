import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'ask_workspace',
  description:
    'Answer a natural-language question using RAG (retrieval-augmented generation) '
    + 'over the vector-indexed documents in a TYDAL workspace. '
    + 'Returns a synthesised answer and the source passages used, including resource names and page numbers. '
    + 'Requires that the workspace resources have been processed through the embedding pipeline.',
  inputSchema: {
    type: 'object',
    required: ['workspace_id', 'question'],
    properties: {
      workspace_id: { type: 'string',  description: 'Workspace ID' },
      question:     { type: 'string',  description: 'The question to answer' },
      k:            { type: 'integer', description: 'Number of document chunks to retrieve (1–20, default 5)' },
      strict:       {
        type: 'boolean',
        description:
          'When true, AI-generated metadata (name, description, tags) is excluded — '
          + 'only raw extracted document text is used as context. Default false.',
      },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { workspace_id, question, k, strict } = args as {
    workspace_id: string;
    question: string;
    k?: number;
    strict?: boolean;
  };
  try {
    const res = await client.post(`/workspaces/${workspace_id}/ask`, {
      question,
      k:      k      ?? 5,
      strict: strict ?? false,
    });
    return res.data?.data ?? res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
