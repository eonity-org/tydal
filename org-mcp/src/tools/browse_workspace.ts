import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'browse_workspace',
  description:
    'List and filter resources (digital assets) in a workspace, with facet counts. '
    + 'Returns paged results with metadata and available filter options. '
    + 'Use `search_workspace` instead when you have a keyword or semantic query.',
  inputSchema: {
    type: 'object',
    required: ['workspace_id'],
    properties: {
      workspace_id: { type: 'string', description: 'Workspace ID' },
      page:         { type: 'integer', description: 'Page number (default 1)' },
      limit:        { type: 'integer', description: 'Results per page (default 20, max 100)' },
      sort_by:      { type: 'string',  description: 'Sort field: updated_at | name | id', enum: ['updated_at', 'name', 'id'] },
      sort_dir:     { type: 'string',  description: 'Sort direction: asc | desc', enum: ['asc', 'desc'] },
      facets:       {
        type: 'object',
        description: 'Facet filters as key → array of values (e.g. { "language": ["en", "es"] })',
        additionalProperties: { type: 'array', items: { type: 'string' } },
      },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { workspace_id, page, limit, sort_by, sort_dir, facets } = args as {
    workspace_id: string;
    page?: number;
    limit?: number;
    sort_by?: string;
    sort_dir?: string;
    facets?: Record<string, string[]>;
  };

  try {
    // Build facets query params: facets[key][]=value
    const params: Record<string, unknown> = {
      page:     page     ?? 1,
      limit:    limit    ?? 20,
      sort_by:  sort_by  ?? 'updated_at',
      sort_dir: sort_dir ?? 'desc',
    };
    if (facets) {
      for (const [key, values] of Object.entries(facets)) {
        params[`facets[${key}][]`] = values;
      }
    }

    const res = await client.get(`/workspaces/${workspace_id}/catalogue`, { params });
    return res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
