/**
 * Tests for the basket page's "Save as workspace". Creating a workspace is
 * `workspaces.create` (administrators and owners, backend/config/permissions.php),
 * so an editor must not be offered a button that can only fail (issue #11).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import BasketPage from '../BasketPage'
import { usePermissions } from '../../hooks/usePermissions'

vi.mock('../../hooks/usePermissions', () => ({ usePermissions: vi.fn() }))
vi.mock('../../components/layout/Header', () => ({ default: () => null }))
vi.mock('../../components/basket/BulkActions', () => ({ default: () => null }))
vi.mock('../../contexts/BasketContext', () => ({
  useBasket: () => ({
    ids: ['r1'],
    count: 1,
    remove: vi.fn(),
    clear: vi.fn(),
    prune: vi.fn(),
    ready: true,
  }),
}))
vi.mock('../../api/resourceService', () => ({
  default: { getResource: vi.fn().mockResolvedValue({ id: 'r1', name: 'Harbour at dawn', state: 'live' }) },
}))
vi.mock('../../api/workspaceService', () => ({
  default: { createWorkspace: vi.fn(), bulkResourceMembership: vi.fn() },
}))

const withPermissions = (permissions: string[]) =>
  vi.mocked(usePermissions).mockReturnValue({
    user: null,
    ready: true,
    can: (ability: string) => permissions.some(
      (p) => p === ability || (p.endsWith('.*') && ability.startsWith(p.slice(0, -1))),
    ),
  })

const renderPage = () => render(<MemoryRouter><BasketPage /></MemoryRouter>)

describe('BasketPage — Save as workspace', () => {
  beforeEach(() => vi.mocked(usePermissions).mockReset())

  it('is offered to a role that can create workspaces', async () => {
    withPermissions(['workspaces.*'])
    renderPage()
    expect(await screen.findByRole('button', { name: 'Save as workspace' })).not.toBeNull()
  })

  it('is not offered to an editor', async () => {
    withPermissions(['workspaces.view', 'workspaces.manage-resources', 'resources.update'])
    renderPage()
    expect(await screen.findByRole('button', { name: 'Empty basket' })).not.toBeNull()
    expect(screen.queryByRole('button', { name: 'Save as workspace' })).toBeNull()
  })
})
