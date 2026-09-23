/**
 * `collections` namespace — read paths mirroring the legacy `collectionService`.
 * Both endpoints use the standard `{ success, data }` envelope, so the core
 * unwraps `data` and callers pick `.collections` / `.collection`.
 */
import type { Http } from './http.js'

export function collectionsNamespace(http: Http) {
  return {
    /** List collections → unwraps to `{ collections }`. */
    list<T = unknown>(opts?: { signal?: AbortSignal }): Promise<T> {
      return http.get<T>('/collections', { signal: opts?.signal })
    },

    /** Fetch one collection → unwraps to `{ collection }` (or `data`). */
    get<T = unknown>(collectionId: string | number, opts?: { signal?: AbortSignal }): Promise<T> {
      return http.get<T>(`/collections/${collectionId}`, { signal: opts?.signal })
    },

    /**
     * Create a collection in the organization currently in context.
     *
     * The scheme must be one that organization is offered, and the call is
     * refused once its collection quota is used up — both enforced server-side.
     * The search index is chosen for you: the most specific one the
     * organization is entitled to.
     */
    create<T = unknown>(body: {
      name: string
      description?: string
      language?: string
      scheme_id: string
    }): Promise<T> {
      return http.post<T>('/collections', { body })
    },
  }
}

export type CollectionsNamespace = ReturnType<typeof collectionsNamespace>
