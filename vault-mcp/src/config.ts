/**
 * Configuration — all values come from environment variables.
 *
 * The connection is scoped to ONE vault (VAULT_SYSTEM.md §7): published
 * vaults connect keyless by slug or hash; private vaults additionally need
 * a vault key.
 *
 * Required:
 *   TYDAL_BASE_URL     Root URL of the TYDAL instance (e.g. http://localhost:8000
 *                      — NOT the /api/v1 prefix; the consumer surface lives at
 *                      /v and /h on the web root)
 *
 * One of:
 *   TYDAL_VAULT        Human address: "orgSlug/vaultSlug" (e.g. "acme/press-kit")
 *   TYDAL_VAULT_HASH   Opaque vault hash (e.g. "Hk3nRw9QzL2p")
 *
 * Optional:
 *   TYDAL_VAULT_KEY        Read vault key (tvk_…) — required for private vaults
 *   TYDAL_VAULT_WRITE_KEY  Write vault key (tvk_… with a w:{method} ability).
 *                          When set, the vault's purpose-exposed write ops
 *                          (e.g. `ingest` on an `ai` vault) become tools — so
 *                          any MCP-capable AI can transform and write back.
 *                          Absent = read-only (the default contract).
 */

function required(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

const slugPair = process.env.TYDAL_VAULT ?? '';
const vaultHash = process.env.TYDAL_VAULT_HASH ?? '';

if (!slugPair && !vaultHash) {
  throw new Error('Set TYDAL_VAULT ("orgSlug/vaultSlug") or TYDAL_VAULT_HASH to scope the connection to one vault');
}

if (slugPair && !/^[^/]+\/[^/]+$/.test(slugPair)) {
  throw new Error(`TYDAL_VAULT must be "orgSlug/vaultSlug", got: ${slugPair}`);
}

export const config = {
  baseUrl: required('TYDAL_BASE_URL').replace(/\/$/, ''),
  /** Path prefix of the scoped vault: /v/{org}/{vault} or /h/{vaultHash}. */
  vaultPath: slugPair ? `/v/${slugPair}` : `/h/${vaultHash}`,
  vaultKey: process.env.TYDAL_VAULT_KEY ?? null,
  /** A write-capable key (w:{method}); its presence turns on the write tools. */
  writeKey: process.env.TYDAL_VAULT_WRITE_KEY ?? null,
} as const;
