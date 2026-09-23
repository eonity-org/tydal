/**
 * resolve — address ↔ entity translation (Tier 0). §7.1. Accepts any address
 * form the vault system mints and returns the entity's identity card.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';
import { config } from '../config.js';

export const resolve = {
  definition: {
    name: 'resolve',
    description:
      'Translate an address into the entity it names: a full /v/… or /h/… URL or path, '
      + '"resourceSlug", or "resourceSlug/fileSlug". Returns the entity\'s identity card. '
      + 'Addresses outside the connected vault are rejected.',
    inputSchema: {
      type: 'object',
      required: ['address'],
      properties: {
        address: { type: 'string', description: 'URL, path, or slug to resolve' },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { address } = args as { address: string };

    let path = address.trim();

    // Full URL → path
    try {
      path = new URL(path).pathname;
    } catch {
      /* not a URL — keep as-is */
    }

    // Absolute /v/… or /h/… path → must stay inside the scoped vault
    if (path.startsWith('/v/') || path.startsWith('/h/')) {
      if (!path.startsWith(config.vaultPath + '/') && path !== config.vaultPath) {
        return { error: `Address is outside the connected vault (${config.vaultPath})` };
      }
      path = path.slice(config.vaultPath.length).replace(/^\//, '');
    }

    const segments = path === '' ? [] : path.split('/').filter(Boolean);

    if (segments.length > 2) {
      return { error: `Cannot resolve "${address}": expected vault, resource, or resource/file` };
    }

    try {
      const res = await client.get(vaultPath(...segments, 'meta'));
      return res.data;
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
