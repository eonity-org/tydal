/**
 * The connections panel: backlinks (notes pointing here) and forward
 * links, straight from the vault graph's edges — plus the edge's origin
 * (manual / tags / semantic) so a reader can tell curation from inference.
 */
import type { VaultGraphEdge, VaultResourceCard } from '@tydal/client'
import type { SlotMap } from '../presentation'

function LinkList({
  title,
  edges,
  endpoint,
  byId,
  slots,
  onOpen,
}: {
  title: string
  edges: VaultGraphEdge[]
  endpoint: 'source' | 'target'
  byId: Map<string, VaultResourceCard>
  slots: SlotMap
  onOpen: (card: VaultResourceCard) => void
}) {
  const items = edges
    .map((e) => ({ edge: e, card: byId.get(e[endpoint]) }))
    .filter((x): x is { edge: VaultGraphEdge; card: VaultResourceCard } => !!x.card)

  if (items.length === 0) return null

  return (
    <section>
      <h3>{title}</h3>
      <ul>
        {items.map(({ edge, card }) => (
          <li key={`${edge.source}-${edge.target}`}>
            <button onClick={() => onOpen(card)}>{slots.label(card)}</button>
            <small>{edge.origin}</small>
          </li>
        ))}
      </ul>
    </section>
  )
}

export function Backlinks({
  incoming,
  outgoing,
  byId,
  slots,
  onOpen,
}: {
  incoming: VaultGraphEdge[]
  outgoing: VaultGraphEdge[]
  byId: Map<string, VaultResourceCard>
  slots: SlotMap
  onOpen: (card: VaultResourceCard) => void
}) {
  return (
    <aside className="backlinks">
      <LinkList title="Linked from" edges={incoming} endpoint="source" byId={byId} slots={slots} onOpen={onOpen} />
      <LinkList title="Links to" edges={outgoing} endpoint="target" byId={byId} slots={slots} onOpen={onOpen} />
      {incoming.length === 0 && outgoing.length === 0 && (
        <p className="lonely">No connections yet.</p>
      )}
    </aside>
  )
}
