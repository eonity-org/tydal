import { useState } from 'react'
import { Box, IconButton, Tooltip } from '@mui/material'
import ZoomInIcon from '@mui/icons-material/ZoomIn'
import ZoomOutIcon from '@mui/icons-material/ZoomOut'
import CropFreeIcon from '@mui/icons-material/CropFree'

interface Props {
  src: string
  alt?: string
  maxHeight?: number | string
}

/**
 * Simple zoomable image viewer.
 * Three zoom levels: fit → 100% → 150%. Cycles on + click; reset on icon.
 */
export function ImageViewer({ src, alt = '', maxHeight = 480 }: Props) {
  const SCALES = [1, 1.5, 2] as const
  const [scaleIdx, setScaleIdx] = useState(0)
  const scale = SCALES[scaleIdx]

  return (
    <Box sx={{ position: 'relative', width: '100%', overflow: 'auto', maxHeight }}>
      {/* Controls */}
      <Box
        sx={{
          position: 'sticky',
          top: 0,
          right: 0,
          zIndex: 1,
          display: 'flex',
          justifyContent: 'flex-end',
          gap: 0.5,
          p: 0.5,
          background: 'rgba(255,255,255,0.7)',
          backdropFilter: 'blur(4px)',
        }}
      >
        <Tooltip title="Zoom in">
          <span>
            <IconButton
              size="small"
              onClick={() => setScaleIdx((i) => Math.min(i + 1, SCALES.length - 1))}
              disabled={scaleIdx === SCALES.length - 1}
            >
              <ZoomInIcon fontSize="small" />
            </IconButton>
          </span>
        </Tooltip>
        <Tooltip title="Zoom out">
          <span>
            <IconButton
              size="small"
              onClick={() => setScaleIdx((i) => Math.max(i - 1, 0))}
              disabled={scaleIdx === 0}
            >
              <ZoomOutIcon fontSize="small" />
            </IconButton>
          </span>
        </Tooltip>
        <Tooltip title="Reset">
          <IconButton size="small" onClick={() => setScaleIdx(0)}>
            <CropFreeIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Box>

      <Box sx={{ display: 'flex', justifyContent: 'center', p: 1 }}>
        <img
          src={src}
          alt={alt}
          style={{
            maxWidth: '100%',
            transform: `scale(${scale})`,
            transformOrigin: 'top center',
            transition: 'transform 0.2s ease',
          }}
        />
      </Box>
    </Box>
  )
}
