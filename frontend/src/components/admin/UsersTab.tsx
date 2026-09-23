import { useState, useEffect } from 'react'
import { apiErrorMessage as extractError, getApiError } from '../../utils/apiError'
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, TextField, Stack, Switch, FormControlLabel,
  Typography, Chip, Alert, Select, MenuItem, FormControl,
  InputLabel, IconButton, Box, Divider, Tooltip,
} from '@mui/material'
import { Add, Close, PauseCircle, PlayCircle } from '@mui/icons-material'
import AdminTable, { AdminColumn } from './AdminTable'
import adminService, { AdminUser, UserFormData, AdminOrganization, UserOrgAssignment } from '../../api/adminService'
import { ORG_ROLE_VALUES, roleLabel } from '../../constants/roles'

// Was a module-local copy of the role list that had already drifted from the
// one Header used. Both now read src/constants/roles.ts.
const ROLES = ORG_ROLE_VALUES
const EMPTY_FORM: UserFormData = { name: '', email: '', password: '', is_active: true, is_superadmin: false, organizations: [] }

function UsersTab() {
  const [rows, setRows] = useState<AdminUser[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  const [modal, setModal] = useState<{ open: boolean; mode: 'create' | 'edit'; user: AdminUser | null }>({
    open: false, mode: 'create', user: null,
  })
  const [form, setForm] = useState<UserFormData>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)
  // Inline per-field validation errors (422), keyed by request field name.
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  // Org assignment state
  const [allOrgs, setAllOrgs] = useState<AdminOrganization[]>([])
  const [orgToAdd, setOrgToAdd] = useState<string>('')

  const [deleteDialog, setDeleteDialog] = useState<{ open: boolean; user: AdminUser | null }>({ open: false, user: null })
  const [deleting, setDeleting] = useState(false)
  const [refreshKey, setRefreshKey] = useState(0)

  // Debounce search input; reset to page 1 when query changes
  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPage(1) }, 350)
    return () => clearTimeout(t)
  }, [search])

  // Single fetch effect — cancelled flag prevents state update after StrictMode unmount
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    adminService.users.list({ page, per_page: 20, search: debouncedSearch })
      .then(res => {
        if (!cancelled) {
          setRows(res.data.users.data)
          setTotal(res.data.users.total)
          setLoading(false)
        }
      })
      .catch(() => { if (!cancelled) { setRows([]); setLoading(false) } })
    return () => { cancelled = true }
  }, [page, debouncedSearch, refreshKey])

  const load = () => setRefreshKey(k => k + 1)

  // Load all orgs once for the selector
  useEffect(() => {
    adminService.organizations.list({ per_page: 200 })
      .then(res => setAllOrgs(res.data.organizations.data))
      .catch(() => {})
  }, [])

  const openCreate = () => {
    setForm(EMPTY_FORM)
    setOrgToAdd('')
    setFormError(null)
    setModal({ open: true, mode: 'create', user: null })
  }

  const openEdit = async (user: AdminUser) => {
    setFormError(null)
    setOrgToAdd('')
    // Load full user data (includes organizations with pivot role)
    try {
      const res = await adminService.users.show(user.id)
      const fullUser = res.data.user
      setForm({
        name: fullUser.name,
        email: fullUser.email,
        password: '',
        is_active: fullUser.is_active,
        is_superadmin: fullUser.is_superadmin,
        organizations: (fullUser.organizations || []).map(o => ({ id: o.id, role: o.pivot.role })),
      })
      setModal({ open: true, mode: 'edit', user: fullUser })
    } catch {
      // Fallback without org data
      setForm({ name: user.name, email: user.email, password: '', is_active: user.is_active, is_superadmin: user.is_superadmin, organizations: [] })
      setModal({ open: true, mode: 'edit', user })
    }
  }

  const closeModal = () => setModal(m => ({ ...m, open: false }))

  /** Drop a field's inline error as the user edits it. */
  const clearField = (key: string) =>
    setFieldErrors(fe => { if (!fe[key]) return fe; const next = { ...fe }; delete next[key]; return next })

  const handleAddOrg = () => {
    if (!orgToAdd) return
    const already = (form.organizations || []).some(o => o.id === orgToAdd)
    if (already) return
    setForm(f => ({ ...f, organizations: [...(f.organizations || []), { id: orgToAdd, role: 'viewer' }] }))
    setOrgToAdd('')
  }

  const handleRemoveOrg = (id: string) => {
    setForm(f => ({ ...f, organizations: (f.organizations || []).filter(o => o.id !== id) }))
  }

  const handleOrgRoleChange = (id: string, role: string) => {
    setForm(f => ({
      ...f,
      organizations: (f.organizations || []).map(o => o.id === id ? { ...o, role } : o),
    }))
  }

  const handleSave = async () => {
    setSaving(true)
    setFormError(null)
    setFieldErrors({})
    try {
      if (modal.mode === 'create') {
        await adminService.users.create(form)
      } else if (modal.user) {
        const payload: Partial<UserFormData> = {
          name: form.name,
          email: form.email,
          is_active: form.is_active,
          is_superadmin: form.is_superadmin,
          organizations: form.organizations,
        }
        if (form.password) payload.password = form.password
        await adminService.users.update(modal.user.id, payload)
      }
      closeModal()
      load()
    } catch (err: unknown) {
      const info = getApiError(err)
      // Show field-level messages inline (422); keep a summary in the banner only
      // when there are no specific fields to point at.
      setFieldErrors(info.fieldErrors ?? {})
      setFormError(info.fieldErrors ? null : info.message)
    } finally {
      setSaving(false)
    }
  }

  const handleToggleActive = async (user: AdminUser) => {
    try {
      await adminService.users.update(user.id, { is_active: !user.is_active })
      load()
    } catch (err: unknown) {
      alert(extractError(err))
    }
  }

  const handleDelete = async () => {
    if (!deleteDialog.user) return
    setDeleting(true)
    try {
      await adminService.users.delete(deleteDialog.user.id)
      setDeleteDialog({ open: false, user: null })
      load()
    } catch (err: unknown) {
      alert(extractError(err))
    } finally {
      setDeleting(false)
    }
  }

  const availableOrgs = allOrgs.filter(o => !(form.organizations || []).some(a => a.id === o.id))

  const columns: AdminColumn<AdminUser>[] = [
    {
      id: 'name', label: 'Name',
      render: (row) => (
        <Stack>
          <Typography variant="body2" fontWeight={500}>{row.name}</Typography>
          <Typography variant="caption" color="text.secondary">{row.email}</Typography>
        </Stack>
      ),
    },
    {
      id: 'role', label: 'Role', width: 120, align: 'center',
      render: (row) => row.is_superadmin
        ? <Chip label="Superadmin" size="small" sx={{ bgcolor: 'primary.subtle', color: 'primary.main', fontWeight: 600 }} />
        : <Chip label="User" size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />,
    },
    {
      id: 'status', label: 'Status', width: 100, align: 'center',
      render: (row) => row.is_active
        ? <Chip label="Active" size="small" sx={{ bgcolor: 'success.light', color: 'success.main' }} />
        : <Chip label="Inactive" size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />,
    },
    {
      id: 'orgs', label: 'Orgs', width: 70, align: 'center',
      render: (row) => <Typography variant="body2" color="text.secondary">{row.organizations_count}</Typography>,
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
        onDelete={(row) => setDeleteDialog({ open: true, user: row })}
        onAdd={openCreate}
        addLabel="New User"
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search by name or email…"
      />

      {/* Create / Edit modal */}
      <Dialog open={modal.open} onClose={closeModal} maxWidth="sm" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>
          {modal.mode === 'create' ? 'New User' : 'Edit User'}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            {formError && <Alert severity="error" onClose={() => setFormError(null)}>{formError}</Alert>}

            <TextField label="Name" size="small" fullWidth required
              error={!!fieldErrors.name} helperText={fieldErrors.name}
              value={form.name} onChange={(e) => { setForm(f => ({ ...f, name: e.target.value })); clearField('name') }} />
            <TextField label="Email" type="email" size="small" fullWidth required
              error={!!fieldErrors.email} helperText={fieldErrors.email}
              value={form.email} onChange={(e) => { setForm(f => ({ ...f, email: e.target.value })); clearField('email') }} />
            <TextField
              label={modal.mode === 'create' ? 'Password' : 'New password (leave blank to keep)'}
              type="password" size="small" fullWidth
              required={modal.mode === 'create'}
              error={!!fieldErrors.password} helperText={fieldErrors.password}
              value={form.password} onChange={(e) => { setForm(f => ({ ...f, password: e.target.value })); clearField('password') }} />

            <Stack direction="row" spacing={3}>
              <FormControlLabel
                control={<Switch checked={form.is_active} onChange={(e) => setForm(f => ({ ...f, is_active: e.target.checked }))} size="small" />}
                label={<Typography variant="body2">Active</Typography>} />
              <FormControlLabel
                control={<Switch checked={form.is_superadmin} onChange={(e) => setForm(f => ({ ...f, is_superadmin: e.target.checked }))} size="small" />}
                label={<Typography variant="body2">Superadmin</Typography>} />
            </Stack>

            <Divider />

            {/* Organization assignments */}
            <Typography variant="body2" fontWeight={600} color="text.primary">
              Organizations
            </Typography>

            {/* Assigned orgs list */}
            <Stack spacing={1}>
              {(form.organizations || []).length === 0 && (
                <Typography variant="caption" color="text.secondary">No organizations assigned.</Typography>
              )}
              {(form.organizations || []).map((assignment: UserOrgAssignment) => {
                const org = allOrgs.find(o => o.id === assignment.id)
                return (
                  <Box
                    key={assignment.id}
                    sx={{ display: 'flex', alignItems: 'center', gap: 1, p: 1, border: '1px solid', borderColor: 'divider', borderRadius: 1 }}
                  >
                    <Typography variant="body2" sx={{ flex: 1, fontWeight: 500 }}>
                      {org?.name ?? assignment.id}
                    </Typography>
                    <FormControl size="small" sx={{ minWidth: 110 }}>
                      <Select
                        value={assignment.role}
                        onChange={(e) => handleOrgRoleChange(assignment.id, e.target.value)}
                        sx={{ fontSize: '0.875rem' }}
                      >
                        {ROLES.map(r => (
                          <MenuItem key={r} value={r} sx={{ fontSize: '0.875rem' }}>{roleLabel(r)}</MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                    <IconButton size="small" onClick={() => handleRemoveOrg(assignment.id)} sx={{ color: 'text.secondary' }}>
                      <Close sx={{ fontSize: '1rem' }} />
                    </IconButton>
                  </Box>
                )
              })}
            </Stack>

            {/* Add org row */}
            {availableOrgs.length > 0 && (
              <Stack direction="row" spacing={1} alignItems="center">
                <FormControl size="small" sx={{ flex: 1 }}>
                  <InputLabel sx={{ fontSize: '0.875rem' }}>Add organization</InputLabel>
                  <Select
                    value={orgToAdd}
                    label="Add organization"
                    onChange={(e) => setOrgToAdd(e.target.value)}
                    sx={{ fontSize: '0.875rem' }}
                  >
                    {availableOrgs.map(o => (
                      <MenuItem key={o.id} value={o.id} sx={{ fontSize: '0.875rem' }}>{o.name}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
                <Button variant="outlined" size="small" startIcon={<Add />} onClick={handleAddOrg} disabled={!orgToAdd}>
                  Add
                </Button>
              </Stack>
            )}
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
      <Dialog open={deleteDialog.open} onClose={() => setDeleteDialog({ open: false, user: null })} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>Delete User</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Delete <strong>{deleteDialog.user?.name}</strong>? This action cannot be undone.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button size="small" onClick={() => setDeleteDialog({ open: false, user: null })}>Cancel</Button>
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

export default UsersTab
