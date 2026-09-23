import { Box, Link, Typography } from '@mui/material'
import OpenInNewIcon from '@mui/icons-material/OpenInNew'

interface Props {
  src: string
  filename?: string
  height?: number | string
}

/**
 * Inline PDF viewer using native browser iframe rendering.
 * Falls back to a download link on browsers that don't support inline PDFs
 * (e.g. mobile Safari without a PDF plugin).
 *
 * Upgrade path: replace the <iframe> with <Document> from react-pdf
 * when page-by-page navigation or annotation overlays are needed.
 */
export function PdfViewer({ src, filename, height = 560 }: Props) {
  return (
    <Box sx={{ width: '100%', height, display: 'flex', flexDirection: 'column' }}>
      <Box sx={{ display: 'flex', justifyContent: 'flex-end', mb: 0.5, flexShrink: 0 }}>
        <Link
          href={src}
          target="_blank"
          rel="noopener noreferrer"
          underline="hover"
          sx={{ display: 'flex', alignItems: 'center', gap: 0.25, fontSize: '0.75rem', color: 'text.secondary' }}
        >
          <OpenInNewIcon sx={{ fontSize: 14 }} />
          Open in new tab
        </Link>
      </Box>

      <Box
        component="iframe"
        src={src}
        title={filename ?? 'PDF preview'}
        sx={{
          width: '100%',
          flex: 1,
          minHeight: 0,
          border: '1px solid',
          borderColor: 'divider',
          borderRadius: 1,
          display: 'block',
        }}
      />

      {/* Fallback text shown when iframe PDF is blocked */}
      <noscript>
        <Typography variant="caption" color="text.secondary">
          Your browser cannot display the PDF inline.{' '}
          <Link href={src} download={filename}>
            Download it instead.
          </Link>
        </Typography>
      </noscript>
    </Box>
  )
}
