import { Box, Chip, Tooltip } from '@mui/material'
import AddIcon from '@mui/icons-material/Add'
import CheckIcon from '@mui/icons-material/Check'
import { getEntityColor } from '../../constants/entityTypes'

interface Props {
  label: string
  type?: string
  description?: string | null
  confidence?: number
  accepted: boolean
  onAccept: () => void
  /** Called when the chip is clicked while already accepted — deselects the tag. */
  onReject?: () => void
  /** Filename of the source file — shown in tooltip when multiple files contribute tags. */
  sourceLabel?: string
  /** Show as non-interactive preview chip (no click, no icon). */
  disabled?: boolean
}

/**
 * A single AI-suggested tag chip. Shows a left-border accent by type,
 * an Add icon (pending) or Check icon (accepted).
 * Context-agnostic — works in wizard, batch carousel, and detail modal.
 */
export function SuggestionChip({ label, type, description, confidence, accepted, onAccept, onReject, sourceLabel, disabled = false }: Props) {
  const accentColor = getEntityColor(type) ?? getEntityColor('tag')!

  const confidencePct = confidence !== undefined ? Math.round(confidence * 100) : null
  const confidenceColor = confidencePct === null ? undefined
    : confidencePct >= 80 ? '#2e7d32'   // green
    : confidencePct >= 60 ? '#e65100'   // amber
    : '#c62828'                          // red

  const tooltipParts = [description, sourceLabel ? `from: ${sourceLabel}` : null].filter(Boolean)
  const tooltipTitle = tooltipParts.join(' · ')

  const handleClick = disabled
    ? undefined
    : accepted
      ? (onReject ?? undefined)
      : onAccept

  const chipLabel = (
    <Box component="span" sx={{ display: 'flex', alignItems: 'baseline', gap: 0.5 }}>
      <span>{label}</span>
      {confidencePct !== null && (
        <Box component="span" sx={{ fontSize: '0.6rem', color: confidenceColor, fontWeight: 600, lineHeight: 1 }}>
          {confidencePct}%
        </Box>
      )}
    </Box>
  )

  return (
    <Tooltip title={tooltipTitle} placement="top" disableHoverListener={tooltipParts.length === 0}>
      <Chip
        size="small"
        label={chipLabel}
        onClick={handleClick}
        icon={disabled ? undefined : (
          accepted
            ? <CheckIcon sx={{ fontSize: '14px !important', color: 'success.main' }} />
            : <AddIcon sx={{ fontSize: '14px !important' }} />
        )}
        sx={{
          borderStyle: 'dashed',
          borderWidth: 1,
          borderColor: 'divider',
          borderLeft: `3px solid ${accentColor}`,
          borderRadius: '4px',
          opacity: disabled ? 0.65 : accepted ? 0.75 : 1,
          cursor: disabled ? 'default' : 'pointer',
          '& .MuiChip-label': { fontSize: '0.72rem' },
        }}
      />
    </Tooltip>
  )
}
