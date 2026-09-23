import { useState, useEffect } from 'react'
import { apiErrorMessage as extractError } from '../../utils/apiError'
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, TextField, Stack, Select, MenuItem, FormControl,
  InputLabel, Typography, Chip, Alert, Switch, FormControlLabel,
  Accordion, AccordionSummary, AccordionDetails,
  Table, TableHead, TableBody, TableRow, TableCell, Box,
  IconButton, Tooltip,
} from '@mui/material'
import { ExpandMore, Storage, DataObject, PauseCircle, PlayCircle, ContentCopy } from '@mui/icons-material'
import AdminTable, { AdminColumn } from './AdminTable'
import adminService, { AdminCollection, AdminCollectionScheme, AdminOrganization, CollectionFormData, CollectionDuplicateData } from '../../api/adminService'

const SUGGESTION_LANGUAGES = [
  { code: 'en', label: 'English' },
  { code: 'es', label: 'Spanish' },
  { code: 'fr', label: 'French' },
  { code: 'de', label: 'German' },
  { code: 'it', label: 'Italian' },
  { code: 'pt', label: 'Portuguese' },
  { code: 'nl', label: 'Dutch' },
  { code: 'pl', label: 'Polish' },
  { code: 'ru', label: 'Russian' },
  { code: 'ar', label: 'Arabic' },
  { code: 'zh', label: 'Chinese' },
  { code: 'ja', label: 'Japanese' },
  { code: 'ko', label: 'Korean' },
  { code: 'tr', label: 'Turkish' },
  { code: 'ca', label: 'Catalan' },
]

const EMPTY_FORM: CollectionFormData = {
  organization_id: '', name: '', description: '', language: 'en', is_active: true, scheme_id: '',
}

function CollectionsTab() {
  const [rows, setRows] = useState<AdminCollection[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)

  const [modal, setModal] = useState<{ open: boolean; mode: 'create' | 'edit'; coll: AdminCollection | null }>({
    open: false, mode: 'create', coll: null,
  })
  const [form, setForm] = useState<CollectionFormData>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  // For org + scheme selects in the form
  const [orgs, setOrgs] = useState<AdminOrganization[]>([])
  const [schemes, setSchemes] = useState<AdminCollectionScheme[]>([])

  // Reference panel: all schemes with full fields, loaded on mount
  const [refSchemes, setRefSchemes] = useState<AdminCollectionScheme[]>([])

  const [deleteDialog, setDeleteDialog] = useState<{ open: boolean; coll: AdminCollection | null }>({ open: false, coll: null })
  const [deleting, setDeleting] = useState(false)

  const [dupDialog, setDupDialog] = useState<{ open: boolean; coll: AdminCollection | null }>({ open: false, coll: null })
  const [dupForm, setDupForm] = useState<CollectionDuplicateData>({ name: '', organization_id: '' })
  const [duplicating, setDuplicating] = useState(false)

  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPage(1) }, 350)
    return () => clearTimeout(t)
  }, [search])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    adminService.collections.list({ page, per_page: 20, search: debouncedSearch })
      .then(res => {
        if (!cancelled) {
          setRows(res.data.collections.data)
          setTotal(res.data.collections.total)
          setLoading(false)
        }
      })
      .catch(() => { if (!cancelled) { setRows([]); setLoading(false) } })
    return () => { cancelled = true }
  }, [page, debouncedSearch, refreshKey])

  const load = () => setRefreshKey(k => k + 1)

  // Load reference schemes once on mount
  useEffect(() => {
    adminService.collectionSchemes.list().then(res => setRefSchemes(res.data.schemes)).catch(() => {})
  }, [])

  // Load orgs and schemes for the form select (only when modal opens)
  useEffect(() => {
    if (!modal.open) return
    adminService.organizations.list({ per_page: 100 }).then(res => setOrgs(res.data.organizations.data)).catch(() => {})
    adminService.collectionSchemes.list().then(res => setSchemes(res.data.schemes)).catch(() => {})
  }, [modal.open])

  // Load orgs when duplicate dialog opens
  useEffect(() => {
    if (!dupDialog.open) return
    adminService.organizations.list({ per_page: 100 }).then(res => setOrgs(res.data.organizations.data)).catch(() => {})
  }, [dupDialog.open])

  const openCreate = () => { setForm(EMPTY_FORM); setFormError(null); setModal({ open: true, mode: 'create', coll: null }) }
  const openEdit = (coll: AdminCollection) => {
    setForm({
      organization_id: coll.organization_id,
      name: coll.name, description: coll.description ?? '', language: coll.language ?? 'en',
      is_active: coll.is_active, scheme_id: coll.scheme_id ?? '',
    })
    setFormError(null)
    setModal({ open: true, mode: 'edit', coll })
  }
  const closeModal = () => setModal(m => ({ ...m, open: false }))

  const handleSave = async () => {
    setSaving(true); setFormError(null)
    try {
      if (modal.mode === 'create') {
        await adminService.collections.create(form)
      } else if (modal.coll) {
        await adminService.collections.update(modal.coll.id, {
          name: form.name, description: form.description, language: form.language || undefined,
          is_active: form.is_active, scheme_id: form.scheme_id || undefined,
        })
      }
      closeModal(); load()
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteDialog.coll) return
    setDeleting(true)
    try {
      await adminService.collections.delete(deleteDialog.coll.id)
      setDeleteDialog({ open: false, coll: null }); load()
    } catch (err) {
      alert(extractError(err))
    } finally {
      setDeleting(false)
    }
  }

  const openDuplicate = (coll: AdminCollection) => {
    setDupForm({ name: `Copy of ${coll.name}`, organization_id: coll.organization_id })
    setDupDialog({ open: true, coll })
  }

  const handleDuplicateSave = async () => {
    if (!dupDialog.coll) return
    setDuplicating(true)
    try {
      await adminService.collections.duplicate(dupDialog.coll.id, dupForm)
      setDupDialog({ open: false, coll: null }); load()
    } catch (err: unknown) {
      alert(extractError(err))
    } finally {
      setDuplicating(false)
    }
  }

  const handleToggleActive = async (coll: AdminCollection) => {
    try {
      await adminService.collections.update(coll.id, { is_active: !coll.is_active })
      load()
    } catch (err: unknown) {
      alert(extractError(err))
    }
  }

  const [copiedId, setCopiedId] = useState<number | null>(null)

  const copyId = (id: number) => {
    navigator.clipboard.writeText(String(id)).then(() => {
      setCopiedId(id)
      setTimeout(() => setCopiedId(null), 1500)
    })
  }

  const columns: AdminColumn<AdminCollection>[] = [
    {
      id: 'name', label: 'Name',
      render: (row) => (
        <Stack spacing={0.25}>
          <Typography variant="body2" fontWeight={500}>{row.name}</Typography>
          <Typography variant="caption" color="text.secondary">{row.slug}</Typography>
          <Tooltip title={copiedId === row.id ? 'Copied!' : 'Copy ID'} placement="right">
            <Stack direction="row" alignItems="center" spacing={0.5} sx={{ cursor: 'pointer', width: 'fit-content' }}
              onClick={() => copyId(row.id)}>
              <Typography variant="caption" sx={{ fontFamily: 'monospace', color: 'text.disabled', fontSize: '0.68rem' }}>
                ID: {row.id}
              </Typography>
              <ContentCopy sx={{ fontSize: '0.65rem', color: 'text.disabled' }} />
            </Stack>
          </Tooltip>
        </Stack>
      ),
    },
    {
      id: 'org', label: 'Organization',
      render: (row) => (
        <Typography variant="body2" color="text.secondary">{row.organization?.name ?? row.organization_id}</Typography>
      ),
    },
    {
      id: 'scheme', label: 'Scheme', align: 'center',
      render: (row) => row.scheme
        ? <Chip label={row.scheme.display_name} size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />
        : <Typography variant="caption" color="text.disabled">—</Typography>,
    },
    {
      id: 'status', label: 'Status', width: 100, align: 'center',
      render: (row) => row.is_active
        ? <Chip label="Active" size="small" sx={{ bgcolor: 'success.light', color: 'success.main' }} />
        : <Chip label="Inactive" size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />,
    },
    {
      id: 'resources', label: 'Resources', width: 90, align: 'center',
      render: (row) => <Typography variant="body2" color="text.secondary">{row.resources_count}</Typography>,
    },
    {
      id: 'created_at', label: 'Created', width: 130, align: 'center',
      render: (row) => <Typography variant="caption" color="text.secondary">{fmtDate(row.created_at)}</Typography>,
    },
    {
      id: '_toggle', label: 'Toggle', width: 70, align: 'center',
      render: (row) => (
        <Tooltip title={row.is_active ? 'Deactivate' : 'Activate'}>
          <IconButton size="small" onClick={() => handleToggleActive(row)}
            sx={{ p: 0.5, color: row.is_active ? 'success.main' : 'warning.main' }}>
            {row.is_active
              ? <PauseCircle sx={{ fontSize: '1.25rem' }} />
              : <PlayCircle sx={{ fontSize: '1.25rem' }} />}
          </IconButton>
        </Tooltip>
      ),
    },
  ]

  return (
    <>
      <AdminTable
        columns={columns}
        rows={rows}
        loading={loading}
        total={total}
        page={page}
        perPage={20}
        onPageChange={setPage}
        onEdit={openEdit}
        onDuplicate={openDuplicate}
        onDelete={(row) => setDeleteDialog({ open: true, coll: row })}
        onAdd={openCreate}
        addLabel="New Collection"
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search collections…"
      />

      {/* Collection Schemes Reference */}
      {refSchemes.length > 0 && (
        <Box sx={{ mt: 4 }}>
          <Typography variant="body2" fontWeight={600} color="text.secondary"
            sx={{ mb: 1, textTransform: 'uppercase', letterSpacing: '0.06em', fontSize: '0.75rem' }}>
            Collection Schemes Reference
          </Typography>
          {refSchemes.map((scheme) => {
            const fields = scheme.fields ?? []
            return (
              <Accordion key={scheme.id} disableGutters elevation={0}
                sx={{ border: '1px solid #d0d7de', '&:not(:last-child)': { borderBottom: 'none' }, '&::before': { display: 'none' } }}>
                <AccordionSummary expandIcon={<ExpandMore sx={{ fontSize: '1.25rem' }} />} sx={{ minHeight: 40, '& .MuiAccordionSummary-content': { my: 0.75 } }}>
                  <Stack direction="row" alignItems="center" spacing={1.5}>
                    <Typography variant="body2" fontWeight={500}>{scheme.display_name}</Typography>
                    {scheme.is_system && <Chip label="system" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'grey.100', color: 'text.secondary' }} />}
                  </Stack>
                </AccordionSummary>
                <AccordionDetails sx={{ pt: 0, pb: 2, px: 2 }}>
                  {scheme.description && (
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>{scheme.description}</Typography>
                  )}
                  {scheme.accepted_mimetypes && scheme.accepted_mimetypes.length > 0 && (
                    <Stack direction="row" spacing={0.5} flexWrap="wrap" sx={{ mb: 1.5 }}>
                      <Typography variant="caption" color="text.secondary" sx={{ mr: 0.5, lineHeight: '20px' }}>Accepts:</Typography>
                      {scheme.accepted_mimetypes.map(t => (
                        <Chip key={t} label={t} size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'grey.100', color: 'text.secondary' }} />
                      ))}
                    </Stack>
                  )}
                  {fields.length > 0 && (
                    <Table size="small" sx={{ '& td, & th': { py: 0.5, px: 1, fontSize: '0.875rem' } }}>
                      <TableHead>
                        <TableRow sx={{ bgcolor: 'grey.100' }}>
                          <TableCell sx={{ fontWeight: 600, color: 'text.secondary', width: 140 }}>Field</TableCell>
                          <TableCell sx={{ fontWeight: 600, color: 'text.secondary', width: 160 }}>Display name</TableCell>
                          <TableCell sx={{ fontWeight: 600, color: 'text.secondary', width: 100 }}>Storage</TableCell>
                          <TableCell sx={{ fontWeight: 600, color: 'text.secondary', width: 80 }}>Type</TableCell>
                          <TableCell sx={{ fontWeight: 600, color: 'text.secondary', width: 80 }}>Facet</TableCell>
                          <TableCell sx={{ fontWeight: 600, color: 'text.secondary', width: 80 }}>Required</TableCell>
                        </TableRow>
                      </TableHead>
                      <TableBody>
                        {fields.map((f) => (
                          <TableRow key={f.name} hover>
                            <TableCell><Typography variant="caption" sx={{ fontFamily: 'monospace' }}>{f.name}</Typography></TableCell>
                            <TableCell>{f.display_name}</TableCell>
                            <TableCell>
                              <Stack direction="row" spacing={0.5} alignItems="center">
                                {f.storage === 'column'
                                  ? <Storage sx={{ fontSize: '0.875rem', color: 'text.disabled' }} />
                                  : <DataObject sx={{ fontSize: '0.875rem', color: 'text.disabled' }} />}
                                <Typography variant="caption" color="text.secondary">{f.storage}</Typography>
                              </Stack>
                            </TableCell>
                            <TableCell><Typography variant="caption" color="text.secondary">{f.type}</Typography></TableCell>
                            <TableCell>
                              {f.is_facet
                                ? <Chip label="facet" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'info.light', color: 'info.main' }} />
                                : <Typography variant="caption" color="text.disabled">—</Typography>}
                            </TableCell>
                            <TableCell>
                              {f.required
                                ? <Chip label="required" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'primary.subtle', color: 'primary.main' }} />
                                : <Typography variant="caption" color="text.disabled">optional</Typography>}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  )}
                </AccordionDetails>
              </Accordion>
            )
          })}
        </Box>
      )}

      {/* Create / Edit modal */}
      <Dialog open={modal.open} onClose={closeModal} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>
          {modal.mode === 'create' ? 'New Collection' : 'Edit Collection'}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            {formError && <Alert severity="error" onClose={() => setFormError(null)}>{formError}</Alert>}

            {modal.mode === 'create' && (
              <FormControl size="small" fullWidth required>
                <InputLabel>Organization</InputLabel>
                <Select label="Organization" value={form.organization_id}
                  onChange={(e) => setForm(f => ({ ...f, organization_id: e.target.value }))}>
                  {orgs.map(o => <MenuItem key={o.id} value={o.id}>{o.name}</MenuItem>)}
                </Select>
              </FormControl>
            )}

            <TextField label="Name" size="small" fullWidth required
              value={form.name} onChange={(e) => setForm(f => ({ ...f, name: e.target.value }))} />

            <FormControl size="small" fullWidth>
              <InputLabel>Scheme (optional)</InputLabel>
              <Select label="Scheme (optional)" value={form.scheme_id ?? ''}
                onChange={(e) => setForm(f => ({ ...f, scheme_id: e.target.value }))}>
                <MenuItem value=""><em>None</em></MenuItem>
                {schemes.map(s => (
                  <MenuItem key={s.id} value={s.id}>
                    {s.display_name}
                    {s.is_system && <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 0.75 }}>(system)</Typography>}
                  </MenuItem>
                ))}
              </Select>
            </FormControl>

            <TextField label="Description" size="small" fullWidth multiline rows={2}
              value={form.description ?? ''} onChange={(e) => setForm(f => ({ ...f, description: e.target.value }))} />

            <FormControl size="small" fullWidth>
              <InputLabel>Suggestion language</InputLabel>
              <Select
                label="Suggestion language"
                value={form.language ?? 'en'}
                onChange={(e) => setForm(f => ({ ...f, language: e.target.value }))}
              >
                {SUGGESTION_LANGUAGES.map(l => (
                  <MenuItem key={l.code} value={l.code}>{l.label} ({l.code})</MenuItem>
                ))}
              </Select>
            </FormControl>

            <FormControlLabel
              control={<Switch checked={form.is_active} size="small"
                onChange={(e) => setForm(f => ({ ...f, is_active: e.target.checked }))} />}
              label={<Typography variant="body2">Active</Typography>} />
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={closeModal} size="small">Cancel</Button>
          <Button variant="contained" size="small" onClick={handleSave} disabled={saving}>
            {saving ? 'Saving…' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Delete confirm */}
      <Dialog open={deleteDialog.open} onClose={() => setDeleteDialog({ open: false, coll: null })} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>Delete Collection</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Delete <strong>{deleteDialog.coll?.name}</strong>? Collections with resources cannot be deleted.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button size="small" onClick={() => setDeleteDialog({ open: false, coll: null })}>Cancel</Button>
          <Button variant="contained" color="error" size="small" onClick={handleDelete} disabled={deleting}>
            {deleting ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Duplicate dialog */}
      <Dialog open={dupDialog.open} onClose={() => setDupDialog({ open: false, coll: null })} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>Duplicate Collection</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            <TextField label="New name" size="small" fullWidth required
              value={dupForm.name} onChange={(e) => setDupForm(f => ({ ...f, name: e.target.value }))} />

            <FormControl size="small" fullWidth required>
              <InputLabel>Organization</InputLabel>
              <Select label="Organization" value={dupForm.organization_id}
                onChange={(e) => setDupForm(f => ({ ...f, organization_id: e.target.value }))}>
                {orgs.map(o => <MenuItem key={o.id} value={o.id}>{o.name}</MenuItem>)}
              </Select>
            </FormControl>
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button size="small" onClick={() => setDupDialog({ open: false, coll: null })}>Cancel</Button>
          <Button variant="contained" size="small" onClick={handleDuplicateSave} disabled={duplicating || !dupForm.name || !dupForm.organization_id}>
            {duplicating ? 'Duplicating…' : 'Duplicate'}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}

function fmtDate(s: string) {
  return new Date(s).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

export default CollectionsTab
