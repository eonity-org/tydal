/**
 * The slot engine — the ONLY place field meaning is resolved.
 *
 * A gallery client never hardcodes scheme field names: the vault's `/meta`
 * ships a resolved `presentation` (core slots from resource built-ins +
 * scheme fields mapped to the gallery vocabulary: caption / subcaption /
 * image / badge / detail / credit / hidden). This module turns that block
 * into card accessors. Pure functions — unit-tested without the DOM.
 */
import type { VaultMeta, VaultResourceCard } from '@tydal/client'

export interface SlotMap {
  caption(card: VaultResourceCard): string
  subcaption(card: VaultResourceCard): string | null
  /** Badge values: tag built-ins (when tags fill the badge core slot) + badge-slot metadata. */
  badges(card: VaultResourceCard): string[]
  /** Detail-slot fields present on the card, in presentation order. */
  details(card: VaultResourceCard): Array<{ label: string; value: string }>
  credit(card: VaultResourceCard): string | null
  /** Fields whose slot aggregates (badge) — the facet bar's vocabulary. */
  badgeFields: string[]
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
    // Core slots are built-ins by contract: caption←name, subcaption←description,
    // badge←tags. Field-mapped caption/subcaption (overlay overrides) win when present.
    caption: (card) =>
      metaValues(card, 'caption')[0]?.value ?? str(card.name) ?? '(untitled)',

    subcaption: (card) =>
      metaValues(card, 'subcaption')[0]?.value ?? str(card.description),

    badges: (card) => [
      ...(card.tags ?? []),
      ...metaValues(card, 'badge').map((d) => d.value),
    ],

    details: (card) => metaValues(card, 'detail'),

    credit: (card) => metaValues(card, 'credit')[0]?.value ?? null,

    badgeFields: bySlot['badge'] ?? [],
  }
}
