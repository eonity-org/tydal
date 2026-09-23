/**
 * Workspace Service for Tydal Frontend
 *
 * Handles fetching workspaces and their resource catalogues.
 */

import { tydal } from './tydalClient'
import { TydalApiError, type BulkActionResult } from '@tydal/client'
import type { ResourceData, Facet } from './resourceService'

export interface Workspace {
  id: string
  name: string
  slug?: string
  description?: string
  is_active?: boolean
  is_default?: boolean
  is_system?: boolean
  purpose?: string | null
  auto_approve_status?: 'pending' | 'running' | 'done' | 'failed' | null
  resources_count?: number
  organization_id?: string | number
  user_owner_id?: string | number
}

export interface AutoApproveLog {
  resources_processed: number
  names_applied: number
  descriptions_applied: number
  tags_applied: number
  tags_skipped: number
  dry_run: boolean
  log: string[]
  partial?: boolean
}

export interface AityBatch extends Workspace {
  resources_count: number
  files_count: number
  aity_processing_count: number
  created_at: string
  auto_approve_log?: AutoApproveLog | null
  auto_approve_reviewed_at?: string | null
}

export interface AityBatchesResponse {
  data: { batches: AityBatch[] }
  meta: { pagination: { current_page: number; per_page: number; total: number; has_more: boolean } }
}

export interface WorkspaceAityStatus {
  workspace_id: number
  total: number
  processing: number
  ready: number
  is_ready: boolean
  auto_approve_status: 'pending' | 'running' | 'done' | 'failed' | null
  by_status: Record<string, number>
}

export interface WorkspaceCatalogueParams {
  page?: number
  limit?: number
  search?: string
  sort_by?: 'updated_at' | 'name' | 'id'
  sort_dir?: 'asc' | 'desc'
  search_mode?: 'prefix' | 'contains' | 'exact'
  facets?: Record<string, string[]>
}

export interface WorkspaceCatalogueResponse {
  data: ResourceData[]
  facets: Facet[]
  total: number
  per_page: number
  current_page: number
  last_page: number
  has_lexical_matches?: boolean
}

export interface RagSource {
  resource_id: string
  resource_name: string
  file_id: string
  pages: number[]
}

export interface AskWorkspaceResponse {
  success: boolean
  data: {
    answer: string
    sources: RagSource[]
    context_truncated?: boolean
  }
}

/** Map a thrown SDK error to the legacy "message string" failure contract. */
function errMessage(error: unknown): string {
  if (error instanceof TydalApiError) return error.message || `HTTP ${error.status}`
  return error instanceof Error ? error.message : 'Network error'
}

class WorkspaceService {
  /**
   * `includeSystem` additionally returns machine-managed workspaces (AITY
   * review batches, vault writers). Only the workspace manager asks for them —
   * they are not browsing scopes, so they must not reach the workspace tabs or
   * any picker. The server grants it to org admins and above only.
   */
  async getWorkspaces(options?: { includeSystem?: boolean }): Promise<Workspace[] | null> {
    try {
      const body = await tydal.workspaces.list<{ workspaces?: Workspace[] }>({
        includeSystem: options?.includeSystem,
      })
      return body?.workspaces ?? null
    } catch (error) {
      console.error('Get workspaces error:', error)
      return null
    }
  }

  async getWorkspaceCatalogue(
    workspaceId: string,
    params: WorkspaceCatalogueParams = {},
    signal?: AbortSignal,
  ): Promise<WorkspaceCatalogueResponse | null> {
    try {
      // Bare `{ data, facets, ... }` passthrough; facets serialize as facets[k][].
      return await tydal.workspaces.catalogue<WorkspaceCatalogueResponse>(workspaceId, { ...params, signal })
    } catch (error) {
      if (error instanceof DOMException && error.name === 'AbortError') throw error
      console.error('Get workspace catalogue error:', error)
      return null
    }
  }

  async addResource(workspaceId: string, resourceId: string | number): Promise<string | null> {
    try {
      await tydal.workspaces.addResource(workspaceId, resourceId)
      return null
    } catch (error) {
      return errMessage(error)
    }
  }

  async removeResource(workspaceId: string, resourceId: string | number): Promise<string | null> {
    try {
      await tydal.workspaces.removeResource(workspaceId, resourceId)
      return null
    } catch (error) {
      return errMessage(error)
    }
  }

  /**
   * Attach or detach many resources in one request — the basket's workspace
   * action. Throws (with the partial-success report on the error body) only
   * when the whole request failed; a partial result resolves normally.
   */
  async bulkResourceMembership(
    workspaceId: string,
    resourceIds: string[],
    mode: 'add' | 'remove',
  ): Promise<BulkActionResult> {
    return mode === 'add'
      ? tydal.workspaces.bulkAttachResources(workspaceId, resourceIds)
      : tydal.workspaces.bulkDetachResources(workspaceId, resourceIds)
  }

  async createWorkspace(data: { name: string; description?: string; purpose?: string }): Promise<Workspace | null> {
    try {
      const body = await tydal.workspaces.create<{ workspace?: Workspace }>(data)
      return body?.workspace ?? null
    } catch (error) {
      console.error('Create workspace error:', error)
      return null
    }
  }

  async updateWorkspace(id: string, data: { name?: string; description?: string }): Promise<Workspace | null> {
    try {
      const body = await tydal.workspaces.update<{ workspace?: Workspace }>(id, data)
      return body?.workspace ?? null
    } catch (error) {
      console.error('Update workspace error:', error)
      return null
    }
  }

  async deleteWorkspace(id: string): Promise<boolean> {
    try {
      await tydal.workspaces.delete(id)
      return true
    } catch (error) {
      console.error('Delete workspace error:', error)
      return false
    }
  }

  async getAityBatches(page = 1, perPage = 50): Promise<AityBatchesResponse | null> {
    try {
      // raw → keeps the `meta.pagination` sibling.
      return await tydal.workspaces.aityBatches<AityBatchesResponse>(page, perPage)
    } catch (error) {
      console.error('Get aity batches error:', error)
      return null
    }
  }

  async getAityStatus(workspaceId: string): Promise<WorkspaceAityStatus | null> {
    try {
      // SDK unwraps the envelope → the status object (was `data.data`).
      return await tydal.workspaces.aityStatus<WorkspaceAityStatus>(workspaceId)
    } catch (error) {
      console.error('Get workspace aity status error:', error)
      return null
    }
  }

  async ask(
    workspaceId: string,
    question: string,
    k = 5,
    strict?: boolean,
  ): Promise<AskWorkspaceResponse> {
    // raw → caller wants the whole `{ success, data: { answer, sources } }`.
    // On failure the SDK throws TydalApiError (an Error whose `.message` is the
    // server message) — the caller reads `.message`, so let it propagate.
    return await tydal.workspaces.ask<AskWorkspaceResponse>(workspaceId, {
      question,
      k,
      ...(strict !== undefined && { strict }),
    })
  }

  async markAityReviewed(id: number): Promise<boolean> {
    try {
      await tydal.workspaces.markReviewed(id)
      return true
    } catch {
      return false
    }
  }

  async deleteAityBatch(id: number): Promise<boolean> {
    try {
      await tydal.workspaces.delete(id)
      return true
    } catch {
      return false
    }
  }
}

export default new WorkspaceService()
