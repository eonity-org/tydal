import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'update_resource_metadata',
  description:
    'Update the metadata of an existing resource. All fields are optional — '
    + 'only the provided fields are changed. '
    + '`metadata` is a free-form object of collection-defined fields (e.g. language, format, audience). '
    + 'Triggers a re-index in Elasticsearch automatically. Returns a short confirmation — the fields '
    + 'you changed, as TYDAL saved them, and the update time; use get_resource for the full record.',
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
    const saved = res.data?.data?.resource;
    return saved ? confirmation(saved, fields) : res.data;
  } catch (err) {
    return { error: apiError(err) };
  }
}

/**
 * Only what the call changed, read back from TYDAL's saved record. The full
 * resource can run to ~100 KB (raw EXIF/XMP, ICC curves, a 1024-number
 * embedding) — returned on every update it fills an agent's context within a
 * few edits of a bulk job.
 */
function confirmation(saved: Record<string, any>, fields: Record<string, unknown>): Record<string, unknown> {
  const changed: Record<string, unknown> = {};
  for (const key of Object.keys(fields)) {
    if (key === 'metadata' && fields.metadata && typeof fields.metadata === 'object') {
      const metadata: Record<string, unknown> = {};
      for (const k of Object.keys(fields.metadata as object)) metadata[k] = saved.metadata?.[k] ?? null;
      changed.metadata = metadata;
    } else {
      changed[key] = saved[key] ?? null;
    }
  }

  return { id: saved.id, updated: Object.keys(fields), ...changed, updated_at: saved.updated_at ?? null };
}
