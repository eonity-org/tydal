/**
 * The basket's Workspace dialog when there is nothing to choose: the hint says
 * how to get a workspace, and what it says depends on whether the user may
 * create one (`workspaces.create`, administrators and owners).
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import BulkActions from '../BulkActions'
import { usePermissions } from '../../../hooks/usePermissions'

vi.mock('../../../hooks/usePermissions', () => ({ usePermissions: vi.fn() }))
vi.mock('../../ui/SemanticTagPicker', () => ({ default: () => null }))
const { getWorkspaces } = vi.hoisted(() => ({ getWorkspaces: vi.fn() }))
vi.mock('../../../api/workspaceService', () => ({
  default: { getWorkspaces, bulkResourceMembership: vi.fn() },
}))
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

const openWorkspaceDialog = async () => {
  render(<BulkActions ids={['r1']} onChanged={vi.fn()} />)
  fireEvent.click(screen.getByRole('button', { name: 'Workspace' }))
  expect(await screen.findByText(/No workspaces available/)).not.toBeNull()
}

describe('BulkActions — Workspace dialog with no workspaces', () => {
  beforeEach(() => {
    getWorkspaces.mockReset().mockResolvedValue([
      { id: '1', name: 'All Resources', is_default: true },
    ])
  })

  it('tells an editor to ask an administrator', async () => {
    withPermissions(['workspaces.view', 'workspaces.manage-resources'])
    await openWorkspaceDialog()
    expect(screen.getByText(/Ask an administrator to create one\./)).not.toBeNull()
    expect(screen.queryByText(/Manage workspaces/)).toBeNull()
  })

  it('points a role that can create workspaces at Manage workspaces', async () => {
    withPermissions(['workspaces.*'])
    await openWorkspaceDialog()
    expect(screen.getByText(/Create one in Manage workspaces \(⋮ after the last workspace tab\)\./)).not.toBeNull()
    expect(screen.queryByText(/Ask an administrator/)).toBeNull()
  })
})
