/**
 * Unit tests for MCP tool handlers.
 *
 * `fetch` is mocked (org-mcp's own client, `../client.ts`, is a thin fetch
 * wrapper) — no running TYDAL backend required. Each test registers a mock
 * response, calls the handler, and asserts on the returned data. The
 * MockFetch shim mimics the slice of axios-mock-adapter's API these tests use
 * (.onGet/.onPost/.onPut/.onDelete → .reply(status, body) | .reply(cfg => [status, body])).
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { config } from '../config.js';

// Handlers under test
import { handler as listWorkspaces }              from '../tools/list_workspaces.js';
import { handler as browseWorkspace }             from '../tools/browse_workspace.js';
import { handler as searchWorkspace }             from '../tools/search_workspace.js';
import { handler as getResource }                 from '../tools/get_resource.js';
import { handler as listResourceChunks }          from '../tools/list_resource_chunks.js';
import { handler as getVaultLinks }                 from '../tools/get_vault_links.js';
import { handler as askWorkspace }                from '../tools/ask_workspace.js';
import { handler as askVault }                    from '../tools/ask_vault.js';
import { handler as createWorkspace }             from '../tools/create_workspace.js';
import { handler as addResourceToWorkspace }      from '../tools/add_resource_to_workspace.js';
import { handler as removeResourceFromWorkspace } from '../tools/remove_resource_from_workspace.js';
import { handler as updateResourceMetadata }      from '../tools/update_resource_metadata.js';
import { handler as syncTags }                    from '../tools/sync_tags.js';

type ReplyConfig = { headers: Record<string, string>; params: Record<string, string>; data?: string };
type ReplyFn = (cfg: ReplyConfig) => [number, unknown];

/** Minimal fetch mock mirroring the bit of axios-mock-adapter the tests rely on. */
class MockFetch {
  private handlers = new Map<string, { status?: number; body?: unknown; fn?: ReplyFn }>();
  private original = globalThis.fetch;
  private basePath = new URL(config.baseUrl).pathname.replace(/\/$/, '');

  constructor() {
    globalThis.fetch = this.fetch as typeof fetch;
  }

  private add(method: string, path: string) {
    return {
      reply: (a: number | ReplyFn, b?: unknown) => {
        this.handlers.set(`${method} ${path}`, typeof a === 'function' ? { fn: a } : { status: a, body: b });
      },
    };
  }
  onGet(path: string)    { return this.add('GET', path); }
  onPost(path: string)   { return this.add('POST', path); }
  onPut(path: string)    { return this.add('PUT', path); }
  onDelete(path: string) { return this.add('DELETE', path); }
  restore() { globalThis.fetch = this.original; }

  private fetch = async (input: string | URL | Request, init?: RequestInit): Promise<Response> => {
    const href = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
    const url = new URL(href);
    const method = (init?.method ?? 'GET').toUpperCase();
    const path = url.pathname.slice(this.basePath.length);
    const entry = this.handlers.get(`${method} ${path}`);
    if (!entry) {
      return new Response(JSON.stringify({ message: `No mock for ${method} ${path}` }), { status: 404 });
    }
    const cfg: ReplyConfig = {
      headers: (init?.headers ?? {}) as Record<string, string>,
      params: Object.fromEntries(url.searchParams),
      data: typeof init?.body === 'string' ? init.body : undefined,
    };
    const [status, body] = entry.fn ? entry.fn(cfg) : [entry.status ?? 200, entry.body];
    return new Response(body === undefined ? '' : JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  };
}

let mock: MockFetch;

beforeEach(() => {
  mock = new MockFetch();
});

afterEach(() => {
  mock.restore();
});

// ── auth headers ──────────────────────────────────────────────────────────────

describe('auth headers', () => {
  it('sends Bearer token and X-Organization-ID on every request', async () => {
    mock.onGet('/workspaces').reply((config) => {
      // Assert against the hardcoded vitest.config.ts values, not process.env —
      // comparing to process.env would be tautological (the client reads the same var).
      expect(config.headers?.Authorization).toBe('Bearer test-token')
      expect(config.headers?.['X-Organization-ID']).toBe('test-org-id')
      return [200, { data: { workspaces: [] }, meta: { pagination: { total: 0 } } }]
    })

    await listWorkspaces({})
  })
})

// ── list_workspaces ────────────────────────────────────────────────────────────

describe('list_workspaces', () => {
  it('returns workspace list from API', async () => {
    mock.onGet('/workspaces').reply(200, {
      data: { workspaces: [{ id: 3, name: 'Brand Assets', is_default: false }] },
      meta: { pagination: { total: 1 } },
    });

    const result = await listWorkspaces({}) as any;
    expect(result.total).toBe(1);
    expect(result.workspaces[0].name).toBe('Brand Assets');
  });

  it('returns error object on API failure', async () => {
    mock.onGet('/workspaces').reply(401, { message: 'Unauthenticated.' });

    const result = await listWorkspaces({}) as any;
    expect(result).toHaveProperty('error');
  });
});

// ── browse_workspace ───────────────────────────────────────────────────────────

describe('browse_workspace', () => {
  it('passes workspace_id in URL and returns catalogue data', async () => {
    mock.onGet('/workspaces/3/catalogue').reply(200, {
      data: [{ id: 'res-1', name: 'Logo' }],
      facets: [],
      total: 1,
    });

    const result = await browseWorkspace({ workspace_id: '3' }) as any;
    expect(result.total).toBe(1);
    expect(result.data[0].name).toBe('Logo');
  });
});

// ── search_workspace ───────────────────────────────────────────────────────────

describe('search_workspace', () => {
  it('sends search query as param', async () => {
    mock.onGet('/workspaces/3/catalogue').reply((config) => {
      expect(config.params.search).toBe('brand colors');
      return [200, { data: [], facets: [], total: 0 }];
    });

    await searchWorkspace({ workspace_id: '3', query: 'brand colors' });
  });
});

// ── get_resource ───────────────────────────────────────────────────────────────

describe('get_resource', () => {
  it('calls the agent-view endpoint and returns the resource object', async () => {
    mock.onGet('/resources/res-abc/agent-view').reply(200, {
      success: true,
      data: {
        resource: {
          id: 'res-abc',
          name: 'Annual Report 2025',
          files: [{ id: 'f1', url: 'https://vault.example/f1.pdf', vault_links: [] }],
          vault_links: [],
          content: { has_chunks: true, chunk_count: 12 },
        },
      },
    });

    const result = await getResource({ resource_id: 'res-abc' }) as any;
    expect(result.name).toBe('Annual Report 2025');
    expect(result.files[0].url).toBe('https://vault.example/f1.pdf');
    expect(result.content.chunk_count).toBe(12);
  });

  it('wraps 404 as error', async () => {
    mock.onGet('/resources/missing/agent-view').reply(404, { message: 'Resource not found' });
    const result = await getResource({ resource_id: 'missing' }) as any;
    expect(result).toHaveProperty('error');
  });
});

// ── list_resource_chunks ──────────────────────────────────────────────────────

describe('list_resource_chunks', () => {
  it('returns chunks in reading order', async () => {
    mock.onGet('/resources/res-abc/chunks').reply(200, {
      success: true,
      data: {
        chunks: [
          { chunk_id: 'c1', file_id: 'f1', sequence: 0, page_number: 1, content: 'First chunk.' },
          { chunk_id: 'c2', file_id: 'f1', sequence: 1, page_number: 1, content: 'Second chunk.' },
        ],
      },
    });

    const result = await listResourceChunks({ resource_id: 'res-abc' }) as any;
    expect(result.chunks).toHaveLength(2);
    expect(result.chunks[0].content).toBe('First chunk.');
  });

  it('returns an empty list when nothing is chunked yet', async () => {
    mock.onGet('/resources/res-abc/chunks').reply(200, {
      success: true,
      data: { chunks: [] },
    });

    const result = await listResourceChunks({ resource_id: 'res-abc' }) as any;
    expect(result.chunks).toEqual([]);
  });

  it('wraps API failure as error', async () => {
    mock.onGet('/resources/res-abc/chunks').reply(500, { message: 'Server error' });
    const result = await listResourceChunks({ resource_id: 'res-abc' }) as any;
    expect(result).toHaveProperty('error');
  });
});

// ── get_vault_links ──────────────────────────────────────────────────────────────

describe('get_vault_links', () => {
  it('flattens resource and file links into separate arrays', async () => {
    mock.onGet('/resources/res-abc/vault-links').reply(200, {
      success: true,
      data: {
        resource: { links: [{ id: 'lnk-1', url: 'https://vault.example.com/res', vault_name: 'S3' }] },
        files: [
          { file_id: 'f-1', links: [{ id: 'lnk-2', url: 'https://vault.example.com/f1', vault_name: 'S3' }] },
        ],
      },
    });

    const result = await getVaultLinks({ resource_id: 'res-abc' }) as any;
    expect(result.resource_links).toHaveLength(1);
    expect(result.file_links).toHaveLength(1);
    expect(result.file_links[0].file_id).toBe('f-1');
  });
});

// ── ask_workspace ──────────────────────────────────────────────────────────────

describe('ask_workspace', () => {
  it('returns answer and sources', async () => {
    mock.onPost('/workspaces/3/ask').reply(200, {
      success: true,
      data: {
        answer:  'The primary brand color is deep red #911A2C.',
        sources: [{ chunk_id: 'c-1', resource_name: 'Brand Guide', page_number: 2 }],
      },
    });

    const result = await askWorkspace({ workspace_id: '3', question: 'What is the brand color?' }) as any;
    expect(result.answer).toContain('#911A2C');
    expect(result.sources).toHaveLength(1);
  });

  it('sends strict=true when requested', async () => {
    mock.onPost('/workspaces/3/ask').reply((config) => {
      const body = JSON.parse(config.data);
      expect(body.strict).toBe(true);
      return [200, { success: true, data: { answer: 'ok', sources: [] } }];
    });

    await askWorkspace({ workspace_id: '3', question: 'test', strict: true });
  });
});

// ── ask_vault (Epic 4.4) ───────────────────────────────────────────────────────

describe('ask_vault', () => {
  it('returns the vault-scoped answer with resource-level sources', async () => {
    mock.onPost('/vaults/v-1/ask').reply(200, {
      success: true,
      data: {
        answer: 'Two oil paintings mention the harbor.',
        sources: [{ resource_name: 'Harbor at Dusk', slug: 'harbor-at-dusk', pages: [3] }],
        vault: { slug: 'expo', name: 'Expo', purpose: 'gallery' },
        used: { chunks: 2, cards: 3, related: 1 },
      },
    });

    const result = await askVault({ vault_id: 'v-1', question: 'What mentions the harbor?' }) as any;
    expect(result.answer).toContain('harbor');
    expect(result.sources[0].slug).toBe('harbor-at-dusk');
    expect(result.vault.purpose).toBe('gallery');
  });

  it('surfaces API errors as { error }', async () => {
    mock.onPost('/vaults/v-x/ask').reply(404, { message: 'Vault not found' });

    const result = await askVault({ vault_id: 'v-x', question: 'anything' }) as any;
    expect(result.error).toBeDefined();
  });
});

// ── create_workspace ───────────────────────────────────────────────────────────

describe('create_workspace', () => {
  it('posts name and description, returns new workspace', async () => {
    mock.onPost('/workspaces').reply(201, {
      success: true,
      data: { workspace: { id: 7, name: 'Final Exhibition', description: 'Selected works' } },
    });

    const result = await createWorkspace({ name: 'Final Exhibition', description: 'Selected works' }) as any;
    expect(result.id).toBe(7);
    expect(result.name).toBe('Final Exhibition');
  });
});

// ── add_resource_to_workspace ──────────────────────────────────────────────────

describe('add_resource_to_workspace', () => {
  it('posts resource_id and returns success', async () => {
    mock.onPost('/workspaces/3/resources').reply((config) => {
      const body = JSON.parse(config.data);
      expect(body.resource_id).toBe('res-abc');
      return [200, { success: true, message: 'Resource added to workspace' }];
    });

    const result = await addResourceToWorkspace({ workspace_id: '3', resource_id: 'res-abc' }) as any;
    expect(result.success).toBe(true);
  });
});

// ── remove_resource_from_workspace ─────────────────────────────────────────────

describe('remove_resource_from_workspace', () => {
  it('sends DELETE to correct URL', async () => {
    mock.onDelete('/workspaces/3/resources/res-abc').reply(200, {
      success: true,
      message: 'Resource removed from workspace',
    });

    const result = await removeResourceFromWorkspace({ workspace_id: '3', resource_id: 'res-abc' }) as any;
    expect(result.success).toBe(true);
  });
});

// ── update_resource_metadata ───────────────────────────────────────────────────

describe('update_resource_metadata', () => {
  it('sends only the provided fields', async () => {
    mock.onPut('/resources/res-abc').reply((config) => {
      const body = JSON.parse(config.data);
      expect(body.name).toBe('Renamed Resource');
      expect(body).not.toHaveProperty('resource_id');
      return [200, { success: true, data: { resource: { id: 'res-abc', name: 'Renamed Resource' } } }];
    });

    const result = await updateResourceMetadata({ resource_id: 'res-abc', name: 'Renamed Resource' }) as any;
    expect(result.name).toBe('Renamed Resource');
  });
});

// ── sync_tags ──────────────────────────────────────────────────────────────────

describe('sync_tags', () => {
  // GET /semantic-tags returns the array directly at .data (no nested
  // .tags key) and POST /semantic-tags returns the created tag directly at
  // .data (no nested .tag key) — verified against the live backend
  // (2026-09-22): an earlier version of these mocks used a plausible-looking
  // but wrong nested shape that matched a bug in sync_tags.ts instead of
  // catching it, since the assertions only exercise the PUT's tag_ids, not
  // the GET/POST parsing that fed them.
  it('looks up existing tags and syncs by ID', async () => {
    mock.onGet('/semantic-tags').reply(200, {
      data: [{ id: 10, label: 'Photography' }, { id: 11, label: 'Architecture' }],
    });
    mock.onPut('/resources/res-abc/semantic-tags').reply((config) => {
      const body = JSON.parse(config.data);
      expect(body.tag_ids).toEqual([10]);
      return [200, { success: true }];
    });

    await syncTags({ resource_id: 'res-abc', tags: ['Photography'] });
  });

  it('creates a tag if the label does not exist yet', async () => {
    mock.onGet('/semantic-tags').reply(200, { data: [] });
    mock.onPost('/semantic-tags').reply(201, { data: { id: 99, label: 'New Tag' } });
    mock.onPut('/resources/res-abc/semantic-tags').reply((config) => {
      const body = JSON.parse(config.data);
      expect(body.tag_ids).toEqual([99]);
      return [200, { success: true }];
    });

    await syncTags({ resource_id: 'res-abc', tags: ['New Tag'] });
  });

  it('deterministically picks the lowest id when a label has duplicates, regardless of return order', async () => {
    // `label` has no uniqueness constraint on the backend (only `slug`
    // does, and it auto-suffixes on collision instead of rejecting) — a
    // reordered response here previously made this resolve to whichever
    // duplicate the DB happened to return last, non-deterministically.
    mock.onGet('/semantic-tags').reply(200, {
      data: [{ id: 22, label: 'software' }, { id: 7, label: 'software' }],
    });
    mock.onPut('/resources/res-abc/semantic-tags').reply((config) => {
      const body = JSON.parse(config.data);
      expect(body.tag_ids).toEqual([7]);
      return [200, { success: true }];
    });

    await syncTags({ resource_id: 'res-abc', tags: ['software'] });
  });
});
