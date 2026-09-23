import { useEffect, useState } from 'react'
import {
  Alert, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogContentText, DialogTitle, MenuItem, Stack, TextField, Tooltip, Typography,
} from '@mui/material'
import { ContentCopy, Lock, Public } from '@mui/icons-material'
import AdminTable, { AdminColumn } from './AdminTable'
import adminService, {
  type AdminCollectionScheme, type AdminOrganization, type AdminSearchIndex, type Visibility,
} from '../../api/adminService'
import { getApiError } from '../../utils/apiError'
import SchemeEditor from './SchemeEditor'

/**
 * Collection schemes and search indexes — the two platform-level things that
 * collections point at, and who each of them is offered to.
 *
 * The pair is deliberately on one screen because they are the same decision
 * seen twice, but they are not equal in weight: scheme visibility curates a
 * menu, while index visibility decides whose documents physically share an
 * index. The copy says so, because "restricted" looks identical in both.
 *
 * A scheme's `fields` drive the dynamic form, the Elasticsearch mapping, the
 * facet list, validation and mimetype gating simultaneously. Changing them
 * under existing collections leaves the mapping disagreeing with the documents,
 * so a scheme in use is locked and Clone is the way forward.
 */
function SchemesTab() {
  const [schemes, setSchemes] = useState<AdminCollectionScheme[]>([])
  const [indexes, setIndexes] = useState<AdminSearchIndex[]>([])
  const [orgs, setOrgs] = useState<AdminOrganization[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const [editingScheme, setEditingScheme] = useState<AdminCollectionScheme | null>(null)
  const [cloning, setCloning] = useState<AdminCollectionScheme | null>(null)
  const [cloneOrg, setCloneOrg] = useState('')

  const [editing, setEditing] = useState<
    { kind: 'scheme' | 'index'; id: string; label: string; visibility: Visibility; orgIds: string[] } | null
  >(null)

  const load = async () => {
    setLoading(true)
    try {
      const [s, i, o] = await Promise.all([
        adminService.collectionSchemes.list(),
        adminService.searchIndexes.list(),
        adminService.organizations.list({ per_page: 200 }),
      ])
      setSchemes(s.data.schemes)
      setIndexes(i.data.indexes)
      setOrgs(o.data.organizations.data ?? [])
      setError(null)
    } catch (e) {
      setError(getApiError(e).message)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => { void load() }, [])

  const run = async (action: () => Promise<unknown>, success: string) => {
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

  const visibilityChip = (visibility: Visibility | undefined, organizations?: { id: string; name: string }[]) => {
    if (visibility !== 'restricted') {
      return <Chip icon={<Public sx={{ fontSize: '0.8rem' }} />} label="All organizations" size="small" sx={{ height: 20, fontSize: '0.7rem' }} />
    }
    const names = (organizations ?? []).map((o) => o.name)
    return (
      <Chip
        label={names.length > 0 ? names.join(', ') : 'Restricted (nobody yet)'}
        size="small"
        color="primary"
        sx={{ height: 20, fontSize: '0.7rem' }}
      />
    )
  }

  const schemeColumns: AdminColumn<AdminCollectionScheme>[] = [
    {
      id: 'name',
      label: 'Scheme',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center" flexWrap="wrap" useFlexGap>
          <Typography variant="body2">{row.display_name}</Typography>
          <Typography variant="caption" color="text.disabled" sx={{ fontFamily: 'monospace' }}>{row.name}</Typography>
          {row.is_system && <Chip label="system" size="small" sx={{ height: 18, fontSize: '0.7rem', bgcolor: 'warning.50', color: 'warning.dark' }} />}
        </Stack>
      ),
    },
    { id: 'fields', label: 'Fields', width: 90, render: (row) => <Typography variant="body2">{row.fields?.length ?? 0}</Typography> },
    {
      id: 'usage',
      label: 'In use by',
      width: 150,
      render: (row) => {
        const count = row.collections_count ?? 0
        if (count === 0) return <Typography variant="caption" color="text.disabled">Unused — editable</Typography>
        return (
          <Tooltip title="Its fields are locked: changing them would leave the search mapping disagreeing with existing documents. Clone it to make a variant.">
            <Stack direction="row" spacing={0.5} alignItems="center">
              <Lock sx={{ fontSize: '0.9rem', color: 'text.disabled' }} />
              <Typography variant="body2">{count} collection{count === 1 ? '' : 's'}</Typography>
            </Stack>
          </Tooltip>
        )
      },
    },
    { id: 'visibility', label: 'Offered to', render: (row) => visibilityChip(row.visibility, row.organizations) },
  ]

  const indexColumns: AdminColumn<AdminSearchIndex>[] = [
    {
      id: 'name',
      label: 'Search index',
      render: (row) => (
        <Stack direction="row" spacing={1} alignItems="center">
          <Typography variant="body2">{row.display_name}</Typography>
          <Typography variant="caption" color="text.disabled" sx={{ fontFamily: 'monospace' }}>{row.index_name}</Typography>
          {!row.is_active && <Chip label="inactive" size="small" sx={{ height: 18, fontSize: '0.7rem' }} />}
        </Stack>
      ),
    },
    { id: 'visibility', label: 'Available to', render: (row) => visibilityChip(row.visibility, row.organizations) },
  ]

  const openVisibility = (
    kind: 'scheme' | 'index',
    row: AdminCollectionScheme | AdminSearchIndex,
    label: string,
  ) => setEditing({
    kind,
    id: row.id,
    label,
    visibility: row.visibility ?? 'global',
    orgIds: (row.organizations ?? []).map((o) => o.id),
  })

  const saveVisibility = () => {
    if (!editing) return
    const payload = { visibility: editing.visibility, organization_ids: editing.visibility === 'restricted' ? editing.orgIds : [] }
    void run(
      () => editing.kind === 'scheme'
        ? adminService.collectionSchemes.update(editing.id, payload)
        : adminService.searchIndexes.update(editing.id, payload),
      `${editing.label} updated.`,
    ).then((ok) => { if (ok) setEditing(null) })
  }

  const doClone = () => {
    if (!cloning || !cloneOrg) return
    void run(
      () => adminService.collectionSchemes.clone(cloning.id, { organization_id: cloneOrg }),
      'Scheme cloned — editable until a collection uses it.',
    ).then((ok) => { if (ok) { setCloning(null); setCloneOrg('') } })
  }

  return (
    <Box>
      {error && <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 2 }}>{error}</Alert>}
      {notice && <Alert severity="success" onClose={() => setNotice(null)} sx={{ mb: 2 }}>{notice}</Alert>}

      <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
        A scheme is the field contract a collection follows; an index is where its documents live.
        Both are platform-level and shared by default — restrict one to give a single organization
        its own.
      </Typography>

      <AdminTable<AdminCollectionScheme>
        columns={schemeColumns}
        rows={schemes}
        loading={loading}
        total={schemes.length}
        page={1}
        perPage={schemes.length || 1}
        onPageChange={() => {}}
        rowActions={(row) => (
          <Stack direction="row" spacing={0.5}>
            <Button size="small" onClick={() => setEditingScheme(row)}>Edit</Button>
            <Tooltip title="Copy into one organization, so its fields can be varied there">
              <span>
                <Button size="small" startIcon={<ContentCopy sx={{ fontSize: '0.9rem' }} />} onClick={() => setCloning(row)}>
                  Clone
                </Button>
              </span>
            </Tooltip>
            <Button size="small" onClick={() => openVisibility('scheme', row, row.display_name)}>
              Visibility
            </Button>
          </Stack>
        )}
      />

      <Box sx={{ mt: 4 }}>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
          Restricting an <strong>index</strong> is not the same kind of act as restricting a scheme:
          it decides whose documents are physically stored together. New collections are given the
          most specific index their organization is entitled to.
        </Typography>

        <AdminTable<AdminSearchIndex>
          columns={indexColumns}
          rows={indexes}
          loading={loading}
          total={indexes.length}
          page={1}
          perPage={indexes.length || 1}
          onPageChange={() => {}}
          rowActions={(row) => (
            <Button size="small" onClick={() => openVisibility('index', row, row.display_name)}>
              Visibility
            </Button>
          )}
        />
      </Box>

      {editingScheme && (
        <SchemeEditor
          scheme={editingScheme}
          saving={saving}
          onClose={() => setEditingScheme(null)}
          onSave={(payload) => {
            void run(
              () => adminService.collectionSchemes.update(editingScheme.id, payload),
              `${payload.display_name} saved.`,
            ).then((ok) => { if (ok) setEditingScheme(null) })
          }}
        />
      )}

      {/* Visibility editor */}
      <Dialog open={Boolean(editing)} onClose={() => !saving && setEditing(null)} maxWidth="sm" fullWidth>
        <DialogTitle>Who may use “{editing?.label}”?</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <TextField
              select size="small" label="Offered to"
              value={editing?.visibility ?? 'global'}
              onChange={(e) => setEditing((s) => s && { ...s, visibility: e.target.value as Visibility })}
            >
              <MenuItem value="global">All organizations</MenuItem>
              <MenuItem value="restricted">Selected organizations</MenuItem>
            </TextField>

            {editing?.visibility === 'restricted' && (
              <TextField
                select size="small" label="Organizations"
                value={editing.orgIds}
                onChange={(e) => setEditing((s) => s && {
                  ...s,
                  orgIds: typeof e.target.value === 'string' ? e.target.value.split(',') : (e.target.value as unknown as string[]),
                })}
                SelectProps={{ multiple: true }}
                helperText="One organization is the interesting case — it gives that customer a scheme, or an index, of their own."
              >
                {orgs.map((o) => <MenuItem key={o.id} value={o.id}>{o.name}</MenuItem>)}
              </TextField>
            )}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setEditing(null)} disabled={saving}>Cancel</Button>
          <Button
            variant="contained" onClick={saveVisibility}
            disabled={saving || (editing?.visibility === 'restricted' && editing.orgIds.length === 0)}
            startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}
          >
            Save
          </Button>
        </DialogActions>
      </Dialog>

      {/* Clone */}
      <Dialog open={Boolean(cloning)} onClose={() => !saving && setCloning(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Clone “{cloning?.display_name}”</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            Makes a copy restricted to one organization. Because the copy has no collections yet, its
            fields stay editable — which is how you vary a contract for one customer without
            disturbing anybody else&apos;s search mapping.
          </DialogContentText>
          <TextField
            select fullWidth size="small" label="For which organization"
            value={cloneOrg} onChange={(e) => setCloneOrg(e.target.value)}
          >
            {orgs.map((o) => <MenuItem key={o.id} value={o.id}>{o.name}</MenuItem>)}
          </TextField>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setCloning(null)} disabled={saving}>Cancel</Button>
          <Button
            variant="contained" onClick={doClone} disabled={saving || !cloneOrg}
            startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}
          >
            Clone
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  )
}

export default SchemesTab
