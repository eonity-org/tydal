import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';
import type { SemanticTag } from '../types.js';

export const definition: Tool = {
  name: 'sync_tags',
  description:
    'Set the semantic tags on a resource by label, replacing the current set entirely. '
    + 'Existing org tags with matching labels are reused; new labels are created automatically. '
    + 'Pass an empty array to clear all tags.',
  inputSchema: {
    type: 'object',
    required: ['resource_id', 'tags'],
    properties: {
      resource_id: { type: 'string', description: 'Resource UUID' },
      tags: {
        type: 'array',
        items: { type: 'string' },
        description: 'Full list of tag labels to set on the resource (replaces current tags)',
      },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { resource_id, tags } = args as { resource_id: string; tags: string[] };

  try {
    // 1. Fetch all existing org tags — GET /semantic-tags returns the array
    // directly at .data (no nested .tags key).
    const indexRes = await client.get('/semantic-tags');
    const existing: SemanticTag[] = indexRes.data?.data ?? [];

    // Labels aren't guaranteed unique (only `slug` is, and it auto-suffixes
    // on collision rather than rejecting one) — the backend orders by label
    // with no secondary sort key, so ties have no defined order. Building
    // the map with a naive `new Map(array.map(...))` would let whichever row
    // happens to come back last for a label silently win, which is
    // non-deterministic across calls. Pick the lowest id (the oldest,
    // canonical one) explicitly instead.
    const byLabel = new Map<string, number>();
    for (const t of existing) {
      const key = t.label.toLowerCase();
      const current = byLabel.get(key);
      if (current === undefined || t.id < current) byLabel.set(key, t.id);
    }

    // 2. Resolve each label — create if not found. POST /semantic-tags
    // returns the created tag directly at .data (no nested .tag key).
    const ids: number[] = [];
    for (const label of tags) {
      const key = label.toLowerCase();
      if (byLabel.has(key)) {
        ids.push(byLabel.get(key)!);
      } else {
        const created = await client.post('/semantic-tags', { label });
        const newTag: SemanticTag = created.data?.data;
        ids.push(newTag.id);
        byLabel.set(key, newTag.id);
      }
    }

    // 3. Sync the resource's tags by ID
    const res = await client.put(`/resources/${resource_id}/semantic-tags`, {
      tag_ids: ids,
    });

    return res.data?.data ?? { success: true, synced_tag_ids: ids };
  } catch (err) {
    return { error: apiError(err) };
  }
}
