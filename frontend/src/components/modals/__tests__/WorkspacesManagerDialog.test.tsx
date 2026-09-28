/**
 * Tests for WorkspacesManagerDialog's vault links — the workspace side of the
 * workspace ↔ vault association. The behaviour that matters: a link the vault
 * itself controls (TYDAL's VaultService::associationLock) is shown locked with
 * its reason, and clicking it never asks the backend to change it.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import WorkspacesManagerDialog from '../WorkspacesManagerDialog'
import vaultService from '../../../api/vaultService'

vi.mock('../../../api/vaultService', () => ({
  default: {
    active: { list: vi.fn() },
    workspace: { listVaults: vi.fn(), attach: vi.fn(), detach: vi.fn() },
  },
}))

const LOCK = "This is the vault's ingest target — uploads land here. Change the target first."

describe('WorkspacesManagerDialog — vault links', () => {
  beforeEach(() => {
    vi.mocked(vaultService.active.list).mockResolvedValue({
      success: true,
      data: {
        vaults: [
          { id: 'v1', name: 'First Frame', attach_lock: null },
          { id: 'v2', name: 'Press Kit', attach_lock: null },
        ],
      },
    } as never)
    vi.mocked(vaultService.workspace.listVaults).mockResolvedValue({
      success: true,
      data: { vaults: [{ id: 'v1', name: 'First Frame', association_lock: LOCK }] },
    } as never)
  })

  it('shows a link the vault controls as locked, and never toggles it', async () => {
    const user = userEvent.setup()
    render(
      <WorkspacesManagerDialog
        open
        onClose={() => {}}
        onWorkspacesChanged={() => {}}
        workspaces={[{ id: '7', name: 'Submissions' }]}
      />,
    )

    // Edit the workspace to reach its vault chips.
    const editButtons = await screen.findAllByRole('button')
    await user.click(editButtons.find(b => b.querySelector('[data-testid="EditIcon"]'))!)

    const locked = (await screen.findAllByText('First Frame')).pop()!
    await user.click(locked)
    expect(vaultService.workspace.detach).not.toHaveBeenCalled()

    await user.hover(locked)
    expect(await screen.findByText(LOCK)).not.toBeNull()

    // An unlocked vault still toggles as before.
    await user.click(screen.getByText('Press Kit'))
    expect(vaultService.workspace.attach).toHaveBeenCalledWith('7', 'v2')
  })
})
