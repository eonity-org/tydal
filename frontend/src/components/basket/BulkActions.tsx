import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogContentText,
  DialogTitle,
  Menu,
  MenuItem,
  Snackbar,
  Stack,
  TextField,
  Tooltip,
} from '@mui/material'
import { Delete, LocalOffer, Publish, WorkspacesOutlined } from '@mui/icons-material'
import type { BulkActionResult } from '@tydal/client'
import workspaceService, { type Workspace } from '../../api/workspaceService'
import resourceService, { type ResourceState, type SemanticTag } from '../../api/resourceService'
import SemanticTagPicker from '../ui/SemanticTagPicker'
import SegmentedChoice from '../ui/SegmentedChoice'
import { usePermissions } from '../../hooks/usePermissions'
import { getApiError } from '../../utils/apiError'

/**
 * The four things you can do to a basket, in one place.
 *
 * Rendered both in the toolbar bar above the results (tick three, act) and on
 * the basket page itself (review the set, then act) — the same set of actions
 * either way, so nothing depends on which surface you reached them from.
 *
 * Every action is one request to a bulk endpoint, not a fan-out: authorization
 * is per-resource server-side, so acting on a subset is the normal outcome and
 * comes back as a report rather than an exception.
 */

export interface BulkActionsProps {
  /** The basket contents to act on. */
  ids: string[]
  /** Refetch whatever the host is showing — data changed underneath it. */
  onChanged: () => void
  /** Ids that left the catalogue for good, so the host can drop them. */
  onRemoved?: (ids: string[]) => void
  size?: 'small' | 'medium'
}

const STATES: Array<{ value: ResourceState; label: string; note: string }> = [
  { value: 'live', label: 'Live', note: 'Listed, searchable and projectable.' },
  { value: 'draft', label: 'Draft', note: 'Not addressable; the wizard’s working copy.' },
  { value: 'archived', label: 'Archived', note: 'Withdrawn from every listing and no longer addressable.' },
]

type Mode = 'add' | 'remove'

export function BulkActions({ ids, onChanged, onRemoved, size = 'small' }: BulkActionsProps) {
  const [workspaces, setWorkspaces] = useState<Workspace[]>([])
  const [busy, setBusy] = useState(false)
  const [report, setReport] = useState<{ message: string; severity: 'success' | 'warning' | 'error' } | null>(null)

  const [workspaceOpen, setWorkspaceOpen] = useState(false)
  const [workspaceId, setWorkspaceId] = useState<string>('')
  const [workspaceMode, setWorkspaceMode] = useState<Mode>('add')

  const [stateAnchor, setStateAnchor] = useState<HTMLElement | null>(null)
  const [stateConfirm, setStateConfirm] = useState<ResourceState | null>(null)

  const [tagOpen, setTagOpen] = useState(false)
  const [tagMode, setTagMode] = useState<Mode>('add')
  const [chosenTags, setChosenTags] = useState<SemanticTag[]>([])

  const [deleteOpen, setDeleteOpen] = useState(false)

  const count = ids.length
  // Two different reasons a button might not be usable, treated differently:
  // a permission the role does not have removes the button, an empty basket
  // only disables it. See usePermissions.
  const { can, ready: permissionsReady } = usePermissions()
  const disabled = count === 0 || busy
  const mayWorkspace = permissionsReady && can('workspaces.manage-resources')
  const mayState = permissionsReady && can('resources.update')
  const mayTag = permissionsReady && can('resources.update')
  const mayDelete = permissionsReady && can('resources.delete')

  // The default workspace refuses manual membership server-side (it holds
  // everything by construction), so it is never an option here.
  const targetWorkspaces = workspaces.filter((w) => !w.is_default)

  useEffect(() => {
    if (!workspaceOpen || workspaces.length > 0) return
    void workspaceService.getWorkspaces().then((list) => setWorkspaces(list ?? []))
  }, [workspaceOpen, workspaces.length])

  /** Turn a partial-success report into one sentence the user can act on. */
  const announce = useCallback((result: BulkActionResult, verb: string) => {
    // Big batches reindex on the queue, so the refetch that follows this
    // message can still be showing the old rows. Say so rather than letting
    // the list look broken.
    const lag = result.indexing === 'queued'
      ? ' The list will catch up in a moment.'
      : ''

    if (result.skipped.length === 0) {
      setReport({
        message: `${result.applied} ${result.applied === 1 ? 'resource' : 'resources'} ${verb}.${lag}`,
        severity: 'success',
      })
      return
    }
    setReport({
      message: `${result.applied} of ${result.requested} ${verb}. ${result.skipped.length} skipped — you may not have permission, or they belong to another organization.${lag}`,
      severity: 'warning',
    })
  }, [])

  const run = useCallback(async (action: () => Promise<BulkActionResult>, verb: string, close: () => void) => {
    setBusy(true)
    try {
      const result = await action()
      announce(result, verb)
      close()
      onChanged()
    } catch (error) {
      setReport({ message: getApiError(error).message, severity: 'error' })
    } finally {
      setBusy(false)
    }
  }, [announce, onChanged])

  const applyWorkspace = () => {
    if (!workspaceId) return
    const name = targetWorkspaces.find((w) => String(w.id) === workspaceId)?.name ?? 'the workspace'
    void run(
      () => workspaceService.bulkResourceMembership(workspaceId, ids, workspaceMode),
      workspaceMode === 'add' ? `added to ${name}` : `removed from ${name}`,
      () => setWorkspaceOpen(false),
    )
  }

  const applyState = (state: ResourceState) => {
    void run(
      () => resourceService.bulkState(ids, state),
      `set to ${state}`,
      () => { setStateConfirm(null); setStateAnchor(null) },
    )
  }

  const applyTags = () => {
    if (chosenTags.length === 0) return
    void run(
      () => resourceService.bulkSemanticTags(ids, chosenTags.map((t) => t.id), tagMode),
      tagMode === 'add' ? 'tagged' : 'untagged',
      () => { setTagOpen(false); setChosenTags([]) },
    )
  }

  /**
   * Delete has no bulk endpoint on purpose: it is soft, the resources land in
   * the reviewable trash, and it is the one action with a safety net already.
   * So it stays a fan-out, reported the same way as the rest.
   */
  const applyDelete = async () => {
    setBusy(true)
    try {
      const results = await Promise.allSettled(ids.map((id) => resourceService.deleteResource(id)))
      const deleted = ids.filter((_, i) => results[i].status === 'fulfilled')
      const failed = ids.length - deleted.length

      setReport(failed === 0
        ? { message: `${deleted.length} ${deleted.length === 1 ? 'resource' : 'resources'} moved to the trash.`, severity: 'success' }
        : { message: `${deleted.length} of ${ids.length} moved to the trash. ${failed} failed.`, severity: 'warning' })

      onRemoved?.(deleted)
      setDeleteOpen(false)
      onChanged()
    } catch (error) {
      setReport({ message: getApiError(error).message, severity: 'error' })
    } finally {
      setBusy(false)
    }
  }

  const spinner = busy ? <CircularProgress size={14} color="inherit" /> : undefined

  return (
    <>
      <Stack direction="row" spacing={1} alignItems="center">
        {mayWorkspace && (
        <Tooltip title="Add these to a workspace, or take them out">
          <span>
            <Button
              variant="contained"
              disableElevation
              size={size}
              disabled={disabled}
              startIcon={<WorkspacesOutlined />}
              onClick={() => setWorkspaceOpen(true)}
            >
              Workspace
            </Button>
          </span>
        </Tooltip>
        )}

        {mayState && (
        <Tooltip title="Publish, withdraw, or send back to draft">
          <span>
            <Button
              variant="contained"
              disableElevation
              size={size}
              disabled={disabled}
              startIcon={<Publish />}
              onClick={(e) => setStateAnchor(e.currentTarget)}
            >
              State
            </Button>
          </span>
        </Tooltip>
        )}

        {mayTag && (
        <Tooltip title="Add or remove semantic tags">
          <span>
            <Button
              variant="contained"
              disableElevation
              size={size}
              disabled={disabled}
              startIcon={<LocalOffer />}
              onClick={() => setTagOpen(true)}
            >
              Tags
            </Button>
          </span>
        </Tooltip>
        )}

        {mayDelete && (
        <Tooltip title="Move to the trash — reversible from there">
          <span>
            <Button
              variant="outlined"
              color="error"
              size={size}
              disabled={disabled}
              startIcon={<Delete />}
              onClick={() => setDeleteOpen(true)}
              // Deliberately the one button here that stays outlined: the three
              // reversible operations carry the filled emphasis, so the
              // destructive one is never the loudest thing in the bar. Its
              // border is forced to full opacity because MUI's default
              // alpha(.5) outline all but vanishes against the tinted panel.
              sx={{ borderColor: 'error.main' }}
            >
              Delete
            </Button>
          </span>
        </Tooltip>
        )}
      </Stack>

      {/* ── Workspace ─────────────────────────────────────────────────────── */}
      <Dialog open={workspaceOpen} onClose={() => !busy && setWorkspaceOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Workspace association</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            {/* `strong`: this choice decides whether the confirm button
                attaches or detaches, and a grey fill would not say which. */}
            <SegmentedChoice
              fullWidth
              emphasis="strong"
              aria-label="Add to or remove from the workspace"
              value={workspaceMode}
              onChange={setWorkspaceMode}
              disabled={busy}
              options={[
                { value: 'add', label: 'Add to' },
                { value: 'remove', label: 'Remove from' },
              ]}
            />

            <TextField
              select
              size="small"
              label="Workspace"
              value={workspaceId}
              onChange={(e) => setWorkspaceId(e.target.value)}
              helperText={
                targetWorkspaces.length === 0
                  ? 'No workspaces available. The default workspace holds everything already, so it cannot be chosen.'
                  : 'A workspace is what a vault projects — adding here can make these public.'
              }
            >
              {targetWorkspaces.map((w) => (
                <MenuItem key={w.id} value={String(w.id)}>{w.name}</MenuItem>
              ))}
            </TextField>
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setWorkspaceOpen(false)} disabled={busy}>Cancel</Button>
          <Button
            variant="contained"
            onClick={applyWorkspace}
            disabled={busy || !workspaceId}
            startIcon={spinner}
          >
            {workspaceMode === 'add' ? `Add ${count}` : `Remove ${count}`}
          </Button>
        </DialogActions>
      </Dialog>

      {/* ── State ─────────────────────────────────────────────────────────── */}
      <Menu anchorEl={stateAnchor} open={Boolean(stateAnchor)} onClose={() => setStateAnchor(null)}>
        {STATES.map((option) => (
          <MenuItem
            key={option.value}
            onClick={() => {
              // Archiving removes the documents from the index and makes the
              // resources unaddressable — worth a second look; the others not.
              if (option.value === 'archived') setStateConfirm('archived')
              else applyState(option.value)
            }}
          >
            <Box>
              <Box sx={{ fontWeight: 500 }}>{option.label}</Box>
              <Box sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>{option.note}</Box>
            </Box>
          </MenuItem>
        ))}
      </Menu>

      <Dialog open={stateConfirm === 'archived'} onClose={() => !busy && setStateConfirm(null)} maxWidth="xs" fullWidth>
        <DialogTitle>Archive {count} {count === 1 ? 'resource' : 'resources'}?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            They leave every listing and stop being addressable, including through any
            vault that projects them. You can set them back to live at any time.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setStateConfirm(null)} disabled={busy}>Cancel</Button>
          <Button variant="contained" onClick={() => applyState('archived')} disabled={busy} startIcon={spinner}>
            Archive
          </Button>
        </DialogActions>
      </Dialog>

      {/* ── Tags ──────────────────────────────────────────────────────────── */}
      <Dialog open={tagOpen} onClose={() => !busy && setTagOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Semantic tags</DialogTitle>
        <DialogContent>
          <Stack spacing={2} sx={{ mt: 1 }}>
            <SegmentedChoice
              fullWidth
              emphasis="strong"
              aria-label="Add or remove tags"
              value={tagMode}
              onChange={setTagMode}
              disabled={busy}
              options={[
                { value: 'add', label: 'Add tags' },
                { value: 'remove', label: 'Remove tags' },
              ]}
            />

            {/* The same picker the resource editor uses: entity type and
                usage count are the semantic dimensions of a TYDAL tag, and a
                plain text autocomplete hides both. Creating is offered when
                adding — bulk tagging is often exactly when a new term is
                coined — but never when removing. */}
            <SemanticTagPicker
              value={chosenTags}
              onChange={setChosenTags}
              allowCreate={tagMode === 'add'}
              disabled={busy}
              helperText={
                tagMode === 'add'
                  ? 'Added alongside whatever tags each resource already has — nothing is replaced.'
                  : 'Removed where present; other tags are left alone.'
              }
            />
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTagOpen(false)} disabled={busy}>Cancel</Button>
          <Button
            variant="contained"
            onClick={applyTags}
            disabled={busy || chosenTags.length === 0}
            startIcon={spinner}
          >
            {tagMode === 'add' ? `Tag ${count}` : `Untag ${count}`}
          </Button>
        </DialogActions>
      </Dialog>

      {/* ── Delete ────────────────────────────────────────────────────────── */}
      <Dialog open={deleteOpen} onClose={() => !busy && setDeleteOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle>Delete {count} {count === 1 ? 'resource' : 'resources'}?</DialogTitle>
        <DialogContent>
          <DialogContentText>
            They move to the trash, where you can restore them. Drafts are removed outright.
          </DialogContentText>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDeleteOpen(false)} disabled={busy}>Cancel</Button>
          <Button variant="contained" color="error" onClick={() => void applyDelete()} disabled={busy} startIcon={spinner}>
            Delete
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar
        open={Boolean(report)}
        autoHideDuration={6000}
        onClose={() => setReport(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert severity={report?.severity ?? 'info'} onClose={() => setReport(null)} variant="filled">
          {report?.message}
        </Alert>
      </Snackbar>
    </>
  )
}

export default BulkActions
