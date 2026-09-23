import { useState } from 'react'
import { Box, Button, Stack, TextField, Typography } from '@mui/material'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import ClearAllIcon from '@mui/icons-material/ClearAll'
import { AiSuggestions, SuggestionsStatus as Status } from '../../hooks/useAiSuggestionsPoller'
import { SuggestionsStatus } from './SuggestionsStatus'
import { SuggestionChip } from './SuggestionChip'

export interface SuggestionsPanelCallbacks {
  onAcceptName: (name: string) => void
  onAcceptDescription: (description: string) => void
  onAcceptTag: (tag: { label: string; description?: string | null; type?: string }) => void
  onDismissAll: () => void
}

interface Props extends Partial<SuggestionsPanelCallbacks> {
  status: Status
  suggestions: AiSuggestions | null
  /** Labels of tags already added to the resource (to show checkmarks). */
  acceptedTagLabels: Set<string>
  /** When true, the dismiss-all button is shown. */
  showDismiss?: boolean
  /** Override label shown while waiting. */
  waitingLabel?: string
  /**
   * Preview-only mode — hides Apply buttons and tag interactions.
   * Used in Step 2 where edits are deferred to the Review step.
   */
  readOnly?: boolean
}

const VARIANTS_VISIBLE = 3

/**
 * The full AI suggestion review UI.
 *
 * Fully props-driven — no internal API calls, no modal awareness.
 * Used in ResourceWizard (Step 2), BatchCarousel (ResourceReviewCard),
 * and ResourceDetailModal (replacing the old accordion).
 */
export function SuggestionsPanel({
  status,
  suggestions,
  acceptedTagLabels,
  showDismiss = true,
  waitingLabel,
  readOnly = false,
  onAcceptName,
  onAcceptDescription,
  onAcceptTag,
  onDismissAll,
}: Props) {
  const [showAllNames, setShowAllNames] = useState(false)
  const [showAllDescs, setShowAllDescs] = useState(false)

  const hasSuggestions = status === 'ready' && suggestions !== null

  const nameVariants    = suggestions?.suggestedNames ?? []
  const descVariants    = suggestions?.suggestedDescriptions ?? []
  const visibleNames    = showAllNames ? nameVariants : nameVariants.slice(0, VARIANTS_VISIBLE)
  const visibleDescs    = showAllDescs ? descVariants : descVariants.slice(0, VARIANTS_VISIBLE)
  const hiddenNameCount = nameVariants.length - VARIANTS_VISIBLE
  const hiddenDescCount = descVariants.length - VARIANTS_VISIBLE

  const multipleNameSources = nameVariants.length > 1
  const multipleDescSources = descVariants.length > 1

  return (
    <Box>
      {!readOnly && (
        <>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
              <AutoAwesomeIcon sx={{ fontSize: 16, color: 'primary.main' }} />
              <Typography variant="subtitle2" color="primary.main">
                Aity Suggestions
              </Typography>
            </Box>
            {showDismiss && hasSuggestions && (
              <Button
                size="small"
                startIcon={<ClearAllIcon />}
                onClick={onDismissAll}
                sx={{ fontSize: '0.7rem', color: 'text.secondary' }}
              >
                Dismiss all
              </Button>
            )}
          </Box>
          <SuggestionsStatus status={status} waitingLabel={waitingLabel} />
        </>
      )}

      {hasSuggestions && (
        <Stack spacing={1.5}>
          {/* Suggested name variants */}
          {nameVariants.length > 0 && (
            <Box>
              <Typography variant="caption" color="text.secondary" sx={{ mb: 0.5, display: 'block' }}>
                Suggested name
              </Typography>
              <Stack spacing={0.75}>
                {visibleNames.map(({ value, sourceFile }) => (
                  <Box key={sourceFile.id}>
                    {multipleNameSources && (
                      <Typography
                        variant="caption"
                        color="text.disabled"
                        sx={{ display: 'block', mb: 0.25, fontSize: '0.65rem' }}
                        noWrap
                      >
                        {sourceFile.filename}
                      </Typography>
                    )}
                    <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-start' }}>
                      <TextField
                        size="small"
                        fullWidth
                        value={value}
                        InputProps={{ readOnly: true }}
                        sx={{
                          '& .MuiOutlinedInput-root': { fontSize: '0.82rem' },
                          '& .MuiOutlinedInput-notchedOutline': {
                            borderStyle: 'dashed',
                            borderColor: 'rgba(0,0,0,0.18)',
                          },
                        }}
                      />
                      {!readOnly && (
                        <Button
                          size="small"
                          variant="outlined"
                          onClick={() => onAcceptName?.(value)}
                          sx={{ whiteSpace: 'nowrap', flexShrink: 0 }}
                        >
                          Apply
                        </Button>
                      )}
                    </Box>
                  </Box>
                ))}
                {hiddenNameCount > 0 && !showAllNames && (
                  <Button
                    size="small"
                    onClick={() => setShowAllNames(true)}
                    sx={{ alignSelf: 'flex-start', fontSize: '0.72rem', px: 0 }}
                  >
                    See {hiddenNameCount} more
                  </Button>
                )}
              </Stack>
            </Box>
          )}

          {/* Suggested description variants */}
          {descVariants.length > 0 && (
            <Box>
              <Typography variant="caption" color="text.secondary" sx={{ mb: 0.5, display: 'block' }}>
                Suggested description
              </Typography>
              <Stack spacing={0.75}>
                {visibleDescs.map(({ value, sourceFile }) => (
                  <Box key={sourceFile.id}>
                    {multipleDescSources && (
                      <Typography
                        variant="caption"
                        color="text.disabled"
                        sx={{ display: 'block', mb: 0.25, fontSize: '0.65rem' }}
                        noWrap
                      >
                        {sourceFile.filename}
                      </Typography>
                    )}
                    <Box sx={{ display: 'flex', gap: 1, alignItems: 'flex-start' }}>
                      <TextField
                        size="small"
                        fullWidth
                        multiline
                        maxRows={3}
                        value={value}
                        InputProps={{ readOnly: true }}
                        sx={{
                          '& .MuiOutlinedInput-root': { fontSize: '0.82rem' },
                          '& .MuiOutlinedInput-notchedOutline': {
                            borderStyle: 'dashed',
                            borderColor: 'rgba(0,0,0,0.18)',
                          },
                        }}
                      />
                      {!readOnly && (
                        <Button
                          size="small"
                          variant="outlined"
                          onClick={() => onAcceptDescription?.(value)}
                          sx={{ whiteSpace: 'nowrap', flexShrink: 0 }}
                        >
                          Apply
                        </Button>
                      )}
                    </Box>
                  </Box>
                ))}
                {hiddenDescCount > 0 && !showAllDescs && (
                  <Button
                    size="small"
                    onClick={() => setShowAllDescs(true)}
                    sx={{ alignSelf: 'flex-start', fontSize: '0.72rem', px: 0 }}
                  >
                    See {hiddenDescCount} more
                  </Button>
                )}
              </Stack>
            </Box>
          )}

          {/* Suggested tags — merged across all source files, deduplicated */}
          {suggestions.suggestedTags.length > 0 && (
            <Box>
              <Typography variant="caption" color="text.secondary" sx={{ mb: 0.75, display: 'block' }}>
                Suggested tags
              </Typography>
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.75 }}>
                {suggestions.suggestedTags.map((tag) => (
                  <SuggestionChip
                    key={tag.label}
                    label={tag.label}
                    type={tag.type}
                    description={tag.description}
                    confidence={tag.confidence}
                    accepted={readOnly ? false : acceptedTagLabels.has(tag.label)}
                    onAccept={readOnly ? () => {} : () => onAcceptTag?.(tag)}
                    sourceLabel={tag.sourceFile.filename}
                    disabled={readOnly}
                  />
                ))}
              </Box>
            </Box>
          )}
        </Stack>
      )}

      {status === 'idle' && (
        <Typography variant="caption" color="text.disabled">
          No suggestions available for this resource.
        </Typography>
      )}
    </Box>
  )
}
