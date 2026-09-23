import { useState } from 'react'
import { useLocation } from 'react-router-dom'
import { Stack } from '@mui/material'
import Header from '../components/layout/Header'
import Sidebar from '../components/layout/Sidebar'
import MainContent from '../components/layout/MainContent'
import type { Facet } from '../api/resourceService'
import type { Workspace } from '../api/workspaceService'

/**
 * TYDAL HomePage Component
 *
 * Main dashboard page with header, collapsible sidebar, and content area.
 *
 * @example
 * ```tsx
 * <HomePage />
 * ```
 */
function HomePage() {
  const location = useLocation()
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)

  // Pre-select a workspace when navigated here from the AiTy Review dashboard
  const navState = location.state as { workspaceId?: string; workspaceName?: string; workspacePurpose?: string } | null
  const [activeCollectionId, setActiveCollectionId] = useState<number | null>(null)
  const [activeWorkspaceId, setActiveWorkspaceId] = useState<string | null>(navState?.workspaceId ?? null)
  const [activeWorkspaceName, setActiveWorkspaceName] = useState<string | null>(navState?.workspaceName ?? null)
  const [activeWorkspacePurpose, setActiveWorkspacePurpose] = useState<string | null>(navState?.workspacePurpose ?? null)
  const [activeFilters, setActiveFilters] = useState<Record<string, string[]>>({})
  const [searchQuery, setSearchQuery] = useState<string>('')
  const [searchMode, setSearchMode] = useState<'prefix' | 'contains' | 'exact'>('prefix')
  const [catalogueVersion, setCatalogueVersion] = useState(0)
  const [workspacesVersion, setWorkspacesVersion] = useState(0)
  const [catalogueFacets, setCatalogueFacets] = useState<Facet[] | null>(null)

  const toggleSidebar = () => {
    setSidebarCollapsed((prev) => !prev)
  }

  const handleFiltersChange = (filters: Record<string, string[]>) => {
    setActiveFilters(filters)
  }

  const handleSearchChange = (query: string) => {
    setSearchQuery(query)
  }

  const handleCollectionChange = (id: number) => {
    setActiveCollectionId(id)
    setActiveWorkspaceId(null)
    setActiveWorkspaceName(null)
    setActiveWorkspacePurpose(null)
  }

  const handleWorkspaceChange = (id: string, name?: string, purpose?: string | null) => {
    setActiveWorkspaceId(id)
    setActiveWorkspaceName(name ?? null)
    setActiveWorkspacePurpose(purpose ?? null)
    setActiveCollectionId(null)
    setActiveFilters({})
    setSearchQuery('')
  }

  /**
   * A workspace was created, renamed or deleted in the manager.
   *
   * The catalogue has to be refetched: resource cards carry workspace chips,
   * and the facet list is derived from the same response, so neither corrects
   * itself otherwise — a deleted workspace kept showing on every card that had
   * belonged to it. If the workspace being viewed is the one that went, fall
   * back to no workspace rather than querying a dead id.
   */
  const handleWorkspacesChanged = (workspaces: Workspace[]) => {
    if (activeWorkspaceId && !workspaces.some((ws) => String(ws.id) === String(activeWorkspaceId))) {
      setActiveWorkspaceId(null)
      setActiveWorkspaceName(null)
      setActiveWorkspacePurpose(null)
      setActiveFilters({})
    }
    setCatalogueVersion((v) => v + 1)
  }

  const handleOrganizationChange = () => {
    setActiveCollectionId(null)
    setActiveWorkspaceId(null)
    setActiveWorkspaceName(null)
    setActiveWorkspacePurpose(null)
    setActiveFilters({})
    setSearchQuery('')
    setSearchMode('prefix')
  }

  return (
    <Stack sx={{ minHeight: '100vh' }}>
      {/* Header */}
      <Header
        activeCollectionId={activeCollectionId}
        onCollectionChange={handleCollectionChange}
        activeWorkspaceId={activeWorkspaceId}
        onWorkspaceChange={handleWorkspaceChange}
        onOrganizationChange={handleOrganizationChange}
        searchQuery={searchQuery}
        onSearchChange={handleSearchChange}
        searchMode={searchMode}
        onSearchModeChange={setSearchMode}
        refreshWorkspacesTrigger={workspacesVersion}
        onWorkspacesChanged={handleWorkspacesChanged}
      />

      {/* Main layout: Sidebar + Content */}
      <Stack direction="row" sx={{ flex: 1 }}>
        {/* Sidebar — shows collection or workspace facets depending on what's active */}
        <Sidebar
          collapsed={sidebarCollapsed}
          width={280}
          collapsedWidth={48}
          onToggle={toggleSidebar}
          collectionId={activeCollectionId}
          workspaceId={activeWorkspaceId}
          searchQuery={searchQuery}
          searchMode={searchMode}
          activeFilters={activeFilters}
          onFiltersChange={handleFiltersChange}
          refreshTrigger={catalogueVersion}
          externalFacets={catalogueFacets}
        />

        {/* Main Content */}
        <MainContent
          collectionId={activeCollectionId}
          activeWorkspaceId={activeWorkspaceId}
          activeWorkspaceName={activeWorkspaceName}
          showAityStatus={activeWorkspacePurpose === 'aity_review'}
          searchQuery={searchQuery}
          searchMode={searchMode}
          activeFilters={activeFilters}
          refreshTrigger={catalogueVersion}
          onFilterRemove={(facetKey, label) => {
            const updated = { ...activeFilters }
            if (updated[facetKey]) {
              updated[facetKey] = updated[facetKey].filter((l) => l !== label)
              if (updated[facetKey].length === 0) {
                delete updated[facetKey]
              }
            }
            setActiveFilters(updated)
          }}
          onFiltersClear={() => {
            setActiveFilters({})
          }}
          onSearchClear={() => {
            setSearchQuery('')
          }}
          onRefresh={() => setCatalogueVersion(v => v + 1)}
          onWorkspaceCreated={() => setWorkspacesVersion(v => v + 1)}
          onFacetsLoaded={setCatalogueFacets}
        />
      </Stack>
    </Stack>
  )
}

export default HomePage
