/**
 * Collection Service for Tydal Frontend
 *
 * Handles fetching collections.
 * Aligned with Sprint A data model: collection_schemes + search_indexes.
 */

import { tydal } from './tydalClient'

// ─── Scheme field definition (matches §4 of architecture doc) ────────────────

export interface SchemeField {
  name: string
  display_name: string
  type: 'string' | 'text' | 'select' | 'integer' | 'boolean' | 'array' | 'email' | 'url' | 'date'
  required: boolean
  /** 'column' = direct resources column, 'metadata' = resources.metadata JSON, 'index_only' = ES only */
  storage: 'column' | 'metadata' | 'index_only'
  is_facet: boolean
  facet_label?: string
  facet_order?: number
  display_in_form: boolean
  order: number
  validators?: {
    min_length?: number
    max_length?: number
    min_value?: number
    max_value?: number
    in?: string[]
  } | null
  es_type?: string
  es_fields?: Record<string, unknown>
}

// ─── Collection Scheme ───────────────────────────────────────────────────────

export interface CollectionScheme {
  id: string
  name: string
  display_name: string
  description?: string | null
  fields: SchemeField[]
  accepted_mimetypes?: string[] | null
  is_system: boolean
  created_at?: string
  updated_at?: string
}

// ─── Collection ──────────────────────────────────────────────────────────────

export interface Collection {
  id: string | number
  name: string
  slug?: string
  description?: string
  coll_resource_count?: number
  resource_count?: number
  scheme_id?: string | null
  index_id?: string | null
  scheme?: CollectionScheme | null
  organization_id?: string | number
  user_owner_id?: string | number
  is_active?: boolean
  created_at?: string
  updated_at?: string
}

/**
 * Return the scheme's fields array for a collection, or null if none assigned.
 * Replaces the old getEffectiveSchema() / custom_schema logic.
 */
export function getSchemeFields(collection: Collection | null | undefined): SchemeField[] | null {
  return collection?.scheme?.fields ?? null
}

export interface CollectionsResponse {
  data: {
    collections: Collection[]
  }
}

class CollectionService {
  /**
   * Get all collections for the current user/organization
   */
  async getCollections(): Promise<Collection[] | null> {
    try {
      // SDK unwraps the envelope → { collections }.
      const body = await tydal.collections.list<{ collections?: Collection[] }>()
      return body?.collections ?? null
    } catch (error) {
      console.error('Get collections error:', error)
      return null
    }
  }

  /**
   * Get a single collection by ID
   */
  async getCollection(collectionId: string | number): Promise<Collection | null> {
    try {
      // SDK unwraps → { collection } (or the data object itself).
      const data = await tydal.collections.get<{ collection?: Collection } & Record<string, unknown>>(collectionId)
      return (data?.collection ?? data ?? null) as Collection | null
    } catch (error) {
      console.error('Get collection error:', error)
      return null
    }
  }

  /**
   * Create a collection in the current organization.
   *
   * Throws TydalApiError so the caller can show why it was refused — the quota
   * message and the "scheme not available to this organization" message both
   * matter to the person clicking the button.
   */
  async createCollection(payload: {
    name: string
    description?: string
    language?: string
    scheme_id: string
  }): Promise<Collection> {
    const body = await tydal.collections.create<{ collection: Collection }>(payload)
    return body.collection
  }
}

export default new CollectionService()
