/**
 * Authentication Service for TYDAL Frontend 2
 *
 * Handles login, logout, and token management using cookies
 * Adapted for Tydal Backend API
 */

import { createTydalClient, TydalApiError } from '@tydal/client'
import type { OrgRole } from '../constants/roles'
import { API_BASE_URL } from '../constants/api'

export interface LoginResponse {
  status?: string
  error?: string | Record<string, string[]> | null
  /** TYDAL's reason on a failed login, e.g. "Invalid credentials" or a wait after too many attempts. */
  message?: string
  code?: number
  data?: {
    token?: string  // Changed from access_token to token for tydal backend
    token_type?: string
    expires_at?: string
    expires_in?: number  // Token lifetime in seconds
    user_id?: number | string
  }
}

export interface Collection {
  id: string | number
  name: string
  slug?: string
  coll_resource_count?: number
  resource_count?: number
  resource_type?: string | null
  max_num_file?: number
  scheme_id?: string
  index_id?: string
}

export interface Organization {
  id: string | number
  name: string
  slug?: string
  org_resource_count?: number
  resource_count?: number
  type?: string
  collections?: Collection[]
}

export interface User {
  id: string | number
  email: string
  name: string
  is_superadmin?: boolean
  is_active?: boolean
  current_organization_id?: string | number
  /**
   * The membership role in the organization currently in context — null for a
   * platform admin who is not a member, which is normal and not a problem.
   * Typed so a stray value (the retired `org-admin`) is a compile error.
   */
  current_organization_role?: OrgRole | null
  /**
   * Permission strings for the effective role, straight from the server's
   * config/permissions.php. Used to decide which write controls to render —
   * see `can()` in constants/roles.ts. Never a substitute for the API's own
   * authorization.
   */
  permissions?: string[]
  organizations?: Organization[]
  workspaces?: Array<{
    id: string | number
    name: string
    organization_id: string | number
    type: string
  }>
}

class AuthService {
  private tokenKey = 'JWT'
  private tokenExpiryKey = 'JWT_EXPIRY'

  /** Own SDK client for the auth endpoints. Built here — NOT imported from
   *  tydalClient.ts — to avoid a cycle (tydalClient delegates its TokenProvider
   *  to this service). No refresh-retry: this service IS the refresh mechanism. */
  private client = createTydalClient({
    baseUrl: API_BASE_URL,
    auth: { getToken: () => this.getToken() },
  })

  /**
   * Login with email and password
   */
  async login(email: string, password: string): Promise<LoginResponse> {
    try {
      // raw body; the SDK throws on bad credentials — we surface the body so the
      // UI keeps its "returns LoginResponse with an error field" contract.
      const data = await this.client.auth.login<LoginResponse>(email, password)

      // Tydal backend uses 'token' field instead of 'access_token'
      if (data.data?.token) {
        this.setToken(data.data.token)
        if (data.data?.expires_in) {
          this.setTokenExpiry(Date.now() + data.data.expires_in * 1000)
        }
      }

      return data
    } catch (error) {
      if (error instanceof TydalApiError) {
        return (error.body ?? { error: error.message }) as LoginResponse
      }
      return {
        error: error instanceof Error ? error.message : 'Network error occurred',
      } as LoginResponse
    }
  }

  /**
   * Logout user and clear token
   */
  async logout(): Promise<void> {
    try {
      await this.client.auth.logout()
    } catch (error) {
      console.error('Logout error:', error)
    } finally {
      this.removeToken()
      window.location.href = '/login'
    }
  }

  /**
   * Get current user data
   */
  async getUser(): Promise<{ data: User } | null> {
    try {
      // Real backend wraps in `{ success, data: { user } }` (SDK unwraps → `{ user }`);
      // tolerate a bare `{ data: { user } }` too.
      const body = await this.client.auth.me<{ user?: User; data?: { user?: User } }>()
      const user = body?.user ?? body?.data?.user
      return user ? { data: user } : null
    } catch (error) {
      console.error('Get user error:', error)
      return null
    }
  }

  /**
   * `; Secure` only over HTTPS — keeps the auth cookie off plaintext HTTP in
   * production while leaving http://localhost dev working. Kept identical
   * between set and clear so the cookie reliably deletes over HTTPS.
   */
  private secureAttr(): string {
    return typeof location !== 'undefined' && location.protocol === 'https:' ? '; Secure' : ''
  }

  /**
   * Store JWT token in cookie
   */
  setToken(token: string): void {
    const maxAge = 31536000 // 1 year
    document.cookie = `${this.tokenKey}=${token}; path=/; max-age=${maxAge}; SameSite=Lax${this.secureAttr()}`
  }

  /**
   * Get JWT token from cookie
   */
  getToken(): string | null {
    const name = `${this.tokenKey}=`
    const cookies = document.cookie.split(';')

    for (const cookie of cookies) {
      const trimmedCookie = cookie.trim()
      if (trimmedCookie.startsWith(name)) {
        return trimmedCookie.substring(name.length)
      }
    }

    return null
  }

  /**
   * Store token expiration time in localStorage
   */
  setTokenExpiry(expiryTime: number): void {
    try {
      localStorage.setItem(this.tokenExpiryKey, expiryTime.toString())
    } catch (e) {
      console.warn('Could not store token expiry:', e)
    }
  }

  /**
   * Get token expiration time
   */
  getTokenExpiry(): number | null {
    try {
      const expiry = localStorage.getItem(this.tokenExpiryKey)
      return expiry ? parseInt(expiry, 10) : null
    } catch (e) {
      return null
    }
  }

  /**
   * Check if token is expired
   */
  isTokenExpired(): boolean {
    const expiry = this.getTokenExpiry()
    if (!expiry) return false
    // Add 5 minute buffer before actual expiry
    return Date.now() >= (expiry - 300000)
  }

  /**
   * Check if token should be refreshed (refresh when 80% expired)
   */
  shouldRefreshToken(): boolean {
    const expiry = this.getTokenExpiry()
    if (!expiry) return false
    const timeLeft = expiry - Date.now()
    const totalDuration = 3600000 // 1 hour in milliseconds
    return timeLeft < (totalDuration * 0.2) // Refresh when less than 20% (12 minutes) left
  }

  /**
   * Refresh the authentication token
   */
  async refreshToken(): Promise<boolean> {
    try {
      // raw body `{ data: { token, expires_in } }`; client adds the bearer header.
      const data = await this.client.auth.refresh<{ data?: { token?: string; expires_in?: number } }>()

      if (data.data?.token) {
        this.setToken(data.data.token)
        if (data.data?.expires_in) {
          this.setTokenExpiry(Date.now() + data.data.expires_in * 1000)
        }
        return true
      }

      return false
    } catch (error) {
      console.error('Refresh token error:', error)
      return false
    }
  }

  /**
   * Remove JWT token from cookie
   */
  removeToken(): void {
    document.cookie = `${this.tokenKey}=; path=/; max-age=0; SameSite=Lax${this.secureAttr()}`
    try {
      localStorage.removeItem(this.tokenExpiryKey)
    } catch (e) {
      console.warn('Could not remove token expiry:', e)
    }
  }

  /**
   * Check if user is authenticated
   */
  isAuthenticated(): boolean {
    const token = this.getToken()
    if (!token) return false

    // Check if token is expired
    if (this.isTokenExpired()) {
      this.removeToken()
      return false
    }

    return true
  }

  /**
   * Get authorization header value
   */
  getAuthHeader(): string | null {
    const token = this.getToken()
    return token ? `Bearer ${token}` : null
  }

  /**
   * Get API base URL
   */
  getApiBaseUrl(): string {
    return API_BASE_URL
  }

  // authenticatedFetch was removed: all callers now go through @tydal/client,
  // which carries the auth header and does reactive 401 refresh-and-retry.
  // The TokenProvider in tydalClient.ts delegates to getToken/refreshToken here.
}

export default new AuthService()
