import { Box, Chip, Divider, Typography } from '@mui/material'
import MicIcon from '@mui/icons-material/Mic'
import GraphicEqIcon from '@mui/icons-material/GraphicEq'

interface TranscriptChunk {
  sequence: number
  page_number?: number | null
  content: string
}

interface Props {
  src: string
  filename?: string
  /** Optional cover art URL (from preview_file or ID3 album art once extraction is built). */
  coverArt?: string | null
  /** Optional transcript chunks from the TRANSCRIPTION SystemFile (future: Whisper). */
  transcript?: TranscriptChunk[]
}

/**
 * HTML5 audio player with optional transcript panel.
 *
 * The transcript panel is ready to receive Whisper output once the backend
 * transcription job is implemented. Until then it renders nothing.
 *
 * Upgrade path: replace <audio> with wavesurfer.js for waveform visualization.
 */
export function AudioPlayer({ src, filename, coverArt, transcript }: Props) {
  return (
    <Box sx={{ width: '100%' }}>
      {/* Cover art + player row */}
      <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', mb: 2 }}>
        {coverArt ? (
          <Box
            component="img"
            src={coverArt}
            alt="Cover art"
            sx={{ width: 80, height: 80, objectFit: 'cover', borderRadius: 1, flexShrink: 0 }}
          />
        ) : (
          <Box
            sx={{
              width: 80,
              height: 80,
              borderRadius: 1,
              bgcolor: 'action.hover',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              flexShrink: 0,
            }}
          >
            <GraphicEqIcon sx={{ color: 'text.disabled', fontSize: 36 }} />
          </Box>
        )}

        <Box sx={{ flex: 1 }}>
          {filename && (
            <Typography variant="body2" fontWeight={500} noWrap sx={{ mb: 0.75 }}>
              {filename}
            </Typography>
          )}
          <Box
            component="audio"
            src={src}
            controls
            sx={{ width: '100%', display: 'block' }}
          />
        </Box>
      </Box>

      {/* Transcript panel */}
      {transcript && transcript.length > 0 && (
        <Box>
          <Divider sx={{ mb: 1.5 }} />
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, mb: 1 }}>
            <MicIcon sx={{ fontSize: 14, color: 'text.secondary' }} />
            <Typography variant="caption" color="text.secondary" fontWeight={500}>
              Transcript
            </Typography>
            <Chip label={`${transcript.length} segments`} size="small" sx={{ fontSize: '0.65rem', height: 16 }} />
          </Box>
          <Box
            sx={{
              maxHeight: 200,
              overflowY: 'auto',
              fontSize: '0.8rem',
              lineHeight: 1.6,
              color: 'text.secondary',
              borderLeft: '2px solid',
              borderColor: 'divider',
              pl: 1.5,
            }}
          >
            {transcript.map((chunk) => (
              <Typography key={chunk.sequence} variant="body2" paragraph sx={{ mb: 0.5 }}>
                {chunk.content}
              </Typography>
            ))}
          </Box>
        </Box>
      )}

      {/* Transcript pending state */}
      {(!transcript || transcript.length === 0) && (
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, mt: 1 }}>
          <MicIcon sx={{ fontSize: 13, color: 'text.disabled' }} />
          <Typography variant="caption" color="text.disabled">
            Transcript not yet available
          </Typography>
        </Box>
      )}
    </Box>
  )
}
