import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'remove_resource_from_workspace',
  description:
    'Remove a resource from a workspace. '
    + 'The resource is NOT deleted — it remains in the organisation and its collection. '
    + 'Cannot be used on the default workspace.',
  inputSchema: {
    type: 'object',
    required: ['workspace_id', 'resource_id'],
    properties: {
      workspace_id: { type: 'string', description: 'Workspace ID' },
      resource_id:  { type: 'string', description: 'Resource UUID to remove' },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { workspace_id, resource_id } = args as { workspace_id: string; resource_id: string };
  try {
    const res = await client.delete(`/workspaces/${workspace_id}/resources/${resource_id}`);
    return { success: true, message: res.data?.message ?? 'Resource removed from workspace' };
  } catch (err) {
    return { error: apiError(err) };
  }
}
