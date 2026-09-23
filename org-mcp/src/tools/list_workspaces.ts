import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';
import type { Workspace } from '../types.js';

export const definition: Tool = {
  name: 'list_workspaces',
  description:
    'List all workspaces available to the authenticated user in the current organisation. '
    + 'Returns workspace id, name, description, and whether it is the default workspace. '
    + 'Use this first to discover available workspace IDs before calling other workspace tools.',
  inputSchema: {
    type: 'object',
    properties: {
      page:     { type: 'integer', description: 'Page number (default 1)' },
      per_page: { type: 'integer', description: 'Results per page (default 20, max 100)' },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  try {
    const res = await client.get('/workspaces', {
      params: {
        page:     args.page     ?? 1,
        per_page: args.per_page ?? 20,
      },
    });
    const workspaces: Workspace[] = res.data?.data?.workspaces ?? [];
    return {
      total:      res.data?.meta?.pagination?.total ?? workspaces.length,
      workspaces: workspaces.map((w) => ({
        id:          w.id,
        name:        w.name,
        description: w.description ?? null,
        is_default:  w.is_default ?? false,
      })),
    };
  } catch (err) {
    return { error: apiError(err) };
  }
}
