/**
 * `notifications` namespace — `list` unwraps to `{ notifications, unread_count }`;
 * the mark-read calls are fire-and-forget (callers ignore the result).
 */
import type { Http } from './http.js'

export function notificationsNamespace(http: Http) {
  return {
    /** List notifications → unwraps to `{ notifications, unread_count }`. */
    list<T = unknown>(opts?: { signal?: AbortSignal }): Promise<T> {
      return http.get<T>('/notifications', { signal: opts?.signal })
    },

    /** Mark a single notification read. */
    markRead(notificationId: string): Promise<unknown> {
      return http.post(`/notifications/${notificationId}/read`)
    },

    /** Mark all notifications read. */
    markAllRead(): Promise<unknown> {
      return http.post('/notifications/read-all')
    },
  }
}

export type NotificationsNamespace = ReturnType<typeof notificationsNamespace>
