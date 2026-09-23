import { Stack, Box, Typography, Tooltip, SxProps, Theme } from '@mui/material'
import Checkbox from './ui/Checkbox'
import Badge from './ui/Badge'

export interface FacetItemProps {
  /**
   * The facet value/label (e.g., "Images", "Videos")
   */
  label: string
  /**
   * Optional entity type icon component (e.g. for semantic tag facets)
   */
  entityIcon?: React.ComponentType<{ sx?: any }>
  /**
   * Background color for the entity type icon box
   */
  entityColor?: string
  /**
   * Number of resources with this facet value
   */
  count: number
  /**
   * Whether this facet item is currently selected
   */
  selected?: boolean
  /**
   * Click handler for the entire item
   */
  onClick?: () => void
  /**
   * Additional sx styles
   */
  sx?: SxProps<Theme>
}

/**
 * TYDAL FacetItem Component
 *
 * A single facet filter item with checkbox, label, counter badge, and optional delete button.
 * Used in sidebar facet cards for filtering resources.
 *
 * @example
 * ```tsx
 * <FacetItem
 *   label="Images"
 *   count={150}
 *   selected={false}
 *   onClick={() => handleToggle('Images')}
 * />
 *
 * ```
 */
function FacetItem(props: FacetItemProps) {
  const {
    label,
    count,
    selected = false,
    onClick,
    sx,
    entityIcon: EntityIcon,
    entityColor,
  } = props

  return (
    <Stack
      direction="row"
      alignItems="center"
      justifyContent="space-between"
      onClick={onClick}
      sx={{
        mx: 1.0,
        pl: 0.5,
        pr: 2.0,
        py: 0.5,
        borderRadius: 1,
        cursor: 'pointer',
        transition: 'all 0.2s ease-in-out',
        backgroundColor: selected ? 'primary.50' : 'transparent',
        '&:hover': {
          backgroundColor: 'action.hover',
        },
        ...sx,
      }}
    >
      {/* Checkbox and Label — flex: 1 so label gets all remaining space */}
      <Stack direction="row" alignItems="center" sx={{ flex: 1, minWidth: 0, overflow: 'hidden' }}>
        <Checkbox
          checked={selected}
          size="small"
          sx={{ p: 0.5, flexShrink: 0 }}
          onClick={(e) => {
            e.stopPropagation()
            onClick?.()
          }}
        />
        {EntityIcon && entityColor && (
          <Box sx={{ width: 14, height: 14, borderRadius: 0.5, bgcolor: entityColor, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, mr: 0.75 }}>
            <EntityIcon sx={{ fontSize: '0.75rem', color: 'white' }} />
          </Box>
        )}
        <Tooltip title={label} placement="top" enterDelay={700} disableHoverListener={label.length < 18}>
          <Typography
            variant="body2"
            noWrap
            sx={{
              color: selected ? 'primary.dark' : 'text.primary',
              fontWeight: selected ? 500 : 400,
              overflow: 'hidden',
              textOverflow: 'ellipsis',
            }}
          >
            {label}
          </Typography>
        </Tooltip>
      </Stack>

      {/* Counter Badge — flexShrink: 0 so it never steals label space */}
      <Badge badgeContent={count} color={selected ? 'primary' : 'default'} sx={{ flexShrink: 0, ml: 1 }}>
        <Box sx={{ width: 20 }} />
      </Badge>
    </Stack>
  )
}

export default FacetItem
