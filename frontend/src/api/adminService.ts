/**
 * Admin Service — platform-level CRUD for superadmins.
 * All calls go to /api/v1/platform/* which requires the `superadmin` middleware.
 */

import { tydal } from './tydalClient'
import { TydalApiError } from '@tydal/client'
import { type SchemeField } from './collectionService'

// Platform endpoints live under /platform, relative to the SDK baseUrl.
const PLATFORM = '/platform'

// ─── Shared types ────────────────────────────────────────────────────────────

export interface PaginatedMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface AdminUserOrg {
  id: string
  name: string
  pivot: { role: string }
}

export interface AdminUser {
  id: string
  name: string
  email: string
  is_superadmin: boolean
  is_active: boolean
  organizations_count: number
  organizations?: AdminUserOrg[]
  created_at: string
}

export interface AdminOrganization {
  id: string
  name: string
  slug: string
  type: string
  description?: string
  is_active: boolean
  users_count: number
  collections_count: number
  resources_count: number
  created_at: string
}

export interface AdminWorkspace {
  id: string
  name: string
  slug?: string
}

export type Visibility = 'global' | 'restricted'

/** An organization a scheme or index has been restricted to. */
export interface VisibilityOrg { id: string; name: string }

export interface AdminCollectionScheme {
  id: string
  name: string
  display_name: string
  description?: string
  fields: SchemeField[]
  accepted_mimetypes?: string[]
  is_system: boolean
  /** 'global' = every organization; 'restricted' = only `organizations`. */
  visibility?: Visibility
  organizations?: VisibilityOrg[]
  /** How many collections use it — non-zero means the fields are locked. */
  collections_count?: number
}

export interface AdminSearchIndex {
  id: string
  index_name: string
  display_name: string
  description?: string
  is_active: boolean
  visibility?: Visibility
  organizations?: VisibilityOrg[]
}

export interface AdminCollection {
  id: number
  name: string
  slug: string
  description?: string
  language: string
  is_active: boolean
  resources_count: number
  organization_id: string
  scheme_id?: string | null
  index_id?: string | null
  organization?: { id: string; name: string }
  scheme?: { id: string; name: string; display_name: string }
  created_at: string
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Platform responses are consumed whole (`success`/`data`/`meta`), so all calls
 *  use `raw`. Mutating helpers preserve the "throw the parsed body" contract. */
async function get<T>(path: string): Promise<T> {
  try {
    return await tydal.http.get<T>(`${PLATFORM}${path}`, { raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? new Error(`GET ${path} failed: ${e.status}`) : e
  }
}

async function post<T>(path: string, body: unknown): Promise<T> {
  try {
    return await tydal.http.post<T>(`${PLATFORM}${path}`, { body, raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? (e.body ?? e) : e
  }
}

async function put<T>(path: string, body: unknown): Promise<T> {
  try {
    return await tydal.http.put<T>(`${PLATFORM}${path}`, { body, raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? (e.body ?? e) : e
  }
}

async function del(path: string): Promise<{ success: boolean; message: string }> {
  try {
    return await tydal.http.delete<{ success: boolean; message: string }>(`${PLATFORM}${path}`, { raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? (e.body ?? e) : e
  }
}

function qs(params: Record<string, string | number | undefined>): string {
  const p = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== '') p.set(k, String(v))
  }
  const s = p.toString()
  return s ? `?${s}` : ''
}

// ─── Users ───────────────────────────────────────────────────────────────────

export interface ListUsersParams {
  page?: number
  per_page?: number
  search?: string
}

export interface ListUsersResponse {
  success: boolean
  data: { users: { data: AdminUser[]; total: number; current_page: number; per_page: number; last_page: number } }
}

export interface UserOrgAssignment {
  id: string
  role: string
}

export interface UserFormData {
  name: string
  email: string
  password?: string
  is_active: boolean
  is_superadmin: boolean
  organizations?: UserOrgAssignment[]
}

const users = {
  list: (params: ListUsersParams = {}) =>
    get<ListUsersResponse>(`/users${qs({ page: params.page, per_page: params.per_page, search: params.search })}`),

  show: (id: string) =>
    get<{ success: boolean; data: { user: AdminUser } }>(`/users/${id}`),

  create: (data: UserFormData) =>
    post<{ success: boolean; data: { user: AdminUser } }>('/users', data),

  update: (id: string, data: Partial<UserFormData>) =>
    put<{ success: boolean; data: { user: AdminUser } }>(`/users/${id}`, data),

  delete: (id: string) => del(`/users/${id}`),
}

// ─── Organizations ────────────────────────────────────────────────────────────

export interface ListOrgsParams {
  page?: number
  per_page?: number
  search?: string
}

export interface ListOrgsResponse {
  success: boolean
  data: { organizations: { data: AdminOrganization[]; total: number; current_page: number; per_page: number; last_page: number } }
}

export interface OrgFormData {
  name: string
  type: string
  description?: string
  owner_email?: string   // required only on create
  is_active?: boolean
}

const organizations = {
  list: (params: ListOrgsParams = {}) =>
    get<ListOrgsResponse>(`/organizations${qs({ page: params.page, per_page: params.per_page, search: params.search })}`),

  create: (data: OrgFormData) =>
    post<{ success: boolean; data: { organization: AdminOrganization } }>('/organizations', data),

  update: (id: string, data: Partial<OrgFormData>) =>
    put<{ success: boolean; data: { organization: AdminOrganization } }>(`/organizations/${id}`, data),

  delete: (id: string) => del(`/organizations/${id}`),

  suspend: (id: string) =>
    post<{ success: boolean }>(`/organizations/${id}/suspend`, {}),

  activate: (id: string) =>
    post<{ success: boolean }>(`/organizations/${id}/activate`, {}),

  workspaces: (id: string) =>
    get<{ success: boolean; data: { workspaces: AdminWorkspace[] } }>(`/organizations/${id}/workspaces`),
}

// ─── Collections ─────────────────────────────────────────────────────────────

export interface ListCollectionsParams {
  page?: number
  per_page?: number
  search?: string
  organization_id?: string
}

export interface ListCollectionsResponse {
  success: boolean
  data: { collections: { data: AdminCollection[]; total: number; current_page: number; per_page: number; last_page: number } }
}

export interface CollectionFormData {
  organization_id: string
  name: string
  description?: string
  language: string
  is_active: boolean
  scheme_id?: string | null
  index_id?: string | null
}

export interface CollectionDuplicateData {
  name: string
  organization_id: string
}

const collections = {
  list: (params: ListCollectionsParams = {}) =>
    get<ListCollectionsResponse>(`/collections${qs({ page: params.page, per_page: params.per_page, search: params.search, organization_id: params.organization_id })}`),

  create: (data: CollectionFormData) =>
    post<{ success: boolean; data: { collection: AdminCollection } }>('/collections', data),

  update: (id: number, data: Partial<CollectionFormData>) =>
    put<{ success: boolean; data: { collection: AdminCollection } }>(`/collections/${id}`, data),

  delete: (id: number) => del(`/collections/${id}`),

  duplicate: (id: number, data: CollectionDuplicateData) =>
    post<{ success: boolean; data: { collection: AdminCollection } }>(`/collections/${id}/duplicate`, data),
}

// ─── Collection schemes and search indexes ───────────────────────────────────
// Reads are open to any authenticated user (scoped to what their organization
// is offered); writes are superadmin-only. See routes/api/v1/schema.php.

const collectionSchemes = {
  /**
   * `forOrganization` asks for the current organization's own menu even when
   * the caller is a platform administrator — what the organization settings
   * page needs, since that screen is about the organization, not the viewer.
   */
  list: async (systemOnly?: boolean, forOrganization = false) => {
    try {
      const params = new URLSearchParams()
      if (systemOnly !== undefined) params.set('system', String(systemOnly))
      if (forOrganization) params.set('for_organization', '1')
      const qs = params.toString()
      return await tydal.http.get<{ success: boolean; data: { schemes: AdminCollectionScheme[] } }>(
        `/collection-schemes${qs ? `?${qs}` : ''}`,
        { raw: true },
      )
    } catch (e) {
      throw e instanceof TydalApiError ? (e.body ?? e) : e
    }
  },

  update: (id: string, payload: Partial<AdminCollectionScheme> & { organization_ids?: string[] }) =>
    tydal.http.put<{ success: boolean; data: { scheme: AdminCollectionScheme } }>(
      `/collection-schemes/${id}`, { body: payload as Record<string, unknown>, raw: true },
    ),

  /** Copy a scheme into one organization so it can be varied independently. */
  clone: (id: string, payload: { organization_id: string; name?: string; display_name?: string }) =>
    tydal.http.post<{ success: boolean; data: { scheme: AdminCollectionScheme } }>(
      `/collection-schemes/${id}/clone`, { body: payload, raw: true },
    ),

  remove: (id: string) => tydal.http.delete(`/collection-schemes/${id}`),
}

const searchIndexes = {
  list: () => tydal.http.get<{ success: boolean; data: { indexes: AdminSearchIndex[] } }>(
    '/search-indexes', { raw: true },
  ),

  update: (id: string, payload: Partial<AdminSearchIndex> & { organization_ids?: string[] }) =>
    tydal.http.put<{ success: boolean; data: { index: AdminSearchIndex } }>(
      `/search-indexes/${id}`, { body: payload as Record<string, unknown>, raw: true },
    ),
}

// ─── Platform stats ───────────────────────────────────────────────────────────

const stats = {
  get: () => get<{ success: boolean; data: { stats: Record<string, Record<string, number>> } }>('/stats'),
}

// ─── AI Services test ────────────────────────────────────────────────────────

export interface AiParam {
  label: string
  value: string
  hint: string
}

export interface AiServiceInfo {
  driver: string
  model: string
  params?: AiParam[]
}

export interface AiConfig {
  embedding: AiServiceInfo
  chat: AiServiceInfo
  vision: AiServiceInfo
}

export interface AiTestResult {
  ok: boolean
  detail: string
  duration_ms: number
}

const ai = {
  getConfig: () =>
    get<{ success: boolean; data: AiConfig }>('/ai/config'),

  ollamaModels: () =>
    get<{ success: boolean; data: { models: string[] } }>('/ai/ollama/models'),

  testEmbedding: () =>
    post<{ success: boolean; data: AiTestResult }>('/ai/test/embedding', {}),

  testChat: () =>
    post<{ success: boolean; data: AiTestResult }>('/ai/test/chat', {}),

  testVision: () =>
    post<{ success: boolean; data: AiTestResult }>('/ai/test/vision', {}),
}

export default { users, organizations, collections, collectionSchemes, searchIndexes, stats, ai }
