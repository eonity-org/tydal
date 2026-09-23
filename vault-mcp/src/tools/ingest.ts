/**
 * ingest — the write counterpart to the read tiers (VAULT_WRITE_METHODS.md §7).
 * Only registered when TYDAL_VAULT_WRITE_KEY is set, so a connection stays
 * read-only until a write-capable key is deliberately provided.
 *
 * This is the "plug any AI" write-back: an MCP-capable model reads the vault's
 * images (get_/link_/read_ tools), transforms them however it likes, and posts
 * the result back through the vault's own `ingest` op — a translated image plus
 * a JSON descriptor document — with no product-side glue. The vault decides
 * where the output lands (its configured ingest workspace/collection); the AI
 * only declares *what*.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';
import { config } from '../config.js';

export const ingest = {
  definition: {
    name: 'ingest',
    description:
      'Write a derived artifact back to this vault (ai vaults only): a translated/processed '
      + 'image plus a JSON descriptor of its tables, graphs, and formulae. Creates one output '
      + 'resource in the vault\'s configured ingest target. Requires a write key with the '
      + 'w:ingest ability. Returns the new resource\'s vault link hash (use get_/read_ tools '
      + 'with it, same as any other resource in this vault).',
    inputSchema: {
      type: 'object',
      required: ['descriptor', 'image_base64'],
      properties: {
        descriptor: {
          type: 'object',
          description:
            'The figure descriptor document (tables/graphs/formulae). See the source vault\'s '
            + 'consuming product for the schema; stored as the output resource\'s canonical JSON.',
        },
        image_base64: {
          type: 'string',
          description: 'The derived image, base64-encoded (no data: prefix).',
        },
        image_mime: {
          type: 'string',
          description: 'MIME type of the image (default image/png).',
        },
        name: { type: 'string', description: 'Optional name for the output resource.' },
        source_hash: {
          type: 'string',
          description: 'Optional vault link hash of the source image, for provenance.',
        },
      },
    },
  } satisfies Tool,

  async handler(args: Record<string, unknown>): Promise<unknown> {
    if (!config.writeKey) {
      return { error: 'No write key configured (set TYDAL_VAULT_WRITE_KEY).' };
    }

    const {
      descriptor,
      image_base64,
      image_mime,
      name,
      source_hash,
    } = args as {
      descriptor: unknown;
      image_base64: string;
      image_mime?: string;
      name?: string;
      source_hash?: string;
    };

    if (typeof descriptor !== 'object' || descriptor === null) {
      return { error: 'descriptor must be a JSON object.' };
    }
    if (typeof image_base64 !== 'string' || image_base64.length === 0) {
      return { error: 'image_base64 is required.' };
    }

    const form = new FormData();
    form.append('descriptor', JSON.stringify(descriptor));
    form.append('image', new Blob([Buffer.from(image_base64, 'base64')], { type: image_mime ?? 'image/png' }), 'image');
    if (name) form.append('name', name);
    if (source_hash) form.append('source_hash', source_hash);

    try {
      // The write op uses the WRITE key, distinct from the read key the client
      // sends by default — override X-Vault-Key on this request only. `fetch`
      // sets the multipart Content-Type/boundary itself from the FormData body.
      const res = await client.post(vaultPath('w', 'ingest'), form, {
        headers: { 'X-Vault-Key': config.writeKey },
      });
      const body = res.data as { ok?: boolean; result?: unknown; error?: string };
      if (!body.ok) return { error: body.error ?? 'ingest was refused' };
      return { type: 'ingest', result: body.result };
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
