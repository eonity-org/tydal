import { useState, useRef, useCallback, useEffect } from 'react'
import resourceService from '../api/resourceService'

// ─── types ────────────────────────────────────────────────────────────────────

export interface AityPollEntry {
  status: 'waiting' | 'ready' | 'failed' | 'not_supported'
  stage: string | null
  suggestions: {
    suggestedName: string | null
    suggestedDescription: string | null
    suggestedTags: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
    /** Scheme-field suggestions (ai_fill contract) — field name → suggested value */
    suggestedMetadata: Record<string, unknown>
  } | null
  // Per-field "this suggestion has been applied" flag — drives the tick next to
  // each row in the per-file AITY card.
  applied?: {
    name: boolean
    description: boolean
    tags: boolean
  }
}

// ─── hook ─────────────────────────────────────────────────────────────────────

interface Options {
  pollInterval?: number
  timeoutMs?: number
}

/**
 * Per-file AITY enrichment poller.
 *
 * Polls GET /resources/{resourceId}/files/{fileId}/aity-status for each file
 * independently. The statuses map is keyed by fileId.
 *
 * Usage:
 *   const { statuses, startPolling, restartPolling, stopAll } = useAityPolling()
 *   // After all files for a resource are uploaded:
 *   startPolling(resource.id, uploadedFileIds)
 */
export function useAityPolling(options: Options = {}) {
  const { pollInterval = 3000, timeoutMs = 300_000 } = options

  const [statuses, setStatuses] = useState<Record<string, AityPollEntry>>({})

  const intervalsRef = useRef<Map<string, ReturnType<typeof setInterval>>>(new Map())
  const timeoutsRef  = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map())
  // resourceId per fileId — needed to build the API URL
  const resourceIdsRef = useRef<Map<string, string>>(new Map())

  const stopOne = useCallback((fileId: string) => {
    const iv = intervalsRef.current.get(fileId)
    if (iv !== undefined) { clearInterval(iv); intervalsRef.current.delete(fileId) }
    const to = timeoutsRef.current.get(fileId)
    if (to !== undefined) { clearTimeout(to); timeoutsRef.current.delete(fileId) }
  }, [])

  const stopAll = useCallback(() => {
    intervalsRef.current.forEach((iv) => clearInterval(iv))
    intervalsRef.current.clear()
    timeoutsRef.current.forEach((to) => clearTimeout(to))
    timeoutsRef.current.clear()
    resourceIdsRef.current.clear()
    setStatuses({})
  }, [])

  const startPollingOne = useCallback(
    (resourceId: string, fileId: string, preserveSuggestions: boolean) => {
      if (intervalsRef.current.has(fileId)) return  // idempotent

      resourceIdsRef.current.set(fileId, resourceId)

      setStatuses((prev) => ({
        ...prev,
        [fileId]: preserveSuggestions && prev[fileId]
          ? { ...prev[fileId], status: 'waiting' as const }
          : { status: 'waiting' as const, stage: null, suggestions: null },
      }))

      const poll = async () => {
        if (!intervalsRef.current.has(fileId)) return
        const rid = resourceIdsRef.current.get(fileId)
        if (!rid) return

        const data = await resourceService.getAityStatus(rid, fileId)
        if (!data || !intervalsRef.current.has(fileId)) return

        const { stage } = data

        if (stage === 'done' || stage === 'not_applicable') {
          stopOne(fileId)
          setStatuses((prev) => ({
            ...prev,
            [fileId]: stage === 'done'
              ? {
                  status: 'ready',
                  stage,
                  suggestions: data.suggestions
                    ? {
                        suggestedName: data.suggestions.name,
                        suggestedDescription: data.suggestions.description,
                        suggestedTags: data.suggestions.tags,
                        suggestedMetadata: data.suggestions.metadata ?? {},
                      }
                    : null,
                }
              : { status: 'not_supported', stage, suggestions: null },
          }))
        } else if (stage === 'failed') {
          stopOne(fileId)
          setStatuses((prev) => ({
            ...prev,
            [fileId]: { status: 'failed', stage, suggestions: prev[fileId]?.suggestions ?? null },
          }))
        } else {
          // queued | extracting | ai_analyzing | null → update stage label, keep waiting
          setStatuses((prev) => ({
            ...prev,
            [fileId]: { ...prev[fileId], status: 'waiting', stage: stage ?? null },
          }))
        }
      }

      poll()
      const iv = setInterval(poll, pollInterval)
      intervalsRef.current.set(fileId, iv)

      const to = setTimeout(() => {
        if (intervalsRef.current.has(fileId)) {
          stopOne(fileId)
          setStatuses((prev) => ({
            ...prev,
            [fileId]: { status: 'failed', stage: 'timeout', suggestions: prev[fileId]?.suggestions ?? null },
          }))
        }
      }, timeoutMs)
      timeoutsRef.current.set(fileId, to)
    },
    [pollInterval, timeoutMs, stopOne]
  )

  const initStatuses = useCallback((entries: Record<string, AityPollEntry>) => {
    setStatuses(prev => ({ ...prev, ...entries }))
  }, [])

  const startPolling = useCallback(
    (resourceId: string, fileIds: string[]) => {
      for (const fileId of fileIds) {
        startPollingOne(resourceId, fileId, false)
      }
    },
    [startPollingOne]
  )

  const restartPolling = useCallback(
    (resourceId: string, fileId: string) => {
      stopOne(fileId)
      startPollingOne(resourceId, fileId, true)
    },
    [stopOne, startPollingOne]
  )

  useEffect(() => {
    return () => {
      intervalsRef.current.forEach((iv) => clearInterval(iv))
      timeoutsRef.current.forEach((to) => clearTimeout(to))
    }
  }, [])

  return { statuses, initStatuses, startPolling, restartPolling, stopAll }
}
