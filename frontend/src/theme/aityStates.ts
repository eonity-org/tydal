/**
 * Single source of truth for AITY state visuals — the pulsing isotype shown
 * on resource cards (grid + list), tooltips, and any future surface that needs
 * to render AITY pipeline status.
 *
 * Consumers must NOT redefine these colours locally. If a surface needs a tweak
 * (e.g. a softer palette in a dense list), add a variant here rather than
 * forking the table — that's how we ended up with three drifting copies before.
 */

export type AityStatus =
  | 'not_applicable'
  | 'queued'
  | 'aity_in_progress'
  | 'suggestions_made'
  | 'automatic_review_done'
  | 'user_review_done'

export interface AityStateStyle {
  /** Long-form tooltip text shown over the isotype. */
  tooltip: string
  /**
   * Colour for the three isotype circles. Can be:
   *   - a MUI palette path  ('info.main', 'success.main', 'text.disabled')
   *   - a raw CSS colour    ('#A77B45')
   *   - 'brand'             → native brand teal trio (auto punched up when pulsing)
   *   - 'brand-active'      → vivid cyan-teal trio with faster, breathing pulse;
   *                           reserved for actively-working AITY states.
   */
  palette: string
  /** Global SVG opacity. Used by the inert "no AI" state. */
  opacity?: number
  /** When true the three circles pulse — signals work in flight. */
  pulsing?: boolean
}

export const AITY_BRONZE = '#A77B45'

export const AITY_STATES: Record<AityStatus, AityStateStyle> = {
  not_applicable: {
    tooltip: 'No AI processing for this resource',
    palette: 'text.disabled',
    opacity: 0.6,
  },
  queued: {
    tooltip: 'Queued for AITY analysis',
    palette: 'brand',
    pulsing: true,
  },
  aity_in_progress: {
    tooltip: 'AITY is analysing this resource',
    // brand-active → vivid cyan-teal + faster/breathing pulse. Reads as "live work".
    palette: 'brand-active',
    pulsing: true,
  },
  suggestions_made: {
    tooltip: 'AITY suggestions awaiting review',
    palette: AITY_BRONZE,
  },
  automatic_review_done: {
    tooltip: 'AITY auto-reviewed',
    // Green → "machine-approved". A different hue family from brand teal so it
    // contrasts clearly with the user_review_done state.
    palette: 'success.main',
  },
  user_review_done: {
    tooltip: 'User reviewed',
    // Final human-blessed state → uses the native brand teal isotype (no pulse).
    palette: 'brand',
  },
}

/**
 * Overlay applied when a `suggestions_made` resource still belongs to a
 * workspace whose auto-approve job is queued or running. Renders as pulsing
 * brand teal so the user is not prompted to review something the job will
 * shortly consume on their behalf.
 */
export const AITY_UNDER_AUTO_APPROVE: AityStateStyle = {
  tooltip: 'Auto-approve in progress — review will not be required.',
  palette: 'brand',
  pulsing: true,
}

/**
 * Overlay applied when an in-flight resource (`queued` / `aity_in_progress`)
 * has not been updated in more than AITY_STALE_AFTER_MIN minutes — the worker
 * is presumed dead, so the indicator is forced into a dim "unknown" state
 * instead of pulsing indefinitely.
 */
export const AITY_STALE_AFTER_MIN = 30

export function aityStaleStyle(): AityStateStyle {
  return {
    tooltip: `Status unknown — last update over ${AITY_STALE_AFTER_MIN} min ago. Worker may have stopped.`,
    palette: 'text.disabled',
    opacity: 0.6,
  }
}

export function isAityStaleInFlight(
  status: string | undefined,
  updatedAt: string | undefined | null,
): boolean {
  if (!updatedAt) return false
  if (status !== 'queued' && status !== 'aity_in_progress') return false
  const ts = new Date(updatedAt).getTime()
  if (Number.isNaN(ts)) return false
  return Date.now() - ts > AITY_STALE_AFTER_MIN * 60 * 1000
}

/**
 * Resolve a MUI palette path like 'info.main' against a theme to a hex string
 * suitable for plain SVG fill attributes. Pass-through for raw colours.
 */
export function resolveAityColor(theme: { palette: Record<string, any> }, path: string): string {
  const segments = path.split('.')
  let cursor: any = theme.palette
  for (const seg of segments) {
    if (cursor == null) return path
    cursor = cursor[seg]
  }
  return typeof cursor === 'string' ? cursor : path
}

/**
 * Translate an AityStateStyle into the props expected by <TydalIsotype />.
 * Handles the two isotype sentinels ('brand', 'brand-active') by switching
 * variant rather than passing an explicit colour. Use this in any consumer that
 * renders an AITY status indicator so the sentinel knowledge stays in one place.
 */
export interface AityIsotypeProps {
  variant?: 'brand' | 'brand-active' | 'white'
  color?: string
  opacity?: number
  pulsing?: boolean
}

export function aityIsotypeProps(
  theme: { palette: Record<string, any> },
  cfg: AityStateStyle,
): AityIsotypeProps {
  if (cfg.palette === 'brand') {
    return { variant: 'brand',        opacity: cfg.opacity, pulsing: cfg.pulsing }
  }
  if (cfg.palette === 'brand-active') {
    return { variant: 'brand-active', opacity: cfg.opacity, pulsing: cfg.pulsing }
  }
  return { color: resolveAityColor(theme, cfg.palette), opacity: cfg.opacity, pulsing: cfg.pulsing }
}
