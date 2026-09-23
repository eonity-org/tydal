import { useState, useCallback, useMemo } from 'react'
import { Stack, Box, Collapse, Typography, Tooltip, IconButton } from '@mui/material'
import DoneAllIcon from '@mui/icons-material/DoneAll'
import ClearAllIcon from '@mui/icons-material/ClearAll'
import { useSurfaces } from '../contexts/ThemeContext'
import Card, { CardHeaderProps } from './ui/Card'
import ExpandCollapseArrow from './ui/ExpandCollapseArrow'
import TextField from './ui/TextField'
import FacetItem from './FacetItem'

export interface FacetValue {
  /**
   * Display label shown in the UI
   */
  label: string
  /**
   * Filter key sent to the backend (defaults to label when not set)
   */
  value?: string
  /**
   * Number of resources with this facet value
   */
  count: number
  /**
   * Whether this facet value is currently selected
   */
  selected: boolean
  /**
   * Optional entity type icon component (e.g. for semantic tag facets)
   */
  entityIcon?: React.ComponentType<{ sx?: any }>
  /**
   * Background color for the entity type icon box
   */
  entityColor?: string
}

export interface FacetCardProps {
  /**
   * Title of the facet card (e.g., "Resource Type", "Category")
   */
  title: string
  /**
   * Facet values to display
   */
  values: FacetValue[]
  /**
   * Whether to show search field (for facets with many values)
   */
  showSearch?: boolean
  /**
   * Search field placeholder
   */
  searchPlaceholder?: string
  /**
   * Maximum number of items to show before "Show more" (0 = unlimited)
   */
  showMoreLimit?: number
  /**
   * Callback when a facet item is clicked
   */
  onToggleValue?: (label: string) => void
  /**
   * Whether the card starts expanded
   */
  defaultExpanded?: boolean
  /**
   * Callback to select all facet values at once
   */
  onSelectAll?: () => void
  /**
   * Callback to deselect all facet values at once
   */
  onSelectNone?: () => void
  /**
   * Additional CardHeader props
   */
  CardHeaderProps?: Partial<CardHeaderProps>
}

/**
 * TYDAL FacetCard Component
 *
 * A collapsible card displaying facet filters with search and show more functionality.
 * Used in the sidebar for filtering resources by various attributes.
 *
 * @example
 * ```tsx
 * <FacetCard
 *   title="Resource Type"
 *   values={[
 *     { label: 'Images', count: 150, selected: true },
 *     { label: 'Videos', count: 50, selected: false },
 *     { label: 'Audio', count: 25, selected: false },
 *   ]}
 *   showSearch
 *   defaultExpanded
 *   onToggleValue={(label) => console.log('Toggled:', label)}
 * />
 * ```
 */
function FacetCard(props: FacetCardProps) {
  const {
    title,
    values,
    showSearch = false,
    searchPlaceholder = 'Search...',
    showMoreLimit = 0,
    onToggleValue,
    defaultExpanded = true,
    onSelectAll,
    onSelectNone,
    CardHeaderProps,
  } = props

  const surfaces = useSurfaces()
  const [expanded, setExpanded] = useState(defaultExpanded)
  const [searchQuery, setSearchQuery] = useState('')
  const [showMore, setShowMore] = useState(false)

  // Toggle expand/collapse
  const handleToggleExpand = useCallback(() => {
    setExpanded((prev) => !prev)
  }, [])

  // Filter values based on search
  const filteredValues = useMemo(() => {
    if (!searchQuery.trim()) {
      return values
    }
    const query = searchQuery.toLowerCase()
    return values.filter((value) =>
      value.label.toLowerCase().includes(query)
    )
  }, [values, searchQuery])

  // Determine which values to show
  const displayedValues = useMemo(() => {
    if (showMoreLimit === 0) {
      return filteredValues
    }
    if (showMore) {
      return filteredValues
    }
    return filteredValues.slice(0, showMoreLimit)
  }, [filteredValues, showMore, showMoreLimit])

  // Check if there are more items to show
  const hasMore = filteredValues.length > showMoreLimit && showMoreLimit > 0

  // Check if any / all values are selected
  const hasSelection = useMemo(() => values.some((v) => v.selected), [values])
  const allSelected  = useMemo(() => values.length > 0 && values.every((v) => v.selected), [values])

  return (
    <Card
      sx={{
        py: 0.25,
        mb: 2,
        bgcolor: surfaces.facet,
        border: hasSelection ? '1px solid' : '1px solid rgba(228, 228, 231, 0.5)',
        borderColor: hasSelection ? 'primary.main' : 'rgba(228, 228, 231, 0.5)',
      }}
    >
      <Card.Header
        title={
          <Typography
            variant="subtitle2"
            sx={{
              fontWeight: 500,
              color: hasSelection ? 'primary.dark' : 'text.primary',
            }}
          >
            {title}
          </Typography>
        }
        action={
          <Stack direction="row" alignItems="center" spacing={0}>
            {!allSelected && (
              <Tooltip title="Select all" placement="top">
                <IconButton
                  size="small"
                  onClick={(e) => { e.stopPropagation(); onSelectAll?.() }}
                  sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'primary.main' } }}
                >
                  <DoneAllIcon sx={{ fontSize: 16 }} />
                </IconButton>
              </Tooltip>
            )}
            {hasSelection && (
              <Tooltip title="Select none" placement="top">
                <IconButton
                  size="small"
                  onClick={(e) => { e.stopPropagation(); onSelectNone?.() }}
                  sx={{ color: 'text.secondary', p: 0.5, '&:hover': { color: 'primary.main' } }}
                >
                  <ClearAllIcon sx={{ fontSize: 16 }} />
                </IconButton>
              </Tooltip>
            )}
            <ExpandCollapseArrow
              expanded={expanded}
              onClick={handleToggleExpand}
              size="small"
            />
          </Stack>
        }
        {...CardHeaderProps}
        sx={{
          py: 1,
          px: 1.5,
          '& .MuiCardHeader-content': {
            overflow: 'hidden',
          },
          ...CardHeaderProps?.sx,
        }}
      />

      <Collapse in={expanded} timeout="auto" unmountOnExit>
        <Card.Content sx={{ pt: 0, pb: 0, px: 0, '&:last-child': { pb: 1.5 } }}>
          <Stack spacing={0}>
            {/* Search Field */}
            {showSearch && (filteredValues.length > 9 || searchQuery.length > 0) && (
              <Box sx={{ px: 0.5, mb: 0.5 }}>
                <TextField
                  placeholder={searchPlaceholder}
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  variant="standard"
                  fullWidth
                  sx={{
                    '& .MuiInputBase-root': {
                      fontSize: '0.875rem',
                    },
                  }}
                />
              </Box>
            )}

            {/* Facet Items */}
            {displayedValues.map((value) => (
              <FacetItem
                key={value.value ?? value.label}
                label={value.label}
                count={value.count}
                selected={value.selected}
                onClick={() => onToggleValue?.(value.value ?? value.label)}
                entityIcon={value.entityIcon}
                entityColor={value.entityColor}
              />
            ))}

            {/* Show More/Less Button */}
            {hasMore && (
              <Box
                sx={{
                  px: 0,
                  py: 1,
                  cursor: 'pointer',
                  '&:hover': {
                    color: 'primary.main',
                  },
                }}
                onClick={() => setShowMore((prev) => !prev)}
              >
                <Typography variant="body2" color="primary">
                  {showMore ? 'Show less' : `Show more (${filteredValues.length - showMoreLimit})`}
                </Typography>
              </Box>
            )}

            {/* No results message */}
            {filteredValues.length === 0 && searchQuery && (
              <Box sx={{ px: 0.5, py: 1 }}>
                <Typography variant="body2" color="text.secondary">
                  No results found
                </Typography>
              </Box>
            )}
          </Stack>
        </Card.Content>
      </Collapse>
    </Card>
  )
}

export default FacetCard
