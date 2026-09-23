import type { ReactNode } from 'react'
import { Stack, Typography } from '@mui/material'

/**
 * TYDAL's segmented control — one choice out of a short, fixed set.
 *
 * The shape is the toolbar idiom used by the grid/list view toggle and the
 * Date/Name/ID sort toggle: a bordered strip, hairline dividers between
 * segments, no gaps.
 *
 * How loudly the active segment is marked depends on what the choice does —
 * see `emphasis`.
 */

export interface SegmentedChoiceOption<T extends string> {
  value: T
  /** Text, an icon, or text plus an adornment (e.g. a sort-direction arrow). */
  label: ReactNode
  /** Required when the label is an icon with no readable text. */
  ariaLabel?: string
}

export interface SegmentedChoiceProps<T extends string> {
  value: T
  /**
   * Fires on every click, including on the already-active segment — the sort
   * toggle depends on that to flip direction when you re-pick the same key.
   */
  onChange: (value: T) => void
  options: Array<SegmentedChoiceOption<T>>
  /**
   * How loudly to mark the active segment.
   *
   * `subtle` (default) is the toolbar treatment — a `grey.100` fill. Right
   * when the control only changes how the same data is presented, sits in
   * view permanently, and its effect is visible in the result the moment you
   * click: view layout, sort order.
   *
   * `strong` tints the segment `primary.subtle` / `primary.main`, the pair
   * that already marks a selected row or a basket card. Use it where the
   * choice changes what a *different* control will do — the add/remove mode in
   * the bulk-action dialogs decides whether the confirm button attaches or
   * detaches. There a grey fill is genuinely ambiguous, since grey reads as
   * "disabled" at least as readily as "selected", and getting it wrong is not
   * something the result will immediately reveal.
   */
  emphasis?: 'subtle' | 'strong'
  disabled?: boolean
  /** Stretch to the container; otherwise the strip hugs its content. */
  fullWidth?: boolean
  /** Fixed width for every segment, so labels of differing length line up. */
  segmentWidth?: string | number
  'aria-label'?: string
}

export function SegmentedChoice<T extends string>({
  value,
  onChange,
  options,
  emphasis = 'subtle',
  disabled = false,
  fullWidth = false,
  segmentWidth,
  'aria-label': ariaLabel,
}: SegmentedChoiceProps<T>) {
  const strong = emphasis === 'strong'
  const activeBg = strong ? 'primary.subtle' : 'grey.100'
  const activeColor = strong ? 'primary.main' : 'text.primary'

  return (
    <Stack
      direction="row"
      role="radiogroup"
      aria-label={ariaLabel}
      sx={{
        border: '1px solid',
        borderColor: 'divider',
        borderRadius: 1,
        overflow: 'hidden',
        height: '2rem',
        width: fullWidth ? '100%' : 'auto',
        opacity: disabled ? 0.5 : 1,
      }}
    >
      {options.map((option, index) => {
        const active = option.value === value
        return (
          <Typography
            key={option.value}
            component="div"
            role="radio"
            aria-checked={active}
            aria-label={option.ariaLabel}
            aria-disabled={disabled || undefined}
            tabIndex={disabled ? -1 : 0}
            onClick={() => !disabled && onChange(option.value)}
            onKeyDown={(e) => {
              if (disabled) return
              if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault()
                onChange(option.value)
              }
            }}
            sx={{
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              gap: 0.5,
              flex: fullWidth ? 1 : '0 0 auto',
              width: segmentWidth,
              px: segmentWidth ? 0 : 1.5,
              height: '100%',
              cursor: disabled ? 'default' : 'pointer',
              userSelect: 'none',
              borderLeft: index > 0 ? '1px solid' : 'none',
              borderLeftColor: 'divider',
              bgcolor: active ? activeBg : 'background.paper',
              color: active ? activeColor : 'text.secondary',
              fontSize: '0.875rem',
              fontWeight: active && strong ? 600 : 400,
              transition: 'background-color 0.15s ease, color 0.15s ease',
              '&:hover': disabled ? undefined : { bgcolor: active ? activeBg : 'grey.100' },
              '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2 },
            }}
          >
            {option.label}
          </Typography>
        )
      })}
    </Stack>
  )
}

export default SegmentedChoice
