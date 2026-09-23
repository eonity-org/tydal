/**
 * `semanticTags` namespace. All calls use the standard envelope, so the core
 * unwraps `data` (an array for `list`, the tag/tags for the rest) and throws
 * `TydalApiError` on `success: false` — callers read `.errors`/`.message`.
 */
import type { Http } from './http.js'

export interface SemanticTagListParams {
  search?: string
  vocabulary?: string
  reviewer?: string
  signal?: AbortSignal
}

export function semanticTagsNamespace(http: Http) {
  return {
    /** List tags → unwraps to the tag array. */
    list<T = unknown>(params: SemanticTagListParams = {}): Promise<T> {
      const { signal, ...query } = params
      return http.get<T>('/semantic-tags', { query, signal })
    },

    /** Create a tag → unwraps to the tag; throws on validation failure. */
    create<T = unknown>(body: Record<string, unknown>): Promise<T> {
      return http.post<T>('/semantic-tags', { body })
    },

    /** Update a tag → unwraps to the tag; throws on validation failure. */
    update<T = unknown>(id: number, body: Record<string, unknown>): Promise<T> {
      return http.put<T>(`/semantic-tags/${id}`, { body })
    },

    /** Delete a tag. Throws on failure. */
    delete(id: number): Promise<unknown> {
      return http.delete(`/semantic-tags/${id}`)
    },

    /** Replace a resource's tag set → unwraps to the resulting tag array. */
    syncResource<T = unknown>(resourceId: string | number, tagIds: number[]): Promise<T> {
      return http.put<T>(`/resources/${resourceId}/semantic-tags`, { body: { tag_ids: tagIds } })
    },
  }
}

export type SemanticTagsNamespace = ReturnType<typeof semanticTagsNamespace>
