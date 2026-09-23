import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError } from '../client.js';
import type { VaultLinkEntry, VaultLinksResponse } from '../types.js';

export const definition: Tool = {
  name: 'get_vault_links',
  description:
    'Retrieve Vault public URLs for a resource and all its files. '
    + 'Each link includes a browseable URL, an optional direct download URL, '
    + 'the Vault provider name, and an expiry flag. '
    + 'Use this to surface deep links to assets in gallery, tutor, or jury applications.',
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
    const res = await client.get(`/resources/${resource_id}/vault-links`);
    const data: VaultLinksResponse = res.data?.data ?? res.data;

    // Flatten to a single list for easier consumption by AI agents
    const resourceLinks: VaultLinkEntry[] = data.resource?.links ?? [];
    const fileLinks: Array<{ file_id: string } & VaultLinkEntry> = (data.files ?? []).flatMap(
      (f) => (f.links ?? []).map((l) => ({ file_id: f.file_id, ...l })),
    );

    return {
      resource_id,
      resource_links: resourceLinks,
      file_links:     fileLinks,
    };
  } catch (err) {
    return { error: apiError(err) };
  }
}
