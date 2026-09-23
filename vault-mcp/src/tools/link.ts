/**
 * link_* — mint Tier 2 URLs; NEVER streams binary. §7.1. The vault's policy
 * decides whether Tier 2 is exposed at all (ai vaults say no by default).
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

interface LinksPayload {
  resource?: { slug?: string; url?: string };
  files?: Array<{ filename?: string; slug?: string; url?: string }>;
}

export const linkResource = {
  definition: {
    name: 'link_resource',
    description:
      'Mint the Tier 2 binary URL for a resource (single exposed file → the binary; several → '
      + 'the manifest address). Returns a URL for a downstream consumer with network access to '
      + 'the server — never bytes, and not necessarily reachable from you. AI clients that need '
      + 'to actually view the content (e.g. an image) should use read_image instead. Denied on '
      + 'vaults that do not expose Tier 2.',
    inputSchema: {
      type: 'object',
      required: ['slug'],
      properties: {
        slug: { type: 'string', description: 'Resource slug within this vault' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { slug } = args as { slug: string };
    try {
      const res = await client.get(vaultPath(slug, 'links'));
      const data = res.data as LinksPayload;
      return { type: 'link', slug: data.resource?.slug ?? slug, url: data.resource?.url ?? null };
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};

export const linkFile = {
  definition: {
    name: 'link_file',
    description:
      'Mint the Tier 2 binary URL for one file of a resource. Returns a URL for a downstream '
      + 'consumer with network access to the server — never bytes, and not necessarily '
      + 'reachable from you. AI clients that need to actually view the content (e.g. an image) '
      + 'should use read_image instead. Denied on vaults that do not expose Tier 2.',
    inputSchema: {
      type: 'object',
      required: ['resource_slug', 'file_slug'],
      properties: {
        resource_slug: { type: 'string', description: 'Resource slug within this vault' },
        file_slug: { type: 'string', description: 'File slug within the resource' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { resource_slug, file_slug } = args as { resource_slug: string; file_slug: string };
    try {
      const res = await client.get(vaultPath(resource_slug, 'links'));
      const data = res.data as LinksPayload;
      const file = (data.files ?? []).find((f) => f.slug === file_slug);

      if (!file) {
        return { error: `No exposed file "${file_slug}" on resource "${resource_slug}"` };
      }

      return { type: 'link', slug: file.slug, filename: file.filename, url: file.url ?? null };
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
