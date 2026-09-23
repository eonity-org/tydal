import { useMemo, useState } from 'react'
import {
  Accordion,
  AccordionSummary,
  AccordionDetails,
  Box,
  Stack,
  Typography,
  Chip,
  Button,
  Divider,
} from '@mui/material'
import ExpandMoreIcon from '@mui/icons-material/ExpandMore'
import RefreshIcon from '@mui/icons-material/Refresh'
import ReplayIcon from '@mui/icons-material/Replay'
import { AityPulse } from './AityPulse'
import { FileAityCard } from './FileAityCard'
import type { AityPollEntry } from '../../hooks/useAityPolling'
import type { ResourceFile } from '../../api/resourceService'

interface Props {
  files: Array<ResourceFile & { [key: string]: any }>
  statuses: Record<string, AityPollEntry>
  onRetryFile: (fileId: string) => Promise<void>
  onRetryAllFailed: () => void
  /** Background poll detected new AITY info not yet pulled in. */
  backgroundRefreshPending?: boolean
  onRefresh?: () => void
  /** Tag labels currently attached to the resource — passed through to per-file chips. */
  acceptedTagLabels?: Set<string>
}

type Group = 'failed' | 'waiting' | 'ready' | 'not_supported' | 'none'

const GROUP_ORDER: Group[] = ['failed', 'waiting', 'ready', 'not_supported', 'none']
const GROUP_LABEL: Record<Group, string> = {
  failed: 'Failed',
  waiting: 'In progress',
  ready: 'Ready',
  not_supported: 'No AI support',
  none: 'Not processed',
}

function groupOf(entry: AityPollEntry | null): Group {
  if (!entry) return 'none'
  if (entry.status === 'failed') return 'failed'
  if (entry.status === 'waiting') return 'waiting'
  if (entry.status === 'ready') return 'ready'
  if (entry.status === 'not_supported') return 'not_supported'
  return 'none'
}

/**
 * One aggregate AITY status surface for a resource's files. Header summarises the
 * whole pipeline in a single line (so you don't expand N file rows); the body
 * lists per-file status grouped by state, exceptions first. Status/retry only —
 * accepting suggestions stays inline at the fields.
 */
export function AityStatusAccordion(props: Props) {
  const { files, statuses, onRetryFile, onRetryAllFailed, backgroundRefreshPending, onRefresh, acceptedTagLabels } = props

  const grouped = useMemo(() => {
    const buckets: Record<Group, typeof files> = { failed: [], waiting: [], ready: [], not_supported: [], none: [] }
    for (const f of files) buckets[groupOf(statuses[f.id] ?? null)].push(f)
    return buckets
  }, [files, statuses])

  const counts = {
    total: files.length,
    failed: grouped.failed.length,
    waiting: grouped.waiting.length,
    ready: grouped.ready.length,
  }
  const processingActive = counts.waiting > 0

  // Open by default when there's something to act on (a failure or in-flight work).
  const [expanded, setExpanded] = useState<boolean>(counts.failed > 0 || counts.waiting > 0)

  if (files.length === 0) return null

  return (
    <Accordion
      expanded={expanded}
      onChange={(_e, v) => setExpanded(v)}
      disableGutters
      sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 1, '&:before': { display: 'none' }, boxShadow: 'none' }}
    >
      <AccordionSummary expandIcon={<ExpandMoreIcon />} sx={{ minHeight: 44, '& .MuiAccordionSummary-content': { alignItems: 'center', gap: 1, my: 0.5, flexWrap: 'wrap' } }}>
        <AityPulse active={processingActive} size={16} />
        <Typography variant="caption" sx={{ fontWeight: 700, textTransform: 'uppercase', letterSpacing: 0.5, color: 'text.secondary' }}>
          Aity
        </Typography>
        <Typography variant="caption" sx={{ color: 'text.secondary' }}>
          {counts.total} file{counts.total > 1 ? 's' : ''}
        </Typography>
        {counts.ready > 0 && <Chip size="small" label={`${counts.ready} ready`} color="success" variant="outlined" sx={{ height: 18, fontSize: '0.65rem' }} />}
        {counts.waiting > 0 && <Chip size="small" label={`${counts.waiting} processing`} color="primary" variant="outlined" sx={{ height: 18, fontSize: '0.65rem' }} />}
        {counts.failed > 0 && <Chip size="small" label={`${counts.failed} failed`} color="error" sx={{ height: 18, fontSize: '0.65rem' }} />}
        {backgroundRefreshPending && <Chip size="small" label="new info" color="info" sx={{ height: 18, fontSize: '0.65rem' }} />}
        <Box sx={{ flex: 1 }} />
        {/* Header actions — stopPropagation so clicking them doesn't toggle the accordion */}
        {backgroundRefreshPending && onRefresh && (
          <Button size="small" variant="text" startIcon={<RefreshIcon sx={{ fontSize: '0.85rem' }} />}
            onClick={(e) => { e.stopPropagation(); onRefresh() }}
            sx={{ fontSize: '0.7rem', py: 0, minHeight: 0 }}>
            Load
          </Button>
        )}
        {counts.failed > 0 && (
          <Button size="small" variant="text" color="error" startIcon={<ReplayIcon sx={{ fontSize: '0.85rem' }} />}
            onClick={(e) => { e.stopPropagation(); onRetryAllFailed() }}
            sx={{ fontSize: '0.7rem', py: 0, minHeight: 0 }}>
            Retry all
          </Button>
        )}
      </AccordionSummary>

      <AccordionDetails sx={{ pt: 0 }}>
        <Stack spacing={1.5} divider={<Divider flexItem />}>
          {GROUP_ORDER.filter((g) => grouped[g].length > 0).map((g) => (
            <Box key={g}>
              <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', display: 'block', mb: 0.5 }}>
                {GROUP_LABEL[g]} ({grouped[g].length})
              </Typography>
              <Stack spacing={1}>
                {grouped[g].map((f) => (
                  <Box key={f.id} sx={{ pl: 1, borderLeft: '2px solid', borderColor: 'divider' }}>
                    <Typography variant="caption" sx={{ fontWeight: 500, display: 'block', mb: 0.25, wordBreak: 'break-word' }}>
                      {f.filename || f.original_name || '(unnamed)'}
                    </Typography>
                    <FileAityCard
                      file={f}
                      pollEntry={statuses[f.id] ?? null}
                      onRetry={() => onRetryFile(String(f.id))}
                      acceptedTagLabels={acceptedTagLabels}
                    />
                  </Box>
                ))}
              </Stack>
            </Box>
          ))}
        </Stack>
      </AccordionDetails>
    </Accordion>
  )
}

export default AityStatusAccordion
