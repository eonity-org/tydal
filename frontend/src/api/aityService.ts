import { tydal } from './tydalClient'

export interface AutoApproveOptions {
  confidence?: number
  mid_confidence?: number
  min_frequency?: number
  dedup?: boolean
  max_tags?: number
  dry_run?: boolean
  apply_name?: boolean
  apply_description?: boolean
  apply_tags?: boolean
}

export interface AutoApproveResult {
  resources_processed: number
  names_applied: number
  descriptions_applied: number
  tags_applied: number
  tags_skipped: number
  dry_run: boolean
  log: string[]
}

/**
 * Dispatch a background queue job to wait for AiTy analysis and then
 * auto-approve all resources in the workspace. Returns immediately.
 * The job status can be polled via workspaceService.getAityStatus().
 */
export async function dispatchAutoApproveWorkspace(
  workspaceId: string | number,
  options: AutoApproveOptions = {}
): Promise<void> {
  // SDK throws TydalApiError (an Error with .message) on failure.
  await tydal.http.post('/aity/auto-approve/dispatch', {
    body: { workspace_id: workspaceId, ...options },
  })
}

export async function autoApproveWorkspace(
  workspaceId: string | number,
  options: AutoApproveOptions = {}
): Promise<AutoApproveResult> {
  // Envelope `{ success, data }` → SDK unwraps to the result.
  return await tydal.http.post<AutoApproveResult>('/aity/auto-approve', {
    body: { workspace_id: workspaceId, ...options },
  })
}

/**
 * Streaming version: connects to the SSE endpoint and calls handlers as events arrive.
 * Resolves when the stream closes. Rejects on network/server error.
 */
export async function autoApproveWorkspaceStream(
  workspaceId: string | number,
  options: AutoApproveOptions = {},
  onLine: (line: string) => void,
  onComplete: (result: AutoApproveResult) => void,
  onError: (message: string) => void,
): Promise<void> {
  // `stream` returns the live Response (throws TydalApiError on non-OK).
  const response = await tydal.http.stream('/aity/auto-approve/stream', {
    body: { workspace_id: workspaceId, ...options },
  })

  const reader = response.body?.getReader()
  if (!reader) throw new Error('Streaming not supported by this browser')

  const decoder = new TextDecoder()
  let buffer = ''

  while (true) {
    const { done, value } = await reader.read()
    if (done) break

    buffer += decoder.decode(value, { stream: true })

    // SSE events are separated by double newlines
    const parts = buffer.split('\n\n')
    buffer = parts.pop() ?? ''

    for (const part of parts) {
      const line = part.trim()
      if (!line.startsWith('data: ')) continue
      try {
        const event = JSON.parse(line.slice(6))
        if (event.type === 'log')      onLine(event.line)
        else if (event.type === 'complete') onComplete(event.result)
        else if (event.type === 'error')    onError(event.message)
      } catch {
        // ignore malformed events
      }
    }
  }
}
