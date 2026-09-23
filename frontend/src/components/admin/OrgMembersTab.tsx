import { useCallback, useEffect, useState } from 'react'
import {
  Alert, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogContentText, DialogTitle, IconButton, MenuItem, Select, Stack, TextField,
  Tooltip, Typography,
} from '@mui/material'
import { Delete } from '@mui/icons-material'
import AdminTable, { type AdminColumn } from './AdminTable'
import authService, { type User } from '../../api/authService'
import organizationService, { type OrganizationMember } from '../../api/organizationService'
import { assignableRoles, canManageOrg, roleLabel, type OrgRole } from '../../constants/roles'
import { getApiError } from '../../utils/apiError'

/**
 * Who belongs to the current organization, and at what role.
 *
 * Distinct from the platform panel's Users tab, which reaches across every
 * organization and can grant platform administration. This one only ever
 * touches `/organizations/{id}/users` for the organization in context — which
 * is why it is a separate component rather than the same one with a flag.
 *
 * Those endpoints existed all along but were unreachable: they authorize on
 * `organizations.update`, whose policy demanded a pivot role that could never be
 * written, so managing your own people was a platform-administrator errand.
 */
function OrgMembersTab() {
  const [user, setUser] = useState<User | null>(null)
  const [members, setMembers] = useState<OrganizationMember[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const [addOpen, setAddOpen] = useState(false)
  const [addEmail, setAddEmail] = useState('')
  const [addRole, setAddRole] = useState<OrgRole>('viewer')
  const [removing, setRemoving] = useState<OrganizationMember | null>(null)

  const organizationId = user?.current_organization_id
  const mayManage = canManageOrg(user)
  const offerableRoles = assignableRoles(user)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const me = await authService.getUser()
      setUser(me?.data ?? null)
      const orgId = me?.data?.current_organization_id
      setMembers(orgId ? await organizationService.getMembers(orgId) : [])
      setError(null)
    } catch (e) {
      setError(getApiError(e).message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void load() }, [load])

  const ownerCount = members.filter((m) => m.pivot?.role === 'owner').length

  const run = async (action: () => Promise<void>, success: string) => {
    setSaving(true)
    setError(null)
    try {
      await action()
      setNotice(success)
      await load()
      return true
    } catch (e) {
      setError(getApiError(e).message)
      return false
    } finally {
      setSaving(false)
    }
  }

  const changeRole = (member: OrganizationMember, role: OrgRole) => {
    if (!organizationId) return
    void run(
      () => organizationService.updateMemberRole(organizationId, member.id, role),
      `${member.name} is now ${roleLabel(role).toLowerCase()}.`,
    )
  }

  const addMember = async () => {
    if (!organizationId || !addEmail.trim()) return
    const ok = await run(
      () => organizationService.addMember(organizationId, { email: addEmail.trim(), role: addRole }),
      `${addEmail.trim()} added.`,
    )
    if (ok) { setAddOpen(false); setAddEmail(''); setAddRole('viewer') }
  }

  const removeMember = async () => {
    if (!organizationId || !removing) return
    const ok = await run(
      () => organizationService.removeMember(organizationId, removing.id),
      `${removing.name} removed from the organization.`,
    )
    if (ok) setRemoving(null)
  }

  const columns: AdminColumn<OrganizationMember>[] = [
    {
      id: 'name',
      label: 'Name',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography variant="body2">{row.name}</Typography>
          {String(row.id) === String(user?.id) && (
            <Chip label="you" size="small" sx={{ height: 18, fontSize: '0.7rem' }} />
          )}
          {row.is_superadmin && (
            <Chip label="platform admin" size="small"
              sx={{ height: 18, fontSize: '0.7rem', bgcolor: 'warning.50', color: 'warning.dark' }} />
          )}
        </Stack>
      ),
    },
    { id: 'email', label: 'Email', render: (row) => <Typography variant="body2" color="text.secondary">{row.email}</Typography> },
    {
      id: 'role',
      label: 'Role',
      width: 200,
      render: (row) => {
        const role = row.pivot?.role
        // A role you may not hand out is shown but not offered — an admin sees
        // that someone is an owner and cannot change it.
        const canEdit = mayManage
          && role !== undefined
          && offerableRoles.includes(role)
          && !(role === 'owner' && ownerCount <= 1)

        if (!canEdit) return <Typography variant="body2" color="text.secondary">{roleLabel(role)}</Typography>

        return (
          <Select
            size="small" value={role} disabled={saving}
            onChange={(e) => changeRole(row, e.target.value as OrgRole)}
            sx={{ minWidth: 150, fontSize: '0.875rem' }}
          >
            {offerableRoles.map((r) => <MenuItem key={r} value={r}>{roleLabel(r)}</MenuItem>)}
          </Select>
        )
      },
    },
  ]

  const canRemove = (row: OrganizationMember) =>
    mayManage && !(row.pivot?.role === 'owner' && ownerCount <= 1)

  return (
    <>
      {error && <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 2 }}>{error}</Alert>}
      {notice && <Alert severity="success" onClose={() => setNotice(null)} sx={{ mb: 2 }}>{notice}</Alert>}

      {!mayManage && !loading && (
        <Alert severity="info" sx={{ mb: 2 }}>
          You can see who belongs to this organization, but changing membership needs an
          administrator or owner role.
        </Alert>
      )}

      <AdminTable<OrganizationMember>
        columns={columns}
        rows={members}
        loading={loading}
        total={members.length}
        page={1}
        perPage={members.length || 1}
        onPageChange={() => {}}
        onAdd={mayManage ? () => setAddOpen(true) : undefined}
        addLabel="Add member"
        // Per-row rather than the table's onDelete: removability varies by row
        // (the last owner cannot go), and an icon that silently does nothing is
        // the dead control this work set out to remove.
        rowActions={(row) => (canRemove(row) ? (
          <Tooltip title="Remove from organization">
            <IconButton size="small" onClick={() => setRemoving(row)} aria-label={`Remove ${row.name}`}
              sx={{ color: 'text.secondary', '&:hover': { color: 'error.main' } }}>
              <Delete sx={{ fontSize: '1rem' }} />
            </IconButton>
          </Tooltip>
        ) : null)}
      />

      <Dialog open={addOpen} onClose={() => !saving && setAddOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Add a member</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            They need a TYDAL account already — this adds an existing user to this organization.
          </DialogContentText>
          <Stack spacing={2}>
            <TextField autoFocus fullWidth size="small" type="email" label="Email address"
              value={addEmail} onChange={(e) => setAddEmail(e.target.value)} />
            <TextField select fullWidth size="small" label="Role"
              value={addRole} onChange={(e) => setAddRole(e.target.value as OrgRole)}>
              {offerableRoles.map((r) => <MenuItem key={r} value={r}>{roleLabel(r)}</MenuItem>)}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setAddOpen(false)} disabled={saving}>Cancel</Button>
          <Button variant="contained" onClick={() => void addMember()}
            disabled={saving || !addEmail.trim()}
            startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Add
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={Boolean(removing)} onClose={() => !saving && setRemoving(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Remove {removing?.name}?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            They lose access to this organization. Their account and the resources they created stay.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRemoving(null)} disabled={saving}>Cancel</Button>
          <Button variant="contained" color="error" onClick={() => void removeMember()} disabled={saving}
            startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Remove
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}

export default OrgMembersTab
