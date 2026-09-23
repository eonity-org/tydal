/**
 * Tests for notificationService — covers the generic notification shape that
 * replaced the old Aity-specific payload (see AppNotification.data typing in
 * notificationService.ts).
 */

import { describe, it, expect, beforeEach } from 'vitest'
import { http, HttpResponse } from 'msw'
import notificationService from '../notificationService'
import { server } from '../../test/mocks/server'

const AUTH_COOKIE = 'JWT=mock-jwt-token-12345; path=/'
const API_BASE_URL = 'http://api.test/api/v1'

describe('notificationService', () => {
  beforeEach(() => {
    document.cookie = AUTH_COOKIE
  })

  // ── getNotifications ────────────────────────────────────────────────────────

  describe('getNotifications', () => {
    it('returns the notifications list and unread count', async () => {
      const result = await notificationService.getNotifications()

      expect(result).not.toBeNull()
      expect(result?.unread_count).toBe(1)
      expect(result?.notifications).toHaveLength(2)
    })

    it('returns notifications with the expected generic shape', async () => {
      const result = await notificationService.getNotifications()

      const first = result?.notifications[0]
      expect(first).toMatchObject({
        id: expect.any(String),
        type: expect.any(String),
        data: expect.any(Object),
        read_at: null,
        created_at: expect.any(String),
      })
    })

    it('accepts any data shape (no Aity-specific fields required)', async () => {
      const result = await notificationService.getNotifications()

      // First notification uses title/message — the convention the Header
      // renderer falls back to.
      expect(result?.notifications[0].data).toMatchObject({
        title: 'A thing happened',
        message: 'Details about the thing',
      })

      // Second notification carries a completely different shape and is still
      // a valid AppNotification — proves the type is no longer locked to a
      // single payload.
      expect(result?.notifications[1].data).toMatchObject({
        custom_field: 'value',
      })
      expect(result?.notifications[1].data.title).toBeUndefined()
      expect(result?.notifications[1].data.message).toBeUndefined()
    })

    it('distinguishes read vs unread by the read_at field', async () => {
      const result = await notificationService.getNotifications()

      expect(result?.notifications[0].read_at).toBeNull()
      expect(result?.notifications[1].read_at).not.toBeNull()
    })

    it('returns null when the request fails', async () => {
      server.use(
        http.get(`${API_BASE_URL}/notifications`, () =>
          HttpResponse.json({ error: 'Server error' }, { status: 500 }),
        ),
      )

      const result = await notificationService.getNotifications()
      expect(result).toBeNull()
    })

    it('returns null without an auth token', async () => {
      document.cookie = 'JWT=; path=/; max-age=0'

      const result = await notificationService.getNotifications()
      expect(result).toBeNull()
    })
  })

  // ── markRead ────────────────────────────────────────────────────────────────

  describe('markRead', () => {
    it('resolves on a successful mark-read response', async () => {
      await expect(
        notificationService.markRead('notif-unread-1'),
      ).resolves.toBeUndefined()
    })

    it('swallows 404 errors silently (best-effort)', async () => {
      await expect(
        notificationService.markRead('missing'),
      ).resolves.toBeUndefined()
    })

    it('swallows network errors silently (best-effort)', async () => {
      server.use(
        http.post(`${API_BASE_URL}/notifications/:id/read`, () => HttpResponse.error()),
      )

      await expect(
        notificationService.markRead('notif-unread-1'),
      ).resolves.toBeUndefined()
    })
  })

  // ── markAllRead ─────────────────────────────────────────────────────────────

  describe('markAllRead', () => {
    it('resolves on a successful mark-all-read response', async () => {
      await expect(notificationService.markAllRead()).resolves.toBeUndefined()
    })

    it('swallows network errors silently (best-effort)', async () => {
      server.use(
        http.post(`${API_BASE_URL}/notifications/read-all`, () => HttpResponse.error()),
      )

      await expect(notificationService.markAllRead()).resolves.toBeUndefined()
    })
  })
})
