import { Box, IconButton, Stack, Typography } from '@mui/material'
import ArrowBackIosNewIcon from '@mui/icons-material/ArrowBackIosNew'
import ArrowForwardIosIcon from '@mui/icons-material/ArrowForwardIos'

import type { SchemeField } from '../../../api/collectionService'
import type { WizardResourceRecord } from '../ResourceWizard'
import type { AityPollEntry } from '../../../hooks/useAityPolling'
import { CarouselResourceEditor } from '../CarouselResourceEditor'

interface Props {
  records: WizardResourceRecord[]
  aityStatuses: Record<string, AityPollEntry>
  schemeFields: SchemeField[]
  onRecordChange: (localId: string, patch: Partial<WizardResourceRecord>) => void
  currentIndex: number
  onNavigate: (index: number) => void
}

/**
 * Step 3 — Carousel through all created resources for inline review/editing.
 */
export function CarouselStep({
  records,
  aityStatuses,
  schemeFields,
  onRecordChange,
  currentIndex,
  onNavigate,
}: Props) {
  const total        = records.length
  const current      = records[currentIndex]
  const canGoBack    = currentIndex > 0
  const canGoForward = currentIndex < total - 1

  if (!current) return null

  // Build a per-file poll entry list matching allFiles / uploadedFileIds order.
  // For batch mode this is always a single entry; for component/canonical it may be many.
  const filePollEntries = current.allFiles.map((f, idx) => ({
    filename: f.name,
    pollEntry: current.uploadedFileIds[idx]
      ? (aityStatuses[current.uploadedFileIds[idx]] ?? null)
      : null,
  }))

  // Default to whichever file the user picked in step 2 (pickedSuggestionFilename),
  // falling back to the first file.
  const defaultFilename = current.pickedSuggestionFilename ?? current.allFiles[0]?.name

  return (
    <Stack sx={{ flex: 1, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>
      {/* Navigation bar */}
      <Stack
        direction="row"
        alignItems="center"
        justifyContent="center"
        spacing={2}
        sx={{
          px: 3,
          py: 1.5,
          flexShrink: 0,
          bgcolor: 'background.paper',
        }}
      >
        <IconButton
          aria-label="Previous resource"
          size="medium"
          onClick={() => onNavigate(currentIndex - 1)}
          disabled={!canGoBack}
          sx={{
            width: 38,
            height: 38,
            border: '1px solid',
            borderColor: canGoBack ? 'primary.200' : 'divider',
            color: canGoBack ? 'primary.main' : 'text.disabled',
            bgcolor: canGoBack ? 'primary.50' : 'transparent',
          }}
        >
          <ArrowBackIosNewIcon sx={{ fontSize: 18 }} />
        </IconButton>

        <Stack
          alignItems="center"
          spacing={0.25}
          sx={{
            minWidth: 220,
            px: 2,
            py: 0.75,
            borderRadius: 1,
            border: '1px solid',
            borderColor: 'primary.200',
            bgcolor: 'primary.50',
          }}
        >
          <Typography
            variant="subtitle2"
            color="primary.dark"
            sx={{ fontWeight: 700, lineHeight: 1.25 }}
          >
            Resource {currentIndex + 1} <Box component="span" sx={{ color: 'primary.main' }}>of {total}</Box>
          </Typography>
          <Typography
            variant="caption"
            color="text.secondary"
            noWrap
            title={current.file.name}
            sx={{ maxWidth: 260, lineHeight: 1.2 }}
          >
            {current.file.name}
          </Typography>
        </Stack>

        <IconButton
          aria-label="Next resource"
          size="medium"
          onClick={() => onNavigate(currentIndex + 1)}
          disabled={!canGoForward}
          sx={{
            width: 38,
            height: 38,
            border: '1px solid',
            borderColor: canGoForward ? 'primary.200' : 'divider',
            color: canGoForward ? 'primary.main' : 'text.disabled',
            bgcolor: canGoForward ? 'primary.50' : 'transparent',
          }}
        >
          <ArrowForwardIosIcon sx={{ fontSize: 18 }} />
        </IconButton>
      </Stack>

      {/* Editor */}
      <Box sx={{ flex: 1, overflow: 'hidden', height: 0 }}>
        <CarouselResourceEditor
          record={current}
          filePollEntries={filePollEntries}
          defaultFilename={defaultFilename}
          schemeFields={schemeFields}
          onChange={onRecordChange}
        />
      </Box>
    </Stack>
  )
}
