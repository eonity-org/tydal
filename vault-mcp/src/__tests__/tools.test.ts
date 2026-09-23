/**
 * Unit tests for the vault MCP tool handlers.
 *
 * `fetch` is mocked directly (vault-mcp's own client, `../client.ts`, is a
 * thin fetch wrapper — no axios) — no running TYDAL backend required. The
 * connection is scoped to the vault set in vitest.config.ts
 * (TYDAL_VAULT=acme/press-kit), so every request must stay under
 * /v/acme/press-kit.
 *
 * Query params travel as real query-string values (strings), unlike the old
 * axios-mock-adapter setup which intercepted the pre-serialization JS object
 * and preserved e.g. numbers — this is the more faithful mock, since that's
 * what a real HTTP request actually carries on the wire.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';

import { getVault, getResource, getFile } from '../tools/get.js';
import { listResources, listChunks } from '../tools/list.js';
import { searchResources, searchChunks } from '../tools/search.js';
import { embedQuery } from '../tools/embed.js';
import { readChunks } from '../tools/read.js';
import { resolve } from '../tools/resolve.js';
import { linkResource, linkFile } from '../tools/link.js';
import { ingest } from '../tools/ingest.js';
import { readImage } from '../tools/read-image.js';

const V = '/v/acme/press-kit';

type ReplyConfig = { headers: Record<string, string>; params: Record<string, string>; data?: string };
type ReplyBody = unknown | Buffer;
type ReplyTuple = [number, ReplyBody, Record<string, string>?];
type ReplyFn = (cfg: ReplyConfig) => ReplyTuple;

/** Minimal fetch mock mirroring the bit of axios-mock-adapter these tests rely on. */
class MockFetch {
  private handlers = new Map<string, { status?: number; body?: ReplyBody; headers?: Record<string, string>; fn?: ReplyFn }>();
  private original = globalThis.fetch;
  history = { get: [] as string[] };

  constructor() {
    globalThis.fetch = this.fetch as typeof fetch;
  }

  private add(method: string, path: string) {
    return {
      reply: (a: number | ReplyFn, b?: ReplyBody, c?: Record<string, string>) => {
        this.handlers.set(`${method} ${path}`, typeof a === 'function' ? { fn: a } : { status: a, body: b, headers: c });
      },
    };
  }
  onGet(path: string) { return this.add('GET', path); }
  onPost(path: string) { return this.add('POST', path); }
  restore() { globalThis.fetch = this.original; }

  private fetch = async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    const href = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    const url = new URL(href);
    const method = (init?.method ?? 'GET').toUpperCase();
    if (method === 'GET') this.history.get.push(href);

    const entry = this.handlers.get(`${method} ${url.pathname}`);
    if (!entry) {
      return new Response(JSON.stringify({ message: `No mock for ${method} ${url.pathname}` }), { status: 404 });
    }

    const reqHeaders: Record<string, string> = { ...(init?.headers as Record<string, string> ?? {}) };
    if (init?.body instanceof FormData && !('Content-Type' in reqHeaders)) {
      // fetch/undici sets this itself for a real FormData body; the mock never
      // reaches that layer, so synthesize what a real request would carry.
      reqHeaders['Content-Type'] = 'multipart/form-data; boundary=----MockFormBoundary';
    }

    const cfg: ReplyConfig = {
      headers: reqHeaders,
      params: Object.fromEntries(url.searchParams),
      data: typeof init?.body === 'string' ? init.body : undefined,
    };

    const [status, body, resHeaders] = entry.fn ? entry.fn(cfg) : [entry.status ?? 200, entry.body, entry.headers];

    const headers = new Headers(resHeaders);
    let responseBody: BodyInit;
    if (Buffer.isBuffer(body)) {
      responseBody = body;
      if (!headers.has('content-type')) headers.set('Content-Type', 'application/octet-stream');
    } else if (body === undefined) {
      responseBody = '';
    } else {
      responseBody = JSON.stringify(body);
      if (!headers.has('content-type')) headers.set('Content-Type', 'application/json');
    }

    return new Response(responseBody, { status, headers });
  };
}

let mock: MockFetch;

beforeEach(() => {
  mock = new MockFetch();
});

afterEach(() => {
  mock.restore();
});

// ── connection scoping ────────────────────────────────────────────────────────

describe('vault-scoped connection', () => {
  it('sends the vault key header on every request', async () => {
    mock.onGet(`${V}/meta`).reply((cfg) => {
      expect(cfg.headers?.['X-Vault-Key']).toMatch(/^tvk_/);
      return [200, { type: 'vault', name: 'Press Kit' }];
    });

    const result = (await getVault.handler()) as any;
    expect(result.name).toBe('Press Kit');
  });
});

// ── get_* (Tier 0) ────────────────────────────────────────────────────────────

describe('get_vault', () => {
  it('returns the vault self-description', async () => {
    mock.onGet(`${V}/meta`).reply(200, {
      type: 'vault',
      purpose: 'ai',
      tiers: { identity: true, chunks: true, binary: false },
    });

    const result = (await getVault.handler()) as any;
    expect(result.purpose).toBe('ai');
    expect(result.tiers.binary).toBe(false);
  });

  it('omits binary_access when the vault does not expose Tier 2', async () => {
    mock.onGet(`${V}/meta`).reply(200, {
      type: 'vault',
      tiers: { identity: true, chunks: true, binary: false },
    });

    const result = (await getVault.handler()) as any;
    expect(result.binary_access).toBeUndefined();
  });

  it('adds a binary_access discoverability hint when the vault exposes Tier 2', async () => {
    mock.onGet(`${V}/meta`).reply(200, {
      type: 'vault',
      tiers: { identity: true, chunks: true, binary: true },
    });

    const result = (await getVault.handler()) as any;
    expect(result.binary_access).toBeDefined();
    expect(result.binary_access.read_image).toContain('use this if you need to see');
    expect(result.binary_access.link_resource).toContain('not for you to fetch');
  });
});

describe('get_resource / get_file', () => {
  it('fetches resource and file identity cards by slug', async () => {
    mock.onGet(`${V}/winter-catalogue/meta`).reply(200, { type: 'resource', name: 'Winter Catalogue' });
    mock.onGet(`${V}/winter-catalogue/cover/meta`).reply(200, { type: 'file', filename: 'cover.jpg' });

    const resource = (await getResource.handler({ slug: 'winter-catalogue' })) as any;
    const file = (await getFile.handler({ resource_slug: 'winter-catalogue', file_slug: 'cover' })) as any;

    expect(resource.name).toBe('Winter Catalogue');
    expect(file.filename).toBe('cover.jpg');
  });

  it('surfaces API errors as { error }', async () => {
    mock.onGet(`${V}/missing/meta`).reply(404, { error: 'Not found or expired' });

    const result = (await getResource.handler({ slug: 'missing' })) as any;
    expect(result.error).toBe('Not found or expired');
  });
});

// ── list_* (Tier 0) ───────────────────────────────────────────────────────────

describe('list_resources', () => {
  it('passes pagination and tag filters through', async () => {
    mock.onGet(`${V}/resources`).reply((cfg) => {
      expect(cfg.params).toMatchObject({ page: '2', tag: 'winter' });
      return [200, { type: 'vault-index', resources: [], pagination: { page: 2 } }];
    });

    const result = (await listResources.handler({ page: 2, tag: 'winter' })) as any;
    expect(result.pagination.page).toBe(2);
  });
});

describe('list_chunks', () => {
  it('returns the chunk index WITHOUT content', async () => {
    mock.onGet(`${V}/doc/chunks`).reply(200, {
      type: 'chunks',
      items: [
        { file_id: 'f1', sequence: 1, page_number: 1, content: 'secret text' },
        { file_id: 'f1', sequence: 2, page_number: 1, content: 'more text' },
      ],
    });

    const result = (await listChunks.handler({ slug: 'doc' })) as any;
    expect(result.type).toBe('chunk-index');
    expect(result.items).toHaveLength(2);
    expect(result.items[0].sequence).toBe(1);
    expect(result.items[0].content).toBeUndefined();
  });
});

// ── search_* ──────────────────────────────────────────────────────────────────

describe('search_resources / search_chunks', () => {
  it('search_resources hits /search with q', async () => {
    mock.onGet(`${V}/search`).reply((cfg) => {
      expect(cfg.params.q).toBe('solar');
      expect(cfg.params.scope).toBeUndefined();
      return [200, { type: 'vault-search', results: [] }];
    });

    const result = (await searchResources.handler({ q: 'solar' })) as any;
    expect(result.type).toBe('vault-search');
  });

  it('search_chunks adds scope=chunks', async () => {
    mock.onGet(`${V}/search`).reply((cfg) => {
      expect(cfg.params).toMatchObject({ q: 'solar', scope: 'chunks' });
      return [200, { type: 'chunk-search', results: [{ content: 'solar panels…', sequence: 3 }] }];
    });

    const result = (await searchChunks.handler({ q: 'solar' })) as any;
    expect(result.results[0].sequence).toBe(3);
  });

  it('surfaces the tier denial on chunk search against a no-chunk vault', async () => {
    mock.onGet(`${V}/search`).reply(403, { error: 'This vault does not expose that tier' });

    const result = (await searchChunks.handler({ q: 'x' })) as any;
    expect(result.error).toContain('tier');
  });

  it('passes mode=semantic through on both search tools', async () => {
    mock.onGet(`${V}/search`).reply((cfg) => {
      expect(cfg.params.mode).toBe('semantic');
      return [200, { type: 'vault-search', mode: 'semantic', results: [] }];
    });

    const resources = (await searchResources.handler({ q: 'cozy scenes', mode: 'semantic' })) as any;
    const chunks = (await searchChunks.handler({ q: 'cozy scenes', mode: 'semantic' })) as any;

    expect(resources.mode).toBe('semantic');
    expect(chunks.mode).toBe('semantic');
  });
});

// ── embed_query (compute) ─────────────────────────────────────────────────────

describe('embed_query', () => {
  it('embeds text via the vault /embed operation', async () => {
    mock.onGet(`${V}/embed`).reply((cfg) => {
      expect(cfg.params.q).toBe('winter landscapes');
      return [200, { type: 'embedding', model: 'nomic-embed-text', dimensions: 3, vector: [0.1, 0.2, 0.3] }];
    });

    const result = (await embedQuery.handler({ text: 'winter landscapes' })) as any;
    expect(result.dimensions).toBe(3);
    expect(result.vector).toHaveLength(3);
  });

  it('surfaces embedder unavailability', async () => {
    mock.onGet(`${V}/embed`).reply(503, { error: 'Embedding service unavailable' });

    const result = (await embedQuery.handler({ text: 'x' })) as any;
    expect(result.error).toContain('unavailable');
  });
});

// ── read_chunks (Tier 1) ──────────────────────────────────────────────────────

describe('read_chunks', () => {
  it('reads a sequence range with content', async () => {
    mock.onGet(`${V}/doc/chunks`).reply((cfg) => {
      expect(cfg.params).toMatchObject({ from: '2', to: '4' });
      return [200, { type: 'chunks', items: [{ sequence: 2, content: 'the actual text' }] }];
    });

    const result = (await readChunks.handler({ slug: 'doc', from: 2, to: 4 })) as any;
    expect(result.items[0].content).toBe('the actual text');
  });
});

// ── resolve ───────────────────────────────────────────────────────────────────

describe('resolve', () => {
  it('resolves a bare resource slug', async () => {
    mock.onGet(`${V}/doc/meta`).reply(200, { type: 'resource', name: 'Doc' });

    const result = (await resolve.handler({ address: 'doc' })) as any;
    expect(result.name).toBe('Doc');
  });

  it('resolves a full /v/ URL inside the vault', async () => {
    mock.onGet(`${V}/doc/cover/meta`).reply(200, { type: 'file', filename: 'cover.jpg' });

    const result = (await resolve.handler({
      address: 'http://localhost:8000/v/acme/press-kit/doc/cover',
    })) as any;
    expect(result.filename).toBe('cover.jpg');
  });

  it('rejects addresses outside the connected vault', async () => {
    const result = (await resolve.handler({ address: '/v/other-org/other-vault/doc' })) as any;
    expect(result.error).toContain('outside the connected vault');
  });
});

// ── link_* (Tier 2 — URLs, never bytes) ──────────────────────────────────────

describe('link_resource / link_file', () => {
  const linksPayload = {
    type: 'resource-links',
    resource: { slug: 'doc', url: 'http://localhost:8000/h/VH123/LH456' },
    files: [
      { filename: 'doc.pdf', slug: 'doc-2', url: 'http://localhost:8000/h/VH123/LH789' },
    ],
  };

  it('link_resource returns the minted resource URL', async () => {
    mock.onGet(`${V}/doc/links`).reply(200, linksPayload);

    const result = (await linkResource.handler({ slug: 'doc' })) as any;
    expect(result.url).toContain('/h/VH123/');
  });

  it('link_file picks the file by slug', async () => {
    mock.onGet(`${V}/doc/links`).reply(200, linksPayload);

    const result = (await linkFile.handler({ resource_slug: 'doc', file_slug: 'doc-2' })) as any;
    expect(result.filename).toBe('doc.pdf');
    expect(result.url).toContain('LH789');
  });

  it('link_* surfaces the Tier 2 denial on ai vaults', async () => {
    mock.onGet(`${V}/doc/links`).reply(403, { error: 'This vault does not expose that tier' });

    const result = (await linkResource.handler({ slug: 'doc' })) as any;
    expect(result.error).toContain('tier');
  });
});

// ── ingest (write, only with a write key) ──────────────────────────────────────

describe('ingest', () => {
  const descriptor = { schemaVersion: '1.0', source: { hash: 'H1' }, figures: [] };

  it('POSTs the descriptor + image to /w/ingest with the WRITE key', async () => {
    mock.onPost(`${V}/w/ingest`).reply((cfg) => {
      // The write op uses the write key, not the default read key.
      expect(cfg.headers?.['X-Vault-Key']).toBe('tvk_write-key-00000000000000000000000000000');
      // multipart body carrying the descriptor + image parts.
      expect(String(cfg.headers?.['Content-Type'])).toContain('multipart/form-data');
      return [200, { ok: true, result: { hash: 'LH999', files: 2 } }];
    });

    const result = (await ingest.handler({
      descriptor,
      image_base64: Buffer.from([1, 2, 3]).toString('base64'),
      image_mime: 'image/png',
      name: 'Fig 1',
      source_hash: 'H1',
    })) as any;

    expect(result.type).toBe('ingest');
    expect(result.result.hash).toBe('LH999');
  });

  it('surfaces a refused write (ok:false)', async () => {
    mock.onPost(`${V}/w/ingest`).reply(403, { ok: false, error: 'Key not authorized to write on this vault' });

    const result = (await ingest.handler({
      descriptor,
      image_base64: Buffer.from([1]).toString('base64'),
    })) as any;
    expect(result.error).toContain('authorized');
  });

  it('validates the descriptor is an object', async () => {
    const result = (await ingest.handler({ descriptor: 'nope', image_base64: 'AAA' })) as any;
    expect(result.error).toContain('descriptor');
  });
});


describe('read_image', () => {
  const bytes = Buffer.from([1, 2, 3, 4]);
  const headers = (rendition = 'ai-prepared') => ({
    'content-type': 'image/png', 'x-tydal-image-rendition': rendition,
    'x-tydal-image-width': '640', 'x-tydal-image-height': '400',
  });

  it('defaults to AI preparation and returns actual bytes and metadata', async () => {
    mock.onGet(`${V}/face/preview`).reply(cfg => {
      expect(cfg.params).toEqual({ rendition: 'ai-prepared' });
      return [200, bytes, headers()];
    });
    const result = await readImage.handler({ resource_slug: 'face' }) as any;
    expect(result.content[0]).toEqual({ type: 'image', data: bytes.toString('base64'), mimeType: 'image/png' });
    expect(JSON.parse(result.content[1].text)).toEqual({
      rendition: 'ai-prepared', mime_type: 'image/png', bytes: 4,
      width: 640, height: 400, max_bytes: 5242880,
    });
  });

  it.each(['original', 'thumbnail', 'small', 'medium', 'large'])('requests %s explicitly', async rendition => {
    mock.onGet(`${V}/face/preview`).reply(cfg => {
      expect(cfg.params).toEqual({ rendition });
      return [200, bytes, headers(rendition)];
    });
    const result = await readImage.handler({ resource_slug: 'face', rendition }) as any;
    expect(JSON.parse(result.content[1].text).rendition).toBe(rendition);
    expect(JSON.parse(result.content[1].text).max_bytes).toBeUndefined();
  });

  it('forwards a custom budget and reports unavailable dimensions as null', async () => {
    mock.onGet(`${V}/face/preview`).reply(cfg => {
      expect(cfg.params).toEqual({ rendition: 'ai-prepared', max_bytes: '65536' });
      return [200, bytes, { 'content-type': 'image/png', 'x-tydal-image-rendition': 'ai-prepared' }];
    });
    const result = await readImage.handler({ resource_slug: 'face', max_bytes: 65536 }) as any;
    expect(JSON.parse(result.content[1].text)).toMatchObject({ width: null, height: null, max_bytes: 65536 });
  });

  it.each([
    { rendition: 'unknown' }, { max_bytes: 0 }, { max_bytes: 20971521 },
    { max_bytes: '65536' }, { max_bytes: 65536.5 }, { max_bytes: [] },
    { rendition: 'original', max_bytes: 65536 }, { resource_slug: '' },
  ])('rejects invalid arguments before making a request: %j', async args => {
    const result = await readImage.handler({ resource_slug: 'face', ...args }) as any;
    expect(result.error).toBeTruthy();
    expect(mock.history.get).toHaveLength(0);
  });

  it.each([403, 404, 422])('preserves a %i API error returned as binary JSON', async status => {
    mock.onGet(`${V}/face/preview`).reply(status, Buffer.from(JSON.stringify({ error: 'Access or preparation refused' })));
    expect(await readImage.handler({ resource_slug: 'face' })).toEqual({ error: 'Access or preparation refused' });
  });

  it.each([undefined, 'original'])('rejects unconfirmed or substituted renditions: %s', async rendition => {
    mock.onGet(`${V}/face/preview`).reply(200, bytes, { 'content-type': 'image/png', ...(rendition ? { 'x-tydal-image-rendition': rendition } : {}) });
    expect((await readImage.handler({ resource_slug: 'face' }) as any).error).toContain('did not confirm');
  });

  it('rejects oversized prepared bytes', async () => {
    mock.onGet(`${V}/face/preview`).reply(200, Buffer.alloc(65537), headers());
    expect((await readImage.handler({ resource_slug: 'face', max_bytes: 65536 }) as any).error).toContain('exceeds');
  });

  it('rejects non-image responses', async () => {
    mock.onGet(`${V}/face/preview`).reply(200, bytes, { 'content-type': 'application/pdf' });
    expect((await readImage.handler({ resource_slug: 'face' }) as any).error).toContain('not a viewable image');
  });
});
