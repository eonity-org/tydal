/**
 * The transport core. One place for: base URL, auth header, org header, query
 * building (incl. Laravel-style facet arrays), envelope unwrapping, error
 * normalization, and 401-refresh-retry. Every namespace is built on this.
 */
import { TydalApiError } from './error.js'
import type { ApiEnvelope } from './types.js'
import type { TokenProvider } from './auth.js'

export interface HttpConfig {
  /** Base URL of the TYDAL API, e.g. `https://api.example.com/api/v1`. */
  baseUrl: string
  /** Auth strategy (see `auth.ts`). */
  auth: TokenProvider
  /** Optional org context — sent as `X-Organization-ID` (used by the MCP server). */
  orgId?: string
  /** Inject a fetch implementation (defaults to the global). */
  fetch?: typeof fetch
}

export type QueryValue = string | number | boolean | null | undefined

export interface RequestOptions {
  /** Scalar/array query params. Arrays serialize as `key[]=v` (Laravel style). */
  query?: Record<string, QueryValue | QueryValue[]>
  /** Faceted filters, serialized as `facets[key][]=value`. */
  facets?: Record<string, string[]>
  /** Request body. A plain object is JSON-encoded; FormData is sent as-is. */
  body?: unknown
  /** Abort signal for cancellation (e.g. catalogue search). */
  signal?: AbortSignal
  /** Extra headers, merged over the defaults. */
  headers?: Record<string, string>
  /**
   * Return the full parsed body instead of unwrapping `data`. Needed for
   * endpoints that put siblings next to `data` under the success envelope —
   * e.g. list endpoints with `{ success, data: {...}, meta: { pagination } }`.
   */
  raw?: boolean
}

export interface UploadOptions {
  /** HTTP method (default POST). */
  method?: string
  /** Upload progress callback (0–100). Uses XHR; browser only. */
  onProgress?: (percent: number) => void
  /** Abort signal. */
  signal?: AbortSignal
  /** Return the full body instead of unwrapping `data`. */
  raw?: boolean
}

export interface Http {
  request<T>(method: string, path: string, opts?: RequestOptions): Promise<T>
  get<T>(path: string, opts?: RequestOptions): Promise<T>
  post<T>(path: string, opts?: RequestOptions): Promise<T>
  put<T>(path: string, opts?: RequestOptions): Promise<T>
  patch<T>(path: string, opts?: RequestOptions): Promise<T>
  delete<T>(path: string, opts?: RequestOptions): Promise<T>
  /** Multipart upload. Uses XHR for progress when available, else fetch. */
  upload<T>(path: string, body: FormData, opts?: UploadOptions): Promise<T>
  /**
   * Authenticated request that returns the raw `Response` (for streaming, e.g.
   * SSE) instead of parsing. Applies auth + 401-refresh-retry; throws
   * `TydalApiError` on a non-OK status, otherwise hands back the live response.
   */
  stream(path: string, opts?: RequestOptions & { method?: string }): Promise<Response>
}

function buildUrl(baseUrl: string, path: string, opts?: RequestOptions): string {
  const base = baseUrl.replace(/\/$/, '')
  const rel = path.startsWith('/') ? path : `/${path}`
  const qs = new URLSearchParams()

  if (opts?.query) {
    for (const [key, value] of Object.entries(opts.query)) {
      if (value === undefined || value === null) continue
      if (Array.isArray(value)) {
        // Add `[]` only if the key isn't already bracketed (callers may pass
        // `facets[type][]` directly), so we never produce `key[][]`.
        const arrayKey = key.endsWith(']') ? key : `${key}[]`
        for (const v of value) {
          if (v !== undefined && v !== null) qs.append(arrayKey, String(v))
        }
      } else {
        qs.append(key, String(value))
      }
    }
  }

  if (opts?.facets) {
    for (const [key, values] of Object.entries(opts.facets)) {
      for (const v of values) qs.append(`facets[${key}][]`, v)
    }
  }

  const query = qs.toString()
  return `${base}${rel}${query ? `?${query}` : ''}`
}

/**
 * Turn a raw response (status + text) into data, unwrapping the envelope or
 * throwing a normalized error. Shared by both the fetch and XHR-upload paths.
 */
function interpret<T>(status: number, ok: boolean, text: string, raw: boolean): T {
  let parsed: unknown = undefined
  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      parsed = text
    }
  }

  const isObject = parsed !== null && typeof parsed === 'object'
  const envelope = (isObject ? parsed : {}) as ApiEnvelope<T>
  const failed = !ok || envelope.success === false

  if (failed) {
    const message =
      envelope.message ||
      (typeof parsed === 'string' && parsed) ||
      `Request failed with status ${status}`
    throw new TydalApiError(message, {
      status,
      errors: envelope.errors,
      body: parsed,
    })
  }

  // TYDAL is inconsistent on purpose: the standard envelope is
  // `{ success: true, data }` (unwrap → data), but some endpoints (e.g. the
  // catalogue) return a bare payload like `{ data: [...], facets, ... }` with no
  // `success` key. So only unwrap when `success === true`; otherwise return the
  // raw body so siblings (facets/total) survive.
  if (!raw && isObject && envelope.success === true && 'data' in (parsed as object)) {
    return envelope.data as T
  }
  return parsed as T
}

/** Parse a fetch Response, unwrapping or throwing. */
async function handle<T>(res: Response, raw = false): Promise<T> {
  const text = await res.text()
  return interpret<T>(res.status, res.ok, text, raw)
}

export function createHttp(config: HttpConfig): Http {
  // Late-bind globalThis.fetch (resolved per call, not captured at construction)
  // so a fetch installed *after* the client is created — e.g. MSW in tests, or a
  // polyfill — is still used. An explicitly injected `config.fetch` wins.
  const doFetch: typeof fetch = config.fetch
    ? config.fetch
    : (input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) => {
        if (!globalThis.fetch) {
          throw new Error(
            '@tydal/client: no fetch implementation found — pass `fetch` in the config.',
          )
        }
        return globalThis.fetch(input, init)
      }

  async function buildInit(
    method: string,
    opts: RequestOptions | undefined,
    token: string | null,
  ): Promise<RequestInit> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      ...opts?.headers,
    }
    if (config.orgId) headers['X-Organization-ID'] = config.orgId
    if (token) headers['Authorization'] = `Bearer ${token}`

    let body: BodyInit | undefined
    if (opts?.body !== undefined && opts.body !== null) {
      if (opts.body instanceof FormData) {
        body = opts.body // let the runtime set the multipart boundary
      } else {
        headers['Content-Type'] = 'application/json'
        body = JSON.stringify(opts.body)
      }
    }

    return { method, headers, body, signal: opts?.signal }
  }

  async function request<T>(
    method: string,
    path: string,
    opts?: RequestOptions,
  ): Promise<T> {
    const url = buildUrl(config.baseUrl, path, opts)

    const token = await config.auth.getToken()
    let res = await doFetch(url, await buildInit(method, opts, token))

    // 401 → try a single refresh-and-retry if the strategy supports it.
    if (res.status === 401 && config.auth.refresh) {
      const refreshed = await config.auth.refresh()
      if (refreshed) {
        const fresh = await config.auth.getToken()
        res = await doFetch(url, await buildInit(method, opts, fresh))
      }
    }

    if (res.status === 401) {
      await config.auth.onUnauthorized?.()
    }

    return handle<T>(res, opts?.raw)
  }

  /** XHR upload with progress events (browser only). */
  function uploadXhr<T>(path: string, formData: FormData, opts: UploadOptions): Promise<T> {
    const url = buildUrl(config.baseUrl, path)
    const method = opts.method ?? 'POST'

    const run = async (allowRefresh: boolean): Promise<T> => {
      const token = await config.auth.getToken()
      return new Promise<T>((resolve, reject) => {
        const xhr = new XMLHttpRequest()
        xhr.open(method, url, true)
        xhr.setRequestHeader('Accept', 'application/json')
        if (config.orgId) xhr.setRequestHeader('X-Organization-ID', config.orgId)
        if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`)
        // Do NOT set Content-Type — the browser adds the multipart boundary.

        if (opts.onProgress) {
          xhr.upload.onprogress = (ev) => {
            if (ev.lengthComputable) opts.onProgress!(Math.round((ev.loaded / ev.total) * 100))
          }
        }

        xhr.onload = async () => {
          // One refresh-and-retry on 401, mirroring the fetch path.
          if (xhr.status === 401 && allowRefresh && config.auth.refresh) {
            try {
              if (await config.auth.refresh()) {
                resolve(await run(false))
                return
              }
            } catch { /* fall through to error handling */ }
          }
          if (xhr.status === 401) await config.auth.onUnauthorized?.()
          try {
            resolve(interpret<T>(xhr.status, xhr.status >= 200 && xhr.status < 300, xhr.responseText, opts.raw ?? false))
          } catch (e) {
            reject(e)
          }
        }
        xhr.onerror = () => reject(new Error('Network error during upload'))
        xhr.onabort = () => reject(new DOMException('Upload aborted', 'AbortError'))

        if (opts.signal) {
          if (opts.signal.aborted) xhr.abort()
          else opts.signal.addEventListener('abort', () => xhr.abort(), { once: true })
        }

        xhr.send(formData)
      })
    }

    return run(true)
  }

  function upload<T>(path: string, formData: FormData, opts: UploadOptions = {}): Promise<T> {
    // XHR only when progress is wanted and available (browser); otherwise plain
    // fetch — which is also the Node/MCP path (no upload progress there).
    if (opts.onProgress && typeof XMLHttpRequest !== 'undefined') {
      return uploadXhr<T>(path, formData, opts)
    }
    return request<T>(opts.method ?? 'POST', path, { body: formData, signal: opts.signal, raw: opts.raw })
  }

  async function stream(
    path: string,
    opts?: RequestOptions & { method?: string },
  ): Promise<Response> {
    const method = opts?.method ?? 'POST'
    const url = buildUrl(config.baseUrl, path, opts)

    const token = await config.auth.getToken()
    let res = await doFetch(url, await buildInit(method, opts, token))

    if (res.status === 401 && config.auth.refresh) {
      if (await config.auth.refresh()) {
        const fresh = await config.auth.getToken()
        res = await doFetch(url, await buildInit(method, opts, fresh))
      }
    }
    if (res.status === 401) await config.auth.onUnauthorized?.()

    if (!res.ok) {
      // interpret() always throws on a non-OK status → normalized TydalApiError.
      const text = await res.text().catch(() => '')
      interpret<unknown>(res.status, false, text, false)
    }
    return res
  }

  return {
    request,
    get: (path, opts) => request('GET', path, opts),
    post: (path, opts) => request('POST', path, opts),
    put: (path, opts) => request('PUT', path, opts),
    patch: (path, opts) => request('PATCH', path, opts),
    delete: (path, opts) => request('DELETE', path, opts),
    upload,
    stream,
  }
}
