/**
 * Shared `@tydal/client` instance for the management SPA.
 *
 * The auth strategy delegates to the existing `authService` so we reuse the
 * exact cookie + refresh logic that already works in production — the SDK only
 * adds reactive 401 refresh-and-retry on top. As services migrate off the
 * hand-rolled `fetch` calls, they import `tydal` from here.
 *
 * (Epic 5.0 — first frontend slice: see tydal/docs/ROADMAP_MILESTONES.md.)
 */
import { createTydalClient, type TokenProvider } from '@tydal/client'
import authService from './authService'
import { API_BASE_URL } from '../constants/api'

const auth: TokenProvider = {
  getToken: () => authService.getToken(),
  refresh: () => authService.refreshToken(),
  onUnauthorized: () => {
    authService.removeToken()
    // Reactive refresh already failed → the session is genuinely gone. Stash a
    // reason + where the user was, so the login page can explain and return them
    // there after sign-in (instead of an abrupt, unexplained redirect).
    try {
      const { pathname, search } = window.location
      if (pathname !== '/login') {
        sessionStorage.setItem('tydal:auth_reason', 'expired')
        sessionStorage.setItem('tydal:return_to', pathname + search)
      }
    } catch { /* sessionStorage may be unavailable; redirect anyway */ }
    if (window.location.pathname !== '/login') window.location.href = '/login'
  },
}

export const tydal = createTydalClient({ baseUrl: API_BASE_URL, auth })
