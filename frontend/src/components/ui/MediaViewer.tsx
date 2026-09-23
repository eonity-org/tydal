import { useState, useEffect } from 'react'
import {
  Box,
  Stack,
  IconButton,
  Typography,
} from '@mui/material'
import { ChevronLeft, ChevronRight, Star, StarBorder, CloudUpload, Hub, HubOutlined } from '@mui/icons-material'
import { PdfViewer } from '../file-viewer/PdfViewer'
import { AudioPlayer } from '../file-viewer/AudioPlayer'
import { useMediaSurface, MEDIA_SURFACE_DARK } from '../../contexts/ThemeContext'

import { API_BASE_URL } from '../../constants/api'

export interface MediaFile {
  id: string
  filename?: string
  original_name?: string // For backward compatibility
  name?: string // For backward compatibility
  mime_type: string
  dam_url?: string // Legacy field
  url?: string // New field
  resource_id?: string
  preview_url?: string
  isTemporary?: boolean
  /** Real server file id for a session upload still shown from its local blob — used
   *  as the snapshot target since the snapshot needs a real id, not the "new-{index}". */
  snapshotId?: string
}

export interface MediaViewerProps {
  /**
   * Array of media files to display
   */
  files: MediaFile[]
  /**
   * Initial file index to display
   */
  defaultIndex?: number
  /**
   * Show navigation controls (default: true)
   */
  showControls?: boolean
  /**
   * Height of the viewer in pixels (default: 400)
   */
  height?: number | string
  /**
   * Background color (default: grey.800)
   */
  bgcolor?: string
  /**
   * Additional sx styles
   */
  sx?: any
  /**
   * Callback when file index changes
   */
  onIndexChange?: (index: number) => void
  /**
   * Whether the viewer is in edit mode (shows set-preview button)
   */
  editMode?: boolean
  /**
   * Whether the currently displayed file is the preview
   */
  isPreview?: boolean
  /**
   * Callback to set the current file as the resource preview
   */
  onSetPreview?: (fileId: string) => void
  /**
   * When provided, the viewer becomes a drop target: dropped files are forwarded
   * here. The parent routes them through EditableFiles' validated upload path.
   * Omitted (undefined) → no drop affordance (e.g. read-only viewers).
   */
  onDropFiles?: (files: File[]) => void
  /**
   * MIME types accepted by the collection — shown as a hint in the empty-state
   * dropzone caption. Purely cosmetic; gating happens in EditableFiles.
   */
  acceptHint?: string[]
  /** Whether the currently displayed file is the resource's canonical (primary) file. */
  isCanonical?: boolean
  /**
   * Toggle the current file's canonical role (edit mode). Clicking a non-canonical
   * file makes it canonical; clicking the current canonical clears it (→ multi-component).
   * When omitted, the canonical control is hidden.
   */
  onToggleCanonical?: (fileId: string) => void
}

/**
 * TYDAL MediaViewer Component
 *
 * A media viewer component that displays images/videos with navigation controls.
 * Supports multiple files with previous/next navigation.
 *
 * @example
 * ```tsx
 * <MediaViewer
 *   files={[
 *     {
 *       id: "123",
 *       original_name: "image1.jpg",
 *       mime_type: "image/jpeg",
 *       dam_url: "@@@dam:@image@uuid@@@"
 *     }
 *   ]}
 *   defaultIndex={0}
 *   height={500}
 * />
 * ```
 */
function MediaViewer(props: MediaViewerProps) {
  // bgcolor defaults to the neutral lightbox rather than the theme's `grey.800`:
  // every palette tints its grey ramp toward its own hue, and a tinted surround
  // shifts the perceived colour of the asset in front of it. Chrome is tinted;
  // content is neutral.
  const { files, defaultIndex = 0, showControls = true, height = 400, bgcolor = MEDIA_SURFACE_DARK, sx, onIndexChange, editMode = false, isPreview = false, onSetPreview, onDropFiles, acceptHint, isCanonical = false, onToggleCanonical } = props

  const mediaSurfaceSx = useMediaSurface()
  const [selectedIndex, setSelectedIndex] = useState(defaultIndex)
  const [dragActive, setDragActive] = useState(false)

  // Sync internal state when defaultIndex prop changes
  useEffect(() => {
    setSelectedIndex(defaultIndex)
  }, [defaultIndex])

  const handleIndexChange = (newIndex: number) => {
    setSelectedIndex(newIndex)
    onIndexChange?.(newIndex)
  }

  // Drag-and-drop wiring — only active when the parent supplied onDropFiles.
  // Spread onto the outer Box of every render branch so dropping anywhere on the
  // pane (empty or populated) routes through the parent's validated upload path.
  const dropEnabled = !!onDropFiles
  const dropHandlers = dropEnabled
    ? {
        onDragEnter: (e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); setDragActive(true) },
        onDragOver:  (e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); setDragActive(true) },
        onDragLeave: (e: React.DragEvent) => { e.preventDefault(); e.stopPropagation(); setDragActive(false) },
        onDrop: (e: React.DragEvent) => {
          e.preventDefault(); e.stopPropagation(); setDragActive(false)
          const dropped = Array.from(e.dataTransfer.files)
          if (dropped.length > 0) onDropFiles!(dropped)
        },
      }
    : {}

  // Overlay shown while dragging over the pane (both empty and populated states).
  const dragOverlay = dropEnabled && dragActive ? (
    <Box
      sx={{
        position: 'absolute', inset: 0, zIndex: 30,
        display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 1,
        bgcolor: 'rgba(20,20,20,0.78)', border: '3px dashed', borderColor: 'primary.light', borderRadius: 2,
        pointerEvents: 'none',
      }}
    >
      <CloudUpload sx={{ fontSize: '3rem', color: 'primary.light' }} />
      <Typography variant="body1" sx={{ color: 'common.white', fontWeight: 600 }}>Drop to add files</Typography>
    </Box>
  ) : null

  if (!files || files.length === 0) {
    return (
      <Box
        {...dropHandlers}
        sx={{
          position: 'relative',
          height,
          bgcolor,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
          justifyContent: 'center',
          gap: 1.5,
          borderRadius: 2,
          ...(dropEnabled ? { border: '2px dashed', borderColor: dragActive ? 'primary.light' : 'rgba(255,255,255,0.18)', cursor: 'default', transition: 'border-color 0.2s' } : {}),
          ...sx,
        }}
      >
        {dropEnabled ? (
          <>
            <CloudUpload sx={{ fontSize: '3rem', color: dragActive ? 'primary.light' : 'rgba(255,255,255,0.4)' }} />
            <Typography variant="body1" sx={{ color: 'rgba(255,255,255,0.85)', fontWeight: 600 }}>
              {dragActive ? 'Drop to add files' : 'Drag files here to start'}
            </Typography>
            {/* Communicates the Option-A role default so the structure isn't a surprise. */}
            <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.55)', textAlign: 'center', px: 3, maxWidth: 360 }}>
              One file becomes the main asset · several at once become a multi-part resource
            </Typography>
            {acceptHint && acceptHint.length > 0 && (
              <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.4)', textAlign: 'center', px: 2 }}>
                {acceptHint.join(', ')}
              </Typography>
            )}
          </>
        ) : (
          <Typography variant="body2" color="text.secondary">
            No media files to display
          </Typography>
        )}
      </Box>
    )
  }

  // Clamp the index: when files shrinks (e.g., parent clears newFiles on close
  // or save), selectedIndex may still point past the end. Falling through to
  // currentFile = undefined then crashes at the first non-optional read.
  const safeIndex   = Math.min(Math.max(selectedIndex, 0), files.length - 1)
  const currentFile = files[safeIndex]
  if (!currentFile) {
    return (
      <Box
        sx={{
          height,
          bgcolor,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          borderRadius: 2,
          ...sx,
        }}
      >
        <Typography variant="body2" color="text.secondary">
          No media files to display
        </Typography>
      </Box>
    )
  }
  const hasMultipleFiles = files.length > 1

  // Get preview URL
  const getPreviewUrl = (): string => {
    // Get the URL from either new 'url' field or legacy 'dam_url' field
    const fileUrl = currentFile?.url || currentFile?.dam_url

    // If it's a blob URL (for newly uploaded files), use it directly
    if (fileUrl?.startsWith('blob:')) return fileUrl

    // If it's a relative URL starting with /storage/, construct full URL using API base
    if (fileUrl?.startsWith('/storage/')) {
      // Extract the API base URL (remove /api/v1 suffix if present)
      const apiBase = API_BASE_URL.replace(/\/api\/v1$/, '')
      return `${apiBase}${fileUrl}`
    }

    // If it's a regular URL (http/https), use it directly
    if (fileUrl?.startsWith('http://') || fileUrl?.startsWith('https://')) {
      return fileUrl
    }

    // If it's a preview_url, use it
    if (currentFile?.preview_url) return currentFile.preview_url

    // Otherwise, use the TYDAL render endpoint with appropriate variant
    // For full-size viewer in modal, use 'medium' variant (1280x720) for good quality
    if (fileUrl) {
      // Check if the URL already has a variant suffix
      const hasVariant = /\/(thumbnail|small|medium|large|raw|default)$/.test(fileUrl)
      if (hasVariant) {
        // Already has a variant, use as-is
        return `${API_BASE_URL}/resource/render/${fileUrl}`
      } else {
        // Add 'medium' variant for better quality in modal viewer
        return `${API_BASE_URL}/resource/render/${fileUrl}/medium`
      }
    }

    return ''
  }

  const previewUrl = getPreviewUrl()
  const mime = currentFile?.mime_type ?? ''
  const isImage = mime.startsWith('image')
  const isVideo = mime.startsWith('video')
  const isPdf = mime === 'application/pdf'
  const isAudio = mime.startsWith('audio')

  // Debug logging
  console.log('📺 MediaViewer rendering:', {
    selectedIndex,
    totalFiles: files.length,
    currentFile: currentFile ? {
      id: currentFile.id,
      filename: currentFile.filename,
      name: currentFile.original_name || currentFile.name || currentFile.filename || 'Unnamed file',
      mime_type: currentFile.mime_type,
      hasUrl: !!currentFile.url,
      hasDamUrl: !!currentFile.dam_url,
      urlPreview: (currentFile.url || currentFile.dam_url)?.substring(0, 80) + '...',
    } : null,
    previewUrl: previewUrl ? previewUrl.substring(0, 80) + '...' : 'empty',
    isImage,
    isVideo,
    isPdf,
    isAudio,
  })

  return (
    <Box
      {...dropHandlers}
      sx={{
        height,
        bgcolor,
        borderRadius: 2,
        overflow: 'hidden',
        position: 'relative',
        display: 'flex',
        flexDirection: 'column',
        ...sx,
      }}
    >
      {dragOverlay}
      {/* Header area - File info and controls */}
      <Box
        sx={{
          position: 'absolute',
          top: 0,
          left: 0,
          right: 0,
          zIndex: 10,
          height: 48,
          px: 2,
          borderBottom: '1px solid',
          borderColor: 'rgba(255,255,255,0.1)',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          bgcolor: 'rgba(0,0,0,0.6)',
          backdropFilter: 'blur(4px)',
        }}
      >
        {/* Left: canonical pin + snapshot star (fixed width so center block stays centered) */}
        <Box sx={{ width: 76, display: 'flex', alignItems: 'center', justifyContent: 'flex-start', gap: 0.25, flexShrink: 0 }}>
          {editMode && onToggleCanonical && (
            <IconButton
              size="small"
              onClick={() => onToggleCanonical(currentFile.id)}
              title={isCanonical
                ? 'Canonical (primary) file — click to make it a component'
                : 'Set as canonical (primary) file'}
              sx={{
                color: isCanonical ? 'primary.light' : 'rgba(255,255,255,0.25)',
                '&:hover': { color: 'primary.light', bgcolor: 'rgba(255,255,255,0.1)' },
              }}
            >
              {isCanonical ? <Hub fontSize="small" /> : <HubOutlined fontSize="small" />}
            </IconButton>
          )}
          {(!currentFile.isTemporary || currentFile.snapshotId) && (
            <IconButton
              size="small"
              onClick={editMode ? () => onSetPreview?.(currentFile.snapshotId ?? currentFile.id) : undefined}
              disableRipple={!editMode}
              title={isPreview ? 'Snapshot file' : 'Set as snapshot file'}
              sx={{
                color: isPreview ? 'warning.light' : 'rgba(255,255,255,0.25)',
                cursor: editMode ? 'pointer' : 'default',
                '&:hover': editMode ? { color: 'warning.light', bgcolor: 'rgba(255,255,255,0.1)' } : {},
              }}
            >
              {isPreview ? <Star /> : <StarBorder />}
            </IconButton>
          )}
        </Box>

        {/* Center: name + counter */}
        <Box sx={{ flex: 1, minWidth: 0, textAlign: 'center' }}>
          <Typography
            variant="body2"
            sx={{
              color: 'white',
              fontSize: '0.875rem',
              fontWeight: 500,
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap',
            }}
          >
            {currentFile?.filename || currentFile?.original_name || currentFile?.name || 'Unnamed file'}
          </Typography>
          {hasMultipleFiles && (
            <Typography
              variant="caption"
              sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.75rem' }}
            >
              File {selectedIndex + 1} of {files.length}
            </Typography>
          )}
        </Box>

        {hasMultipleFiles && showControls && (
          <Stack direction="row" spacing={0.5} ml={2}>
            <IconButton
              size="small"
              onClick={() => handleIndexChange(selectedIndex === 0 ? files.length - 1 : selectedIndex - 1)}
              disabled={false}
              sx={{
                bgcolor: 'rgba(255,255,255,0.1)',
                color: 'white',
                '&:hover': { bgcolor: 'rgba(255,255,255,0.2)' },
                '&:disabled': { color: 'rgba(255,255,255,0.3)', bgcolor: 'transparent' },
              }}
            >
              <ChevronLeft />
            </IconButton>
            <IconButton
              size="small"
              onClick={() => handleIndexChange(selectedIndex === files.length - 1 ? 0 : selectedIndex + 1)}
              disabled={false}
              sx={{
                bgcolor: 'rgba(255,255,255,0.1)',
                color: 'white',
                '&:hover': { bgcolor: 'rgba(255,255,255,0.2)' },
                '&:disabled': { color: 'rgba(255,255,255,0.3)', bgcolor: 'transparent' },
              }}
            >
              <ChevronRight />
            </IconButton>
          </Stack>
        )}
        {(!hasMultipleFiles || !showControls) && <Box sx={{ width: 76, flexShrink: 0 }} />}
      </Box>

      {/* Main preview area */}
      <Box
        sx={{
          position: 'absolute',
          top: 48,
          left: 0,
          right: 0,
          bottom: 0,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          p: isPdf || isAudio ? 1 : 3,
        }}
      >
        <Box
          sx={{
            width: isPdf || isAudio ? '100%' : undefined,
            height: isPdf ? '100%' : undefined,
            maxWidth: isPdf || isAudio ? undefined : '100%',
            maxHeight: isPdf || isAudio ? undefined : '100%',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            overflow: 'hidden',
          }}
        >
          {previewUrl && isImage && (
            <Box
              component="img"
              src={previewUrl}
              alt={currentFile.filename || currentFile.original_name || currentFile.name || 'file'}
              sx={{
                maxWidth: '100%',
                maxHeight: '100%',
                width: 'auto',
                height: 'auto',
                objectFit: 'contain',
                display: 'block',
                // Backdrop on the asset's own box, so it never bleeds into the
                // surround — same rule as the card well.
                ...mediaSurfaceSx.image,
              }}
            />
          )}

          {previewUrl && isVideo && (
            <Box
              component="video"
              src={previewUrl}
              controls
              sx={{
                maxWidth: '100%',
                maxHeight: '100%',
                width: 'auto',
                height: 'auto',
              }}
            />
          )}

          {previewUrl && isPdf && (
            <Box sx={{ width: '100%', height: '100%', bgcolor: 'background.paper', borderRadius: 1, overflow: 'hidden', p: 1 }}>
              <PdfViewer
                src={previewUrl}
                filename={currentFile.filename || currentFile.original_name || currentFile.name}
                height="100%"
              />
            </Box>
          )}

          {previewUrl && isAudio && (
            <Box sx={{ width: '100%', bgcolor: 'background.paper', borderRadius: 1, p: 2 }}>
              <AudioPlayer
                src={previewUrl}
                filename={currentFile.filename || currentFile.original_name || currentFile.name}
              />
            </Box>
          )}

          {!previewUrl && !isPdf && !isAudio && (
            <Typography variant="body2" color="text.secondary">
              No preview available
            </Typography>
          )}
        </Box>
      </Box>
    </Box>
  )
}

export default MediaViewer
