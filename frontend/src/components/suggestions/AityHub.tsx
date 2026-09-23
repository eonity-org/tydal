import { useState } from 'react'
import {
  Box,
  Button,
  Menu,
  MenuItem,
  ListItemIcon,
  ListItemText,
  Divider,
  Typography,
  Chip,
} from '@mui/material'
import RefreshIcon from '@mui/icons-material/Refresh'
import AutorenewIcon from '@mui/icons-material/Autorenew'
import AutoFixHighIcon from '@mui/icons-material/AutoFixHigh'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import ReplayIcon from '@mui/icons-material/Replay'
import RestoreIcon from '@mui/icons-material/Restore'
import ExpandMoreIcon from '@mui/icons-material/ExpandMore'
import { AityPulse } from './AityPulse'

export interface AityHubProps {
  /** Suggestion availability: none = never had any, found = available, used = processed but cleared. */
  status: 'none' | 'found' | 'used'
  /** True while any file's AITY pipeline is still in flight — drives the pulsing icon. */
  processingActive: boolean
  /** Count of files whose AITY processing failed — surfaces a Retry action + badge. */
  failedCount: number
  /** True when the editor is in edit mode (the inline show/hide toggle only applies there). */
  isEditMode: boolean
  /** A background poll detected new AITY info not yet pulled in. */
  backgroundRefreshPending: boolean
  /** True when the resource has more than one file — gates the "combined metadata"
   *  synthesis actions, which only make sense across multiple files. */
  multiFile: boolean
  onRestore: () => void
  onReRun: () => void
  /** Synthesize unified metadata across the files and persist as suggestions to review. */
  onGenerateMetadata: () => void
  /** Synthesize unified metadata and apply it onto the resource (auto-approve). */
  onAutoApprove: () => void
  onRefresh: () => void
  onRetryFailed: () => void
}

/**
 * Transversal AITY access point. Lives in the modal title bar in both view and
 * edit mode (fixing the prior bug where it only appeared while editing). The
 * button shows live status; the menu is the single front door to AITY actions:
 * show/hide suggestions, re-run enrichment, refresh status, retry failed files.
 */
export function AityHub(props: AityHubProps) {
  const {
    status, processingActive, failedCount, isEditMode,
    multiFile, backgroundRefreshPending, onRestore, onReRun,
    onGenerateMetadata, onAutoApprove, onRefresh, onRetryFailed,
  } = props

  const [anchorEl, setAnchorEl] = useState<HTMLElement | null>(null)
  const open = Boolean(anchorEl)
  const close = () => setAnchorEl(null)
  const run = (fn: () => void) => () => { close(); fn() }

  // backgroundRefreshPending means AITY produced info not yet pulled into view, so
  // it takes priority over the (stale) 'none' status — otherwise the button reads a
  // contradictory "No Aity · new".
  const label = processingActive
    ? 'Aity · working'
    : backgroundRefreshPending
      ? 'Aity · new'
      : status === 'found'
        ? 'Aity'
        : status === 'used'
          ? 'Aity · used'
          : 'No Aity'

  const hasAity = processingActive || backgroundRefreshPending || status === 'found'
  const tone = hasAity ? 'primary.main' : 'text.secondary'

  return (
    <>
      <Button
        size="small"
        variant="outlined"
        onClick={(e) => setAnchorEl(e.currentTarget)}
        endIcon={<ExpandMoreIcon sx={{ fontSize: 16 }} />}
        sx={{
          // Outlined small button so the height matches the sibling Edit/Save/Cancel
          // buttons in the row (the prior hand-rolled py made it shorter).
          textTransform: 'none', fontWeight: 600, color: tone, minWidth: 0,
          gap: 0.5, borderColor: 'primary.200', bgcolor: 'white',
          '&:hover': { bgcolor: 'grey.50', borderColor: 'primary.300' },
        }}
      >
        <AityPulse active={processingActive} size={16} dim={!hasAity} />
        {/* No fontSize override — inherit the small-button font so the line-box (and
            thus the button height) matches the sibling Edit/Save/Cancel buttons. */}
        <Box component="span">{label}</Box>
        {failedCount > 0 && (
          <Chip
            size="small"
            label={`${failedCount} failed`}
            color="error"
            sx={{ height: 16, fontSize: '0.6rem', ml: 0.25 }}
          />
        )}
      </Button>

      <Menu
        anchorEl={anchorEl}
        open={open}
        onClose={close}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
        slotProps={{ paper: { sx: { mt: 0.5, minWidth: 240 } } }}
      >
        <Box sx={{ px: 2, py: 0.5 }}>
          <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5 }}>
            Aity
          </Typography>
        </Box>
        <Divider />

        {/* Show/hide lives in a dedicated toggle button beside the hub now. */}
        {isEditMode && status === 'used' && (
          <MenuItem onClick={run(onRestore)}>
            <ListItemIcon><RestoreIcon fontSize="small" /></ListItemIcon>
            <ListItemText>Restore suggestions</ListItemText>
          </MenuItem>
        )}

        {backgroundRefreshPending && (
          <MenuItem onClick={run(onRefresh)}>
            <ListItemIcon><RefreshIcon fontSize="small" color="info" /></ListItemIcon>
            <ListItemText>Load new Aity info</ListItemText>
          </MenuItem>
        )}

        {failedCount > 0 && (
          <MenuItem onClick={run(onRetryFailed)}>
            <ListItemIcon><ReplayIcon fontSize="small" color="error" /></ListItemIcon>
            <ListItemText>{`Retry ${failedCount} failed file${failedCount > 1 ? 's' : ''}`}</ListItemText>
          </MenuItem>
        )}

        <MenuItem onClick={run(onReRun)} disabled={processingActive}>
          <ListItemIcon><AutorenewIcon fontSize="small" /></ListItemIcon>
          <ListItemText
            primary="Re-run enrichment"
            secondary="Re-analyses every file — regenerates per-file suggestions"
            secondaryTypographyProps={{ sx: { fontSize: '0.65rem' } }}
          />
        </MenuItem>

        {/* Multi-file synthesis: unify per-file suggestions into one resource-level
            name/description + deduped tag set. Only meaningful across >1 file. */}
        {multiFile && [
          <MenuItem key="generate" onClick={run(onGenerateMetadata)} disabled={processingActive}>
            <ListItemIcon><AutoFixHighIcon fontSize="small" /></ListItemIcon>
            <ListItemText
              primary="Generate combined metadata"
              secondary="Unifies all files into one name, description & deduped tags — to review"
              secondaryTypographyProps={{ sx: { fontSize: '0.65rem' } }}
            />
          </MenuItem>,
          <MenuItem key="autoapprove" onClick={run(onAutoApprove)} disabled={processingActive}>
            <ListItemIcon><AutoAwesomeIcon fontSize="small" color="primary" /></ListItemIcon>
            <ListItemText
              primary="Auto-approve metadata"
              secondary="Generates and applies the combined metadata onto the resource"
              secondaryTypographyProps={{ sx: { fontSize: '0.65rem' } }}
            />
          </MenuItem>,
        ]}

        <MenuItem onClick={run(onRefresh)}>
          <ListItemIcon><RefreshIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Refresh status</ListItemText>
        </MenuItem>
      </Menu>
    </>
  )
}

export default AityHub
