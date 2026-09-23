import { useState, useEffect } from 'react'
import { apiErrorMessage as extractError } from '../../utils/apiError'
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  Button, TextField, Stack, Switch, FormControlLabel,
  Typography, Chip, Box, Alert,
} from '@mui/material'
import AdminTable, { AdminColumn } from './AdminTable'
import semanticTagService, { CreateSemanticTagData } from '../../api/semanticTagService'
import type { SemanticTag, TagReviewer, TagVocabulary } from '../../api/resourceService'
import { ENTITY_TYPES, type EntityTypeKey } from '../../constants/entityTypes'


const VOCABULARY_OPTIONS: { value: TagVocabulary; label: string }[] = [
  { value: 'organization', label: 'Organization' },
  { value: 'user',         label: 'User' },
  { value: 'ai_generated', label: 'AI Generated' },
  { value: 'taxonomy',     label: 'Taxonomy' },
]

const REVIEWER_OPTIONS: { value: TagReviewer; label: string }[] = [
  { value: 'user', label: 'User' },
  { value: 'aity', label: 'Aity' },
]

const VOCABULARY_CHIP: Record<TagVocabulary, { bg: string; fg: string }> = {
  organization: { bg: 'primary.subtle', fg: 'primary.main' },
  user:         { bg: 'grey.100',       fg: 'text.secondary' },
  ai_generated: { bg: 'warning.light',  fg: 'warning.main' },
  taxonomy:     { bg: 'info.light',     fg: 'info.main' },
}

const REVIEWER_CHIP: Record<TagReviewer, { bg: string; fg: string }> = {
  user: { bg: 'grey.100',      fg: 'text.secondary' },
  aity: { bg: 'warning.light', fg: 'warning.main' },
}

type FormState = CreateSemanticTagData & { is_active: boolean; entity_type: string }

const EMPTY_FORM: FormState = {
  label:       '',
  description: '',
  entity_type: '',
  vocabulary:  'organization',
  reviewer:    'user',
  is_active:   true,
}

function EntityTypePicker({ value, onChange, error }: { value: string; onChange: (key: EntityTypeKey) => void; error?: boolean }) {
  return (
    <Box>
      <Typography variant="caption" sx={{ color: error ? 'error.main' : 'text.secondary', mb: 0.75, display: 'block' }}>
        Entity type <span style={{ color: 'inherit' }}>*</span>
      </Typography>
      <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
        {(Object.entries(ENTITY_TYPES) as [EntityTypeKey, typeof ENTITY_TYPES[EntityTypeKey]][]).map(([key, et]) => {
          const selected = value === key
          return (
            <Box
              key={key}
              onClick={() => onChange(key)}
              sx={{
                display: 'flex',
                alignItems: 'center',
                gap: 0.75,
                px: 1.25,
                py: 0.75,
                borderRadius: 1.5,
                border: '2px solid',
                borderColor: selected ? et.color : 'divider',
                bgcolor: selected ? `${et.color}14` : 'background.paper',
                cursor: 'pointer',
                transition: 'all 0.15s',
                '&:hover': { borderColor: et.color, bgcolor: `${et.color}0A` },
              }}
            >
              <et.Icon sx={{ fontSize: '1rem', color: et.color }} />
              <Typography variant="caption" sx={{ fontWeight: selected ? 600 : 400, color: selected ? et.color : 'text.secondary' }}>
                {et.label}
              </Typography>
            </Box>
          )
        })}
      </Stack>
    </Box>
  )
}

export interface SemanticTagsTabProps {
  /**
   * Show a reminder that these tags belong to the selected organization.
   * Set from the platform panel, where the surrounding title says "Platform"
   * and would otherwise mislead; unnecessary on the organization page.
   */
  scopeNote?: boolean
}

function SemanticTagsTab({ scopeNote = false }: SemanticTagsTabProps) {
  const [rows, setRows] = useState<SemanticTag[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [refreshKey, setRefreshKey] = useState(0)

  const [modal, setModal] = useState<{ open: boolean; mode: 'create' | 'edit'; tag: SemanticTag | null }>({
    open: false, mode: 'create', tag: null,
  })
  const [form, setForm] = useState<FormState>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState<string | null>(null)

  const [deleteDialog, setDeleteDialog] = useState<{ open: boolean; tag: SemanticTag | null }>({
    open: false, tag: null,
  })
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    const t = setTimeout(() => setDebouncedSearch(search), 350)
    return () => clearTimeout(t)
  }, [search])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    semanticTagService.list(debouncedSearch || undefined)
      .then(tags => { if (!cancelled) { setRows(tags); setLoading(false) } })
      .catch(() => { if (!cancelled) { setRows([]); setLoading(false) } })
    return () => { cancelled = true }
  }, [debouncedSearch, refreshKey])

  const load = () => setRefreshKey(k => k + 1)

  const openCreate = () => {
    setForm(EMPTY_FORM)
    setFormError(null)
    setModal({ open: true, mode: 'create', tag: null })
  }

  const openEdit = (tag: SemanticTag) => {
    setForm({
      label:       tag.label,
      description: tag.description ?? '',
      entity_type: tag.entity_type ?? 'tag',
      vocabulary:  tag.vocabulary ?? 'organization',
      reviewer:    tag.reviewer   ?? 'user',
      is_active:   tag.is_active,
    })
    setFormError(null)
    setModal({ open: true, mode: 'edit', tag })
  }

  const closeModal = () => setModal(m => ({ ...m, open: false }))

  const handleEntityType = (key: EntityTypeKey) => {
    setForm(f => ({ ...f, entity_type: key }))
  }

  const handleSave = async () => {
    if (!form.label.trim()) { setFormError('Label is required.'); return }
    if (!form.entity_type) { setFormError('Entity type is required.'); return }
    setSaving(true); setFormError(null)
    try {
      const payload = {
        label:       form.label.trim(),
        description: form.description || undefined,
        entity_type: form.entity_type || undefined,
        vocabulary:  form.vocabulary ?? 'organization',
        reviewer:    form.reviewer   ?? 'user',
      }
      if (modal.mode === 'create') {
        await semanticTagService.create(payload)
      } else if (modal.tag) {
        await semanticTagService.update(modal.tag.id, { ...payload, is_active: form.is_active })
      }
      closeModal(); load()
    } catch (err) {
      setFormError(extractError(err))
    } finally {
      setSaving(false)
    }
  }

  const handleDelete = async () => {
    if (!deleteDialog.tag) return
    setDeleting(true)
    try {
      await semanticTagService.delete(deleteDialog.tag.id)
      setDeleteDialog({ open: false, tag: null }); load()
    } catch (err) {
      alert(extractError(err))
    } finally {
      setDeleting(false)
    }
  }

  const columns: AdminColumn<SemanticTag>[] = [
    {
      id: 'label', label: 'Label',
      render: (row) => {
        const et = row.entity_type && row.entity_type in ENTITY_TYPES ? ENTITY_TYPES[row.entity_type as EntityTypeKey] : null
        return (
          <Stack direction="row" spacing={1} alignItems="center">
            {et ? (
              <Box sx={{ width: 28, height: 28, borderRadius: 1, bgcolor: et.color, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                <et.Icon sx={{ fontSize: '0.875rem', color: 'white' }} />
              </Box>
            ) : (
              <Box sx={{ width: 28, height: 28, borderRadius: 1, bgcolor: 'grey.200', flexShrink: 0 }} />
            )}
            <Stack>
              <Typography variant="body2" fontWeight={500}>{row.label}</Typography>
              {row.description && (
                <Typography variant="caption" color="text.secondary" sx={{ maxWidth: 300, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {row.description}
                </Typography>
              )}
            </Stack>
          </Stack>
        )
      },
    },
    {
      id: 'vocabulary', label: 'Vocabulary', width: 130, align: 'center',
      render: (row) => {
        const v = row.vocabulary ?? 'organization'
        const style = VOCABULARY_CHIP[v] ?? VOCABULARY_CHIP.organization
        return <Chip label={v} size="small" sx={{ bgcolor: style.bg, color: style.fg }} />
      },
    },
    {
      id: 'reviewer', label: 'Reviewer', width: 110, align: 'center',
      render: (row) => {
        const r = row.reviewer ?? 'user'
        const style = REVIEWER_CHIP[r] ?? REVIEWER_CHIP.user
        return <Chip label={r} size="small" sx={{ bgcolor: style.bg, color: style.fg }} />
      },
    },
    {
      id: 'status', label: 'Status', width: 100, align: 'center',
      render: (row) => row.is_active
        ? <Chip label="Active" size="small" sx={{ bgcolor: 'success.light', color: 'success.main' }} />
        : <Chip label="Inactive" size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary' }} />,
    },
  ]

  return (
    <>
      {scopeNote && (
        // This tab is shared with the organization settings page because its
        // endpoints are plain `auth:sanctum` and filter by the current
        // organization — semantic tags belong to a tenant, not the platform.
        // Sitting under "Platform Administration" implies otherwise, so say it.
        <Alert severity="info" sx={{ mb: 2, py: 0.5 }}>
          <Typography variant="caption">
            Tags belong to an organization, not the platform. These are the tags of the
            organization you currently have selected.
          </Typography>
        </Alert>
      )}

      <AdminTable
        columns={columns}
        rows={rows}
        loading={loading}
        total={rows.length}
        page={1}
        perPage={rows.length || 1}
        onPageChange={() => {}}
        onEdit={openEdit}
        onDelete={(row) => setDeleteDialog({ open: true, tag: row })}
        onAdd={openCreate}
        addLabel="New Tag"
        search={search}
        onSearchChange={setSearch}
        searchPlaceholder="Search tags…"
      />

      {/* Create / Edit dialog */}
      {/* md: the form pairs Vocabulary/Reviewer side by side and carries the
          entity-type picker — at xs both rows are squeezed. Matches the vault
          forms' width. */}
      <Dialog open={modal.open} onClose={closeModal} maxWidth="md" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>
          {modal.mode === 'create' ? 'New Tag' : 'Edit Tag'}
        </DialogTitle>
        <DialogContent>
          <Stack spacing={2.5} sx={{ pt: 1 }}>
            {formError && (
              <Alert severity="error" onClose={() => setFormError(null)}>{formError}</Alert>
            )}

            <TextField
              label="Label" size="small" fullWidth required
              value={form.label}
              onChange={(e) => setForm(f => ({ ...f, label: e.target.value }))}
            />

            <TextField
              label="Description" size="small" fullWidth multiline rows={2}
              value={form.description ?? ''}
              onChange={(e) => setForm(f => ({ ...f, description: e.target.value }))}
            />

            <EntityTypePicker value={form.entity_type ?? ''} onChange={handleEntityType} error={formError?.includes('Entity type')} />

            <Stack direction="row" spacing={1.5}>
              <TextField
                label="Vocabulary" size="small" fullWidth select
                value={form.vocabulary ?? 'organization'}
                onChange={(e) => setForm(f => ({ ...f, vocabulary: e.target.value as TagVocabulary }))}
                SelectProps={{ native: true }}
                helperText="Where the label comes from"
              >
                {VOCABULARY_OPTIONS.map(o => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </TextField>

              <TextField
                label="Reviewer" size="small" fullWidth select
                value={form.reviewer ?? 'user'}
                onChange={(e) => setForm(f => ({ ...f, reviewer: e.target.value as TagReviewer }))}
                SelectProps={{ native: true }}
                helperText="Who approved this entry"
              >
                {REVIEWER_OPTIONS.map(o => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </TextField>
            </Stack>

            {modal.mode === 'edit' && (
              <FormControlLabel
                control={
                  <Switch
                    size="small"
                    checked={form.is_active}
                    onChange={(e) => setForm(f => ({ ...f, is_active: e.target.checked }))}
                  />
                }
                label={<Typography variant="body2">Active</Typography>}
              />
            )}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={closeModal} size="small">Cancel</Button>
          <Button variant="contained" size="small" onClick={handleSave} disabled={saving || !form.label.trim() || !form.entity_type}>
            {saving ? 'Saving…' : modal.mode === 'create' ? 'Create' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Delete confirm */}
      <Dialog open={deleteDialog.open} onClose={() => setDeleteDialog({ open: false, tag: null })} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ fontWeight: 600, fontSize: '1rem' }}>Delete Tag</DialogTitle>
        <DialogContent>
          <Typography variant="body2">
            Delete <strong>{deleteDialog.tag?.label}</strong>? It will be removed from all resources.
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button size="small" onClick={() => setDeleteDialog({ open: false, tag: null })}>Cancel</Button>
          <Button variant="contained" color="error" size="small" onClick={handleDelete} disabled={deleting}>
            {deleting ? 'Deleting…' : 'Delete'}
          </Button>
        </DialogActions>
      </Dialog>
    </>
  )
}

export default SemanticTagsTab
