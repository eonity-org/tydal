import { useState, useEffect, useCallback, useRef } from 'react'
import notificationService, {
  type AppNotification,
} from '../api/notificationService'

const POLL_INTERVAL_MS = 30_000

export interface UseNotificationsResult {
  notifications: AppNotification[]
  unreadCount: number
  markRead: (id: string) => Promise<void>
  markAllRead: () => Promise<void>
  /** Re-fetch immediately (e.g. after opening the bell popover). */
  refresh: () => void
}

/**
 * Polls /api/v1/notifications every 30 seconds.
 * Pauses while the browser tab is hidden to avoid unnecessary requests.
 */
export function useNotifications(): UseNotificationsResult {
  const [notifications, setNotifications] = useState<AppNotification[]>([])
  const [unreadCount, setUnreadCount]     = useState(0)
  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const fetchOnce = useCallback(async () => {
    const result = await notificationService.getNotifications()
    if (result) {
      setNotifications(result.notifications)
      setUnreadCount(result.unread_count)
    }
  }, [])

  // Fetch immediately on mount, then on interval
  useEffect(() => {
    fetchOnce()
    timerRef.current = setInterval(() => {
      if (!document.hidden) fetchOnce()
    }, POLL_INTERVAL_MS)

    return () => {
      if (timerRef.current) clearInterval(timerRef.current)
    }
  }, [fetchOnce])

  const markRead = useCallback(async (id: string) => {
    await notificationService.markRead(id)
    setNotifications(prev =>
      prev.map(n => n.id === id ? { ...n, read_at: new Date().toISOString() } : n),
    )
    setUnreadCount(prev => Math.max(0, prev - 1))
  }, [])

  const markAllRead = useCallback(async () => {
    await notificationService.markAllRead()
    setNotifications(prev => prev.map(n => ({ ...n, read_at: n.read_at ?? new Date().toISOString() })))
    setUnreadCount(0)
  }, [])

  return { notifications, unreadCount, markRead, markAllRead, refresh: fetchOnce }
}
