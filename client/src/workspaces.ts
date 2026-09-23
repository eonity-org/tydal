/**
 * `workspaces` namespace — mirrors the legacy `workspaceService`.
 *
 * Envelope notes (why some calls pass `raw: true`):
 *  - `list` / `create` / `update` / `aityStatus` use the standard `{ success,
 *    data }` envelope → unwrapped.
 *  - `catalogue` returns a bare `{ data, facets, ... }` (no `success`) → raw
 *    passthrough handled by the core automatically.
 *  - `aityBatches` and `ask` carry siblings (`meta`, or a `{ success, data }`
 *    the caller wants whole) → `raw: true` so nothing is dropped.
 */
import type { Http } from './http.js'
import type { BulkActionResult } from './types.js'

export interface WorkspaceCatalogueParams {
  page?: number
  limit?: number
  search?: string
  sort_by?: 'updated_at' | 'name' | 'id'
  sort_dir?: 'asc' | 'desc'
  search_mode?: 'prefix' | 'contains' | 'exact'
  facets?: Record<string, string[]>
  signal?: AbortSignal
}

export function workspacesNamespace(http: Http) {
  return {
    /**
     * List workspaces → unwraps to `{ workspaces }`.
     *
     * `includeSystem` asks for the machine-managed ones too (AITY review
     * batches, vault writers). The server honours it only for org admins and
     * above, and silently ignores it otherwise, so a caller can pass it
     * without first knowing the viewer's role.
     */
    list<T = unknown>(opts?: { signal?: AbortSignal; includeSystem?: boolean }): Promise<T> {
      return http.get<T>('/workspaces', {
        query: opts?.includeSystem ? { include_system: 1 } : undefined,
        signal: opts?.signal,
      })
    },

    /** Workspace catalogue (bare `{ data, facets, ... }` passthrough). */
    catalogue<T = unknown>(workspaceId: string | number, params: WorkspaceCatalogueParams = {}): Promise<T> {
      const { facets, signal, ...query } = params
      return http.get<T>(`/workspaces/${workspaceId}/catalogue`, { query, facets, signal })
    },

    /** Attach a resource to a workspace. Throws on failure. */
    addResource<T = unknown>(workspaceId: string | number, resourceId: string | number): Promise<T> {
      return http.post<T>(`/workspaces/${workspaceId}/resources`, { body: { resource_id: resourceId } })
    },

    /** Detach a resource from a workspace. Throws on failure. */
    removeResource<T = unknown>(workspaceId: string | number, resourceId: string | number): Promise<T> {
      return http.delete<T>(`/workspaces/${workspaceId}/resources/${resourceId}`)
    },

    /**
     * Attach many resources at once (max 200). Because workspace membership is
     * what a vault projects, this is also the bulk publish gesture.
     *
     * Resolves with a partial-success report; throws only when not one id
     * could be applied. `raw` keeps the report's fields, which sit beside
     * `success` rather than under `data`.
     */
    bulkAttachResources(
      workspaceId: string | number,
      resourceIds: Array<string | number>,
    ): Promise<BulkActionResult> {
      return http.post<BulkActionResult>(`/workspaces/${workspaceId}/resources/bulk-attach`, {
        body: { resource_ids: resourceIds },
        raw: true,
      })
    },

    /** Detach many resources at once (max 200). See `bulkAttachResources`. */
    bulkDetachResources(
      workspaceId: string | number,
      resourceIds: Array<string | number>,
    ): Promise<BulkActionResult> {
      return http.post<BulkActionResult>(`/workspaces/${workspaceId}/resources/bulk-detach`, {
        body: { resource_ids: resourceIds },
        raw: true,
      })
    },

    /** Create a workspace → unwraps to `{ workspace }`. */
    create<T = unknown>(body: Record<string, unknown>): Promise<T> {
      return http.post<T>('/workspaces', { body })
    },

    /** Update a workspace → unwraps to `{ workspace }`. */
    update<T = unknown>(workspaceId: string | number, body: Record<string, unknown>): Promise<T> {
      return http.put<T>(`/workspaces/${workspaceId}`, { body })
    },

    /** Delete a workspace (also used to delete an AITY batch). Throws on failure. */
    delete<T = unknown>(workspaceId: string | number): Promise<T> {
      return http.delete<T>(`/workspaces/${workspaceId}`)
    },

    /** AITY batches (raw — keeps the `meta.pagination` sibling). */
    aityBatches<T = unknown>(page = 1, perPage = 50): Promise<T> {
      return http.get<T>('/workspaces/aity-batches', { query: { page, per_page: perPage }, raw: true })
    },

    /** AITY status for a workspace → unwraps `data`. */
    aityStatus<T = unknown>(workspaceId: string | number): Promise<T> {
      return http.get<T>(`/workspaces/${workspaceId}/aity-status`)
    },

    /** RAG ask over a workspace (raw — caller wants the whole `{ success, data }`). */
    ask<T = unknown>(workspaceId: string | number, body: Record<string, unknown>): Promise<T> {
      return http.post<T>(`/workspaces/${workspaceId}/ask`, { body, raw: true })
    },

    /** Mark an AITY batch reviewed. Throws on failure. */
    markReviewed<T = unknown>(workspaceId: string | number): Promise<T> {
      return http.post<T>(`/workspaces/${workspaceId}/mark-reviewed`)
    },
  }
}

export type WorkspacesNamespace = ReturnType<typeof workspacesNamespace>
