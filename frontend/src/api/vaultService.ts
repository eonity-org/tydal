/**
 * Vault Service — manages Vault configurations, workspace associations,
 * and resource link generation.
 */

import { tydal } from './tydalClient'
import { TydalApiError } from '@tydal/client'

// Paths are relative to the SDK's configured baseUrl (single transport, Epic 5.0).
const PLATFORM_BASE = '/platform/vaults'
const API_BASE      = ''

// ─── Types ────────────────────────────────────────────────────────────────────

export type VaultPurpose = 'delivery' | 'gallery' | 'obsidian' | 'ai' | 'mixed'

export const VAULT_PURPOSES: VaultPurpose[] = ['delivery', 'gallery', 'obsidian', 'ai', 'mixed']

/**
 * The capability matrix (VAULT_SYSTEM.md §6.3). Purpose is a *preset* over
 * these knobs; a vault's `exposure_policy` overrides any of them. The
 * vocabulary and every preset are served by the backend
 * (`GET /platform/vaults/capabilities`) so the defaults are never restated here.
 */
export type VaultCapabilityKey =
  | 'allow_chunks' | 'allow_binary' | 'allow_ask'
  | 'address_roles' | 'chunk_roles'
  | 'write_methods' | 'ingest'
  | 'rag_min_score'

export type VaultCapabilityGroup = 'read_tiers' | 'projection' | 'write' | 'retrieval'

export interface VaultCapabilityDef {
  key: VaultCapabilityKey
  label: string
  group: VaultCapabilityGroup
}

/** A vault's overrides. A key that is absent (or null) falls back to the preset. */
export type VaultExposurePolicy = Partial<Record<VaultCapabilityKey, unknown>>

export interface VaultPresetEntry {
  label: string
  values: Record<VaultCapabilityKey, unknown>
}

export interface VaultCapabilitiesResponse {
  success: boolean
  data: {
    capabilities: VaultCapabilityDef[]
    presets: Record<VaultPurpose, VaultPresetEntry>
  }
}

/** How open a vault's boundary is: off / credential-required / address-alone. */
export type VaultState = 'disabled' | 'private' | 'public'

export const VAULT_STATES: VaultState[] = ['disabled', 'private', 'public']

export interface VaultConfig {
  id: string
  organization_id: string
  name: string
  slug: string
  hash: string
  description?: string
  purpose: VaultPurpose
  state: VaultState
  /** Present on the platform listing (superadmin) for cross-org display. */
  organization?: { id: string; name: string; slug: string }
  salt?: string
  has_public_workspace: boolean
  is_downloadable: boolean
  hash_ttl_hours: number | null
  allowed_ips: string[] | null
  exposure_policy: VaultExposurePolicy | null
  base_url: string | null
  is_active: boolean
  created_at: string
}

export interface VaultFormData {
  /** Optional on create — the backend defaults it to the caller's current
   *  organization (the one selected in the header). Immutable after creation. */
  organization_id?: string
  name: string
  slug: string
  description?: string
  purpose: VaultPurpose
  state: VaultState
  salt?: string
  has_public_workspace: boolean
  is_downloadable: boolean
  hash_ttl_hours: number | null
  allowed_ips: string[]
  /** Capability overrides; omit or null to run entirely on the purpose preset. */
  exposure_policy?: VaultExposurePolicy | null
  base_url?: string
}

export interface VaultLinkEntry {
  id: number
  vault_id: string
  vault_name: string
  vault_purpose?: VaultPurpose
  workspace_id: number | null   // null = vault-purpose link (workspace-free address)
  workspace_name: string | null
  hash: string
  url: string           // direct inline serve URL
  info_url: string      // JSON metadata endpoint
  download_url: string | null  // null when Vault has is_downloadable = false
  expires_at: string | null
  is_expired: boolean
}

export interface ResourceVaultLinks {
  resource: {
    id: string
    name: string
    links: VaultLinkEntry[]
  }
  files: Array<{
    id: string
    filename: string
    mime_type: string
    size: number
    links: VaultLinkEntry[]
  }>
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

/** Vault responses are consumed whole (`success`/`data`/`meta`/`message`), so all
 *  calls use `raw`. The mutating helpers preserve the old "throw the parsed
 *  body" contract; `get` keeps throwing an Error. */
async function get<T>(path: string): Promise<T> {
  try {
    return await tydal.http.get<T>(path, { raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? new Error(`GET ${path} failed: ${e.status}`) : e
  }
}

async function post<T>(path: string, body: unknown): Promise<T> {
  try {
    return await tydal.http.post<T>(path, { body, raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? (e.body ?? e) : e
  }
}

async function put<T>(path: string, body: unknown): Promise<T> {
  try {
    return await tydal.http.put<T>(path, { body, raw: true })
  } catch (e) {
    throw e instanceof TydalApiError ? (e.body ?? e) : e
  }
}

async function del(path: string): Promise<{ success: boolean; message: string }> {
  try {
    return await tydal.http.delete<{ success: boolean; message: string }>(path, { raw: true })
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

// ─── Active Vaults (any authenticated user) ────────────────────────────────────

const active = {
  list: () =>
    get<{ success: boolean; data: { vaults: VaultConfig[] } }>(`${API_BASE}/vaults`),
}

// ─── Platform Vault CRUD (superadmin only) ──────────────────────────────────────

export interface ListVaultsParams {
  page?: number
  per_page?: number
  search?: string
}

export interface ListVaultsResponse {
  success: boolean
  data: { vaults: VaultConfig[] }
  meta: { pagination: { current_page: number; per_page: number; total: number; has_more: boolean } }
}

export interface VaultKeyEntry {
  id: string
  name: string
  key_prefix: string
  abilities?: string[]
  last_used_at: string | null
  revoked_at: string | null
  created_at: string
}

// Fallback only — the authoritative list is the `write_methods` capability
// served by GET /platform/vaults/capabilities (VaultPurpose::writeMethods on
// the backend, VAULT_WRITE_METHODS.md §3). Kept so the key minter still offers
// sane options if that request fails; it must not be the source of truth,
// because a table restated here is a table free to drift.
export const VAULT_WRITE_METHODS: Record<VaultPurpose, string[]> = {
  delivery: [],
  gallery: ['activate', 'open', 'close', 'ingest', 'update', 'withdraw'],
  obsidian: [],
  ai: ['ingest', 'update', 'withdraw'],
  mixed: [],
}

/**
 * The abilities a key on this vault may hold: `read`, plus a `w:{method}` per
 * write method the vault actually accepts. Pass the vault's *effective* write
 * methods (preset ⊕ its own override) when they are known — a vault that
 * widened `write_methods` can mint keys for the methods it added.
 */
export function keyAbilityOptions(purpose: VaultPurpose, effective?: string[]): string[] {
  const methods = effective ?? VAULT_WRITE_METHODS[purpose]
  return ['read', ...methods.map((m) => `w:${m}`)]
}

export interface SignedUrlGrant {
  url: string
  sig: string
  exp: number
  expires_at: string
  scope: 'vault' | 'link'
}

const admin = {
  list: (params: ListVaultsParams = {}) =>
    get<ListVaultsResponse>(`${PLATFORM_BASE}${qs({ page: params.page, per_page: params.per_page, search: params.search })}`),

  /** The capability vocabulary and every purpose's preset — see VaultCapabilityDef. */
  capabilities: () =>
    get<VaultCapabilitiesResponse>(`${PLATFORM_BASE}/capabilities`),

  create: (data: VaultFormData) =>
    post<{ success: boolean; data: { vault: VaultConfig } }>(PLATFORM_BASE, data),

  update: (id: string, data: Partial<VaultFormData>) =>
    put<{ success: boolean; data: { vault: VaultConfig }; message: string }>(`${PLATFORM_BASE}/${id}`, data),

  delete: (id: string) =>
    del(`${PLATFORM_BASE}/${id}`),

  // Vault keys — private-vault access credentials (plaintext returned once on mint)
  listKeys: (vaultId: string) =>
    get<{ success: boolean; data: { keys: VaultKeyEntry[] } }>(`${PLATFORM_BASE}/${vaultId}/keys`),

  createKey: (vaultId: string, name: string, abilities: string[] = ['read']) =>
    post<{ success: boolean; data: { key: VaultKeyEntry; plaintext: string } }>(`${PLATFORM_BASE}/${vaultId}/keys`, { name, abilities }),

  revokeKey: (vaultId: string, keyId: string) =>
    del(`${PLATFORM_BASE}/${vaultId}/keys/${keyId}`),

  // Signed URLs — time-limited publish grants (Epic 5.4). Stateless (HMAC on
  // the vault salt + grant_epoch): nothing to list or revoke individually.
  mintSignedUrl: (vaultId: string, expiresInHours: number, linkHash?: string) =>
    post<{ success: boolean; data: SignedUrlGrant }>(
      `${PLATFORM_BASE}/${vaultId}/signed-urls`,
      { expires_in_hours: expiresInHours, ...(linkHash ? { link_hash: linkHash } : {}) },
    ),

  // Revoke ALL outstanding grants by bumping grant_epoch — links are untouched
  // (unlike a salt rotation, which also purges every link in the vault).
  revokeGrants: (vaultId: string) =>
    post<{ success: boolean; data: { grant_epoch: number }; message: string }>(
      `${PLATFORM_BASE}/${vaultId}/revoke-grants`,
      {},
    ),

  // Rotate the salt — fresh server-generated secret + purge every link. The
  // nuclear reset: all shared URLs and grants stop working.
  rotateSalt: (vaultId: string) =>
    post<{ success: boolean; data: { links_purged: number }; message: string }>(
      `${PLATFORM_BASE}/${vaultId}/rotate-salt`,
      {},
    ),
}

/** Web root of the TYDAL instance — public vault URLs (/v, /h) live there.
 *  (URL math on the configured API base, not a transport path.) */
export { API_ORIGIN as PUBLIC_BASE } from '../constants/api'

// ─── Workspace Vault association ────────────────────────────────────────────────

const workspace = {
  listVaults: (workspaceId: string | number) =>
    get<{ success: boolean; data: { vaults: VaultConfig[] } }>(`${API_BASE}/workspaces/${workspaceId}/vaults`),

  attach: (workspaceId: string | number, vaultId: string) =>
    post<{ success: boolean; message: string }>(`${API_BASE}/workspaces/${workspaceId}/vaults`, { vault_id: vaultId }),

  detach: (workspaceId: string | number, vaultId: string) =>
    del(`${API_BASE}/workspaces/${workspaceId}/vaults/${vaultId}`),
}

// ─── Resource Vault links (lazy generation) ────────────────────────────────────

const links = {
  forResource: (resourceId: string) =>
    get<{ success: boolean; data: ResourceVaultLinks }>(`${API_BASE}/resources/${resourceId}/vault-links`),
}

export default { active, admin, workspace, links }
