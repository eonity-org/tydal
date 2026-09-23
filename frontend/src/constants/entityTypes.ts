import { Person, CorporateFare, Place, Category, LocalOffer } from '@mui/icons-material'
import type { ComponentType } from 'react'

/**
 * The semantic entity vocabulary — what kind of thing a tag names.
 *
 * Colour ordering is hot → cold: person, organization, place, thing, and a
 * neutral grey for a plain tag.
 *
 * This used to live in `components/admin/SemanticTagsTab.tsx`, which caused two
 * problems. Architecturally it was the wrong home: the sidebar, resource cards,
 * the detail modal and the tag picker all need this vocabulary and none of them
 * is an admin screen. Practically, exporting non-component values alongside a
 * React component breaks Fast Refresh — every edit to that tab invalidated and
 * force-reloaded all six importers, which is how a stale module once made a
 * permission gate look broken during testing.
 *
 * Keep this file free of components so it stays cheap to import anywhere.
 * Mirrors the backend's ENTITY_TYPES in SemanticTagController.
 */
export const ENTITY_TYPES = {
  person: { label: 'Person', color: '#E42E3F', Icon: Person },
  organization: { label: 'Organization', color: '#DABF60', Icon: CorporateFare },
  place: { label: 'Place', color: '#3A9E6F', Icon: Place },
  thing: { label: 'Thing', color: '#4A7CC7', Icon: Category },
  tag: { label: 'Tag', color: '#757575', Icon: LocalOffer },
} as const

export type EntityTypeKey = keyof typeof ENTITY_TYPES

export function getEntityColor(entityType?: string | null): string | null {
  if (!entityType || !(entityType in ENTITY_TYPES)) return null
  return ENTITY_TYPES[entityType as EntityTypeKey].color
}

export function getEntityIcon(
  entityType?: string | null,
): ComponentType<{ sx?: Record<string, unknown> }> | null {
  if (!entityType || !(entityType in ENTITY_TYPES)) return null
  return ENTITY_TYPES[entityType as EntityTypeKey].Icon
}
