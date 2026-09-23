/**
 * `auth` namespace — the authentication endpoints.
 *
 *  - `login` / `refresh` are `raw` (callers read the whole `{ data: { token,
 *    expires_in } }` body, which is NOT the standard `success`-wrapped shape).
 *  - `me` uses the standard envelope → unwraps to `{ user }`.
 *  - `logout` is fire-and-forget.
 *
 * Note: distinct from `auth.ts`, which defines the `TokenProvider` *strategy*.
 */
import type { Http } from './http.js'

export function authNamespace(http: Http) {
  return {
    /** Exchange credentials for a token (raw body; no token required). */
    login<T = unknown>(email: string, password: string): Promise<T> {
      return http.post<T>('/login', { body: { email, password }, raw: true })
    },

    /** Invalidate the current token server-side. */
    logout(): Promise<unknown> {
      return http.post('/logout')
    },

    /** Current user → unwraps to `{ user }`. */
    me<T = unknown>(): Promise<T> {
      return http.get<T>('/me')
    },

    /** Refresh the token (raw body). */
    refresh<T = unknown>(): Promise<T> {
      return http.post<T>('/refresh', { raw: true })
    },
  }
}

export type AuthNamespace = ReturnType<typeof authNamespace>
