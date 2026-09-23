import { useEffect, useMemo, useState } from 'react'
import {
  Box, Button, Chip, CircularProgress,
  LinearProgress, Stack, Tooltip, Typography,
} from '@mui/material'
import CheckCircleIcon from '@mui/icons-material/CheckCircle'
import CheckIcon from '@mui/icons-material/Check'
import ErrorIcon from '@mui/icons-material/Error'
import ImageIcon from '@mui/icons-material/Image'
import InsertDriveFileIcon from '@mui/icons-material/InsertDriveFile'
import MovieIcon from '@mui/icons-material/Movie'
import MusicNoteIcon from '@mui/icons-material/MusicNote'
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf'
import RefreshIcon from '@mui/icons-material/Refresh'
import UndoIcon from '@mui/icons-material/Undo'
import WarningAmberIcon from '@mui/icons-material/WarningAmber'

import type { WizardMode } from './ModeUploadStep'
import type { WizardResourceRecord } from '../ResourceWizard'
import type { AityPollEntry } from '../../../hooks/useAityPolling'
import type { FileSuggestion } from '../../../hooks/useAiSuggestionsPoller'

// ─── helpers ──────────────────────────────────────────────────────────────────

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function statusLabel(
  uploadStatus: WizardResourceRecord['uploadStatus'],
  pollEntry: AityPollEntry | null,
  error?: string
): string {
  if (uploadStatus === 'pending')                      return 'Queued'
  if (uploadStatus === 'creating')                     return 'Creating resource…'
  if (uploadStatus === 'uploading')                    return 'Uploading…'
  if (uploadStatus === 'error')                        return error ?? 'Upload failed'
  if (pollEntry?.stage === 'extracting')               return 'Extracting text…'
  if (pollEntry?.stage === 'ai_analyzing')             return 'Analyzing with AI…'
  if (pollEntry?.stage === 'queued')                   return 'Queued for processing…'
  if (pollEntry?.status === 'ready')                   return 'Analysis complete'
  if (pollEntry?.status === 'failed')                  return 'Processing failed'
  if (pollEntry?.status === 'not_supported')           return 'No AI support'
  return 'Analyzing…'
}

function fileKindLabel(file: File): string {
  if (file.type.startsWith('image/')) return 'Image preview'
  if (file.type.startsWith('video/')) return 'Video preview'
  if (file.type.startsWith('audio/')) return 'Audio file'
  if (file.type === 'application/pdf') return 'PDF file'
  return 'File'
}

function FileSnapshot({ file }: { file: File }) {
  const previewUrl = useMemo(() => {
    if (!file.type.startsWith('image/') && !file.type.startsWith('video/')) return null
    return URL.createObjectURL(file)
  }, [file])

  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl)
    }
  }, [previewUrl])

  const fallbackIcon = file.type.startsWith('audio/')
    ? <MusicNoteIcon sx={{ fontSize: 26 }} />
    : file.type === 'application/pdf'
      ? <PictureAsPdfIcon sx={{ fontSize: 26 }} />
      : file.type.startsWith('image/')
        ? <ImageIcon sx={{ fontSize: 26 }} />
        : file.type.startsWith('video/')
          ? <MovieIcon sx={{ fontSize: 26 }} />
          : <InsertDriveFileIcon sx={{ fontSize: 26 }} />

  return (
    <Tooltip title={fileKindLabel(file)} placement="top">
      <Box
        sx={{
          width: 64,
          height: 64,
          flexShrink: 0,
          borderRadius: 1,
          overflow: 'hidden',
          bgcolor: 'action.hover',
          border: '1px solid',
          borderColor: 'divider',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          color: 'text.secondary',
        }}
      >
        {previewUrl && file.type.startsWith('image/') ? (
          <Box
            component="img"
            src={previewUrl}
            alt=""
            sx={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
          />
        ) : previewUrl && file.type.startsWith('video/') ? (
          <Box
            component="video"
            src={previewUrl}
            muted
            playsInline
            preload="metadata"
            sx={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
          />
        ) : (
          fallbackIcon
        )}
      </Box>
    </Tooltip>
  )
}

// ─── FileAnalysisCard ─────────────────────────────────────────────────────────

interface FileAnalysisCardProps {
  file: File
  role?: string           // 'canonical' | 'supporting' — only shown for canonical mode
  uploadStatus: WizardResourceRecord['uploadStatus']
  pollEntry: AityPollEntry | null
  suggestion: FileSuggestion | null
  isPicked?: boolean      // components/canonical: this file's suggestion drives name/desc
  isAccepted?: boolean    // batch: suggestion was accepted
  mode: WizardMode
  onAccept?: () => void
  onPick?: () => void
  onReject?: () => void
  onRetry?: () => Promise<void>
}

function FileAnalysisCard({
  file, role,
  uploadStatus, pollEntry, suggestion,
  isPicked, isAccepted, mode,
  onAccept, onPick, onReject, onRetry,
}: FileAnalysisCardProps) {
  const [retrying, setRetrying] = useState(false)

  const isDone      = uploadStatus === 'done'
  const isError     = uploadStatus === 'error'
  const pollStatus  = pollEntry?.status ?? null
  const highlighted = isAccepted || isPicked
  const hasSuggestion = !!suggestion && (
    !!suggestion.suggestedName || !!suggestion.suggestedDescription || suggestion.suggestedTags.length > 0
  )
  const showRetry = isDone && onRetry && (
    pollStatus === 'failed' || pollStatus === 'ready' || pollStatus === 'not_supported'
  )

  const handleRetry = async () => {
    if (!onRetry) return
    setRetrying(true)
    try { await onRetry() } finally { setRetrying(false) }
  }

  const actionButton = mode === 'batch'
    ? (!hasSuggestion ? null
        : isAccepted
          ? <Button size="small" variant="text" startIcon={<UndoIcon sx={{ fontSize: '0.875rem' }} />}
              onClick={onReject} sx={{ fontSize: '0.75rem', py: 0, color: 'text.secondary' }}>
              Undo
            </Button>
          : <Button size="small" variant="outlined" color="success"
              startIcon={<CheckIcon sx={{ fontSize: '0.875rem' }} />}
              onClick={onAccept} sx={{ fontSize: '0.75rem', py: 0.25 }}>
              Accept
            </Button>
      )
    : (!hasSuggestion ? null
        : isPicked
          ? <Button size="small" variant="text" startIcon={<UndoIcon sx={{ fontSize: '0.875rem' }} />}
              onClick={onReject} sx={{ fontSize: '0.75rem', py: 0, color: 'text.secondary' }}>
              Unpick
            </Button>
          : <Button size="small" variant="outlined" color="primary"
              onClick={onPick} sx={{ fontSize: '0.75rem', py: 0.25 }}>
              Pick
            </Button>
      )

  return (
    <Box
      sx={{
        borderRadius: 1.5,
        overflow: 'hidden',
        borderLeft: '3px solid',
        borderColor: highlighted ? 'primary.main' : 'transparent',
        bgcolor: highlighted ? 'grey.100' : isError ? 'error.50' : 'grey.50',
        transition: 'background-color 0.2s, border-color 0.2s',
      }}
    >
      {/* ── Header row ──────────────────────────────────────────────────── */}
      <Stack direction="row" alignItems="flex-start" spacing={1.5} sx={{ px: 2, py: 1.25 }}>
        <FileSnapshot file={file} />

        {/* Status icon */}
        <Box sx={{ pt: 0.25, flexShrink: 0 }}>
          {isError
            ? <ErrorIcon sx={{ fontSize: '1.125rem', color: 'error.main' }} />
            : !isDone
              ? <CircularProgress size={16} />
              : hasSuggestion
                ? <CheckCircleIcon sx={{ fontSize: '1.125rem', color: 'success.main' }} />
                : (pollStatus === 'failed' || pollStatus === 'not_supported' || pollStatus === 'ready')
                  ? <WarningAmberIcon sx={{ fontSize: '1.125rem', color: 'warning.main' }} />
                  : <CircularProgress size={16} />
          }
        </Box>

        {/* File info */}
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Stack direction="row" alignItems="center" spacing={0.75} sx={{ flexWrap: 'wrap' }}>
            <Typography variant="body2" noWrap title={file.name} sx={{ fontWeight: 500, maxWidth: '100%' }}>
              {file.name}
            </Typography>
            {role && (
              <Chip
                label={role}
                size="small"
                variant="outlined"
                sx={{
                  height: '1.25rem',
                  fontSize: '0.65rem',
                  px: 0.25,
                  color: role === 'canonical' ? 'primary.main' : 'text.secondary',
                  borderColor: role === 'canonical' ? 'primary.main' : 'divider',
                }}
              />
            )}
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {statusLabel(uploadStatus, pollEntry)}
          </Typography>
        </Box>

        {/* Size + MIME + retry */}
        <Stack alignItems="flex-end" spacing={0} sx={{ flexShrink: 0 }}>
          <Typography variant="caption" color="text.disabled">
            {formatBytes(file.size)}
          </Typography>
          <Typography variant="caption" color="text.disabled" sx={{ opacity: 0.7 }}>
            {file.type || '—'}
          </Typography>
          {showRetry && (
            <Button
              size="small"
              variant="text"
              startIcon={retrying
                ? <CircularProgress size={11} />
                : <RefreshIcon sx={{ fontSize: '0.8rem' }} />}
              onClick={handleRetry}
              disabled={retrying}
              sx={{ fontSize: '0.7rem', py: 0, minHeight: 0, color: 'text.secondary', mt: 0.5 }}
            >
              Retry
            </Button>
          )}
        </Stack>
      </Stack>

      {/* Upload progress */}
      {uploadStatus === 'uploading' && <LinearProgress sx={{ borderRadius: 0 }} />}

      {/* ── Suggestion panel ────────────────────────────────────────────── */}
      {hasSuggestion && isDone && (
        <Box
          sx={{
            px: 2,
            pt: 1,
            pb: 1.5,
            bgcolor: highlighted ? 'grey.200' : 'grey.100',
          }}
        >
          {suggestion!.suggestedName && (
            <Typography variant="caption" fontWeight={600} sx={{ display: 'block' }}>
              {suggestion!.suggestedName}
            </Typography>
          )}
          {suggestion!.suggestedDescription && (
            <Typography
              variant="caption"
              color="text.secondary"
              sx={{
                display: '-webkit-box',
                WebkitLineClamp: 2,
                WebkitBoxOrient: 'vertical',
                overflow: 'hidden',
                mt: 0.5,
              }}
            >
              {suggestion!.suggestedDescription}
            </Typography>
          )}
          {suggestion!.suggestedTags.length > 0 && (
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5, mt: 0.75 }}>
              {suggestion!.suggestedTags.map((t) => (
                <Chip
                  key={t.label}
                  label={t.label}
                  size="small"
                  sx={{ bgcolor: highlighted ? 'grey.300' : 'grey.200', fontSize: '0.7rem', height: '1.4rem', border: 'none' }}
                />
              ))}
            </Box>
          )}

          <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mt: 1 }}>
            {highlighted ? (
              <Stack direction="row" alignItems="center" spacing={0.5}>
                <CheckIcon sx={{ fontSize: '0.875rem', color: 'success.main' }} />
                <Typography variant="caption" color="success.main" fontWeight={600}>
                  {mode === 'batch' ? 'Accepted' : 'Picked'}
                </Typography>
              </Stack>
            ) : <Box />}
            {actionButton}
          </Stack>
        </Box>
      )}
    </Box>
  )
}

// ─── ResourceNameHeader ───────────────────────────────────────────────────────

function ResourceNameHeader({ isWaiting }: { isWaiting: boolean }) {
  return (
    <Box sx={{ px: 3, pt: 2, pb: 1.75, bgcolor: 'primary.50' }}>
      <Typography variant="overline" color="primary.dark" sx={{ display: 'block', letterSpacing: '0.08em', lineHeight: 1.4 }}>
        Resource
      </Typography>
      {isWaiting && (
        <Typography variant="subtitle2" sx={{ mt: 0.25, color: 'text.disabled', fontStyle: 'italic' }}>
          Waiting for AI suggestions…
        </Typography>
      )}
    </Box>
  )
}

// ─── AiTyStep ────────────────────────────────────────────────────────────────

interface Props {
  mode: WizardMode
  records: WizardResourceRecord[]
  aityStatuses: Record<string, AityPollEntry>
  onAcceptSuggestions: (localId: string, selectedFileSuggestion?: FileSuggestion) => void
  onRejectSuggestions: (localId: string) => void
  onAcceptAll: () => void
  onRejectAll: () => void
  onRetryFile: (localId: string, fileId: string) => Promise<void>
}

/**
 * Step 2 — unified file analysis view for all 3 wizard modes.
 *
 * - Each file gets its own FileAnalysisCard with inline suggestion + Retry.
 * - Batch: "Accept" / "Accept All" per file / globally.
 * - Components / Canonical: "Pick" selects a file's suggestion as the resource
 *   name + description; a header shows the currently picked result.
 */
export function AiTyStep({
  mode,
  records,
  aityStatuses,
  onAcceptSuggestions,
  onRejectSuggestions,
  onAcceptAll,
  onRejectAll,
  onRetryFile,
}: Props) {
  const anyUploading  = records.some((r) => ['pending', 'creating', 'uploading'].includes(r.uploadStatus))
  const readyCount    = records.filter((r) => {
    const fid = r.uploadedFileIds?.[0]
    return fid && aityStatuses[fid]?.status === 'ready'
  }).length
  const acceptedCount = records.filter((r) => r.suggestionsAccepted).length

  // Single-resource state (components / canonical)
  const singleRecord  = records[0]
  const singleFileIds = singleRecord?.uploadedFileIds ?? []
  const isWaiting     = singleFileIds.length === 0 ||
                        singleFileIds.some((fid) => aityStatuses[fid]?.status === 'waiting')

  // ── Build per-file card data ───────────────────────────────────────────────
  type FileCardData = {
    key: string
    localId: string
    file: File
    fileId?: string
    role?: string
    resourceId?: string
    uploadStatus: WizardResourceRecord['uploadStatus']
    pollEntry: AityPollEntry | null
    suggestion: FileSuggestion | null
    isAccepted?: boolean
    isPicked?: boolean
  }

  let fileCards: FileCardData[]

  if (mode === 'batch') {
    fileCards = records.map((rec) => {
      const fid  = rec.uploadedFileIds?.[0]
      const poll = fid ? aityStatuses[fid] ?? null : null
      const suggestion: FileSuggestion | null = poll?.suggestions
        ? { filename: rec.file.name, suggestedName: poll.suggestions.suggestedName, suggestedDescription: poll.suggestions.suggestedDescription, suggestedTags: poll.suggestions.suggestedTags }
        : null
      return {
        key: rec.localId,
        localId: rec.localId,
        file: rec.file,
        fileId: fid,
        resourceId: rec.resourceId,
        uploadStatus: rec.uploadStatus,
        pollEntry: poll,
        suggestion,
        isAccepted: rec.suggestionsAccepted ?? false,
      }
    })
  } else {
    // Single resource — one card per file in allFiles
    fileCards = (singleRecord?.allFiles ?? []).map((file, idx) => {
      const fileId     = singleRecord?.uploadedFileIds?.[idx]
      const poll       = fileId ? aityStatuses[fileId] ?? null : null
      const suggestion: FileSuggestion | null = poll?.suggestions
        ? { filename: file.name, suggestedName: poll.suggestions.suggestedName, suggestedDescription: poll.suggestions.suggestedDescription, suggestedTags: poll.suggestions.suggestedTags }
        : null
      const isPicked   = !!(singleRecord?.pickedSuggestionFilename &&
                            singleRecord.pickedSuggestionFilename === file.name)
      const role       = mode === 'canonical'
        ? (idx === 0 ? 'canonical' : 'supporting')
        : undefined  // component role not labeled — all files are peers
      return {
        key: `${singleRecord?.localId}-${idx}`,
        localId: singleRecord?.localId ?? '',
        file,
        fileId,
        role,
        resourceId: singleRecord?.resourceId,
        uploadStatus: singleRecord?.uploadStatus ?? 'pending',
        pollEntry: poll,
        suggestion,
        isPicked,
      }
    })
  }

  return (
    <Stack spacing={0} sx={{ flex: 1, overflow: 'hidden', display: 'flex', flexDirection: 'column' }}>

      {/* ── Batch summary header ──────────────────────────────────────────── */}
      {mode === 'batch' && (
        <Stack direction="row" alignItems="center" sx={{ px: 3, pt: 2, pb: 1 }}>
          <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
            {anyUploading
              ? `Uploading… (${readyCount} of ${records.length} analyzed)`
              : acceptedCount > 0
                ? `${acceptedCount} of ${readyCount} suggestions accepted`
                : `${readyCount} of ${records.length} files analyzed`}
          </Typography>
          {!anyUploading && readyCount > 0 && (
            <Stack direction="row" spacing={1}>
              <Button
                size="small"
                variant="outlined"
                color="inherit"
                onClick={onRejectAll}
                sx={{ fontSize: '0.75rem', py: 0.25, color: 'text.secondary' }}
              >
                Reject All
              </Button>
              <Button
                size="small"
                variant="contained"
                color="success"
                startIcon={<CheckIcon sx={{ fontSize: '0.875rem' }} />}
                onClick={onAcceptAll}
                sx={{ fontSize: '0.75rem', py: 0.25 }}
              >
                Accept All
              </Button>
            </Stack>
          )}
        </Stack>
      )}

      {/* ── Single-resource name header (components / canonical) ──────────── */}
      {mode !== 'batch' && singleRecord && (
        <ResourceNameHeader isWaiting={isWaiting} />
      )}

      {/* ── File cards (scrollable) ───────────────────────────────────────── */}
      <Box sx={{ flex: 1, overflowY: 'auto', px: 3, pt: 2, pb: 1 }}>
        <Stack spacing={1}>
          {fileCards.map((card) => (
            <FileAnalysisCard
              key={card.key}
              file={card.file}
              role={card.role}
              uploadStatus={card.uploadStatus}
              pollEntry={card.pollEntry}
              suggestion={card.suggestion}
              isPicked={card.isPicked}
              isAccepted={card.isAccepted}
              mode={mode}
              onAccept={() => onAcceptSuggestions(card.localId, card.suggestion ?? undefined)}
              onPick={() => onAcceptSuggestions(card.localId, card.suggestion ?? undefined)}
              onReject={() => onRejectSuggestions(card.localId)}
              onRetry={card.fileId
                ? () => onRetryFile(card.localId, card.fileId!)
                : undefined}
            />
          ))}
        </Stack>
      </Box>

    </Stack>
  )
}
