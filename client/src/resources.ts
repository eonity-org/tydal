/**
 * `resources` namespace — the first vertical slice.
 *
 * Mirrors the read paths of the legacy `resourceService.ts` (`getResource`,
 * `getCatalogue`) but envelope-unwrapped and cancellable. More methods
 * (create/update/uploadFile/vaultLinks/aiSuggestions) land as the SDK grows.
 */
import type { Http, UploadOptions } from './http.js'
import type { BulkActionResult } from './types.js'

/** The lifecycle axis — see the ResourceState enum on the backend. */
export type ResourceStateValue = 'draft' | 'live' | 'archived'

export interface CatalogueParams {
  page?: number
  limit?: number
  search?: string
  facets?: Record<string, string[]>
  sort_by?: 'updated_at' | 'name' | 'id'
  sort_dir?: 'asc' | 'desc'
  search_mode?: 'prefix' | 'contains' | 'exact'
  /** Abort signal — wire this to cancel in-flight searches. */
  signal?: AbortSignal
}

export interface ResourceListParams {
  page?: number
  limit?: number
  search?: string
  collection_id?: string | number
  signal?: AbortSignal
}

export function resourcesNamespace(http: Http) {
  return {
    /** Fetch a single resource by id. Unwraps the envelope → `{ resource }`. */
    get<T = unknown>(
      resourceId: string | number,
      opts?: { signal?: AbortSignal },
    ): Promise<T> {
      return http.get<T>(`/resources/${resourceId}`, { signal: opts?.signal })
    },

    /**
     * List resources (DB-backed). Returns the raw envelope so the `meta`
     * pagination sibling survives (`{ success, data: { resources }, meta }`).
     */
    list<T = unknown>(params: ResourceListParams = {}): Promise<T> {
      const { signal, ...query } = params
      return http.get<T>('/resources', { query, signal, raw: true })
    },

    /** Fetch the catalogue (ES- or DB-backed) for a collection, with facets. */
    catalogue<T = unknown>(
      collectionId: string | number,
      params: CatalogueParams = {},
    ): Promise<T> {
      const { facets, signal, ...query } = params
      return http.get<T>(`/catalogue/${collectionId}`, { query, facets, signal })
    },

    /** Create a resource (JSON). Unwraps → `{ resource }`; throws on failure. */
    create<T = unknown>(body: Record<string, unknown>): Promise<T> {
      return http.post<T>('/resources', { body })
    },

    /** Update a resource (JSON). Unwraps → `{ resource }`; throws on failure. */
    update<T = unknown>(resourceId: string | number, body: Record<string, unknown>): Promise<T> {
      return http.put<T>(`/resources/${resourceId}`, { body })
    },

    /** Delete a resource. Drafts are removed outright; anything else is soft-deleted into the trash. */
    delete<T = unknown>(resourceId: string | number): Promise<T> {
      return http.delete<T>(`/resources/${resourceId}`)
    },

    /**
     * Move many resources along the lifecycle at once (max 200) — the bulk
     * publish/withdraw. Resolves with a partial-success report; throws only
     * when not one id could be applied.
     */
    bulkState(
      resourceIds: Array<string | number>,
      state: ResourceStateValue,
    ): Promise<BulkActionResult> {
      return http.post<BulkActionResult>('/resources/bulk/state', {
        body: { resource_ids: resourceIds, state },
        raw: true,
      })
    },

    /**
     * Add or remove a set of tags across many resources (max 200).
     *
     * Additive/subtractive, unlike `semanticTags.syncResource`, which replaces
     * one resource's whole tag set — adding a tag to forty resources must not
     * wipe the tags each of them already carries.
     */
    bulkSemanticTags(
      resourceIds: Array<string | number>,
      tagIds: number[],
      mode: 'add' | 'remove',
    ): Promise<BulkActionResult> {
      return http.post<BulkActionResult>('/resources/bulk/semantic-tags', {
        body: { resource_ids: resourceIds, tag_ids: tagIds, mode },
        raw: true,
      })
    },

    /** Restore previously-cleared AI suggestions for a resource. */
    restoreAiSuggestions(resourceId: string | number): Promise<unknown> {
      return http.post(`/resources/${resourceId}/ai-suggestions/restore`)
    },

    /** Clear AI suggestions on a single file of a resource. */
    clearFileAiSuggestions(resourceId: string | number, fileId: string): Promise<unknown> {
      return http.delete(`/resources/${resourceId}/files/${fileId}/ai-suggestions`)
    },

    /**
     * Upload a file to a resource (multipart). Pass `onProgress` for an XHR
     * upload with progress; the caller builds the FormData (File, role, etc.).
     */
    uploadFile<T = unknown>(
      resourceId: string | number,
      formData: FormData,
      opts?: UploadOptions,
    ): Promise<T> {
      return http.upload<T>(`/resources/${resourceId}/files`, formData, opts)
    },
  }
}

export type ResourcesNamespace = ReturnType<typeof resourcesNamespace>
