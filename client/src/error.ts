/**
 * The single error type thrown by `@tydal/client` for any non-success API
 * response (HTTP error status, or an envelope with `success: false`).
 *
 * Replaces the two ad-hoc error paths in the legacy clients: the frontend's
 * `extractApiError()` and the MCP server's `apiError()`.
 */
export class TydalApiError extends Error {
  /** HTTP status code (0 if the request never completed). */
  readonly status: number
  /** Laravel field-level validation errors, when present. */
  readonly errors?: Record<string, string[]>
  /** The raw parsed response body, for callers that need more than `message`. */
  readonly body?: unknown

  constructor(
    message: string,
    opts: { status: number; errors?: Record<string, string[]>; body?: unknown },
  ) {
    super(message)
    this.name = 'TydalApiError'
    this.status = opts.status
    this.errors = opts.errors
    this.body = opts.body
    // Restore prototype chain for instanceof across transpile targets.
    Object.setPrototypeOf(this, TydalApiError.prototype)
  }
}
