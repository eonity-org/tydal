import { tydal } from './tydalClient'

export interface AppNotification {
  id: string
  type: string
  /** Free-form payload set by the backend Notification class. Renderers should
   *  treat keys defensively — different notification types have different shapes. */
  data: Record<string, unknown> & {
    title?: string
    message?: string
  }
  read_at: string | null
  created_at: string
}

export interface NotificationsResponse {
  notifications: AppNotification[]
  unread_count: number
}

class NotificationService {
  async getNotifications(): Promise<NotificationsResponse | null> {
    try {
      // This endpoint is a bare `{ data: { notifications, unread_count } }` (no
      // `success` key), so the SDK returns it whole — extract `.data`.
      const body = await tydal.notifications.list<{ data?: NotificationsResponse }>()
      return body?.data ?? null
    } catch {
      return null
    }
  }

  async markRead(notificationId: string): Promise<void> {
    try {
      await tydal.notifications.markRead(notificationId)
    } catch {
      // best-effort
    }
  }

  async markAllRead(): Promise<void> {
    try {
      await tydal.notifications.markAllRead()
    } catch {
      // best-effort
    }
  }
}

export default new NotificationService()
