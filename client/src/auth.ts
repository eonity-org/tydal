/**
 * Pluggable auth strategy.
 *
 * This is the seam that lets one client serve very different surfaces:
 *  - the management SPA plugs in cookie storage + token refresh + 401 redirect;
 *  - the MCP server plugs in a static env token;
 *  - a published vault renderer (later) plugs in a hash/credential provider.
 */
export interface TokenProvider {
  /** Return the current bearer token, or null for an unauthenticated request. */
  getToken(): string | null | Promise<string | null>
  /**
   * Attempt to refresh the token after a 401. Return true if a fresh token is
   * now available (the request will be retried once). Omit if not supported.
   */
  refresh?(): boolean | Promise<boolean>
  /** Called when a request is unauthorized and refresh failed or is unsupported. */
  onUnauthorized?(): void | Promise<void>
}

/** A fixed bearer token (e.g. the MCP server's `TYDAL_TOKEN`). */
export function staticToken(token: string): TokenProvider {
  return { getToken: () => token }
}

/** No authentication (public endpoints). */
export function noAuth(): TokenProvider {
  return { getToken: () => null }
}
