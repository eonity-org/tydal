/**
 * Tests for EditableBasicInfo's lifecycle control. A resource's lifecycle is
 * `state` (draft · live · archived); the pre-state `active` switch, the
 * Visibility select and the Downloadable/Public/Featured switches read fields
 * nothing honours, so they must not come back (issue #8).
 */

import { describe, it, expect, vi } from 'vitest'
import { render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import EditableBasicInfo from '../EditableBasicInfo'
import type { ResourceData } from '../../../../api/resourceService'

const resource = (overrides: Partial<ResourceData> = {}): ResourceData => ({
  id: '0190a000-0000-7000-8000-000000000001',
  organization_id: 'org',
  collection_id: 1,
  user_owner_id: 'user',
  type: 'multimedia',
  name: 'Harbour at dawn',
  slug: null,
  description: null,
  state: 'live',
  metadata: {},
  payload: { downloadable: true, public: false, featured: false },
  published_at: null,
  created_at: '2026-10-01T00:00:00Z',
  updated_at: '2026-10-01T00:00:00Z',
  deleted_at: null,
  ...overrides,
}) as ResourceData

describe('EditableBasicInfo — state', () => {
  it('shows the state of a live resource, and none of the legacy controls', () => {
    render(<EditableBasicInfo resource={resource()} onChange={() => {}} />)

    expect(screen.getByRole('combobox', { name: 'State' }).textContent).toBe('Live')
    expect(screen.queryByText('Active Status')).toBeNull()
    expect(screen.queryByText(/Resource is (in)?active/)).toBeNull()
    expect(screen.queryByRole('combobox', { name: 'Visibility' })).toBeNull()
    expect(screen.queryByText('Visibility & Access')).toBeNull()
    expect(screen.queryByText('Downloadable')).toBeNull()
    expect(screen.queryByText('Featured')).toBeNull()
  })

  it('shows an archived resource as archived', () => {
    render(<EditableBasicInfo resource={resource({ state: 'archived' })} onChange={() => {}} />)
    expect(screen.getByRole('combobox', { name: 'State' }).textContent).toBe('Archived')
  })

  it('changes the state through onChange', async () => {
    const user = userEvent.setup()
    const onChange = vi.fn()
    render(<EditableBasicInfo resource={resource()} onChange={onChange} />)

    await user.click(screen.getByRole('combobox', { name: 'State' }))
    await user.click(within(screen.getByRole('listbox')).getByText('Archived'))

    expect(onChange).toHaveBeenCalledWith(expect.objectContaining({ state: 'archived' }))
  })

  it('offers no state for a draft, which saving publishes', () => {
    render(<EditableBasicInfo resource={resource({ state: 'draft' })} onChange={() => {}} />)
    expect(screen.queryByRole('combobox', { name: 'State' })).toBeNull()
  })
})
