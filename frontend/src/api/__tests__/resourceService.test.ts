/**
 * Tests for resourceService
 */

import { describe, it, expect, beforeEach } from 'vitest'
import resourceService from '../resourceService'
import { mockResources } from '../../test/mockData'

describe('resourceService', () => {
  beforeEach(() => {
    // Set auth token for all tests
    document.cookie = 'JWT=mock-jwt-token-12345; path=/'
  })

  describe('getCatalogue', () => {
    it('should fetch catalogue data successfully', async () => {
      const response = await resourceService.getCatalogue(1)

      expect(response).not.toBeNull()
      expect(response?.data).toEqual(mockResources)
      expect(response?.facets).toBeDefined()
      expect(response?.total).toBe(2)
    })

    it('should fetch catalogue with pagination params', async () => {
      const response = await resourceService.getCatalogue(1, {
        page: 2,
        limit: 24,
      })

      expect(response?.current_page).toBe(2)
      expect(response?.per_page).toBe(24)
    })

    it('should fetch catalogue with search query', async () => {
      const response = await resourceService.getCatalogue(1, {
        search: 'Test Resource 1',
      })

      expect(response?.data).toHaveLength(1)
      expect(response?.data[0].name).toBe('Test Resource 1')
    })

    it('should fetch catalogue with facet filters', async () => {
      const response = await resourceService.getCatalogue(1, {
        facets: {
          type: ['image'],
        },
      })

      expect(response?.data).toHaveLength(1)
      expect(response?.data[0].type).toBe('image')
    })

    it('should handle missing collection gracefully', async () => {
      const response = await resourceService.getCatalogue(999)
      expect(response).toBeNull()
    })

    it('should return null without auth token', async () => {
      document.cookie = 'JWT=; path=/; max-age=0'
      const response = await resourceService.getCatalogue(1)
      expect(response).toBeNull()
    })
  })

  describe('getApiBaseUrl', () => {
    it('should return API base URL', () => {
      const url = resourceService.getApiBaseUrl()
      expect(url).toBe('http://api.test/api/v1')
    })
  })
})
