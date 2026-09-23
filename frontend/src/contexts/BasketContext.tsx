import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import authService from '../api/authService'

/**
 * The Basket — the dashboard's one and only multi-select.
 *
 * Ticking a card in the grid or a row in the list puts a resource here, and it
 * stays: through paging, sorting, filters, the grid/list toggle and a change of
 * collection or workspace. That persistence is only safe because the basket is
 * *inspectable* — /basket lists what is in it, so "23 selected" is an inventory
 * you can open rather than a number you have to trust. A transient selection
 * that quietly survived a scope change would be a trap; a basket is not.
 *
 * It is deliberately client-side. The obvious server-side home would be a
 * workspace row (`workspaces.purpose` already models a non-editorial working
 * set for AITY review), but every write to `dam_resource_workspace` fires an
 * inline Elasticsearch reindex, and that pivot is what `workspace_vault`
 * projects publicly. Ticking a checkbox must not touch the graph that drives
 * vault exposure. Graduating a basket into a real workspace is an explicit act
 * instead — see the "Save as workspace" action on the basket page.
 *
 * Not called a Collection: that word is already a first-class TYDAL entity
 * (scheme + ES index) and reusing it here would be genuinely confusing.
 */

/** Matches the server-side cap on every bulk endpoint (BulkResourceIdsRequest::MAX_IDS). */
export const BASKET_CAPACITY = 200

interface BasketContextValue {
  /** Contents, in the order they were added. */
  ids: string[]
  count: number
  has: (id: string) => boolean
  /** Add if absent, remove if present. Refuses to add past capacity. */
  toggle: (id: string) => void
  /** Add many at once (e.g. "select this page"), stopping at capacity. */
  add: (ids: string[]) => void
  remove: (id: string) => void
  /** Drop ids that no longer resolve — the basket page does this on load. */
  prune: (missingIds: string[]) => void
  clear: () => void
  isFull: boolean
  capacity: number
  /** False until the stored basket for this user+org has been read back. */
  ready: boolean
  /** Re-read who and where we are; call after switching organization. */
  rescope: () => void
}

const BasketContext = createContext<BasketContextValue>({
  ids: [],
  count: 0,
  has: () => false,
  toggle: () => {},
  add: () => {},
  remove: () => {},
  prune: () => {},
  clear: () => {},
  isFull: false,
  capacity: BASKET_CAPACITY,
  ready: false,
  rescope: () => {},
})

const storageKey = (orgId: string, userId: string) => `tydal-basket:${orgId}:${userId}`

function readStored(key: string): string[] {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    return parsed.filter((id): id is string => typeof id === 'string').slice(0, BASKET_CAPACITY)
  } catch {
    return []
  }
}

export function BasketProvider({ children }: { children: ReactNode }) {
  const [ids, setIds] = useState<string[]>([])
  const [scope, setScope] = useState<string | null>(null)
  const [ready, setReady] = useState(false)
  const [scopeNonce, setScopeNonce] = useState(0)
  // Guards the persist effect: without it, the first render (empty basket, no
  // scope yet) would write [] over whatever was stored.
  const loadedScope = useRef<string | null>(null)

  useEffect(() => {
    let cancelled = false

    const resolveScope = async () => {
      if (!authService.isAuthenticated()) {
        if (!cancelled) {
          setScope(null)
          setIds([])
          setReady(true)
        }
        return
      }

      try {
        const user = await authService.getUser()
        const userId = user?.data?.id
        const orgId = user?.data?.current_organization_id
        if (cancelled) return

        if (userId == null || orgId == null) {
          // Signed in but with no organization context — keep the basket in
          // memory rather than writing it to an ambiguous key.
          setScope(null)
          setReady(true)
          return
        }

        const key = storageKey(String(orgId), String(userId))
        loadedScope.current = key
        setScope(key)
        setIds(readStored(key))
      } catch {
        if (!cancelled) setScope(null)
      } finally {
        if (!cancelled) setReady(true)
      }
    }

    void resolveScope()
    return () => { cancelled = true }
  }, [scopeNonce])

  useEffect(() => {
    if (!scope || loadedScope.current !== scope) return
    try {
      localStorage.setItem(scope, JSON.stringify(ids))
    } catch {
      // A full or unavailable localStorage should cost the user their basket's
      // persistence, not the interaction they were in the middle of.
    }
  }, [ids, scope])

  const idSet = useMemo(() => new Set(ids), [ids])

  const has = useCallback((id: string) => idSet.has(String(id)), [idSet])

  const toggle = useCallback((id: string) => {
    const key = String(id)
    setIds((prev) => {
      if (prev.includes(key)) return prev.filter((existing) => existing !== key)
      if (prev.length >= BASKET_CAPACITY) return prev
      return [...prev, key]
    })
  }, [])

  const add = useCallback((incoming: string[]) => {
    setIds((prev) => {
      const seen = new Set(prev)
      const next = [...prev]
      for (const id of incoming) {
        const key = String(id)
        if (seen.has(key)) continue
        if (next.length >= BASKET_CAPACITY) break
        seen.add(key)
        next.push(key)
      }
      return next.length === prev.length ? prev : next
    })
  }, [])

  const remove = useCallback((id: string) => {
    const key = String(id)
    setIds((prev) => (prev.includes(key) ? prev.filter((existing) => existing !== key) : prev))
  }, [])

  const prune = useCallback((missingIds: string[]) => {
    if (missingIds.length === 0) return
    const gone = new Set(missingIds.map(String))
    setIds((prev) => {
      const next = prev.filter((id) => !gone.has(id))
      return next.length === prev.length ? prev : next
    })
  }, [])

  const clear = useCallback(() => setIds([]), [])

  const rescope = useCallback(() => {
    loadedScope.current = null
    setIds([])
    setReady(false)
    setScopeNonce((n) => n + 1)
  }, [])

  const value = useMemo<BasketContextValue>(() => ({
    ids,
    count: ids.length,
    has,
    toggle,
    add,
    remove,
    prune,
    clear,
    isFull: ids.length >= BASKET_CAPACITY,
    capacity: BASKET_CAPACITY,
    ready,
    rescope,
  }), [ids, has, toggle, add, remove, prune, clear, ready, rescope])

  return <BasketContext.Provider value={value}>{children}</BasketContext.Provider>
}

export const useBasket = () => useContext(BasketContext)
