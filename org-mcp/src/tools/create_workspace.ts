import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'create_workspace',
  description:
    'Create a new workspace in the current organisation. '
    + 'Returns the created workspace including its ID, which can be used '
    + 'with add_resource_to_workspace to populate it.',
  inputSchema: {
    type: 'object',
    required: ['name'],
    properties: {
      name:        { type: 'string', description: 'Workspace name (unique within the organisation)' },
      description: { type: 'string', description: 'Optional description' },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { name, description } = args as { name: string; description?: string };
  try {
    const res = await client.post('/workspaces', { name, description });
    return res.data?.data?.workspace ?? res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
