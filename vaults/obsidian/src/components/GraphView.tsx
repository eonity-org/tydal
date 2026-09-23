/**
 * The whole vault as a force graph — every projected note a node, every
 * both-ends-projected relation an edge. Click a node to open its note;
 * the active note and its neighborhood are highlighted.
 */
import { useMemo } from 'react'
import type { VaultGraph, VaultResourceCard } from '@tydal/client'
import type { SlotMap } from '../presentation'
import { layoutGraph } from '../graph'

export function GraphView({
  graph,
  slots,
  activeId,
  onOpen,
}: {
  graph: VaultGraph
  slots: SlotMap
  activeId: string | null
  onOpen: (card: VaultResourceCard) => void
}) {
  const layout = useMemo(() => layoutGraph(graph), [graph])
  const byId = useMemo(() => new Map(graph.nodes.map((n) => [n.id, n])), [graph])
  const posById = useMemo(() => new Map(layout.nodes.map((p) => [p.id, p])), [layout])

  const neighborhood = useMemo(() => {
    const ids = new Set<string>()
    if (activeId) {
      ids.add(activeId)
      for (const e of graph.edges) {
        if (e.source === activeId) ids.add(e.target)
        if (e.target === activeId) ids.add(e.source)
      }
    }
    return ids
  }, [graph, activeId])

  const dimmed = (id: string) => activeId !== null && !neighborhood.has(id)

  return (
    <svg
      className="graph"
      viewBox={`0 0 ${layout.width} ${layout.height}`}
      role="img"
      aria-label="Vault graph"
    >
      {graph.edges.map((e, i) => {
        const a = posById.get(e.source)
        const b = posById.get(e.target)
        if (!a || !b) return null
        const active = activeId !== null && (e.source === activeId || e.target === activeId)
        return (
          <line
            key={i}
            x1={a.x}
            y1={a.y}
            x2={b.x}
            y2={b.y}
            className={`edge ${e.origin} ${active ? 'active' : ''} ${dimmed(e.source) || dimmed(e.target) ? 'dim' : ''}`}
          />
        )
      })}

      {layout.nodes.map((p) => {
        const card = byId.get(p.id)
        if (!card) return null
        return (
          <g
            key={p.id}
            transform={`translate(${p.x}, ${p.y})`}
            className={`node ${p.id === activeId ? 'active' : ''} ${dimmed(p.id) ? 'dim' : ''}`}
            onClick={() => onOpen(card)}
            tabIndex={0}
            role="button"
            aria-label={slots.label(card)}
            onKeyDown={(e) => e.key === 'Enter' && onOpen(card)}
          >
            <circle r={p.r} />
            <text y={p.r + 14}>{slots.label(card)}</text>
          </g>
        )
      })}
    </svg>
  )
}
