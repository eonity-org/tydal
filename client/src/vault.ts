/**
 * Vault consumer (Epic 5.1) — the CLIENT side of the vault boundary.
 *
 * A vault is TYDAL's external, tier-gated projection (VAULT_SYSTEM.md): this
 * namespace speaks the public `/v/{org}/{vault}` (human) or `/h/{hash}`
 * (machine) operation grammar — web-root routes, NOT the authenticated
 * `/api/v1` surface. Auth is the vault's own: published vaults are keyless,
 * private vaults take a vault key (`X-Vault-Key`). Responses are bare JSON
 * payloads (no `success` envelope), so nothing is unwrapped.
 *
 *   const vault = createVaultConsumer({
 *     baseUrl: 'https://tydal.example.com',       // web root
 *     vault: { org: 'acme', slug: 'expo' },       // or { hash: 'AbC123XyZ012' }
 *     key: 'tvk_…',                               // private vaults only
 *   })
 *   const meta = await vault.meta()               // presentation, tiers, operations
 *   const page = await vault.resources({ page: 1 })
 */
import { createHttp, type Http, type QueryValue } from './http.js'
import { noAuth } from './auth.js'

// ─── Config ──────────────────────────────────────────────────────────────────

export type VaultAddress = { org: string; slug: string } | { hash: string }

export interface VaultConsumerConfig {
  /** Web root of the TYDAL instance (no `/api/v1`), e.g. `https://tydal.example.com`. */
  baseUrl: string
  /** Which vault this consumer is bound to — slugs (human) or hash (machine). */
  vault: VaultAddress
  /** Vault key for private vaults — sent as `X-Vault-Key`. */
  key?: string
  /**
   * Signed grant for private vaults (Epic 5.4) — the `sig`/`exp` pair from a
   * minted signed URL, appended to every request. Time-limited; dies at
   * expiry or on vault salt rotation.
   */
  grant?: { sig: string; exp: number }
  /** Inject a fetch implementation (defaults to the global). */
  fetch?: typeof fetch
}

// ─── Payload types (the grammar's own shapes — see VAULT_SYSTEM.md §5) ───────

/** Tier 0 identity card — what listings and search results are made of. */
export interface VaultResourceCard {
  /**
   * Vault-scoped opaque id (the link hash, as in `/h/{vaultHash}/{id}`). Stable
   * within this vault and safe to use as a key or to match graph edges; it is
   * NOT the internal resource id and never crosses the vault boundary.
   */
  id: string
  name: string
  slug: string | null
  description: string | null
  resource_type: string | null
  tags: string[]
  url: string
  path: string
  /**
   * Vault-scoped URL of the resource's designated face (snapshot file or
   * rendered preview) — null when the resource has none. Renderers should
   * prefer this over probing `url`/`files` for an image.
   */
  preview?: string | null
  /** Available versions of the designated preview; URLs retain the vault view gate. */
  preview_renditions?: Array<{ name: string; url: string; on_demand?: boolean; max_bytes?: number }>
  /** Present on ES-served search hits. */
  metadata?: Record<string, unknown>
  score?: number
  [key: string]: unknown
}

export interface VaultPresentationBlock {
  scheme: string
  scheme_name: string
  /** Core slots served by resource built-ins, e.g. `{ caption: 'name', image: 'snapshot' }`. */
  core: Record<string, string>
  /** Scheme fields resolved to slots, e.g. `{ technique: 'badge' }`. */
  fields: Record<string, string>
}

/**
 * One knob of the vault's exposure policy, with its provenance: `preset` means
 * the value comes from the vault's purpose, `override` means this vault set it
 * itself (VAULT_SYSTEM.md §6.3).
 */
export interface VaultCapability {
  value: unknown
  source: 'preset' | 'override'
  /**
   * Present on gated capabilities (`allow_chunks`, `allow_binary`,
   * `allow_ask`): how open this one knob is, independently of the vault's
   * `state`. `inherit` follows the vault, `key` requires a vault key even when
   * the vault is public, `denied` never answers. Lets a vault list publicly
   * while keeping its binaries behind a credential.
   */
  level?: 'denied' | 'key' | 'inherit'
}

export interface VaultMeta {
  type: 'vault'
  name: string
  slug: string
  hash: string
  description: string | null
  purpose: string
  organization: string
  /** `disabled` | `private` (credential required) | `public` (address alone). */
  state: 'disabled' | 'private' | 'public'
  resource_count: number
  tiers: { identity: boolean; chunks: boolean; binary: boolean; ask?: boolean }
  address_roles: string[]
  chunk_roles: string[]
  /**
   * The full capability matrix keyed by capability name (`allow_binary`,
   * `chunk_roles`, `write_methods`, …), each with the effective value and
   * whether it came from the purpose preset or this vault's own override.
   *
   * Optional: older backends omit it. `tiers` above stays the stable contract
   * for the read gates — prefer it for "may I?" checks, and read this when you
   * need to explain *why* a tier answers the way it does.
   */
  capabilities?: Record<string, VaultCapability>
  presentation: VaultPresentationBlock[]
  operations: { vault: string[]; resource: string[]; file: string[] }
  search_modes: string[]
}

export interface VaultPagination {
  page: number
  per_page: number
  total: number
  has_more: boolean
}

export interface VaultIndexPage {
  type: 'vault-index'
  vault: { name: string; slug: string; purpose: string }
  resources: VaultResourceCard[]
  pagination: VaultPagination
}

export interface VaultSearchResult {
  type: 'vault-search'
  query: string
  mode: 'keyword' | 'semantic'
  results: VaultResourceCard[]
  /** field → value → count. Present when the per-vault index answered. */
  facets?: Record<string, Record<string, number>>
  pagination: VaultPagination
}

export interface VaultChunkHit {
  resource_id: string
  resource_name: string | null
  file_id: string
  sequence: number | null
  page_number: number | null
  content: string
  score: number | null
}

export interface VaultTags {
  type: 'vault-tags'
  tags: Array<{ name: string; count: number }>
}

export interface VaultFileEntry {
  position: number | null
  filename: string
  slug: string | null
  mime_type: string
  size: number
  role: string
  /** Binary URL — null when the vault denies Tier 2. */
  url: string | null
}

export interface VaultRelated {
  type: 'resource-related'
  source: 'graph' | 'tags'
  resources: Array<VaultResourceCard & {
    relation?: { type: string; origin: string; weight: number | null }
  }>
}

export interface VaultEmbedding {
  type: 'embedding'
  model: string
  dimensions: number
  vector: number[]
}

export interface VaultAskSource {
  /** Vault-scoped opaque id (link hash) — matches `VaultResourceCard.id`. */
  resource_id: string
  resource_name: string
  slug: string | null
  url: string | null
  pages: number[]
}

export interface VaultAskResult {
  answer: string
  sources: VaultAskSource[]
  vault: { slug: string | null; name: string; purpose: string }
  used: { chunks: number; cards: number; related: number }
  context_truncated?: boolean
}

export interface VaultGraphEdge {
  /** Vault-scoped ids (link hashes) — match `VaultResourceCard.id` on the nodes. */
  source: string
  target: string
  type: string
  origin: string
  weight: number | null
}

export interface VaultGraph {
  type: 'vault-graph'
  nodes: VaultResourceCard[]
  edges: VaultGraphEdge[]
  /** True when the node cap cut the projection — edges beyond it are dropped too. */
  truncated: boolean
}

/**
 * Result of a successful boundary write (VAULT_WRITE_METHODS.md §5): `ok` is
 * true and `result` carries the method's summary (e.g. `{ activated }`).
 * Refusals (bad key/ability, unknown method, malformed body, hidden vault) come
 * back as 4xx and therefore throw `TydalApiError` — the same shape the read
 * grammar uses — with the boundary's `{ ok: false, error }` body attached.
 */
export interface VaultWriteResult {
  ok: boolean
  result?: unknown
  error?: string
}

// ─── Consumer ────────────────────────────────────────────────────────────────

export interface VaultResourceOps {
  /** Resource identity + metadata (`/meta`). */
  meta(): Promise<VaultResourceCard & { metadata: Record<string, unknown> | null; file_count: number; chunks_available: boolean }>
  /** Semantic tags + categories (`/tags`). */
  tags(): Promise<{ type: string; tags: string[]; categories: string[] }>
  /** Ordered exposed files (`/files`). */
  files(): Promise<{ type: string; files: VaultFileEntry[] }>
  /** Related resources (`/related`) — graph edges first, always vault-projected. */
  related(): Promise<VaultRelated>
  /** Tier 1 chunk content (`/chunks`); 403 when the vault denies chunks. */
  chunks(params?: { from?: number; to?: number }): Promise<{ type: string; items: Array<Record<string, unknown>> }>
}

export interface VaultConsumer {
  /** Self-description: purpose, tiers, presentation, operations (`/meta`). */
  meta(): Promise<VaultMeta>
  /** Tier 0 listing (`/resources`), navigable by tag/category. */
  resources(params?: { page?: number; perPage?: number; tag?: string; category?: string }): Promise<VaultIndexPage>
  /** Aggregated tags across the vault (`/tags`). */
  tags(): Promise<VaultTags>
  /**
   * Search the identity surface (`/search`). `mode: 'semantic'` ranks by
   * meaning; `facets` narrows to exact metadata values (`facet[field]=value`).
   */
  search(params: {
    q: string
    mode?: 'keyword' | 'semantic'
    page?: number
    facets?: Record<string, string[]>
  }): Promise<VaultSearchResult>
  /** Tier 1 passage search (`/search?scope=chunks`). */
  searchChunks(params: { q: string; mode?: 'keyword' | 'semantic'; limit?: number }): Promise<{ type: string; query: string; mode: string; results: VaultChunkHit[] }>
  /** Embed a query into the vault's vector space (`/embed`). */
  embed(text: string): Promise<VaultEmbedding>
  /** The projected graph (`/graph`): node cards + both-ends-projected edges. */
  graph(params?: { nodes?: number }): Promise<VaultGraph>
  /**
   * Ask AITY at the boundary (`POST /ask`) — answered only when the vault's
   * ask policy allows (see `meta().tiers.ask`); 403 otherwise. Answers are
   * grounded in the vault's own projection, with resource-level citations.
   */
  ask(params: { question: string; k?: number; signal?: AbortSignal }): Promise<VaultAskResult>
  /**
   * Streaming ask (`POST /ask` with `Accept: text/event-stream`): `onToken`
   * fires for each answer fragment as it arrives; the promise resolves with
   * the final result (answer + sources). Falls back to whole-answer chunks
   * on backends that can't stream, so it always resolves the same shape.
   */
  askStream(params: {
    question: string
    k?: number
    onToken: (text: string) => void
    signal?: AbortSignal
  }): Promise<VaultAskResult>
  /** Operations on one resource, addressed by its per-vault slug (or link hash on `/h`). */
  resource(slug: string): VaultResourceOps
  /**
   * Write at the boundary (`POST /w/{method}`) — the inbound counterpart to the
   * read grammar, authorized by a write-capable vault key (`config.key`).
   *
   * The **op** (`method`) is the unit of permission (`w:{op}`), audit, and
   * discovery (`writeCapabilities()`); its **payload is a declarative document**
   * the vault's purpose maps (VAULT_WRITE_METHODS.md). This is the *only* write
   * surface — behaviors grow server-side, not as SDK namespaces. Pass a plain
   * object for a JSON document, or one with a `Blob`/`File` value (e.g.
   * `ingest`'s image) to send multipart automatically:
   *
   * ```ts
   * await vault.write('activate', { resources: hashes })
   * await vault.write('ingest', { descriptor, image })   // image: Blob/File → multipart
   * ```
   *
   * A refused op throws `TydalApiError` (4xx); success resolves `{ ok, result }`.
   */
  write(method: string, body?: unknown): Promise<VaultWriteResult>
  /**
   * Non-destructive write-auth probe (`GET /w`) — reports the write methods the
   * configured key may invoke here, performing none. Use it to validate a write
   * key at bind/config time before an operation depends on it. A key that
   * cannot write (missing/read-only) throws `TydalApiError` (403), a hidden
   * vault 404s — the same opacity a real write has.
   */
  writeCapabilities(): Promise<{ ok: boolean; methods: string[] }>
  /**
   * @deprecated Per-purpose sugar is retired in favor of the generic
   * `write(op, payload)` — `write('activate', { resources })` / `write('open')` /
   * `write('close')`. Kept as thin shims so existing callers keep working; new
   * code should not add namespaces like this.
   */
  readonly gallery: {
    /** Project exactly these works (by link hash); the rest stop resolving. */
    activate(linkHashes: string[]): Promise<VaultWriteResult>
    /** Publish: private → public. */
    open(): Promise<VaultWriteResult>
    /** Un-publish and restore the full pre-selection projection. */
    close(): Promise<VaultWriteResult>
  }
  /** The consumer's base path — `/v/{org}/{slug}` or `/h/{hash}`. */
  readonly basePath: string
}

/**
 * Build the request body for a write op. A plain document is sent as JSON; a
 * document carrying a binary (any Blob/File value — e.g. `ingest`'s image) is
 * sent as multipart, with non-file object values JSON-encoded per field so the
 * server validates them as it would a JSON body (VAULT_WRITE_METHODS.md §7).
 */
function toWriteBody(body: unknown): unknown {
  if (body === undefined || body === null) return {}
  if (typeof FormData !== 'undefined' && body instanceof FormData) return body

  const hasBlob =
    typeof Blob !== 'undefined' &&
    typeof body === 'object' &&
    Object.values(body as Record<string, unknown>).some((v) => v instanceof Blob)

  if (!hasBlob) return body

  const form = new FormData()
  for (const [key, value] of Object.entries(body as Record<string, unknown>)) {
    if (value === undefined || value === null) continue
    if (value instanceof Blob) {
      // Always send a filename so the part is received as a *file* (e.g.
      // Laravel's `$request->file()`), not a plain field. Files keep their name;
      // bare Blobs are named after the field.
      const name = typeof File !== 'undefined' && value instanceof File ? value.name : key
      form.append(key, value, name)
    } else if (typeof value === 'object') {
      form.append(key, JSON.stringify(value))
    } else {
      form.append(key, String(value))
    }
  }
  return form
}

export function createVaultConsumer(config: VaultConsumerConfig): VaultConsumer {
  const basePath = 'hash' in config.vault
    ? `/h/${config.vault.hash}`
    : `/v/${config.vault.org}/${config.vault.slug}`

  const http: Http = createHttp({
    baseUrl: config.baseUrl.replace(/\/$/, ''),
    auth: noAuth(),
    fetch: config.fetch,
  })

  const headers = config.key ? { 'X-Vault-Key': config.key } : undefined
  const grantQuery = config.grant ? { sig: config.grant.sig, exp: config.grant.exp } : undefined

  const get = <T>(path: string, query?: Record<string, QueryValue | QueryValue[]>) =>
    http.get<T>(`${basePath}${path}`, { query: { ...grantQuery, ...query }, headers, raw: true })

  const write = (method: string, body?: unknown): Promise<VaultWriteResult> =>
    http.post<VaultWriteResult>(`${basePath}/w/${method}`, {
      body: toWriteBody(body),
      query: grantQuery,
      headers,
      raw: true,
    })

  return {
    basePath,

    meta: () => get<VaultMeta>('/meta'),

    resources: (params = {}) =>
      get<VaultIndexPage>('/resources', {
        page: params.page,
        per_page: params.perPage,
        tag: params.tag,
        category: params.category,
      }),

    tags: () => get<VaultTags>('/tags'),

    search: (params) => {
      const query: Record<string, QueryValue | QueryValue[]> = {
        q: params.q,
        mode: params.mode,
        page: params.page,
      }
      for (const [field, values] of Object.entries(params.facets ?? {})) {
        query[`facet[${field}][]`] = values
      }
      return get<VaultSearchResult>('/search', query)
    },

    searchChunks: (params) =>
      get('/search', { q: params.q, scope: 'chunks', mode: params.mode, limit: params.limit }),

    embed: (text) => get<VaultEmbedding>('/embed', { q: text }),

    graph: (params = {}) => get<VaultGraph>('/graph', { nodes: params.nodes }),

    ask: (params) =>
      http.post<VaultAskResult>(`${basePath}/ask`, {
        body: { question: params.question, k: params.k },
        query: grantQuery,
        headers,
        signal: params.signal,
        raw: true,
      }),

    askStream: async (params) => {
      const res = await http.stream(`${basePath}/ask`, {
        method: 'POST',
        body: { question: params.question, k: params.k },
        query: grantQuery,
        headers: { ...headers, Accept: 'text/event-stream' },
        signal: params.signal,
      })

      const reader = res.body?.getReader()
      if (!reader) {
        // No readable body (unexpected) — parse whatever came back as the result.
        return (await res.json()) as VaultAskResult
      }

      const decoder = new TextDecoder()
      let buffer = ''
      let result: VaultAskResult | undefined

      const drain = () => {
        let sep: number
        while ((sep = buffer.indexOf('\n\n')) !== -1) {
          const frame = buffer.slice(0, sep)
          buffer = buffer.slice(sep + 2)
          const event = /^event:\s*(.+)$/m.exec(frame)?.[1]?.trim() ?? 'message'
          const data = /^data:\s*(.+)$/m.exec(frame)?.[1]
          if (data === undefined) continue
          const payload = JSON.parse(data)
          if (event === 'token') params.onToken(payload.text ?? '')
          else if (event === 'done') result = payload as VaultAskResult
          else if (event === 'error') throw new Error(payload.error ?? 'Reasoning service unavailable')
        }
      }

      for (;;) {
        const { value, done } = await reader.read()
        if (done) break
        buffer += decoder.decode(value, { stream: true })
        drain()
      }
      buffer += decoder.decode()
      drain()

      if (!result) throw new Error('Stream ended without a result')
      return result
    },

    resource: (slug) => ({
      meta: () => get(`/${slug}/meta`),
      tags: () => get(`/${slug}/tags`),
      files: () => get(`/${slug}/files`),
      related: () => get(`/${slug}/related`),
      chunks: (params = {}) => get(`/${slug}/chunks`, { from: params.from, to: params.to }),
    }),

    write: (method, body) => write(method, body),

    writeCapabilities: () => get<{ ok: boolean; methods: string[] }>('/w'),

    gallery: {
      activate: (linkHashes) => write('activate', { resources: linkHashes }),
      open: () => write('open'),
      close: () => write('close'),
    },
  }
}
