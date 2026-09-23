/**
 * The slot engine — the ONLY place field meaning is resolved.
 *
 * An obsidian client never hardcodes scheme field names: the vault's `/meta`
 * ships a resolved `presentation` (scheme fields mapped to the obsidian
 * vocabulary: node_label / body / property / link_source / hidden). This
 * module turns that block into note accessors. Pure functions — unit-tested
 * without the DOM.
 */
import type { VaultMeta, VaultResourceCard } from '@tydal/client'

export interface SlotMap {
  /** The note's title on the graph and in lists: node_label field else name. */
  label(card: VaultResourceCard): string
  /** Property-slot fields present on the card, in presentation order. */
  properties(card: VaultResourceCard): Array<{ label: string; value: string }>
  /** Body-slot field values, joined in presentation order — note prose. */
  body(card: VaultResourceCard): string | null
  /** link_source fields — values that name other notes (wiki-style). */
  linkSources(card: VaultResourceCard): string[]
}

const str = (v: unknown): string | null =>
  v === null || v === undefined || v === '' ? null : Array.isArray(v) ? v.join(', ') : String(v)

/**
 * Union the presentation blocks (one per projected scheme, first wins) into
 * slot → field-name lists.
 */
export function buildSlotMap(meta: Pick<VaultMeta, 'presentation'>): SlotMap {
  const bySlot: Record<string, string[]> = {}
  const seen = new Set<string>()

  for (const block of meta.presentation ?? []) {
    for (const [field, slot] of Object.entries(block.fields)) {
      if (seen.has(field)) continue
      seen.add(field)
      ;(bySlot[slot] ??= []).push(field)
    }
  }

  const metaValues = (card: VaultResourceCard, slot: string): Array<{ label: string; value: string }> => {
    const out: Array<{ label: string; value: string }> = []
    for (const field of bySlot[slot] ?? []) {
      const value = str(card.metadata?.[field])
      if (value !== null) out.push({ label: field, value })
    }
    return out
  }

  return {
    label: (card) =>
      metaValues(card, 'node_label')[0]?.value ?? str(card.name) ?? '(untitled)',

    properties: (card) => metaValues(card, 'property'),

    body: (card) => {
      const parts = metaValues(card, 'body').map((d) => d.value)
      return parts.length > 0 ? parts.join('\n\n') : null
    },

    linkSources: (card) => metaValues(card, 'link_source').map((d) => d.value),
  }
}
