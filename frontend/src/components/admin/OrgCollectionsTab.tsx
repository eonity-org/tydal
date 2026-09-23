import { useCallback, useEffect, useState } from 'react'
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogContentText, DialogTitle, MenuItem, Stack, TextField, Tooltip, Typography,
} from '@mui/material'
import { Add } from '@mui/icons-material'
import AdminTable, { type AdminColumn } from './AdminTable'
import authService, { type User } from '../../api/authService'
import organizationService from '../../api/organizationService'
import collectionService, { type Collection, type CollectionScheme } from '../../api/collectionService'
import adminService from '../../api/adminService'
import { canManageOrg } from '../../constants/roles'
import { getApiError } from '../../utils/apiError'

/**
 * The current organization's own collections, and creating one.
 *
 * Separate from the platform panel's Collections tab, which lists every
 * organization's and picks the owning organization on creation. This one is
 * always the organization in context, and the choices it offers are narrower by
 * design: the scheme must be one this organization is offered, the search index
 * is derived rather than asked for, and the number of collections is capped.
 */
function OrgCollectionsTab() {
  const [user, setUser] = useState<User | null>(null)
  const [collections, setCollections] = useState<Collection[]>([])
  const [schemes, setSchemes] = useState<CollectionScheme[]>([])
  const [quota, setQuota] = useState<{ used: number; quota: number } | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const [createOpen, setCreateOpen] = useState(false)
  const [form, setForm] = useState({ name: '', description: '', scheme_id: '' })

  const mayManage = canManageOrg(user)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const me = await authService.getUser()
      setUser(me?.data ?? null)
      const orgId = me?.data?.current_organization_id

      if (!orgId) {
        setCollections([]); setSchemes([]); setQuota(null)
      } else {
        const [collectionList, schemeList, org] = await Promise.all([
          collectionService.getCollections(),
          // `for_organization` asks for this organization's menu even when the
          // viewer is a platform administrator — the screen answers "what can
          // THIS organization use", not "what can I see".
          adminService.collectionSchemes.list(undefined, true).then((r) => r.data.schemes).catch(() => []),
          organizationService.getOrganization(orgId).catch(() => null),
        ])
        setCollections(collectionList ?? [])
        setSchemes(schemeList as unknown as CollectionScheme[])
        setQuota(org?.collections ?? null)
      }
      setError(null)
    } catch (e) {
      setError(getApiError(e).message)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void load() }, [load])

  const quotaReached = quota !== null && quota.used >= quota.quota

  const create = async () => {
    if (!form.name.trim() || !form.scheme_id) return
    setSaving(true)
    setError(null)
    try {
      await collectionService.createCollection({
        name: form.name.trim(),
        description: form.description || undefined,
        language: 'en',
        scheme_id: form.scheme_id,
      })
      setNotice(`Collection “${form.name.trim()}” created.`)
      setCreateOpen(false)
      setForm({ name: '', description: '', scheme_id: '' })
      await load()
    } catch (e) {
      setError(getApiError(e).message)
    } finally {
      setSaving(false)
    }
  }

  const columns: AdminColumn<Collection>[] = [
    { id: 'name', label: 'Collection', render: (row) => <Typography variant="body2">{row.name}</Typography> },
    {
      id: 'scheme',
      label: 'Scheme',
      render: (row) => (
        <Typography variant="caption" color="text.secondary">
          {schemes.find((s) => s.id === row.scheme_id)?.display_name ?? '—'}
        </Typography>
      ),
    },
    {
      id: 'search',
      label: 'Search',
      width: 190,
      render: (row) => (row.index_id
        ? <Chip label="indexed" size="small" sx={{ height: 18, fontSize: '0.7rem', bgcolor: 'primary.subtle', color: 'primary.main' }} />
        : (
          // Without an index a collection falls back to database search:
          // keyword only, no facets, no semantic retrieval. Say so, rather than
          // letting it look identical to one that is fully indexed.
          <Tooltip title="No search index: keyword search only, no facets or semantic retrieval. A platform administrator can attach one.">
            <Chip label="database only" size="small" sx={{ height: 18, fontSize: '0.7rem' }} />
          </Tooltip>
        )),
    },
  ]

  return (
    <>
      {error && <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 2 }}>{error}</Alert>}
      {notice && <Alert severity="success" onClose={() => setNotice(null)} sx={{ mb: 2 }}>{notice}</Alert>}

      <Stack direction="row" alignItems="center" justifyContent="space-between" sx={{ mb: 1 }}>
        <Typography variant="caption" color="text.secondary">
          A collection holds resources and fixes the fields they carry.
          {quota && ` ${quota.used} of ${quota.quota} used.`}
        </Typography>

        {mayManage && (
          <Tooltip title={
            quotaReached
              ? `This organization has used all ${quota?.quota} of its collections. A platform administrator can raise the limit.`
              : schemes.length === 0
                ? 'No schemes are available to this organization yet.'
                : ''
          }>
            <span>
              <Button
                size="small" variant="outlined" startIcon={<Add />}
                disabled={quotaReached || schemes.length === 0}
                onClick={() => setCreateOpen(true)}
              >
                New collection
              </Button>
            </span>
          </Tooltip>
        )}
      </Stack>

      <AdminTable<Collection>
        columns={columns}
        rows={collections}
        loading={loading}
        total={collections.length}
        page={1}
        perPage={collections.length || 1}
        onPageChange={() => {}}
      />

      <Dialog open={createOpen} onClose={() => !saving && setCreateOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>New collection</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            The scheme decides which fields its resources carry and cannot be changed afterwards —
            pick the one that matches what you will store. The search index is chosen for you.
          </DialogContentText>
          <Stack spacing={2}>
            <TextField autoFocus fullWidth size="small" label="Name"
              value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} />
            <TextField fullWidth size="small" label="Description (optional)"
              value={form.description} onChange={(e) => setForm((f) => ({ ...f, description: e.target.value }))} />
            <TextField select fullWidth size="small" label="Scheme"
              value={form.scheme_id}
              onChange={(e) => setForm((f) => ({ ...f, scheme_id: e.target.value }))}
              helperText="Only the schemes this organization is offered.">
              {schemes.map((s) => (
                <MenuItem key={s.id} value={s.id}>
                  <Box>
                    <Typography variant="body2">{s.display_name}</Typography>
                    <Typography variant="caption" color="text.secondary">
                      {(s.fields ?? []).length} fields{s.description ? ` · ${s.description}` : ''}
                    </Typography>
                  </Box>
                </MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCreateOpen(false)} disabled={saving}>Cancel</Button>
          <Button variant="contained" onClick={() => void create()}
            disabled={saving || !form.name.trim() || !form.scheme_id}
            startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}>
            Create
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}

export default OrgCollectionsTab
