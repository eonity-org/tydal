import { Box, Typography, SxProps, Theme } from '@mui/material'
import IconButton from './IconButton'

export interface FilterTagProps {
  /**
   * The label text to display
   */
  label: string
  /**
   * Click handler for removing the filter
   */
  onRemove: () => void
  /**
   * Additional sx styles
   */
  sx?: SxProps<Theme>
}

/**
 * TYDAL FilterTag Component
 *
 * A cartouche-style tag for displaying active filters with a remove button.
 * Used for showing selected facet filters that can be clicked to remove.
 *
 * @example
 * ```tsx
 * <FilterTag
 *   label="Images"
 *   onRemove={() => handleRemove('Images')}
 * />
 *
 * <FilterTag
 *   label="Technology"
 *   onRemove={() => handleRemove('Technology')}
 *   sx={{ bgcolor: 'secondary.50' }}
 * />
 * ```
 */
function FilterTag(props: FilterTagProps) {
  const { label, onRemove, sx } = props

  return (
    <Box
      onClick={onRemove}
      sx={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 0.5,
        px: 1.5,
        py: 0.25,
        bgcolor: 'primary.50',
        borderRadius: 3,
        border: '1px solid',
        borderColor: 'primary.200',
        cursor: 'pointer',
        transition: 'all 0.2s ease-in-out',
        '&:hover': {
          bgcolor: 'primary.100',
          borderColor: 'primary.main',
          boxShadow: 1,
        },
        ...sx,
      }}
    >
      <Typography
        variant="body2"
        sx={{
          fontWeight: 500,
          color: 'primary.dark',
          lineHeight: 1.5,
        }}
      >
        {label}
      </Typography>
      <IconButton
        icon="close"
        size="small"
        aria-label={`Remove ${label}`}
        sx={{
          color: 'primary.main',
          ml: -0.5,
          p: 0.25,
          '& .MuiSvgIcon-root': {
            fontSize: '1rem',
          },
          '&:hover': {
            bgcolor: 'transparent',
          },
        }}
      />
    </Box>
  )
}

export default FilterTag
