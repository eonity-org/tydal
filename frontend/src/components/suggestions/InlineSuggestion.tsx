import { Box, Button, IconButton, Tooltip, Typography } from '@mui/material'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import CheckCircleIcon from '@mui/icons-material/CheckCircle'
import CloseIcon from '@mui/icons-material/Close'

interface InlineSuggestionProps {
  value: string
  multiline?: boolean
  onApply: () => void
  /** Called when the user dismisses the suggestion without applying it. */
  onDismiss?: () => void
  /** When multiple variants exist, show the source filename above the suggestion text. */
  sourceLabel?: string
  /** True when this suggestion is the one currently used by the resource field. */
  isApplied?: boolean
}

export function InlineSuggestion({ value, multiline = false, onApply, onDismiss, sourceLabel, isApplied = false }: InlineSuggestionProps) {
  return (
    <Box
      sx={{
        mt: 0.5,
        ml: '14px',
        pl: 1,
        py: 0.4,
        borderLeft: '2px solid',
        borderColor: 'primary.light',
        bgcolor: 'primary.50',
        borderRadius: '0 4px 4px 0',
      }}
    >
      {/* Row 1 — legend + source label + apply */}
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.4 }}>
          <Typography variant="caption" sx={{ color: 'primary.main', fontWeight: 700, lineHeight: 1.5 }}>
            Aity
          </Typography>
          <AutoAwesomeIcon sx={{ fontSize: 11, color: 'primary.main' }} />
          {isApplied && (
            <Tooltip title="Currently used by this field" arrow>
              <CheckCircleIcon sx={{ fontSize: 12, color: 'success.main', ml: 0.25, opacity: 0.85 }} />
            </Tooltip>
          )}
          {sourceLabel && (
            <Typography variant="caption" sx={{ color: 'text.disabled', fontSize: '0.65rem', ml: 0.5 }} noWrap>
              · {sourceLabel}
            </Typography>
          )}
        </Box>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.25, flexShrink: 0 }}>
          <Button
            size="small"
            variant="text"
            onClick={onApply}
            disabled={isApplied}
            sx={{ fontSize: '0.7rem', fontWeight: 600, minWidth: 0, px: 0.75, py: 0, lineHeight: 1.5 }}
          >
            {isApplied ? 'In use' : 'Apply suggestion'}
          </Button>
          {onDismiss && (
            <IconButton
              size="small"
              onClick={onDismiss}
              sx={{ p: 0.25, color: 'text.disabled', '&:hover': { color: 'text.secondary' } }}
            >
              <CloseIcon sx={{ fontSize: '0.85rem' }} />
            </IconButton>
          )}
        </Box>
      </Box>

      {/* Row 2 — suggested text */}
      <Typography
        variant="caption"
        sx={{
          color: 'text.secondary',
          fontStyle: 'italic',
          display: '-webkit-box',
          overflow: 'hidden',
          WebkitLineClamp: multiline ? 3 : 2,
          WebkitBoxOrient: 'vertical',
        }}
      >
        "{value}"
      </Typography>
    </Box>
  )
}
