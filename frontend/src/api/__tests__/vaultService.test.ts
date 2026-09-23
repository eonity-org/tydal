/**
 * Tests for vaultService
 */

import { describe, it, expect, beforeEach } from 'vitest'
import vaultService from '../vaultService'

const AUTH_COOKIE = 'JWT=mock-jwt-token-12345; path=/'

describe('vaultService', () => {
  beforeEach(() => {
    document.cookie = AUTH_COOKIE
  })

  // ── active.list ─────────────────────────────────────────────────────────────

  describe('active.list', () => {
    it('returns active Vaults for authenticated user', async () => {
      const res = await vaultService.active.list()

      expect(res.success).toBe(true)
      expect(res.data.vaults).toHaveLength(2)
      expect(res.data.vaults[0]).toMatchObject({
        id: 'vault-1',
        name: 'Public Vault',
        state: 'private',
      })
    })
  })

  // ── admin.list ──────────────────────────────────────────────────────────────

  describe('admin.list', () => {
    it('returns paginated Vaults', async () => {
      const res = await vaultService.admin.list()

      expect(res.success).toBe(true)
      expect(res.data.vaults).toHaveLength(1)
      expect(res.meta.pagination).toMatchObject({
        current_page: 1,
        total: 1,
        has_more: false,
      })
    })

    it('accepts optional search and pagination params', async () => {
      const res = await vaultService.admin.list({ page: 1, per_page: 10, search: 'vault' })

      expect(res.success).toBe(true)
      expect(res.data.vaults).toBeDefined()
    })
  })

  // ── admin.create ─────────────────────────────────────────────────────────────

  describe('admin.create', () => {
    it('creates a new Vault and returns it', async () => {
      const payload = {
        organization_id: 'org-1',
        name: 'New Vault',
        slug: 'new-vault',
        purpose: 'delivery' as const,
        state: 'private' as const,
        salt: 'secret',
        has_public_workspace: false,
        is_downloadable: true,
        hash_ttl_hours: null,
        allowed_ips: [],
      }

      const res = await vaultService.admin.create(payload)

      expect(res.success).toBe(true)
      expect(res.data.vault).toMatchObject({ id: 'vault-new', name: 'New Vault' })
    })
  })

  // ── admin.update ─────────────────────────────────────────────────────────────

  describe('admin.update', () => {
    it('updates an existing Vault', async () => {
      const res = await vaultService.admin.update('vault-1', { name: 'Updated Vault' })

      expect(res.success).toBe(true)
      expect(res.data.vault).toMatchObject({ id: 'vault-1', name: 'Updated Vault' })
    })
  })

  // ── admin.delete ─────────────────────────────────────────────────────────────

  describe('admin.delete', () => {
    it('deletes a Vault', async () => {
      const res = await vaultService.admin.delete('vault-1')

      expect(res.success).toBe(true)
    })
  })

  // ── workspace.listVaults ────────────────────────────────────────────────────────

  describe('workspace.listVaults', () => {
    it('returns Vaults associated with a workspace', async () => {
      const res = await vaultService.workspace.listVaults(42)

      expect(res.success).toBe(true)
      expect(res.data.vaults).toHaveLength(1)
      expect(res.data.vaults[0].id).toBe('vault-1')
    })
  })

  // ── workspace.attach ──────────────────────────────────────────────────────────

  describe('workspace.attach', () => {
    it('attaches a Vault to a workspace', async () => {
      const res = await vaultService.workspace.attach(42, 'vault-1')

      expect(res.success).toBe(true)
    })
  })

  // ── workspace.detach ──────────────────────────────────────────────────────────

  describe('workspace.detach', () => {
    it('detaches a Vault from a workspace', async () => {
      const res = await vaultService.workspace.detach(42, 'vault-1')

      expect(res.success).toBe(true)
    })
  })

  // ── links.forResource ────────────────────────────────────────────────────────

  describe('links.forResource', () => {
    it('returns resource and file Vault links', async () => {
      const res = await vaultService.links.forResource('resource-abc')

      expect(res.success).toBe(true)
      expect(res.data.resource.name).toBe('Test Resource')
      expect(res.data.resource.links).toHaveLength(1)
      expect(res.data.resource.links[0]).toMatchObject({
        vault_name: 'Public Vault',
        workspace_name: 'Main Workspace',
        hash: 'Ab3xZ9Kp',
        is_expired: false,
      })
    })

    it('returns file-level links with file metadata', async () => {
      const res = await vaultService.links.forResource('resource-abc')

      expect(res.data.files).toHaveLength(1)
      expect(res.data.files[0]).toMatchObject({
        filename: 'photo.jpg',
        mime_type: 'image/jpeg',
      })
      expect(res.data.files[0].links).toHaveLength(1)
      expect(res.data.files[0].links[0].hash).toBe('Cd5yW2Lm')
    })

    it('returns empty links when resource has no Vault associations', async () => {
      const res = await vaultService.links.forResource('resource-no-links')

      expect(res.success).toBe(true)
      expect(res.data.resource.links).toHaveLength(0)
      expect(res.data.files).toHaveLength(0)
    })

    it('throws on 404 for missing resource', async () => {
      await expect(vaultService.links.forResource('missing-resource')).rejects.toThrow()
    })
  })
})
