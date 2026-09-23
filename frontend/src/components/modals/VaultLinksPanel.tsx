import { useState, useEffect, useRef } from 'react'
import {
  Box, Stack, Typography, Chip, Tooltip, IconButton, Alert, Button,
  CircularProgress, Accordion, AccordionSummary, AccordionDetails,
  Table, TableBody, TableCell, TableHead, TableRow, TableContainer,
  Paper, Popover, MenuItem, TextField,
} from '@mui/material'
import { ExpandMore, ContentCopy, Check, InsertDriveFile, Description, OpenInNew, Download, InfoOutlined, Schedule } from '@mui/icons-material'
import vaultService, { type VaultLinkEntry, type ResourceVaultLinks, type SignedUrlGrant } from '../../api/vaultService'

interface VaultLinksPanelProps {
  resourceId: string
  open: boolean   // when true → triggers lazy fetch if not yet loaded
}

function VaultLinksPanel({ resourceId, open }: VaultLinksPanelProps) {
  const [data, setData] = useState<ResourceVaultLinks | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [copiedHash, setCopiedHash] = useState<string | null>(null)
  const [fetchKey, setFetchKey] = useState(0)

  // Signed-link sharing (Epic 5.4) — a per-link, link-scoped grant popover.
  const [shareAnchor, setShareAnchor] = useState<HTMLElement | null>(null)
  const [shareLink, setShareLink] = useState<VaultLinkEntry | null>(null)
  const [shareHours, setShareHours] = useState(168)
  const [shareBusy, setShareBusy] = useState(false)
  const [shareResult, setShareResult] = useState<SignedUrlGrant | null>(null)
  const [shareError, setShareError] = useState<string | null>(null)
  const [shareCopied, setShareCopied] = useState(false)

  // Ref flag prevents re-fetching within the same fetchKey cycle.
  // Mutating a ref never triggers a render — only fetchKey does.
  const fetchedRef = useRef(false)
  const lastResourceIdRef = useRef('')

  if (lastResourceIdRef.current !== resourceId) {
    lastResourceIdRef.current = resourceId
    fetchedRef.current = false
  }

  // Lazy fetch: fires once when the tab opens. Re-fires only after retry.
  // `resolved` closes over each effect invocation so the cleanup can tell
  // whether the fetch completed before it ran. When React StrictMode (or a
  // mid-flight tab-close) triggers cleanup before the response arrives,
  // fetchedRef is reset so the next open kicks off a fresh fetch.
  useEffect(() => {
    if (!open || fetchedRef.current) return

    fetchedRef.current = true
    let cancelled = false
    let resolved = false
    setLoading(true)
    setError(null)

    vaultService.links.forResource(resourceId)
      .then(res => {
        resolved = true
        if (!cancelled) {
          setData(res.data)
          setLoading(false)
        }
      })
      .catch(() => {
        resolved = true
        if (!cancelled) {
          setError('Failed to load Vault links. Please try again.')
          setLoading(false)
        }
      })

    return () => {
      cancelled = true
      // If the fetch hadn't finished when cleanup ran (StrictMode double-invoke,
      // tab closed mid-request), allow the next open to start a fresh fetch.
      if (!resolved) fetchedRef.current = false
    }
  }, [open, resourceId, fetchKey])

  const handleRetry = () => {
    fetchedRef.current = false
    setError(null)
    setData(null)
    setFetchKey(k => k + 1)   // increments dep → effect re-fires
  }

  const handleCopy = (url: string, hash: string) => {
    navigator.clipboard.writeText(url).then(() => {
      setCopiedHash(hash)
      setTimeout(() => setCopiedHash(null), 2000)
    })
  }

  const openShare = (link: VaultLinkEntry, anchor: HTMLElement) => {
    setShareLink(link)
    setShareAnchor(anchor)
    setShareHours(168)
    setShareResult(null)
    setShareError(null)
    setShareCopied(false)
  }

  const closeShare = () => {
    setShareAnchor(null)
    setShareLink(null)
  }

  const mintShare = async () => {
    if (!shareLink) return
    setShareBusy(true)
    setShareError(null)
    try {
      const res = await vaultService.admin.mintSignedUrl(shareLink.vault_id, shareHours, shareLink.hash)
      setShareResult(res.data)
    } catch {
      setShareError('Could not mint a signed link (superadmin only).')
    } finally {
      setShareBusy(false)
    }
  }

  const copyShare = () => {
    if (!shareResult) return
    navigator.clipboard.writeText(shareResult.url).then(() => {
      setShareCopied(true)
      setTimeout(() => setShareCopied(false), 2000)
    })
  }

  if (!open) return null

  if (loading) {
    return (
      <Stack alignItems="center" justifyContent="center" sx={{ py: 6 }}>
        <CircularProgress size={32} />
        <Typography variant="body2" color="text.secondary" sx={{ mt: 1.5 }}>
          Generating Vault links…
        </Typography>
      </Stack>
    )
  }

  if (error) {
    return (
      <Alert
        severity="error"
        sx={{ mt: 1 }}
        action={
          <Button
            size="small"
            color="inherit"
            onClick={handleRetry}
          >
            Retry
          </Button>
        }
      >
        {error}
      </Alert>
    )
  }

  if (!data) return null

  const hasAnyLinks =
    data.resource.links.length > 0 ||
    data.files.some(f => f.links.length > 0)

  if (!hasAnyLinks) {
    return (
      <Box sx={{ py: 4, textAlign: 'center' }}>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
          No Vault links available for this resource.
        </Typography>
        <Typography variant="caption" color="text.disabled">
          To generate links: add this resource to a non-default workspace, then associate that workspace
          with a Vault in the workspace edit form. Or enable "Include default workspace" on a Vault in Platform Administration.
        </Typography>
      </Box>
    )
  }

  return (
    <Stack spacing={1.5}>
      {/* Resource-level links */}
      {data.resource.links.length > 0 && (
        <Accordion defaultExpanded disableGutters>
          <AccordionSummary expandIcon={<ExpandMore />}>
            <Stack direction="row" spacing={1} alignItems="center">
              <Description sx={{ fontSize: '1rem', color: 'text.secondary' }} />
              <Typography variant="body2" fontWeight={600}>Resource</Typography>
              <Chip label={data.resource.name} size="small" sx={{ bgcolor: 'grey.100', color: 'text.secondary', maxWidth: 200 }} />
              <Chip
                label={`${data.resource.links.length} link${data.resource.links.length !== 1 ? 's' : ''}`}
                size="small"
                sx={{ bgcolor: 'primary.subtle', color: 'primary.main', fontSize: '0.75rem' }}
              />
            </Stack>
          </AccordionSummary>
          <AccordionDetails sx={{ p: 0 }}>
            <LinksTable links={data.resource.links} copiedHash={copiedHash} onCopy={handleCopy} onShare={openShare} />
          </AccordionDetails>
        </Accordion>
      )}

      {/* File-level links */}
      {data.files.map((file) => (
        file.links.length > 0 && (
          <Accordion key={file.id} disableGutters>
            <AccordionSummary expandIcon={<ExpandMore />}>
              <Stack direction="row" spacing={1} alignItems="center">
                <InsertDriveFile sx={{ fontSize: '1rem', color: 'text.secondary' }} />
                <Typography variant="body2" fontWeight={500} sx={{ fontFamily: 'monospace', fontSize: '0.875rem' }}>
                  {file.filename}
                </Typography>
                <Chip
                  label={file.mime_type.split('/')[1] ?? file.mime_type}
                  size="small"
                  sx={{ bgcolor: 'grey.100', color: 'text.secondary', fontSize: '0.75rem' }}
                />
                <Chip
                  label={`${file.links.length} link${file.links.length !== 1 ? 's' : ''}`}
                  size="small"
                  sx={{ bgcolor: 'primary.subtle', color: 'primary.main', fontSize: '0.75rem' }}
                />
              </Stack>
            </AccordionSummary>
            <AccordionDetails sx={{ p: 0 }}>
              <LinksTable links={file.links} copiedHash={copiedHash} onCopy={handleCopy} onShare={openShare} />
            </AccordionDetails>
          </Accordion>
        )
      ))}

      {/* Link-scoped signed URL (Epic 5.4) — a time-limited grant that opens
          just this address, no vault key. Superadmin only. */}
      <Popover
        open={Boolean(shareAnchor)}
        anchorEl={shareAnchor}
        onClose={closeShare}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
        transformOrigin={{ vertical: 'top', horizontal: 'right' }}
      >
        <Box sx={{ p: 2, width: 340 }}>
          <Typography variant="body2" fontWeight={600} sx={{ mb: 0.5 }}>
            Share a signed link
          </Typography>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>
            A time-limited URL opening only this {shareLink?.workspace_id === null ? 'resource' : 'address'} —
            no vault key, works even while unpublished.
          </Typography>

          {shareError && <Alert severity="error" sx={{ mb: 1, py: 0 }}>{shareError}</Alert>}

          {!shareResult ? (
            <Stack direction="row" spacing={1}>
              <TextField
                select size="small" label="Valid for"
                value={shareHours}
                onChange={(e) => setShareHours(Number(e.target.value))}
                sx={{ width: 130 }}
              >
                <MenuItem value={1}>1 hour</MenuItem>
                <MenuItem value={24}>24 hours</MenuItem>
                <MenuItem value={168}>7 days</MenuItem>
                <MenuItem value={720}>30 days</MenuItem>
              </TextField>
              <Button
                size="small" variant="contained"
                startIcon={<Schedule sx={{ fontSize: '0.875rem' }} />}
                disabled={shareBusy}
                onClick={mintShare}
              >
                {shareBusy ? 'Minting…' : 'Create link'}
              </Button>
            </Stack>
          ) : (
            <Alert
              severity="success"
              sx={{ py: 0 }}
              action={
                <Button color="inherit" size="small" onClick={copyShare} startIcon={shareCopied ? <Check sx={{ fontSize: '0.875rem' }} /> : <ContentCopy sx={{ fontSize: '0.875rem' }} />}>
                  {shareCopied ? 'Copied' : 'Copy'}
                </Button>
              }
            >
              <Typography variant="caption" sx={{ fontFamily: 'monospace', wordBreak: 'break-all', display: 'block' }}>
                {shareResult.url}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                Expires {new Date(shareResult.expires_at).toLocaleString()}
              </Typography>
            </Alert>
          )}
        </Box>
      </Popover>
    </Stack>
  )
}

interface LinksTableProps {
  links: VaultLinkEntry[]
  copiedHash: string | null
  onCopy: (url: string, hash: string) => void
  onShare: (link: VaultLinkEntry, anchor: HTMLElement) => void
}

function LinksTable({ links, copiedHash, onCopy, onShare }: LinksTableProps) {
  return (
    <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 0 }}>
      <Table size="small">
        <TableHead>
          <TableRow sx={{ bgcolor: 'grey.50' }}>
            <TableCell sx={{ fontWeight: 600, fontSize: '0.75rem', py: 1 }}>Vault</TableCell>
            <TableCell sx={{ fontWeight: 600, fontSize: '0.75rem', py: 1 }}>Workspace</TableCell>
            <TableCell sx={{ fontWeight: 600, fontSize: '0.75rem', py: 1 }}>URL</TableCell>
            <TableCell sx={{ fontWeight: 600, fontSize: '0.75rem', py: 1, width: 144 }} align="center">Actions</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {links.map((link) => (
            <TableRow key={link.id} sx={{ '&:last-child td': { border: 0 } }}>
              <TableCell sx={{ py: 0.75 }}>
                <Stack direction="row" spacing={0.75} alignItems="center">
                  <Typography variant="caption" fontWeight={500}>{link.vault_name}</Typography>
                  {link.is_expired && (
                    <Chip label="Expired" size="small" color="warning" sx={{ fontSize: '0.75rem', height: 18 }} />
                  )}
                </Stack>
              </TableCell>
              <TableCell sx={{ py: 0.75 }}>
                <Typography variant="caption" color="text.secondary">
                  {link.workspace_name ?? `— (${link.vault_purpose ?? 'vault'})`}
                </Typography>
              </TableCell>
              <TableCell sx={{ py: 0.75, maxWidth: 260 }}>
                <Typography
                  variant="caption"
                  sx={{
                    fontFamily: 'monospace',
                    color: link.is_expired ? 'text.disabled' : 'primary.main',
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    display: 'block',
                  }}
                >
                  {link.url}
                </Typography>
              </TableCell>
              <TableCell sx={{ py: 0.75 }} align="center">
                <Stack direction="row" spacing={0} justifyContent="center">
                  {/* Open inline in new tab */}
                  <Tooltip title="Open in browser">
                    <span>
                      <IconButton
                        size="small"
                        disabled={link.is_expired}
                        component="a"
                        href={link.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                      >
                        <OpenInNew sx={{ fontSize: '0.875rem' }} />
                      </IconButton>
                    </span>
                  </Tooltip>

                  {/* Metadata info */}
                  <Tooltip title="View metadata">
                    <span>
                      <IconButton
                        size="small"
                        disabled={link.is_expired}
                        component="a"
                        href={link.info_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                      >
                        <InfoOutlined sx={{ fontSize: '0.875rem' }} />
                      </IconButton>
                    </span>
                  </Tooltip>

                  {/* Download (only when Vault has is_downloadable = true) */}
                  {link.download_url && (
                    <Tooltip title="Download">
                      <span>
                        <IconButton
                          size="small"
                          disabled={link.is_expired}
                          component="a"
                          href={link.download_url}
                          target="_blank"
                          rel="noopener noreferrer"
                          sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                        >
                          <Download sx={{ fontSize: '0.875rem' }} />
                        </IconButton>
                      </span>
                    </Tooltip>
                  )}

                  {/* Copy URL */}
                  <Tooltip title={copiedHash === link.hash ? 'Copied!' : 'Copy URL'}>
                    <span>
                      <IconButton
                        size="small"
                        disabled={link.is_expired}
                        onClick={() => onCopy(link.url, link.hash)}
                        sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                      >
                        {copiedHash === link.hash
                          ? <Check sx={{ fontSize: '0.875rem', color: 'success.main' }} />
                          : <ContentCopy sx={{ fontSize: '0.875rem' }} />
                        }
                      </IconButton>
                    </span>
                  </Tooltip>

                  {/* Share a time-limited signed link scoped to this address */}
                  <Tooltip title="Share signed link">
                    <span>
                      <IconButton
                        size="small"
                        disabled={link.is_expired}
                        onClick={(e) => onShare(link, e.currentTarget)}
                        sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                      >
                        <Schedule sx={{ fontSize: '0.875rem' }} />
                      </IconButton>
                    </span>
                  </Tooltip>
                </Stack>
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  )
}

export default VaultLinksPanel
