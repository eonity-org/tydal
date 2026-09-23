import { IconButton as MuiIconButton, IconButtonProps as MuiIconButtonProps, Tooltip } from '@mui/material'
import { icons } from './iconMap'

export interface IconButtonProps extends Omit<MuiIconButtonProps, 'color'> {
  /**
   * Icon name from the icon map (e.g., 'delete', 'close', 'visibility', 'add', 'edit')
   */
  icon: keyof typeof icons
  /**
   * Tooltip text to display on hover
   */
  tooltip?: string
  /**
   * Color variant
   */
  color?: 'primary' | 'secondary' | 'error' | 'info' | 'success' | 'warning'
  /**
   * Size variant
   */
  size?: 'small' | 'medium' | 'large'
}

/**
 * TYDAL Icon Button Component
 *
 * A button component that displays only an icon with optional tooltip.
 *
 * @example
 * ```tsx
 * <IconButton icon="delete" aria-label="Delete" tooltip="Delete" />
 * <IconButton icon="close" aria-label="Close" tooltip="Close" color="error" />
 * <IconButton icon="visibility" aria-label="View" size="small" />
 * ```
 */
function IconButton(props: IconButtonProps) {
  const { icon, tooltip, color = 'default', size = 'medium', ...muiProps } = props

  const IconComponent = icons[icon]

  const button = (
    <MuiIconButton
      aria-label={props['aria-label'] || tooltip}
      color={color}
      size={size}
      {...muiProps}
    >
      <IconComponent />
    </MuiIconButton>
  )

  if (tooltip) {
    return <Tooltip title={tooltip}>{button}</Tooltip>
  }

  return button
}

export default IconButton
