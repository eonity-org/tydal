import { useState, useEffect } from 'react'
import { apiErrorMessage as extractError } from '../../utils/apiError'
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, TextField, Stack, Select, MenuItem, FormControl,
  InputLabel, Typography, Chip, Alert, Tooltip, IconButton,
} from '@mui/material'
import { PauseCircle, PlayCircle, ContentCopy } from '@mui/icons-material'
import AdminTable, { AdminColumn } from './AdminTable'
import adminService, { AdminOrganization, OrgFormData } from '../../api/adminService'

const ORG_TYPES = ['individual', 'business', 'educational', 'government', 'non_profit'] as const
type OrgType = typeof ORG_TYPES[number]

const TYPE_LABELS: Record<OrgType, string> = {
  individual: 'Individual', business: 'Business', educational: 'Educational',
  government: 'Government', non_profit: 'Non-profit',
}

const EMPTY_FORM: OrgFormData = { name: '', type: 'business', description: '', owner_email: '' }

function OrgsTab() {
  const [rows, setRows] = useState<AdminOrganization[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)

  const [modal, setModal] = useState<{ open: boolean; mode: 'create' | 'edit'; org: AdminOrganization | null }>({
    open: false, mode: 'create', org: null,
  })
  const [form, setForm] = useState<OrgFormData>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const [deleteDialog, setDeleteDialog] = useState<{ open: boolean; org: AdminOrganization | null }>({ open: false, org: null })
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPage(1) }, 350)
    return () => clearTimeout(t)
  }, [search])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    adminService.organizations.list({ page, per_page: 20, search: debouncedSearch })
      .then(res => {
        if (!cancelled) {
          setRows(res.data.organizations.data)
          setTotal(res.data.organizations.total)
          setLoading(false)
        }
      })
      .catch(() => { if (!cancelled) { setRows([]); setLoading(false) } })
    return () => { cancelled = true }
  }, [page, debouncedSearch, refreshKey])

  const load = () => setRefreshKey(k => k + 1)

  const openCreate = () => { setForm(EMPTY_FORM); setFormError(null); setModal({ open: true, mode: 'create', org: null }) }
  const openEdit = (org: AdminOrganization) => {
    setForm({ name: org.name, type: org.type, description: org.description ?? '' })
    setFormError(null)
    setModal({ open: true, mode: 'edit', org })
  }
  const closeModal = () => setModal(m => ({ ...m, open: false }))

  const handleSave = async () => {
    setSaving(true); setFormError(null)
    try {
      if (modal.mode === 'create') {
        await adminService.organizations.create(form)
      } else if (modal.org) {
        await adminService.organizations.update(modal.org.id, { name: form.name, type: form.type, description: form.description })
      }
      closeModal(); load()
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setSaving(false)
    }
  }

  const handleToggleActive = async (org: AdminOrganization) => {
    try {
      if (org.is_active) {
        await adminService.organizations.suspend(org.id)
      } else {
        await adminService.organizations.activate(org.id)
      }
      load()
    } catch (err) {
      alert(extractError(err))
    }
  }

  const handleDelete = async () => {
    if (!deleteDialog.org) return
    setDeleting(true)
    try {
      await adminService.organizations.delete(deleteDialog.org.id)
      setDeleteDialog({ open: false, org: null }); load()
    } catch (err) {
      alert(extractError(err))
    } finally {
      setDeleting(false)
    }
  }

  const [copiedId, setCopiedId] = useState<string | null>(null)

  const copyId = (id: string) => {
    navigator.clipboard.writeText(id).then(() => {
      setCopiedId(id)
      setTimeout(() => setCopiedId(null), 1500)
    })
  }

  const columns: AdminColumn<AdminOrganization>[] = [
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
                {row.id}
              </Typography>
              <ContentCopy sx={{ fontSize: '0.65rem', color: 'text.disabled' }} />
            </Stack>
          </Tooltip>
        </Stack>
      ),
    },
    {
      id: 'type', label: 'Type', width: 130, align: 'center',
      render: (row) => (
        <Chip label={TYPE_LABELS[row.type as OrgType] ?? row.type} size="small"
          sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />
      ),
    },
    {
      id: 'status', label: 'Status', width: 100, align: 'center',
      render: (row) => row.is_active
        ? <Chip label="Active" size="small" sx={{ bgcolor: 'success.light', color: 'success.main' }} />
        : <Chip label="Suspended" size="small" sx={{ bgcolor: 'warning.light', color: 'warning.main' }} />,
    },
    {
      id: 'users', label: 'Users', width: 70, align: 'center',
      render: (row) => <Typography variant="body2" color="text.secondary">{row.users_count}</Typography>,
    },
    {
      id: 'collections', label: 'Collections', width: 100, align: 'center',
      render: (row) => <Typography variant="body2" color="text.secondary">{row.collections_count}</Typography>,
    },
    {
      id: 'created_at', label: 'Created', width: 130, align: 'center',
      render: (row) => <Typography variant="caption" color="text.secondary">{fmtDate(row.created_at)}</Typography>,
    },
    {
      id: '_toggle', label: 'Toggle', width: 70, align: 'center',
      render: (row) => (
        <Tooltip title={row.is_active ? 'Suspend' : 'Activate'}>
          <IconButton size="small" onClick={() => handleToggleActive(row)}
            sx={{ p: 0.5, color: row.is_active ? 'success.main' : 'warning.main' }}>
            {row.is_active ? <PauseCircle sx={{ fontSize: '1.25rem' }} /> : <PlayCircle sx={{ fontSize: '1.25rem' }} />}
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
        onDelete={(row) => setDeleteDialog({ open: true, org: row })}
        onAdd={openCreate}
        addLabel="New Organization"
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search organizations…"
      />

      {/* Create / Edit modal */}
      <Dialog open={modal.open} onClose={closeModal} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>
          {modal.mode === 'create' ? 'New Organization' : 'Edit Organization'}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            {formError && <Alert severity="error" onClose={() => setFormError(null)}>{formError}</Alert>}
            <TextField label="Name" size="small" fullWidth required
              value={form.name} onChange={(e) => setForm(f => ({ ...f, name: e.target.value }))} />
            <FormControl size="small" fullWidth required>
              <InputLabel>Type</InputLabel>
              <Select label="Type" value={form.type} onChange={(e) => setForm(f => ({ ...f, type: e.target.value }))}>
                {ORG_TYPES.map((t) => <MenuItem key={t} value={t}>{TYPE_LABELS[t]}</MenuItem>)}
              </Select>
            </FormControl>
            {modal.mode === 'create' && (
              <TextField label="Owner email" type="email" size="small" fullWidth required
                helperText="Must be an existing user"
                value={form.owner_email ?? ''} onChange={(e) => setForm(f => ({ ...f, owner_email: e.target.value }))} />
            )}
            <TextField label="Description" size="small" fullWidth multiline rows={2}
              value={form.description ?? ''} onChange={(e) => setForm(f => ({ ...f, description: e.target.value }))} />
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
      <Dialog open={deleteDialog.open} onClose={() => setDeleteDialog({ open: false, org: null })} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>Delete Organization</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Delete <strong>{deleteDialog.org?.name}</strong>? Organizations with resources cannot be deleted.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button size="small" onClick={() => setDeleteDialog({ open: false, org: null })}>Cancel</Button>
          <Button variant="contained" color="error" size="small" onClick={handleDelete} disabled={deleting}>
            {deleting ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}

function fmtDate(s: string) {
  return new Date(s).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

export default OrgsTab
