/**
 * Minimal REST client for the org MCP server — plain `fetch`, no dependency
 * on `@tydal/client`. org-mcp is run by customers, on their own machines and
 * their own upgrade schedule (see docs/AI_SURFACES.md — "who runs the
 * model"), not deployed by TYDAL alongside the backend the way the SPA is, so
 * it deliberately stays free of TYDAL's fast-moving internal packages.
 *
 * Exposed as an axios-shaped `{ data }` wrapper so tool files stay unchanged:
 * every call here is effectively "raw" (no envelope unwrapping) — tools read
 * `res.data` and dig into `res.data.data.*` / `res.data.meta` themselves.
 *
 * Sets on every request: Authorization: Bearer <token>, X-Organization-ID:
 * <orgId>, Accept: application/json.
 */
import { config } from './config.js';

/** Thrown for any non-OK response or an envelope with `success: false`. */
export class TydalApiError extends Error {
  readonly status: number;
  readonly body?: unknown;

  constructor(message: string, status: number, body?: unknown) {
    super(message);
    this.name = 'TydalApiError';
    this.status = status;
    this.body = body;
    Object.setPrototypeOf(this, TydalApiError.prototype);
  }
}

// `any`, not `unknown`, on purpose: every tool file does unchecked chained
// optional access (`res.data?.data?.resource ?? res.data`) — `unknown` narrows
// `x?.y` to `{}` under strict mode and breaks that pattern everywhere.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Res<T = any> = { data: T };
type Params = { params?: Record<string, unknown> };

/** Laravel-style query serialization: arrays as `key[]=v` (§ VAULT_WRITE_METHODS.md's SDK does the same). */
function buildUrl(path: string, params?: Record<string, unknown>): string {
  const rel = path.startsWith('/') ? path : `/${path}`;
  const qs = new URLSearchParams();

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null) continue;
      if (Array.isArray(value)) {
        const arrayKey = key.endsWith(']') ? key : `${key}[]`;
        for (const v of value) {
          if (v !== undefined && v !== null) qs.append(arrayKey, String(v));
        }
      } else {
        qs.append(key, String(value));
      }
    }
  }

  const query = qs.toString();
  return `${config.baseUrl}${rel}${query ? `?${query}` : ''}`;
}

async function request<T = unknown>(
  method: string,
  path: string,
  body?: unknown,
  params?: Record<string, unknown>,
): Promise<Res<T>> {
  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${config.token}`,
    'X-Organization-ID': config.orgId,
  };

  let requestBody: BodyInit | undefined;
  if (body !== undefined && body !== null) {
    if (body instanceof FormData) {
      requestBody = body; // let fetch set the multipart boundary
    } else {
      headers['Content-Type'] = 'application/json';
      requestBody = JSON.stringify(body);
    }
  }

  const res = await fetch(buildUrl(path, params), { method, headers, body: requestBody });
  const text = await res.text();

  let parsed: unknown;
  if (text) {
    try {
      parsed = JSON.parse(text);
    } catch {
      parsed = text;
    }
  }

  const isObject = parsed !== null && typeof parsed === 'object';
  const envelope = (isObject ? parsed : {}) as { success?: boolean; message?: string };
  const failed = !res.ok || envelope.success === false;

  if (failed) {
    const message = envelope.message
      || (typeof parsed === 'string' && parsed)
      || `Request failed with status ${res.status}`;
    throw new TydalApiError(message, res.status, parsed);
  }

  return { data: parsed as T };
}

export const client = {
  get: (path: string, opts?: Params): Promise<Res> => request('GET', path, undefined, opts?.params),
  post: (path: string, body?: unknown): Promise<Res> => request('POST', path, body),
  put: (path: string, body?: unknown): Promise<Res> => request('PUT', path, body),
  delete: (path: string): Promise<Res> => request('DELETE', path),
};

/** Extract a clean error message from a thrown client error. */
export function apiError(err: unknown): string {
  if (err instanceof TydalApiError) {
    const body = err.body as { message?: string } | undefined;
    return body?.message ?? err.message;
  }
  return err instanceof Error ? err.message : String(err);
}
