import { useCallback, useRef, useState } from 'react'
import {
  Alert, Box, Card, CardContent, Chip, IconButton, List, ListItem,
  ListItemIcon, ListItemText, Stack, Tooltip, Typography,
} from '@mui/material'
import CloudUploadIcon from '@mui/icons-material/CloudUpload'
import DynamicFeedIcon from '@mui/icons-material/DynamicFeed'
import LayersIcon from '@mui/icons-material/Layers'
import StarIcon from '@mui/icons-material/Star'
import AudiotrackIcon from '@mui/icons-material/Audiotrack'
import ArticleIcon from '@mui/icons-material/Article'
import ImageIcon from '@mui/icons-material/Image'
import OndemandVideoIcon from '@mui/icons-material/OndemandVideo'
import CloseIcon from '@mui/icons-material/Close'

export type WizardMode = 'batch' | 'components' | 'canonical'

export interface WizardFileEntry {
  localId: string
  file: File
  role: 'canonical' | 'component' | 'supporting'
}

interface ModeCard {
  mode: WizardMode
  icon: React.ReactNode
  title: string
  description: string
}

const MODE_CARDS: ModeCard[] = [
  {
    mode: 'batch',
    icon: <DynamicFeedIcon sx={{ fontSize: 32, color: 'primary.main' }} />,
    title: 'Batch',
    description: 'Each file becomes a separate resource',
  },
  {
    mode: 'components',
    icon: <LayersIcon sx={{ fontSize: 32, color: 'primary.main' }} />,
    title: 'Multi-component',
    description: 'All files form one resource (equal importance)',
  },
  {
    mode: 'canonical',
    icon: <StarIcon sx={{ fontSize: 32, color: 'primary.main' }} />,
    title: 'Canonical',
    description: 'One primary file + optional supporting files',
  },
]

interface Props {
  mode: WizardMode | null
  files: WizardFileEntry[]
  collectionMimeTypes: string[]
  onModeChange: (m: WizardMode) => void
  onFilesChange: (files: WizardFileEntry[]) => void
}

/** Returns true if `mime` matches any of the accepted MIME types (supports wildcards like `image/*`). */
function mimeMatches(mime: string, accepted: string[]): boolean {
  if (accepted.length === 0) return true
  return accepted.some((a) => {
    if (a === mime) return true
    if (a.endsWith('/*') && mime.startsWith(a.slice(0, -1))) return true
    return false
  })
}

/**
 * Step 1 — Mode selector + adaptive file dropzone.
 */
export function ModeUploadStep({ mode, files, collectionMimeTypes, onModeChange, onFilesChange }: Props) {
  const primaryInputRef   = useRef<HTMLInputElement>(null)
  const supportingInputRef = useRef<HTMLInputElement>(null)
  const multiInputRef     = useRef<HTMLInputElement>(null)
  const [dragOver, setDragOver] = useState<'primary' | 'supporting' | 'multi' | null>(null)
  const [rejectedByZone, setRejectedByZone] = useState<Partial<Record<'primary' | 'supporting' | 'multi', string[]>>>({})

  const accept = collectionMimeTypes.length > 0 ? collectionMimeTypes.join(',') : undefined

  // ── file helpers ─────────────────────────────────────────────────────────────

  const makeEntries = (fileList: FileList | File[], role: WizardFileEntry['role']): WizardFileEntry[] =>
    Array.from(fileList).map((file) => ({
      localId: crypto.randomUUID(),
      file,
      role,
    }))

  const addFiles = useCallback(
    (incoming: WizardFileEntry[], zone: 'primary' | 'supporting' | 'multi') => {
      if (collectionMimeTypes.length > 0) {
        const rejected = incoming.filter((e) => !mimeMatches(e.file.type, collectionMimeTypes))
        if (rejected.length > 0) {
          setRejectedByZone((prev) => ({
            ...prev,
            [zone]: [...(prev[zone] ?? []), ...rejected.map((e) => e.file.name)],
          }))
        }
        incoming = incoming.filter((e) => mimeMatches(e.file.type, collectionMimeTypes))
      }
      if (incoming.length > 0) {
        onFilesChange([...files, ...incoming])
      }
    },
    [files, collectionMimeTypes, onFilesChange]
  )

  const removeFile = useCallback(
    (localId: string) => {
      onFilesChange(files.filter((f) => f.localId !== localId))
    },
    [files, onFilesChange]
  )

  // ── drop handlers ────────────────────────────────────────────────────────────

  const handleDrop = useCallback(
    (e: React.DragEvent, role: WizardFileEntry['role'], zone: 'primary' | 'supporting' | 'multi') => {
      e.preventDefault()
      setDragOver(null)
      const dropped = makeEntries(e.dataTransfer.files, role)
      if (dropped.length) addFiles(dropped, zone)
    },
    [addFiles]
  )

  // ── rendered dropzone ────────────────────────────────────────────────────────

  const Dropzone = ({
    zone,
    role,
    label,
    multiple,
    inputRef,
    compact = false,
  }: {
    zone: 'primary' | 'supporting' | 'multi'
    role: WizardFileEntry['role']
    label: string
    multiple: boolean
    inputRef: React.RefObject<HTMLInputElement>
    compact?: boolean
  }) => (
    <Box
      onDrop={(e) => handleDrop(e, role, zone)}
      onDragOver={(e) => { e.preventDefault(); setDragOver(zone) }}
      onDragLeave={() => setDragOver(null)}
      onClick={() => inputRef.current?.click()}
      sx={{
        border: '2px dashed',
        borderColor: dragOver === zone ? 'primary.main' : 'divider',
        borderRadius: 2,
        p: compact ? 1.25 : 3,
        display: 'flex',
        flexDirection: compact ? 'row' : 'column',
        alignItems: 'center',
        gap: compact ? 1.25 : 1,
        cursor: 'pointer',
        bgcolor: dragOver === zone ? 'primary.50' : 'transparent',
        transition: 'all 0.15s',
        '&:hover': { bgcolor: 'action.hover', borderColor: 'primary.light' },
      }}
    >
      <CloudUploadIcon sx={{ fontSize: compact ? 20 : 32, color: 'text.disabled', flexShrink: 0 }} />
      <Box>
        <Typography variant={compact ? 'caption' : 'body2'} color="text.secondary">{label}</Typography>
        {collectionMimeTypes.length > 0 && (
          <Typography variant="caption" color="text.disabled" sx={{ display: 'block' }}>
            {collectionMimeTypes.join(', ')}
          </Typography>
        )}
      </Box>
      <input
        ref={inputRef}
        type="file"
        style={{ display: 'none' }}
        accept={accept}
        multiple={multiple}
        onChange={(e) => {
          if (e.target.files?.length) {
            addFiles(makeEntries(e.target.files, role), zone)
            e.target.value = ''
          }
        }}
      />
    </Box>
  )

  // ── file list ─────────────────────────────────────────────────────────────────

  const primaryFiles    = files.filter((f) => f.role === 'canonical')
  const supportingFiles = files.filter((f) => f.role === 'supporting')
  const allFiles        = files

  function fileIcon(mime: string) {
    const sx = { fontSize: 18, color: 'text.secondary' }
    if (mime.startsWith('image/'))  return <ImageIcon sx={sx} />
    if (mime.startsWith('audio/'))  return <AudiotrackIcon sx={sx} />
    if (mime.startsWith('video/'))  return <OndemandVideoIcon sx={sx} />
    if (mime === 'application/pdf') return <ArticleIcon sx={sx} />
    return <ArticleIcon sx={sx} />
  }

  function formatBytes(bytes: number) {
    if (bytes < 1024) return `${bytes} B`
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  const FileList = ({ entries, zone, label }: { entries: WizardFileEntry[]; zone: 'primary' | 'supporting' | 'multi'; label?: string }) => {
    const zoneRejected = rejectedByZone[zone] ?? []
    if (entries.length === 0 && zoneRejected.length === 0) return null
    return (
      <Box sx={{ mt: 1.5 }}>
        {entries.length > 0 && (
          <Box
            sx={{
              border: '1px solid',
              borderColor: 'divider',
              borderRadius: 1.5,
              overflow: 'hidden',
            }}
          >
            <Stack
              direction="row"
              alignItems="center"
              sx={{ px: 1.5, py: 0.75, bgcolor: 'action.hover', borderBottom: '1px solid', borderColor: 'divider' }}
            >
              <Typography variant="caption" fontWeight={700} color="text.secondary" sx={{ flex: 1 }}>
                {label ?? 'Files'} · {entries.length}
              </Typography>
            </Stack>
            <List dense disablePadding>
              {entries.map((e, i) => (
                <ListItem
                  key={e.localId}
                  divider={i < entries.length - 1}
                  sx={{ py: 0.25, pl: 1.5 }}
                  secondaryAction={
                    <Tooltip title="Remove">
                      <IconButton
                        edge="end"
                        size="small"
                        onClick={() => removeFile(e.localId)}
                        sx={{ color: 'text.disabled', '&:hover': { color: 'error.main' } }}
                      >
                        <CloseIcon sx={{ fontSize: 14 }} />
                      </IconButton>
                    </Tooltip>
                  }
                >
                  <ListItemIcon sx={{ minWidth: 28 }}>
                    {fileIcon(e.file.type)}
                  </ListItemIcon>
                  <ListItemText
                    primary={
                      <Tooltip title={e.file.name} placement="top-start">
                        <Stack direction="row" alignItems="baseline" gap={1} sx={{ minWidth: 0 }}>
                          <Typography variant="body2" noWrap sx={{ fontSize: '0.8rem', minWidth: 0, flex: 1 }}>
                            {e.file.name}
                          </Typography>
                          <Typography variant="caption" color="text.disabled" sx={{ flexShrink: 0 }}>
                            {formatBytes(e.file.size)}
                          </Typography>
                        </Stack>
                      </Tooltip>
                    }
                  />
                </ListItem>
              ))}
            </List>
          </Box>
        )}
        {zoneRejected.length > 0 && (
          <Alert
            severity="error"
            sx={{ mt: 1 }}
            onClose={() => setRejectedByZone((prev) => ({ ...prev, [zone]: [] }))}
          >
            <Typography variant="caption" sx={{ display: 'block', fontWeight: 600, mb: 0.25 }}>
              File type not allowed in this collection:
            </Typography>
            {zoneRejected.map((name) => (
              <Typography key={name} variant="caption" sx={{ display: 'block', ml: 1 }}>
                · {name}
              </Typography>
            ))}
          </Alert>
        )}
      </Box>
    )
  }

  return (
    <Stack spacing={3} sx={{ p: 3, overflowY: 'auto', flex: 1 }}>
      {/* ── Mode picker ─────────────────────────────────────────────────────── */}
      <Box>
        <Typography variant="subtitle2" gutterBottom>
          Choose upload mode
        </Typography>
        <Stack direction="row" spacing={1.5}>
          {MODE_CARDS.map(({ mode: m, icon, title, description }) => (
            <Card
              key={m}
              onClick={() => {
                onModeChange(m)
                onFilesChange([])
                setRejectedByZone({})
              }}
              elevation={mode === m ? 2 : 0}
              sx={{
                flex: 1,
                cursor: 'pointer',
                border: '2px solid',
                borderColor: mode === m ? 'primary.main' : 'divider',
                borderRadius: 2,
                transition: 'all 0.15s',
                '&:hover': { borderColor: 'primary.light' },
              }}
            >
              <CardContent sx={{ p: 2, '&:last-child': { pb: 2 } }}>
                <Stack alignItems="center" spacing={1} textAlign="center">
                  {icon}
                  <Typography variant="subtitle2" fontWeight={600}>
                    {title}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    {description}
                  </Typography>
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Stack>
      </Box>

      {/* ── Dropzone (visible only after mode selected) ──────────────────────── */}
      {mode !== null && (
        <Box>
          <Typography variant="subtitle2" gutterBottom>
            {mode === 'canonical' ? 'Upload files' : 'Select files'}
          </Typography>

          {mode === 'canonical' ? (
            /* Two-zone layout for canonical mode */
            <Stack spacing={1.5}>
              <Box>
                <Typography variant="caption" color="text.secondary" sx={{ mb: 0.5, display: 'block' }}>
                  Primary file ★ <span style={{ color: 'red' }}>*</span>
                </Typography>
                <Dropzone
                  zone="primary"
                  role="canonical"
                  label={primaryFiles.length === 0 ? 'Drop primary file here, or click to browse' : 'Replace primary file'}
                  multiple={false}
                  inputRef={primaryInputRef}
                  compact
                />
                <FileList entries={primaryFiles} zone="primary" label="Primary file" />
              </Box>

              <Box>
                <Typography variant="caption" color="text.secondary" sx={{ mb: 0.5, display: 'block' }}>
                  Supporting files (optional)
                </Typography>
                <Dropzone
                  zone="supporting"
                  role="supporting"
                  label="Drop supporting files here, or click to browse"
                  multiple
                  inputRef={supportingInputRef}
                  compact
                />
                <FileList entries={supportingFiles} zone="supporting" label="Supporting files" />
              </Box>
            </Stack>
          ) : (
            /* Single multi-file dropzone for batch / components */
            <Box>
              <Dropzone
                zone="multi"
                role={mode === 'batch' ? 'canonical' : 'component'}
                label="Drop files here, or click to browse"
                multiple
                inputRef={multiInputRef}
              />
              <FileList entries={allFiles} zone="multi" label="Selected files" />
              {mode === 'components' && allFiles.length > 10 && (
                <Chip
                  color="warning"
                  size="small"
                  label={`Warning: ${allFiles.length} files may slow processing`}
                  sx={{ mt: 1 }}
                />
              )}
            </Box>
          )}
        </Box>
      )}
    </Stack>
  )
}
