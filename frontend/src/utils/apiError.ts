/**
 * One place to turn anything thrown by the API layer into user-facing info.
 *
 * Now that every call goes through `@tydal/client` and throws a typed
 * `TydalApiError` (status + field errors + message), this replaces the
 * per-component `extractError` copies and adds status-aware messaging
 * (validation / auth / not-found / server / network).
 */
import { TydalApiError } from '@tydal/client'

export type ApiErrorKind = 'validation' | 'auth' | 'notfound' | 'server' | 'network' | 'error'

export interface ApiErrorInfo {
  /** User-facing message. */
  message: string
  /** HTTP status when known. */
  status?: number
  kind: ApiErrorKind
  /** field → first error message (422), for inline form display. */
  fieldErrors?: Record<string, string>
}

const DEFAULTS: Record<ApiErrorKind, string> = {
  validation: 'Please check the highlighted fields.',
  auth: 'You are not authorized to do that.',
  notfound: 'The requested item was not found.',
  server: 'Something went wrong on the server. Please try again.',
  network: 'Network error — please check your connection and try again.',
  error: 'An unexpected error occurred.',
}

function kindFor(status: number | undefined): ApiErrorKind {
  if (status === 422) return 'validation'
  if (status === 401 || status === 403) return 'auth'
  if (status === 404) return 'notfound'
  if (status !== undefined && status >= 500) return 'server'
  return 'error'
}

function flatten(errors: Record<string, string[]> | undefined): Record<string, string> | undefined {
  if (!errors || typeof errors !== 'object') return undefined
  const out: Record<string, string> = {}
  for (const [key, value] of Object.entries(errors)) {
    const first = Array.isArray(value) ? value[0] : (value as unknown as string)
    if (first) out[key] = first
  }
  return Object.keys(out).length ? out : undefined
}

/** Normalize any thrown value into structured, user-facing error info. */
export function getApiError(err: unknown): ApiErrorInfo {
  // The SDK standard.
  if (err instanceof TydalApiError) {
    const fieldErrors = flatten(err.errors)
    const kind = kindFor(err.status)
    const message = err.message || (fieldErrors && Object.values(fieldErrors).join(' ')) || DEFAULTS[kind]
    return { message, status: err.status, kind, fieldErrors }
  }
  // Transport / generic errors arrive as plain Errors (fetch throws on network failure).
  if (err instanceof Error) {
    if (/fetch|network|failed to fetch/i.test(err.message)) {
      return { message: DEFAULTS.network, kind: 'network' }
    }
    return { message: err.message || DEFAULTS.error, kind: 'error' }
  }
  // Legacy "throw the parsed body" objects (e.g. vault / workspace.ask).
  if (err && typeof err === 'object') {
    const body = err as { message?: string; error?: unknown; errors?: Record<string, string[]> }
    const fieldErrors = flatten(body.errors)
    const message =
      (typeof body.message === 'string' && body.message) ||
      (typeof body.error === 'string' && body.error) ||
      (fieldErrors && Object.values(fieldErrors).join(' ')) ||
      DEFAULTS.error
    return { message, kind: fieldErrors ? 'validation' : 'error', fieldErrors }
  }
  if (typeof err === 'string') return { message: err, kind: 'error' }
  return { message: DEFAULTS.error, kind: 'error' }
}

/** Just the user-facing message — drop-in for the old per-component `extractError`. */
export function apiErrorMessage(err: unknown): string {
  return getApiError(err).message
}

/** Field-level errors (422) for inline form display, or undefined. */
export function apiFieldErrors(err: unknown): Record<string, string> | undefined {
  return getApiError(err).fieldErrors
}
