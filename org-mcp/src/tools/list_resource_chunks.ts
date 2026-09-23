import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'list_resource_chunks',
  description:
    'Retrieve a resource\'s extracted text in reading order — raw chunks with page numbers, '
    + 'not a synthesized answer. Use this to read the actual source content directly; '
    + 'use `ask_workspace` instead when you have a question and want a synthesized answer '
    + 'with citations. Returns an empty list if the resource has not been chunked/embedded yet '
    + 'or its collection has no search index configured.',
  inputSchema: {
    type: 'object',
    required: ['resource_id'],
    properties: {
      resource_id: { type: 'string', description: 'Resource UUID' },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { resource_id } = args as { resource_id: string };
  try {
    const res = await client.get(`/resources/${resource_id}/chunks`);
    return res.data?.data ?? res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
