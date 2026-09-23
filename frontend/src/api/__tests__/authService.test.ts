/**
 * Tests for authService
 */

import { describe, it, expect, beforeEach } from 'vitest'
import authService from '../authService'
import { mockUser } from '../../test/mockData'

describe('authService', () => {
  beforeEach(() => {
    // Clear cookies before each test
    document.cookie.split(';').forEach((cookie) => {
      const eqPos = cookie.indexOf('=')
      const name = eqPos > -1 ? cookie.substr(0, eqPos) : cookie
      document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT'
    })
  })

  describe('login', () => {
    it('should successfully login with valid credentials', async () => {
      const response = await authService.login('test@tydal.com', 'password123')

      expect(response.data?.token).toBe('mock-jwt-token-12345')
      expect(authService.getToken()).toBe('mock-jwt-token-12345')
    })

    it('should fail with invalid credentials', async () => {
      const response = await authService.login('test@tydal.com', 'wrongpassword')

      expect(response.error).toBeTruthy()
      expect(authService.getToken()).toBeNull()
    })

    it('should handle server errors', async () => {
      const response = await authService.login('error@test.com', 'password123')

      expect(response.error).toBeTruthy()
    })
  })

  describe('logout', () => {
    it('should clear token on logout', async () => {
      authService.setToken('test-token')
      expect(authService.getToken()).toBe('test-token')

      await authService.logout()
      expect(authService.getToken()).toBeNull()
    })
  })

  describe('getUser', () => {
    it('should return user data when authenticated', async () => {
      authService.setToken('mock-jwt-token-12345')
      const response = await authService.getUser()

      expect(response?.data).toEqual(mockUser)
    })

    it('should return null when not authenticated', async () => {
      const response = await authService.getUser()
      expect(response).toBeNull()
    })
  })

  describe('token management', () => {
    it('should set and get token correctly', () => {
      authService.setToken('test-token-123')
      expect(authService.getToken()).toBe('test-token-123')
    })

    it('should remove token correctly', () => {
      authService.setToken('test-token-123')
      authService.removeToken()
      expect(authService.getToken()).toBeNull()
    })

    it('should check authentication status', () => {
      expect(authService.isAuthenticated()).toBe(false)
      authService.setToken('test-token')
      expect(authService.isAuthenticated()).toBe(true)
    })

    it('should get auth header correctly', () => {
      authService.setToken('test-token')
      expect(authService.getAuthHeader()).toBe('Bearer test-token')
    })
  })

  describe('getApiBaseUrl', () => {
    it('should return API base URL', () => {
      const url = authService.getApiBaseUrl()
      expect(url).toBe('http://api.test/api/v1')
    })
  })
})
