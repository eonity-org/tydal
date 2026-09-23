import { describe, it, expect } from 'vitest'
import { annotateAnswer } from './citations'
import type { VaultAskSource } from '@tydal/client'

const source = (name: string, url = `http://x/${name}`): VaultAskSource => ({
  resource_id: name,
  resource_name: name,
  slug: name.toLowerCase().replace(/\s+/g, '-'),
  url,
  pages: [],
})

describe('annotateAnswer', () => {
  it('weaves cited resource names into citation segments', () => {
    const segs = annotateAnswer(
      'According to Harbor at Dusk, the light fades slowly.',
      [source('Harbor at Dusk')],
    )
    expect(segs).toEqual([
      { kind: 'text', text: 'According to ' },
      { kind: 'citation', text: 'Harbor at Dusk', source: source('Harbor at Dusk') },
      { kind: 'text', text: ', the light fades slowly.' },
    ])
  })

  it('matches case-insensitively and prefers the longest name', () => {
    const long = source('Harbor at Dusk (study)')
    const short = source('Harbor at Dusk')
    const segs = annotateAnswer('See harbor at dusk (study) for details.', [short, long])
    expect(segs[1]).toMatchObject({ kind: 'citation', source: long })
  })

  it('escapes regex metacharacters in names', () => {
    const tricky = source('What? (v2) [final]')
    const segs = annotateAnswer('Read What? (v2) [final] first.', [tricky])
    expect(segs.filter((s) => s.kind === 'citation')).toHaveLength(1)
  })

  it('passes through answers with no matching sources', () => {
    expect(annotateAnswer('Nothing relevant found.', [source('Unmentioned')])).toEqual([
      { kind: 'text', text: 'Nothing relevant found.' },
    ])
    expect(annotateAnswer('', [source('X')])).toEqual([])
  })
})
