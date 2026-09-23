import IconButton, { IconButtonProps } from './IconButton'

export interface ExpandCollapseArrowProps extends Omit<IconButtonProps, 'icon'> {
  /**
   * Whether the section is expanded (shows "less" icon) or collapsed (shows "more" icon)
   */
  expanded: boolean
  /**
   * Size of the arrow icon
   */
  size?: 'small' | 'medium'
}

/**
 * TYDAL Expand/Collapse Arrow Component
 *
 * An icon button that toggles between expand more (▼) and expand less (▲) icons.
 *
 * @example
 * ```tsx
 * <ExpandCollapseArrow expanded={false} onClick={handleToggle} />
 * <ExpandCollapseArrow expanded={true} onClick={handleToggle} size="small" />
 * ```
 */
function ExpandCollapseArrow(props: ExpandCollapseArrowProps) {
  const { expanded, size = 'medium', ...iconButtonProps } = props

  return (
    <IconButton
      icon={expanded ? 'expand-more' : 'chevron-right'}
      size={size}
      aria-label={expanded ? 'Collapse' : 'Expand'}
      {...iconButtonProps}
    />
  )
}

export default ExpandCollapseArrow
