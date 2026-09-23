import TydalIsotype from '../ui/TydalIsotype'

interface AityPulseProps {
  /**
   * When true, render the brand-active TYDAL isotype with its breathing pulse
   * (AITY actively processing) — the same indicator the dashboard resource cards
   * use. When false, render the static brand isotype.
   */
  active: boolean
  /** Pixel width of the isotype. Default 18 (the mark is a 2:1 wide three-ball). */
  size?: number
  /** Dim the static (inactive) isotype — e.g. the inert "no AI" resting state. */
  dim?: boolean
}

/**
 * AITY status indicator — the TYDAL three-ball isotype. Pulses (brand-active,
 * breathing) while work is in flight, static brand at rest. Reuses `TydalIsotype`
 * so it matches the dashboard resource-card indicator exactly. Shared across the
 * AITY hub button, the status accordion header, and per-file rows.
 */
export function AityPulse({ active, size = 18, dim = false }: AityPulseProps) {
  return (
    <TydalIsotype
      size={size}
      variant={active ? 'brand-active' : 'brand'}
      pulsing={active}
      opacity={!active && dim ? 0.4 : undefined}
    />
  )
}

export default AityPulse
