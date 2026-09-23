import { useState } from 'react'
import { Box, Typography, SxProps, Theme } from '@mui/material'
import { useMediaSurface, MEDIA_SURFACE_BORDER, MEDIA_WELL_INSET_SHADOW } from '../../contexts/ThemeContext'

export interface AssetPreviewProps {
  /** Resolved preview URL. Empty/undefined renders the placeholder. */
  src?: string
  alt: string
  /** Aspect ratio of the well itself, as width/height. Defaults to 16:9. */
  wellAspect?: number
  /** Rendered inside the well, above the asset (basket tick, badges). */
  children?: React.ReactNode
  sx?: SxProps<Theme>
}

/**
 * The surface an asset is displayed against, wherever it appears in a grid or
 * list. Two things it gets right that a plain `<img objectFit="contain">` does
 * not:
 *
 * 1. The well is a *recess* — neutral fill plus an inset shadow — so it reads
 *    as a viewing area carved into the card rather than an unthemed patch. It
 *    is also theme-neutral on purpose: every palette tints its grey ramp toward
 *    its own hue, and a tinted surround shifts the perceived colour of the
 *    asset in front of it. Chrome is tinted; content is neutral.
 *
 * 2. The transparency backdrop paints the asset's own box and nothing of the
 *    letterbox bars. Painted across the whole well, the checkerboard would show
 *    around every *opaque* photo too — a screenful of noise announcing
 *    transparency that isn't there. Getting that right needs the asset's
 *    rendered rect, which `object-fit: contain` computes internally and does
 *    not expose to CSS; hence the natural-dimension read on load, which also
 *    preserves contain's upscaling of assets smaller than the well.
 */
function AssetPreview(props: AssetPreviewProps) {
  const { src, alt, wellAspect = 16 / 9, children, sx } = props

  const mediaSurfaceSx = useMediaSurface()
  const [error, setError] = useState(false)
  const [aspect, setAspect] = useState<number | null>(null)

  const showAsset = !error && !!src

  // Which edge the asset touches once contained. Until the natural dimensions
  // are known the box stays unsized, so nothing flashes at the wrong scale.
  const fill =
    aspect === null
      ? { width: 0, height: 0 }
      : aspect >= wellAspect
        ? { width: '100%', height: 'auto' }
        : { width: 'auto', height: '100%' }

  return (
    <Box
      sx={{
        position: 'relative',
        width: '100%',
        aspectRatio: String(wellAspect),
        borderRadius: 1,
        overflow: 'hidden',
        outline: `1px solid ${MEDIA_SURFACE_BORDER}`,
        boxShadow: MEDIA_WELL_INSET_SHADOW,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        ...mediaSurfaceSx.well,
        ...sx,
      }}
    >
      {showAsset ? (
        <Box
          component="img"
          src={src}
          alt={alt}
          onLoad={(e: React.SyntheticEvent<HTMLImageElement>) => {
            const img = e.currentTarget
            if (img.naturalHeight > 0) setAspect(img.naturalWidth / img.naturalHeight)
          }}
          onError={() => setError(true)}
          sx={{
            display: 'block',
            maxWidth: '100%',
            maxHeight: '100%',
            ...fill,
            ...mediaSurfaceSx.image,
          }}
        />
      ) : (
        <Typography sx={{ fontSize: '0.75rem', color: 'text.disabled' }}>
          No preview
        </Typography>
      )}
      {children}
    </Box>
  )
}

export default AssetPreview
