/**
 * Which vault to open — resolved at load time, never hardcoded.
 *
 * Priority: URL params (`?vault=org/slug` or `?hash=…`, plus `?key=` and
 * `?base=`) over build-time env (`VITE_VAULT`, `VITE_VAULT_KEY`,
 * `VITE_TYDAL_URL`). Empty base = same origin (the vite dev proxy, or a
 * deployment where the app is served next to the vault routes).
 */
import type { VaultConsumerConfig } from '@tydal/client'

export function resolveConfig(search: string = window.location.search): VaultConsumerConfig | null {
  const params = new URLSearchParams(search)

  const baseUrl = params.get('base') ?? import.meta.env.VITE_TYDAL_URL ?? ''
  const key = params.get('key') ?? import.meta.env.VITE_VAULT_KEY ?? undefined

  // Signed grant (?sig=&exp=) — a minted signed URL pasted into the app.
  const sig = params.get('sig')
  const exp = params.get('exp')
  const grant = sig && exp && /^\d+$/.test(exp) ? { sig, exp: Number(exp) } : undefined

  const hash = params.get('hash')
  if (hash) {
    return { baseUrl, vault: { hash }, key, grant }
  }

  const pair = params.get('vault') ?? import.meta.env.VITE_VAULT ?? ''
  const [org, slug] = pair.split('/')
  if (org && slug) {
    return { baseUrl, vault: { org, slug }, key, grant }
  }

  return null
}
