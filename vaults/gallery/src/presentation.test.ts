import { describe, it, expect } from 'vitest'
import { buildSlotMap } from './presentation'
import type { VaultResourceCard } from '@tydal/client'

const card = (over: Partial<VaultResourceCard> = {}): VaultResourceCard => ({
  id: 'r1',
  name: 'Harbor at Dusk',
  slug: 'harbor-at-dusk',
  description: 'Oil on canvas.',
  resource_type: 'image',
  tags: ['harbor', 'sunset'],
  url: '/h/VH/LH',
  path: '/v/acme/expo/harbor-at-dusk',
  ...over,
})

const presentation = [
  {
    scheme: 's1',
    scheme_name: 'artworks',
    core: { caption: 'name', subcaption: 'description', image: 'snapshot', badge: 'tags' },
    fields: { technique: 'badge', year: 'detail', artist: 'credit', internal_ref: 'hidden' },
  },
]

describe('buildSlotMap', () => {
  const slots = buildSlotMap({ presentation })

  it('serves caption/subcaption from the built-ins', () => {
    expect(slots.caption(card())).toBe('Harbor at Dusk')
    expect(slots.subcaption(card())).toBe('Oil on canvas.')
    expect(slots.caption(card({ name: '' as never }))).toBe('(untitled)')
  })

  it('merges tag built-ins with badge-slot metadata', () => {
    const c = card({ metadata: { technique: 'oil', year: 1898 } })
    expect(slots.badges(c)).toEqual(['harbor', 'sunset', 'oil'])
  })

  it('collects detail-slot fields and credit, skipping absent values', () => {
    const c = card({ metadata: { year: 1898, artist: 'A. Painter' } })
    expect(slots.details(c)).toEqual([{ label: 'year', value: '1898' }])
    expect(slots.credit(c)).toBe('A. Painter')
    expect(slots.details(card())).toEqual([])
    expect(slots.credit(card())).toBeNull()
  })

  it('never surfaces hidden fields and exposes the facet vocabulary', () => {
    const c = card({ metadata: { internal_ref: 'LEAK-1', technique: 'oil' } })
    const everything = [
      slots.caption(c),
      slots.subcaption(c) ?? '',
      ...slots.badges(c),
      ...slots.details(c).map((d) => `${d.label}${d.value}`),
      slots.credit(c) ?? '',
    ].join(' ')
    expect(everything).not.toContain('LEAK-1')
    expect(slots.badgeFields).toEqual(['technique'])
  })

  it('first scheme wins on field collisions across blocks', () => {
    const two = buildSlotMap({
      presentation: [
        ...presentation,
        { scheme: 's2', scheme_name: 'docs', core: {}, fields: { technique: 'hidden', pages: 'detail' } },
      ],
    })
    expect(two.badgeFields).toEqual(['technique'])
    expect(two.details(card({ metadata: { pages: 12 } }))).toEqual([{ label: 'pages', value: '12' }])
  })
})
