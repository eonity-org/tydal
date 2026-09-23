import { useState, useEffect, useCallback, useMemo } from 'react'
import { Box, Stack, Paper, CircularProgress, Typography, Alert } from '@mui/material'
import NarrowDivider from '../ui/NarrowDivider'
import IconButton from '../ui/IconButton'
import FacetCard from '../FacetCard'
import Button from '../ui/Button'
import resourceService, { Facet } from '../../api/resourceService'
import workspaceService from '../../api/workspaceService'
import type { FacetValue as FacetCardValue } from '../FacetCard'
import { useSurfaces } from '../../contexts/ThemeContext'
import { ENTITY_TYPES, type EntityTypeKey } from '../../constants/entityTypes'

// DEBUG: Set to true to show borders, false to hide
const LAYOUT_DEBUG = false

export interface SidebarProps {
  /**
   * Whether the sidebar is collapsed
   */
  collapsed: boolean
  /**
   * Width of the sidebar when expanded (in pixels)
   */
  width?: number
  /**
   * Width of the sidebar when collapsed (in pixels)
   */
  collapsedWidth?: number
  /**
   * Toggle sidebar collapse state
   */
  onToggle?: () => void
  /**
   * Collection ID to fetch facets for
   */
  collectionId?: number | null
  /**
   * Workspace ID — when set, fetches facets from workspace catalogue instead of collection catalogue
   */
  workspaceId?: string | null
  /**
   * Increment to force a facet re-fetch (e.g. after a resource is saved/deleted)
   */
  refreshTrigger?: number
  /**
   * When provided by the parent (lifted from MainContent's catalogue response), the Sidebar
   * skips its own catalogue API call and uses these facets directly. Pass `null` to signal
   * that a fetch is in progress (shows a spinner). Pass `[]` for an empty result.
   */
  externalFacets?: Facet[] | null
  /**
   * Search query to filter facets
   */
  searchQuery?: string
  /**
   * Active search mode — must match what MainContent uses so facet counts are consistent
   */
  searchMode?: 'prefix' | 'contains' | 'exact'
  /**
   * Active filters (controlled by parent)
   */
  activeFilters?: Record<string, string[]>
  /**
   * Callback when filters change
   */
  onFiltersChange?: (filters: Record<string, string[]>) => void
}

/**
 * TYDAL Sidebar Component
 *
 * Collapsible sidebar with facet filters for resource browsing.
 * Fetches real facet data from the backend API.
 *
 * @example
 * ```tsx
 * <Sidebar
 *   collapsed={sidebarCollapsed}
 *   collectionId={selectedCollectionId}
 *   width={280}
 *   collapsedWidth={0}
 * />
 * ```
 */
function Sidebar(props: SidebarProps) {
  const { collapsed, width = 280, collapsedWidth = 0, onToggle, collectionId, workspaceId, searchQuery = '', searchMode = 'prefix', activeFilters = {}, onFiltersChange, refreshTrigger, externalFacets } = props
  const surfaces = useSurfaces()

  // State for facets
  const [facets, setFacets] = useState<Facet[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // Fetch facets when collection/workspace or search changes.
  // Skipped entirely when the parent provides externalFacets (lifted from MainContent's catalogue call).
  useEffect(() => {
    if (externalFacets !== undefined) return

    const fetchFacets = async () => {
      if (!collectionId && !workspaceId) {
        setFacets([])
        setLoading(false)
        return
      }

      setLoading(true)
      setError(null)

      try {
        let facetData: Facet[] | undefined

        if (workspaceId) {
          const data = await workspaceService.getWorkspaceCatalogue(workspaceId, {
            page: 1,
            limit: 1,
            search: searchQuery || undefined,
            search_mode: 'contains',
          })
          facetData = data?.facets
        } else if (collectionId) {
          const data = await resourceService.getCatalogue(collectionId, {
            page: 1,
            limit: 1,
            search: searchQuery || undefined,
            search_mode: searchMode,
          })
          facetData = data?.facets
        }

        setFacets(facetData ?? [])
      } catch (err) {
        setError('Failed to load facets')
        setFacets([])
      } finally {
        setLoading(false)
      }
    }

    fetchFacets()
  }, [collectionId, workspaceId, searchQuery, searchMode, refreshTrigger, externalFacets])

  // When externalFacets is provided: use them directly; null means a fetch is in progress.
  const usingExternal = externalFacets !== undefined
  const activeFacets  = usingExternal ? (externalFacets ?? []) : facets
  const isLoading     = usingExternal ? externalFacets === null : loading

  // Transform backend facet to FacetCard format
  const transformFacetToCard = useCallback((facet: Facet) => {
    const activeValues = activeFilters[facet.key] || []
    const values: FacetCardValue[] = Object.entries(facet.values).map(([key, facetValue]) => {
      if (facet.key === 'semantic_tags') {
        // Key is "label||entity_type" composite — parse for display + entity type rendering
        const sepIdx = key.indexOf('||')
        const displayLabel = sepIdx >= 0 ? key.slice(0, sepIdx) : key
        const entityTypeKey = sepIdx >= 0 ? key.slice(sepIdx + 2) : ''
        const et = entityTypeKey && entityTypeKey in ENTITY_TYPES ? ENTITY_TYPES[entityTypeKey as EntityTypeKey] : null
        return {
          label: displayLabel,
          value: key,
          count: facetValue.count,
          selected: activeValues.includes(key),
          entityIcon: et?.Icon ?? undefined,
          entityColor: et?.color ?? undefined,
        }
      }
      return {
        label: key,
        count: facetValue.count,
        selected: activeValues.includes(key),
      }
    })

    // Collapse lom/lomes facets by default (labels are too long)
    const isLOMFacet = facet.key === 'lom' || facet.key === 'lomes'

    return {
      title: facet.label,
      values,
      showSearch: values.length > 9,
      searchPlaceholder: `Search ${facet.label.toLowerCase()}...`,
      showMoreLimit: 5,
      defaultExpanded: !isLOMFacet,
    }
  }, [activeFilters])

  // Compute facet configurations and states from facets and activeFilters
  const { facetConfigs, facetStates } = useMemo(() => {
    const configs: Record<string, ReturnType<typeof transformFacetToCard>> = {}
    const states: Record<string, FacetCardValue[]> = {}

    activeFacets.forEach((facet) => {
      const transformed = transformFacetToCard(facet)
      configs[facet.key] = transformed
      states[facet.key] = transformed.values
    })

    return { facetConfigs: configs, facetStates: states }
  }, [activeFacets, transformFacetToCard])

  // Helper: Update filters by adding or removing a value
  const updateFilterValue = useCallback((facetKey: string, label: string, action: 'add' | 'remove') => {
    const currentActive = activeFilters[facetKey] || []
    const updatedFilters = { ...activeFilters }

    if (action === 'remove') {
      // Remove the filter
      updatedFilters[facetKey] = currentActive.filter((l) => l !== label)
      if (updatedFilters[facetKey].length === 0) {
        delete updatedFilters[facetKey]
      }
    } else {
      // Add the filter
      updatedFilters[facetKey] = [...currentActive, label]
    }

    // Notify parent of filter change
    if (onFiltersChange) {
      onFiltersChange(updatedFilters)
    }
  }, [activeFilters, onFiltersChange])

  // Handle toggle value
  const handleToggleValue = useCallback((facetKey: string, label: string) => {
    const currentActive = activeFilters[facetKey] || []
    const isActive = currentActive.includes(label)
    updateFilterValue(facetKey, label, isActive ? 'remove' : 'add')
  }, [activeFilters, updateFilterValue])

  // Select all values for a facet
  const handleSelectAll = useCallback((facetKey: string, allLabels: string[]) => {
    const updatedFilters = { ...activeFilters, [facetKey]: allLabels }
    onFiltersChange?.(updatedFilters)
  }, [activeFilters, onFiltersChange])

  // Deselect all values for a facet
  const handleSelectNone = useCallback((facetKey: string) => {
    const updatedFilters = { ...activeFilters }
    delete updatedFilters[facetKey]
    onFiltersChange?.(updatedFilters)
  }, [activeFilters, onFiltersChange])

  // Clear all selections
  const clearAllSelections = useCallback(() => {
    // Notify parent that all filters are cleared
    if (onFiltersChange) {
      onFiltersChange({})
    }
  }, [onFiltersChange])

  // Count total selections
  const totalSelections = useMemo(() => {
    return Object.values(activeFilters).reduce((sum, values) => sum + values.length, 0)
  }, [activeFilters])

  return (
    <Box
      sx={{
        width: collapsed ? collapsedWidth : width,
        minWidth: collapsed ? collapsedWidth : width,
        height: 'calc(100vh - 64px)',
        bgcolor: surfaces.sidebar,
        borderRight: '1px solid rgba(228, 228, 231, 0.5)',
        display: 'flex',
        flexDirection: 'column',
        outline: LAYOUT_DEBUG ? '2px solid green' : 'none',
      }}
    >
      {/* Collapse button at the top - always visible */}
      {onToggle && (
        <>
        <Box
          sx={{
            px: 1.5,
            minHeight: '4rem',
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            position: 'relative',
            outline: LAYOUT_DEBUG ? '2px solid yellow' : 'none',
          }}
        >
          {/* Left section: Collapse button + Filters label */}
          <Stack direction="row" spacing={1} alignItems="center">
            <IconButton
              icon={collapsed ? 'chevron-right' : 'chevron-left'}
              size="small"
              aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              tooltip={collapsed ? 'Expand' : 'Collapse'}
              onClick={onToggle}
            />

            {!collapsed && (
              <Typography
                variant="body1"
                color="text.primary"
                sx={{ fontWeight: 400 }}
              >
                Filters
              </Typography>
            )}
          </Stack>

          {/* Right section: Clear button - positioned absolutely to not affect layout */}
          <Box
            sx={{
              position: 'absolute',
              right: 8,
              top: '50%',
              transform: 'translateY(-50%)',
              opacity: !collapsed && totalSelections > 0 ? 1 : 0,
              pointerEvents: !collapsed && totalSelections > 0 ? 'auto' : 'none',
              transition: 'opacity 0.2s ease-in-out',
            }}
          >
            <Button
              variant="text"
              size="small"
              onClick={clearAllSelections}
              sx={{ color: 'primary.main', px: 1, fontSize: '0.75rem' }}
            >
              Clear ({totalSelections})
            </Button>
          </Box>
        </Box>
        <NarrowDivider />
        </>
      )}

      {/* Facet container */}
      <Paper
        elevation={0}
        sx={{
          flex: 1,
          overflow: 'auto',
          borderRadius: 0,
          bgcolor: surfaces.sidebar,
          display: collapsed ? 'none' : 'block',
          outline: LAYOUT_DEBUG ? '2px solid blue' : 'none',
        }}
      >
          <Box sx={{ p: 2 }}>
            {/* Loading state — only when a collection is selected */}
            {isLoading && (collectionId || workspaceId) && (
              <Box sx={{ display: 'flex', justifyContent: 'center', p: 2 }}>
                <CircularProgress size={24} />
              </Box>
            )}

            {/* Error state — only shown when using internal fetch */}
            {!usingExternal && error && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {error}
              </Alert>
            )}

            {/* No collection or workspace selected */}
            {!collectionId && !workspaceId && (
              <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'center', p: 2 }}>
                Select a collection to view facets
              </Typography>
            )}

            {/* Facet content */}
            {!isLoading && (collectionId || workspaceId) && activeFacets.length > 0 && (
              <>
                {/* Facet Cards */}
                <Stack spacing={1}>
                  {activeFacets.map((facet) => {
                    const card = facetConfigs[facet.key]
                    const values = facetStates[facet.key]

                    return (
                      <FacetCard
                        key={facet.key}
                        {...card}
                        values={values}
                        onToggleValue={(label: string) => handleToggleValue(facet.key, label)}
                        onSelectAll={() => handleSelectAll(facet.key, values.map((v) => v.value ?? v.label))}
                        onSelectNone={() => handleSelectNone(facet.key)}
                      />
                    )
                  })}
                </Stack>
              </>
            )}

            {/* No facets available */}
            {!isLoading && (collectionId || workspaceId) && activeFacets.length === 0 && (
              <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'center', p: 2 }}>
                {workspaceId ? 'No facets available for this workspace' : 'No facets available for this collection'}
              </Typography>
            )}
          </Box>
        </Paper>
    </Box>
  )
}

export default Sidebar
