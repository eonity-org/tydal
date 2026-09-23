/**
 * Graph geometry + adjacency, pure and framework-free.
 *
 * The layout is a small force simulation (repulsion + edge springs +
 * centering) run to rest up front — a vault graph is hundreds of nodes at
 * most (the backend caps the projection), so a fixed tick budget lays it
 * out in milliseconds and the view stays static and calm, like a board of
 * pinned cards.
 */
import type { VaultGraph, VaultGraphEdge } from '@tydal/client'

export interface NodePosition {
  id: string
  x: number
  y: number
  /** Degree-scaled radius, for drawing. */
  r: number
}

export interface Layout {
  nodes: NodePosition[]
  width: number
  height: number
}

/** id → number of edges touching it. */
export function degrees(graph: Pick<VaultGraph, 'nodes' | 'edges'>): Map<string, number> {
  const out = new Map<string, number>()
  for (const n of graph.nodes) out.set(n.id, 0)
  for (const e of graph.edges) {
    out.set(e.source, (out.get(e.source) ?? 0) + 1)
    out.set(e.target, (out.get(e.target) ?? 0) + 1)
  }
  return out
}

/** Incoming edges per node — the backlinks index (who points at me). */
export function backlinksIndex(edges: VaultGraphEdge[]): Map<string, VaultGraphEdge[]> {
  const out = new Map<string, VaultGraphEdge[]>()
  for (const e of edges) {
    const list = out.get(e.target) ?? []
    list.push(e)
    out.set(e.target, list)
  }
  return out
}

/** Outgoing edges per node — forward links. */
export function outlinksIndex(edges: VaultGraphEdge[]): Map<string, VaultGraphEdge[]> {
  const out = new Map<string, VaultGraphEdge[]>()
  for (const e of edges) {
    const list = out.get(e.source) ?? []
    list.push(e)
    out.set(e.source, list)
  }
  return out
}

/**
 * Deterministic force layout (seeded ring start, fixed ticks) — same input,
 * same picture, no flicker between loads.
 */
export function layoutGraph(
  graph: Pick<VaultGraph, 'nodes' | 'edges'>,
  width = 900,
  height = 640,
  ticks = 220,
): Layout {
  const n = graph.nodes.length
  const deg = degrees(graph)
  const cx = width / 2
  const cy = height / 2

  const pos = graph.nodes.map((node, i) => {
    const angle = (2 * Math.PI * i) / Math.max(1, n)
    const ring = Math.min(width, height) * 0.35
    return {
      id: node.id,
      x: cx + ring * Math.cos(angle),
      y: cy + ring * Math.sin(angle),
      vx: 0,
      vy: 0,
      r: 6 + Math.min(10, (deg.get(node.id) ?? 0) * 1.5),
    }
  })
  const byId = new Map(pos.map((p) => [p.id, p]))

  const repulsion = 5200
  const spring = 0.035
  const springLength = 120
  const centering = 0.012
  const damping = 0.85

  for (let t = 0; t < ticks; t++) {
    for (let i = 0; i < pos.length; i++) {
      for (let j = i + 1; j < pos.length; j++) {
        const a = pos[i]
        const b = pos[j]
        const dx = a.x - b.x
        const dy = a.y - b.y
        const d2 = Math.max(64, dx * dx + dy * dy)
        const f = repulsion / d2
        const d = Math.sqrt(d2)
        a.vx += (dx / d) * f
        a.vy += (dy / d) * f
        b.vx -= (dx / d) * f
        b.vy -= (dy / d) * f
      }
    }

    for (const e of graph.edges) {
      const a = byId.get(e.source)
      const b = byId.get(e.target)
      if (!a || !b) continue
      const dx = b.x - a.x
      const dy = b.y - a.y
      const d = Math.max(1, Math.sqrt(dx * dx + dy * dy))
      const f = spring * (d - springLength) * (0.5 + (e.weight ?? 0.5))
      a.vx += (dx / d) * f
      a.vy += (dy / d) * f
      b.vx -= (dx / d) * f
      b.vy -= (dy / d) * f
    }

    for (const p of pos) {
      p.vx += (cx - p.x) * centering
      p.vy += (cy - p.y) * centering
      p.vx *= damping
      p.vy *= damping
      p.x += p.vx
      p.y += p.vy
    }
  }

  // Clamp into the frame with a margin for the drawn radius + label.
  for (const p of pos) {
    p.x = Math.min(width - 30, Math.max(30, p.x))
    p.y = Math.min(height - 30, Math.max(30, p.y))
  }

  return {
    nodes: pos.map(({ id, x, y, r }) => ({ id, x, y, r })),
    width,
    height,
  }
}
