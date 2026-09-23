import { describe, it, expect, vi } from 'vitest'
import { createTydalClient } from '../client.js'
import { staticToken } from '../auth.js'
import { TydalApiError } from '../error.js'
import type { TokenProvider } from '../auth.js'

/** Build a fake fetch that records calls and returns a queued response. */
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

const base = 'https://api.test/api/v1'

describe('createTydalClient', () => {
  it('unwraps data only when success === true', async () => {
    const { fn, calls } = fakeFetch(() => json({ success: true, data: { id: 7, name: 'R' } }))
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('tok'), fetch: fn })

    const res = await tydal.resources.get<{ id: number; name: string }>(7)

    expect(res).toEqual({ id: 7, name: 'R' })
    expect(calls[0].url).toBe(`${base}/resources/7`)
  })

  it('returns the raw body for envelope-less payloads (e.g. catalogue)', async () => {
    // Catalogue: { data: [...], facets, ... } with NO success key — must survive intact.
    const { fn } = fakeFetch(() =>
      json({ data: [{ id: 1 }], facets: [{ key: 'type' }], total: 1 }),
    )
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('t'), fetch: fn })

    const res = await tydal.resources.catalogue<{ data: unknown[]; facets: unknown[]; total: number }>(5)

    expect(res).toEqual({ data: [{ id: 1 }], facets: [{ key: 'type' }], total: 1 })
  })

  it('sends the bearer token and accept header', async () => {
    const { fn, calls } = fakeFetch(() => json({ data: {} }))
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('abc'), fetch: fn })

    await tydal.resources.get(1)

    const headers = calls[0].init.headers as Record<string, string>
    expect(headers['Authorization']).toBe('Bearer abc')
    expect(headers['Accept']).toBe('application/json')
    expect(headers['X-Organization-ID']).toBeUndefined()
  })

  it('sends X-Organization-ID when orgId is configured', async () => {
    const { fn, calls } = fakeFetch(() => json({ data: {} }))
    const tydal = createTydalClient({
      baseUrl: base,
      auth: staticToken('abc'),
      orgId: 'org-123',
      fetch: fn,
    })

    await tydal.resources.get(1)

    const headers = calls[0].init.headers as Record<string, string>
    expect(headers['X-Organization-ID']).toBe('org-123')
  })

  it('serializes catalogue params and facets Laravel-style', async () => {
    const { fn, calls } = fakeFetch(() => json({ data: { data: [] } }))
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('t'), fetch: fn })

    await tydal.resources.catalogue(5, {
      search: 'paris',
      page: 2,
      facets: { type: ['image', 'video'], year: ['2026'] },
    })

    const url = calls[0].url
    expect(url).toContain('/catalogue/5?')
    expect(url).toContain('search=paris')
    expect(url).toContain('page=2')
    expect(url).toContain(`${encodeURIComponent('facets[type][]')}=image`)
    expect(url).toContain(`${encodeURIComponent('facets[type][]')}=video`)
    expect(url).toContain(`${encodeURIComponent('facets[year][]')}=2026`)
  })

  it('raw: true keeps meta sibling that unwrap would drop (list endpoints)', async () => {
    const { fn } = fakeFetch(() =>
      json({ success: true, data: { resources: [{ id: 1 }] }, meta: { pagination: { total: 1 } } }),
    )
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('t'), fetch: fn })

    const res = await tydal.resources.list<{ data: { resources: unknown[] }; meta: { pagination: { total: number } } }>()

    expect(res.data.resources).toEqual([{ id: 1 }])
    expect(res.meta.pagination.total).toBe(1)
  })

  it('throws TydalApiError with status and field errors on failure', async () => {
    const { fn } = fakeFetch(() =>
      json({ success: false, message: 'Validation failed', errors: { name: ['required'] } }, 422),
    )
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('t'), fetch: fn })

    const err = await tydal.resources.get(1).catch((e) => e)
    expect(err).toBeInstanceOf(TydalApiError)
    expect(err.status).toBe(422)
    expect(err.message).toBe('Validation failed')
    expect(err.errors).toEqual({ name: ['required'] })
  })

  it('refreshes once and retries on 401, then succeeds', async () => {
    let tokenValue = 'stale'
    let call = 0
    const auth: TokenProvider = {
      getToken: () => tokenValue,
      refresh: async () => {
        tokenValue = 'fresh'
        return true
      },
    }
    const { fn, calls } = fakeFetch(() => {
      call += 1
      return call === 1
        ? json({ message: 'Unauthorized' }, 401)
        : json({ success: true, data: { ok: true } })
    })
    const tydal = createTydalClient({ baseUrl: base, auth, fetch: fn })

    const res = await tydal.resources.get<{ ok: boolean }>(1)

    expect(res).toEqual({ ok: true })
    expect(calls).toHaveLength(2)
    expect((calls[0].init.headers as Record<string, string>)['Authorization']).toBe('Bearer stale')
    expect((calls[1].init.headers as Record<string, string>)['Authorization']).toBe('Bearer fresh')
  })

  it('calls onUnauthorized when refresh is unavailable', async () => {
    const onUnauthorized = vi.fn()
    const auth: TokenProvider = { getToken: () => 'tok', onUnauthorized }
    const { fn } = fakeFetch(() => json({ message: 'Unauthorized' }, 401))
    const tydal = createTydalClient({ baseUrl: base, auth, fetch: fn })

    await tydal.resources.get(1).catch(() => undefined)

    expect(onUnauthorized).toHaveBeenCalledOnce()
  })

  it('passes the abort signal through to fetch', async () => {
    const { fn, calls } = fakeFetch(() => json({ data: {} }))
    const tydal = createTydalClient({ baseUrl: base, auth: staticToken('t'), fetch: fn })
    const controller = new AbortController()

    await tydal.resources.catalogue(1, { signal: controller.signal })

    expect(calls[0].init.signal).toBe(controller.signal)
  })
})
