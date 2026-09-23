/** Shared TypeScript types matching TYDAL API response shapes. */

export interface Workspace {
  id: string | number;
  name: string;
  slug?: string;
  description?: string;
  is_active?: boolean;
  is_default?: boolean;
  organization_id?: string | number;
}

export interface Resource {
  id: string;
  name: string;
  description?: string;
  type?: string;
  state?: 'draft' | 'live' | 'archived';
  metadata?: Record<string, unknown>;
  language?: string;
  collection_id?: number;
  organization_id?: string;
  updated_at?: string;
  created_at?: string;
}

export interface ResourceFile {
  id: string;
  name?: string;
  mime_type?: string;
  role?: string;
  relation?: string;
  usage?: string[];
}

/** Shape returned by GET /resources/{id}/agent-view (get_resource tool). */
export interface AgentResourceView {
  id: string;
  slug: string | null;
  name: string;
  description: string | null;
  type: string;
  state: string;
  tags: SemanticTag[];
  metadata: Record<string, unknown> | null;
  collection: { id: number; name: string } | null;
  files: AgentResourceFile[];
  vault_links: VaultLinkEntry[];
  content: { has_chunks: boolean; chunk_count: number };
  workspaces: Array<{ id: string | number; name: string }>;
  created_at: string | null;
  updated_at: string | null;
}

export interface AgentResourceFile {
  id: string;
  filename: string;
  mime_type: string;
  role: string;
  relation: string | null;
  size: number;
  url: string;
  conversion_urls?: Record<string, string>;
  vault_links: VaultLinkEntry[];
  content: { has_chunks: boolean; chunk_count: number; embedded: boolean };
}

/** A single chunk from GET /resources/{id}/chunks (list_resource_chunks tool). */
export interface ResourceChunk {
  chunk_id: string;
  file_id: string;
  sequence: number;
  page_number: number | null;
  content: string;
}

export interface VaultLinkEntry {
  id: string;
  url: string;
  download_url?: string;
  vault_name: string;
  workspace_name?: string;
  is_expired?: boolean;
}

export interface VaultLinksResponse {
  resource: { links: VaultLinkEntry[] };
  files: Array<{ file_id: string; links: VaultLinkEntry[] }>;
}

export interface RagSource {
  chunk_id: string;
  resource_id: string;
  resource_name: string;
  file_id: string;
  page_number: number | null;
}

export interface AskResult {
  answer: string;
  sources: RagSource[];
}

export interface CatalogueResponse {
  data: Resource[];
  facets: Facet[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export interface Facet {
  key: string;
  label: string;
  values: Array<{ value: string; count: number }>;
}

export interface SemanticTag {
  id: number;
  label: string;
}
