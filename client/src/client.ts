/**
 * The client factory. Composes the HTTP core with feature namespaces. Grows one
 * namespace at a time toward the full surface (auth, organizations, collections,
 * workspaces, resources, semanticTags, notifications, vaults, aity, platform).
 */
import { createHttp, type HttpConfig, type Http } from './http.js'
import { resourcesNamespace } from './resources.js'
import { collectionsNamespace } from './collections.js'
import { workspacesNamespace } from './workspaces.js'
import { notificationsNamespace } from './notifications.js'
import { organizationsNamespace } from './organizations.js'
import { semanticTagsNamespace } from './semantic-tags.js'
import { authNamespace } from './auth-endpoints.js'

export type TydalClientConfig = HttpConfig

export interface TydalClient {
  /** Escape hatch for endpoints without a namespace yet. */
  http: Http
  auth: ReturnType<typeof authNamespace>
  resources: ReturnType<typeof resourcesNamespace>
  collections: ReturnType<typeof collectionsNamespace>
  workspaces: ReturnType<typeof workspacesNamespace>
  notifications: ReturnType<typeof notificationsNamespace>
  organizations: ReturnType<typeof organizationsNamespace>
  semanticTags: ReturnType<typeof semanticTagsNamespace>
}

export function createTydalClient(config: TydalClientConfig): TydalClient {
  const http = createHttp(config)
  return {
    http,
    auth: authNamespace(http),
    resources: resourcesNamespace(http),
    collections: collectionsNamespace(http),
    workspaces: workspacesNamespace(http),
    notifications: notificationsNamespace(http),
    organizations: organizationsNamespace(http),
    semanticTags: semanticTagsNamespace(http),
  }
}
