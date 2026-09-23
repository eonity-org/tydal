import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'search_workspace',
  description:
    'Search for resources in a workspace using a natural-language or keyword query. '
    + 'When Elasticsearch is configured the search is hybrid (BM25 + semantic k-NN); '
    + 'otherwise it falls back to a database full-text search. '
    + 'Returns paged resources with facet counts.',
  inputSchema: {
    type: 'object',
    required: ['workspace_id', 'query'],
    properties: {
      workspace_id: { type: 'string',  description: 'Workspace ID' },
      query:        { type: 'string',  description: 'Search query' },
      page:         { type: 'integer', description: 'Page number (default 1)' },
      limit:        { type: 'integer', description: 'Results per page (default 20, max 100)' },
      search_mode:  {
        type: 'string',
        description: 'Match mode: prefix (default) | contains | exact',
        enum: ['prefix', 'contains', 'exact'],
      },
      sort_by:  { type: 'string', enum: ['updated_at', 'name', 'id'] },
      sort_dir: { type: 'string', enum: ['asc', 'desc'] },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { workspace_id, query, page, limit, search_mode, sort_by, sort_dir } = args as {
    workspace_id: string;
    query: string;
    page?: number;
    limit?: number;
    search_mode?: string;
    sort_by?: string;
    sort_dir?: string;
  };

  try {
    const res = await client.get(`/workspaces/${workspace_id}/catalogue`, {
      params: {
        search:      query,
        search_mode: search_mode ?? 'prefix',
        page:        page     ?? 1,
        limit:       limit    ?? 20,
        sort_by:     sort_by  ?? 'updated_at',
        sort_dir:    sort_dir ?? 'desc',
      },
    });
    return res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
