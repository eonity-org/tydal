import { useState, useEffect, useCallback } from 'react'
import {
  Box,
  Grid,
  Paper,
  Stack,
  Typography,
  CircularProgress,
  Chip,
  IconButton,
  Tooltip,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogContentText,
  DialogActions,
  Button,
  Snackbar,
  Alert,
} from '@mui/material'
import { Unarchive, ArrowUpward, ArrowDownward, ChevronLeft, ChevronRight } from '@mui/icons-material'
import Header from '../components/layout/Header'
import NarrowDivider from '../components/ui/NarrowDivider'
import SegmentedChoice from '../components/ui/SegmentedChoice'
import resourceService, { type ResourceData } from '../api/resourceService'
import { resolveStorageUrl } from '../utils/storageUrl'
import { shortId } from '../utils/shortId'
import { usePermissions } from '../hooks/usePermissions'
import { getApiError } from '../utils/apiError'

type SortKey = 'updated_at' | 'name' | 'id'

/** The server caps one bulk request at this many ids (BulkResourceIdsRequest). */
const BULK_MAX = 200

/**
 * Archived resources (#25). Archiving takes a resource out of every listing,
 * search and vault, so this page, modelled on the trash, is where they can be
 * found again and set back to live. Unlike the trash nothing here expires.
 * Setting live goes through the basket's bulk state endpoint, which authorizes
 * per resource and reports partial success; skipped resources stay listed.
 */

function ArchivedPage() {
  const [resources, setResources] = useState<ResourceData[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)
  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [confirmSetAll, setConfirmSetAll] = useState(false)
  const [setAllLoading, setSetAllLoading] = useState(false)
  const [sortBy, setSortBy] = useState<SortKey>('updated_at')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const [report, setReport] = useState<{ message: string; severity: 'success' | 'warning' | 'error' } | null>(null)

  // Viewers may list (like the trash) but not change state: hide what can only fail.
  const { can, ready: permissionsReady } = usePermissions()
  const mayUpdate = permissionsReady && can('resources.update')

  const limit = 48

  const fetchArchived = useCallback(async (p: number, by: SortKey, dir: 'asc' | 'desc') => {
    setLoading(true)
    const result = await resourceService.getArchivedResources(p, limit, by, dir)
    if (result) {
      setResources(result.data)
      setTotal(result.total)
      setLastPage(result.last_page)
    }
    setLoading(false)
  }, [])

  useEffect(() => {
    fetchArchived(page, sortBy, sortDir)
  }, [page, sortBy, sortDir, fetchArchived])

  const handleSort = (by: SortKey) => {
    if (sortBy === by) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(by)
      setSortDir(by === 'updated_at' ? 'desc' : 'asc')
    }
    setPage(1)
  }

  const plural = (n: number) => (n === 1 ? 'resource' : 'resources')

  const handleSetLive = async (resource: ResourceData) => {
    setActionLoading(resource.id)
    try {
      const result = await resourceService.bulkState([resource.id], 'live')
      if (result.applied > 0) {
        setResources((prev) => prev.filter((r) => r.id !== resource.id))
        setTotal((prev) => prev - 1)
        setReport({ message: `${resource.name} is live again.`, severity: 'success' })
      } else {
        setReport({ message: `${resource.name} was skipped — you may not have permission.`, severity: 'warning' })
      }
    } catch (error) {
      setReport({ message: getApiError(error).message, severity: 'error' })
    }
    setActionLoading(null)
  }

  /**
   * Every archived resource this user can see, in batches of the server's
   * bulk cap. Authorization is per resource, so a partial result is normal:
   * report it the way the basket does and leave the skipped ones listed.
   */
  const handleSetAllLive = async () => {
    setSetAllLoading(true)
    try {
      const ids: string[] = []
      for (let p = 1, last = 1; p <= last; p++) {
        const result = await resourceService.getArchivedResources(p, BULK_MAX, sortBy, sortDir)
        if (!result) throw new Error('Could not load the archived resources.')
        ids.push(...result.data.map((r) => r.id))
        last = result.last_page
      }

      let applied = 0
      let skipped = 0
      for (let i = 0; i < ids.length; i += BULK_MAX) {
        const batch = ids.slice(i, i + BULK_MAX)
        try {
          const result = await resourceService.bulkState(batch, 'live')
          applied += result.applied
          skipped += result.skipped.length
        } catch {
          // The endpoint throws only when nothing in the batch could be applied.
          skipped += batch.length
        }
      }

      setReport(skipped === 0
        ? { message: `${applied} ${plural(applied)} set live.`, severity: 'success' }
        : { message: `${applied} of ${ids.length} set live. ${skipped} skipped — you may not have permission.`, severity: 'warning' })
    } catch (error) {
      setReport({ message: getApiError(error).message, severity: 'error' })
    }
    setSetAllLoading(false)
    setConfirmSetAll(false)
    if (page === 1) fetchArchived(1, sortBy, sortDir)
    else setPage(1)
  }

  const formatDate = (iso: string | null) => {
    if (!iso) return '—'
    return new Date(iso).toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
  }

  const getPreviewUrl = (resource: ResourceData): string => {
    const snap = (resource as any).snapshot_file
    const urls = snap?.conversion_urls
    if (urls) return resolveStorageUrl(urls.thumbnail || urls.small || urls.original || snap?.url || '')
    return resolveStorageUrl(snap?.url || '')
  }

  const SORTS: { key: SortKey; label: string }[] = [
    { key: 'updated_at', label: 'Date' },
    { key: 'name',       label: 'Name' },
    { key: 'id',         label: 'ID'   },
  ]

  return (
    <Stack sx={{ height: '100vh', overflow: 'hidden', minHeight: 0 }}>
      <Header hideCollections hideSearch title="Archived" />

      <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', bgcolor: 'background.paper' }}>

        {/* Toolbar — mirrors MainContent header strip */}
        <Box sx={{ px: 2, minHeight: '4rem', display: 'flex', alignItems: 'center' }}>

          {/* Left: sort toggle — flex:1 so it mirrors right side width */}
          <Box sx={{ flex: 1, display: 'flex', alignItems: 'center' }}>
            <SegmentedChoice
              aria-label="Sort order"
              value={sortBy}
              onChange={handleSort}
              segmentWidth="4rem"
              options={SORTS.map(({ key, label }) => {
                const Arrow = sortDir === 'asc' ? ArrowUpward : ArrowDownward
                return {
                  value: key,
                  label: (
                    <>
                      {label}
                      {sortBy === key && <Arrow sx={{ fontSize: '0.875rem' }} />}
                    </>
                  ),
                }
              })}
            />
          </Box>

          {/* Center: italic count — always truly centered */}
          <Typography
            color="text.secondary"
            sx={{
              fontFamily: '"Playfair Display Variable", "Playfair Display", Georgia, serif',
              fontSize: '1.25rem',
              fontStyle: 'italic',
              fontWeight: 400,
              textAlign: 'center',
            }}
          >
            {total > 0 ? `${total} archived ${plural(total)}` : ''}
          </Typography>

          {/* Right: Set all live — flex:1 mirrors left side */}
          <Box sx={{ flex: 1, display: 'flex', justifyContent: 'flex-end', gap: 1 }}>
            {total > 0 && mayUpdate && (
              <Button variant="outlined" size="small" onClick={() => setConfirmSetAll(true)} sx={{ height: '2rem' }}>
                Set all live
              </Button>
            )}
          </Box>
        </Box>

        <NarrowDivider />

        {/* Scrollable content */}
        <Box sx={{ flex: 1, minHeight: 0, overflowY: 'auto', overflowX: 'hidden' }}>
          <Box sx={{ px: 3, py: 3 }}>
            {loading ? (
              <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
                <CircularProgress size={40} />
              </Box>
            ) : resources.length === 0 ? (
              <Paper elevation={0} sx={{ p: 4, textAlign: 'center', border: '1px solid', borderColor: 'divider', borderRadius: 1.5, minHeight: 400, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Stack spacing={1}>
                  <Typography variant="body2" color="text.secondary">
                    No archived resources.
                  </Typography>
                  <Typography variant="caption" color="text.disabled">
                    Archive a resource from the basket&apos;s State action or the State selector in its edit form.
                  </Typography>
                </Stack>
              </Paper>
            ) : (
              <Stack spacing={2}>
                <Paper elevation={0} sx={{ width: '100%', p: 2, minHeight: 400 }}>
                  <Grid container spacing={3} justifyContent="center">
                    {resources.map((resource) => {
                      const isActing = actionLoading === resource.id
                      const previewUrl = getPreviewUrl(resource)
                      return (
                        <Grid item xs={12} sm={6} md={4} lg={4} xl={3} key={resource.id}>
                          <Box
                            sx={{
                              border: '1px solid', borderColor: 'divider',
                              borderRadius: 1.5,
                              p: 1.5,
                              bgcolor: 'background.paper',
                              display: 'flex',
                              flexDirection: 'column',
                              gap: 1,
                              opacity: isActing ? 0.6 : 1,
                              transition: 'border-color 0.2s ease, background-color 0.2s ease',
                              '&:hover': { borderColor: 'grey.400', bgcolor: 'grey.50' },
                            }}
                          >
                            {/* Type chip + actions */}
                            <Stack direction="row" justifyContent="space-between" alignItems="center">
                              <Chip
                                label={resource.type?.toUpperCase()}
                                size="small"
                                sx={{ height: 18, fontSize: '0.75rem', fontWeight: 500, letterSpacing: 0.5, bgcolor: 'grey.100', color: 'text.disabled', borderRadius: '4px' }}
                              />
                              {mayUpdate && (
                                <Tooltip title="Set live">
                                  <span>
                                    <IconButton size="small" aria-label="Set live" disabled={isActing} onClick={() => handleSetLive(resource)}
                                      sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}>
                                      {isActing ? <CircularProgress size={14} /> : <Unarchive sx={{ fontSize: '1rem' }} />}
                                    </IconButton>
                                  </span>
                                </Tooltip>
                              )}
                            </Stack>

                            {/* Preview image */}
                            <Box sx={{ width: '100%', paddingTop: '56.25%', position: 'relative', bgcolor: 'grey.100', borderRadius: 1, overflow: 'hidden', outline: '1px solid', outlineColor: 'grey.300' }}>
                              {previewUrl ? (
                                <Box component="img" src={previewUrl} alt={resource.name}
                                  sx={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'contain' }} />
                              ) : (
                                <Box sx={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                  <Typography sx={{ fontSize: '0.75rem', color: 'text.disabled' }}>No preview</Typography>
                                </Box>
                              )}
                            </Box>

                            {/* Name + ID */}
                            <Stack direction="row" alignItems="baseline" spacing={1} sx={{ minWidth: 0 }}>
                              <Typography variant="body2" sx={{ fontWeight: 400, lineHeight: 1.3, color: 'text.primary', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }} title={resource.name}>
                                {resource.name}
                              </Typography>
                              <Typography variant="caption" sx={{ color: 'text.disabled', fontFamily: 'monospace', flexShrink: 0, fontSize: '0.75rem' }} title={resource.id}>
                                {shortId(resource.id)}
                              </Typography>
                            </Stack>

                            {/* Last change (archiving is one) */}
                            <Typography variant="caption" color="text.disabled">
                              Updated {formatDate(resource.updated_at)}
                            </Typography>
                          </Box>
                        </Grid>
                      )
                    })}
                  </Grid>
                </Paper>

                {/* Pagination */}
                {lastPage > 1 && (
                  <Stack direction="row" justifyContent="center" alignItems="center" spacing={1} sx={{ py: 1 }}>
                    <IconButton size="small" onClick={() => setPage((p) => p - 1)} disabled={page === 1}>
                      <ChevronLeft fontSize="small" />
                    </IconButton>
                    <Typography variant="caption" color="text.secondary">
                      Page <strong>{page}</strong> of <strong>{lastPage}</strong>
                    </Typography>
                    <IconButton size="small" onClick={() => setPage((p) => p + 1)} disabled={page === lastPage}>
                      <ChevronRight fontSize="small" />
                    </IconButton>
                  </Stack>
                )}
              </Stack>
            )}
          </Box>
        </Box>
      </Box>

      {/* Confirm set all live */}
      <Dialog open={confirmSetAll} onClose={() => setConfirmSetAll(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Set all live?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            All {total} archived {plural(total)} will be set live and returned to the catalogue.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmSetAll(false)}>Cancel</Button>
          <Button variant="contained" onClick={handleSetAllLive} disabled={setAllLoading}
            startIcon={setAllLoading ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Set all live
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={Boolean(report)}
        autoHideDuration={6000}
        onClose={() => setReport(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert severity={report?.severity ?? 'info'} onClose={() => setReport(null)} variant="filled">
          {report?.message}
        </Alert>
      </Snackbar>
    </Stack>
  )
}

export default ArchivedPage
