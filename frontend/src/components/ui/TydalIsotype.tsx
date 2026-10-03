type TydalIsotypeVariant = 'brand' | 'brand-active' | 'white'

interface TydalIsotypeProps {
  size?: number
  /** 'brand'        — the logo's three blues, for outlined buttons and light surfaces (default).
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
  // The logo's balls exactly (sRGB, 2026-10): large / medium (also "DAL") / small.
  brand: {
    large:  '#1A5D7D',
    medium: '#287C9C',
    small:  '#5295B3',
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

// The brand trio one step darker, still the logo's own colours (the wordmark's
// #0A5475, then the large and medium balls), used only while the plain 'brand'
// variant is pulsing (AITY queued, or under-auto-approve overlay) so the pulse
// reads against light surfaces. Brand-active has its own palette in COLORS
// above and does not use this override.
const BRAND_PULSING: { large: string; medium: string; small: string } = {
  large:  '#0A5475',
  medium: '#1A5D7D',
  small:  '#287C9C',
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
 * The three overlapping circles from the TYDAL logo isotype, at the logo's own
 * geometry: radii 75/60/45 with centres 120 and 90 apart, scaled
 * by 0.06 into the 20 × 10 box (radii 4.5/3.6/2.7, centres 15.3/8.1/2.7), so
 * the small ball touches the left edge and the large one ends at 19.8.
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

  // Pulse sweep reads left → right: small (cx=2.7) first, then medium (cx=8.1),
  // then large (cx=15.3).
  return (
    <svg
      width={size}
      height={size * 0.5}
      // A hair of margin (same 2:1 shape) so the breathing balls' peak (r × 1.07)
      // stays inside: the outer balls reach -0.19 and 20.12 at full breath.
      viewBox="-0.25 -0.125 20.5 10.25"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      style={opacity !== undefined ? { opacity } : undefined}
    >
      <circle cx="15.3" cy="5" r="4.5" fill={tones.large}  fillOpacity={stops.large}>
        {animateOpacity(pulseCfg.stagger.large,  stops.large)}
        {animateRadius (pulseCfg.stagger.large,  4.5)}
      </circle>
      <circle cx="8.1"  cy="5" r="3.6" fill={tones.medium} fillOpacity={stops.medium}>
        {animateOpacity(pulseCfg.stagger.medium, stops.medium)}
        {animateRadius (pulseCfg.stagger.medium, 3.6)}
      </circle>
      <circle cx="2.7"  cy="5" r="2.7" fill={tones.small}  fillOpacity={stops.small}>
        {animateOpacity(pulseCfg.stagger.small,  stops.small)}
        {animateRadius (pulseCfg.stagger.small,  2.7)}
      </circle>
    </svg>
  )
}

export default TydalIsotype
