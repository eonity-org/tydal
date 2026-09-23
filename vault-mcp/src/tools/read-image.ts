/**
 * read_image — stream a resource's actual pixels back to the model as an image
 * content block (not a URL). This is the Tier 2 *inline preview* (viewing, not
 * taking a copy — gated by the vault's binary exposure, not by is_downloadable),
 * fetched server-side so it works even when the TYDAL address (e.g.
 * host.docker.internal) is only reachable from inside this container.
 *
 * link_resource / link_file mint a URL the *caller's* environment must fetch;
 * an MCP client (Claude Desktop) usually cannot reach an internal address, and
 * cannot "see" a URL as an image anyway. read_image closes that gap: it is how a
 * bring-your-own-AI actually looks at the figure it is about to describe/ingest.
 */

import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { client, apiError, vaultPath } from '../client.js';

const renditions = ['ai-prepared', 'original', 'thumbnail', 'small', 'medium', 'large'];
const defaultMaxBytes = 5 * 1024 * 1024;

function dimension(value: unknown): number | null {
  const n = Number(value);
  return Number.isSafeInteger(n) && n > 0 ? n : null;
}

export const readImage = {
  definition: {
    name: 'read_image',
    description:
      "Fetch the actual pixels of a resource's image and return them so you can SEE the figure — "
      + 'use this whenever you need to look at the image itself (e.g. to read a table, graph, formula '
      + 'or diagram and describe it), not just its metadata. Returns the image inline (Tier 2 preview: '
      + 'viewing, not downloading a copy), fetched server-side — so it works even when link_resource '
      + 'would hand back an address your environment cannot reach. Denied if the vault does not expose '
      + 'binary. Defaults to ai-prepared (5 MiB maximum before base64): preserves fitting bytes except PNG orientation normalization, '
      + 'otherwise uses high-quality JPEG and reduces dimensions only as needed. Choose original for unchanged '
      + 'source bytes or an advertised thumbnail/small/medium/large rendition. Returns actual image metadata too. '
      + 'Resource references come from list_resources or search_resources.',
    inputSchema: {
      type: 'object',
      required: ['resource_slug'],
      properties: {
        resource_slug: { type: 'string', description: 'Resource reference within this vault (hash for a hash connection, slug for a slug connection)' },
        rendition: { type: 'string', enum: renditions, default: 'ai-prepared' },
        max_bytes: {
          type: 'integer', minimum: 65536, maximum: 20971520,
          description: 'AI-prepared binary byte limit before base64 (default 5242880). Only valid with ai-prepared.',
        },
      },
    },
  } satisfies Tool,
  async handler(args: Record<string, unknown>): Promise<unknown> {
    const { resource_slug, max_bytes } = args;
    const rendition = args.rendition ?? 'ai-prepared';
    if (typeof resource_slug !== 'string' || !resource_slug.trim()) {
      return { error: 'resource_slug must be a non-empty resource reference' };
    }
    if (typeof rendition !== 'string' || !renditions.includes(rendition)) {
      return { error: `rendition must be one of: ${renditions.join(', ')}` };
    }
    if (max_bytes !== undefined && (rendition !== 'ai-prepared' || typeof max_bytes !== 'number'
      || !Number.isSafeInteger(max_bytes) || max_bytes < 65536 || max_bytes > 20971520)) {
      return { error: 'max_bytes must be an integer between 65536 and 20971520, only with ai-prepared' };
    }
    const maxBytes = typeof max_bytes === 'number' ? max_bytes : defaultMaxBytes;
    try {
      const res = await client.getBinary(vaultPath(resource_slug, 'preview'), {
        params: { rendition, ...(max_bytes !== undefined ? { max_bytes } : {}) },
        ...(rendition === 'ai-prepared' ? { maxBytes } : {}),
        headers: { Accept: 'image/*' },
      });
      const mimeType = (res.headers.get('content-type') ?? 'application/octet-stream')
        .split(';')[0]
        .trim();

      if (!mimeType.startsWith('image/')) {
        return { error: `Resource "${resource_slug}" preview is ${mimeType}, not a viewable image` };
      }

      if (res.headers.get('x-tydal-image-rendition') !== rendition) {
        return { error: 'TYDAL did not confirm the requested image rendition. Update the server or check the rendition URL.' };
      }
      const bytes = Buffer.from(res.data);
      if (rendition === 'ai-prepared' && bytes.length > maxBytes) {
        return { error: 'Prepared image exceeds the requested byte limit' };
      }
      const metadata = {
        rendition, mime_type: mimeType, bytes: bytes.length,
        width: dimension(res.headers.get('x-tydal-image-width')),
        height: dimension(res.headers.get('x-tydal-image-height')),
        ...(rendition === 'ai-prepared' ? { max_bytes: maxBytes } : {}),
      };
      // A ready-made MCP content payload — index.ts passes this through as-is.
      return { content: [
        { type: 'image', data: bytes.toString('base64'), mimeType },
        { type: 'text', text: JSON.stringify(metadata) },
      ] };
    } catch (err) {
      return { error: apiError(err) };
    }
  },
};
