/**
 * Resource Service for TYDAL Frontend 2
 *
 * Handles fetching resources, facets, and catalogue data
 * Adapted for Tydal Backend API
 */

import { API_BASE_URL } from '../constants/api'

// Shared SDK — resourceService is fully migrated off hand-rolled fetch (Epic 5.0)
import { tydal } from './tydalClient'
import { TydalApiError, type BulkActionResult } from '@tydal/client'

/** Where a resource sits in its lifecycle: unfinished / normal / withdrawn. */
export type ResourceState = 'draft' | 'live' | 'archived'

export interface AityStatusData {
  file_id: string
  stage: 'queued' | 'extracting' | 'ai_analyzing' | 'done' | 'not_applicable' | 'failed' | null
  error: string | null
  queued_at: string | null
  started_at: string | null
  done_at: string | null
  results: { source: string; has_name: boolean; has_description: boolean; tag_count: number } | null
  suggestions: {
    name: string | null
    description: string | null
    tags: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
    /** Scheme-field suggestions (ai_fill contract) — field name → suggested value */
    metadata?: Record<string, unknown>
  } | null
}

export interface ResourceData {
  // Core fields (match backend exactly - flat structure)
  id: string
  organization_id: string
  collection_id: number
  user_owner_id: string
  type: string
  name: string
  slug: string | null
  description: string | null
  state: ResourceState
  metadata: {
    tags?: string[]
    lom?: any
    lomes?: any
    partials?: Record<string, any>
    [key: string]: any
  }
  payload: {
    public?: boolean
    downloadable?: boolean
    [key: string]: any
  }
  published_at: string | null
  created_at: string
  updated_at: string
  deleted_at: string | null

  // Snapshot file — the default preview image for this resource.
  // Identified by usage containing 'snapshot' on the file record.
  snapshot_file?: ResourceFile & {
    conversion_urls?: {
      original?: string
      thumbnail?: string
      small?: string
      medium?: string
      large?: string
    }
  }

  // URL of the rendered preview image for PDF/audio resources.
  // Populated once the ExtractEmbeddedPreview job completes.
  // Use this in preference to snapshot_file.url when the snapshot_file is not an image.
  preview_snapshot_url?: string | null

  // AI suggestions state (computed by backend on each load)
  ai_suggestions_status?: 'found' | 'processed' | 'none' | 'not_applicable'
  aity_status?: 'not_applicable' | 'queued' | 'aity_in_progress' | 'suggestions_made' | 'automatic_review_done' | 'user_review_done'
  /** True while this resource belongs to a workspace whose auto-approve job is
   *  still queued or running. Used to suppress the "to review" icon prematurely. */
  under_auto_approve?: boolean

  // Per-field provenance. Origin describes where the current value came from;
  // source_file_id pins it to the AITY suggestion's file when applicable;
  // set_by is either a user UUID or an agent identifier like 'aity'.
  name_origin?: 'aity_suggestion' | 'aity_generated' | 'user' | null
  name_source_file_id?: string | null
  name_set_by?: string | null
  name_set_at?: string | null
  description_origin?: 'aity_suggestion' | 'aity_generated' | 'user' | null
  description_source_file_id?: string | null
  description_set_by?: string | null
  description_set_at?: string | null
  tags_origin?: 'aity_suggestion' | 'aity_generated' | 'user' | null
  tags_source_file_id?: string | null
  tags_set_by?: string | null
  tags_set_at?: string | null

  // Resource-level AITY-generated suggestions (multi-component synthesis or workspace
  // tag dedup). source_file_id on these rows is always null — the value is synthetic.
  latest_ai_generated_name_system_file?: {
    id: string
    metadata?: { value?: string }
    applied_at?: string | null
  } | null
  latest_ai_generated_description_system_file?: {
    id: string
    metadata?: { value?: string }
    applied_at?: string | null
  } | null
  latest_ai_generated_tags_system_file?: {
    id: string
    metadata?: {
      value?: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
    }
    applied_at?: string | null
  } | null
  latest_ai_generated_name_system_file_for_display?: {
    id: string
    metadata?: { value?: string }
    applied_at?: string | null
  } | null
  latest_ai_generated_description_system_file_for_display?: {
    id: string
    metadata?: { value?: string }
    applied_at?: string | null
  } | null
  latest_ai_generated_tags_system_file_for_display?: {
    id: string
    metadata?: {
      value?: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
    }
    applied_at?: string | null
  } | null

  // Relationships (flat, as returned by backend)
  files?: ResourceFile[]
  categories?: Category[]
  semanticTags?: SemanticTag[]

  // Legacy fields for compatibility (will be removed)
  data?: {
    description?: {
      lang?: string
      name?: string
      description?: string
      active?: boolean
      partials?: Record<string, any>
      categories?: string[]
      semantic_tags?: Array<{
        id: string
        solr_language: string
        label: string
      }>
      [key: string]: any
    }
    lom?: any
    lomes?: any
    [key: string]: any
  }
  previews?: string[]
  tags?: string[]
  files_count?: number

  // Additional related data
  collection?: Array<{
    id: number
    name: string
    slug: string
    organization_id: number
    created_at: string
    updated_at: string
    max_number_of_files?: number | null
  }>
  workspace?: Array<{
    id: number
    name: string
  }>

  [key: string]: any
}

export interface ResourceFile {
  id: string
  resource_id: string
  filename: string
  mime_type: string
  size?: number
  role?: 'canonical' | 'component' | 'supporting'
  relation?: 'derived' | 'rendition' | 'variant' | 'translation' | 'transcript' | 'extracted' | null
  usage?: string[] | null
  disk?: string
  path?: string
  url?: string
  media_id?: string
  user_owner_id?: string
  metadata?: {
    original_filename?: string
    description?: string
    [key: string]: any
  }
  is_active?: boolean
  // Session-uncommitted upload (edit modal). Cleared by commit-files on Save.
  uncommitted_at?: string | null
  uncommitted_by?: string | null
  latest_tika_system_file?: {
    id: string
    purpose?: string
    metadata?: {
      tika_metadata?: Record<string, string | string[]>
      // EXTRACTED_TEXT fields (set by ExtractFileText)
      chunk_count?: number
      extracted_at?: string
      char_count?: number
      // Embedding failure fields (set by EmbedFileChunks::failed)
      embedding_error?: string
      embedding_failed_at?: string
    }
  } | null
  latest_ai_suggested_tags_system_file?: {
    id: string
    metadata?: {
      value?: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
    }
  } | null
  latest_ai_suggested_name_system_file?: {
    id: string
    metadata?: { value?: string }
  } | null
  latest_ai_suggested_description_system_file?: {
    id: string
    metadata?: { value?: string }
  } | null
  latest_ai_suggested_tags_system_file_for_display?: {
    id: string
    metadata?: {
      value?: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
    }
    applied_at?: string | null
  } | null
  latest_ai_suggested_name_system_file_for_display?: {
    id: string
    metadata?: { value?: string }
    applied_at?: string | null
  } | null
  latest_ai_suggested_description_system_file_for_display?: {
    id: string
    metadata?: { value?: string }
    applied_at?: string | null
  } | null
  processing_status?: {
    stage: 'queued' | 'extracting' | 'ai_analyzing' | 'done' | 'not_applicable' | 'failed' | null
    [key: string]: any
  } | null
  created_at?: string
  updated_at?: string
  [key: string]: any
}

export interface Category {
  id: string
  name: string
  type: string
  [key: string]: any
}

export type TagVocabulary = 'organization' | 'user' | 'ai_generated' | 'taxonomy'
export type TagReviewer   = 'user' | 'aity'

export interface SemanticTag {
  id: number
  label: string
  slug: string
  description?: string | null
  entity_type?: string | null
  vocabulary: TagVocabulary
  reviewer: TagReviewer
  is_active: boolean
  resources_count?: number
  [key: string]: any
}

export interface FacetValue {
  count: number
  selected: boolean
  radio: boolean
  key?: {
    o_key: string
    key: string | null
    key_title: string | null
    subkey: string | null
    value: string | null
  }
}

export interface Facet {
  key: string
  label: string
  values: Record<string, FacetValue>
}

export interface CatalogueData {
  data: ResourceData[]
  facets: Facet[]
  total: number
  per_page: number
  current_page: number
  last_page: number
  has_lexical_matches?: boolean
}

export interface ActivityEvent {
  event: string
  file_id: string | null
  file_name: string | null
  status: 'completed' | 'superseded' | 'failed'
  is_active?: boolean
  /** Present on rows that come from the resource_events audit log (not the synthesized timeline). */
  actor_type?: 'user' | 'aity' | 'system'
  actor_id?: string | null
  target_type?: string | null
  target_id?: string | null
  created_at: string | null
  details: Record<string, any>
}

/** Map an upload failure to the legacy custom messages (413, PHP cap); pass
 *  aborts through untouched. Returns the value to `throw`. */
function uploadError(error: unknown): unknown {
  if (error instanceof DOMException && error.name === 'AbortError') return error
  if (error instanceof TydalApiError) {
    if (error.status === 413) {
      return new Error('File too large — the server rejected it. Maximum upload size is 300 MB.')
    }
    const msg = (error.body as { message?: string } | undefined)?.message
    if (msg === 'The file failed to upload.') {
      return new Error("File rejected by the server — likely exceeds PHP's upload_max_filesize limit. Check public/.user.ini.")
    }
    return new Error(msg || error.message || `HTTP ${error.status}`)
  }
  return error instanceof Error ? error : new Error('Upload failed')
}

class ResourceService {
  /**
   * Get catalogue data (resources + facets) for a collection
   */
  async getCatalogue(
    collectionId: number,
    params?: {
      page?: number
      limit?: number
      search?: string
      facets?: Record<string, string[]>
      sort_by?: 'updated_at' | 'name' | 'id'
      sort_dir?: 'asc' | 'desc'
      search_mode?: 'prefix' | 'contains' | 'exact'
    },
    signal?: AbortSignal
  ): Promise<CatalogueData | null> {
    try {
      // Catalogue returns a bare `{ data, facets, total, ... }` (no `success`
      // key) so the SDK passes it through unchanged. Facets serialize as
      // `facets[key][]=value`, matching the previous hand-rolled query.
      return await tydal.resources.catalogue<CatalogueData>(collectionId, {
        page: params?.page,
        limit: params?.limit,
        search: params?.search,
        sort_by: params?.sort_by,
        sort_dir: params?.sort_dir,
        search_mode: params?.search_mode,
        facets: params?.facets,
        signal,
      })
    } catch (error) {
      // Preserve abort semantics; everything else → null (old contract).
      if (error instanceof DOMException && error.name === 'AbortError') throw error
      console.error('Get catalogue error:', error)
      return null
    }
  }

  async restoreAiSuggestions(resourceId: string | number): Promise<void> {
    // SDK throws TydalApiError when the envelope reports success:false / non-2xx.
    await tydal.resources.restoreAiSuggestions(resourceId)
  }

  async clearFileAiSuggestions(resourceId: string, fileId: string): Promise<void> {
    await tydal.resources.clearFileAiSuggestions(resourceId, fileId)
  }

  /**
   * Get a single resource by ID
   * Returns backend data directly without mapping
   */
  async getResource(resourceId: number | string): Promise<ResourceData | null> {
    try {
      // SDK unwraps the `{ success, data }` envelope → returns `{ resource }`.
      const body = await tydal.resources.get<{ resource: ResourceData }>(resourceId)
      const resource = (body?.resource ?? null) as (ResourceData & { semantic_tags?: unknown }) | null

      // Laravel serialises camelCase relationship names to snake_case in JSON:
      // semanticTags() → "semantic_tags". Normalise to camelCase for the frontend.
      if (resource && resource.semantic_tags !== undefined) {
        ;(resource as Record<string, unknown>).semanticTags = resource.semantic_tags
      }

      return resource
    } catch (error) {
      // SDK throws TydalApiError on non-2xx; preserve the old null-on-failure contract.
      console.error('Get resource error:', error)
      return null
    }
  }


  /**
   * Get resources for Tydal backend using /resources endpoint
   * Returns backend data directly without mapping
   */
  async getResources(
    params?: {
      page?: number
      limit?: number
      search?: string
      collection_id?: string | number
    }
  ): Promise<{ data: ResourceData[]; total?: number; per_page?: number; current_page?: number } | null> {
    try {
      // `raw` envelope so the `meta.pagination` sibling survives unwrapping.
      const body = await tydal.resources.list<{
        data?: { resources?: ResourceData[] }
        meta?: { pagination?: { total?: number; per_page?: number; current_page?: number } }
      }>({
        page: params?.page,
        limit: params?.limit,
        search: params?.search,
        collection_id: params?.collection_id,
      })

      const resources = body?.data?.resources
      if (resources) {
        const pagination = body.meta?.pagination
        return {
          data: resources,
          total: pagination?.total || resources.length,
          per_page: pagination?.per_page,
          current_page: pagination?.current_page,
        }
      }

      return null
    } catch (error) {
      console.error('Get resources error:', error)
      return null
    }
  }

  /**
   * Get API base URL
   */
  getApiBaseUrl(): string {
    return API_BASE_URL
  }

  /**
   * Update a resource with new data
   */
  async updateResource(
    resourceId: string,
    data: {
      name?: string
      type?: string
      collection_id?: number
      description?: string | null
      state?: ResourceState
      metadata?: Record<string, any>
      payload?: Record<string, any>
      language?: string
    }
  ): Promise<ResourceData | null> {
    // SDK unwraps → { resource }; throws TydalApiError (with .message/.errors) on failure.
    const body = await tydal.resources.update<{ resource?: ResourceData }>(resourceId, data)
    return body?.resource ?? null
  }

  /**
   * Create a new resource (JSON body to POST /resources, then upload files separately)
   */
  async createResource(data: {
    name: string
    type: string
    collection_id: number
    state: ResourceState
    description?: string
    language?: string
    metadata?: Record<string, any>
    payload?: { downloadable?: boolean; public?: boolean; featured?: boolean }
  }): Promise<ResourceData | null> {
    // SDK unwraps → { resource }; throws TydalApiError (with .message/.errors) on failure.
    const body = await tydal.resources.create<{ resource?: ResourceData }>(data)
    return body?.resource ?? null
  }

  /**
   * Upload a file with real progress events. Uses XMLHttpRequest because fetch()
   * does not expose upload progress. Accepts an onProgress callback (0–100) and an
   * optional deferCommit flag — when true, the server marks the file as session-
   * uncommitted and the edit modal must call commitFiles() on save.
   */
  async uploadFileWithProgress(
    resourceId: string,
    file: File,
    opts: {
      role?: string
      relation?: string
      usage?: string[]
      deferCommit?: boolean
      onProgress?: (percent: number) => void
      signal?: AbortSignal
    } = {}
  ): Promise<ResourceFile> {
    const {
      role = 'canonical',
      relation,
      usage,
      deferCommit = false,
      onProgress,
      signal,
    } = opts

    const formData = new FormData()
    formData.append('File', file)
    formData.append('role', role)
    if (relation) formData.append('relation', relation)
    if (usage && usage.length > 0) {
      usage.forEach((u) => formData.append('usage[]', u))
    }
    if (deferCommit) formData.append('defer_commit', '1')

    try {
      // SDK uses XHR for upload progress; envelope may be `{success,data:{file}}`
      // (unwrapped → `{file}`) or a bare `{data:{file}}` — handle both.
      const body = await tydal.resources.uploadFile<{ file?: ResourceFile; data?: { file?: ResourceFile } }>(
        resourceId,
        formData,
        { onProgress, signal },
      )
      const fileRecord = body?.file ?? body?.data?.file
      if (fileRecord) return fileRecord
      throw new Error('Upload succeeded but response had no file payload')
    } catch (error) {
      throw uploadError(error)
    }
  }

  /**
   * Commit the current user's session-uncommitted files on a resource. Called by
   * the edit modal on Save to flip uncommitted_at → null and trigger an ES reindex.
   */
  async commitFiles(resourceId: string): Promise<{ committed: number }> {
    // raw → read `{ data: { committed } }`; SDK throws TydalApiError on failure.
    const body = await tydal.http.post<{ data?: { committed?: number } }>(
      `/resources/${resourceId}/commit-files`,
      { raw: true },
    )
    return { committed: Number(body?.data?.committed ?? 0) }
  }

  /**
   * Upload a single file to an existing resource
   */
  async uploadFile(
    resourceId: string,
    file: File,
    role: string = 'canonical',
    relation?: string,
    usage?: string[]
  ): Promise<ResourceFile | null> {
    const formData = new FormData()
    formData.append('File', file)
    formData.append('role', role)
    if (relation) formData.append('relation', relation)
    if (usage && usage.length > 0) {
      usage.forEach((u) => formData.append('usage[]', u))
    }

    try {
      const body = await tydal.resources.uploadFile<{ file?: ResourceFile; data?: { file?: ResourceFile } }>(
        resourceId,
        formData,
      )
      return body?.file ?? body?.data?.file ?? null
    } catch (error) {
      throw uploadError(error)
    }
  }

  /**
   * Remove a single file from a resource (deletes stored file + DB record)
   */
  async setFileCanonical(resourceId: string, fileId: string): Promise<void> {
    // SDK throws TydalApiError (an Error with .message) on failure.
    await tydal.http.patch(`/resources/${resourceId}/files/${fileId}/canonical`)
  }

  async updateFile(resourceId: string, fileId: string, data: { role?: string; relation?: string | null }): Promise<void> {
    await tydal.http.patch(`/resources/${resourceId}/files/${fileId}`, { body: data })
  }

  async setFileSnapshot(resourceId: string, fileId: string): Promise<void> {
    await tydal.http.patch(`/resources/${resourceId}/files/${fileId}/snapshot`)
  }

  async aityEnrichResource(resourceId: string): Promise<void> {
    await tydal.http.post(`/resources/${resourceId}/aity-enrich`)
  }

  /**
   * Run the auto-approval synthesis for a single resource — unifies per-file AI
   * suggestions into resource-level metadata (synthetic name/description + deduped
   * tags across all files). apply=false persists them as AI_GENERATED_* suggestions
   * to review; apply=true also writes them onto the resource. (POST /aity-approve)
   */
  async aityApproveResource(resourceId: string, apply: boolean): Promise<void> {
    await tydal.http.post(`/resources/${resourceId}/aity-approve`, { body: { apply } })
  }

  async aityEnrichFile(resourceId: string, fileId: string): Promise<void> {
    await tydal.http.post(`/resources/${resourceId}/files/${fileId}/aity-enrich`)
  }

  async getAityStatus(resourceId: string, fileId: string): Promise<AityStatusData | null> {
    try {
      const body = await tydal.http.get<{ data?: AityStatusData }>(
        `/resources/${resourceId}/files/${fileId}/aity-status`,
        { raw: true },
      )
      return body?.data ?? null
    } catch {
      return null
    }
  }

  /**
   * Bulk lightweight aity_status fetch for dashboard polling — backend caps at
   * 200 ids per call. Returns an empty array on any error so the caller's
   * "anything in flight" gate can keep iterating without throwing.
   */
  async getAityStatusBulk(resourceIds: Array<string | number>): Promise<Array<{ id: string; aity_status: string | null; updated_at: string | null; under_auto_approve?: boolean }>> {
    if (!resourceIds.length) return []
    try {
      // Chunk into 200-id batches in case the caller passes more.
      const chunks: Array<Array<string | number>> = []
      for (let i = 0; i < resourceIds.length; i += 200) {
        chunks.push(resourceIds.slice(i, i + 200))
      }
      const results: Array<{ id: string; aity_status: string | null; updated_at: string | null; under_auto_approve?: boolean }> = []
      for (const chunk of chunks) {
        try {
          const ids = chunk.map(String).join(',')
          const body = await tydal.http.get<{ data?: typeof results }>(
            `/resources/aity-status?ids=${encodeURIComponent(ids)}`,
            { raw: true },
          )
          if (Array.isArray(body?.data)) results.push(...body.data)
        } catch {
          // skip a failed chunk, keep going
        }
      }
      return results
    } catch {
      return []
    }
  }

  async getResourceActivity(resourceId: string): Promise<ActivityEvent[]> {
    try {
      const body = await tydal.http.get<{ data?: ActivityEvent[] }>(
        `/resources/${resourceId}/activity`,
        { raw: true },
      )
      return body?.data ?? []
    } catch {
      return []
    }
  }

  async deleteFile(resourceId: string, fileId: string): Promise<void> {
    await tydal.http.delete(`/resources/${resourceId}/files/${fileId}`)
  }

  /**
   * Get soft-deleted (trashed) resources for the current organisation
   */
  async getTrashedResources(
    page = 1,
    limit = 48,
    sortBy: 'deleted_at' | 'name' | 'id' = 'deleted_at',
    sortDir: 'asc' | 'desc' = 'desc'
  ): Promise<{ data: ResourceData[]; total: number; per_page: number; current_page: number; last_page: number } | null> {
    try {
      // Bare paginated payload (like the catalogue) → raw passthrough.
      return await tydal.http.get<{ data: ResourceData[]; total: number; per_page: number; current_page: number; last_page: number }>(
        `/resources/trashed`,
        { query: { page, limit, sort_by: sortBy, sort_dir: sortDir }, raw: true },
      )
    } catch (error) {
      console.error('Get trashed resources error:', error)
      return null
    }
  }

  /**
   * Restore all trashed resources for the current organisation
   */
  async restoreAll(): Promise<number | null> {
    try {
      const body = await tydal.http.patch<{ count?: number }>(`/resources/trashed/restore-all`, { raw: true })
      return body?.count ?? 0
    } catch (error) {
      console.error('Restore all error:', error)
      return null
    }
  }

  /**
   * Permanently delete all trashed resources for the current organisation
   */
  async purgeTrash(): Promise<number | null> {
    try {
      const body = await tydal.http.delete<{ count?: number }>(`/resources/trashed/purge`, { raw: true })
      return body?.count ?? 0
    } catch (error) {
      console.error('Purge trash error:', error)
      return null
    }
  }

  /**
   * Restore a soft-deleted resource
   */
  async restoreResource(id: string): Promise<boolean> {
    try {
      await tydal.http.patch(`/resources/${id}/restore`)
      return true
    } catch (error) {
      console.error('Restore resource error:', error)
      return false
    }
  }

  /**
   * Permanently delete a soft-deleted resource
   */
  async forceDeleteResource(id: string): Promise<boolean> {
    try {
      await tydal.http.delete(`/resources/${id}/force`)
      return true
    } catch (error) {
      console.error('Force delete resource error:', error)
      return false
    }
  }

  /**
   * Delete a resource
   */
  async deleteResource(resourceId: string): Promise<boolean> {
    try {
      // SDK throws TydalApiError (an Error with the server .message) on failure.
      await tydal.resources.delete(resourceId)
      return true
    } catch (error) {
      console.error('Delete resource error:', error)
      if (error instanceof Error) throw error
      throw new Error('Failed to delete resource')
    }
  }

  /**
   * Move many resources along the lifecycle at once — the basket's state
   * action. Resolves with a partial-success report; throws only when nothing
   * could be applied.
   */
  async bulkState(resourceIds: string[], state: ResourceState): Promise<BulkActionResult> {
    return tydal.resources.bulkState(resourceIds, state)
  }

  /**
   * Add or remove tags across many resources. Additive/subtractive — unlike
   * semanticTagService.syncResource, which replaces one resource's whole set.
   */
  async bulkSemanticTags(
    resourceIds: string[],
    tagIds: number[],
    mode: 'add' | 'remove',
  ): Promise<BulkActionResult> {
    return tydal.resources.bulkSemanticTags(resourceIds, tagIds, mode)
  }
}

export default new ResourceService()
