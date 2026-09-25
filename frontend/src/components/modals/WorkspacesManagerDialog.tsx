import { useState, useEffect } from 'react'
import {
  Alert,
  Dialog,
  DialogTitle,
  DialogContent,
  Box,
  Stack,
  Typography,
  IconButton,
  TextField,
  Button,
  Chip,
  CircularProgress,
  Divider,
  Tooltip,
} from '@mui/material'
import { AutoAwesome, WorkspacesOutlined, Edit, Delete, Close, Add, Lock } from '@mui/icons-material'
import workspaceService, { type Workspace } from '../../api/workspaceService'
import vaultService, { type VaultConfig } from '../../api/vaultService'

export interface WorkspacesManagerDialogProps {
  open: boolean
  onClose: () => void
  workspaces: Workspace[]
  onWorkspacesChanged: () => void
}

export default function WorkspacesManagerDialog({
  open,
  onClose,
  workspaces,
  onWorkspacesChanged,
}: WorkspacesManagerDialogProps) {
  const [editingId, setEditingId] = useState<string | null>(null)
  const [editName, setEditName] = useState('')
  const [editDescription, setEditDescription] = useState('')
  const [creatingNew, setCreatingNew] = useState(false)
  const [newName, setNewName] = useState('')
  const [newDescription, setNewDescription] = useState('')
  const [deletingId, setDeletingId] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)


  // Vault association state
  const [availableVaults, setAvailableVaults] = useState<VaultConfig[]>([])
  // workspaceVaultIds: IDs attached to the workspace currently being edited
  const [workspaceVaultIds, setWorkspaceVaultIds] = useState<Set<string>>(new Set())
  // newVaultIds: selection for the create form — attached right after creation
  const [newVaultIds, setNewVaultIds] = useState<Set<string>>(new Set())
  // allWorkspaceVaultIds: pre-loaded map of wsId → Set<vaultId> for view-mode chips
  const [allWorkspaceVaultIds, setAllWorkspaceVaultIds] = useState<Record<string, Set<string>>>({})
  // wsId → vaultId → why the vault controls that link (it can't be removed here)
  const [linkLocks, setLinkLocks] = useState<Record<string, Record<string, string>>>({})
  const [vaultError, setVaultError] = useState<string | null>(null)

  // Load available Vaults + all workspace Vault associations when dialog opens
  useEffect(() => {
    if (!open) return
    vaultService.active.list()
      .then(res => setAvailableVaults(res.data.vaults))
      .catch(() => setAvailableVaults([]))

    Promise.all(
      workspaces.map(ws =>
        vaultService.workspace.listVaults(ws.id)
          .then(res => ({
            wsId: String(ws.id),
            vaultIds: new Set(res.data.vaults.map(c => c.id)),
            locks: Object.fromEntries(
              res.data.vaults.filter(c => c.association_lock).map(c => [c.id, c.association_lock as string]),
            ),
          }))
          .catch(() => ({ wsId: String(ws.id), vaultIds: new Set<string>(), locks: {} }))
      )
    ).then(results => {
      setAllWorkspaceVaultIds(Object.fromEntries(results.map(r => [r.wsId, r.vaultIds])))
      setLinkLocks(Object.fromEntries(results.map(r => [r.wsId, r.locks])))
    })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const resetState = () => {
    setEditingId(null)
    setEditName('')
    setEditDescription('')
    setCreatingNew(false)
    setNewName('')
    setNewDescription('')
    setDeletingId(null)
    setSaving(false)
    setWorkspaceVaultIds(new Set())
    setNewVaultIds(new Set())
    setAllWorkspaceVaultIds({})
    setLinkLocks({})
    setVaultError(null)
  }

  /**
   * When the vault itself controls a link (TYDAL's VaultService::associationLock),
   * the chip explains why instead of toggling: removing a vault's ingest target,
   * or changing anything while a gallery's published selection decides what it shows.
   */
  const lockFor = (wsId: string | number, vault: VaultConfig, attached: boolean): string | null =>
    attached ? linkLocks[String(wsId)]?.[vault.id] ?? null : vault.attach_lock ?? null

  const handleVaultToggle = async (ws: Workspace, vaultId: string) => {
    const wsKey = String(ws.id)
    const isAttached = workspaceVaultIds.has(vaultId)
    try {
      if (isAttached) {
        await vaultService.workspace.detach(ws.id, vaultId)
        setWorkspaceVaultIds(prev => { const s = new Set(prev); s.delete(vaultId); return s })
        setAllWorkspaceVaultIds(prev => {
          const s = new Set(prev[wsKey] ?? [])
          s.delete(vaultId)
          return { ...prev, [wsKey]: s }
        })
      } else {
        await vaultService.workspace.attach(ws.id, vaultId)
        setWorkspaceVaultIds(prev => new Set([...prev, vaultId]))
        setAllWorkspaceVaultIds(prev => ({
          ...prev,
          [wsKey]: new Set([...(prev[wsKey] ?? []), vaultId]),
        }))
      }
      setVaultError(null)
    } catch (err) {
      // e.g. a 409 from a lock this view didn't know about yet — say why.
      const message = (err as { message?: string })?.message
      setVaultError(message || 'The vault did not accept this change.')
    }
  }

  const handleClose = () => {
    resetState()
    onClose()
  }

  const handleStartEdit = (ws: Workspace) => {
    setCreatingNew(false)
    setDeletingId(null)
    setEditingId(ws.id)
    setEditName(ws.name)
    setEditDescription(ws.description ?? '')
    // Pre-loaded at dialog open — no extra request needed
    setWorkspaceVaultIds(new Set(allWorkspaceVaultIds[String(ws.id)] ?? []))
  }

  const handleSaveEdit = async () => {
    if (!editingId || !editName.trim()) return
    setSaving(true)
    const updated = await workspaceService.updateWorkspace(editingId, {
      name: editName.trim(),
      description: editDescription.trim() || undefined,
    })
    setSaving(false)
    if (updated) {
      setEditingId(null)
      onWorkspacesChanged()
    }
  }

  const handleStartCreate = () => {
    setEditingId(null)
    setDeletingId(null)
    setCreatingNew(true)
    setNewName('')
    setNewDescription('')
    setNewVaultIds(new Set())
  }

  const handleSaveNew = async () => {
    if (!newName.trim()) return
    setSaving(true)
    const created = await workspaceService.createWorkspace({
      name: newName.trim(),
      description: newDescription.trim() || undefined,
    })

    // Attach the vaults picked in the create form — no save-and-reedit dance
    if (created && newVaultIds.size > 0) {
      const attached = new Set<string>()
      await Promise.all(
        [...newVaultIds].map(vaultId =>
          vaultService.workspace.attach(created.id, vaultId)
            .then(() => { attached.add(vaultId) })
            .catch(() => {})
        )
      )
      setAllWorkspaceVaultIds(prev => ({ ...prev, [String(created.id)]: attached }))
    }

    setSaving(false)
    if (created) {
      setCreatingNew(false)
      setNewVaultIds(new Set())
      onWorkspacesChanged()
    }
  }

  const handleConfirmDelete = async () => {
    if (!deletingId) return
    setSaving(true)
    const ok = await workspaceService.deleteWorkspace(deletingId)
    setSaving(false)
    if (ok) {
      setDeletingId(null)
      onWorkspacesChanged()
    }
  }

  return (
    <Dialog open={open} onClose={handleClose} maxWidth="sm" fullWidth>
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', fontWeight: 600, fontSize: '1rem' }}>
        Manage Workspaces
        <Stack direction="row" spacing={1} alignItems="center">
          <Button
            variant="contained"
            size="small"
            startIcon={<Add />}
            onClick={handleStartCreate}
            disabled={creatingNew}
          >
            New workspace
          </Button>
          <IconButton size="small" onClick={handleClose}>
            <Close fontSize="small" />
          </IconButton>
        </Stack>
      </DialogTitle>

      <DialogContent sx={{ px: 3, pt: 1, pb: 3 }}>
        <Stack spacing={1}>
          {/* New workspace form row */}
          {creatingNew && (
            <Box sx={{ p: 2.5, border: '1px solid', borderColor: 'primary.light', borderRadius: 1.5, bgcolor: 'background.paper' }}>
              <Stack spacing={2}>
                <TextField
                  autoFocus
                  size="small"
                  label="Name"
                  value={newName}
                  onChange={(e) => setNewName(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') handleSaveNew() }}
                  fullWidth
                />
                <TextField
                  size="small"
                  label="Description (optional)"
                  value={newDescription}
                  onChange={(e) => setNewDescription(e.target.value)}
                  fullWidth
                />
                {availableVaults.length > 0 && (
                  <>
                    <Divider />
                    <Box>
                      <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600, display: 'block', mb: 1 }}>
                        Vault Configurations
                      </Typography>
                      <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
                        {availableVaults.map((vault) => {
                          const isOn = newVaultIds.has(vault.id)
                          const lock = lockFor('', vault, false)
                          if (lock) return <LockedVaultChip key={vault.id} name={vault.name} reason={lock} on={false} />
                          return (
                            <Chip
                              key={vault.id}
                              label={vault.name}
                              size="small"
                              onClick={() => setNewVaultIds(prev => {
                                const s = new Set(prev)
                                if (s.has(vault.id)) { s.delete(vault.id) } else { s.add(vault.id) }
                                return s
                              })}
                              variant={isOn ? 'filled' : 'outlined'}
                              sx={{
                                cursor: 'pointer',
                                fontWeight: isOn ? 600 : 400,
                                bgcolor: isOn ? 'secondary.main' : undefined,
                                color: isOn ? 'white' : 'text.secondary',
                                borderColor: isOn ? 'secondary.main' : 'divider',
                                '&:hover': { bgcolor: isOn ? 'secondary.dark' : 'action.hover' },
                              }}
                            />
                          )
                        })}
                      </Stack>
                    </Box>
                  </>
                )}
                <Stack direction="row" spacing={1} justifyContent="flex-end">
                  <Button size="small" onClick={() => setCreatingNew(false)} disabled={saving}>Cancel</Button>
                  <Button
                    size="small"
                    variant="contained"
                    onClick={handleSaveNew}
                    disabled={!newName.trim() || saving}
                    startIcon={saving ? <CircularProgress size={12} color="inherit" /> : undefined}
                  >
                    Create
                  </Button>
                </Stack>
              </Stack>
            </Box>
          )}

          {/* Workspace list — editable ones first, machine-managed ones under
              their own heading. With only a chip to tell them apart, an admin
              scanning the list meets rows that look editable and are not; the
              heading says why before they try. */}
          {[...workspaces].sort((a, b) => Number(a.is_system ?? false) - Number(b.is_system ?? false)).map((ws, index, sorted) => (
            <Box key={`row-${ws.id}`}>
            {ws.is_system && !sorted[index - 1]?.is_system && (
              <Box sx={{ pt: index === 0 ? 0 : 1.5, pb: 1 }}>
                <Typography variant="caption" sx={{ color: 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem', display: 'block' }}>
                  Managed by the system
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Created and kept up to date by AITY review batches and by vault writers
                  (for example a gallery’s “open exhibition” step). Their membership belongs to
                  that process, so they cannot be renamed or deleted here, and they are not
                  offered as browsing scopes.
                </Typography>
              </Box>
            )}
            {(
            <Box
              key={ws.id}
              sx={{
                p: 2,
                border: '1px solid',
                borderColor: editingId === ws.id ? 'primary.light' : 'divider',
                borderRadius: 1.5,
                bgcolor: 'background.paper',
              }}
            >
              {editingId === ws.id ? (
                /* Edit form */
                <Stack spacing={2}>
                  <TextField
                    autoFocus
                    size="small"
                    label="Name"
                    value={editName}
                    onChange={(e) => setEditName(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') handleSaveEdit() }}
                    fullWidth
                  />
                  <TextField
                    size="small"
                    label="Description (optional)"
                    value={editDescription}
                    onChange={(e) => setEditDescription(e.target.value)}
                    fullWidth
                  />
                  {availableVaults.length > 0 && (
                    <>
                      <Divider />
                      <Box>
                        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600, display: 'block', mb: 1 }}>
                          Vault Configurations
                        </Typography>
                        <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
                            {availableVaults.map((vault) => {
                              const isOn = workspaceVaultIds.has(vault.id)
                              const lock = lockFor(ws.id, vault, isOn)
                              if (lock) return <LockedVaultChip key={vault.id} name={vault.name} reason={lock} on={isOn} />
                              return (
                                <Chip
                                  key={vault.id}
                                  label={vault.name}
                                  size="small"
                                  onClick={() => handleVaultToggle(ws, vault.id)}
                                  variant={isOn ? 'filled' : 'outlined'}
                                  sx={{
                                    cursor: 'pointer',
                                    fontWeight: isOn ? 600 : 400,
                                    bgcolor: isOn ? 'secondary.main' : undefined,
                                    color: isOn ? 'white' : 'text.secondary',
                                    borderColor: isOn ? 'secondary.main' : 'divider',
                                    '&:hover': { bgcolor: isOn ? 'secondary.dark' : 'action.hover' },
                                  }}
                                />
                              )
                            })}
                          </Stack>
                        {vaultError && (
                          <Alert severity="warning" sx={{ mt: 1 }} onClose={() => setVaultError(null)}>
                            {vaultError}
                          </Alert>
                        )}
                      </Box>
                    </>
                  )}
                  <Stack direction="row" spacing={1} justifyContent="flex-end">
                    <Button size="small" onClick={() => setEditingId(null)} disabled={saving}>Cancel</Button>
                    <Button
                      size="small"
                      variant="contained"
                      onClick={handleSaveEdit}
                      disabled={!editName.trim() || saving}
                      startIcon={saving ? <CircularProgress size={12} color="inherit" /> : undefined}
                    >
                      Save
                    </Button>
                  </Stack>
                </Stack>
              ) : deletingId === ws.id ? (
                /* Delete confirmation — one prompt, but one that states the
                   consequence. A second identical "are you sure?" only teaches
                   people to click through both; what an admin actually cannot
                   tell from here is whether the resources go with it (they do
                   not) and whether anything stops being published (it might). */
                (() => {
                  const memberCount = ws.resources_count ?? 0
                  const vaultNames = availableVaults
                    .filter((v) => allWorkspaceVaultIds[String(ws.id)]?.has(v.id))
                    .map((v) => v.name)

                  return (
                    <Stack spacing={1.5}>
                      <Typography variant="body2" color="error.main" sx={{ fontWeight: 500 }}>
                        Delete “{ws.name}”?
                      </Typography>

                      <Typography variant="caption" color="text.secondary">
                        {memberCount > 0
                          ? `The ${memberCount} ${memberCount === 1 ? 'resource' : 'resources'} in it are not deleted — they stay in their collections and lose only this grouping.`
                          : 'It holds no resources.'}
                      </Typography>

                      {vaultNames.length > 0 && (
                        <Alert severity="warning" sx={{ py: 0.5 }}>
                          <Typography variant="caption">
                            {memberCount > 0
                              ? `This workspace is what publishes those resources through ${vaultNames.join(', ')}. Deleting it takes them off ${vaultNames.length === 1 ? 'that vault' : 'those vaults'}.`
                              : `This workspace is attached to ${vaultNames.join(', ')}. Deleting it removes that link.`}
                          </Typography>
                        </Alert>
                      )}

                      <Stack direction="row" spacing={1} justifyContent="flex-end">
                        <Button size="small" onClick={() => setDeletingId(null)} disabled={saving}>Cancel</Button>
                        <Button
                          size="small"
                          variant="contained"
                          color="error"
                          onClick={handleConfirmDelete}
                          disabled={saving}
                          startIcon={saving ? <CircularProgress size={12} color="inherit" /> : undefined}
                        >
                          Delete workspace
                        </Button>
                      </Stack>
                    </Stack>
                  )
                })()
              ) : (
                /* Normal row */
                <Stack direction="row" alignItems="center" justifyContent="space-between">
                  <Stack direction="row" alignItems="center" spacing={1} flexWrap="wrap" useFlexGap>
                    {ws.is_system
                      ? <AutoAwesome sx={{ fontSize: '1rem', color: 'warning.main', flexShrink: 0 }} />
                      : <WorkspacesOutlined sx={{ fontSize: '1rem', color: 'text.secondary', flexShrink: 0 }} />
                    }
                    <Typography variant="body2" sx={{ fontWeight: 500 }}>{ws.name}</Typography>
                    {ws.is_system && (
                      <Chip label="system" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'warning.50', color: 'warning.dark' }} />
                    )}
                    {ws.is_default && (
                      <Chip label="default" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'grey.100', color: 'text.secondary' }} />
                    )}
                    {ws.description && (
                      <Typography variant="caption" color="text.disabled" noWrap sx={{ maxWidth: 200 }}>
                        {ws.description}
                      </Typography>
                    )}
                    {availableVaults
                      .filter(c => allWorkspaceVaultIds[String(ws.id)]?.has(c.id))
                      .map(vault => {
                        const lock = lockFor(ws.id, vault, true)
                        const chip = (
                          <Chip
                            key={vault.id}
                            label={vault.name}
                            size="small"
                            icon={lock ? <Lock sx={{ fontSize: '0.7rem !important', color: 'white !important' }} /> : undefined}
                            sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'secondary.main', color: 'white' }}
                          />
                        )
                        return lock ? <Tooltip key={vault.id} title={lock}>{chip}</Tooltip> : chip
                      })}
                  </Stack>
                  <Stack direction="row" spacing={0.5}>
                    <IconButton
                      size="small"
                      onClick={() => handleStartEdit(ws)}
                      disabled={ws.is_default || ws.is_system}
                      sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'primary.main' } }}
                    >
                      <Edit sx={{ fontSize: '0.875rem' }} />
                    </IconButton>
                    <IconButton
                      size="small"
                      onClick={() => { setEditingId(null); setDeletingId(ws.id) }}
                      disabled={ws.is_default || ws.is_system}
                      sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'error.main' } }}
                    >
                      <Delete sx={{ fontSize: '0.875rem' }} />
                    </IconButton>
                  </Stack>
                </Stack>
              )}
            </Box>
            )}
            </Box>
          ))}

          {workspaces.length === 0 && !creatingNew && (
            <Box sx={{ py: 3, textAlign: 'center' }}>
              <Typography variant="body2" color="text.secondary">No workspaces yet.</Typography>
            </Box>
          )}
        </Stack>
      </DialogContent>
    </Dialog>
  )
}

/** A vault link the vault itself controls: shown as it is, with the reason, never toggled. */
function LockedVaultChip({ name, reason, on }: { name: string; reason: string; on: boolean }) {
  return (
    <Tooltip title={reason}>
      <Chip
        label={name}
        size="small"
        icon={<Lock sx={{ fontSize: '0.85rem !important', color: on ? 'white !important' : undefined }} />}
        variant={on ? 'filled' : 'outlined'}
        aria-disabled
        sx={{
          cursor: 'not-allowed',
          fontWeight: on ? 600 : 400,
          bgcolor: on ? 'secondary.main' : undefined,
          color: on ? 'white' : 'text.disabled',
          borderColor: on ? 'secondary.main' : 'divider',
          opacity: on ? 0.85 : 0.7,
        }}
      />
    </Tooltip>
  )
}
