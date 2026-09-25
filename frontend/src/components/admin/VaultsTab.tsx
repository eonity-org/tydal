import { useState, useEffect, useRef, useMemo } from 'react'
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, TextField, Stack, Switch, FormControlLabel,
  Typography, Chip, Alert, Tooltip, MenuItem, IconButton,
  FormControl, InputLabel, Select,
} from '@mui/material'
import { Warning, ContentCopy, Key, Block, Schedule, Share, Tune, Lock } from '@mui/icons-material'
import AdminTable, { AdminColumn } from './AdminTable'
import vaultService, { VaultConfig, VaultFormData, VaultKeyEntry, SignedUrlGrant, VAULT_PURPOSES, VaultPurpose, VAULT_STATES, VaultState, PUBLIC_BASE, keyAbilityOptions, VaultCapabilityDef, VaultPresetEntry } from '../../api/vaultService'
import VaultCapabilityGrid, { IngestTargetOption } from './VaultCapabilityGrid'
import SectionTitle from './SectionTitle'
import adminService, { AdminOrganization, AdminWorkspace, AdminCollection } from '../../api/adminService'
import { CHIP_COLORS } from '../../contexts/ThemeContext'

const EMPTY_FORM: VaultFormData = {
  organization_id: '',
  name: '',
  slug: '',
  description: '',
  purpose: 'delivery',
  state: 'private',
  has_public_workspace: false,
  is_downloadable: false,
  hash_ttl_hours: null,
  allowed_ips: [],
  exposure_policy: null,
  base_url: '',
  workspace_ids: [],
}

function slugify(s: string): string {
  return s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
}

function VaultsTab() {
  const [rows, setRows] = useState<VaultConfig[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)

  // Two edit surfaces over one vault: `create`/`basic` handle identity (name,
  // purpose, slug, description); `sharing` handles the boundary — state,
  // addresses, access keys, signed URLs, salt. Reached by separate buttons so
  // neither form is a wall.
  // The vault admin is deliberately several small forms over one record, reached
  // by separate row buttons, rather than one wall: `create`/`basic` are identity;
  // `access` is keys; `sharing` is reach (state, addresses, signed URLs,
  // projection); `revocation` is the kill-switches (revoke grants, rotate salt).
  const [modal, setModal] = useState<{ open: boolean; view: 'create' | 'basic' | 'capabilities' | 'access' | 'sharing' | 'revocation'; vault: VaultConfig | null }>({
    open: false, view: 'create', vault: null,
  })
  const [form, setForm] = useState<VaultFormData>(EMPTY_FORM)
  const [orgs, setOrgs] = useState<AdminOrganization[]>([])
  // The vault's organization's workspaces and collections, for the pickers.
  const [orgLists, setOrgLists] = useState<{ workspaces: AdminWorkspace[]; collections: AdminCollection[] } | undefined>()
  // The capability vocabulary + every purpose's preset, served by the backend so
  // the defaults are never restated here (see vaultService.VAULT_WRITE_METHODS).
  const [capabilities, setCapabilities] = useState<VaultCapabilityDef[]>([])
  const [presets, setPresets] = useState<Record<VaultPurpose, VaultPresetEntry> | null>(null)
  const [allowedIpsText, setAllowedIpsText] = useState('')
  const [saltRotated, setSaltRotated] = useState(false)
  const [purposeWarning, setPurposeWarning] = useState(false)

  // Vault keys (edit mode)
  const [keys, setKeys] = useState<VaultKeyEntry[]>([])
  const [newKeyName, setNewKeyName] = useState('')
  const [newKeyAbilities, setNewKeyAbilities] = useState<string[]>(['read'])
  const [mintedKey, setMintedKey] = useState<string | null>(null)
  const [keyBusy, setKeyBusy] = useState(false)
  const [showRevoked, setShowRevoked] = useState(false)
  const mintedKeyRef = useRef<HTMLDivElement | null>(null)

  // Signed URLs (edit mode) — stateless grants, nothing to list
  const [grantHours, setGrantHours] = useState(24)
  const [mintedGrant, setMintedGrant] = useState<SignedUrlGrant | null>(null)
  // Collapsed by default — a vault key is the ordinary way to share.
  const [showSignedUrls, setShowSignedUrls] = useState(false)
  const [grantBusy, setGrantBusy] = useState(false)
  const [grantsRevoked, setGrantsRevoked] = useState(false)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const [deleteDialog, setDeleteDialog] = useState<{ open: boolean; vault: VaultConfig | null }>({
    open: false, vault: null,
  })
  const [deleting, setDeleting] = useState(false)

  // Debounce search
  useEffect(() => {
    const t = setTimeout(() => { setDebouncedSearch(search); setPage(1) }, 350)
    return () => clearTimeout(t)
  }, [search])

  // Load rows
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    vaultService.admin.list({ page, per_page: 20, search: debouncedSearch })
      .then(res => {
        if (!cancelled) {
          setRows(res.data.vaults)
          setTotal(res.meta.pagination.total)
          setLoading(false)
        }
      })
      .catch(() => { if (!cancelled) { setRows([]); setLoading(false) } })
    return () => { cancelled = true }
  }, [page, debouncedSearch, refreshKey])

  const load = () => setRefreshKey(k => k + 1)

  // The mint button sits near the bottom of a long scrolling dialog — bring the
  // one-time plaintext into view so it can't be minted-and-lost off-screen.
  useEffect(() => {
    if (mintedKey) mintedKeyRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, [mintedKey])

  // Load organizations for the create form's org selector
  useEffect(() => {
    adminService.organizations.list({ page: 1, per_page: 100 })
      .then(res => setOrgs(res.data.organizations.data))
      .catch(() => setOrgs([]))
  }, [])

  // Workspaces and collections are picked by name from the vault's own
  // organization — their ids appear nowhere else in the admin.
  const listsNeeded = modal.open && ['create', 'capabilities', 'sharing'].includes(modal.view)
  const orgId = listsNeeded ? form.organization_id ?? '' : ''
  useEffect(() => {
    if (!orgId) { setOrgLists(undefined); return }
    let stale = false
    Promise.all([
      adminService.organizations.workspaces(orgId),
      adminService.collections.list({ organization_id: orgId, per_page: 100 }),
    ])
      .then(([ws, cols]) => {
        if (!stale) setOrgLists({ workspaces: ws.data.workspaces, collections: cols.data.collections.data })
      })
      .catch(() => { if (!stale) setOrgLists(undefined) }) // pickers fall back / hide
    return () => { stale = true }
  }, [orgId])

  // Ingest target: any workspace but TYDAL's internal ones (a gallery's selection).
  const ingestOptions = useMemo(() => orgLists && {
    workspaces: orgLists.workspaces
      .filter(w => !w.is_system)
      .map((w): IngestTargetOption => ({ id: Number(w.id), name: w.name, hint: w.is_default ? 'default · every resource in the org' : undefined })),
    collections: orgLists.collections.map((c): IngestTargetOption => ({ id: Number(c.id), name: c.name })),
  }, [orgLists])

  // What the vault reads from. The default workspace is left out: it stands
  // for the whole org, which the "Include default workspace" switch controls.
  const readableWorkspaces = useMemo(
    () => (orgLists?.workspaces ?? []).filter(w => !w.is_system && !w.is_default),
    [orgLists],
  )
  const ingestTargetId = Number((form.exposure_policy as { ingest?: { workspace_id?: number } } | null)?.ingest?.workspace_id ?? 0) || null
  const selectionActive = modal.view !== 'create' && modal.vault?.selection_snapshot != null

  // The capability matrix is static per deployment — fetch it once.
  useEffect(() => {
    vaultService.admin.capabilities()
      .then(res => { setCapabilities(res.data.capabilities); setPresets(res.data.presets) })
      .catch(() => { setCapabilities([]); setPresets(null) })
  }, [])

  const openCreate = () => {
    setForm(EMPTY_FORM)
    setAllowedIpsText('')
    setSaltRotated(false)
    setPurposeWarning(false)
    setFormError(null)
    setModal({ open: true, view: 'create', vault: null })
  }

  const loadKeys = (vaultId: string) => {
    vaultService.admin.listKeys(vaultId)
      .then(res => setKeys(res.data.keys))
      .catch(() => setKeys([]))
  }

  const handleMintKey = async (vaultId: string) => {
    if (!newKeyName.trim()) return
    setKeyBusy(true)
    try {
      const abilities = newKeyAbilities.length > 0 ? newKeyAbilities : ['read']
      const res = await vaultService.admin.createKey(vaultId, newKeyName.trim(), abilities)
      setMintedKey(res.data.plaintext)
      setNewKeyName('')
      setNewKeyAbilities(['read'])
      loadKeys(vaultId)
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setKeyBusy(false)
    }
  }

  const handleRevokeKey = async (vaultId: string, keyId: string) => {
    setKeyBusy(true)
    try {
      await vaultService.admin.revokeKey(vaultId, keyId)
      loadKeys(vaultId)
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setKeyBusy(false)
    }
  }

  const handleMintSignedUrl = async (vaultId: string) => {
    setGrantBusy(true)
    try {
      const res = await vaultService.admin.mintSignedUrl(vaultId, grantHours)
      setMintedGrant(res.data)
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setGrantBusy(false)
    }
  }

  const handleRevokeGrants = async (vaultId: string) => {
    setGrantBusy(true)
    try {
      await vaultService.admin.revokeGrants(vaultId)
      setMintedGrant(null)
      setGrantsRevoked(true)
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setGrantBusy(false)
    }
  }

  const handleRotateSalt = async (vaultId: string) => {
    if (!window.confirm('Rotate the salt? This invalidates EVERY shared link and signed grant for this vault. This cannot be undone.')) return
    setGrantBusy(true)
    try {
      await vaultService.admin.rotateSalt(vaultId)
      setMintedGrant(null)
      setSaltRotated(true)
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setGrantBusy(false)
    }
  }

  const copy = (text: string) => { navigator.clipboard?.writeText(text).catch(() => {}) }

  const hydrateForm = (vault: VaultConfig) => {
    setForm({
      organization_id:      vault.organization_id,
      name:                 vault.name,
      slug:                 vault.slug,
      description:          vault.description ?? '',
      purpose:              vault.purpose ?? 'delivery',
      state:                vault.state ?? 'private',
      has_public_workspace: vault.has_public_workspace,
      is_downloadable:      vault.is_downloadable,
      hash_ttl_hours:       vault.hash_ttl_hours,
      allowed_ips:          vault.allowed_ips ?? [],
      exposure_policy:      vault.exposure_policy ?? null,
      base_url:             vault.base_url ?? '',
      // Omitted when unknown, so a save never unlinks what it didn't load.
      workspace_ids:        vault.workspaces
        ?.filter(w => !w.is_system)
        .map(w => Number(w.id)),
    })
    setAllowedIpsText((vault.allowed_ips ?? []).join(', '))
    setPurposeWarning(false)
    setFormError(null)
  }

  // Step 1 — identity: name, purpose, slug, description.
  const openEdit = (vault: VaultConfig) => {
    hydrateForm(vault)
    setModal({ open: true, view: 'basic', vault })
  }

  // Access — credentials: the vault's access keys.
  const openAccess = (vault: VaultConfig) => {
    setKeys([])
    setNewKeyName('')
    setNewKeyAbilities(['read'])
    setMintedKey(null)
    setShowRevoked(false)
    loadKeys(vault.id)
    hydrateForm(vault)
    setModal({ open: true, view: 'access', vault })
  }

  // Sharing — reach: state, addresses, signed URLs, projection.
  const openSharing = (vault: VaultConfig) => {
    setMintedGrant(null)
    setShowSignedUrls(false)
    setGrantHours(24)
    hydrateForm(vault)
    setModal({ open: true, view: 'sharing', vault })
  }

  // Capabilities — the exposure matrix this vault overrides on its preset.
  const openCapabilities = (vault: VaultConfig) => {
    hydrateForm(vault)
    setModal({ open: true, view: 'capabilities', vault })
  }

  // Revocation — the kill-switches: revoke all grants, rotate salt.
  const openRevocation = (vault: VaultConfig) => {
    setGrantsRevoked(false)
    setSaltRotated(false)
    hydrateForm(vault)
    setModal({ open: true, view: 'revocation', vault })
  }

  const closeModal = () => setModal(m => ({ ...m, open: false }))

  const isCreate = modal.view === 'create'
  const isBasic = modal.view === 'create' || modal.view === 'basic'
  const isCapabilities = modal.view === 'capabilities'
  const isAccess = modal.view === 'access'
  const isSharing = modal.view === 'sharing'
  const isRevocation = modal.view === 'revocation'
  // Only the forms that persist vault fields get a Save; access & revocation act
  // through immediate API calls (mint/revoke/rotate), so they need none.
  const showSave = isBasic || isCapabilities || isSharing

  // Revoked keys are kept (they back the vault_writes audit trail), so the list
  // splits: active shown, revoked collapsed behind a toggle.
  const activeKeys = keys.filter(k => !k.revoked_at)
  const revokedKeys = keys.filter(k => k.revoked_at)

  const keyRow = (k: VaultKeyEntry) => (
    <Stack key={k.id} direction="row" alignItems="center" spacing={1}>
      <Key sx={{ fontSize: '0.875rem', color: k.revoked_at ? 'text.disabled' : 'secondary.main' }} />
      <Typography variant="caption" sx={{ fontWeight: 500, width: 110, flexShrink: 0, color: k.revoked_at ? 'text.disabled' : 'text.primary' }} noWrap>
        {k.name}
      </Typography>
      <Typography variant="caption" sx={{ fontFamily: 'monospace', color: 'text.secondary' }}>
        {k.key_prefix}…
      </Typography>
      {(k.abilities?.length ? k.abilities : ['read']).map((a) => (
        <Chip
          key={a}
          label={a.startsWith('w:') ? a.slice(2) : a}
          size="small"
          color={a.startsWith('w:') ? 'secondary' : 'default'}
          variant="outlined"
          sx={{ height: 20, opacity: k.revoked_at ? 0.6 : 1, '& .MuiChip-label': { px: 0.75, fontSize: '0.75rem' } }}
        />
      ))}
      <Typography variant="caption" color="text.disabled" sx={{ flex: 1 }} noWrap>
        {k.revoked_at
          ? 'revoked'
          : k.last_used_at
            ? `last used ${new Date(k.last_used_at).toLocaleDateString()}`
            : 'never used'}
      </Typography>
      {!k.revoked_at && (
        <Tooltip title="Revoke key">
          <Button
            size="small"
            color="error"
            sx={{ minWidth: 0, p: 0.25 }}
            disabled={keyBusy}
            onClick={() => handleRevokeKey(modal.vault!.id, k.id)}
          >
            <Block sx={{ fontSize: '0.875rem' }} />
          </Button>
        </Tooltip>
      )}
    </Stack>
  )

  // The write methods this vault actually accepts — its own `write_methods`
  // override when set, otherwise the purpose preset. A key can only be minted
  // for a method the boundary will honour.
  const effectiveWriteMethods = ((form.exposure_policy?.write_methods
    ?? presets?.[form.purpose]?.values.write_methods) as string[] | undefined)

  const abilityOptions = keyAbilityOptions(form.purpose, effectiveWriteMethods)

  const handleSave = async () => {
    setSaving(true); setFormError(null)
    const payload: VaultFormData = {
      ...form,
      allowed_ips: allowedIpsText.split(',').map(s => s.trim()).filter(Boolean),
    }
    try {
      if (modal.view === 'create') {
        // The tenant is chosen explicitly in the form. (If a caller omits it,
        // the backend still defaults organization_id from the header org
        // context — see StoreVaultRequest::prepareForValidation.)
        await vaultService.admin.create(payload)
      } else if (modal.vault) {
        // Both edit surfaces carry the whole hydrated form, so saving either
        // one leaves the other's fields intact.
        await vaultService.admin.update(modal.vault.id, payload)
      }
      closeModal(); load()
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setSaving(false)
    }
  }

  /**
   * The workspaces this vault reads from — the same links the workspace
   * selector edits, from the vault's side. Links the vault controls are
   * locked here too (VaultService::associationLock): its ingest target, and
   * everything while a published selection decides what it shows.
   */
  const workspacePicker = () => {
    if (!orgLists) return null
    const chosen = form.workspace_ids ?? []
    const lockNote = selectionActive
      ? 'Locked — this vault\'s published selection decides what it shows. Close the exhibition first.'
      : ingestTargetId && chosen.includes(ingestTargetId)
        ? 'The ingest target stays linked — uploads land there. Change the target first to unlink it.'
        : 'Resources in these workspaces are what the vault shows. For the whole organization, use "Include default workspace".'
    return (
      <TextField
        select size="small" fullWidth label="Workspaces (reads from)"
        disabled={saving || selectionActive}
        value={chosen}
        onChange={(e) => {
          const raw = e.target.value as unknown as Array<number | string>
          const next = raw.map(Number)
          // Keep the ingest target linked; the backend refuses to drop it anyway.
          if (ingestTargetId && chosen.includes(ingestTargetId) && !next.includes(ingestTargetId)) next.push(ingestTargetId)
          setForm(f => ({ ...f, workspace_ids: next }))
        }}
        helperText={lockNote}
        SelectProps={{
          multiple: true,
          renderValue: (ids) => (
            <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
              {(ids as number[]).map(id => (
                <Chip
                  key={id} size="small"
                  label={readableWorkspaces.find(w => Number(w.id) === id)?.name ?? `#${id}`}
                  icon={id === ingestTargetId ? <Lock sx={{ fontSize: '0.9rem' }} /> : undefined}
                />
              ))}
            </Stack>
          ),
        }}
      >
        {readableWorkspaces.length === 0 && (
          <MenuItem disabled value="">No workspaces yet — create one in the organization first</MenuItem>
        )}
        {readableWorkspaces.map(w => (
          <MenuItem key={w.id} value={Number(w.id)} disabled={Number(w.id) === ingestTargetId && chosen.includes(ingestTargetId)}>
            {w.name}
            {Number(w.id) === ingestTargetId && (
              <Typography component="span" variant="caption" color="text.disabled" sx={{ ml: 1 }}>ingest target</Typography>
            )}
          </MenuItem>
        ))}
      </TextField>
    )
  }

  const handleDelete = async () => {
    if (!deleteDialog.vault) return
    setDeleting(true)
    try {
      await vaultService.admin.delete(deleteDialog.vault.id)
      setDeleteDialog({ open: false, vault: null }); load()
    } catch (err) {
      alert(extractError(err))
    } finally {
      setDeleting(false)
    }
  }

  const columns: AdminColumn<VaultConfig>[] = [
    {
      id: 'name', label: 'Name',
      render: (row) => (
        <Stack>
          <Typography variant="body2" fontWeight={500}>{row.name}</Typography>
          <Typography variant="caption" color="text.secondary">{row.slug}</Typography>
        </Stack>
      ),
    },
    {
      id: 'organization', label: 'Organization', width: 140,
      render: (row) => (
        <Typography variant="caption" color="text.secondary">
          {row.organization?.name ?? orgs.find(o => o.id === row.organization_id)?.name ?? '—'}
        </Typography>
      ),
    },
    {
      id: 'base_url', label: 'Base URL',
      render: (row) => (
        <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>
          {row.base_url || '—'}
        </Typography>
      ),
    },
    {
      id: 'purpose', label: 'Purpose', width: 100, align: 'center',
      render: (row) => (
        <Chip label={row.purpose ?? 'delivery'} size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />
      ),
    },
    {
      id: 'flags', label: 'Flags', width: 180, align: 'center',
      render: (row) => (
        <Stack direction="row" spacing={0.5} justifyContent="center" flexWrap="wrap" useFlexGap>
          {row.has_public_workspace && (
            <Chip label="Public WS" size="small" sx={{ bgcolor: 'info.light', color: 'info.main' }} />
          )}
          {row.is_downloadable && (
            <Chip label="Download" size="small" sx={{ bgcolor: 'success.light', color: 'success.main' }} />
          )}
          {row.hash_ttl_hours && (
            <Chip label={`TTL ${row.hash_ttl_hours}h`} size="small" sx={{ bgcolor: CHIP_COLORS.ttl.light, color: CHIP_COLORS.ttl.main }} />
          )}
        </Stack>
      ),
    },
    {
      id: 'allowed_ips', label: 'IPs', width: 70, align: 'center',
      render: (row) => (
        <Typography variant="body2" color="text.secondary">
          {(row.allowed_ips ?? []).length === 0 ? 'All' : `${row.allowed_ips!.length} IPs`}
        </Typography>
      ),
    },
    {
      id: 'status', label: 'Status', width: 110, align: 'center',
      // One axis now: off / credential-required / open to anyone with the URL.
      render: (row) => {
        const style = row.state === 'public'
          ? { bgcolor: 'success.light', color: 'success.main' }
          : row.state === 'private'
            ? { bgcolor: 'info.light', color: 'info.main' }
            : { bgcolor: 'grey.100', color: 'text.secondary' }
        const label = row.state.charAt(0).toUpperCase() + row.state.slice(1)

        return <Chip label={label} size="small" sx={style} />
      },
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
        onDelete={(row) => setDeleteDialog({ open: true, vault: row })}
        rowActions={(row) => (
          <>
            <Tooltip title="Capabilities">
              <IconButton
                size="small"
                onClick={() => openCapabilities(row)}
                sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'secondary.main' } }}
              >
                <Tune sx={{ fontSize: '1rem' }} />
              </IconButton>
            </Tooltip>
            <Tooltip title="Sharing & reach">
              <IconButton
                size="small"
                onClick={() => openSharing(row)}
                sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'secondary.main' } }}
              >
                <Share sx={{ fontSize: '1rem' }} />
              </IconButton>
            </Tooltip>
            <Tooltip title="Access keys">
              <IconButton
                size="small"
                onClick={() => openAccess(row)}
                sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'secondary.main' } }}
              >
                <Key sx={{ fontSize: '1rem' }} />
              </IconButton>
            </Tooltip>
            <Tooltip title="Revocation (grants & salt)">
              <IconButton
                size="small"
                onClick={() => openRevocation(row)}
                sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'warning.main' } }}
              >
                <Block sx={{ fontSize: '1rem' }} />
              </IconButton>
            </Tooltip>
          </>
        )}
        onAdd={openCreate}
        addLabel="New Vault"
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search Vaults…"
      />

      {/* One record, several small forms — each reached by its own row action */}
      {/* One width for every vault form (md = 900px): the capability matrix is a
          three-column grid that needs the room, and the sharing view carries
          full addresses — sizing per view made the dialog jump between actions. */}
      <Dialog open={modal.open} onClose={closeModal} maxWidth="md" fullWidth>
        <DialogTitle sx={{ fontWeight: 700, fontSize: '1.125rem' }}>
          {isCreate ? 'New Vault'
            : isCapabilities ? `Capabilities · ${modal.vault?.name ?? ''}`
            : isAccess ? `Access keys · ${modal.vault?.name ?? ''}`
            : isSharing ? `Sharing & reach · ${modal.vault?.name ?? ''}`
            : isRevocation ? `Revocation · ${modal.vault?.name ?? ''}`
            : 'Edit Vault'}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ pt: 1 }}>
            {formError && (
              <Alert severity="error" onClose={() => setFormError(null)}>{formError}</Alert>
            )}

            {/* ── Step 1 · Identity ─────────────────────────────────────── */}
            {isBasic && (
              <>
                {isCreate && (
                  <FormControl size="small" fullWidth required>
                    <InputLabel>Organization</InputLabel>
                    <Select
                      label="Organization" value={form.organization_id}
                      // Workspaces belong to one organization — a new org starts clean.
                      onChange={(e) => setForm(f => ({ ...f, organization_id: e.target.value, workspace_ids: [] }))}
                    >
                      {orgs.map(o => <MenuItem key={o.id} value={o.id}>{o.name}</MenuItem>)}
                    </Select>
                  </FormControl>
                )}

                {isCreate && workspacePicker()}

                <TextField
                  label="Purpose (preset)" size="small" fullWidth select
                  value={form.purpose}
                  onChange={(e) => {
                    const purpose = e.target.value as VaultPurpose
                    setPurposeWarning(modal.view === 'basic' && purpose !== modal.vault?.purpose)
                    setForm(f => ({ ...f, purpose }))
                  }}
                  helperText="What this vault projects, and the preset for the capabilities below. 'delivery' is the classic CDN."
                >
                  {VAULT_PURPOSES.map(p => (
                    <MenuItem key={p} value={p}>{presets?.[p]?.label ?? p}</MenuItem>
                  ))}
                </TextField>

                {purposeWarning && (
                  <Alert severity="warning" icon={<Warning />}>
                    Changing the purpose will purge and invalidate all existing links for this vault.
                  </Alert>
                )}

                <TextField
                  label="Name" size="small" fullWidth required
                  value={form.name}
                  onChange={(e) => {
                    const name = e.target.value
                    setForm(f => ({
                      ...f,
                      name,
                      slug: isCreate ? slugify(name) : f.slug,
                    }))
                  }}
                />

                <TextField
                  label="Slug" size="small" fullWidth required
                  value={form.slug}
                  onChange={(e) => setForm(f => ({ ...f, slug: e.target.value }))}
                  helperText="URL-safe identifier (auto-filled from name)"
                />

                <TextField
                  label="Description" size="small" fullWidth multiline rows={2}
                  value={form.description ?? ''}
                  onChange={(e) => setForm(f => ({ ...f, description: e.target.value }))}
                />

                {modal.view === 'basic' && (
                  <Typography variant="caption" color="text.disabled">
                    The exposure matrix, access keys, openness/addresses, and the
                    revocation switches live under the <b>Capabilities</b>,{' '}
                    <b>Access</b>, <b>Sharing</b> and <b>Revocation</b> buttons in
                    the vault row.
                  </Typography>
                )}
              </>
            )}

            {/* ── Capabilities · the exposure matrix ────────────────────── */}
            {isCapabilities && modal.vault && (
              <>
                <Typography variant="caption" color="text.disabled">
                  The purpose sets a preset; anything here overrides it for this
                  vault only. Tuning a capability re-gates the boundary — it does
                  not touch the hash domain, so existing links stay valid.
                </Typography>

                {/* Which preset the matrix overlays — shown as a chip, not a
                    field: it is read-only context, and a full-width input here
                    would sit oddly above the grid's narrower controls. */}
                <Stack direction="row" spacing={1} alignItems="center">
                  <Typography variant="caption" color="text.secondary">Purpose (preset)</Typography>
                  <Chip size="small" label={presets?.[form.purpose]?.label ?? form.purpose} />
                  <Typography variant="caption" color="text.disabled">
                    changed under Basic — switching it purges this vault&apos;s links
                  </Typography>
                </Stack>

                {capabilities.length > 0 && presets ? (
                  <VaultCapabilityGrid
                    capabilities={capabilities}
                    preset={presets[form.purpose].values}
                    value={form.exposure_policy ?? null}
                    onChange={(exposure_policy) => setForm(f => ({ ...f, exposure_policy }))}
                    disabled={saving}
                    ingestOptions={ingestOptions}
                  />
                ) : (
                  <Typography variant="caption" color="text.disabled">
                    Loading the capability matrix…
                  </Typography>
                )}
              </>
            )}

            {/* ── Access · credentials ──────────────────────────────────── */}
            {isAccess && modal.vault && (
              <>
                <Typography variant="caption" color="text.disabled">
                  A key unlocks this vault while it is private. Each carries abilities:{' '}
                  <b>read</b> consumes the vault (e.g. a jury proxy);{' '}
                  <b>activate / open / close</b> are the gallery write methods (the opening).
                  The full <code>tvk_…</code> value is shown once, at mint — store it then.
                </Typography>

                {activeKeys.map(keyRow)}
                {activeKeys.length === 0 && (
                  <Typography variant="caption" color="text.disabled">
                    No active keys yet.
                  </Typography>
                )}

                <Stack direction="row" spacing={1}>
                  <TextField
                    size="small"
                    placeholder="Key name (e.g. partner-a)"
                    value={newKeyName}
                    onChange={(e) => setNewKeyName(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') handleMintKey(modal.vault!.id) }}
                    sx={{ flex: 1 }}
                  />
                  {abilityOptions.length > 1 && (
                    <FormControl size="small" sx={{ minWidth: 150 }}>
                      <InputLabel>Abilities</InputLabel>
                      <Select
                        multiple
                        label="Abilities"
                        value={newKeyAbilities}
                        onChange={(e) => setNewKeyAbilities(
                          typeof e.target.value === 'string' ? e.target.value.split(',') : e.target.value,
                        )}
                        renderValue={(selected) => (selected as string[])
                          .map((a) => (a === 'read' ? 'read' : a.slice(2)))
                          .join(', ')}
                      >
                        {abilityOptions.map((a) => (
                          <MenuItem key={a} value={a}>
                            {a === 'read' ? 'read (consume)' : `write · ${a.slice(2)}`}
                          </MenuItem>
                        ))}
                      </Select>
                    </FormControl>
                  )}
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<Key sx={{ fontSize: '0.875rem' }} />}
                    disabled={!newKeyName.trim() || keyBusy}
                    onClick={() => handleMintKey(modal.vault!.id)}
                  >
                    Mint key
                  </Button>
                </Stack>

                {/* One-time plaintext — rendered right under the mint controls
                    (and scrolled into view) so it can't be minted-and-lost. */}
                {mintedKey && (
                  <Alert
                    ref={mintedKeyRef}
                    severity="success"
                    onClose={() => setMintedKey(null)}
                    action={
                      <Button color="inherit" size="small" startIcon={<ContentCopy sx={{ fontSize: '0.875rem' }} />} onClick={() => copy(mintedKey)}>
                        Copy
                      </Button>
                    }
                  >
                    <Typography variant="caption" sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>
                      {mintedKey}
                    </Typography>
                    <Typography variant="caption" display="block">
                      Store it now — it will not be shown again.
                    </Typography>
                  </Alert>
                )}

                {/* Revoked keys — collapsed (kept for the write audit trail) */}
                {revokedKeys.length > 0 && (
                  <>
                    <Button
                      size="small"
                      variant="text"
                      onClick={() => setShowRevoked(v => !v)}
                      sx={{ alignSelf: 'flex-start', color: 'text.secondary', textTransform: 'none' }}
                    >
                      {showRevoked ? `▾ Hide revoked` : `▸ Show ${revokedKeys.length} revoked`}
                    </Button>
                    {showRevoked && revokedKeys.map(keyRow)}
                  </>
                )}
              </>
            )}

            {/* ── Sharing · reach ───────────────────────────────────────── */}
            {isSharing && modal.vault && (
              <>
                <Typography variant="caption" color="text.disabled">
                  How the outside world reaches this vault: its openness, its addresses,
                  time-limited share links, and how content is projected.
                </Typography>

                <TextField
                  select size="small" fullWidth label="State" value={form.state}
                  onChange={(e) => setForm(f => ({ ...f, state: e.target.value as VaultState }))}
                  helperText="disabled: off · private: needs a key or signed grant · public: the address alone"
                >
                  {VAULT_STATES.map(s => (
                    <MenuItem key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</MenuItem>
                  ))}
                </TextField>

                {workspacePicker()}

                <Stack spacing={1}>
                  <FormControlLabel
                    control={
                      <Switch
                        size="small"
                        checked={form.has_public_workspace}
                        onChange={(e) => setForm(f => ({ ...f, has_public_workspace: e.target.checked }))}
                      />
                    }
                    label={<Typography variant="body2">Include default workspace</Typography>}
                  />
                  <FormControlLabel
                    control={
                      <Switch
                        size="small"
                        checked={form.is_downloadable}
                        onChange={(e) => setForm(f => ({ ...f, is_downloadable: e.target.checked }))}
                      />
                    }
                    label={
                      <Stack spacing={0}>
                        <Typography variant="body2">Allow download and raw image display</Typography>
                        <Typography variant="caption" color="text.disabled">
                          Off falls back to display-sized preview images instead of the original file.
                        </Typography>
                      </Stack>
                    }
                  />
                </Stack>

                {/* Addresses — how the outside world reaches this vault */}
                <SectionTitle>Addresses</SectionTitle>
                <Stack spacing={0.5}>
                  {[
                    { label: 'Vault hash', value: modal.vault.hash },
                    {
                      label: 'Human URL',
                      value: `${PUBLIC_BASE}/v/${orgs.find(o => o.id === modal.vault!.organization_id)?.slug ?? modal.vault.organization_id}/${form.slug || modal.vault.slug}`,
                    },
                    { label: 'Machine URL', value: `${PUBLIC_BASE}/h/${modal.vault.hash}` },
                  ].map(({ label, value }) => (
                    <Stack key={label} direction="row" alignItems="center" spacing={1}>
                      <Typography variant="caption" color="text.secondary" sx={{ width: 90, flexShrink: 0 }}>
                        {label}
                      </Typography>
                      <Typography
                        variant="caption"
                        sx={{ fontFamily: 'monospace', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flex: 1 }}
                      >
                        {value}
                      </Typography>
                      <Tooltip title="Copy">
                        <Button size="small" sx={{ minWidth: 0, p: 0.25 }} onClick={() => copy(value)}>
                          <ContentCopy sx={{ fontSize: '0.875rem' }} />
                        </Button>
                      </Tooltip>
                    </Stack>
                  ))}
                </Stack>
                {form.state !== 'public' && (
                  <Alert severity="info" sx={{ py: 0 }}>
                    This vault is not published — public URLs answer only with a valid vault key.
                  </Alert>
                )}

                {/* Signed URLs — time-limited publish grants (Epic 5.4).
                    Collapsed by default: an access key is the ordinary way to
                    share a private vault, and this is the rarer case. */}
                <SectionTitle>Signed URLs</SectionTitle>
                <Button
                  size="small"
                  variant="text"
                  onClick={() => setShowSignedUrls(v => !v)}
                  sx={{ alignSelf: 'flex-start', color: 'text.secondary', textTransform: 'none' }}
                >
                  {showSignedUrls ? '▾ Hide time-limited links' : '▸ Time-limited links — advanced'}
                </Button>

                {showSignedUrls && (
                <>
                <Typography variant="caption" color="text.disabled">
                  A time-limited link that opens this vault without a key — even unpublished.
                  Stateless. Prefer an <b>Access</b> key for ordinary sharing; use this when the
                  access must expire on its own. To revoke outstanding links early, use the{' '}
                  <b>Revocation</b> action in the vault row.
                </Typography>

                {mintedGrant && (
                  <Alert
                    severity="success"
                    onClose={() => setMintedGrant(null)}
                    action={
                      <Button color="inherit" size="small" startIcon={<ContentCopy sx={{ fontSize: '0.875rem' }} />} onClick={() => copy(mintedGrant.url)}>
                        Copy
                      </Button>
                    }
                  >
                    <Typography variant="caption" sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>
                      {mintedGrant.url}
                    </Typography>
                    <Typography variant="caption" display="block">
                      Valid until {new Date(mintedGrant.expires_at).toLocaleString()} — append{' '}
                      <code>&sig=…&exp=…</code> to any vault app URL, or share as is.
                    </Typography>
                  </Alert>
                )}

                <Stack direction="row" spacing={1}>
                  <TextField
                    select
                    size="small"
                    label="Valid for"
                    value={grantHours}
                    onChange={(e) => setGrantHours(Number(e.target.value))}
                    sx={{ width: 140 }}
                  >
                    <MenuItem value={1}>1 hour</MenuItem>
                    <MenuItem value={24}>24 hours</MenuItem>
                    <MenuItem value={168}>7 days</MenuItem>
                    <MenuItem value={720}>30 days</MenuItem>
                  </TextField>
                  <Button
                    size="small"
                    variant="outlined"
                    startIcon={<Schedule sx={{ fontSize: '0.875rem' }} />}
                    disabled={grantBusy}
                    onClick={() => handleMintSignedUrl(modal.vault!.id)}
                  >
                    Mint signed URL
                  </Button>
                </Stack>
                </>
                )}

                {/* Projection options */}
                <SectionTitle>Projection</SectionTitle>

                <TextField
                  label="Base URL" size="small" fullWidth
                  value={form.base_url ?? ''}
                  onChange={(e) => setForm(f => ({ ...f, base_url: e.target.value }))}
                  helperText="Optional Vault domain (e.g. https://vault.example.com). Leave empty to use the app URL."
                  placeholder="https://vault.example.com"
                />

                <TextField
                  label="TTL (hours)" size="small" fullWidth
                  type="number"
                  value={form.hash_ttl_hours ?? ''}
                  onChange={(e) => setForm(f => ({ ...f, hash_ttl_hours: e.target.value ? parseInt(e.target.value) : null }))}
                  helperText="Hours until links expire. Leave empty for no expiry."
                  inputProps={{ min: 1, max: 8760 }}
                />

                <TextField
                  label="Allowed IPs" size="small" fullWidth
                  value={allowedIpsText}
                  onChange={(e) => setAllowedIpsText(e.target.value)}
                  helperText="Comma-separated IPs. Leave empty = all IPs."
                  placeholder="192.168.1.1, 10.0.0.1"
                />
              </>
            )}

            {/* ── Revocation · kill-switches ────────────────────────────── */}
            {isRevocation && modal.vault && (
              <>
                <Typography variant="caption" color="text.disabled">
                  Two ways to cut off access. <b>Revoke all grants</b> kills every outstanding
                  signed URL but leaves permanent links working. <b>Rotate salt</b> is the full
                  reset — it regenerates the secret that seeds every link hash and grant, so
                  <b> every</b> shared link and signed URL stops working at once.
                </Typography>

                {/* Signed-URL grants */}
                <SectionTitle>Signed-URL grants</SectionTitle>
                {grantsRevoked && (
                  <Alert severity="info" onClose={() => setGrantsRevoked(false)}>
                    All outstanding signed grants revoked. Existing links are unaffected.
                  </Alert>
                )}
                <Button
                  size="small"
                  color="warning"
                  variant="outlined"
                  disabled={grantBusy}
                  onClick={() => handleRevokeGrants(modal.vault!.id)}
                  sx={{ alignSelf: 'flex-start' }}
                >
                  Revoke all grants
                </Button>

                {/* Salt — the nuclear reset */}
                <SectionTitle>Salt</SectionTitle>
                <Typography variant="caption" color="text.disabled">
                  The salt is generated automatically — it seeds every link hash and grant
                  signature. Rotating it invalidates <b>every</b> shared link and signed grant
                  for this vault, and cannot be undone. (To revoke only grants, use “Revoke all
                  grants” above.)
                </Typography>
                {saltRotated ? (
                  <Alert severity="warning" icon={<Warning />} onClose={() => setSaltRotated(false)}>
                    Salt rotated — all links and grants for this vault were invalidated.
                  </Alert>
                ) : (
                  <Button
                    size="small"
                    color="warning"
                    variant="outlined"
                    startIcon={<Warning sx={{ fontSize: '0.875rem' }} />}
                    disabled={grantBusy}
                    onClick={() => handleRotateSalt(modal.vault!.id)}
                    sx={{ alignSelf: 'flex-start' }}
                  >
                    Rotate salt
                  </Button>
                )}
              </>
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={closeModal} size="small">{showSave ? 'Cancel' : 'Close'}</Button>
          {showSave && (
            <Button variant="contained" size="small" onClick={handleSave} disabled={saving}>
              {saving ? 'Saving…' : 'Save'}
            </Button>
          )}
        </DialogActions>
      </Dialog>

      {/* Delete confirm */}
      <Dialog open={deleteDialog.open} onClose={() => setDeleteDialog({ open: false, vault: null })} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>Delete Vault</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Delete <strong>{deleteDialog.vault?.name}</strong>? All generated links for this Vault will be permanently removed.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button size="small" onClick={() => setDeleteDialog({ open: false, vault: null })}>Cancel</Button>
          <Button variant="contained" color="error" size="small" onClick={handleDelete} disabled={deleting}>
            {deleting ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}

function extractError(err: unknown): string {
  if (err && typeof err === 'object') {
    const e = err as Record<string, unknown>
    if (typeof e.message === 'string') return e.message
    if (e.errors && typeof e.errors === 'object') {
      return Object.values(e.errors as Record<string, string[]>).flat().join(' ')
    }
  }
  return 'An unexpected error occurred'
}

export default VaultsTab
