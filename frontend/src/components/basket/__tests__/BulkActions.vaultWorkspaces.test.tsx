/**
 * The basket's Workspace dialog and vault-connected workspaces: changing the
 * members of a workspace a vault projects publishes or unpublishes, so it
 * takes `workspaces.manage-vault-resources` (admins and owners). Without it
 * the workspace is listed but disabled, with a hint.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, within } from '@testing-library/react'
import BulkActions from '../BulkActions'
import { usePermissions } from '../../../hooks/usePermissions'

vi.mock('../../../hooks/usePermissions', () => ({ usePermissions: vi.fn() }))
vi.mock('../../ui/SemanticTagPicker', () => ({ default: () => null }))
const { getWorkspaces } = vi.hoisted(() => ({ getWorkspaces: vi.fn() }))
vi.mock('../../../api/workspaceService', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../../api/workspaceService')>()
  return { ...actual, default: { getWorkspaces, bulkResourceMembership: vi.fn() } }
})
vi.mock('../../../api/resourceService', () => ({
  default: { bulkState: vi.fn(), bulkSemanticTags: vi.fn(), deleteResource: vi.fn() },
}))

const withPermissions = (permissions: string[]) =>
  vi.mocked(usePermissions).mockReturnValue({
    user: null,
    ready: true,
    can: (ability: string) => permissions.some(
      (p) => p === ability || (p.endsWith('.*') && ability.startsWith(p.slice(0, -1))),
    ),
  })

const openWorkspaceList = async () => {
  render(<BulkActions ids={['r1']} onChanged={vi.fn()} />)
  fireEvent.click(screen.getByRole('button', { name: 'Workspace' }))
  await screen.findByText(/A workspace is what a vault projects/)
  fireEvent.mouseDown(screen.getByRole('combobox'))
  return screen.findByRole('listbox')
}

describe('BulkActions — vault-connected workspaces', () => {
  beforeEach(() => {
    getWorkspaces.mockReset().mockResolvedValue([
      { id: '1', name: 'All Resources', is_default: true },
      { id: '2', name: 'Shortlist', vaults_count: 0 },
      { id: '3', name: 'Open Canopy', vaults_count: 1 },
    ])
  })

  it('shows a vault-connected workspace disabled to an editor', async () => {
    withPermissions(['workspaces.view', 'workspaces.manage-resources'])
    const list = await openWorkspaceList()
    const shared = within(list).getByRole('option', { name: 'Open Canopy' })
    const plain = within(list).getByRole('option', { name: 'Shortlist' })
    expect(shared.getAttribute('aria-disabled')).toBe('true')
    expect(plain.getAttribute('aria-disabled')).not.toBe('true')

    fireEvent.mouseOver(within(shared).getByText('Open Canopy'))
    expect(await screen.findByText('Shared through a vault — ask an administrator')).not.toBeNull()

    // Choosing it anyway changes nothing: the confirm button stays disabled.
    fireEvent.click(shared)
    expect((screen.getByRole('button', { name: 'Add 1' }) as HTMLButtonElement).disabled).toBe(true)
  })

  it('lets an administrator choose it', async () => {
    withPermissions(['workspaces.*'])
    const list = await openWorkspaceList()
    const shared = within(list).getByRole('option', { name: 'Open Canopy' })
    expect(shared.getAttribute('aria-disabled')).not.toBe('true')
    fireEvent.click(shared)
    expect((screen.getByRole('button', { name: 'Add 1' }) as HTMLButtonElement).disabled).toBe(false)
  })
})
