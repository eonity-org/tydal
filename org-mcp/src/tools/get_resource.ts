import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'get_resource',
  description:
    'Retrieve a single TYDAL resource in one call: name, description, slug, tags, '
    + 'custom metadata, files (each with a direct URL, MIME type, role, and its own '
    + 'Vault links if published), resource-level Vault links, chunk/embedding availability, '
    + 'and workspace memberships. Use `list_resource_chunks` to read the underlying '
    + 'extracted text, or `get_vault_links` if you only need links without the rest.',
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
    const res = await client.get(`/resources/${resource_id}/agent-view`);
    return res.data?.data?.resource ?? res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
