import { Box, Typography } from '@mui/material'
import InsertDriveFileOutlinedIcon from '@mui/icons-material/InsertDriveFileOutlined'
import { ResourceFile } from '../../api/resourceService'
import { ImageViewer } from './ImageViewer'
import { PdfViewer } from './PdfViewer'
import { AudioPlayer } from './AudioPlayer'

interface Props {
  file: ResourceFile
  /** Direct URL to the file (from file.url). */
  src: string
  /** Optional cover art URL for audio files. */
  coverArt?: string | null
  maxHeight?: number | string
}

/**
 * Routes to the correct viewer based on the file's MIME type.
 *
 * Supported:
 *   image/*       → ImageViewer (zoomable)
 *   application/pdf → PdfViewer (iframe)
 *   audio/*       → AudioPlayer (HTML5 + transcript panel)
 *
 * Everything else shows a generic "no preview" placeholder with a download link.
 */
export function FileViewer({ file, src, coverArt, maxHeight = 520 }: Props) {
  const mime = file.mime_type ?? ''

  if (mime.startsWith('image/')) {
    return <ImageViewer src={src} alt={file.filename} maxHeight={maxHeight} />
  }

  if (mime === 'application/pdf') {
    return <PdfViewer src={src} filename={file.filename} height={maxHeight} />
  }

  if (mime.startsWith('audio/')) {
    return (
      <AudioPlayer
        src={src}
        filename={file.filename}
        coverArt={coverArt}
        // transcript: passed in once Whisper integration lands
      />
    )
  }

  // Generic fallback — no inline preview available
  return (
    <Box
      sx={{
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        height: 160,
        gap: 1,
        color: 'text.disabled',
        border: '1px dashed',
        borderColor: 'divider',
        borderRadius: 1,
      }}
    >
      <InsertDriveFileOutlinedIcon sx={{ fontSize: 40 }} />
      <Typography variant="caption">{file.filename}</Typography>
      <Typography variant="caption" color="text.disabled">
        No inline preview for {mime || 'this file type'}
      </Typography>
    </Box>
  )
}
