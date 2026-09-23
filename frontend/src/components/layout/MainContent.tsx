import React, { useState, useEffect, useCallback } from 'react'
import {
  Box,
  Grid,
  Paper,
  Typography,
  CircularProgress,
  Alert,
  Stack,
  IconButton,
  TextField,
  MenuItem,
  Button,
  Chip,
  Tooltip,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Checkbox,
  useTheme,
} from '@mui/material'
import { ViewModule, ViewList, ChevronLeft, ChevronRight, Close, Add, ArrowUpward, ArrowDownward, Edit, Delete, AutoAwesome } from '@mui/icons-material'
import TydalIsotype from '../ui/TydalIsotype'
import ResourceCard from '../ResourceCard'
import AssetPreview from '../ui/AssetPreview'
import { useAityStatusSync } from '../../hooks/useAityStatusSync'
import { AITY_STATES, AITY_UNDER_AUTO_APPROVE, aityIsotypeProps } from '../../theme/aityStates'
import ResourceDetailModal from '../modals/ResourceDetailModal'
import { ResourceWizard } from '../wizard/ResourceWizard'
import resourceService, { type ResourceData, type CatalogueData, type Facet } from '../../api/resourceService'
import workspaceService, { type WorkspaceCatalogueResponse } from '../../api/workspaceService'
import { autoApproveWorkspaceStream, type AutoApproveResult } from '../../api/aityService'
import WorkspaceAskDialog from '../modals/WorkspaceAskDialog'
import NarrowDivider from '../ui/NarrowDivider'
import { useSurfaces, CHIP_COLORS } from '../../contexts/ThemeContext'
import { ENTITY_TYPES, type EntityTypeKey } from '../../constants/entityTypes'
import { resolveStorageUrl } from '../../utils/storageUrl'
import { useBasket } from '../../contexts/BasketContext'
import SelectionBar from '../basket/SelectionBar'
import SegmentedChoice from '../ui/SegmentedChoice'
import { usePermissions } from '../../hooks/usePermissions'

// DEBUG: Set to true to show borders, false to hide
const LAYOUT_DEBUG = false

export interface MainContentProps {
  /**
   * Collection ID to fetch resources for
   */
  collectionId?: number | null
  /**
   * Workspace ID — when set, fetches from workspace catalogue instead of collection catalogue
   */
  activeWorkspaceId?: string | null
  /**
   * Display name of the active workspace (shown in the Ask AI dialog)
   */
  activeWorkspaceName?: string | null
  /**
   * Search query
   */
  searchQuery?: string
  /**
   * Search mode: prefix (starts with), contains, exact
   */
  searchMode?: 'prefix' | 'contains' | 'exact'
  /**
   * Active filters from sidebar
   */
  activeFilters?: Record<string, string[]>
  /**
   * Callback when a filter is removed
   */
  onFilterRemove?: (facetKey: string, label: string) => void
  /**
   * Callback when all filters are cleared
   */
  onFiltersClear?: () => void
  /**
   * Callback to clear the active search query
   */
  onSearchClear?: () => void
  /**
   * Called after a resource is saved or deleted so the parent can refresh related components (e.g. facets)
   */
  onRefresh?: () => void
  /** Called when the wizard creates a new workspace (Defer or AiTy mode) so the parent can refresh the workspace list. */
  onWorkspaceCreated?: () => void
  /**
   * Called after every successful catalogue fetch, passing the latest facets up to the parent.
   * The parent can then forward these to the Sidebar, eliminating a second catalogue API call.
   */
  onFacetsLoaded?: (facets: Facet[] | null) => void
  /**
   * Incrementing version counter from the parent — triggers a resource re-fetch when it changes.
   * Used to react to async backend events (e.g. AITY workspace association) without user interaction.
   */
  refreshTrigger?: number
  /**
   * When true, each resource card shows its aity_status chip.
   * Enabled only in the AiTy Review workspace view.
   */
  showAityStatus?: boolean
}

/**
 * TYDAL MainContent Component
 *
 * Main content area for displaying resources in grid/list view with pagination.
 *
 * @example
 * ```tsx
 * <MainContent collectionId={selectedCollectionId} />
 * ```
 */
function MainContent(props: MainContentProps) {
  const { collectionId, activeWorkspaceId, activeWorkspaceName, searchQuery = '', searchMode = 'prefix', activeFilters = {}, onFilterRemove, onFiltersClear, onSearchClear, onRefresh, onWorkspaceCreated, onFacetsLoaded, refreshTrigger: externalRefreshTrigger, showAityStatus = false } = props
  const surfaces = useSurfaces()
  const theme = useTheme()

  const [resources, setResources] = useState<ResourceData[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [viewMode, setViewMode] = useState<'grid' | 'list'>('grid')
  const [itemsPerPage, setItemsPerPage] = useState<number>(48)
  const [currentPage, setCurrentPage] = useState<number>(1)
  const [totalResources, setTotalResources] = useState<number>(0)
  const [detailModalOpen, setDetailModalOpen] = useState(false)
  const [selectedResourceId, setSelectedResourceId] = useState<string | null>(null)
  const [detailModalMode, setDetailModalMode] = useState<'view' | 'edit' | 'create'>('view')
  const [refreshTrigger, setRefreshTrigger] = useState(0)
  const [sortBy, setSortBy] = useState<'updated_at' | 'name' | 'id'>('updated_at')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const [lastInteractedId, setLastInteractedId] = useState<string | null>(null)
  const [deleteConfirm, setDeleteConfirm] = useState<{ open: boolean; resourceIds: string[]; resourceName: string }>({
    open: false,
    resourceIds: [],
    resourceName: '',
  })
  // Guards the confirm button: without it a double-click fires the whole batch twice.
  const [deleting, setDeleting] = useState(false)
  const [wizardOpen, setWizardOpen] = useState(false)
  const [askOpen, setAskOpen] = useState(false)
  const [hasLexicalMatches, setHasLexicalMatches] = useState(true)
  const [autoApproving, setAutoApproving] = useState(false)
  const [autoApproveResult, setAutoApproveResult] = useState<AutoApproveResult | null>(null)
  const [autoApproveError, setAutoApproveError] = useState<string | null>(null)
  const [autoApproveLogOpen, setAutoApproveLogOpen] = useState(false)
  const [autoApproveLogLines, setAutoApproveLogLines] = useState<string[]>([])
  const autoApproveLogEndRef = React.useRef<HTMLDivElement>(null)

  // Auto-scroll log dialog to bottom whenever new lines arrive
  useEffect(() => {
    autoApproveLogEndRef.current?.scrollIntoView({ behavior: 'smooth' })
  }, [autoApproveLogLines])

  // Clear auto-approve state when leaving the AiTy workspace
  useEffect(() => {
    if (!showAityStatus) {
      setAutoApproveResult(null)
      setAutoApproveError(null)
      setAutoApproveLogLines([])
      setAutoApproveLogOpen(false)
    }
  }, [showAityStatus])

  // Smart polling for in-flight aity_status changes on the visible cards.
  // Stops automatically when nothing is in flight; pauses when the tab is
  // hidden; backs off after consecutive unchanged polls.
  useAityStatusSync(resources, (patches) => {
    if (patches.length === 0) return

    // Detect transitions BEFORE we hand off to setState. We do this against the
    // most-recent `resources` closure (refreshed on each render), not inside the
    // updater — the updater is called lazily and may double-run in StrictMode,
    // so any side-effects scheduled from inside it are unreliable.
    const prevById = new Map(resources.map((r) => [String(r.id), r]))
    const refetchIds: string[] = []
    for (const p of patches) {
      const prevRow = prevById.get(String(p.id))
      // A card just promoted into automatic_review_done needs a full refetch —
      // auto-review applies AI-generated name/description/tags on the backend,
      // and a status-only patch would leave the visible card stale.
      if (p.aity_status === 'automatic_review_done'
          && prevRow?.aity_status !== 'automatic_review_done') {
        refetchIds.push(String(p.id))
      }
    }

    setResources((prev) => {
      const byId = new Map(patches.map((p) => [String(p.id), p]))
      let changed = false
      const next = prev.map((r) => {
        const patch = byId.get(String(r.id))
        if (!patch) return r
        const underNext = patch.under_auto_approve ?? r.under_auto_approve
        if (patch.aity_status === r.aity_status
            && patch.updated_at === r.updated_at
            && underNext === r.under_auto_approve) return r
        changed = true
        return {
          ...r,
          aity_status: (patch.aity_status as ResourceData['aity_status']) ?? r.aity_status,
          updated_at: patch.updated_at ?? r.updated_at,
          under_auto_approve: underNext,
        }
      })
      return changed ? next : prev
    })

    for (const id of refetchIds) {
      resourceService.getResource(id).then((fresh) => {
        if (!fresh) return
        setResources((prev) => {
          let replaced = false
          const next = prev.map((r) => {
            if (String(r.id) !== String(fresh.id)) return r
            replaced = true
            // Preserve catalogue-only enrichment fields that /resources/{id}
            // may not return (e.g. workspace[], collection[]), then overlay
            // the fresh fields. Fresh values win for everything they include.
            return { ...r, ...fresh }
          })
          return replaced ? next : prev
        })
      }).catch(() => { /* swallow — next poll will retry the transition detection */ })
    }
  })

  // Count total active filters
  const totalActiveFilters = Object.values(activeFilters).reduce((sum, values) => sum + values.length, 0)
  const visibleResourceIds = resources.map((resource) => String(resource.id))

  // Selection lives in the basket, identically in grid and list: it survives
  // paging, sorting, filters, the view toggle and a change of collection or
  // workspace. See BasketContext for why that persistence is safe.
  const basket = useBasket()

  // Controls the role will never grant are not rendered at all: a viewer meets
  // a dashboard they can read, not a row of buttons that only ever 403. What
  // stays visible-but-disabled is the contextual case (no collection chosen),
  // which is a precondition they can clear.
  const { can, ready: permissionsReady } = usePermissions()
  const mayCreate = permissionsReady && can('resources.create')
  const mayUpdate = permissionsReady && can('resources.update')
  const mayDelete = permissionsReady && can('resources.delete')
  const selectedVisibleCount = visibleResourceIds.filter((id) => basket.has(id)).length
  const allVisibleSelected = visibleResourceIds.length > 0 && selectedVisibleCount === visibleResourceIds.length
  const someVisibleSelected = selectedVisibleCount > 0 && selectedVisibleCount < visibleResourceIds.length

  // Fetch resources when collection/workspace, page, items per page, or activeFilters changes
  useEffect(() => {
    const controller = new AbortController()

    const fetchResources = async () => {
      if (!collectionId && !activeWorkspaceId) {
        setResources([])
        setLoading(false)
        setTotalResources(0)
        onFacetsLoaded?.(null)
        return
      }

      setLoading(true)
      setError(null)

      try {
        let data: CatalogueData | WorkspaceCatalogueResponse | null = null

        if (activeWorkspaceId) {
          data = await workspaceService.getWorkspaceCatalogue(activeWorkspaceId, {
            page: currentPage,
            limit: itemsPerPage,
            search: searchQuery || undefined,
            facets: Object.keys(activeFilters).length > 0 ? activeFilters : undefined,
            sort_by: sortBy,
            sort_dir: sortDir,
            search_mode: 'contains',
          }, controller.signal)
        } else if (collectionId) {
          data = await resourceService.getCatalogue(collectionId, {
            page: currentPage,
            limit: itemsPerPage,
            search: searchQuery || undefined,
            facets: Object.keys(activeFilters).length > 0 ? activeFilters : undefined,
            sort_by: sortBy,
            sort_dir: sortDir,
            search_mode: searchMode,
          }, controller.signal)
        }

        if (data?.data) {
          setResources(data.data)
          setTotalResources(data.total || data.data.length || 0)
          setHasLexicalMatches(data.has_lexical_matches ?? true)
          onFacetsLoaded?.(data.facets ?? [])
        } else {
          setResources([])
          setTotalResources(0)
          setHasLexicalMatches(true)
          onFacetsLoaded?.([])
        }
      } catch (err) {
        if (err instanceof DOMException && err.name === 'AbortError') return
        setError('Failed to load resources')
        setResources([])
        setTotalResources(0)
        onFacetsLoaded?.([])
      } finally {
        setLoading(false)
      }
    }

    fetchResources()
    return () => controller.abort()
  }, [collectionId, activeWorkspaceId, currentPage, itemsPerPage, searchQuery, searchMode, activeFilters, refreshTrigger, externalRefreshTrigger, sortBy, sortDir])

  const handleResourceClick = useCallback((resourceId: string) => {
    setSelectedResourceId(resourceId)
    setDetailModalMode('view') // Open in view mode
    setDetailModalOpen(true)
  }, [])

  const handleEditResource = useCallback((e: React.MouseEvent, resourceId: string) => {
    e.stopPropagation()
    setSelectedResourceId(resourceId)
    setDetailModalMode('edit') // Open in edit mode
    setDetailModalOpen(true)
  }, [])

  const handleDeleteResource = useCallback((e: React.MouseEvent, resourceId: string) => {
    e.stopPropagation()

    // Find the resource to get its name
    const resource = resources.find(r => r.id === resourceId)
    if (!resource) return

    setDeleteConfirm({
      open: true,
      resourceIds: [String(resourceId)],
      resourceName: resource.name || 'this resource',
    })
  }, [resources])

  const handleToggleResourceSelected = useCallback((e: React.ChangeEvent<HTMLInputElement>, resourceId: string) => {
    e.stopPropagation()
    basket.toggle(String(resourceId))
  }, [basket])

  /** Put this page in the basket, or take it back out if it is already all in. */
  const handleToggleVisibleSelected = useCallback((e: React.ChangeEvent<HTMLInputElement>) => {
    e.stopPropagation()
    if (allVisibleSelected) visibleResourceIds.forEach(basket.remove)
    else basket.add(visibleResourceIds)
  }, [allVisibleSelected, visibleResourceIds, basket])

  const handleNewMultimedia = useCallback(() => {
    if (!collectionId) {
      console.error('Cannot create resource: No collection selected')
      return
    }
    setSelectedResourceId(null)
    setDetailModalMode('create')
    setDetailModalOpen(true)
  }, [collectionId])

  const handleSort = (by: 'updated_at' | 'name' | 'id') => {
    if (sortBy === by) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortBy(by)
      setSortDir(by === 'updated_at' ? 'desc' : 'asc')
    }
    setCurrentPage(1)
  }

  const SORTS: { key: 'updated_at' | 'name' | 'id'; label: string }[] = [
    { key: 'updated_at', label: 'Date' },
    { key: 'name',       label: 'Name' },
    { key: 'id',         label: 'ID'   },
  ]

  const handleNewBatch = useCallback(() => {
    if (!collectionId) return
    setWizardOpen(true)
  }, [collectionId])

  const handlePageChange = useCallback((newPage: number) => {
    setCurrentPage(newPage)
  }, [])

  const totalPages = Math.ceil(totalResources / itemsPerPage)

  const handleModalClose = useCallback(() => {
    setDetailModalOpen(false)
    setSelectedResourceId(null)
    setDetailModalMode('view')
  }, [])

  useEffect(() => {
    if (!lastInteractedId) return
    const t = setTimeout(() => setLastInteractedId(null), 3000)
    return () => clearTimeout(t)
  }, [lastInteractedId])

  // After a save, a PDF/audio resource's rendered preview can lag the catalogue refetch by a
  // second or two (the backend renders it synchronously on upload, but a fast Save can outrun
  // a still-in-flight upload). Rather than force the user to reload, poll just this one resource
  // a few times and patch its card in place the moment preview_snapshot_url appears.
  const backfillPreview = useCallback((id: string) => {
    if (!id) return
    let attempts = 0
    const maxAttempts = 4
    const tick = () => {
      attempts++
      resourceService.getResource(id).then((fresh) => {
        if (!fresh) return
        // Once the rendered preview lands, patch this one card in place and stop. Resources
        // that never get a preview_snapshot_url (e.g. images, served from conversions) simply
        // run out the small attempt budget and stop — harmless.
        if (fresh.preview_snapshot_url) {
          setResources((prev) => {
            let replaced = false
            const next = prev.map((r) => {
              if (String(r.id) !== String(fresh.id)) return r
              replaced = true
              return { ...r, ...fresh }
            })
            return replaced ? next : prev
          })
          return
        }
        if (attempts < maxAttempts) setTimeout(tick, 1500)
      }).catch(() => {
        if (attempts < maxAttempts) setTimeout(tick, 1500)
      })
    }
    setTimeout(tick, 1200)
  }, [])

  const handleResourceSaved = useCallback((resource?: ResourceData) => {
    onRefresh?.()
    const savedId = resource ? String(resource.id) : selectedResourceId
    if (savedId) backfillPreview(savedId)
    if (resource) {
      // Create: go to page 1 so the new resource is visible at the top (sorted by updated_at desc).
      // If already on page 1, page state won't change so force a refresh via the trigger.
      setLastInteractedId(String(resource.id))
      if (currentPage === 1) {
        setRefreshTrigger(prev => prev + 1)
      } else {
        setCurrentPage(1)
      }
    } else {
      // Edit: reset to page 1 so the freshly-updated card (now at top by updated_at desc) is visible.
      // setCurrentPage(1) triggers the useEffect if page was > 1; otherwise use refreshTrigger.
      setLastInteractedId(selectedResourceId)
      if (currentPage === 1) {
        setRefreshTrigger(prev => prev + 1)
      } else {
        setCurrentPage(1)
      }
    }
  }, [selectedResourceId, currentPage, onRefresh, backfillPreview])

  const handleConfirmDelete = useCallback(async () => {
    if (deleteConfirm.resourceIds.length === 0 || deleting) return

    setDeleting(true)
    try {
      const results = await Promise.allSettled(
        deleteConfirm.resourceIds.map(async (resourceId) => {
          const deleted = await resourceService.deleteResource(resourceId)
          if (!deleted) throw new Error('Delete request did not complete')
          return resourceId
        })
      )

      const failedIds = deleteConfirm.resourceIds.filter((_, index) => results[index].status === 'rejected')
      const deletedCount = deleteConfirm.resourceIds.length - failedIds.length

      // Whatever went to the trash leaves the basket; whatever failed stays in
      // it, so the retry is one click rather than a re-selection.
      deleteConfirm.resourceIds
        .filter((id) => !failedIds.includes(id))
        .forEach(basket.remove)

      setActionError(failedIds.length > 0
        ? `Deleted ${deletedCount} ${deletedCount === 1 ? 'resource' : 'resources'}. ${failedIds.length} failed.`
        : null)

      // Go to page 1 — avoids empty-page scenario if the deleted item was the only
      // one on the last page. If already on page 1, force a refresh via the trigger.
      onRefresh?.()
      setDeleteConfirm({ open: false, resourceIds: [], resourceName: '' })
      if (currentPage === 1) {
        setRefreshTrigger(prev => prev + 1)
      } else {
        setCurrentPage(1)
      }
    } catch (error) {
      console.error('Failed to delete resource:', error)
      setActionError(`Failed to delete resource: ${error instanceof Error ? error.message : 'Unknown error'}`)
    } finally {
      setDeleting(false)
    }
  }, [deleteConfirm.resourceIds, currentPage, onRefresh, basket, deleting])

  const handleCancelDelete = useCallback(() => {
    setDeleteConfirm({ open: false, resourceIds: [], resourceName: '' })
  }, [])

  const handleAutoApprove = useCallback(async () => {
    if (!activeWorkspaceId || autoApproving) return
    setAutoApproving(true)
    setAutoApproveResult(null)
    setAutoApproveError(null)
    setAutoApproveLogLines([])
    setAutoApproveLogOpen(true)
    try {
      await autoApproveWorkspaceStream(
        activeWorkspaceId,
        {},
        (line) => setAutoApproveLogLines(prev => [...prev, line]),
        (result) => {
          setAutoApproveResult(result)
          setRefreshTrigger(prev => prev + 1)
          onRefresh?.()
        },
        (message) => setAutoApproveError(message),
      )
    } catch (err: any) {
      setAutoApproveError(err?.message ?? 'Auto-approve failed')
    } finally {
      setAutoApproving(false)
    }
  }, [activeWorkspaceId, autoApproving, onRefresh])

  const getResourceName = (resource: ResourceData): string => {
    if (resource.name) return resource.name
    if (resource.data?.description?.course_title) return resource.data.description.course_title
    return 'Untitled Resource'
  }

  const getResourcePreviewUrl = (resource: ResourceData): string => {
    const conversionUrls = (resource as any).snapshot_file?.conversion_urls
    return resolveStorageUrl(conversionUrls?.thumbnail
      || conversionUrls?.small
      || resource.preview_snapshot_url
      || conversionUrls?.original
      || (resource as any).snapshot_file?.url
      || '')
  }

  const getResourceFileCount = (resource: ResourceData): number => {
    if (typeof resource.files_count === 'number') return resource.files_count
    return resource.files?.length || 0
  }

  const formatResourceDate = (value?: string | null): string => {
    if (!value) return 'No date'
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return 'No date'
    return new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(date)
  }

  const renderResourceList = () => (
    <Stack spacing={1}>
      {resources.map((resource) => {
        const name = getResourceName(resource)
        const previewUrl = getResourcePreviewUrl(resource)
        const tags: any[] = resource.semanticTags ?? (resource as any).semantic_tags ?? []
        const isSelected = basket.has(String(resource.id))
        return (
          <Paper
            key={resource.id}
            elevation={0}
            onClick={() => handleResourceClick(resource.id)}
            sx={{
              display: 'grid',
              gridTemplateColumns: { xs: '2rem 4.5rem minmax(0, 1fr) auto', md: '2.25rem 5.5rem minmax(0, 1fr) auto' },
              gap: 1.5,
              alignItems: 'center',
              p: 1,
              cursor: 'pointer',
              border: String(resource.id) === lastInteractedId ? '2px solid' : '1px solid',
              borderColor: String(resource.id) === lastInteractedId ? 'primary.main' : isSelected ? 'primary.light' : 'rgba(228, 228, 231, 0.6)',
              borderRadius: 1.5,
              bgcolor: isSelected ? 'primary.subtle' : 'background.paper',
              '&:hover': {
                borderColor: 'primary.light',
                bgcolor: isSelected ? 'primary.subtle' : 'background.paper',
                boxShadow: '0 8px 16px rgba(15, 23, 42, 0.08)',
              },
            }}
          >
            <Box onClick={(e) => e.stopPropagation()} sx={{ display: 'flex', justifyContent: 'center' }}>
              <Checkbox
                size="small"
                checked={isSelected}
                onChange={(e) => handleToggleResourceSelected(e, resource.id)}
                inputProps={{ 'aria-label': `Select ${name}` }}
                sx={{ p: 0.5 }}
              />
            </Box>

            <AssetPreview src={previewUrl} alt={name} />

            <Stack spacing={0.75} sx={{ minWidth: 0 }}>
              <Stack direction="row" spacing={0.75} alignItems="center" sx={{ minWidth: 0 }}>
                <Typography
                  variant="body2"
                  sx={{
                    minWidth: 0,
                    overflow: 'hidden',
                    textOverflow: 'ellipsis',
                    whiteSpace: 'nowrap',
                    color: 'text.primary',
                  }}
                >
                  {name}
                </Typography>
                <Typography variant="caption" sx={{ color: 'text.disabled', fontFamily: 'monospace', flexShrink: 0 }} title={String(resource.id)}>
                  {String(resource.id).slice(0, 8)}
                </Typography>
              </Stack>

              <Stack direction="row" spacing={0.75} alignItems="center" flexWrap="wrap" useFlexGap>
                <Chip
                  label={resource.type?.toUpperCase() || 'MULTIMEDIA'}
                  size="small"
                  sx={{
                    height: 20,
                    borderRadius: '4px',
                    fontSize: '0.75rem',
                    bgcolor: resource.state !== 'live' ? 'grey.100' : 'primary.subtle',
                    color: resource.state !== 'live' ? 'text.secondary' : 'primary.main',
                  }}
                />
                {resource.aity_status && AITY_STATES[resource.aity_status as keyof typeof AITY_STATES] && (() => {
                  const baseCfg = AITY_STATES[resource.aity_status as keyof typeof AITY_STATES]
                  const cfg = (resource.aity_status === 'suggestions_made' && resource.under_auto_approve === true)
                    ? AITY_UNDER_AUTO_APPROVE
                    : baseCfg
                  return (
                    <Tooltip title={cfg.tooltip} arrow>
                      <Box sx={{ display: 'inline-flex', alignItems: 'center' }}>
                        <TydalIsotype size={18} {...aityIsotypeProps(theme, cfg)} />
                      </Box>
                    </Tooltip>
                  )
                })()}
                <Typography variant="caption" color="text.secondary">
                  {getResourceFileCount(resource)} {getResourceFileCount(resource) === 1 ? 'file' : 'files'}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  Updated {formatResourceDate(resource.updated_at)}
                </Typography>
                {tags.slice(0, 3).map((tag: any) => (
                  <Chip
                    key={tag.id ?? tag.label}
                    label={tag.label}
                    size="small"
                    sx={{ height: 20, borderRadius: '4px', fontSize: '0.75rem', bgcolor: 'grey.50' }}
                  />
                ))}
                {tags.length > 3 && (
                  <Typography variant="caption" color="text.disabled">
                    +{tags.length - 3}
                  </Typography>
                )}
              </Stack>
            </Stack>

            {/* Same rule as the grid card: no permission, no control. */}
            <Stack direction="row" spacing={0.5} justifyContent="flex-end">
              {mayUpdate && (
                <Tooltip title="Edit">
                  <IconButton
                    size="small"
                    onClick={(e) => handleEditResource(e, resource.id)}
                    aria-label={`Edit ${name}`}
                    sx={{ color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                  >
                    <Edit sx={{ fontSize: '1rem' }} />
                  </IconButton>
                </Tooltip>
              )}
              {mayDelete && (
                <Tooltip title="Delete">
                  <IconButton
                    size="small"
                    onClick={(e) => handleDeleteResource(e, resource.id)}
                    aria-label={`Delete ${name}`}
                    sx={{ color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                  >
                    <Delete sx={{ fontSize: '1rem' }} />
                  </IconButton>
                </Tooltip>
              )}
            </Stack>
          </Paper>
        )
      })}
    </Stack>
  )

  return (
    <Box
      sx={{
        flex: 1,
        display: 'flex',
        flexDirection: 'column',
        bgcolor: surfaces.canvas,
        width: '100%',
        border: LAYOUT_DEBUG ? '2px solid red' : 'none',
      }}
    >
      {/* Toolbar header strip — mirrors Sidebar filter header */}
      <Box
        sx={{
          px: 2,
          minHeight: '4rem',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          outline: LAYOUT_DEBUG ? '2px solid violet' : 'none',
        }}
      >
        {/* Left: view toggle + items per page */}
        <Stack direction="row" spacing={1.5} alignItems="center">
          <SegmentedChoice
            aria-label="Result layout"
            value={viewMode}
            onChange={setViewMode}
            segmentWidth="2.25rem"
            options={[
              { value: 'grid', label: <ViewModule fontSize="small" />, ariaLabel: 'Grid view' },
              { value: 'list', label: <ViewList fontSize="small" />, ariaLabel: 'List view' },
            ]}
          />

          <TextField
            select size="small" value={itemsPerPage}
            onChange={(e) => { setItemsPerPage(Number(e.target.value)); setCurrentPage(1) }}
            sx={{ minWidth: 70, '& .MuiOutlinedInput-root': { bgcolor: 'background.paper', fontSize: '0.875rem', height: '2rem' } }}
          >
            <MenuItem value={12}>12</MenuItem>
            <MenuItem value={24}>24</MenuItem>
            <MenuItem value={36}>36</MenuItem>
            <MenuItem value={48}>48</MenuItem>
          </TextField>

          {/* Re-picking the active key flips the direction, which is why the
              arrow rides on the label rather than being a separate control. */}
          <SegmentedChoice
            aria-label="Sort order"
            value={sortBy}
            onChange={handleSort}
            segmentWidth="4rem"
            options={SORTS.map(({ key, label }) => {
              const Arrow = sortDir === 'asc' ? ArrowUpward : ArrowDownward
              return {
                value: key,
                label: (
                  <>
                    {label}
                    {sortBy === key && <Arrow sx={{ fontSize: '0.875rem' }} />}
                  </>
                ),
              }
            })}
          />

          {/* "Select page" — available in both views now that selection is
              the basket, and phrased as the page it acts on rather than an
              ambiguous "visible". */}
          <Box
            component="label"
            sx={{
              display: 'flex',
              alignItems: 'center',
              gap: 0.25,
              color: 'text.secondary',
              fontSize: '0.8125rem',
              cursor: visibleResourceIds.length === 0 ? 'default' : 'pointer',
              userSelect: 'none',
            }}
          >
            <Tooltip title={allVisibleSelected ? 'Take this page out of the basket' : 'Put this page in the basket'}>
              <Checkbox
                size="small"
                checked={allVisibleSelected}
                indeterminate={someVisibleSelected}
                onChange={handleToggleVisibleSelected}
                disabled={visibleResourceIds.length === 0}
                inputProps={{ 'aria-label': 'Put this page in the basket' }}
                sx={{ p: 0.5 }}
              />
            </Tooltip>
            Select page
          </Box>
        </Stack>

        {/* Center: resource count */}
        <Typography
          color="text.secondary"
          sx={{
            fontSize: '1.25rem',
            fontWeight: 400,
          }}
        >
          {(collectionId || activeWorkspaceId) ? `${totalResources} ${totalResources === 1 ? 'resource' : 'resources'}` : ''}
        </Typography>

        {/* Right: actions — bulk actions live in the SelectionBar below, not
            here beside the create buttons. */}
        {!activeWorkspaceId && mayCreate && (
          // Permission decides whether these exist at all; having a collection
          // decides whether they work right now. The second is a precondition
          // the user can clear, so it disables and says so — previously both
          // buttons were live and clicking simply did nothing.
          <Stack direction="row" spacing={1}>
            <Tooltip title={collectionId ? '' : 'Choose a collection first — every resource belongs to one'}>
              <span>
                <Button
                  variant="outlined"
                  size="small"
                  startIcon={<Add />}
                  disabled={!collectionId}
                  onClick={handleNewMultimedia}
                >
                  New Resource
                </Button>
              </span>
            </Tooltip>
            <Tooltip title={collectionId ? '' : 'Choose a collection first — every resource belongs to one'}>
              <span>
                <Button
                  variant="contained"
                  size="small"
                  startIcon={<Add />}
                  disabled={!collectionId}
                  onClick={handleNewBatch}
                >
                  New Resources Wizard
                </Button>
              </span>
            </Tooltip>
          </Stack>
        )}
        {activeWorkspaceId && (
          <Stack direction="row" spacing={1}>
            {showAityStatus && (
              <Tooltip title="Apply AI-suggested names, descriptions, and tags to all resources in this workspace">
                <span>
                  <Button
                    variant="outlined"
                    size="small"
                    startIcon={autoApproving ? <CircularProgress size={14} color="inherit" /> : <AutoAwesome />}
                    onClick={handleAutoApprove}
                    disabled={autoApproving}
                    sx={{ borderColor: 'secondary.main', color: 'secondary.main', '&:hover': { borderColor: 'secondary.dark', bgcolor: 'secondary.subtle' } }}
                  >
                    Auto-approve all
                  </Button>
                </span>
              </Tooltip>
            )}
            <Button
              variant="contained"
              size="small"
              onClick={() => setAskOpen(true)}
              sx={{
                bgcolor: 'secondary.main',
                '&:hover': { bgcolor: 'secondary.dark' },
                color: 'common.white',
                display: 'flex',
                alignItems: 'center',
                gap: 0.75,
              }}
            >
              <TydalIsotype size={18} variant="white" />
              Ask aity
            </Button>
          </Stack>
        )}
      </Box>

      <NarrowDivider />

      {/* Scrollable content */}
      <Box
        sx={{
          flex: 1,
          overflow: 'auto',
          outline: LAYOUT_DEBUG ? '2px solid orange' : 'none',
        }}
      >
        <Box sx={{ px: 3, py: 3 }}>
          {/* Loading state */}
          {loading && (
            <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
              <CircularProgress size={40} />
            </Box>
          )}

          {/* Error state */}
          {error && (
            <Alert severity="error" sx={{ mb: 2 }}>
              {error}
            </Alert>
          )}

          {actionError && (
            <Alert severity="error" onClose={() => setActionError(null)} sx={{ mb: 2 }}>
              {actionError}
            </Alert>
          )}

          {showAityStatus && autoApproveError && (
            <Alert severity="error" onClose={() => setAutoApproveError(null)} sx={{ mb: 2 }}>
              Auto-approve failed: {autoApproveError}
            </Alert>
          )}

          {showAityStatus && autoApproveResult && !autoApproveLogOpen && (
            <Alert
              severity={autoApproveResult.dry_run ? 'info' : 'success'}
              action={
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Button color="inherit" size="small" onClick={() => setAutoApproveLogOpen(true)}>
                    View log
                  </Button>
                  <IconButton size="small" color="inherit" onClick={() => setAutoApproveResult(null)}>
                    <Close fontSize="small" />
                  </IconButton>
                </Stack>
              }
              sx={{ mb: 2 }}
            >
              {autoApproveResult.dry_run ? '[DRY RUN] ' : ''}
              Auto-approved {autoApproveResult.resources_processed} resource{autoApproveResult.resources_processed !== 1 ? 's' : ''} —
              {' '}{autoApproveResult.names_applied} name{autoApproveResult.names_applied !== 1 ? 's' : ''},
              {' '}{autoApproveResult.descriptions_applied} description{autoApproveResult.descriptions_applied !== 1 ? 's' : ''},
              {' '}{autoApproveResult.tags_applied} tag{autoApproveResult.tags_applied !== 1 ? 's' : ''} applied
              {autoApproveResult.tags_skipped > 0 ? ` (${autoApproveResult.tags_skipped} skipped)` : ''}.
            </Alert>
          )}

          {/* No collection or workspace selected */}
          {!loading && !collectionId && !activeWorkspaceId && (
            <Paper elevation={0} sx={{ p: 4, textAlign: 'center', border: '1px solid rgba(189, 201, 198, 0.25)', borderRadius: 1.5 }}>
              <Typography variant="body2" color="text.secondary">
                Select a collection to view resources
              </Typography>
            </Paper>
          )}

          {/* Resources content */}
          {!loading && (collectionId || activeWorkspaceId) && (
            <Stack spacing={2}>
              {/* Active filters */}
              {(totalActiveFilters > 0 || searchQuery !== '') && (
                <Box sx={{ outline: LAYOUT_DEBUG ? '2px solid cyan' : 'none' }}>
                  {/* Paired with the basket's SelectionBar below: same padding,
                      same radius, same left edge and right-hand action column.
                      The two are told apart by their BORDER, not their fill —
                      a neutral `divider` here vs `primary.light` there. Every
                      theme tints its grey ramp toward its own hue, so grey.100
                      and primary.subtle land within ΔE ~1 of each other in most
                      of them; the backgrounds cannot carry the distinction. */}
                  <Stack
                    direction="row"
                    alignItems="center"
                    spacing={1}
                    sx={{ py: 1, px: 2, bgcolor: 'grey.100', border: '1px solid', borderColor: 'divider', borderRadius: 1.5 }}
                  >
                    <Typography variant="body2" color="text.secondary" sx={{ fontWeight: 500 }}>
                      Active filters:
                    </Typography>
                    <Stack direction="row" spacing={1} flexWrap="wrap">
                      {searchQuery !== '' && (
                        <Chip
                          key="search-query"
                          label={`"${searchQuery}"`}
                          size="small"
                          onDelete={onSearchClear}
                          deleteIcon={<Close sx={{ fontSize: '0.875rem !important' }} />}
                          sx={{
                            bgcolor: CHIP_COLORS.search.light,
                            color: CHIP_COLORS.search.main,
                            '& .MuiChip-deleteIcon': { color: CHIP_COLORS.search.main, opacity: 0.6, '&:hover': { opacity: 1 } },
                          }}
                        />
                      )}
                      {Object.entries(activeFilters).map(([facetKey, values]) =>
                        values.map((rawValue) => {
                          let displayLabel = rawValue
                          let EntityIcon: React.ComponentType<{ sx?: any }> | undefined
                          let entityColor: string | undefined

                          if (facetKey === 'semantic_tags') {
                            const sepIdx = rawValue.indexOf('||')
                            if (sepIdx >= 0) {
                              displayLabel = rawValue.slice(0, sepIdx)
                              const iconKey = rawValue.slice(sepIdx + 2)
                              const et = iconKey in ENTITY_TYPES ? ENTITY_TYPES[iconKey as EntityTypeKey] : null
                              if (et) { EntityIcon = et.Icon; entityColor = et.color }
                            }
                          }

                          return (
                            <Chip
                              key={`${facetKey}-${rawValue}`}
                              label={
                                EntityIcon && entityColor ? (
                                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                                    <Box sx={{ width: 12, height: 12, borderRadius: 0.5, bgcolor: entityColor, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                                      <EntityIcon sx={{ fontSize: '0.75rem', color: 'white' }} />
                                    </Box>
                                    {displayLabel}
                                  </Box>
                                ) : displayLabel
                              }
                              size="small"
                              onDelete={() => onFilterRemove?.(facetKey, rawValue)}
                              deleteIcon={<Close sx={{ fontSize: '0.875rem !important' }} />}
                              sx={{
                                bgcolor: 'info.light',
                                color: 'info.main',
                                '& .MuiChip-deleteIcon': { color: 'info.main', opacity: 0.6, '&:hover': { opacity: 1 } },
                              }}
                            />
                          )
                        })
                      )}
                    </Stack>
                    <Button
                      variant="text"
                      size="small"
                      onClick={() => { onFiltersClear?.(); onSearchClear?.() }}
                      // ml:auto rather than a fixed gap — puts it in the same
                      // right-hand action column as the basket bar's controls,
                      // and keeps it there however many chips wrap on the left.
                      sx={{ ml: 'auto', flexShrink: 0, color: 'primary.main' }}
                    >
                      Clear all
                    </Button>
                  </Stack>
                </Box>
              )}

              {searchMode === 'prefix' && !hasLexicalMatches && resources.length > 0 && (
                <Alert severity="info" sx={{ py: 0.5 }}>
                  No exact matches found — showing smart results
                </Alert>
              )}

              {/* Resource grid/list */}
              <Box sx={{ outline: LAYOUT_DEBUG ? '2px solid blue' : 'none' }}>
                <SelectionBar
                  visibleIds={visibleResourceIds}
                  onChanged={() => setRefreshTrigger((prev) => prev + 1)}
                />
                <Paper
                  elevation={0}
                  sx={{ width: '100%', p: 2, minHeight: 400, bgcolor: surfaces.canvas }}
                >
                  {resources.length > 0 && viewMode === 'grid' ? (
                    <Grid container spacing={3} justifyContent="center">
                      {resources.map((resource) => (
                        <Grid
                          item
                          xs={12}
                          sm={6}
                          md={6}
                          lg={5}
                          xl={4}
                          key={resource.id}
                        >
                          <ResourceCard
                            resource={resource}
                            onClick={() => handleResourceClick(resource.id)}
                            onEdit={mayUpdate ? (e) => handleEditResource(e, resource.id) : undefined}
                            onDelete={mayDelete ? (e) => handleDeleteResource(e, resource.id) : undefined}
                            highlighted={String(resource.id) === lastInteractedId}
                            showAityStatus={showAityStatus}
                            selected={basket.has(String(resource.id))}
                            onSelectToggle={() => basket.toggle(String(resource.id))}
                            selectionActive={basket.count > 0}
                          />
                        </Grid>
                      ))}
                    </Grid>
                  ) : resources.length > 0 ? (
                    renderResourceList()
                  ) : (
                    <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: 400 }}>
                      <Typography variant="body1" color="text.secondary">
                        {activeWorkspaceId ? 'No resources in this workspace' : 'No resources found in this collection'}
                      </Typography>
                    </Box>
                  )}
                </Paper>
              </Box>

              {/* Pagination */}
              {totalPages > 1 && (
                <Stack direction="row" justifyContent="center" alignItems="center" spacing={1} sx={{ py: 1, outline: LAYOUT_DEBUG ? '2px solid magenta' : 'none' }}>
                  <IconButton size="small" onClick={() => handlePageChange(currentPage - 1)} disabled={currentPage === 1}>
                    <ChevronLeft fontSize="small" />
                  </IconButton>
                  <Typography variant="caption" color="text.secondary">
                    Page <strong>{currentPage}</strong> of <strong>{totalPages}</strong>
                  </Typography>
                  <IconButton size="small" onClick={() => handlePageChange(currentPage + 1)} disabled={currentPage === totalPages}>
                    <ChevronRight fontSize="small" />
                  </IconButton>
                </Stack>
              )}
            </Stack>
          )}

          {/* Resource Detail Modal (view / edit / create) */}
          {(selectedResourceId !== null || detailModalMode === 'create') && (
            <ResourceDetailModal
              resourceId={selectedResourceId}
              open={detailModalOpen}
              onClose={handleModalClose}
              mode={detailModalMode}
              collectionId={collectionId}
              onResourceSaved={handleResourceSaved}
            />
          )}

          {/* RAG Ask AI dialog — workspace-scoped */}
          {activeWorkspaceId && (
            <WorkspaceAskDialog
              open={askOpen}
              workspaceId={activeWorkspaceId}
              workspaceName={activeWorkspaceName ?? undefined}
              onClose={() => setAskOpen(false)}
            />
          )}

          {/* Single-file AiTy creation wizard */}
          {collectionId && (
            <ResourceWizard
              open={wizardOpen}
              collectionId={collectionId}
              onClose={(refreshNeeded) => {
                setWizardOpen(false)
                if (refreshNeeded) {
                  onRefresh?.()
                  onWorkspaceCreated?.()
                }
              }}
              onSaved={(resource) => {
                setWizardOpen(false)
                handleResourceSaved(resource)
              }}
            />
          )}

          {/* Delete Confirmation Dialog */}
          <Dialog
            open={deleteConfirm.open}
            onClose={handleCancelDelete}
            maxWidth="sm"
            fullWidth
          >
            <DialogTitle>Confirm Delete</DialogTitle>
            <DialogContent>
              <Typography variant="body1">
                {deleteConfirm.resourceIds.length > 1
                  ? <>Delete <strong>{deleteConfirm.resourceIds.length}</strong> selected resources?</>
                  : <>Are you sure you want to delete <strong>"{deleteConfirm.resourceName}"</strong>?</>}
              </Typography>
              <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
                This action cannot be undone.
              </Typography>
            </DialogContent>
            <DialogActions>
              <Button onClick={handleCancelDelete} disabled={deleting}>
                Cancel
              </Button>
              <Button
                onClick={handleConfirmDelete}
                color="error"
                variant="contained"
                disabled={deleting}
                startIcon={deleting ? <CircularProgress size={14} color="inherit" /> : undefined}
              >
                Delete
              </Button>
            </DialogActions>
          </Dialog>

          {/* Auto-approve log dialog */}
          <Dialog
            open={autoApproveLogOpen}
            onClose={() => setAutoApproveLogOpen(false)}
            maxWidth="md"
            fullWidth
          >
            <DialogTitle sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', pb: 1 }}>
              <Stack direction="row" spacing={1} alignItems="center">
                {autoApproving && <CircularProgress size={16} color="inherit" />}
                <span>
                  Auto-approve{autoApproving ? ' — running…' : autoApproveResult ? ' — done' : ''}
                </span>
              </Stack>
              <IconButton size="small" onClick={() => setAutoApproveLogOpen(false)}>
                <Close fontSize="small" />
              </IconButton>
            </DialogTitle>
            <DialogContent dividers sx={{ p: 0 }}>
              <Box
                sx={{
                  m: 0,
                  p: 2,
                  fontFamily: 'monospace',
                  fontSize: '0.78rem',
                  lineHeight: 1.6,
                  color: 'text.primary',
                  bgcolor: 'grey.50',
                  whiteSpace: 'pre-wrap',
                  wordBreak: 'break-word',
                  maxHeight: '65vh',
                  overflow: 'auto',
                }}
              >
                {autoApproveLogLines.join('\n')}
                {autoApproving && (
                  <Box component="span" sx={{ display: 'inline-block', width: 8, height: '1em', bgcolor: 'text.secondary', ml: 0.5, animation: 'blink 1s step-end infinite', '@keyframes blink': { '50%': { opacity: 0 } } }} />
                )}
                <div ref={autoApproveLogEndRef} />
              </Box>
            </DialogContent>
            {autoApproveError && (
              <Alert severity="error" sx={{ borderRadius: 0, borderTop: '1px solid', borderColor: 'divider' }}>
                {autoApproveError}
              </Alert>
            )}
            <DialogActions>
              <Button onClick={() => setAutoApproveLogOpen(false)}>Close</Button>
            </DialogActions>
          </Dialog>
        </Box>
      </Box>
    </Box>
  )
}

export default MainContent
