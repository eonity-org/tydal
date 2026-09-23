/**
 * @tydal/client — shared transport for every TYDAL surface.
 *
 *   import { createTydalClient, staticToken } from '@tydal/client'
 *
 *   const tydal = createTydalClient({
 *     baseUrl: 'https://api.example.com/api/v1',
 *     auth: staticToken(process.env.TYDAL_TOKEN!),
 *     orgId: process.env.TYDAL_ORG_ID,
 *   })
 *
 *   const resource = await tydal.resources.get(id)
 */
export { createTydalClient } from './client.js'
export type { TydalClient, TydalClientConfig } from './client.js'

export { staticToken, noAuth } from './auth.js'
export type { TokenProvider } from './auth.js'

export { TydalApiError } from './error.js'

export { createHttp } from './http.js'
export type { Http, HttpConfig, RequestOptions, QueryValue, UploadOptions } from './http.js'

export { createVaultConsumer } from './vault.js'
export type {
  VaultConsumer,
  VaultConsumerConfig,
  VaultAddress,
  VaultMeta,
  VaultCapability,
  VaultPresentationBlock,
  VaultResourceCard,
  VaultIndexPage,
  VaultSearchResult,
  VaultChunkHit,
  VaultTags,
  VaultFileEntry,
  VaultRelated,
  VaultEmbedding,
  VaultGraph,
  VaultGraphEdge,
  VaultAskResult,
  VaultAskSource,
  VaultResourceOps,
  VaultPagination,
  VaultWriteResult,
} from './vault.js'

export type { AuthNamespace } from './auth-endpoints.js'
export type {
  CatalogueParams,
  ResourceListParams,
  ResourceStateValue,
  ResourcesNamespace,
} from './resources.js'
export type { CollectionsNamespace } from './collections.js'
export type { WorkspaceCatalogueParams, WorkspacesNamespace } from './workspaces.js'
export type { NotificationsNamespace } from './notifications.js'
export type { OrganizationsNamespace } from './organizations.js'
export type { SemanticTagListParams, SemanticTagsNamespace } from './semantic-tags.js'
export type { ApiEnvelope, BulkActionResult, PaginatedData } from './types.js'
