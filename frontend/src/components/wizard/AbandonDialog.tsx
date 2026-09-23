import {
  Button, CircularProgress, Dialog, DialogActions,
  DialogContent, DialogTitle, Typography,
} from '@mui/material'

interface Props {
  open: boolean
  resourceCount: number
  isDeleting: boolean
  isPublishing: boolean
  onKeep: () => void
  onDeleteAll: () => void
  onCancel: () => void
}

/**
 * Shown when the user closes the wizard after resources have already been created.
 * Lets them choose to keep the draft resources or delete them all.
 */
export function AbandonDialog({ open, resourceCount, isDeleting, isPublishing, onKeep, onDeleteAll, onCancel }: Props) {
  const plural = resourceCount !== 1

  return (
    <Dialog open={open} onClose={onCancel} maxWidth="xs" fullWidth>
      <DialogTitle>Exit wizard?</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary">
          {resourceCount > 0
            ? `${resourceCount} resource${plural ? 's were' : ' was'} already created. Keep ${plural ? 'them' : 'it'} in the collection or delete ${plural ? 'them' : 'it'} all?`
            : 'Are you sure you want to exit the wizard?'}
        </Typography>
      </DialogContent>
      <DialogActions sx={{ px: 2.5, pb: 2 }}>
        <Button onClick={onCancel} disabled={isDeleting || isPublishing}>
          Cancel
        </Button>
        {resourceCount > 0 && (
          <Button
            onClick={onKeep}
            disabled={isDeleting || isPublishing}
            variant="outlined"
            startIcon={isPublishing ? <CircularProgress size={14} /> : undefined}
          >
            {isPublishing ? 'Publishing…' : 'Keep'}
          </Button>
        )}
        <Button
          onClick={onDeleteAll}
          color="error"
          variant="contained"
          disabled={isDeleting || isPublishing}
          startIcon={isDeleting ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          {isDeleting ? 'Deleting…' : resourceCount > 0 ? 'Delete all' : 'Exit'}
        </Button>
      </DialogActions>
    </Dialog>
  )
}
