import { useEffect, useRef } from 'react'
import resourceService, { type ResourceData } from '../api/resourceService'
import authService from '../api/authService'

/**
 * Smart-polling sync of aity_status across the currently-visible resource grid.
 *
 * Why this exists: the grid does NOT poll per card. Without this hook, status
 * changes on the backend (worker completes analysis, auto-approve fires, …)
 * never surface unless the user re-filters or saves something. This hook does
 * one tiny request per dashboard tick — only when at least one card is in an
 * in-flight state — and patches each affected card's `aity_status` /
 * `updated_at` in place.
 *
 * Design notes:
 *  - Hidden tabs do not poll (uses Page Visibility API).
 *  - Polling stops automatically when every visible resource is terminal.
 *  - On N consecutive unchanged polls the interval doubles (5 s → 10 s → 20 s
 *    → 30 s cap). Any change resets it.
 *  - Backend caps at 200 ids per request and the service chunks beyond that,
 *    so passing the full visible list is safe.
 */

const POLL_BASE_MS = 5_000
const POLL_MAX_MS  = 30_000
const BACKOFF_THRESHOLD = 3    // unchanged polls before doubling

const IN_FLIGHT = new Set<string>(['queued', 'aity_in_progress'])

export function useAityStatusSync(
  resources: ResourceData[],
  onPatch: (patches: Array<{ id: string; aity_status: string | null; updated_at: string | null; under_auto_approve?: boolean }>) => void,
): void {
  // Pin onPatch in a ref so identity changes don't reset the timer/backoff.
  const onPatchRef = useRef(onPatch)
  useEffect(() => { onPatchRef.current = onPatch }, [onPatch])

  // Compute the id set to poll. "In flight" = pure pipeline states OR
  // suggestions_made while the parent workspace's auto-approve is still pending/
  // running, since that will soon promote the resource to automatic_review_done.
  // Memoise via join+sort so we only restart the effect when the actual set
  // changes (not on every render).
  const inFlightIds = resources
    .filter((r) => {
      if (!r?.aity_status) return false
      if (IN_FLIGHT.has(r.aity_status)) return true
      return r.aity_status === 'suggestions_made' && r.under_auto_approve === true
    })
    .map((r) => String(r.id))
    .sort()
  const inFlightKey = inFlightIds.join(',')

  useEffect(() => {
    if (inFlightKey === '') return  // nothing to watch — no timer

    let stopped       = false
    let timer: ReturnType<typeof setTimeout> | null = null
    let intervalMs    = POLL_BASE_MS
    let unchangedRuns = 0

    const snapshotRef = new Map<string, string>()
    // Snapshot includes both aity_status and under_auto_approve so a workspace
    // auto-approve flipping from running→done triggers a patch even when
    // aity_status hasn't changed yet (state suggestions_made → bronze allowed).
    const snapshotKey = (status: string | null | undefined, under: boolean | undefined) =>
      `${status ?? ''}|${under ? '1' : '0'}`
    for (const r of resources) snapshotRef.set(String(r.id), snapshotKey(r.aity_status, r.under_auto_approve))

    const tick = async () => {
      if (stopped) return
      if (typeof document !== 'undefined' && document.hidden) {
        // Tab is in the background — defer to whenever it becomes visible again.
        timer = setTimeout(tick, intervalMs)
        return
      }

      // Don't poll while logged out — the request would just return 401 and
      // pollute the console / server log. Reschedule so we pick up again once
      // the user signs in.
      if (!authService.isAuthenticated()) {
        timer = setTimeout(tick, intervalMs)
        return
      }

      const rows = await resourceService.getAityStatusBulk(inFlightIds)

      const patches: Array<{ id: string; aity_status: string | null; updated_at: string | null; under_auto_approve?: boolean }> = []
      for (const row of rows) {
        const prev = snapshotRef.get(String(row.id))
        const next = snapshotKey(row.aity_status, row.under_auto_approve)
        if (prev !== next) {
          patches.push(row)
          snapshotRef.set(String(row.id), next)
        }
      }

      if (patches.length > 0) {
        onPatchRef.current(patches)
        unchangedRuns = 0
        intervalMs    = POLL_BASE_MS
      } else {
        unchangedRuns++
        if (unchangedRuns >= BACKOFF_THRESHOLD) {
          intervalMs = Math.min(intervalMs * 2, POLL_MAX_MS)
        }
      }

      if (stopped) return
      timer = setTimeout(tick, intervalMs)
    }

    // First tick on a small delay so we don't fire mid-render.
    timer = setTimeout(tick, POLL_BASE_MS)

    return () => {
      stopped = true
      if (timer !== null) clearTimeout(timer)
    }
  // We intentionally restart only when the in-flight id set changes — not on
  // every render. `resources` is read inside but only used to seed the snapshot;
  // subsequent polls compare against snapshotRef, not the (closure-captured)
  // array.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inFlightKey])
}

export default useAityStatusSync
