import { describe, it, expect } from 'vitest'
import { buildSlotMap } from './presentation'
import type { VaultResourceCard } from '@tydal/client'

const card = (over: Partial<VaultResourceCard> = {}): VaultResourceCard => ({
  id: 'r1',
  name: 'Alpha Note',
  slug: 'alpha-note',
  description: 'A first note',
  resource_type: 'document',
  tags: ['Ideas'],
  url: 'http://x/h/v/l',
  path: '/v/acme/notes/alpha-note',
  ...over,
})

const meta = {
  presentation: [
    {
      scheme: 's1',
      scheme_name: 'notes',
      core: {},
      fields: {
        title: 'node_label',
        author: 'property',
        year: 'property',
        abstract: 'body',
        see_also: 'link_source',
        internal_ref: 'hidden',
      },
    },
  ],
}

describe('obsidian slot map', () => {
  it('labels nodes from the node_label field, falling back to name', () => {
    const slots = buildSlotMap(meta)
    expect(slots.label(card({ metadata: { title: 'The Real Title' } }))).toBe('The Real Title')
    expect(slots.label(card())).toBe('Alpha Note')
    expect(slots.label(card({ name: null as unknown as string }))).toBe('(untitled)')
  })

  it('collects property fields in presentation order, skipping absent values', () => {
    const slots = buildSlotMap(meta)
    const props = slots.properties(card({ metadata: { year: 2024, internal_ref: 'X9' } }))
    expect(props).toEqual([{ label: 'year', value: '2024' }])
  })

  it('joins body fields into prose and never surfaces hidden fields', () => {
    const slots = buildSlotMap(meta)
    expect(slots.body(card({ metadata: { abstract: 'Some prose.', internal_ref: 'X9' } }))).toBe('Some prose.')
    expect(slots.body(card())).toBeNull()
  })

  it('exposes link_source values', () => {
    const slots = buildSlotMap(meta)
    expect(slots.linkSources(card({ metadata: { see_also: ['Beta', 'Gamma'] } }))).toEqual(['Beta, Gamma'])
  })

  it('first scheme wins on duplicate fields', () => {
    const slots = buildSlotMap({
      presentation: [
        ...meta.presentation,
        { scheme: 's2', scheme_name: 'other', core: {}, fields: { title: 'hidden' } },
      ],
    })
    expect(slots.label(card({ metadata: { title: 'Kept' } }))).toBe('Kept')
  })
})
