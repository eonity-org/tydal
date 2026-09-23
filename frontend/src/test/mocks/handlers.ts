/**
 * MSW (Mock Service Worker) API handlers for TYDAL Frontend2 tests
 */

import { http, HttpResponse } from 'msw'
import {
  mockLoginResponse,
  mockUser,
  mockCatalogueResponse,
  mockCollections,
} from '../mockData'

// Match both relative and absolute URLs
const API_BASE_URL = 'http://api.test/api/v1'

export const handlers = [
  // Authentication endpoints — paths match authService.ts: /login, /logout, /me, /refresh
  http.post(`${API_BASE_URL}/login`, async ({ request }) => {
    const body = await request.json() as { email?: string; password?: string }
    const { email, password } = body

    if (email === 'test@tydal.com' && password === 'password123') {
      return HttpResponse.json(mockLoginResponse)
    }

    return HttpResponse.json({ error: 'Invalid credentials' }, { status: 401 })
  }),

  http.post(`${API_BASE_URL}/logout`, async () => {
    return HttpResponse.json({ success: true })
  }),

  http.get(`${API_BASE_URL}/me`, async ({ request }) => {
    const authHeader = request.headers.get('Authorization')

    if (!authHeader) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }

    const url = new URL(request.url)
    const lite = url.searchParams.get('lite') === 'true'
    const user = lite ? { ...mockUser, workspaces: undefined } : mockUser

    // Shape matches AuthController@me: { data: { user } } — authService.getUser
    // extracts data.data.user and rewraps as { data: user }.
    return HttpResponse.json({ data: { user } })
  }),

  // Refresh requires a valid token (real backend 401s without one). Without this
  // handler the SDK's 401-refresh-retry would passthrough to the network.
  http.post(`${API_BASE_URL}/refresh`, async ({ request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }
    return HttpResponse.json({ data: { token: 'mock-jwt-token-12345', expires_in: 3600 } })
  }),

  // Catalogue/Resources endpoints
  http.get(`${API_BASE_URL}/catalogue/:collectionId`, async ({ params, request }) => {
    const collectionId = Number(params.collectionId)

    // Real catalogue route is behind auth — 401 without a token.
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }

    // Return 404 for missing collection
    if (collectionId === 999) {
      return HttpResponse.json({ error: 'Collection not found' }, { status: 404 })
    }

    const url = new URL(request.url)
    const page = Number(url.searchParams.get('page')) || 1
    const limit = Number(url.searchParams.get('limit')) || 48
    const search = url.searchParams.get('search')

    // Get all search params to parse facets
    const allParams = Array.from(url.searchParams.entries())

    console.log('[MSW] Mock catalogue request:', {
      collectionId,
      page,
      limit,
      search,
      allParams,
    })

    // Apply search filter
    let filteredData = mockCatalogueResponse.data
    if (search) {
      const searchLower = search.toLowerCase()
      filteredData = filteredData.filter(
        (resource) =>
          resource.name.toLowerCase().includes(searchLower) ||
          resource.data.description?.text?.toLowerCase().includes(searchLower)
      )
    }

    // Apply facet filters from allParams
    if (allParams.length > 0) {
      const facetFilters: Record<string, string[]> = {}
      allParams.forEach(([key, value]) => {
        // Parse facets[type][]=image format
        const match = key.match(/facets\[([^\]]+)\]\[\]/)
        if (match) {
          const [, facetKey] = match
          if (!facetFilters[facetKey]) {
            facetFilters[facetKey] = []
          }
          facetFilters[facetKey].push(value)
        }
      })

      // Apply type filters
      if (facetFilters.type && facetFilters.type.length > 0) {
        filteredData = filteredData.filter((resource) => facetFilters.type.includes(resource.type))
        console.log('[MSW] Applied type filters:', facetFilters.type)
      }
    }

    return HttpResponse.json({
      ...mockCatalogueResponse,
      data: filteredData,
      total: filteredData.length,
      current_page: page,
      per_page: limit,
    })
  }),

  // Organizations & Collections
  http.get(`${API_BASE_URL}/collection`, async () => {
    return HttpResponse.json({ data: mockCollections })
  }),

  http.get(`${API_BASE_URL}/organization/:id/workspaces`, async () => {
    return HttpResponse.json({
      data: [
        { id: 1, name: 'Main Workspace', organization_id: 1, type: 'personal' },
      ],
    })
  }),

  // ── Vault endpoints ──────────────────────────────────────────────────────────

  // Active Vaults list (any authenticated user)
  http.get(`${API_BASE_URL}/vaults`, async ({ request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }
    return HttpResponse.json({
      success: true,
      data: {
        vaults: [
          { id: 'vault-1', name: 'Public Vault', slug: 'public-vault', salt: 's1', has_public_workspace: false, is_downloadable: true, hash_ttl_hours: null, allowed_ips: null, base_url: null, state: 'private', created_at: '2026-03-01T00:00:00Z' },
          { id: 'vault-2', name: 'Restricted Vault', slug: 'restricted-vault', salt: 's2', has_public_workspace: false, is_downloadable: false, hash_ttl_hours: 48, allowed_ips: ['10.0.0.1'], base_url: 'https://vault.example.com', state: 'private', created_at: '2026-03-01T00:00:00Z' },
        ],
      },
    })
  }),

  // Platform Vault CRUD (superadmin)
  http.get(`${API_BASE_URL}/platform/vaults`, async ({ request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }
    return HttpResponse.json({
      success: true,
      data: { vaults: [
        { id: 'vault-1', name: 'Public Vault', slug: 'public-vault', salt: 's1', has_public_workspace: false, is_downloadable: true, hash_ttl_hours: null, allowed_ips: null, base_url: null, state: 'private', created_at: '2026-03-01T00:00:00Z' },
      ] },
      meta: { pagination: { current_page: 1, per_page: 20, total: 1, has_more: false } },
    })
  }),

  http.post(`${API_BASE_URL}/platform/vaults`, async ({ request }) => {
    const body = await request.json() as Record<string, unknown>
    return HttpResponse.json({
      success: true,
      data: { vault: { id: 'vault-new', ...body, created_at: '2026-03-26T00:00:00Z' } },
      message: 'Vault created successfully',
    }, { status: 201 })
  }),

  http.put(`${API_BASE_URL}/platform/vaults/:id`, async ({ params, request }) => {
    const body = await request.json() as Record<string, unknown>
    return HttpResponse.json({
      success: true,
      data: { vault: { id: params.id, ...body } },
      message: 'Vault updated successfully',
    })
  }),

  http.delete(`${API_BASE_URL}/platform/vaults/:id`, async () => {
    return HttpResponse.json({ success: true, message: 'Vault deleted successfully' })
  }),

  // Workspace catalogue
  http.get(`${API_BASE_URL}/workspaces/:wsId/catalogue`, async ({ params, request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }

    if (params.wsId === 'ws-missing') {
      return HttpResponse.json({ success: false, message: 'Workspace not found' }, { status: 404 })
    }

    const url = new URL(request.url)
    const search = url.searchParams.get('search') ?? ''
    const page = Number(url.searchParams.get('page')) || 1
    const limit = Number(url.searchParams.get('limit')) || 48

    // Collect parsed facets for inspection
    const facets: Record<string, string[]> = {}
    for (const [key, value] of url.searchParams.entries()) {
      const match = key.match(/^facets\[([^\]]+)\]\[\]$/)
      if (match) {
        const facetKey = match[1]
        if (!facets[facetKey]) facets[facetKey] = []
        facets[facetKey].push(value)
      }
    }

    const mockFacets = [
      { key: 'language', label: 'Language', values: { en: { count: 3 }, fr: { count: 1 } } },
      { key: 'semantic_tags', label: 'Tags', values: {} },
    ]

    const data = search
      ? [{ id: 'ws-resource-1', name: `Result for ${search}`, type: 'document', active: true }]
      : [
          { id: 'ws-resource-1', name: 'Workspace Resource 1', type: 'image', active: true },
          { id: 'ws-resource-2', name: 'Workspace Resource 2', type: 'document', active: true },
        ]

    return HttpResponse.json({
      data,
      facets: mockFacets,
      total: data.length,
      per_page: limit,
      current_page: page,
      last_page: 1,
      _debug_facets: facets,
    })
  }),

  // Workspace Vault association
  http.get(`${API_BASE_URL}/workspaces/:wsId/vaults`, async () => {
    return HttpResponse.json({
      success: true,
      data: { vaults: [
        { id: 'vault-1', name: 'Public Vault', slug: 'public-vault', salt: 's1', has_public_workspace: false, is_downloadable: true, hash_ttl_hours: null, allowed_ips: null, base_url: null, state: 'private', created_at: '2026-03-01T00:00:00Z' },
      ] },
    })
  }),

  http.post(`${API_BASE_URL}/workspaces/:wsId/vaults`, async () => {
    return HttpResponse.json({ success: true, message: 'Vault attached' })
  }),

  http.delete(`${API_BASE_URL}/workspaces/:wsId/vaults/:vaultId`, async () => {
    return HttpResponse.json({ success: true, message: 'Vault detached' })
  }),

  // Resource Vault links
  http.get(`${API_BASE_URL}/resources/:resourceId/vault-links`, async ({ params, request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }

    if (params.resourceId === 'missing-resource') {
      return HttpResponse.json({ error: 'Not found' }, { status: 404 })
    }

    if (params.resourceId === 'resource-no-links') {
      return HttpResponse.json({
        success: true,
        data: {
          resource: { id: params.resourceId, name: 'Empty Resource', links: [] },
          files: [],
        },
      })
    }

    return HttpResponse.json({
      success: true,
      data: {
        resource: {
          id: params.resourceId,
          name: 'Test Resource',
          links: [
            {
              id: 1,
              vault_id: 'vault-1',
              vault_name: 'Public Vault',
              workspace_id: 10,
              workspace_name: 'Main Workspace',
              hash: 'Ab3xZ9Kp',
              url: 'http://localhost:8001/vault/Ab3xZ9Kp',
              expires_at: null,
              is_expired: false,
            },
          ],
        },
        files: [
          {
            id: 'file-uuid-1',
            filename: 'photo.jpg',
            mime_type: 'image/jpeg',
            size: 204800,
            links: [
              {
                id: 2,
                vault_id: 'vault-1',
                vault_name: 'Public Vault',
                workspace_id: 10,
                workspace_name: 'Main Workspace',
                hash: 'Cd5yW2Lm',
                url: 'http://localhost:8001/vault/Cd5yW2Lm',
                expires_at: null,
                is_expired: false,
              },
            ],
          },
        ],
      },
    })
  }),

  // ── Notifications ──────────────────────────────────────────────────────────

  http.get(`${API_BASE_URL}/notifications`, async ({ request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }

    return HttpResponse.json({
      data: {
        notifications: [
          {
            id: 'notif-unread-1',
            type: 'App\\Notifications\\GenericEvent',
            data: { title: 'A thing happened', message: 'Details about the thing' },
            read_at: null,
            created_at: '2026-05-19T10:00:00.000Z',
          },
          {
            id: 'notif-read-1',
            type: 'App\\Notifications\\AnotherEvent',
            // No title / message — exercises the renderer's fallback path.
            data: { custom_field: 'value' },
            read_at: '2026-05-19T09:00:00.000Z',
            created_at: '2026-05-19T08:00:00.000Z',
          },
        ],
        unread_count: 1,
      },
    })
  }),

  http.post(`${API_BASE_URL}/notifications/read-all`, async ({ request }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }
    return HttpResponse.json({ data: { message: 'All notifications marked as read.' } })
  }),

  http.post(`${API_BASE_URL}/notifications/:id/read`, async ({ request, params }) => {
    if (!request.headers.get('Authorization')) {
      return HttpResponse.json({ error: 'Unauthorized' }, { status: 401 })
    }
    if (params.id === 'missing') {
      return HttpResponse.json({ message: 'Notification not found.' }, { status: 404 })
    }
    return HttpResponse.json({ data: { message: 'Marked as read.' } })
  }),

  // ── Error scenarios ─────────────────────────────────────────────────────────

  // Error scenarios
  http.post(`${API_BASE_URL}/login`, async ({ request }) => {
    const body = await request.json() as { email?: string }
    const { email } = body

    if (email === 'error@test.com') {
      return HttpResponse.json({ error: 'Server error' }, { status: 500 })
    }

    return HttpResponse.json(mockLoginResponse)
  }),
]
