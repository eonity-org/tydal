import {
  Alert, Button, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Stack, Typography,
} from '@mui/material'

/** What Keep or Delete all could not do, one entry per resource. */
export interface AbandonFailure {
  action: 'keep' | 'delete'
  failed: Array<{ localId: string; name: string; message: string }>
}

interface Props {
  open: boolean
  resourceCount: number
  isDeleting: boolean
  isPublishing: boolean
  /**
   * Keep or Delete all was pressed while an upload was still running: it waits
   * for that upload to settle first, so the resource is kept or deleted with
   * the others instead of being left a draft.
   */
  isFinishingUploads?: boolean
  /** Set when the last Keep / Delete all left some resources behind. */
  failure?: AbandonFailure | null
  onKeep: () => void
  onDeleteAll: () => void
  /** Repeat the failed action for the resources it failed on. */
  onRetry?: () => void
  /** Close the wizard with the failures unresolved. */
  onLeave?: () => void
  onCancel: () => void
}

/**
 * Shown when the user closes the wizard after resources have already been created.
 * Lets them choose to keep the draft resources or delete them all. If either
 * fails for some resources, it says which and why, and offers Retry or Leave
 * anyway instead of closing as if it had worked. Pressed while an upload is
 * still running, either one first shows "Finishing uploads…" with every button
 * disabled until the upload in flight has settled.
 */
export function AbandonDialog({
  open, resourceCount, isDeleting, isPublishing, isFinishingUploads = false, failure, onKeep, onDeleteAll, onRetry, onLeave, onCancel,
}: Props) {
  const plural = resourceCount !== 1
  const busy = isDeleting || isPublishing

  if (failure && failure.failed.length > 0) {
    const n = failure.failed.length
    const what = `${n} resource${n !== 1 ? 's' : ''}`
    return (
      <Dialog open={open} maxWidth="sm" fullWidth>
        <DialogTitle>
          {failure.action === 'keep' ? `Could not keep ${what}` : `Could not delete ${what}`}
        </DialogTitle>
        <DialogContent>
          <Alert severity="error" sx={{ mb: 2 }}>
            <Stack spacing={0.5}>
              {failure.failed.map((f) => (
                <Typography key={f.localId} variant="body2">
                  <strong>{f.name}</strong>: {f.message}
                </Typography>
              ))}
            </Stack>
          </Alert>
          <Typography variant="body2" color="text.secondary">
            {failure.action === 'keep'
              ? `If you leave now, ${n !== 1 ? 'these stay drafts' : 'this stays a draft'}: not visible in the collection, and deleted automatically after a while, files and all.`
              : `If you leave now, ${n !== 1 ? 'these stay hidden drafts' : 'this stays a hidden draft'}, deleted automatically after a while.`}
          </Typography>
        </DialogContent>
        <DialogActions sx={{ px: 2.5, pb: 2 }}>
          <Button onClick={onLeave} disabled={busy} color="error">
            Leave anyway
          </Button>
          <Button
            onClick={onRetry}
            disabled={busy}
            variant="contained"
            startIcon={busy ? <CircularProgress size={14} color="inherit" /> : undefined}
          >
            {busy ? 'Retrying…' : 'Retry'}
          </Button>
        </DialogActions>
      </Dialog>
    )
  }

  return (
    <Dialog open={open} onClose={busy ? undefined : onCancel} maxWidth="xs" fullWidth>
      <DialogTitle>Exit wizard?</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary">
          {resourceCount > 0
            ? `${resourceCount} resource${plural ? 's were' : ' was'} already created. Keep ${plural ? 'them' : 'it'} in the collection or delete ${plural ? 'them' : 'it'} all?`
            : 'Are you sure you want to exit the wizard?'}
        </Typography>
        {isFinishingUploads && (
          <Alert severity="info" icon={<CircularProgress size={16} />} sx={{ mt: 2 }} role="status">
            Finishing uploads… The file being uploaded is completed first, then
            {isPublishing ? ' kept' : ' deleted'} with the others. Files not started yet are not uploaded.
          </Alert>
        )}
      </DialogContent>
      <DialogActions sx={{ px: 2.5, pb: 2 }}>
        <Button onClick={onCancel} disabled={busy}>
          Cancel
        </Button>
        {resourceCount > 0 && (
          <Button
            onClick={onKeep}
            disabled={busy}
            variant="outlined"
            startIcon={isPublishing ? <CircularProgress size={14} /> : undefined}
          >
            {isPublishing ? (isFinishingUploads ? 'Finishing uploads…' : 'Publishing…') : 'Keep'}
          </Button>
        )}
        <Button
          onClick={onDeleteAll}
          color="error"
          variant="contained"
          disabled={busy}
          startIcon={isDeleting ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          {isDeleting ? (isFinishingUploads ? 'Finishing uploads…' : 'Deleting…') : resourceCount > 0 ? 'Delete all' : 'Exit'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
