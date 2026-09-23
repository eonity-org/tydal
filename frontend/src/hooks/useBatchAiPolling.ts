import { useState, useRef, useCallback, useEffect } from 'react'
import resourceService, { ResourceData } from '../api/resourceService'
import { AiSuggestions, extractSuggestions, extractTikaSuggestions } from './useAiSuggestionsPoller'

// Re-export so consumers don't need to change imports
export type { FileSuggestion, AiSuggestions } from './useAiSuggestionsPoller'

// ─── types ────────────────────────────────────────────────────────────────────

export interface BatchPollEntry {
  /** not_supported = file type cannot produce AI suggestions (image/video) */
  status: 'waiting' | 'ready' | 'timeout' | 'not_supported'
  suggestions: AiSuggestions | null
}

interface Options {
  pollInterval?: number
  timeoutMs?: number
}

// How long to keep polling after 'not_applicable', waiting for Tika / vision-AI.
const TIKA_WAIT_MS = 30_000

// ─── hook ─────────────────────────────────────────────────────────────────────

/**
 * Manages N concurrent AI suggestion pollers without calling hooks in loops.
 * Uses plain refs for intervals/timeouts and a state map for statuses.
 *
 * When the backend reports 'not_applicable' (images/video), the poller
 * switches to a shorter 30-second Tika-wait phase instead of stopping,
 * so EXIF metadata extracted by Tika can still be surfaced as suggestions.
 *
 * Usage:
 *   const { statuses, startPolling, stopAll, getEndTime } = useBatchAiPolling()
 *   // After a resource is created + its file uploaded:
 *   startPolling(resource.id)
 */
export function useBatchAiPolling(options: Options = {}) {
  const { pollInterval = 3000, timeoutMs = 300_000 } = options

  const [statuses, setStatuses] = useState<Record<string, BatchPollEntry>>({})

  // Refs hold the actual interval/timeout handles so they survive re-renders
  const intervalsRef   = useRef<Map<string, ReturnType<typeof setInterval>>>(new Map())
  const timeoutsRef    = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map())
  // Track resources that are in the Tika-wait phase (AI not_applicable, waiting for Tika)
  const tikaWaitingRef    = useRef<Set<string>>(new Set())
  // Deadline timestamps per resource — used to render a countdown in the UI
  const endTimesRef       = useRef<Map<string, number>>(new Map())
  // How many per-file suggestions we need before considering analysis complete.
  // Not cleared in stopOne so restartPolling reuses the same count.
  const expectedCountsRef = useRef<Map<string, number>>(new Map())

  const stopOne = useCallback((resourceId: string) => {
    const iv = intervalsRef.current.get(resourceId)
    if (iv !== undefined) { clearInterval(iv); intervalsRef.current.delete(resourceId) }
    const to = timeoutsRef.current.get(resourceId)
    if (to !== undefined) { clearTimeout(to); timeoutsRef.current.delete(resourceId) }
    tikaWaitingRef.current.delete(resourceId)
    endTimesRef.current.delete(resourceId)
  }, [])

  const stopAll = useCallback(() => {
    intervalsRef.current.forEach((iv) => clearInterval(iv))
    intervalsRef.current.clear()
    timeoutsRef.current.forEach((to) => clearTimeout(to))
    timeoutsRef.current.clear()
    tikaWaitingRef.current.clear()
    endTimesRef.current.clear()
    expectedCountsRef.current.clear()
    setStatuses({})
  }, [])

  const startPolling = useCallback(
    (resourceId: string, opts: { preserveSuggestions?: boolean; expectedFileCount?: number } = {}) => {
      // Idempotent — don't start a second poller for the same ID
      if (intervalsRef.current.has(resourceId)) return

      // Store expected file count so we keep polling until all files have suggestions.
      // Only overwrite if explicitly provided — restartPolling omits it to reuse the stored count.
      if (opts.expectedFileCount !== undefined) {
        expectedCountsRef.current.set(resourceId, opts.expectedFileCount)
      }

      setStatuses((prev) => ({
        ...prev,
        // preserveSuggestions: keep existing suggestions visible while re-polling
        [resourceId]: opts.preserveSuggestions && prev[resourceId]
          ? { ...prev[resourceId], status: 'waiting' as const }
          : { status: 'waiting' as const, suggestions: null },
      }))

      // Record the deadline so the UI can display a countdown
      endTimesRef.current.set(resourceId, Date.now() + timeoutMs)

      const poll = async () => {
        // Guard: check the interval is still registered (not stopped mid-flight)
        if (!intervalsRef.current.has(resourceId)) return
        const resource = await resourceService.getResource(resourceId) as ResourceData | null
        if (!resource || !intervalsRef.current.has(resourceId)) return

        const backendStatus = resource.ai_suggestions_status

        if (backendStatus === 'found') {
          const suggestions  = extractSuggestions(resource)
          const expectedCount = expectedCountsRef.current.get(resourceId) ?? 1
          const gotCount      = suggestions?.perFileSuggestions.length ?? 0

          if (gotCount >= expectedCount) {
            // All expected files have suggestions — analysis complete.
            stopOne(resourceId)
            setStatuses((prev) => ({
              ...prev,
              [resourceId]: { status: 'ready', suggestions },
            }))
          } else {
            // Partial — surface what we have but keep polling for remaining files.
            setStatuses((prev) => ({
              ...prev,
              [resourceId]: { ...prev[resourceId], suggestions },
            }))
          }

        } else if (backendStatus === 'not_applicable') {
          // AI won't run (image/video) — try to extract EXIF/IPTC metadata via Tika instead.
          const tikaSuggestions = extractTikaSuggestions(resource)
          if (tikaSuggestions && !tikaWaitingRef.current.has(resourceId)) {
            // Tika data found on the first not_applicable — show it immediately, but
            // DON'T stop polling yet. Replace the long timeout with a short window so
            // vision-AI results (AnalyzeImageContent job) can still upgrade the suggestions.
            tikaWaitingRef.current.add(resourceId)
            setStatuses((prev) => ({
              ...prev,
              [resourceId]: { status: 'ready', suggestions: tikaSuggestions },
            }))
            const existingTo = timeoutsRef.current.get(resourceId)
            if (existingTo !== undefined) clearTimeout(existingTo)
            endTimesRef.current.set(resourceId, Date.now() + TIKA_WAIT_MS)
            const tikaTo = setTimeout(() => {
              if (intervalsRef.current.has(resourceId)) {
                stopOne(resourceId)
                // Keep whatever suggestions we have (Tika or vision if it arrived)
              }
            }, TIKA_WAIT_MS)
            timeoutsRef.current.set(resourceId, tikaTo)
          } else if (tikaSuggestions) {
            // Already in tika-wait phase — update in case richer Tika scan completed
            setStatuses((prev) => ({
              ...prev,
              [resourceId]: { status: 'ready', suggestions: tikaSuggestions },
            }))
          } else if (!tikaWaitingRef.current.has(resourceId)) {
            // First not_applicable with no Tika data yet — start the wait window
            tikaWaitingRef.current.add(resourceId)
            const existingTo = timeoutsRef.current.get(resourceId)
            if (existingTo !== undefined) clearTimeout(existingTo)
            endTimesRef.current.set(resourceId, Date.now() + TIKA_WAIT_MS)
            const tikaTo = setTimeout(() => {
              if (intervalsRef.current.has(resourceId)) {
                stopOne(resourceId)
                setStatuses((prev) => ({
                  ...prev,
                  [resourceId]: { status: 'not_supported', suggestions: null },
                }))
              }
            }, TIKA_WAIT_MS)
            timeoutsRef.current.set(resourceId, tikaTo)
          }
          // Keep the interval running in all not_applicable cases so vision can arrive

        } else if (backendStatus === 'processed') {
          // Suggestions existed but were already cleared — treat as done with no pending suggestions
          stopOne(resourceId)
          setStatuses((prev) => ({
            ...prev,
            [resourceId]: { status: 'ready', suggestions: null },
          }))
        }
        // 'none' → keep polling
      }

      // Poll immediately, then on interval
      poll()
      const iv = setInterval(poll, pollInterval)
      intervalsRef.current.set(resourceId, iv)

      // Timeout guard (replaced by shorter Tika timeout when not_applicable fires)
      const to = setTimeout(() => {
        if (intervalsRef.current.has(resourceId)) {
          stopOne(resourceId)
          setStatuses((prev) => ({
            ...prev,
            [resourceId]: { status: 'timeout', suggestions: null },
          }))
        }
      }, timeoutMs)
      timeoutsRef.current.set(resourceId, to)
    },
    [pollInterval, timeoutMs, stopOne]
  )

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      intervalsRef.current.forEach((iv) => clearInterval(iv))
      timeoutsRef.current.forEach((to) => clearTimeout(to))
    }
  }, [])

  // Keeps existing suggestions visible while the re-poll runs (used by the retry button)
  const restartPolling = useCallback((resourceId: string) => {
    stopOne(resourceId)
    startPolling(resourceId, { preserveSuggestions: true })
  }, [stopOne, startPolling])

  // Returns the Unix timestamp (ms) at which this resource's analysis will time out.
  // Returns undefined when no poller is active. Read from a ref — callers must
  // set up their own ticker (e.g. a 1-second setInterval) to drive re-renders.
  const getEndTime = useCallback(
    (resourceId: string) => endTimesRef.current.get(resourceId),
    []
  )

  return { statuses, startPolling, restartPolling, stopAll, getEndTime }
}
