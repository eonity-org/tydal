/**
 * The TYDAL API base URL, in one place.
 *
 * Configured by `VITE_API_BASE_URL` (see `.env.example`). The fallback is the
 * local dev backend on purpose: a build that forgets the variable then fails
 * visibly against localhost instead of quietly sending traffic — credentials
 * included — to some remote host baked into the bundle.
 */
export const API_BASE_URL: string =
  import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api/v1'

/** Web root of the TYDAL instance (the API base without `/api/v1`). */
export const API_ORIGIN = API_BASE_URL.replace(/\/api\/v1\/?$/, '')
