type TydalIsotypeVariant = 'brand' | 'brand-active' | 'white'

interface TydalIsotypeProps {
  size?: number
  /** 'brand'        — teal palette, for outlined buttons and light surfaces (default).
   *  'brand-active' — vivid cyan-teal, used for actively-working AITY indicators.
   *                   Pulses faster and "breathes" (radius grows slightly) so it
   *                   reads as live work, not a static state.
   *  'white'        — opacity-stepped white, for filled/dark-colored surfaces. */
  variant?: TydalIsotypeVariant
  /** Override colour applied to all three circles (with opacity stepping to keep
   *  the three-ball depth). Used as a status indicator, e.g. on resource cards. */
  color?: string
  /** Global opacity applied on top of the chosen variant/color. Useful for the
   *  inert "no AI" state on cards. */
  opacity?: number
  /** When true, the three circles pulse opacity in sequence — used to signal
   *  in-flight work (queued / analysing). The animation is purely visual; the
   *  underlying fillOpacity stops are preserved. */
  pulsing?: boolean
}

const COLORS: Record<TydalIsotypeVariant, { large: string; medium: string; small: string }> = {
  brand: {
    large:  '#1a5d7b',
    medium: '#1f7b92',
    small:  '#5299a9',
  },
  'brand-active': {
    // Cyan-leaning, more saturated than brand. Used for aity_in_progress so the
    // running indicator clearly differs from the static brand of user_review_done.
    large:  '#0a4f6e',
    medium: '#0e7ea0',
    small:  '#1cb0d2',
  },
  white: {
    large:  'rgba(255,255,255,0.45)',
    medium: 'rgba(255,255,255,0.72)',
    small:  'rgba(255,255,255,0.97)',
  },
}

// Darker / more saturated teal trio used only while the plain 'brand' variant is
// pulsing (AITY queued, or under-auto-approve overlay). Brand-active has its own
// palette in COLORS above and does not use this override.
const BRAND_PULSING: { large: string; medium: string; small: string } = {
  large:  '#134d68',
  medium: '#176d85',
  small:  '#2a8499',
}

// Per-variant pulse cadence. Active variants pulse faster and breathe (radius
// grows ~7% at the peak) to read as live activity.
const PULSE: Record<TydalIsotypeVariant, {
  duration: string
  stagger: { large: string; medium: string; small: string }
  grow: boolean
}> = {
  brand:          { duration: '1.6s', stagger: { large: '0.6s', medium: '0.3s', small: '0s' }, grow: false },
  'brand-active': { duration: '1.0s', stagger: { large: '0.4s', medium: '0.2s', small: '0s' }, grow: true  },
  white:          { duration: '1.6s', stagger: { large: '0.6s', medium: '0.3s', small: '0s' }, grow: false },
}

/**
 * The three overlapping circles from the TYDAL logo isotype.
 * Sizes follow the 5:4:3 ratio of the original brand mark (150/120/90).
 *
 * When `color` is supplied the three circles take that colour with descending
 * opacity (back → front: 0.55 / 0.78 / 1.00) so the depth reads identically to
 * the brand variant while encoding a status (queued, in-progress, etc.).
 *
 * When `pulsing` is true the circles cycle their opacity in sequence (back →
 * front, 0.3 s apart, full period 1.6 s) to read as "work in progress".
 */
function TydalIsotype({ size = 20, variant = 'brand', color, opacity, pulsing = false }: TydalIsotypeProps) {
  const tones = color
    ? { large: color, medium: color, small: color }
    : (pulsing && variant === 'brand' ? BRAND_PULSING : COLORS[variant])
  const stops = color
    ? { large: 0.55, medium: 0.78, small: 1.0 }
    : { large: 1.0, medium: 1.0, small: 1.0 }

  const pulseCfg = PULSE[variant]

  const animateOpacity = (begin: string, base: number) => pulsing ? (
    <animate
      attributeName="fill-opacity"
      values={`${base};1;${base * 0.4};${base}`}
      dur={pulseCfg.duration}
      begin={begin}
      repeatCount="indefinite"
    />
  ) : null

  // Optional radius "breath" — only on variants flagged grow=true (brand-active).
  // Grows ~7% at the peak and returns. Synced to the same begin/dur as opacity.
  const animateRadius = (begin: string, baseR: number) => pulsing && pulseCfg.grow ? (
    <animate
      attributeName="r"
      values={`${baseR};${(baseR * 1.07).toFixed(2)};${baseR}`}
      dur={pulseCfg.duration}
      begin={begin}
      repeatCount="indefinite"
    />
  ) : null

  // Pulse sweep reads left → right: small (cx=3.5) first, then medium (cx=8.5),
  // then large (cx=14.5).
  return (
    <svg
      width={size}
      height={size * 0.5}
      viewBox="0 0 20 10"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      style={opacity !== undefined ? { opacity } : undefined}
    >
      <circle cx="14.5" cy="5" r="4.5" fill={tones.large}  fillOpacity={stops.large}>
        {animateOpacity(pulseCfg.stagger.large,  stops.large)}
        {animateRadius (pulseCfg.stagger.large,  4.5)}
      </circle>
      <circle cx="8.5"  cy="5" r="3.6" fill={tones.medium} fillOpacity={stops.medium}>
        {animateOpacity(pulseCfg.stagger.medium, stops.medium)}
        {animateRadius (pulseCfg.stagger.medium, 3.6)}
      </circle>
      <circle cx="3.5"  cy="5" r="2.7" fill={tones.small}  fillOpacity={stops.small}>
        {animateOpacity(pulseCfg.stagger.small,  stops.small)}
        {animateRadius (pulseCfg.stagger.small,  2.7)}
      </circle>
    </svg>
  )
}

export default TydalIsotype
