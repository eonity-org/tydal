/**
 * Shared response shapes for the TYDAL REST API.
 *
 * TYDAL mostly wraps responses in a Laravel-style envelope: `{ success, data,
 * message, errors }`. The HTTP core unwraps `data` **only when `success === true`**
 * — some endpoints (e.g. the catalogue) return a bare payload `{ data: [...],
 * facets, ... }` with no `success` key, which is returned as-is (see `http.ts`).
 */

/** Standard envelope returned by the TYDAL API. */
export interface ApiEnvelope<T> {
  success?: boolean
  data?: T
  message?: string
  errors?: Record<string, string[]>
}

/**
 * What every bulk action answers with.
 *
 * Authorization on a bulk write is per-resource, so acting on a subset is the
 * normal outcome rather than an error: the caller gets the count that landed
 * plus, item by item, why the rest did not, and can keep those selected. The
 * request only throws when nothing at all could be applied (403).
 */
export interface BulkActionResult {
  success: boolean
  requested: number
  applied: number
  skipped: Array<{ id: string; reason: string }>
  /**
   * Whether the search index is already up to date. Small batches reindex
   * inline (`immediate`), so a refetch straight after this response sees the
   * change; large ones go on the queue (`queued`) and the caller should say
   * the list is still catching up.
   */
  indexing?: 'immediate' | 'queued'
}

/** Loose shape for Laravel paginators (fields vary by endpoint). */
export interface PaginatedData<T> {
  data: T[]
  current_page?: number
  last_page?: number
  per_page?: number
  total?: number
  [key: string]: unknown
}
