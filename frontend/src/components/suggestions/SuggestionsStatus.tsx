import { Box, CircularProgress, Typography } from '@mui/material'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline'
import HourglassEmptyIcon from '@mui/icons-material/HourglassEmpty'
import { SuggestionsStatus as Status } from '../../hooks/useAiSuggestionsPoller'

interface Props {
  status: Status
  /** Override the default "waiting" label. */
  waitingLabel?: string
}

/**
 * Compact status indicator shown above the SuggestionsPanel while AiTy is
 * processing. Renders nothing when status is 'idle'.
 */
export function SuggestionsStatus({ status, waitingLabel }: Props) {
  if (status === 'idle') return null

  const config = {
    waiting: {
      icon: <CircularProgress size={14} color="inherit" />,
      label: waitingLabel ?? 'Aity is analyzing your file…',
      color: 'text.secondary',
    },
    ready: {
      icon: <CheckCircleOutlineIcon sx={{ fontSize: 16 }} />,
      label: 'Aity suggestions ready',
      color: 'success.main',
    },
    timeout: {
      icon: <HourglassEmptyIcon sx={{ fontSize: 16 }} />,
      label: 'Analysis is taking longer than expected — check back later',
      color: 'warning.main',
    },
  }[status]

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.75, mb: 1 }}>
      <AutoAwesomeIcon sx={{ fontSize: 14, color: 'primary.main' }} />
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, color: config.color }}>
        {config.icon}
        <Typography variant="caption" color="inherit">
          {config.label}
        </Typography>
      </Box>
    </Box>
  )
}
