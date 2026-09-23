import React, { useState, useEffect, type ReactNode } from 'react'
import tydalLogo from '../../assets/tydal-logo.png'
import { useNavigate, useLocation } from 'react-router-dom'
import {
  Badge,
  Box,
  Chip,
  Popover,
  Stack,
  Typography,
  TextField,
  IconButton as MuiIconButton,
  Button,
  InputAdornment,
  Menu,
  MenuItem,
  Select,
  CircularProgress,
  Tooltip,
  ListItemIcon,
  ListItemText,
  Divider,
} from '@mui/material'
import { AdminPanelSettings, AutoAwesome, Search, FolderOpen, Check, Dashboard, Groups, NotificationsOutlined, ShoppingBasket, WorkspacesOutlined, MoreVert, HexagonOutlined, ContentCopy, OpenInNew } from '@mui/icons-material'
import { useNotifications } from '../../hooks/useNotifications'
import { icons } from '../ui/iconMap'
import authService from '../../api/authService'
import organizationService from '../../api/organizationService'
import collectionService, { type Collection } from '../../api/collectionService'
import workspaceService, { type Workspace } from '../../api/workspaceService'
import vaultService, { type VaultConfig, PUBLIC_BASE } from '../../api/vaultService'
import WorkspacesManagerDialog from '../modals/WorkspacesManagerDialog'
import type { Organization, User } from '../../api/authService'
import { useColorTheme, useSurfaces, type ColorThemeName, type SurfaceScheme, type TransparencyBackdrop, THEME_LABELS, SURFACE_LABELS, SURFACE_SCHEMES, TRANSPARENCY_LABELS, TRANSPARENCY_BACKDROPS, themes } from '../../contexts/ThemeContext'
import { useBasket } from '../../contexts/BasketContext'
import { canManageOrg, isPlatformAdmin, roleLabel } from '../../constants/roles'
import { invalidatePermissions } from '../../hooks/usePermissions'

export interface HeaderProps {
  activeCollectionId?: number | null
  onCollectionChange?: (collectionId: number) => void
  activeWorkspaceId?: string | null
  onWorkspaceChange?: (workspaceId: string, workspaceName?: string, workspacePurpose?: string | null) => void
  onOrganizationChange?: () => void
  searchQuery?: string
  onSearchChange?: (query: string) => void
  searchMode?: 'prefix' | 'contains' | 'exact'
  onSearchModeChange?: (mode: 'prefix' | 'contains' | 'exact') => void
  /** Hide the search bar (e.g. in admin pages) */
  hideSearch?: boolean
  /** Hide the collection tabs row (e.g. in admin pages) */
  hideCollections?: boolean
  /** Optional title shown in the center slot when hideSearch is true */
  title?: string
  /** Optional content rendered in a second row with the same surface styling */
  secondRow?: ReactNode
  /** Increment to force the workspace list to re-fetch (e.g. after a wizard creates a new workspace) */
  refreshWorkspacesTrigger?: number
  /**
   * The workspace list changed here (created, renamed, deleted). The page owns
   * the catalogue, so it has to be told — otherwise the grid keeps rendering
   * workspace chips for a workspace that no longer exists. Receives the fresh
   * list so the page can also notice the workspace it is currently showing was
   * the one deleted.
   */
  onWorkspacesChanged?: (workspaces: Workspace[]) => void
}

function humanizeNotificationType(type: string): string {
  const base = type.split('\\').pop() ?? type
  return base
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/_/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
}

function Header(props: HeaderProps) {
  const { activeCollectionId, onCollectionChange, activeWorkspaceId, onWorkspaceChange, onOrganizationChange, searchQuery = '', onSearchChange, searchMode = 'prefix', onSearchModeChange, hideSearch = false, hideCollections = false, title, secondRow, refreshWorkspacesTrigger, onWorkspacesChanged } = props
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const isAdmin = pathname.startsWith('/admin')
  const isTrash = pathname === '/trash'
  const isBasket = pathname === '/basket'
  const isOrgSettings = pathname === '/organization'
  const basket = useBasket()
  const { colorTheme, setColorTheme, surfaceScheme, setSurfaceScheme, transparencyBackdrop, setTransparencyBackdrop } = useColorTheme()
  const surfaces = useSurfaces()
  const [anchorEl, setAnchorEl] = useState<null | HTMLElement>(null)
  const [orgAnchorEl, setOrgAnchorEl] = useState<null | HTMLElement>(null)
  const [settingsAnchorEl, setSettingsAnchorEl] = useState<null | HTMLElement>(null)
  const [bellAnchorEl, setBellAnchorEl] = useState<null | HTMLElement>(null)
  const menuOpen = Boolean(anchorEl)
  const orgMenuOpen = Boolean(orgAnchorEl)
  const settingsMenuOpen = Boolean(settingsAnchorEl)
  const bellOpen = Boolean(bellAnchorEl)
  const [workspacesManagerOpen, setWorkspacesManagerOpen] = useState(false)
  const [inputValue, setInputValue] = useState(searchQuery)

  // Sync local input when parent resets searchQuery (collection change, chip clear, etc.)
  useEffect(() => {
    setInputValue(searchQuery)
  }, [searchQuery])

  const { notifications, unreadCount, markRead, markAllRead, refresh: refreshNotifications } = useNotifications()

  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const [collections, setCollections] = useState<Collection[]>([])
  const [workspaces, setWorkspaces] = useState<Workspace[]>([])
  const [organizations, setOrganizations] = useState<Organization[]>([])
  const [activeOrganization, setActiveOrganization] = useState<string | null>(null)

  useEffect(() => {
    const fetchUserData = async () => {
      try {
        const userData = await authService.getUser()
        if (userData?.data) {
          setUser(userData.data)

          // A platform admin reaches every organization, so their switcher is
          // built from GET /organizations (which returns all of them for a
          // superadmin) rather than from their own memberships. Previously the
          // full list was only fetched when they had NO memberships at all —
          // so a platform admin who belonged to one organization could see
          // only that one and had no way to reach the others.
          const memberships = userData.data.organizations ?? []
          const orgsData = isPlatformAdmin(userData.data) || memberships.length === 0
            ? await organizationService.getOrganizations()
            : memberships

          if (orgsData && orgsData.length > 0) {
            setOrganizations(orgsData)
            const initialOrgId = userData.data.current_organization_id || orgsData[0]?.id
            if (initialOrgId) {
              setActiveOrganization(String(initialOrgId))
            }
          }
        }

        // `user` state has not landed yet at this point, so the check reads
        // from the payload we just fetched rather than from the hook.
        const mayManage = canManageOrg(userData?.data)

        const [collectionsData, workspacesData] = await Promise.all([
          collectionService.getCollections(),
          workspaceService.getWorkspaces({ includeSystem: mayManage }),
        ])
        if (collectionsData) {
          setCollections(collectionsData)
          const initialCollectionId = collectionsData?.[0]?.id || null
          if (initialCollectionId && onCollectionChange && !activeWorkspaceId) {
            onCollectionChange(typeof initialCollectionId === 'number' ? initialCollectionId : parseInt(initialCollectionId as string, 10))
          }
        }
        if (workspacesData) {
          setWorkspaces(workspacesData)
        }
      } catch (error) {
        console.error('Failed to fetch user data:', error)
      } finally {
        setLoading(false)
      }
    }
    fetchUserData()
  }, [])

  const handleMenuOpen = (event: React.MouseEvent<HTMLElement>) => setAnchorEl(event.currentTarget)
  const handleMenuClose = () => setAnchorEl(null)
  const handleOrgMenuOpen = (event: React.MouseEvent<HTMLElement>) => setOrgAnchorEl(event.currentTarget)
  const handleOrgMenuClose = () => setOrgAnchorEl(null)

  const handleSwitchOrganization = async (org: Organization) => {
    handleOrgMenuClose()
    try {
      setCollections([])
      onOrganizationChange?.()
      await organizationService.switchOrganization(org.id)
      setActiveOrganization(String(org.id))
      // The basket is scoped per user AND organization — resources from the
      // org you just left cannot be acted on here, so it reloads for the new one.
      basket.rescope()

      // Your role is per-organization, so it changes with the organization.
      // Without this re-fetch `current_organization_role` still described the
      // one you just left, and every gate derived from it — the manage-
      // workspaces control, the organization settings button — stayed wrong
      // until a full page reload.
      invalidatePermissions()
      const refreshed = await authService.getUser()
      if (refreshed?.data) setUser(refreshed.data)
      const mayManage = canManageOrg(refreshed?.data)

      const [collectionsData, workspacesData] = await Promise.all([
        collectionService.getCollections(),
        workspaceService.getWorkspaces({ includeSystem: mayManage }),
      ])
      if (collectionsData) {
        setCollections(collectionsData)
        const newCollectionId = collectionsData[0]?.id || null
        if (newCollectionId && onCollectionChange) {
          onCollectionChange(typeof newCollectionId === 'number' ? newCollectionId : parseInt(newCollectionId as string, 10))
        }
      }
      if (workspacesData) {
        setWorkspaces(workspacesData)
      }
    } catch (error) {
      console.error('Failed to switch organization:', error)
    }
  }

  const handleLogout = () => { handleMenuClose(); authService.logout() }

  const getActiveOrganizationName = () => {
    if (!user) return 'Loading...'
    if (activeOrganization) {
      const org = organizations.find((o) => String(o.id) === activeOrganization)
      if (org?.name) return org.name
    }
    if (user.current_organization_id) {
      const org = organizations.find((o) => String(o.id) === String(user.current_organization_id))
      if (org?.name) return org.name
    }
    return 'Unknown'
  }

  // One predicate, from src/constants/roles.ts. This used to be written out
  // twice in this file against a role ('org-admin') that could never exist.
  const canManageWorkspaces = canManageOrg(user)

  /**
   * The tabs are browsing scopes, so machine-managed workspaces are filtered
   * out of them — but they stay in `workspaces`, because the manager dialog is
   * where an admin is meant to discover that they exist.
   */
  const browsableWorkspaces = workspaces.filter((ws) => !ws.is_system)

  const handleWorkspacesChanged = async () => {
    const data = await workspaceService.getWorkspaces({ includeSystem: canManageWorkspaces })
    if (data) {
      setWorkspaces(data)
      onWorkspacesChanged?.(data)
    }
    loadVaultsForWorkspace(activeWorkspaceId)
  }

  // ── Vault badge — which vaults project the active workspace ────────────────
  const [wsVaults, setWsVaults] = useState<VaultConfig[]>([])
  const [vaultsAnchor, setVaultsAnchor] = useState<HTMLElement | null>(null)

  const loadVaultsForWorkspace = (wsId?: string | null) => {
    if (!wsId) {
      setWsVaults([])
      return
    }
    vaultService.workspace.listVaults(wsId)
      .then(res => setWsVaults(res.data.vaults))
      .catch(() => setWsVaults([]))
  }

  useEffect(() => {
    loadVaultsForWorkspace(activeWorkspaceId)
  }, [activeWorkspaceId])

  const currentOrgSlug = organizations.find(
    (o) => String(o.id) === String(user?.current_organization_id)
  )?.slug

  const vaultHumanUrl = (vault: VaultConfig) =>
    currentOrgSlug ? `${PUBLIC_BASE}/v/${currentOrgSlug}/${vault.slug}` : `${PUBLIC_BASE}/h/${vault.hash}`

  const copyText = (text: string) => { navigator.clipboard?.writeText(text).catch(() => {}) }

  useEffect(() => {
    if (refreshWorkspacesTrigger) handleWorkspacesChanged()
  }, [refreshWorkspacesTrigger])

  const simplifyCollectionName = (collectionName: string): string => {
    const orgName = getActiveOrganizationName()
    let simplified = collectionName
    if (orgName !== 'Loading...' && orgName !== 'Unknown') {
      const escapedOrgName = orgName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
      const prefixPattern = new RegExp(`^${escapedOrgName}\\s*(?:[-:–—/|]\\s*)?`, 'i')
      simplified = simplified.replace(prefixPattern, '')
    }
    simplified = simplified.replace(/collection$/i, '').trim()
    return simplified
  }

  return (
    <Box
      component="header"
      sx={{
        position: 'sticky',
        top: 0,
        zIndex: 1100,
        bgcolor: surfaces.headerBg,
        backdropFilter: surfaces.headerBlur ? 'blur(24px)' : 'none',
        WebkitBackdropFilter: surfaces.headerBlur ? 'blur(24px)' : 'none',
        borderBottom: '1px solid rgba(228, 228, 231, 0.15)',
        boxShadow: '0 1px 2px rgba(9, 9, 11, 0.05)',
      }}
    >
      {/* Top row: Logo + Search + User controls */}
      <Stack direction="row" alignItems="center" sx={{ px: 2, height: 62 }}>
        {/* Logo */}
        <Stack
          direction="row" alignItems="center" spacing={1}
          onClick={() => navigate('/')}
          sx={{ flex: 1, cursor: 'pointer', width: 'fit-content' }}
        >
          <Box component="img" src={tydalLogo} alt="TYDAL" sx={{ height: 48, width: 'auto', display: 'block' }} />
        </Stack>

        {/* Search / title */}
        <Box sx={{ flex: 1, maxWidth: 560, display: 'flex', justifyContent: 'center' }}>
          {!hideSearch ? (
            <Stack direction="row" spacing={1} alignItems="center" sx={{ width: '100%' }}>
              <TextField
                placeholder="Search resources..."
                size="small"
                value={inputValue}
                onChange={(e) => setInputValue(e.target.value)}
                onKeyDown={(e) => { if (e.key === 'Enter') onSearchChange?.(inputValue) }}
                onBlur={() => { if (inputValue !== searchQuery) onSearchChange?.(inputValue) }}
                sx={{ flex: 1, '& .MuiOutlinedInput-root': { bgcolor: 'background.paper', fontSize: '0.875rem' } }}
                slotProps={{
                  input: {
                    startAdornment: (
                      <InputAdornment position="start">
                        <Search sx={{ color: 'text.secondary', fontSize: '1rem' }} />
                      </InputAdornment>
                    ),
                    endAdornment: inputValue ? (
                      <InputAdornment position="end">
                        <MuiIconButton size="small" sx={{ p: 0.5 }} onClick={() => { setInputValue(''); onSearchChange?.('') }} aria-label="Clear search">
                          {icons['close'] && React.createElement(icons['close'] as React.ElementType, { fontSize: 'small' })}
                        </MuiIconButton>
                      </InputAdornment>
                    ) : null,
                  },
                }}
              />
              {!activeWorkspaceId && (
                <Select
                  size="small"
                  value={searchMode}
                  onChange={(e) => onSearchModeChange?.(e.target.value as 'prefix' | 'contains' | 'exact')}
                  sx={{ minWidth: 110, bgcolor: 'background.paper', fontSize: '0.875rem' }}
                >
                  <MenuItem value="prefix" sx={{ fontSize: '0.875rem' }}>Smart</MenuItem>
                  <MenuItem value="contains" sx={{ fontSize: '0.875rem' }}>Contains</MenuItem>
                  <MenuItem value="exact" sx={{ fontSize: '0.875rem' }}>Exact</MenuItem>
                </Select>
              )}
            </Stack>
          ) : title ? (
            <Typography variant="body2" sx={{ fontWeight: 500, color: 'text.secondary' }}>
              {title}
            </Typography>
          ) : null}
        </Box>

        {/* Right controls */}
        <Stack direction="row" alignItems="center" spacing={1} sx={{ flex: 1, justifyContent: 'flex-end' }}>
          {/* The two administration surfaces are different scopes: the platform
              one runs the installation, the organization one runs this tenant.
              A platform admin sees both; an org owner/admin sees only theirs. */}
          {isAdmin || isTrash || isOrgSettings ? (
            <Tooltip title="Back to dashboard">
              <Button
                variant="outlined"
                size="small"
                onClick={() => navigate('/')}
                sx={{ minWidth: 0, p: 0.75, borderRadius: 1.5, borderColor: 'primary.light' }}
              >
                <Dashboard sx={{ fontSize: '1.25rem' }} />
              </Button>
            </Tooltip>
          ) : (
            <>
              {canManageWorkspaces && (
                <Tooltip title="Organization settings">
                  <Button
                    variant="outlined"
                    size="small"
                    onClick={() => navigate('/organization')}
                    sx={{ minWidth: 0, p: 0.75, borderRadius: 1.5, borderColor: 'primary.light' }}
                  >
                    <Groups sx={{ fontSize: '1.25rem' }} />
                  </Button>
                </Tooltip>
              )}
              {isPlatformAdmin(user) && (
                <Tooltip title="Platform administration">
                  <Button
                    variant="outlined"
                    size="small"
                    onClick={() => navigate('/admin')}
                    sx={{ minWidth: 0, p: 0.75, borderRadius: 1.5, borderColor: 'primary.light' }}
                  >
                    <AdminPanelSettings sx={{ fontSize: '1.25rem' }} />
                  </Button>
                </Tooltip>
              )}
            </>
          )}

          {/* Organization selector */}
          {organizations.length > 0 && (
            <Box
              onClick={organizations.length > 1 ? handleOrgMenuOpen : undefined}
              sx={{
                display: 'flex', alignItems: 'center', gap: 0.25,
                cursor: organizations.length > 1 ? 'pointer' : 'default',
                px: 1, py: 0.5, borderRadius: 1,
                '&:hover': organizations.length > 1 ? { bgcolor: 'grey.100' } : {},
              }}
            >
              <Typography variant="body2" noWrap sx={{ fontWeight: 500, color: 'text.secondary', fontSize: '0.875rem' }}>
                {getActiveOrganizationName()}
              </Typography>
              {organizations.length > 1 && (
                <MuiIconButton size="small" sx={{ p: 0, '&:hover': { bgcolor: 'transparent' } }}>
                  {React.createElement(icons['expand-more'] as React.ElementType, { fontSize: 'small' })}
                </MuiIconButton>
              )}
            </Box>
          )}

          {/* Basket — present on every page, because a selection that survives
              navigation has to be reachable (and inspectable) from anywhere. */}
          {basket.count > 0 && (
            <Tooltip title={`${basket.count} in the basket — click to review`}>
              <MuiIconButton
                size="small"
                aria-label={`Basket, ${basket.count} resources`}
                onClick={() => navigate('/basket')}
                sx={{ p: 0.5, color: isBasket ? 'primary.main' : 'text.secondary' }}
              >
                <Badge
                  badgeContent={basket.count}
                  color="primary"
                  max={999}
                  sx={{ '& .MuiBadge-badge': { fontSize: '0.625rem', height: 16, minWidth: 16 } }}
                >
                  <ShoppingBasket sx={{ fontSize: '1.25rem' }} />
                </Badge>
              </MuiIconButton>
            </Tooltip>
          )}

          {/* Notification bell */}
          <Tooltip title="Notifications">
            <MuiIconButton
              size="small"
              aria-label="Notifications"
              onClick={(e) => { setBellAnchorEl(e.currentTarget); refreshNotifications() }}
              sx={{ p: 0.5, color: 'text.secondary' }}
            >
              <Badge
                variant="dot"
                color="error"
                invisible={unreadCount === 0}
                sx={{ '& .MuiBadge-dot': { width: 7, height: 7, minWidth: 7 } }}
              >
                <NotificationsOutlined sx={{ fontSize: '1.25rem' }} />
              </Badge>
            </MuiIconButton>
          </Tooltip>

          {/* Notification popover */}
          <Popover
            open={bellOpen}
            anchorEl={bellAnchorEl}
            onClose={() => setBellAnchorEl(null)}
            anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
            transformOrigin={{ vertical: 'top', horizontal: 'right' }}
            slotProps={{ paper: { sx: { width: 360, maxHeight: 480, display: 'flex', flexDirection: 'column' } } }}
          >
            {/* Header row */}
            <Box sx={{ px: 2, py: 1.5, display: 'flex', alignItems: 'center', justifyContent: 'space-between', borderBottom: 1, borderColor: 'divider' }}>
              <Typography variant="subtitle2" fontWeight={600}>
                Notifications
                {unreadCount > 0 && (
                  <Chip label={unreadCount} size="small" color="error" sx={{ ml: 1, height: 18, fontSize: '0.7rem', '& .MuiChip-label': { px: 0.75 } }} />
                )}
              </Typography>
              {unreadCount > 0 && (
                <Button size="small" variant="text" sx={{ fontSize: '0.75rem', p: 0.5 }} onClick={markAllRead}>
                  Mark all read
                </Button>
              )}
            </Box>

            {/* Notification list */}
            <Box sx={{ flex: 1, overflowY: 'auto' }}>
              {notifications.length === 0 ? (
                <Box sx={{ p: 3, textAlign: 'center' }}>
                  <Typography variant="body2" color="text.secondary">No notifications yet</Typography>
                </Box>
              ) : (
                notifications.map((n) => {
                  const isUnread = !n.read_at
                  const title    = (n.data?.title as string | undefined) ?? humanizeNotificationType(n.type)
                  const message  = n.data?.message as string | undefined
                  return (
                    <Box
                      key={n.id}
                      onClick={() => markRead(n.id)}
                      sx={{
                        px: 2, py: 1.25,
                        borderBottom: 1, borderColor: 'divider',
                        cursor: isUnread ? 'pointer' : 'default',
                        bgcolor: isUnread ? 'warning.50' : 'background.paper',
                        '&:hover': isUnread ? { bgcolor: 'warning.100' } : {},
                        display: 'flex', gap: 1.5, alignItems: 'flex-start',
                      }}
                    >
                      <NotificationsOutlined sx={{ fontSize: '1rem', color: 'text.secondary', mt: 0.25, flexShrink: 0 }} />
                      <Box sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="body2" fontWeight={isUnread ? 600 : 400} noWrap>
                          {title}
                        </Typography>
                        {message && (
                          <Typography variant="caption" color="text.secondary" display="block">
                            {message}
                          </Typography>
                        )}
                        <Typography variant="caption" color="text.disabled" display="block">
                          {new Intl.DateTimeFormat(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit' }).format(new Date(n.created_at))}
                        </Typography>
                      </Box>
                      {isUnread && (
                        <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'error.main', flexShrink: 0, mt: 0.5 }} />
                      )}
                    </Box>
                  )
                })
              )}
            </Box>
          </Popover>

          <Tooltip title="Settings">
            <MuiIconButton
              size="small"
              aria-label="Settings"
              onClick={(e) => setSettingsAnchorEl(e.currentTarget)}
              sx={{ p: 0.5, color: 'text.secondary' }}
            >
              {icons['settings'] && React.createElement(icons['settings'] as React.ElementType, { sx: { fontSize: '1.25rem' } })}
            </MuiIconButton>
          </Tooltip>

          {/* Settings menu */}
          <Menu
            anchorEl={settingsAnchorEl}
            open={settingsMenuOpen}
            onClose={() => setSettingsAnchorEl(null)}
            anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
            transformOrigin={{ vertical: 'top', horizontal: 'right' }}
          >
            <MenuItem
              disabled
              sx={{
                fontSize: '0.75rem',
                fontWeight: 600,
                letterSpacing: 0.5,
                textTransform: 'uppercase',
                color: 'text.secondary',
                py: 0.5,
                '&.Mui-disabled': { opacity: 1 },
              }}
            >
              Color theme
            </MenuItem>
            {(Object.keys(THEME_LABELS) as ColorThemeName[]).map((name) => (
              <MenuItem
                key={name}
                onClick={() => { setColorTheme(name); setSettingsAnchorEl(null) }}
                selected={colorTheme === name}
                sx={{ gap: 1, fontSize: '0.875rem' }}
              >
                <ListItemIcon sx={{ minWidth: 'auto' }}>
                  <Box sx={{ width: 14, height: 14, borderRadius: '3px', bgcolor: themes[name].palette.primary.main, flexShrink: 0 }} />
                </ListItemIcon>
                <ListItemText primaryTypographyProps={{ fontSize: '0.875rem' }}>
                  {THEME_LABELS[name]}
                </ListItemText>
                {colorTheme === name && <Check sx={{ fontSize: '1rem', color: 'primary.main', ml: 1 }} />}
              </MenuItem>
            ))}
            <Divider sx={{ mt: 1 }} />
            <MenuItem
              disabled
              sx={{
                fontSize: '0.75rem',
                fontWeight: 600,
                letterSpacing: 0.5,
                textTransform: 'uppercase',
                color: 'text.secondary',
                py: 0.5,
                '&.Mui-disabled': { opacity: 1 },
              }}
            >
              Surface style
            </MenuItem>
            {(Object.keys(SURFACE_LABELS) as SurfaceScheme[]).map((scheme) => (
              <MenuItem
                key={scheme}
                onClick={() => { setSurfaceScheme(scheme); setSettingsAnchorEl(null) }}
                selected={surfaceScheme === scheme}
                sx={{ gap: 1, fontSize: '0.875rem' }}
              >
                <ListItemIcon sx={{ minWidth: 'auto' }}>
                  <Box sx={{ width: 14, height: 14, borderRadius: '3px', bgcolor: SURFACE_SCHEMES[scheme].sidebar, border: '1px solid rgba(228,228,231,0.8)', flexShrink: 0 }} />
                </ListItemIcon>
                <ListItemText primaryTypographyProps={{ fontSize: '0.875rem' }}>
                  {SURFACE_LABELS[scheme]}
                </ListItemText>
                {surfaceScheme === scheme && <Check sx={{ fontSize: '1rem', color: 'primary.main', ml: 1 }} />}
              </MenuItem>
            ))}
            <Divider sx={{ mt: 1 }} />
            <MenuItem
              disabled
              sx={{
                fontSize: '0.75rem',
                fontWeight: 600,
                letterSpacing: 0.5,
                textTransform: 'uppercase',
                color: 'text.secondary',
                py: 0.5,
                '&.Mui-disabled': { opacity: 1 },
              }}
            >
              Transparency
            </MenuItem>
            {(Object.keys(TRANSPARENCY_LABELS) as TransparencyBackdrop[]).map((backdrop) => (
              <MenuItem
                key={backdrop}
                onClick={() => { setTransparencyBackdrop(backdrop); setSettingsAnchorEl(null) }}
                selected={transparencyBackdrop === backdrop}
                sx={{ gap: 1, fontSize: '0.875rem' }}
              >
                <ListItemIcon sx={{ minWidth: 'auto' }}>
                  {/* The swatch renders the backdrop itself, so the choice is
                      legible without opening a resource. */}
                  <Box
                    sx={{
                      width: 14, height: 14, borderRadius: '3px', flexShrink: 0,
                      border: '1px solid rgba(228,228,231,0.8)',
                      ...TRANSPARENCY_BACKDROPS[backdrop].image,
                      ...(backdrop === 'checker' ? { backgroundSize: '8px 8px', backgroundPosition: '0 0, 4px 4px' } : {}),
                    }}
                  />
                </ListItemIcon>
                <ListItemText primaryTypographyProps={{ fontSize: '0.875rem' }}>
                  {TRANSPARENCY_LABELS[backdrop]}
                </ListItemText>
                {transparencyBackdrop === backdrop && <Check sx={{ fontSize: '1rem', color: 'primary.main', ml: 1 }} />}
              </MenuItem>
            ))}
          </Menu>

          {/* User avatar */}
          <Box
            onClick={handleMenuOpen}
            sx={{
              width: 30, height: 30, borderRadius: '2px',
              bgcolor: 'primary.main',
              display: 'flex', alignItems: 'center', justifyContent: 'center',
              color: 'white', fontSize: '0.875rem', fontWeight: 600,
              cursor: 'pointer',
              '&:hover': { bgcolor: 'primary.dark' },
            }}
          >
            {loading ? <CircularProgress size={14} color="inherit" /> : (user?.name?.charAt(0).toUpperCase() || user?.email?.charAt(0).toUpperCase() || 'U')}
          </Box>

          {/* User menu — who you are signed in as, and where.
              "Profile" and "Settings" used to sit here as handlers that only
              closed the menu: there is no profile page, and appearance settings
              already live under the gear. Two items that did nothing have been
              replaced by the thing the menu should have said all along. */}
          <Menu anchorEl={anchorEl} open={menuOpen} onClose={handleMenuClose}
            anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
            transformOrigin={{ vertical: 'top', horizontal: 'right' }}
          >
            <Box sx={{ px: 2, py: 1.5, minWidth: 260 }}>
              <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>
                {user?.name ?? 'Signed in'}
              </Typography>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }} noWrap>
                {user?.email ?? '—'}
              </Typography>

              <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap sx={{ mt: 1 }}>
                {isPlatformAdmin(user) && (
                  <Chip
                    label="Platform admin"
                    size="small"
                    sx={{ height: 20, fontSize: '0.7rem', bgcolor: 'warning.50', color: 'warning.dark' }}
                  />
                )}
                <Chip
                  label={
                    // A platform admin outside the organization holds no
                    // membership role, which is correct rather than a gap —
                    // say so plainly instead of showing "No role".
                    isPlatformAdmin(user)
                      ? `${getActiveOrganizationName()} · full access`
                      : `${getActiveOrganizationName()} · ${roleLabel(user?.current_organization_role).toLowerCase()}`
                  }
                  size="small"
                  sx={{ height: 20, fontSize: '0.7rem' }}
                />
              </Stack>
            </Box>

            <Divider />

            {canManageWorkspaces && (
              <MenuItem onClick={() => { handleMenuClose(); navigate('/organization') }}>
                <ListItemIcon><Groups sx={{ fontSize: '1.1rem' }} /></ListItemIcon>
                <ListItemText primary="Organization settings" />
              </MenuItem>
            )}
            {isPlatformAdmin(user) && (
              <MenuItem onClick={() => { handleMenuClose(); navigate('/admin') }}>
                <ListItemIcon><AdminPanelSettings sx={{ fontSize: '1.1rem' }} /></ListItemIcon>
                <ListItemText primary="Platform administration" />
              </MenuItem>
            )}

            <Divider />

            <MenuItem onClick={handleLogout}>Log out</MenuItem>
          </Menu>

          {/* Organization menu */}
          <Menu anchorEl={orgAnchorEl} open={orgMenuOpen} onClose={handleOrgMenuClose}
            anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
            transformOrigin={{ vertical: 'top', horizontal: 'left' }}
          >
            {organizations.map((org) => (
              <MenuItem key={org.id} onClick={() => handleSwitchOrganization(org)} selected={String(org.id) === activeOrganization}>
                {org.name}
              </MenuItem>
            ))}
          </Menu>
        </Stack>
      </Stack>

      {/* Collection + workspace tab row */}
      {!hideCollections && (collections.length > 0 || browsableWorkspaces.length > 0) && (
        <Box sx={{ borderTop: '1px solid rgba(228, 228, 231, 0.15)', bgcolor: surfaces.headerTabBg, px: 2, display: 'flex', alignItems: 'center' }}>
          <Stack direction="row" alignItems="center" spacing={0} sx={{ flex: 1, height: 44, overflowX: 'auto', '&::-webkit-scrollbar': { display: 'none' } }}>
            {collections.map((collection) => {
              const collectionId = typeof collection.id === 'number' ? collection.id : parseInt(collection.id as string, 10)
              const isActive = collectionId === activeCollectionId && !activeWorkspaceId
              return (
                <Box
                  key={collection.id}
                  onClick={() => onCollectionChange?.(collectionId)}
                  sx={{
                    px: 2, height: '100%', display: 'flex', alignItems: 'center',
                    cursor: 'pointer',
                    whiteSpace: 'nowrap',
                    fontSize: '1rem',
                    fontWeight: isActive ? 600 : 400,
                    color: isActive ? 'primary.main' : 'text.secondary',
                    position: 'relative',
                    '&::after': {
                      content: '""',
                      position: 'absolute',
                      bottom: 0,
                      left: '12%',
                      width: '76%',
                      height: '2px',
                      borderRadius: '1px 1px 0 0',
                      bgcolor: 'primary.main',
                      opacity: isActive ? 1 : 0,
                      transition: 'opacity 0.2s ease',
                    },
                    '&:hover': { color: isActive ? 'primary.main' : 'text.primary' },
                  }}
                >
                  <FolderOpen sx={{ fontSize: '0.875rem', mr: 0.75, flexShrink: 0 }} />
                  {simplifyCollectionName(collection.name)}
                  {(collection.coll_resource_count || collection.resource_count || 0) > 0 && (
                    <Box component="span" sx={{ ml: 0.75, fontSize: '0.75rem', color: isActive ? 'primary.main' : 'text.disabled' }}>
                      {collection.coll_resource_count || collection.resource_count}
                    </Box>
                  )}
                </Box>
              )
            })}

            {/* AiTy Review dashboard link — always present between collections and workspaces */}
            {collections.length > 0 && (
              <Box sx={{ mx: 1, width: '1px', height: 22, bgcolor: 'divider', flexShrink: 0 }} />
            )}
            {(() => {
              const isActive = pathname === '/aity-review'
              return (
                <Box
                  onClick={() => navigate('/aity-review')}
                  sx={{
                    px: 2, height: '100%', display: 'flex', alignItems: 'center',
                    cursor: 'pointer',
                    whiteSpace: 'nowrap',
                    fontSize: '1rem',
                    fontWeight: isActive ? 600 : 400,
                    color: isActive ? 'secondary.main' : 'text.secondary',
                    position: 'relative',
                    '&::after': {
                      content: '""',
                      position: 'absolute',
                      bottom: 0,
                      left: '12%',
                      width: '76%',
                      height: '2px',
                      borderRadius: '1px 1px 0 0',
                      bgcolor: 'secondary.main',
                      opacity: isActive ? 1 : 0,
                      transition: 'opacity 0.2s ease',
                    },
                    '&:hover': { color: isActive ? 'secondary.main' : 'text.primary' },
                  }}
                >
                  <AutoAwesome sx={{ fontSize: '0.875rem', mr: 0.75, flexShrink: 0 }} />
                  AiTy Review
                </Box>
              )
            })()}

            {/* Separator before regular workspaces */}
            {browsableWorkspaces.length > 0 && (
              <Box sx={{ mx: 1, width: '1px', height: 22, bgcolor: 'divider', flexShrink: 0 }} />
            )}

            {browsableWorkspaces.map((workspace) => {
              const isActive  = workspace.id === activeWorkspaceId
              const isSystem  = workspace.is_system === true
              const accent    = isSystem ? 'warning.main' : 'secondary.main'
              const TabIcon   = isSystem ? AutoAwesome : WorkspacesOutlined
              return (
                <Box
                  key={workspace.id}
                  onClick={() => onWorkspaceChange?.(workspace.id, workspace.name, workspace.purpose)}
                  sx={{
                    px: 2, height: '100%', display: 'flex', alignItems: 'center',
                    cursor: 'pointer',
                    whiteSpace: 'nowrap',
                    fontSize: '1rem',
                    fontWeight: isActive ? 600 : 400,
                    color: isActive ? accent : 'text.secondary',
                    position: 'relative',
                    '&::after': {
                      content: '""',
                      position: 'absolute',
                      bottom: 0,
                      left: '12%',
                      width: '76%',
                      height: '2px',
                      borderRadius: '1px 1px 0 0',
                      bgcolor: accent,
                      opacity: isActive ? 1 : 0,
                      transition: 'opacity 0.2s ease',
                    },
                    '&:hover': { color: isActive ? accent : 'text.primary' },
                  }}
                >
                  <TabIcon sx={{ fontSize: '0.875rem', mr: 0.75, flexShrink: 0 }} />
                  {workspace.name}
                </Box>
              )
            })}

            {/* Manage workspaces button — org admins and owners only */}
            {canManageWorkspaces && (
              <Tooltip title="Manage workspaces">
                <MuiIconButton
                  size="small"
                  onClick={() => setWorkspacesManagerOpen(true)}
                  sx={{ ml: 0.5, p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                >
                  <MoreVert sx={{ fontSize: '1rem' }} />
                </MuiIconButton>
              </Tooltip>
            )}

            {/* Vault badge — where the ACTIVE workspace is projected. Vaults are
                outputs, not browsing scopes: they get a contextual badge, never
                sibling tabs (UX decision 2026-07-03). */}
            {activeWorkspaceId && wsVaults.length > 0 && (
              <Tooltip title="This workspace is projected into these vaults">
                <Box
                  onClick={(e) => setVaultsAnchor(e.currentTarget)}
                  sx={{
                    ml: 1, px: 1, height: 24, display: 'flex', alignItems: 'center',
                    cursor: 'pointer', flexShrink: 0,
                    border: '1px solid', borderColor: 'divider', borderRadius: 3,
                    color: 'text.secondary', fontSize: '0.8rem',
                    '&:hover': { color: 'secondary.main', borderColor: 'secondary.main' },
                  }}
                >
                  <HexagonOutlined sx={{ fontSize: '0.875rem', mr: 0.5 }} />
                  {wsVaults.length}
                </Box>
              </Tooltip>
            )}
          </Stack>
          <Box
            onClick={() => navigate('/trash')}
            sx={{
              px: 2, height: 44, display: 'flex', alignItems: 'center',
              cursor: 'pointer',
              whiteSpace: 'nowrap',
              fontSize: '1rem',
              fontWeight: isTrash ? 600 : 400,
              color: isTrash ? 'primary.main' : 'text.disabled',
              flexShrink: 0,
              position: 'relative',
              '&::after': {
                content: '""',
                position: 'absolute',
                bottom: 0,
                left: '12%',
                width: '76%',
                height: '2px',
                borderRadius: '1px 1px 0 0',
                bgcolor: 'primary.main',
                opacity: isTrash ? 1 : 0,
                transition: 'opacity 0.2s ease',
              },
              '&:hover': { color: 'text.primary' },
            }}
          >
            Deleted resources
          </Box>
        </Box>
      )}

      {secondRow && (
        <Box sx={{ borderTop: '1px solid rgba(228, 228, 231, 0.15)', bgcolor: surfaces.headerTabBg, px: 3 }}>
          {secondRow}
        </Box>
      )}

      {/* Vault projection popover — copy addresses, open the public index */}
      <Popover
        open={Boolean(vaultsAnchor)}
        anchorEl={vaultsAnchor}
        onClose={() => setVaultsAnchor(null)}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
        slotProps={{ paper: { sx: { p: 1.5, minWidth: 340 } } }}
      >
        <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', display: 'block', mb: 1 }}>
          Projected into
        </Typography>
        <Stack spacing={0.75}>
          {wsVaults.map((vault) => (
            <Stack key={vault.id} direction="row" alignItems="center" spacing={1}>
              <HexagonOutlined
                sx={{ fontSize: '1rem', color: vault.state === 'public' ? 'success.main' : 'text.disabled', flexShrink: 0 }}
              />
              <Typography variant="body2" sx={{ fontWeight: 500, flex: 1 }} noWrap>
                {vault.name}
              </Typography>
              <Chip
                label={vault.purpose ?? 'delivery'}
                size="small"
                sx={{ height: 18, fontSize: '0.7rem', bgcolor: 'grey.100', color: 'text.secondary' }}
              />
              {vault.state !== 'public' && (
                <Chip label="private" size="small" sx={{ height: 18, fontSize: '0.7rem', bgcolor: 'warning.50', color: 'warning.dark' }} />
              )}
              <Tooltip title="Copy public URL">
                <MuiIconButton size="small" sx={{ p: 0.25 }} onClick={() => copyText(vaultHumanUrl(vault))}>
                  <ContentCopy sx={{ fontSize: '0.875rem' }} />
                </MuiIconButton>
              </Tooltip>
              <Tooltip title={vault.state === 'public' ? 'Open vault index' : 'Private — requires a vault key'}>
                <span>
                  <MuiIconButton
                    size="small"
                    sx={{ p: 0.25 }}
                    disabled={vault.state !== 'public'}
                    onClick={() => window.open(vaultHumanUrl(vault), '_blank', 'noopener')}
                  >
                    <OpenInNew sx={{ fontSize: '0.875rem' }} />
                  </MuiIconButton>
                </span>
              </Tooltip>
            </Stack>
          ))}
        </Stack>
        {canManageWorkspaces && (
          <>
            <Divider sx={{ my: 1 }} />
            <Button
              size="small"
              fullWidth
              onClick={() => { setVaultsAnchor(null); setWorkspacesManagerOpen(true) }}
            >
              Manage workspaces & vaults
            </Button>
          </>
        )}
      </Popover>

      <WorkspacesManagerDialog
        open={workspacesManagerOpen}
        onClose={() => setWorkspacesManagerOpen(false)}
        workspaces={workspaces}
        onWorkspacesChanged={handleWorkspacesChanged}
      />
    </Box>
  )
}

export default Header
