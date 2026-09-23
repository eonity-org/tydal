/**
 * WorkspaceAskDialog — RAG Q&A dialog for workspace-scoped knowledge retrieval.
 *
 * Sends a question to POST /workspaces/{id}/ask and renders the answer along with
 * source cards. Each source card lazily fetches Vault links via
 * GET /resources/{id}/vault-links so users can navigate directly to the asset.
 */

import { useState, useEffect } from 'react'
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Box,
  Button,
  TextField,
  Typography,
  Stack,
  CircularProgress,
  Alert,
  Divider,
  Chip,
  IconButton,
  Tooltip,
  Collapse,
  Switch,
  FormControlLabel,
} from '@mui/material'
import {
  Close,
  AutoAwesome,
  OpenInNew,
  Download,
  ExpandMore,
  ExpandLess,
  InsertDriveFile,
} from '@mui/icons-material'
import workspaceService, { type RagSource } from '../../api/workspaceService'
import vaultService, { type VaultLinkEntry } from '../../api/vaultService'
import TydalIsotype from '../ui/TydalIsotype'

// ─── Source Card ──────────────────────────────────────────────────────────────

interface SourceCardProps {
  source: RagSource
  index: number
}

function SourceCard({ source, index }: SourceCardProps) {
  const [links, setLinks] = useState<VaultLinkEntry[] | null>(null)
  const [loadingLinks, setLoadingLinks] = useState(false)
  const [expanded, setExpanded] = useState(false)

  const fetchLinks = async () => {
    if (links !== null) {
      setExpanded((v) => !v)
      return
    }
    setLoadingLinks(true)
    setExpanded(true)
    try {
      const res = await vaultService.links.forResource(source.resource_id)
      // Combine resource-level and first-file links for display
      const resourceLinks = res.data.resource.links ?? []
      const fileLinks = res.data.files.flatMap((f) => f.links)
      setLinks([...resourceLinks, ...fileLinks])
    } catch {
      setLinks([])
    } finally {
      setLoadingLinks(false)
    }
  }

  return (
    <Box
      sx={{
        border: '1px solid',
        borderColor: 'divider',
        borderRadius: 1.5,
        p: 1.5,
        bgcolor: 'background.paper',
      }}
    >
      <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={1}>
        <Stack direction="row" alignItems="center" spacing={1} sx={{ minWidth: 0, flex: 1 }}>
          <Chip
            label={index + 1}
            size="small"
            sx={{ bgcolor: 'primary.main', color: 'white', fontWeight: 700, minWidth: 28, height: 22 }}
          />
          <InsertDriveFile sx={{ fontSize: '1rem', color: 'text.secondary', flexShrink: 0 }} />
          <Typography
            variant="body2"
            sx={{ fontWeight: 500, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', flex: 1 }}
          >
            {source.resource_name}
          </Typography>
          {source.pages.length > 0 && (
            <Stack direction="row" spacing={0.5} sx={{ flexShrink: 0 }}>
              {source.pages.map((p) => (
                <Chip
                  key={p}
                  label={`p. ${p}`}
                  size="small"
                  variant="outlined"
                  sx={{ fontSize: '0.7rem', height: 20 }}
                />
              ))}
            </Stack>
          )}
        </Stack>

        <Tooltip title={expanded ? 'Hide links' : 'Show Vault links'}>
          <IconButton size="small" onClick={fetchLinks} sx={{ flexShrink: 0 }}>
            {loadingLinks
              ? <CircularProgress size={14} />
              : expanded
                ? <ExpandLess fontSize="small" />
                : <ExpandMore fontSize="small" />}
          </IconButton>
        </Tooltip>
      </Stack>

      <Collapse in={expanded}>
        <Box sx={{ mt: 1.5 }}>
          {links === null || loadingLinks ? null : links.length === 0 ? (
            <Typography variant="caption" color="text.secondary">
              No Vault links available for this resource.
            </Typography>
          ) : (
            <Stack spacing={0.75}>
              {links.map((link) => (
                <Stack
                  key={link.id}
                  direction="row"
                  alignItems="center"
                  spacing={1}
                  sx={{ pl: 0.5 }}
                >
                  <Typography variant="caption" color="text.secondary" sx={{ flex: 1, minWidth: 0 }}>
                    <strong>{link.vault_name}</strong>
                    {link.workspace_name ? ` · ${link.workspace_name}` : ''}
                    {link.is_expired ? (
                      <Chip label="expired" size="small" color="warning" sx={{ ml: 0.5, height: 16, fontSize: '0.65rem' }} />
                    ) : null}
                  </Typography>
                  <Tooltip title="Open in browser">
                    <IconButton
                      size="small"
                      component="a"
                      href={link.url}
                      target="_blank"
                      rel="noopener noreferrer"
                      sx={{ p: 0.25 }}
                    >
                      <OpenInNew sx={{ fontSize: '0.875rem' }} />
                    </IconButton>
                  </Tooltip>
                  {link.download_url && (
                    <Tooltip title="Download">
                      <IconButton
                        size="small"
                        component="a"
                        href={link.download_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        sx={{ p: 0.25 }}
                      >
                        <Download sx={{ fontSize: '0.875rem' }} />
                      </IconButton>
                    </Tooltip>
                  )}
                </Stack>
              ))}
            </Stack>
          )}
        </Box>
      </Collapse>
    </Box>
  )
}

// ─── Main Dialog ──────────────────────────────────────────────────────────────

interface WorkspaceAskDialogProps {
  open: boolean
  workspaceId: string
  workspaceName?: string
  onClose: () => void
}

export default function WorkspaceAskDialog({
  open,
  workspaceId,
  workspaceName,
  onClose,
}: WorkspaceAskDialogProps) {
  const [question, setQuestion] = useState('')
  const [loading, setLoading] = useState(false)
  const [answer, setAnswer] = useState<string | null>(null)
  const [sources, setSources] = useState<RagSource[]>([])
  const [error, setError] = useState<string | null>(null)
  const [contextTruncated, setContextTruncated] = useState(false)
  const [includeMetadata, setIncludeMetadata] = useState(true)

  // Reset state when dialog is closed or workspace changes
  useEffect(() => {
    if (!open) {
      setQuestion('')
      setAnswer(null)
      setSources([])
      setError(null)
      setContextTruncated(false)
      // includeMetadata intentionally preserved across opens — user set it deliberately
    }
  }, [open])

  const handleAsk = async () => {
    const q = question.trim()
    if (!q) return

    setLoading(true)
    setAnswer(null)
    setSources([])
    setError(null)
    setContextTruncated(false)

    try {
      const res = await workspaceService.ask(workspaceId, q, 5, !includeMetadata)
      setAnswer(res.data.answer)
      setSources(res.data.sources ?? [])
      setContextTruncated(res.data.context_truncated ?? false)
    } catch (err: unknown) {
      const msg =
        typeof err === 'object' && err !== null && 'message' in err
          ? (err as { message: string }).message
          : 'Could not reach the RAG service. Please try again.'
      setError(msg)
    } finally {
      setLoading(false)
    }
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleAsk()
    }
  }

  const hasResult = answer !== null

  return (
    <Dialog
      open={open}
      onClose={onClose}
      maxWidth="sm"
      fullWidth
      PaperProps={{ sx: { borderRadius: 2 } }}
    >
      <DialogTitle sx={{ pb: 1 }}>
        <Stack direction="row" alignItems="center" spacing={1}>
          <AutoAwesome sx={{ color: 'primary.main', fontSize: '1.25rem' }} />
          <Typography variant="h6" sx={{ flex: 1, fontWeight: 600 }}>
            Ask Aity about this workspace
          </Typography>
          <IconButton size="small" onClick={onClose} sx={{ color: 'text.secondary' }}>
            <Close fontSize="small" />
          </IconButton>
        </Stack>
        {workspaceName && (
          <Typography variant="caption" color="text.secondary" sx={{ pl: 3.5 }}>
            {workspaceName}
          </Typography>
        )}
      </DialogTitle>

      <DialogContent dividers sx={{ pt: 2, pb: 2 }}>
        <Stack spacing={2}>
          {/* Question input */}
          <TextField
            autoFocus
            multiline
            minRows={2}
            maxRows={5}
            fullWidth
            label="Your question"
            placeholder="What are the brand colors? Which resources cover safety regulations?"
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            onKeyDown={handleKeyDown}
            disabled={loading}
            size="small"
            helperText="Press Enter to submit, Shift+Enter for newline"
          />

          {/* Error */}
          {error && (
            <Alert severity="error" onClose={() => setError(null)}>
              {error}
            </Alert>
          )}

          {/* Loading */}
          {loading && (
            <Stack direction="row" alignItems="center" spacing={1.5}>
              <CircularProgress size={18} />
              <Typography variant="body2" color="text.secondary">
                Searching through workspace knowledge…
              </Typography>
            </Stack>
          )}

          {/* Answer */}
          {hasResult && !loading && (
            <Stack spacing={2}>
              <Box
                sx={{
                  bgcolor: 'grey.50',
                  border: '1px solid',
                  borderColor: 'divider',
                  borderRadius: 1.5,
                  p: 2,
                }}
              >
                <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', lineHeight: 1.7 }}>
                  {answer}
                </Typography>
              </Box>

              {contextTruncated && (
                <Alert severity="warning" sx={{ py: 0.5 }}>
                  This workspace has too many resources to fit in a single query — some may not be represented in this answer. Try narrowing your question to a specific topic or document.
                </Alert>
              )}

              {!includeMetadata && (
                <Alert severity="info" sx={{ py: 0.5 }}>
                  Names, descriptions, and tags are excluded. Enable <strong>Include AI metadata</strong> to search them.
                </Alert>
              )}

              {sources.length > 0 && (
                <>
                  <Divider>
                    <Typography variant="caption" color="text.secondary">
                      {sources.length} relevant resource{sources.length > 1 ? 's' : ''}
                    </Typography>
                  </Divider>
                  <Stack spacing={1}>
                    {sources.map((source, i) => (
                      <SourceCard key={source.resource_id} source={source} index={i} />
                    ))}
                  </Stack>
                </>
              )}
            </Stack>
          )}
        </Stack>
      </DialogContent>

      <DialogActions sx={{ px: 2.5, py: 1.5, justifyContent: 'space-between' }}>
        {/* Left: metadata toggle */}
        <Tooltip title={includeMetadata ? 'Names, descriptions, and tags are included alongside document text' : 'Only raw document text is searched — names, descriptions, and tags are excluded'}>
          <FormControlLabel
            control={
              <Switch
                size="small"
                checked={includeMetadata}
                onChange={(e) => setIncludeMetadata(e.target.checked)}
                color="secondary"
              />
            }
            label={
              <Typography variant="caption" color={includeMetadata ? 'secondary.main' : 'text.disabled'}>
                Include AI metadata
              </Typography>
            }
            sx={{ ml: 0, mr: 'auto' }}
          />
        </Tooltip>

        {/* Right: close + ask */}
        <Stack direction="row" spacing={1}>
          <Button onClick={onClose} size="small">
            Close
          </Button>
          <Button
            variant="contained"
            size="small"
            onClick={handleAsk}
            disabled={loading || !question.trim()}
            startIcon={loading ? <CircularProgress size={14} color="inherit" /> : <TydalIsotype size={18} variant="white" />}
          >
            {hasResult ? 'Ask again' : 'Ask'}
          </Button>
        </Stack>
      </DialogActions>
    </Dialog>
  )
}
