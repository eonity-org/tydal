import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'update_resource_metadata',
  description:
    'Update the metadata of an existing resource. All fields are optional — '
    + 'only the provided fields are changed. '
    + '`metadata` is a free-form object of collection-defined fields (e.g. language, format, audience). '
    + 'Triggers a re-index in Elasticsearch automatically.',
  inputSchema: {
    type: 'object',
    required: ['resource_id'],
    properties: {
      resource_id:  { type: 'string', description: 'Resource UUID' },
      name:         { type: 'string', description: 'New resource name' },
      description:  { type: 'string', description: 'New description' },
      metadata:     {
        type: 'object',
        description: 'Collection-defined metadata fields (key-value pairs)',
        additionalProperties: true,
      },
      language:     { type: 'string', description: 'ISO language code (e.g. "en", "es")' },
      state:        {
        type: 'string',
        description: 'Lifecycle state: draft (unfinished), live (listed and projectable), archived (withdrawn)',
        enum: ['draft', 'live', 'archived'],
      },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { resource_id, ...fields } = args as {
    resource_id: string;
    name?: string;
    description?: string;
    metadata?: Record<string, unknown>;
    language?: string;
    state?: string;
  };
  try {
    const res = await client.put(`/resources/${resource_id}`, fields);
    return res.data?.data?.resource ?? res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}
