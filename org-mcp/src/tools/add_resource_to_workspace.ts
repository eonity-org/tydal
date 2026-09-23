import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'add_resource_to_workspace',
  description:
    'Add a resource (digital asset) to a workspace. '
    + 'Safe to call on a resource already in the workspace — it is idempotent. '
    + 'Cannot be used on the default workspace (all org resources are always included there).',
  inputSchema: {
    type: 'object',
    required: ['workspace_id', 'resource_id'],
    properties: {
      workspace_id: { type: 'string', description: 'Target workspace ID' },
      resource_id:  { type: 'string', description: 'Resource UUID to add' },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { workspace_id, resource_id } = args as { workspace_id: string; resource_id: string };
  try {
    const res = await client.post(`/workspaces/${workspace_id}/resources`, {
      resource_id,
    });
    return { success: true, message: res.data?.message ?? 'Resource added to workspace' };
  } catch (err) {
    return { error: apiError(err) };
  }
}
