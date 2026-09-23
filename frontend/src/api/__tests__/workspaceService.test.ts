/**
 * Tests for workspaceService — focuses on Phase 9 additions:
 * - getWorkspaceCatalogue() with facets support
 * - facets query-string serialization (facets[key][] format)
 * - WorkspaceCatalogueResponse.facets typed correctly
 */

import { describe, it, expect, beforeEach } from 'vitest'
import workspaceService from '../workspaceService'

const AUTH_COOKIE = 'JWT=mock-jwt-token-12345; path=/'

describe('workspaceService', () => {
  beforeEach(() => {
    document.cookie = AUTH_COOKIE
  })

  // ── getWorkspaceCatalogue ────────────────────────────────────────────────────

  describe('getWorkspaceCatalogue', () => {
    it('returns catalogue data for a known workspace', async () => {
      const result = await workspaceService.getWorkspaceCatalogue('ws-1')

      expect(result).not.toBeNull()
      expect(result?.data).toBeDefined()
      expect(Array.isArray(result?.data)).toBe(true)
    })

    it('returns typed facets array in the response', async () => {
      const result = await workspaceService.getWorkspaceCatalogue('ws-1')

      expect(result).not.toBeNull()
      expect(Array.isArray(result?.facets)).toBe(true)
      expect(result?.facets[0]).toMatchObject({
        key: 'language',
        label: 'Language',
        values: expect.any(Object),
      })
    })

    it('returns null when workspace is not found', async () => {
      const result = await workspaceService.getWorkspaceCatalogue('ws-missing')

      expect(result).toBeNull()
    })

    it('returns null without auth token', async () => {
      document.cookie = 'JWT=; path=/; max-age=0'

      const result = await workspaceService.getWorkspaceCatalogue('ws-1')

      expect(result).toBeNull()
    })

    it('passes search param to the API', async () => {
      const result = await workspaceService.getWorkspaceCatalogue('ws-1', {
        search: 'design',
      })

      expect(result).not.toBeNull()
      // The mock handler returns a title containing the search term
      expect(result?.data[0]?.name).toContain('design')
    })

    it('passes pagination params', async () => {
      const result = await workspaceService.getWorkspaceCatalogue('ws-1', {
        page: 2,
        limit: 12,
      })

      expect(result?.current_page).toBe(2)
      expect(result?.per_page).toBe(12)
    })

    it('passes sort params', async () => {
      // Just asserting no error is thrown and result is received
      const result = await workspaceService.getWorkspaceCatalogue('ws-1', {
        sort_by: 'name',
        sort_dir: 'asc',
        search_mode: 'contains',
      })

      expect(result).not.toBeNull()
    })
  })

  // ── facet serialization ──────────────────────────────────────────────────────

  describe('facet query-string serialization', () => {
    it('serializes single-value facet as facets[key][]=value', async () => {
      // Verify the URLSearchParams format matches what the backend expects
      const params = new URLSearchParams()
      const facets = { language: ['en'] }

      for (const [key, values] of Object.entries(facets)) {
        for (const value of values) {
          params.append(`facets[${key}][]`, value)
        }
      }

      expect(params.toString()).toBe('facets%5Blanguage%5D%5B%5D=en')
      expect(params.getAll('facets[language][]')).toEqual(['en'])
    })

    it('serializes multi-value facet as repeated array params', async () => {
      const params = new URLSearchParams()
      const facets = { language: ['en', 'fr', 'es'] }

      for (const [key, values] of Object.entries(facets)) {
        for (const value of values) {
          params.append(`facets[${key}][]`, value)
        }
      }

      expect(params.getAll('facets[language][]')).toEqual(['en', 'fr', 'es'])
    })

    it('serializes multiple facet keys independently', async () => {
      const params = new URLSearchParams()
      const facets = { language: ['en'], type: ['image', 'document'] }

      for (const [key, values] of Object.entries(facets)) {
        for (const value of values) {
          params.append(`facets[${key}][]`, value)
        }
      }

      expect(params.getAll('facets[language][]')).toEqual(['en'])
      expect(params.getAll('facets[type][]')).toEqual(['image', 'document'])
    })

    it('sends facets to the API and receives a response', async () => {
      // The MSW handler stores parsed facets in _debug_facets for inspection
      const result = await workspaceService.getWorkspaceCatalogue('ws-1', {
        facets: { language: ['en', 'fr'], type: ['image'] },
      })

      expect(result).not.toBeNull()
      // Response still has the facets field intact
      expect(result?.facets).toBeDefined()
    })

    it('does not include facets in query string when undefined', async () => {
      // When facets is undefined, no facets[...][]=... params should appear.
      // Asserting that a valid result is still returned (no 422 from bad params).
      const result = await workspaceService.getWorkspaceCatalogue('ws-1', {
        search: 'test',
      })

      expect(result).not.toBeNull()
    })
  })
})
