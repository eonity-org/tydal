import { Badge as MuiBadge, BadgeProps as MuiBadgeProps } from '@mui/material'

export interface BadgeProps extends Omit<MuiBadgeProps, 'color'> {
  /**
   * Content to display inside the badge (typically a number or string)
   */
  badgeContent: React.ReactNode
  /**
   * Color variant for the badge
   */
  color?: 'primary' | 'secondary' | 'error' | 'info' | 'success' | 'warning' | 'default'
  /**
   * Maximum value to display (shows "99+" when exceeded)
   */
  max?: number
  /**
   * Position of the badge relative to the child
   */
  anchorOrigin?: {
    vertical: 'top' | 'bottom'
    horizontal: 'left' | 'right'
  }
  /**
   * Whether to show a dot badge instead of content
   */
  variant?: 'standard' | 'dot'
}

/**
 * TYDAL Badge Component
 *
 * Displays a badge (counter, notification dot) on a child element.
 * Used for facet item counts, notification indicators, etc.
 *
 * @example
 * ```tsx
 * <Badge badgeContent={150}>
 *   <Typography>Images</Typography>
 * </Badge>
 *
 * <Badge badgeContent={5} color="error">
 *   <NotificationsIcon />
 * </Badge>
 *
 * <Badge badgeContent={99} max={99}>
 *   <MailIcon />
 * </Badge>
 *
 * // Dot variant for notification
 * <Badge variant="dot" color="error">
 *   <Avatar />
 * </Badge>
 * ```
 */
function Badge(props: BadgeProps) {
  const { badgeContent, color = 'default', max = 99, variant = 'standard', ...muiProps } = props

  return (
    <MuiBadge
      badgeContent={badgeContent}
      color={color}
      max={max}
      variant={variant}
      {...muiProps}
    />
  )
}

export default Badge
