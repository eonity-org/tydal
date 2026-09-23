import { useEffect, useState } from 'react'
import authService, { type User } from '../api/authService'
import { can } from '../constants/roles'

/**
 * What the signed-in user may do, for deciding which controls to render.
 *
 * The rule this exists to serve: a control the user's ROLE will never grant is
 * hidden — a viewer is not shown a "New Resource" button that can only ever
 * fail. A control that is theirs but momentarily unavailable (no collection
 * selected, empty basket) stays visible and disabled with a reason, because
 * that is a precondition they can clear. Everything is still authorized
 * server-side; this only decides what is worth offering.
 *
 * Permissions are per-organization, so switching organization changes them.
 * Components already mounted have to hear about that — a cache that is merely
 * dropped would leave them holding the previous organization's answer, which
 * is why this is a subscribable store rather than a memoised promise.
 */

let cached: Promise<User | null> | null = null
const subscribers = new Set<() => void>()

function loadUser(): Promise<User | null> {
  if (!cached) {
    cached = authService.getUser()
      .then((res) => res?.data ?? null)
      .catch(() => null)
  }
  return cached
}

/**
 * Forget the cached user and make every mounted consumer re-read it.
 *
 * Called on organization switch. Without the notify half, MainContent kept the
 * role from the organization you just left: an owner arriving in their own
 * organization was still being treated as the viewer they were a moment ago.
 */
export function invalidatePermissions(): void {
  cached = null
  subscribers.forEach((notify) => notify())
}

export interface Permissions {
  user: User | null
  /** True once the user has been fetched; controls should not flash before then. */
  ready: boolean
  can: (ability: string) => boolean
}

export function usePermissions(): Permissions {
  const [user, setUser] = useState<User | null>(null)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    let cancelled = false

    const read = () => {
      void loadUser().then((u) => {
        if (cancelled) return
        setUser(u)
        setReady(true)
      })
    }

    read()

    const onInvalidated = () => {
      setReady(false)
      read()
    }
    subscribers.add(onInvalidated)

    return () => {
      cancelled = true
      subscribers.delete(onInvalidated)
    }
  }, [])

  return {
    user,
    ready,
    can: (ability: string) => can(user, ability),
  }
}
