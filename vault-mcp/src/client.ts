/**
 * Minimal REST client for the vault consumer surface — plain `fetch`, no
 * dependency on axios/form-data or @tydal/client. vault-mcp is run by
 * customers (or their own downstream consumers) on their own machines and
 * their own upgrade schedule, not deployed by TYDAL alongside the backend, so
 * it deliberately stays free of TYDAL's fast-moving internal packages.
 *
 * All requests stay inside the scoped vault's path prefix; the vault key
 * (private vaults) travels as X-Vault-Key. Responses are bare JSON payloads
 * (no `success` envelope — VAULT_SYSTEM.md §7), so `res.data` is just the
 * parsed body.
 */
import { config } from './config.js';

/** Thrown for any non-OK response. `body` is the parsed (or raw text) error payload. */
export class VaultApiError extends Error {
  readonly status: number;
  readonly body?: unknown;

  constructor(message: string, status: number, body?: unknown) {
    super(message);
    this.name = 'VaultApiError';
    this.status = status;
    this.body = body;
    Object.setPrototypeOf(this, VaultApiError.prototype);
  }
}

// `any`, not `unknown`: tool files do unchecked chained optional access
// (`res.data?.items ?? []`) — `unknown` narrows `x?.y` to `{}` under strict
// mode and breaks that pattern.
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type Res<T = any> = { data: T; headers: Headers };
type GetOptions = { params?: Record<string, unknown>; headers?: Record<string, string> };
type PostOptions = { headers?: Record<string, string> };
type BinaryGetOptions = GetOptions & { maxBytes?: number };

function buildUrl(path: string, params?: Record<string, unknown>): string {
  const url = new URL(config.baseUrl + path);
  if (params) {
    for (const [key, value] of Object.entries(params)) {
      if (value === undefined || value === null) continue;
      if (Array.isArray(value)) {
        const arrayKey = key.endsWith(']') ? key : `${key}[]`;
        for (const v of value) {
          if (v !== undefined && v !== null) url.searchParams.append(arrayKey, String(v));
        }
      } else {
        url.searchParams.set(key, String(value));
      }
    }
  }
  return url.toString();
}

function defaultHeaders(extra?: Record<string, string>): Record<string, string> {
  return {
    Accept: 'application/json',
    ...(config.vaultKey ? { 'X-Vault-Key': config.vaultKey } : {}),
    ...extra,
  };
}

async function parseBody(res: Response): Promise<unknown> {
  const text = await res.text();
  if (!text) return undefined;
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}

async function assertOk(res: Response): Promise<void> {
  if (res.ok) return;
  const body = await parseBody(res);
  const data = (typeof body === 'object' && body !== null ? body : {}) as { error?: string; message?: string };
  throw new VaultApiError(
    data.error ?? data.message ?? `Request failed with status ${res.status}`,
    res.status,
    body,
  );
}

export const client = {
  async get(path: string, opts: GetOptions = {}): Promise<Res> {
    const res = await fetch(buildUrl(path, opts.params), { headers: defaultHeaders(opts.headers) });
    await assertOk(res);
    return { data: await parseBody(res), headers: res.headers };
  },

  /** `body` is a `FormData` — fetch sets the multipart boundary automatically. */
  async post(path: string, body: FormData, opts: PostOptions = {}): Promise<Res> {
    const res = await fetch(buildUrl(path), {
      method: 'POST',
      headers: defaultHeaders(opts.headers),
      body,
    });
    await assertOk(res);
    return { data: await parseBody(res), headers: res.headers };
  },

  /**
   * Binary GET with an optional byte cap enforced *during* the stream (not
   * just checked after downloading) — mirrors axios's `maxContentLength`, so
   * an oversized upstream response never gets fully buffered into memory.
   */
  async getBinary(path: string, opts: BinaryGetOptions = {}): Promise<Res<ArrayBuffer>> {
    const res = await fetch(buildUrl(path, opts.params), { headers: defaultHeaders(opts.headers) });
    await assertOk(res);

    if (opts.maxBytes === undefined || !res.body) {
      return { data: await res.arrayBuffer(), headers: res.headers };
    }

    const reader = res.body.getReader();
    const chunks: Uint8Array[] = [];
    let total = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > opts.maxBytes) {
        await reader.cancel();
        throw new VaultApiError('Prepared image exceeds the requested byte limit', res.status);
      }
      chunks.push(value);
    }
    const merged = new Uint8Array(total);
    let offset = 0;
    for (const chunk of chunks) {
      merged.set(chunk, offset);
      offset += chunk.byteLength;
    }
    return { data: merged.buffer, headers: res.headers };
  },
};

/** Build a path inside the scoped vault. vaultPath('meta') → /v/acme/kit/meta */
export function vaultPath(...segments: string[]): string {
  return [config.vaultPath, ...segments.map(encodeURIComponent)].join('/');
}

/** Absolute URL form of a vault path — for handing addresses to the user. */
export function vaultUrl(...segments: string[]): string {
  return config.baseUrl + vaultPath(...segments);
}

/** Extract a clean error message from a thrown client error. */
export function apiError(err: unknown): string {
  if (err instanceof VaultApiError) {
    const data = err.body as { error?: string; message?: string } | undefined;
    return data?.error ?? data?.message ?? err.message;
  }
  return err instanceof Error ? err.message : String(err);
}
