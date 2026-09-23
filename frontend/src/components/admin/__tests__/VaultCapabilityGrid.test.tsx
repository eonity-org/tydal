/**
 * Tests for VaultCapabilityGrid — the vault capability matrix editor.
 *
 * The behaviour that matters: a value the operator has not touched reads from
 * the purpose preset and says so, and clearing an override restores the preset
 * rather than pinning a falsy value (which is what the backend's `?? preset`
 * resolution expects).
 */

import { useState } from 'react'
import { describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import VaultCapabilityGrid from '../VaultCapabilityGrid'
import type { VaultCapabilityDef, VaultCapabilityKey } from '../../../api/vaultService'

const CAPABILITIES: VaultCapabilityDef[] = [
  { key: 'allow_binary', label: 'Expose binaries (Tier 2)', group: 'read_tiers' },
  { key: 'chunk_roles', label: 'File roles that feed chunks', group: 'projection' },
]

// The `ai` preset — chunk-first, binary off.
const PRESET = {
  allow_binary: false,
  chunk_roles: ['canonical', 'component', 'supporting'],
} as unknown as Record<VaultCapabilityKey, unknown>

function setup(value: Record<string, unknown> | null = null) {
  const onChange = vi.fn()
  render(
    <VaultCapabilityGrid
      capabilities={CAPABILITIES}
      preset={PRESET}
      value={value}
      onChange={onChange}
    />,
  )
  return { onChange, user: userEvent.setup() }
}

describe('VaultCapabilityGrid', () => {
  it('shows preset provenance when nothing is overridden', () => {
    setup()

    expect(screen.getAllByText('preset')).toHaveLength(2)
    expect(screen.queryByText('override')).toBeNull()
  })

  it('offers the preset value as the default option', async () => {
    const { user } = setup()

    await user.click(screen.getAllByRole('combobox')[0])

    // The `ai` preset denies binary, so the fallback option must say so.
    expect(within(screen.getByRole('listbox')).queryByText('Preset (denied)')).not.toBeNull()
  })

  it('emits an override when a capability is set', async () => {
    const { onChange, user } = setup()

    await user.click(screen.getAllByRole('combobox')[0])
    await user.click(within(screen.getByRole('listbox')).getByText('Follow vault state'))

    // The ImageLab case: an `ai` vault that must hand out source pixels.
    expect(onChange).toHaveBeenCalledWith({ allow_binary: 'inherit' })
  })

  it('offers the key level, which the boolean model could not express', async () => {
    const { onChange, user } = setup()

    await user.click(screen.getAllByRole('combobox')[0])
    await user.click(within(screen.getByRole('listbox')).getByText('Key required'))

    expect(onChange).toHaveBeenCalledWith({ allow_binary: 'key' })
  })

  it('normalises a legacy boolean override onto a level', async () => {
    // Policies written before levels existed store `true`/`false`; they must
    // render as inherit/denied rather than as an empty control.
    setup({ allow_binary: true })

    await userEvent.setup().click(screen.getAllByRole('combobox')[0])

    const selected = within(screen.getByRole('listbox'))
      .getByText('Follow vault state')
      .closest('[role="option"]')
    expect(selected?.getAttribute('aria-selected')).toBe('true')
  })

  it('marks an overridden row and clears it back to the preset', async () => {
    const { onChange, user } = setup({ allow_binary: true })

    expect(screen.queryByText('override')).not.toBeNull()

    await user.click(screen.getByRole('button', { name: 'Reset' }))

    // Dropping the last override empties the document entirely, so the vault
    // runs on its preset again rather than storing `{}`.
    expect(onChange).toHaveBeenCalledWith(null)
  })

  it('keeps other overrides when one row is reset', async () => {
    const { onChange, user } = setup({ allow_binary: true, chunk_roles: ['canonical'] })

    await user.click(screen.getAllByRole('button', { name: 'Reset' })[0])

    expect(onChange).toHaveBeenCalledWith({ chunk_roles: ['canonical'] })
  })

  it('parses a comma separated list override', async () => {
    // Driven through real state: the grid is controlled, so accumulating text
    // only works when the parent feeds each change back.
    const user = userEvent.setup()
    const seen: (Record<string, unknown> | null)[] = []

    function Harness() {
      const [policy, setPolicy] = useState<Record<string, unknown> | null>(null)
      return (
        <VaultCapabilityGrid
          capabilities={CAPABILITIES}
          preset={PRESET}
          value={policy}
          onChange={(next) => { seen.push(next); setPolicy(next) }}
        />
      )
    }

    render(<Harness />)
    await user.type(screen.getByPlaceholderText('canonical, component, supporting'), 'canonical, component')

    expect(seen[seen.length - 1]).toEqual({ chunk_roles: ['canonical', 'component'] })
  })

  it('resets every row at once', async () => {
    const { onChange, user } = setup({ allow_binary: true, chunk_roles: ['canonical'] })

    await user.click(screen.getByRole('button', { name: 'Reset all to preset' }))

    expect(onChange).toHaveBeenCalledWith(null)
  })
})
