import { Chip, ChipProps, SxProps, Theme } from '@mui/material'
import { Folder } from '@mui/icons-material'
import { CHIP_COLORS } from '../../contexts/ThemeContext'

export interface StatusChipProps extends Omit<ChipProps, 'variant' | 'icon'> {
  /**
   * Variant of the status chip
   */
  variant: 'file-count' | 'active' | 'inactive' | 'lom' | 'lomes'
  /**
   * Label text to display
   */
  label?: string | number
  /**
   * Count for file-count variant
   */
  count?: number
}

const baseChipSx = {
  height: 20,
  fontSize: '0.75rem',
  '& .MuiChip-label': {
    px: 0.5,
  },
} as SxProps<Theme>


const variantConfig = {
  'file-count': {
    icon: <Folder sx={{ fontSize: '0.875rem' }} />,
    sx: {
      ...baseChipSx,
      bgcolor: 'grey.100',
      color: 'text.secondary',
      '& .MuiChip-icon': { fontSize: '0.875rem', ml: 0.5 },
    } as SxProps<Theme>,
  },
  active: {
    icon: undefined,
    sx: {
      ...baseChipSx,
      bgcolor: 'success.light',
      color: 'success.main',
    } as SxProps<Theme>,
  },
  inactive: {
    icon: undefined,
    sx: {
      ...baseChipSx,
      bgcolor: 'grey.100',
      color: 'text.secondary',
    } as SxProps<Theme>,
  },
  lom: {
    icon: undefined,
    sx: {
      ...baseChipSx,
      bgcolor: CHIP_COLORS.lom.light,
      color: CHIP_COLORS.lom.main,
      '& .MuiChip-label': { px: 0.5, fontWeight: 600 },
    } as SxProps<Theme>,
  },
  lomes: {
    icon: undefined,
    sx: {
      ...baseChipSx,
      bgcolor: 'info.light',
      color: 'info.main',
      '& .MuiChip-label': { px: 0.5, fontWeight: 600 },
    } as SxProps<Theme>,
  },
}

/**
 * TYDAL StatusChip Component
 *
 * A standardized chip component for displaying resource status indicators.
 * Used in ResourceCard to show file count, active status, and LOM indicators.
 *
 * @example
 * ```tsx
 * <StatusChip variant="file-count" count={5} />
 * <StatusChip variant="active" label="Active" />
 * <StatusChip variant="inactive" label="Inactive" />
 * <StatusChip variant="lom" label="LOM" />
 * <StatusChip variant="lomes" label="LOM-ES" />
 * ```
 */
function StatusChip(props: StatusChipProps) {
  const { variant, label, count, ...chipProps } = props

  const config = variantConfig[variant]

  return (
    <Chip
      label={variant === 'file-count' ? (count ?? 0) : label}
      size="small"
      icon={config.icon}
      sx={config.sx}
      {...chipProps}
    />
  )
}

export default StatusChip
