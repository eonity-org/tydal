/**
 * Configuration — all values come from environment variables.
 *
 * Required:
 *   TYDAL_BASE_URL   Base URL of the TYDAL REST API (e.g. http://localhost:8000/api/v1)
 *   TYDAL_TOKEN      Sanctum personal access token
 *   TYDAL_ORG_ID     Organization UUID — sent as X-Organization-ID header on every request
 */

function required(name: string): string {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

export const config = {
  baseUrl: (process.env.TYDAL_BASE_URL ?? 'http://localhost:8000/api/v1').replace(/\/$/, ''),
  token:   required('TYDAL_TOKEN'),
  orgId:   required('TYDAL_ORG_ID'),
} as const;
