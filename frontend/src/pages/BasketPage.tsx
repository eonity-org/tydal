import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  Grid,
  IconButton,
  Paper,
  Stack,
  TextField,
  Tooltip,
  Typography,
} from '@mui/material'
import { Close, WorkspacesOutlined } from '@mui/icons-material'
import { useNavigate } from 'react-router-dom'
import Header from '../components/layout/Header'
import NarrowDivider from '../components/ui/NarrowDivider'
import BulkActions from '../components/basket/BulkActions'
import { useBasket } from '../contexts/BasketContext'
import resourceService, { type ResourceData } from '../api/resourceService'
import workspaceService from '../api/workspaceService'
import { resolveStorageUrl } from '../utils/storageUrl'
import { getApiError } from '../utils/apiError'

/**
 * The basket, laid out like the trash can — a gallery of what is in it, with
 * the actions along the top.
 *
 * This page is what makes a persistent, cross-collection selection safe rather
 * than alarming: "23 in basket" stops being a number you have to trust and
 * becomes a set you can look through and take things out of one by one.
 *
 * Ids whose resources no longer resolve (deleted from another tab, or by
 * someone else) are dropped from the basket on load and reported once, so a
 * stale entry can never quietly inflate a count or a bulk action.
 */
function BasketPage() {
  const navigate = useNavigate()
  const { ids, count, remove, clear, prune, ready } = useBasket()

  const [resources, setResources] = useState<ResourceData[]>([])
  const [loading, setLoading] = useState(true)
  const [dropped, setDropped] = useState(0)
  const [saveOpen, setSaveOpen] = useState(false)
  const [workspaceName, setWorkspaceName] = useState('')
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    if (!ready) return

    let cancelled = false

    const load = async () => {
      setLoading(true)
      if (ids.length === 0) {
        if (!cancelled) { setResources([]); setLoading(false) }
        return
      }

      // Fetched id by id — there is no batch read for arbitrary ids, and the
      // basket is capped at 200, so a small concurrency window is enough.
      const found: ResourceData[] = []
      const missing: string[] = []
      const WINDOW = 6

      for (let i = 0; i < ids.length; i += WINDOW) {
        if (cancelled) return
        const slice = ids.slice(i, i + WINDOW)
        const settled = await Promise.all(slice.map((id) => resourceService.getResource(id)))
        settled.forEach((resource, index) => {
          if (resource) found.push(resource)
          else missing.push(slice[index])
        })
      }

      if (cancelled) return
      setResources(found)
      setDropped(missing.length)
      if (missing.length > 0) prune(missing)
      setLoading(false)
    }

    void load()
    return () => { cancelled = true }
    // `ids` is intentionally not a dependency: removing one item should not
    // re-fetch the other 199. `reload` is the explicit refetch signal.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ready, reload])

  const previewUrl = useCallback((resource: ResourceData): string => {
    const snap = (resource as unknown as { snapshot_file?: { conversion_urls?: Record<string, string>; url?: string } }).snapshot_file
    const urls = snap?.conversion_urls
    if (urls) return resolveStorageUrl(urls.thumbnail || urls.small || urls.original || snap?.url || '')
    return resolveStorageUrl(snap?.url || '')
  }, [])

  /**
   * Graduate the basket into a real workspace: the personal, ephemeral set
   * becomes the persisted, shareable one that a vault can project.
   */
  const saveAsWorkspace = async () => {
    if (!workspaceName.trim()) return
    setSaving(true)
    setSaveError(null)
    try {
      const workspace = await workspaceService.createWorkspace({ name: workspaceName.trim() })
      if (!workspace) throw new Error('The workspace could not be created.')
      await workspaceService.bulkResourceMembership(String(workspace.id), ids, 'add')
      setSaveOpen(false)
      setWorkspaceName('')
    } catch (error) {
      setSaveError(getApiError(error).message)
    } finally {
      setSaving(false)
    }
  }

  const shown = resources.filter((resource) => ids.includes(String(resource.id)))

  return (
    <Stack sx={{ height: '100vh', overflow: 'hidden', minHeight: 0 }}>
      <Header hideCollections hideSearch title="Basket" />

      <Box sx={{ flex: 1, minHeight: 0, display: 'flex', flexDirection: 'column', bgcolor: 'background.paper' }}>

        {/* Toolbar — mirrors the trash can's header strip */}
        <Box sx={{ px: 2, minHeight: '4rem', display: 'flex', alignItems: 'center', gap: 1 }}>
          <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', gap: 1 }}>
            {count > 0 && (
              <>
                <Button size="small" onClick={clear} sx={{ height: '2rem' }}>Empty basket</Button>
                <Button
                  size="small"
                  startIcon={<WorkspacesOutlined />}
                  onClick={() => setSaveOpen(true)}
                  sx={{ height: '2rem' }}
                >
                  Save as workspace
                </Button>
              </>
            )}
          </Box>

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
            {count > 0 ? `${count} ${count === 1 ? 'resource' : 'resources'} in the basket` : ''}
          </Typography>

          {/* Right: the same actions as the bar above the results — reaching
              them from here or from there makes no difference. */}
          <Box sx={{ flex: 1, display: 'flex', justifyContent: 'flex-end' }}>
            <BulkActions
              ids={ids}
              onChanged={() => setReload((n) => n + 1)}
              onRemoved={(gone) => gone.forEach(remove)}
            />
          </Box>
        </Box>

        <NarrowDivider />

        <Box sx={{ flex: 1, minHeight: 0, overflowY: 'auto', overflowX: 'hidden' }}>
          <Box sx={{ px: 3, py: 3 }}>
            {dropped > 0 && (
              <Alert severity="info" onClose={() => setDropped(0)} sx={{ mb: 2 }}>
                {dropped} {dropped === 1 ? 'item is' : 'items are'} no longer available and {dropped === 1 ? 'was' : 'were'} taken out of the basket.
              </Alert>
            )}

            {loading ? (
              <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
                <CircularProgress size={40} />
              </Box>
            ) : count === 0 ? (
              <Paper
                elevation={0}
                sx={{ p: 4, textAlign: 'center', border: '1px solid', borderColor: 'divider', borderRadius: 1.5, minHeight: 400, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1.5 }}
              >
                <Typography variant="body2" color="text.secondary">
                  The basket is empty.
                </Typography>
                <Typography variant="caption" color="text.disabled" sx={{ maxWidth: 420 }}>
                  Tick resources in the grid or the list to gather them here. The basket keeps them
                  across pages, filters and collections until you act on them.
                </Typography>
                <Button size="small" onClick={() => navigate('/')}>Back to the dashboard</Button>
              </Paper>
            ) : (
              <Paper elevation={0} sx={{ width: '100%', p: 2, minHeight: 400 }}>
                <Grid container spacing={3} justifyContent="center">
                  {shown.map((resource) => (
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
                          transition: 'border-color 0.2s ease, background-color 0.2s ease',
                          '&:hover': { borderColor: 'grey.400', bgcolor: 'grey.50' },
                        }}
                      >
                        <Stack direction="row" justifyContent="space-between" alignItems="center">
                          <Chip
                            label={resource.type?.toUpperCase()}
                            size="small"
                            sx={{ height: 18, fontSize: '0.75rem', fontWeight: 500, letterSpacing: 0.5, bgcolor: 'grey.100', color: 'text.secondary', borderRadius: '4px' }}
                          />
                          <Tooltip title="Take out of the basket">
                            <IconButton
                              size="small"
                              onClick={() => remove(String(resource.id))}
                              sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                            >
                              <Close sx={{ fontSize: '1rem' }} />
                            </IconButton>
                          </Tooltip>
                        </Stack>

                        <Box sx={{ width: '100%', paddingTop: '56.25%', position: 'relative', bgcolor: 'grey.100', borderRadius: 1, overflow: 'hidden', outline: '1px solid', outlineColor: 'grey.300' }}>
                          {previewUrl(resource) ? (
                            <Box
                              component="img"
                              src={previewUrl(resource)}
                              alt={resource.name}
                              sx={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', objectFit: 'contain' }}
                            />
                          ) : (
                            <Box sx={{ position: 'absolute', top: 0, left: 0, width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                              <Typography sx={{ fontSize: '0.75rem', color: 'text.disabled' }}>No preview</Typography>
                            </Box>
                          )}
                        </Box>

                        <Stack direction="row" alignItems="baseline" spacing={1} sx={{ minWidth: 0 }}>
                          <Typography
                            variant="body2"
                            sx={{ fontWeight: 400, lineHeight: 1.3, color: 'text.primary', flex: 1, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}
                            title={resource.name}
                          >
                            {resource.name}
                          </Typography>
                          <Typography
                            variant="caption"
                            sx={{ color: 'text.disabled', fontFamily: 'monospace', flexShrink: 0, fontSize: '0.75rem' }}
                            title={String(resource.id)}
                          >
                            {String(resource.id).slice(0, 8)}
                          </Typography>
                        </Stack>

                        <Typography variant="caption" color="text.disabled">
                          {resource.state ?? 'live'}
                        </Typography>
                      </Box>
                    </Grid>
                  ))}
                </Grid>
              </Paper>
            )}
          </Box>
        </Box>
      </Box>

      {/* Graduate the basket into a workspace */}
      <Dialog open={saveOpen} onClose={() => !saving && setSaveOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Save the basket as a workspace</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            Creates a workspace holding these {count} {count === 1 ? 'resource' : 'resources'}. Unlike the
            basket, a workspace is shared, permanent, and can be projected by a vault.
          </DialogContentText>
          <TextField
            autoFocus
            fullWidth
            size="small"
            label="Workspace name"
            value={workspaceName}
            onChange={(e) => setWorkspaceName(e.target.value)}
            placeholder="e.g. Spring exhibition shortlist"
          />
          {saveError && <Alert severity="error" sx={{ mt: 2 }}>{saveError}</Alert>}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSaveOpen(false)} disabled={saving}>Cancel</Button>
          <Button
            variant="contained"
            onClick={() => void saveAsWorkspace()}
            disabled={saving || !workspaceName.trim()}
            startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}
          >
            Create
          </Button>
        </DialogActions>
      </Dialog>
    </Stack>
  )
}

export default BasketPage
