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
} from '@mui/material'
import { RestoreFromTrash, DeleteForever, ArrowUpward, ArrowDownward, ChevronLeft, ChevronRight } from '@mui/icons-material'
import Header from '../components/layout/Header'
import NarrowDivider from '../components/ui/NarrowDivider'
import SegmentedChoice from '../components/ui/SegmentedChoice'
import resourceService, { type ResourceData } from '../api/resourceService'
import { resolveStorageUrl } from '../utils/storageUrl'

function TrashPage() {
  const [resources, setResources] = useState<ResourceData[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)
  const [confirmTarget, setConfirmTarget] = useState<ResourceData | null>(null)
  const [actionLoading, setActionLoading] = useState<string | null>(null)
  const [confirmPurge, setConfirmPurge] = useState(false)
  const [purgeLoading, setPurgeLoading] = useState(false)
  const [confirmRestoreAll, setConfirmRestoreAll] = useState(false)
  const [restoreAllLoading, setRestoreAllLoading] = useState(false)
  const [sortBy, setSortBy] = useState<'deleted_at' | 'name' | 'id'>('deleted_at')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')

  const limit = 48

  const fetchTrashed = useCallback(async (p: number, by: 'deleted_at' | 'name' | 'id', dir: 'asc' | 'desc') => {
    setLoading(true)
    const result = await resourceService.getTrashedResources(p, limit, by, dir)
    if (result) {
      setResources(result.data)
      setTotal(result.total)
      setLastPage(result.last_page)
    }
    setLoading(false)
  }, [])

  useEffect(() => {
    fetchTrashed(page, sortBy, sortDir)
  }, [page, sortBy, sortDir, fetchTrashed])

  const handleSort = (by: 'deleted_at' | 'name' | 'id') => {
    if (sortBy === by) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(by)
      setSortDir(by === 'deleted_at' ? 'desc' : 'asc')
    }
    setPage(1)
  }

  const handleRestoreAll = async () => {
    setRestoreAllLoading(true)
    const count = await resourceService.restoreAll()
    if (count !== null) {
      setResources([])
      setTotal(0)
      setLastPage(1)
      setPage(1)
    }
    setRestoreAllLoading(false)
    setConfirmRestoreAll(false)
  }

  const handlePurge = async () => {
    setPurgeLoading(true)
    const count = await resourceService.purgeTrash()
    if (count !== null) {
      setResources([])
      setTotal(0)
      setLastPage(1)
      setPage(1)
    }
    setPurgeLoading(false)
    setConfirmPurge(false)
  }

  const handleRestore = async (resource: ResourceData) => {
    setActionLoading(resource.id)
    const ok = await resourceService.restoreResource(resource.id)
    if (ok) {
      setResources((prev) => prev.filter((r) => r.id !== resource.id))
      setTotal((prev) => prev - 1)
    }
    setActionLoading(null)
  }

  const handleForceDelete = async () => {
    if (!confirmTarget) return
    setActionLoading(confirmTarget.id)
    const ok = await resourceService.forceDeleteResource(confirmTarget.id)
    if (ok) {
      setResources((prev) => prev.filter((r) => r.id !== confirmTarget.id))
      setTotal((prev) => prev - 1)
    }
    setActionLoading(null)
    setConfirmTarget(null)
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

  const SORTS: { key: 'deleted_at' | 'name' | 'id'; label: string }[] = [
    { key: 'deleted_at', label: 'Date' },
    { key: 'name',       label: 'Name' },
    { key: 'id',         label: 'ID'   },
  ]

  return (
    <Stack sx={{ height: '100vh', overflow: 'hidden', minHeight: 0 }}>
      <Header hideCollections hideSearch title="Trash Can" />

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
            {total > 0 ? `${total} deleted ${total === 1 ? 'resource' : 'resources'}` : ''}
          </Typography>

          {/* Right: Restore all + Delete all — flex:1 mirrors left side */}
          <Box sx={{ flex: 1, display: 'flex', justifyContent: 'flex-end', gap: 1 }}>
            {total > 0 && (
              <Button variant="outlined" size="small" onClick={() => setConfirmRestoreAll(true)} sx={{ height: '2rem' }}>
                Restore all
              </Button>
            )}
            {total > 0 && (
              <Button variant="outlined" size="small" color="error" onClick={() => setConfirmPurge(true)} sx={{ height: '2rem' }}>
                Delete all
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
                <Typography variant="body2" color="text.secondary">
                  Trash is empty
                </Typography>
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
                              <Stack direction="row" spacing={0.5}>
                                <Tooltip title="Restore">
                                  <span>
                                    <IconButton size="small" disabled={isActing} onClick={() => handleRestore(resource)}
                                      sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}>
                                      {isActing ? <CircularProgress size={14} /> : <RestoreFromTrash sx={{ fontSize: '1rem' }} />}
                                    </IconButton>
                                  </span>
                                </Tooltip>
                                <Tooltip title="Delete permanently">
                                  <span>
                                    <IconButton size="small" disabled={isActing} onClick={() => setConfirmTarget(resource)}
                                      sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}>
                                      <DeleteForever sx={{ fontSize: '1rem' }} />
                                    </IconButton>
                                  </span>
                                </Tooltip>
                              </Stack>
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
                                {resource.id.slice(0, 8)}
                              </Typography>
                            </Stack>

                            {/* Deleted date */}
                            <Typography variant="caption" color="text.disabled">
                              Deleted {formatDate(resource.deleted_at)}
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

      {/* Confirm restore all */}
      <Dialog open={confirmRestoreAll} onClose={() => setConfirmRestoreAll(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Restore all?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            All {total} deleted resource{total !== 1 ? 's' : ''} will be restored and returned to the catalogue.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmRestoreAll(false)}>Cancel</Button>
          <Button variant="contained" onClick={handleRestoreAll} disabled={restoreAllLoading}
            startIcon={restoreAllLoading ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Restore all
          </Button>
        </DialogActions>
      </Dialog>

      {/* Confirm purge all */}
      <Dialog open={confirmPurge} onClose={() => setConfirmPurge(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Delete all permanently?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            All {total} deleted resource{total !== 1 ? 's' : ''} will be permanently removed and cannot be recovered.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmPurge(false)}>Cancel</Button>
          <Button variant="contained" color="error" onClick={handlePurge} disabled={purgeLoading}
            startIcon={purgeLoading ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Delete all
          </Button>
        </DialogActions>
      </Dialog>

      {/* Confirm single permanent delete */}
      <Dialog open={!!confirmTarget} onClose={() => setConfirmTarget(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Delete permanently?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            <strong>{confirmTarget?.name}</strong> will be permanently deleted and cannot be recovered.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmTarget(null)}>Cancel</Button>
          <Button variant="contained" color="error" onClick={handleForceDelete} disabled={!!actionLoading}
            startIcon={actionLoading === confirmTarget?.id ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Delete permanently
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default TrashPage
