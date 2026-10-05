/**
 * Tests for the Archived page (#25): it lists archived resources and sets one
 * back to live through the basket's bulk state endpoint.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import ArchivedPage from '../ArchivedPage'
import { usePermissions } from '../../hooks/usePermissions'

vi.mock('../../hooks/usePermissions', () => ({ usePermissions: vi.fn() }))
vi.mock('../../components/layout/Header', () => ({ default: () => null }))
const { getArchivedResources, bulkState } = vi.hoisted(() => ({
  getArchivedResources: vi.fn(),
  bulkState: vi.fn(),
}))
vi.mock('../../api/resourceService', () => ({
  default: { getArchivedResources, bulkState },
}))

const archived = {
  id: '01890000-0000-7000-8000-000000000001',
  name: 'Harbour at dawn',
  type: 'image',
  state: 'archived',
  updated_at: '2026-10-01T10:00:00Z',
  deleted_at: null,
}

const withPermissions = (permissions: string[]) =>
  vi.mocked(usePermissions).mockReturnValue({
    user: null,
    ready: true,
    can: (ability: string) => permissions.includes(ability),
  })

const renderPage = () => render(<MemoryRouter><ArchivedPage /></MemoryRouter>)

describe('ArchivedPage', () => {
  beforeEach(() => {
    getArchivedResources.mockReset().mockResolvedValue({
      data: [archived],
      total: 1,
      per_page: 48,
      current_page: 1,
      last_page: 1,
    })
    bulkState.mockReset().mockResolvedValue({
      success: true,
      requested: 1,
      applied: 1,
      skipped: [],
    })
  })

  it('lists archived resources', async () => {
    withPermissions(['resources.update'])
    renderPage()
    expect(await screen.findByText('Harbour at dawn')).not.toBeNull()
    expect(screen.getByText('1 archived resource')).not.toBeNull()
  })

  it('sets a resource live through bulkState', async () => {
    withPermissions(['resources.update'])
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Set live' }))

    await waitFor(() => expect(bulkState).toHaveBeenCalledWith([archived.id], 'live'))
    await waitFor(() => expect(screen.queryByText('Harbour at dawn')).toBeNull())
  })

  it('keeps a skipped resource listed', async () => {
    withPermissions(['resources.update'])
    bulkState.mockResolvedValue({
      success: true,
      requested: 1,
      applied: 0,
      skipped: [{ id: archived.id, reason: 'forbidden' }],
    })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Set live' }))

    expect(await screen.findByText(/was skipped/)).not.toBeNull()
    expect(screen.getByText('Harbour at dawn')).not.toBeNull()
  })

  it('offers no state changes to a viewer', async () => {
    withPermissions([])
    renderPage()
    expect(await screen.findByText('Harbour at dawn')).not.toBeNull()
    expect(screen.queryByRole('button', { name: 'Set live' })).toBeNull()
    expect(screen.queryByRole('button', { name: 'Set all live' })).toBeNull()
  })

  it('shows the empty state', async () => {
    withPermissions(['resources.update'])
    getArchivedResources.mockResolvedValue({
      data: [], total: 0, per_page: 48, current_page: 1, last_page: 1,
    })
    renderPage()
    expect(await screen.findByText('No archived resources.')).not.toBeNull()
  })
})
