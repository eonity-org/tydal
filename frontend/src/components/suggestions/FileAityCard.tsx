import { useState } from 'react'
import { Box, Button, Chip, CircularProgress, Stack, Typography } from '@mui/material'
import CheckCircleIcon from '@mui/icons-material/CheckCircle'
import ErrorIcon from '@mui/icons-material/Error'
import RefreshIcon from '@mui/icons-material/Refresh'
import WarningAmberIcon from '@mui/icons-material/WarningAmber'
import type { AityPollEntry } from '../../hooks/useAityPolling'
import type { ResourceFile } from '../../api/resourceService'
import { AityPulse } from './AityPulse'

interface Props {
  file: ResourceFile & { [key: string]: any }
  pollEntry: AityPollEntry | null
  onRetry: () => Promise<void>
  canRetry?: boolean
  /** Labels of tags currently attached to the resource — used to render the
   * suggested-tag chips as accepted (filled) or rejected (outlined). */
  acceptedTagLabels?: Set<string>
}

function statusLabel(pollEntry: AityPollEntry | null): string {
  if (!pollEntry) return 'Not processed'
  if (pollEntry.status === 'waiting') {
    if (pollEntry.stage === 'queued')       return 'Queued for processing…'
    if (pollEntry.stage === 'extracting')   return 'Extracting text…'
    if (pollEntry.stage === 'ai_analyzing') return 'Analyzing with AI…'
    return 'Processing…'
  }
  if (pollEntry.status === 'ready')         return 'Analysis complete'
  if (pollEntry.status === 'failed')        return 'Processing failed'
  if (pollEntry.status === 'not_supported') return 'No AI support for this file type'
  return 'Unknown'
}

export function FileAityCard({ file: _file, pollEntry, onRetry, canRetry = true, acceptedTagLabels }: Props) {
  const [retrying, setRetrying] = useState(false)

  const isWaiting = pollEntry?.status === 'waiting'
  const showRetry = canRetry && (
    !pollEntry ||
    pollEntry.status === 'ready' ||
    pollEntry.status === 'failed' ||
    pollEntry.status === 'not_supported'
  )

  const sugg = pollEntry?.suggestions
  const hasSuggestions = !!sugg && (
    !!sugg.suggestedName ||
    !!sugg.suggestedDescription ||
    (sugg.suggestedTags?.length ?? 0) > 0 ||
    Object.keys(sugg.suggestedMetadata ?? {}).length > 0
  )

  const handleRetry = async () => {
    setRetrying(true)
    try { await onRetry() } finally { setRetrying(false) }
  }

  return (
    <Box>
      {/* ── Status row ── */}
      <Stack direction="row" alignItems="center" spacing={0.75}>
        <AityPulse active={isWaiting} size={16} dim={!pollEntry || pollEntry.status === 'not_supported'} />
        <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: 0.5 }}>
          Aity
        </Typography>

        {isWaiting ? null : pollEntry?.status === 'ready' ? (
          <CheckCircleIcon sx={{ fontSize: '0.875rem', color: 'success.main' }} />
        ) : pollEntry?.status === 'failed' ? (
          <ErrorIcon sx={{ fontSize: '0.875rem', color: 'error.main' }} />
        ) : pollEntry?.status === 'not_supported' ? (
          <WarningAmberIcon sx={{ fontSize: '0.875rem', color: 'warning.main' }} />
        ) : null}

        <Typography variant="caption" color="text.secondary" sx={{ flex: 1 }}>
          {statusLabel(pollEntry)}
        </Typography>

        {showRetry && (
          <Button
            size="small"
            variant="text"
            startIcon={retrying
              ? <CircularProgress size={11} />
              : <RefreshIcon sx={{ fontSize: '0.8rem' }} />}
            onClick={handleRetry}
            disabled={retrying}
            sx={{ fontSize: '0.7rem', py: 0, minHeight: 0, color: 'text.secondary' }}
          >
            Retry
          </Button>
        )}
      </Stack>

      {/* ── Suggestions (shown when ready) ── */}
      {pollEntry?.status === 'ready' && hasSuggestions && (
        <Stack spacing={0.5} sx={{ mt: 0.75, pl: 1.5 }}>
          {sugg!.suggestedName && (
            <Stack direction="row" spacing={1} alignItems="flex-start">
              <FieldLabel label="Name" applied={!!pollEntry?.applied?.name} />
              <Typography variant="caption" sx={{ flex: 1, wordBreak: 'break-word', fontSize: '0.75rem' }}>
                {sugg!.suggestedName}
              </Typography>
            </Stack>
          )}
          {sugg!.suggestedDescription && (
            <Stack direction="row" spacing={1} alignItems="flex-start">
              <FieldLabel label="Description" applied={!!pollEntry?.applied?.description} />
              <Typography variant="caption" sx={{ flex: 1, wordBreak: 'break-word', fontSize: '0.75rem' }}>
                {sugg!.suggestedDescription}
              </Typography>
            </Stack>
          )}
          {sugg!.suggestedTags && sugg!.suggestedTags.length > 0 && (
            <Stack direction="row" spacing={1} alignItems="flex-start">
              <FieldLabel label="Tags" applied={!!pollEntry?.applied?.tags} sx={{ pt: 0.25 }} />
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                {sugg!.suggestedTags.map((tag) => {
                  const accepted = !!acceptedTagLabels?.has(tag.label.toLowerCase())
                  return (
                    <Chip
                      key={tag.label}
                      label={tag.label}
                      size="small"
                      variant={accepted ? 'filled' : 'outlined'}
                      color={accepted ? 'success' : 'default'}
                      title={accepted ? 'Selected' : 'Not selected'}
                      sx={{
                        height: 18,
                        fontSize: '0.65rem',
                        opacity: accepted ? 1 : 0.65,
                      }}
                    />
                  )
                })}
              </Box>
            </Stack>
          )}
          {/* Scheme-field suggestions (ai_fill contract) — one row per field */}
          {Object.entries(sugg!.suggestedMetadata ?? {}).map(([field, value]) => (
            <Stack key={field} direction="row" spacing={1} alignItems="flex-start">
              <FieldLabel label={field} applied={false} />
              <Typography variant="caption" sx={{ flex: 1, wordBreak: 'break-word', fontSize: '0.75rem' }}>
                {Array.isArray(value) ? value.join(', ') : String(value)}
              </Typography>
            </Stack>
          ))}
        </Stack>
      )}
    </Box>
  )
}

function FieldLabel({ label, applied, sx }: { label: string; applied: boolean; sx?: object }) {
  return (
    <Stack direction="row" spacing={0.25} alignItems="center" sx={{ minWidth: 80, ...sx }}>
      <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500, fontSize: '0.75rem' }}>
        {label}
      </Typography>
      {applied && (
        <CheckCircleIcon
          titleAccess="Suggestion used"
          sx={{ fontSize: '0.75rem', color: 'success.main' }}
        />
      )}
    </Stack>
  )
}
