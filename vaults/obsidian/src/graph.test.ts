import { describe, it, expect } from 'vitest'
import { backlinksIndex, outlinksIndex, degrees, layoutGraph } from './graph'
import type { VaultGraph, VaultResourceCard } from '@tydal/client'

const node = (id: string): VaultResourceCard => ({
  id,
  name: id,
  slug: id,
  description: null,
  resource_type: 'document',
  tags: [],
  url: `http://x/h/v/${id}`,
  path: `/v/a/n/${id}`,
})

const edge = (source: string, target: string, weight = 1) => ({
  source,
  target,
  type: 'related',
  origin: 'manual',
  weight,
})

const graph: Pick<VaultGraph, 'nodes' | 'edges'> = {
  nodes: [node('a'), node('b'), node('c'), node('d')],
  edges: [edge('a', 'b'), edge('c', 'b'), edge('b', 'd')],
}

describe('adjacency', () => {
  it('indexes backlinks by target and outlinks by source', () => {
    const back = backlinksIndex(graph.edges)
    const out = outlinksIndex(graph.edges)

    expect(back.get('b')?.map((e) => e.source)).toEqual(['a', 'c'])
    expect(out.get('b')?.map((e) => e.target)).toEqual(['d'])
    expect(back.get('a')).toBeUndefined()
  })

  it('counts degree across both directions', () => {
    const deg = degrees(graph)
    expect(deg.get('b')).toBe(3)
    expect(deg.get('d')).toBe(1)
  })
})

describe('layout', () => {
  it('is deterministic and keeps every node inside the frame', () => {
    const one = layoutGraph(graph, 800, 600)
    const two = layoutGraph(graph, 800, 600)

    expect(one).toEqual(two)
    for (const p of one.nodes) {
      expect(p.x).toBeGreaterThanOrEqual(0)
      expect(p.x).toBeLessThanOrEqual(800)
      expect(p.y).toBeGreaterThanOrEqual(0)
      expect(p.y).toBeLessThanOrEqual(600)
    }
  })

  it('pulls connected nodes closer than the isolated pair', () => {
    const layout = layoutGraph(graph, 800, 600)
    const pos = new Map(layout.nodes.map((p) => [p.id, p]))
    const dist = (m: string, n: string) => {
      const a = pos.get(m)!
      const b = pos.get(n)!
      return Math.hypot(a.x - b.x, a.y - b.y)
    }

    // a–b share an edge; a–c do not (they only meet through b).
    expect(dist('a', 'b')).toBeLessThan(dist('a', 'c'))
  })
})
