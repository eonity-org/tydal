import { describe, it, expect, vi } from 'vitest'
import { createVaultConsumer } from '../vault.js'
import { TydalApiError } from '../error.js'

function fakeFetch(
  responder: (url: string, init: RequestInit) => Response | Promise<Response>,
) {
  const calls: Array<{ url: string; init: RequestInit }> = []
  const fn = vi.fn(async (url: string, init: RequestInit) => {
    calls.push({ url, init })
    return responder(url, init)
  }) as unknown as typeof fetch
  return { fn, calls }
}

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

const root = 'https://tydal.test'

describe('createVaultConsumer', () => {
  it('addresses the human form and returns bare payloads intact', async () => {
    const { fn, calls } = fakeFetch(() =>
      json({ type: 'vault', name: 'Expo', purpose: 'gallery', tiers: { identity: true, chunks: false, binary: true }, presentation: [] }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    const meta = await vault.meta()

    expect(calls[0].url).toBe(`${root}/v/acme/expo/meta`)
    expect(meta.purpose).toBe('gallery')
    expect(meta.tiers.binary).toBe(true) // bare payload — nothing unwrapped
  })

  it('surfaces the capability matrix with its provenance', async () => {
    const { fn } = fakeFetch(() =>
      json({
        type: 'vault', name: 'Figures', purpose: 'ai',
        tiers: { identity: true, chunks: true, binary: true },
        capabilities: {
          allow_binary: { value: true, source: 'override' },
          allow_chunks: { value: true, source: 'preset' },
        },
        presentation: [],
      }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'figures' }, fetch: fn })

    const meta = await vault.meta()

    // The ImageLab shape: an `ai` vault whose binary tier is on because this
    // vault overrode the preset, not because `ai` grants it.
    expect(meta.capabilities?.allow_binary).toEqual({ value: true, source: 'override' })
    expect(meta.capabilities?.allow_chunks.source).toBe('preset')
  })

  it('tolerates a backend that omits the capability matrix', async () => {
    const { fn } = fakeFetch(() =>
      json({ type: 'vault', name: 'Expo', purpose: 'gallery', tiers: { identity: true, chunks: false, binary: true }, presentation: [] }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    const meta = await vault.meta()

    expect(meta.capabilities).toBeUndefined()
    expect(meta.tiers.binary).toBe(true) // the stable gate is unaffected
  })

  it('addresses the machine form via hash', async () => {
    const { fn, calls } = fakeFetch(() => json({ type: 'vault-index', resources: [], pagination: {} }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123XyZ012' }, fetch: fn })

    await vault.resources({ page: 2, tag: 'winter' })

    const url = new URL(calls[0].url)
    expect(url.pathname).toBe('/h/AbC123XyZ012/resources')
    expect(url.searchParams.get('page')).toBe('2')
    expect(url.searchParams.get('tag')).toBe('winter')
  })

  it('sends the vault key header when configured', async () => {
    const { fn, calls } = fakeFetch(() => json({ type: 'vault-tags', tags: [] }))
    const vault = createVaultConsumer({
      baseUrl: root,
      vault: { org: 'acme', slug: 'expo' },
      key: 'tvk_secret',
      fetch: fn,
    })

    await vault.tags()

    expect((calls[0].init.headers as Record<string, string>)['X-Vault-Key']).toBe('tvk_secret')
  })

  it('serializes search mode and facet filters the way the grammar expects', async () => {
    const { fn, calls } = fakeFetch(() =>
      json({ type: 'vault-search', mode: 'semantic', results: [], facets: {}, pagination: {} }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    await vault.search({ q: 'warm evening', mode: 'semantic', facets: { technique: ['oil', 'acrylic'] } })

    const url = new URL(calls[0].url)
    expect(url.searchParams.get('q')).toBe('warm evening')
    expect(url.searchParams.get('mode')).toBe('semantic')
    expect(url.searchParams.getAll('facet[technique][]')).toEqual(['oil', 'acrylic'])
  })

  it('scopes chunk search and exposes resource sub-operations', async () => {
    const { fn, calls } = fakeFetch(() => json({ type: 'resource-related', source: 'graph', resources: [] }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    await vault.resource('harbor-at-dusk').related()

    expect(new URL(calls[0].url).pathname).toBe('/v/acme/expo/harbor-at-dusk/related')
  })

  it('fetches the projected graph with an optional node cap', async () => {
    const { fn, calls } = fakeFetch(() =>
      json({ type: 'vault-graph', nodes: [{ id: 'r1' }], edges: [{ source: 'r1', target: 'r2', type: 'related', origin: 'manual', weight: 0.8 }], truncated: false }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    const graph = await vault.graph({ nodes: 50 })

    const url = new URL(calls[0].url)
    expect(url.pathname).toBe('/v/acme/expo/graph')
    expect(url.searchParams.get('nodes')).toBe('50')
    expect(graph.edges[0].source).toBe('r1')
  })

  it('posts questions to /ask with the vault key and returns the grounded answer', async () => {
    const { fn, calls } = fakeFetch(() =>
      json({ answer: 'It glows.', sources: [{ resource_id: 'r1', resource_name: 'Harbor', slug: 'harbor', url: 'http://x', pages: [3] }], vault: { slug: 'expo', name: 'Expo', purpose: 'ai' }, used: { chunks: 1, cards: 0, related: 0 } }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, key: 'tvk_secret', fetch: fn })

    const res = await vault.ask({ question: 'What glows at dusk?', k: 3 })

    expect(new URL(calls[0].url).pathname).toBe('/v/acme/expo/ask')
    expect(calls[0].init.method).toBe('POST')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ question: 'What glows at dusk?', k: 3 })
    expect((calls[0].init.headers as Record<string, string>)['X-Vault-Key']).toBe('tvk_secret')
    expect(res.sources[0].pages).toEqual([3])
  })

  it('appends signed-grant credentials to every request', async () => {
    const { fn, calls } = fakeFetch(() => json({ type: 'vault', tiers: {}, presentation: [] }))
    const vault = createVaultConsumer({
      baseUrl: root,
      vault: { hash: 'AbC123' },
      grant: { sig: 'deadbeef', exp: 1799999999 },
      fetch: fn,
    })

    await vault.meta()

    const url = new URL(calls[0].url)
    expect(url.pathname).toBe('/h/AbC123/meta')
    expect(url.searchParams.get('sig')).toBe('deadbeef')
    expect(url.searchParams.get('exp')).toBe('1799999999')
  })

  it('streams answer tokens over SSE and resolves the final result', async () => {
    const sse =
      'event: token\ndata: {"text":"The harbor "}\n\n' +
      'event: token\ndata: {"text":"glows."}\n\n' +
      'event: done\ndata: {"answer":"The harbor glows.","sources":[{"resource_id":"r1","resource_name":"Harbor","slug":"harbor","url":"http://x","pages":[3]}],"vault":{"slug":"expo","name":"Expo","purpose":"ai"},"used":{"chunks":1,"cards":0,"related":0}}\n\n'
    const { fn, calls } = fakeFetch(
      () => new Response(sse, { status: 200, headers: { 'Content-Type': 'text/event-stream' } }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    const tokens: string[] = []
    const result = await vault.askStream({ question: 'What glows?', onToken: (t) => tokens.push(t) })

    expect(calls[0].init.method).toBe('POST')
    expect((calls[0].init.headers as Record<string, string>).Accept).toBe('text/event-stream')
    expect(tokens).toEqual(['The harbor ', 'glows.'])
    expect(result.answer).toBe('The harbor glows.')
    expect(result.sources[0].slug).toBe('harbor')
  })

  it('throws when the stream emits an error frame', async () => {
    const { fn } = fakeFetch(
      () => new Response('event: error\ndata: {"error":"Reasoning service unavailable"}\n\n', {
        status: 200, headers: { 'Content-Type': 'text/event-stream' },
      }),
    )
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    await expect(vault.askStream({ question: 'What glows?', onToken: () => {} }))
      .rejects.toThrow('Reasoning service unavailable')
  })

  it('normalizes grammar errors (404 hidden vault, 403 tier denial)', async () => {
    const { fn } = fakeFetch(() => json({ error: 'This vault does not expose that tier' }, 403))
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, fetch: fn })

    await expect(vault.resource('doc').chunks()).rejects.toBeInstanceOf(TydalApiError)
    await expect(vault.resource('doc').chunks()).rejects.toMatchObject({ status: 403 })
  })

  it('activates a gallery selection with the write key over POST /w/activate', async () => {
    const { fn, calls } = fakeFetch(() => json({ ok: true, result: { activated: 2, hashes: ['h1', 'h2'] } }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123' }, key: 'tvk_write', fetch: fn })

    const res = await vault.gallery.activate(['h1', 'h2'])

    expect(new URL(calls[0].url).pathname).toBe('/h/AbC123/w/activate')
    expect(calls[0].init.method).toBe('POST')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ resources: ['h1', 'h2'] })
    expect((calls[0].init.headers as Record<string, string>)['X-Vault-Key']).toBe('tvk_write')
    expect(res).toEqual({ ok: true, result: { activated: 2, hashes: ['h1', 'h2'] } })
  })

  it('opens and closes a gallery with empty-bodied writes', async () => {
    const { fn, calls } = fakeFetch(() => json({ ok: true, result: { state: 'public' } }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { org: 'acme', slug: 'expo' }, key: 'tvk_write', fetch: fn })

    await vault.gallery.open()
    await vault.gallery.close()

    expect(new URL(calls[0].url).pathname).toBe('/v/acme/expo/w/open')
    expect(new URL(calls[1].url).pathname).toBe('/v/acme/expo/w/close')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({})
  })

  it('surfaces a refused write as ok:false rather than throwing', async () => {
    const { fn } = fakeFetch(() => json({ ok: false, error: 'Key not authorized for this method' }, 403))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123' }, key: 'tvk_read', fetch: fn })

    // Refusals ride on a 4xx status, so they throw like any read-grammar error.
    await expect(vault.gallery.activate(['h1'])).rejects.toMatchObject({ status: 403 })
  })

  it('probes write capabilities over GET /w with the key', async () => {
    const { fn, calls } = fakeFetch(() => json({ ok: true, methods: ['activate', 'open', 'close'] }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123' }, key: 'tvk_write', fetch: fn })

    const caps = await vault.writeCapabilities()

    expect(new URL(calls[0].url).pathname).toBe('/h/AbC123/w')
    expect(calls[0].init.method ?? 'GET').toBe('GET')
    expect((calls[0].init.headers as Record<string, string>)['X-Vault-Key']).toBe('tvk_write')
    expect(caps).toEqual({ ok: true, methods: ['activate', 'open', 'close'] })
  })

  it('throws when the probed key cannot write (403)', async () => {
    const { fn } = fakeFetch(() => json({ ok: false, error: 'Key not authorized to write on this vault' }, 403))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123' }, key: 'tvk_read', fetch: fn })

    await expect(vault.writeCapabilities()).rejects.toMatchObject({ status: 403 })
  })

  it('sends a plain-object write op as JSON', async () => {
    const { fn, calls } = fakeFetch(() => json({ ok: true, result: { activated: 1 } }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123' }, key: 'tvk_write', fetch: fn })

    await vault.write('activate', { resources: ['h1'] })

    expect(new URL(calls[0].url).pathname).toBe('/h/AbC123/w/activate')
    expect((calls[0].init.headers as Record<string, string>)['Content-Type']).toBe('application/json')
    expect(JSON.parse(calls[0].init.body as string)).toEqual({ resources: ['h1'] })
  })

  it('sends a write op carrying a Blob as multipart (op + document model)', async () => {
    const { fn, calls } = fakeFetch(() => json({ ok: true, result: { resource_id: 'r1', files: 2 } }))
    const vault = createVaultConsumer({ baseUrl: root, vault: { hash: 'AbC123' }, key: 'tvk_write', fetch: fn })

    const image = new Blob([new Uint8Array([1, 2, 3])], { type: 'image/png' })
    const res = await vault.write('ingest', { descriptor: { figures: [] }, image, name: 'Fig 1' })

    expect(new URL(calls[0].url).pathname).toBe('/h/AbC123/w/ingest')
    // Multipart: the runtime sets its own boundary — the client must NOT force JSON.
    const headers = calls[0].init.headers as Record<string, string>
    expect(headers['Content-Type']).toBeUndefined()
    expect(headers['X-Vault-Key']).toBe('tvk_write')

    const form = calls[0].init.body as FormData
    expect(form).toBeInstanceOf(FormData)
    expect(form.get('descriptor')).toBe(JSON.stringify({ figures: [] }))
    expect(form.get('name')).toBe('Fig 1')
    expect(form.get('image')).toBeInstanceOf(Blob)
    expect(res).toEqual({ ok: true, result: { resource_id: 'r1', files: 2 } })
  })
})
