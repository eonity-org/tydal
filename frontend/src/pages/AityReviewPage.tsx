import { useState, useEffect, useCallback, useRef } from 'react'
import { useNavigate } from 'react-router-dom'
import {
  Alert, Box, Button, Chip, CircularProgress, Collapse, Divider,
  IconButton, LinearProgress, Popover, Stack, Tooltip, Typography,
} from '@mui/material'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import CheckCircleOutlineIcon from '@mui/icons-material/CheckCircleOutline'
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutline'
import ExpandMoreIcon from '@mui/icons-material/ExpandMore'
import OpenInNewIcon from '@mui/icons-material/OpenInNew'
import RefreshIcon from '@mui/icons-material/Refresh'
import Header from '../components/layout/Header'
import workspaceService, { type AityBatch, type AutoApproveLog } from '../api/workspaceService'

// ─── status helpers ───────────────────────────────────────────────────────────

type BatchStatus = 'analyzing' | 'waiting' | 'auto-approving' | 'done' | 'failed' | 'ready'

function batchStatus(b: AityBatch): BatchStatus {
  if (b.aity_processing_count > 0)             return 'analyzing'
  if (b.auto_approve_status === 'running')      return 'auto-approving'
  if (b.auto_approve_status === 'pending')      return 'waiting'
  if (b.auto_approve_status === 'done')         return 'done'
  if (b.auto_approve_status === 'failed')       return 'failed'
  return 'ready'
}

const STATUS_META: Record<BatchStatus, { label: string; color: 'default' | 'info' | 'warning' | 'success' | 'error' }> = {
  analyzing:        { label: 'Analyzing',        color: 'info'    },
  waiting:          { label: 'Waiting for job',  color: 'warning' },
  'auto-approving': { label: 'Auto-approving',   color: 'warning' },
  ready:            { label: 'Ready for review', color: 'success' },
  done:             { label: 'Done',             color: 'success' },
  failed:           { label: 'Failed',           color: 'error'   },
}

function StatusChip({ batch }: { batch: AityBatch }) {
  const st = batchStatus(batch)
  const { label, color } = STATUS_META[st]
  const isSpinner = st === 'analyzing' || st === 'auto-approving'
  return (
    <Chip
      size="small"
      color={color}
      label={label}
      icon={isSpinner ? <CircularProgress size={12} color="inherit" /> : undefined}
      sx={{ fontWeight: 500 }}
    />
  )
}

// ─── log panel ───────────────────────────────────────────────────────────────

function LogPanel({ log }: { log: AutoApproveLog }) {
  const logEndRef = useRef<HTMLDivElement>(null)

  // Auto-scroll to bottom as new lines arrive while the job is running
  useEffect(() => {
    if (log.partial) {
      logEndRef.current?.scrollIntoView({ behavior: 'smooth' })
    }
  }, [log.partial, log.log.length])

  return (
    <Box sx={{ mt: 1.5 }}>
      {/* Running indicator shown while job is in progress */}
      {log.partial && (
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 1.5 }}>
          <CircularProgress size={14} />
          <Typography variant="caption" color="text.secondary" sx={{ fontStyle: 'italic' }}>
            Running…
          </Typography>
        </Stack>
      )}

      {/* Summary stats — hidden while job is still in progress */}
      {!log.partial && (
        <Stack direction="row" spacing={2} flexWrap="wrap" sx={{ mb: 1.5 }}>
          {[
            { label: 'Resources processed', value: log.resources_processed },
            { label: 'Names applied',        value: log.names_applied        },
            { label: 'Descriptions applied', value: log.descriptions_applied },
            { label: 'Tags applied',         value: log.tags_applied         },
            { label: 'Tags skipped',         value: log.tags_skipped         },
          ].map(({ label, value }) => (
            <Box key={label} sx={{ textAlign: 'center', minWidth: 80 }}>
              <Typography variant="h6" sx={{ fontWeight: 700, lineHeight: 1.1, color: value > 0 ? 'primary.main' : 'text.disabled' }}>
                {value}
              </Typography>
              <Typography variant="caption" color="text.secondary" sx={{ fontSize: '0.65rem', display: 'block' }}>
                {label}
              </Typography>
            </Box>
          ))}
          {log.dry_run && (
            <Chip size="small" label="Dry run" variant="outlined" sx={{ alignSelf: 'center' }} />
          )}
        </Stack>
      )}

      {/* Log lines */}
      {log.log.length > 0 && (
        <Box
          sx={{
            bgcolor: 'grey.950',
            borderRadius: 1,
            p: 1.5,
            maxHeight: 280,
            overflowY: 'auto',
            fontFamily: 'monospace',
            fontSize: '0.72rem',
            lineHeight: 1.5,
            border: '1px solid',
            borderColor: 'divider',
          }}
        >
          {log.log.map((line, i) => (
            <Box
              key={i}
              component="div"
              sx={{
                color: line.startsWith('ERROR') || line.startsWith('  WARNING')
                  ? 'error.main'
                  : line.startsWith('─') || line.startsWith('━')
                    ? 'text.disabled'
                    : 'text.primary',
                whiteSpace: 'pre-wrap',
                wordBreak: 'break-word',
              }}
            >
              {line || ' '}
            </Box>
          ))}
          <div ref={logEndRef} />
        </Box>
      )}
    </Box>
  )
}

// ─── batch row ────────────────────────────────────────────────────────────────

function BatchRow({ batch, onReview, onDelete }: {
  batch: AityBatch
  onReview: (b: AityBatch) => void
  onDelete: (b: AityBatch) => void
}) {
  const isPartial  = batch.auto_approve_log?.partial === true
  const [logOpen, setLogOpen]           = useState(isPartial)
  const [confirmDelete, setConfirmDelete] = useState(false)
  const deleteAnchorRef = useRef<HTMLButtonElement>(null)
  const total     = batch.resources_count ?? 0
  const fileCount = batch.files_count ?? 0
  const analyzing = batch.aity_processing_count ?? 0
  const ready     = total - analyzing
  const progress  = total > 0 ? Math.round((ready / total) * 100) : 100
  const st        = batchStatus(batch)
  const isActive  = st === 'analyzing' || st === 'auto-approving' || st === 'waiting'
  const isDone    = st === 'done' || st === 'failed'
  const hasLog    = !!batch.auto_approve_log
  const isReviewed = !!batch.auto_approve_reviewed_at

  // Auto-open the log panel whenever the job transitions to partial (running)
  useEffect(() => {
    if (isPartial) setLogOpen(true)
  }, [isPartial])

  const fmt = (iso: string) => new Intl.DateTimeFormat(undefined, {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: 'numeric', minute: '2-digit',
  }).format(new Date(iso))

  const formattedDate     = batch.created_at ? fmt(batch.created_at) : '—'
  const formattedReviewed = batch.auto_approve_reviewed_at ? fmt(batch.auto_approve_reviewed_at) : null

  return (
    <Box
      sx={{
        px: 2.5, py: 1.75,
        borderBottom: '1px solid',
        borderColor: 'divider',
        '&:last-child': { borderBottom: 0 },
        transition: 'background 0.1s',
      }}
    >
      {/* Main row */}
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2.5} alignItems={{ sm: 'center' }}>

        {/* Name + date */}
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Typography variant="body2" fontWeight={600} noWrap>
            {batch.name}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {formattedDate}
          </Typography>
        </Box>

        {/* Counts — own column so the labels never wrap into the progress bar */}
        <Box sx={{ flexShrink: 0, minWidth: 150 }}>
          <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block', lineHeight: 1.4 }}>
            {total} resource{total !== 1 ? 's' : ''}
          </Typography>
          <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block', lineHeight: 1.4 }}>
            {fileCount} file{fileCount !== 1 ? 's' : ''}
          </Typography>
        </Box>

        {/* Progress bar */}
        <Box sx={{ width: 160, flexShrink: 0 }}>
          <Typography variant="caption" color="text.secondary" noWrap sx={{ display: 'block', mb: 0.5 }}>
            {analyzing > 0 ? `${analyzing} analyzing` : 'all analyzed'}
          </Typography>
          <Tooltip title={`${ready} / ${total} analyzed`}>
            <LinearProgress
              variant={isActive && analyzing > 0 ? 'indeterminate' : 'determinate'}
              value={progress}
              color={st === 'failed' ? 'error' : (st === 'done' || st === 'ready') ? 'success' : 'primary'}
              sx={{ height: 4, borderRadius: 2 }}
            />
          </Tooltip>
        </Box>

        {/* Status chip */}
        <Box sx={{ flexShrink: 0, minWidth: 130, display: 'flex', justifyContent: 'flex-start' }}>
          <StatusChip batch={batch} />
        </Box>

        {/* Reviewed indicator — fixed-width slot so its presence never shifts the
            action buttons out of column alignment with the other rows */}
        <Box sx={{ width: 24, flexShrink: 0, display: 'flex', justifyContent: 'center' }}>
          {isReviewed && (
            <Tooltip title={`Reviewed on ${formattedReviewed}`}>
              <CheckCircleOutlineIcon sx={{ fontSize: '1rem', color: 'success.main' }} />
            </Tooltip>
          )}
        </Box>

        {/* Actions */}
        <Stack direction="row" spacing={0.5} alignItems="center" sx={{ flexShrink: 0 }}>

          {/* Review button */}
          <Tooltip title="Open workspace for review">
            <Button
              size="small"
              variant="outlined"
              startIcon={<OpenInNewIcon sx={{ fontSize: '0.875rem !important' }} />}
              onClick={() => onReview(batch)}
              sx={{ whiteSpace: 'nowrap', fontSize: '0.75rem' }}
            >
              Review
            </Button>
          </Tooltip>

          {/* Delete — confirmation lives in a popover so the trash icon stays put
              and the row never reflows when confirming */}
          {isDone && (
            <>
              <Tooltip title={isReviewed ? 'Delete batch' : 'Delete batch (not yet reviewed)'}>
                <IconButton
                  ref={deleteAnchorRef}
                  size="small"
                  onClick={() => setConfirmDelete(true)}
                  sx={{ color: isReviewed ? 'text.secondary' : 'text.disabled' }}
                >
                  <DeleteOutlineIcon fontSize="small" />
                </IconButton>
              </Tooltip>
              <Popover
                open={confirmDelete}
                anchorEl={deleteAnchorRef.current}
                onClose={() => setConfirmDelete(false)}
                anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
                transformOrigin={{ vertical: 'top', horizontal: 'right' }}
              >
                <Box sx={{ p: 1.5, width: 240 }}>
                  <Typography variant="body2" sx={{ mb: 1.5 }}>
                    Delete this batch? This cannot be undone.
                  </Typography>
                  <Stack direction="row" spacing={1} justifyContent="flex-end">
                    <Button size="small" onClick={() => setConfirmDelete(false)}>
                      Cancel
                    </Button>
                    <Button
                      size="small"
                      color="error"
                      variant="contained"
                      onClick={() => { setConfirmDelete(false); onDelete(batch) }}
                    >
                      Delete
                    </Button>
                  </Stack>
                </Box>
              </Popover>
            </>
          )}

          {/* Log toggle */}
          <Tooltip title={hasLog ? 'View activity log' : 'Log not yet available'}>
            <span>
              <IconButton
                size="small"
                onClick={() => setLogOpen((v) => !v)}
                disabled={!hasLog}
                sx={{
                  transform: logOpen ? 'rotate(180deg)' : 'rotate(0deg)',
                  transition: 'transform 0.2s',
                  color: hasLog ? 'text.secondary' : 'text.disabled',
                }}
              >
                <ExpandMoreIcon fontSize="small" />
              </IconButton>
            </span>
          </Tooltip>
        </Stack>
      </Stack>

      {/* Expandable log */}
      <Collapse in={logOpen && hasLog}>
        {batch.auto_approve_log && (
          <LogPanel log={batch.auto_approve_log} />
        )}
      </Collapse>
    </Box>
  )
}

// ─── page ─────────────────────────────────────────────────────────────────────

export default function AityReviewPage() {
  const navigate = useNavigate()
  const [batches, setBatches]    = useState<AityBatch[]>([])
  const [loading, setLoading]    = useState(true)
  const [error, setError]        = useState<string | null>(null)
  const [totalBatches, setTotal] = useState(0)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    const res = await workspaceService.getAityBatches()
    if (res) {
      setBatches(res.data.batches)
      setTotal(res.meta.pagination.total)
    } else {
      setError('Could not load AiTy batches.')
    }
    setLoading(false)
  }, [])

  useEffect(() => { load() }, [load])

  // Auto-refresh every 10s while any batch is still active
  useEffect(() => {
    const hasActive = batches.some((b) => {
      const st = batchStatus(b)
      return st === 'analyzing' || st === 'auto-approving' || st === 'waiting'
    })
    if (!hasActive) return
    const timer = setInterval(load, 10_000)
    return () => clearInterval(timer)
  }, [batches, load])

  const handleReview = useCallback((batch: AityBatch) => {
    // Mark reviewed (fire-and-forget) + optimistic local update
    workspaceService.markAityReviewed(Number(batch.id)).catch(() => {})
    setBatches((prev) =>
      prev.map((b) =>
        b.id === batch.id ? { ...b, auto_approve_reviewed_at: new Date().toISOString() } : b,
      ),
    )
    navigate('/', {
      state: {
        workspaceId:      batch.id,
        workspaceName:    batch.name,
        workspacePurpose: batch.purpose,
      },
    })
  }, [navigate, setBatches])

  const handleDelete = useCallback(async (batch: AityBatch) => {
    const ok = await workspaceService.deleteAityBatch(Number(batch.id))
    if (ok) {
      setBatches((prev) => prev.filter((b) => b.id !== batch.id))
      setTotal((prev) => prev - 1)
    }
  }, [setBatches])

  const activeBatches    = batches.filter((b) => { const s = batchStatus(b); return s === 'analyzing' || s === 'auto-approving' || s === 'waiting' })
  const completedBatches = batches.filter((b) => { const s = batchStatus(b); return s === 'ready' || s === 'done' || s === 'failed' })

  return (
    <Stack sx={{ minHeight: '100vh' }}>
      <Header hideSearch hideCollections title="AiTy Review" />

      <Box sx={{ flex: 1, maxWidth: 960, width: '100%', mx: 'auto', px: 3, py: 4 }}>

        {/* Heading */}
        <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 3 }}>
          <Box>
            <Stack direction="row" spacing={1} alignItems="center">
              <AutoAwesomeIcon sx={{ color: 'secondary.main', fontSize: '1.25rem' }} />
              <Typography variant="h6" fontWeight={700}>AiTy Review Batches</Typography>
              {!loading && (
                <Chip size="small" label={totalBatches} sx={{ height: 20, fontSize: '0.7rem' }} />
              )}
            </Stack>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
              Wizard upload jobs — track AI analysis and auto-approval progress.
            </Typography>
          </Box>
          <Tooltip title="Refresh">
            <span>
              <Button
                variant="outlined"
                size="small"
                startIcon={loading ? <CircularProgress size={14} color="inherit" /> : <RefreshIcon />}
                onClick={load}
                disabled={loading}
              >
                Refresh
              </Button>
            </span>
          </Tooltip>
        </Stack>

        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}

        {loading && batches.length === 0 && (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
            <CircularProgress />
          </Box>
        )}

        {!loading && batches.length === 0 && (
          <Box sx={{ textAlign: 'center', py: 8 }}>
            <AutoAwesomeIcon sx={{ fontSize: 48, color: 'text.disabled', mb: 2 }} />
            <Typography variant="body1" color="text.secondary">No batch uploads yet.</Typography>
            <Typography variant="caption" color="text.disabled" display="block" sx={{ mt: 0.5 }}>
              Use the wizard's "AiTy Review" option to create your first batch.
            </Typography>
            <Button variant="outlined" sx={{ mt: 3 }} onClick={() => navigate('/')}>
              Go to catalogue
            </Button>
          </Box>
        )}

        {/* In progress */}
        {activeBatches.length > 0 && (
          <Box sx={{ mb: 4 }}>
            <Typography variant="subtitle2" color="text.secondary"
              sx={{ mb: 1, px: 0.5, textTransform: 'uppercase', letterSpacing: '0.05em', fontSize: '0.7rem' }}>
              In progress — {activeBatches.length}
            </Typography>
            <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
              {activeBatches.map((b) => (
                <BatchRow key={b.id} batch={b} onReview={handleReview} onDelete={handleDelete} />
              ))}
            </Box>
          </Box>
        )}

        {/* Completed */}
        {completedBatches.length > 0 && (
          <Box>
            {activeBatches.length > 0 && <Divider sx={{ mb: 3 }} />}
            <Typography variant="subtitle2" color="text.secondary"
              sx={{ mb: 1, px: 0.5, textTransform: 'uppercase', letterSpacing: '0.05em', fontSize: '0.7rem' }}>
              Completed — {completedBatches.length}
            </Typography>
            <Box sx={{ border: '1px solid', borderColor: 'divider', borderRadius: 2, overflow: 'hidden' }}>
              {completedBatches.map((b) => (
                <BatchRow key={b.id} batch={b} onReview={handleReview} onDelete={handleDelete} />
              ))}
            </Box>
          </Box>
        )}
      </Box>
    </Stack>
  )
}
