import { Box, Stack, Typography, Card, CardContent, Checkbox, SxProps, Theme, IconButton, Chip, Tooltip, useTheme, useMediaQuery } from '@mui/material'
import { Edit, Delete } from '@mui/icons-material'
// Placeholder for the file-count glyph on the footer row — swap to your preferred icon.
import LayersIcon from '@mui/icons-material/Layers'
import TydalIsotype from './ui/TydalIsotype'
import type { ResourceData } from '../api/resourceService'
import StatusChip from './ui/StatusChip'
import { ENTITY_TYPES, type EntityTypeKey } from '../constants/entityTypes'
import {
  AITY_STATES,
  AITY_UNDER_AUTO_APPROVE,
  aityIsotypeProps,
  aityStaleStyle,
  isAityStaleInFlight,
} from '../theme/aityStates'
import { resolveStorageUrl } from '../utils/storageUrl'
import AssetPreview from './ui/AssetPreview'
import { CARD_SHADOW } from '../contexts/ThemeContext'


export interface ResourceCardProps {
  /**
   * Resource data
   */
  resource: ResourceData
  /**
   * Click handler
   */
  onClick?: () => void
  /**
   * Edit handler
   */
  onEdit?: (e: React.MouseEvent) => void
  /**
   * Delete handler
   */
  onDelete?: (e: React.MouseEvent) => void
  /**
   * Highlight this card with a primary-color border (e.g. after create/edit)
   */
  highlighted?: boolean
  /**
   * When true, renders an aity_status chip. Only set in the AiTy Review workspace view.
   */
  showAityStatus?: boolean
  /**
   * This resource is in the basket. Renders the tick and a selected border.
   */
  selected?: boolean
  /**
   * Put in / take out of the basket. Omit to render a card with no tick at all.
   */
  onSelectToggle?: () => void
  /**
   * Something is already in the basket, so every tick stays visible instead of
   * waiting for hover — while a selection is under way, the affordance should
   * not be a thing you have to go looking for.
   */
  selectionActive?: boolean
  /**
   * Additional sx styles
   */
  sx?: SxProps<Theme>
}

/**
 * TYDAL ResourceCard Component
 *
 * Displays a resource with preview image, type label, filename, and action icons.
 *
 * @example
 * ```tsx
 * <ResourceCard
 *   resource={resource}
 *   onClick={() => handleResourceClick(resource.id)}
 *   onEdit={(e) => handleEdit(e, resource.id)}
 *   onDelete={(e) => handleDelete(e, resource.id)}
 * />
 * ```
 */
function ResourceCard(props: ResourceCardProps) {
  // showAityStatus is kept on the props API for callers that still pass it,
  // but the card now always renders the sparkle (low-opacity for inert states).
  const {
    resource, onClick, onEdit, onDelete, highlighted = false, sx,
    selected = false, onSelectToggle, selectionActive = false,
  } = props

  const theme = useTheme()
  // At lg+ breakpoints cards are wide enough (≥40% of viewport) to warrant the 'small' (426x240) variant.
  // Below lg, 'thumbnail' (256x144) is sufficient and saves bandwidth.
  const isWideCard = useMediaQuery(theme.breakpoints.up('lg'))

  // Get preview URL — variant chosen once per render, not per-call
  const getPreviewUrl = (): string => {
    const variant = isWideCard ? 'small' : 'thumbnail'

    // PRIORITY 1: Rendered preview image for PDF/audio snapshot files (SystemFile)
    // This must be checked first because when a PDF is starred as the snapshot,
    // conversion_urls only contains the PDF URL which can't be displayed in <img>
    if (resource.preview_snapshot_url) {
      return resolveStorageUrl(resource.preview_snapshot_url)
    }

    // PRIORITY 2: MediaLibrary conversion variants (images only)
    const conversionUrls = (resource as any).snapshot_file?.conversion_urls
    if (conversionUrls) {
      const sized = conversionUrls[variant as keyof typeof conversionUrls]
      if (sized) return resolveStorageUrl(sized)
    }

    // PRIORITY 3: Fallback to original conversion URL for images without sized variants
    if (conversionUrls?.original) {
      return resolveStorageUrl(conversionUrls.original)
    }

    // PRIORITY 4: Last resort - snapshot_file direct URL
    if ((resource as any).snapshot_file?.url) {
      return resolveStorageUrl((resource as any).snapshot_file.url)
    }

    return ''
  }

  // Get resource name (filename)
  const getName = (): string => {
    if (resource.name) return resource.name
    if (resource.data?.description?.course_title) {
      return resource.data.description.course_title
    }
    return 'Untitled Resource'
  }

  /**
   * UUID v7 is time-prefixed — batch-created resources share the same opening
   * characters. To give the user a visible distinguishing handle we take the
   * tail of the entropy block (the last segment) so even sibling resources
   * get unique short codes on the card.
   */
  const getShortId = (): string => {
    const id = String(resource.id)
    if (id.includes('-')) {
      const last = id.split('-').pop() ?? id
      return last.slice(-8)
    }
    return id.slice(-8)
  }

  /**
   * Compact relative time for the bottom-line "modified ..." stamp.
   * Falls back to the raw date string if updated_at is missing.
   */
  const getModifiedLabel = (): string | null => {
    const raw = resource.updated_at ?? resource.created_at
    if (!raw) return null
    const date = new Date(raw)
    if (Number.isNaN(date.getTime())) return null
    const diffMs = Date.now() - date.getTime()
    const sec    = Math.round(diffMs / 1000)
    if (sec < 60)        return 'just now'
    const min  = Math.round(sec / 60)
    if (min < 60)        return `${min}m ago`
    const hr   = Math.round(min / 60)
    if (hr < 24)         return `${hr}h ago`
    const day  = Math.round(hr / 24)
    if (day < 30)        return `${day}d ago`
    const mo   = Math.round(day / 30)
    if (mo < 12)         return `${mo}mo ago`
    const yr   = Math.round(mo / 12)
    return `${yr}y ago`
  }

  // Get resource type label
  const getTypeLabel = (): string => {
    return resource.type?.toUpperCase() || 'MULTIMEDIA'
  }

  // Get file count
  const getFileCount = (): number => {
    // Use files_count from backend if available (from withCount)
    if (typeof resource.files_count === 'number') {
      return resource.files_count
    }
    // Fallback to files array length
    return resource.files?.length || 0
  }

  // Listed, searchable, projectable — anything else is a draft or archived.
  const isActive = (): boolean => resource.state === undefined || resource.state === 'live'

  // Check if resource has LOM data
  const hasLOM = (): boolean => {
    return !!resource.metadata?.lom && Object.keys(resource.metadata.lom).length > 0
  }

  // Check if resource has LOM-ES data
  const hasLOMES = (): boolean => {
    return !!resource.metadata?.lomes && Object.keys(resource.metadata.lomes).length > 0
  }

  const previewUrl = getPreviewUrl()

  return (
    <Card
      onClick={onClick}
      elevation={0}
      sx={{
        cursor: 'pointer',
        // The card carries its own depth (edge + elevation) rather than a fill,
        // so it survives the `white` surface scheme where canvas and card are
        // the same colour. Fill is reserved exclusively for `selected` — that
        // is what keeps the basket readable across a 48-card grid without
        // reading any single card.
        border: highlighted || selected ? '2px solid' : '1px solid',
        borderColor: highlighted ? 'primary.main' : selected ? 'primary.light' : 'divider',
        bgcolor: selected ? 'primary.subtle' : 'background.paper',
        borderRadius: 1.5,
        boxShadow: highlighted ? undefined : CARD_SHADOW,
        transition: 'all 0.3s ease',
        '&:hover': {
          boxShadow: highlighted ? undefined : '0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1)',
          transform: 'translateY(-4px)',
          '& .card-actions': { opacity: 1 },
          '& .card-select': { opacity: 1 },
        },
        ...sx,
      }}
    >
      <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
        <Stack spacing={1}>
          {/* Row 1 — AITY isotype + type label + workspace chips + actions */}
          <Stack direction="row" justifyContent="space-between" alignItems="center">
            <Stack direction="row" spacing={0.75} alignItems="center" flexWrap="wrap" useFlexGap>
              {(() => {
                const baseCfg = resource.aity_status
                  ? AITY_STATES[resource.aity_status as keyof typeof AITY_STATES]
                  : null
                if (!baseCfg) return null

                const stale = isAityStaleInFlight(resource.aity_status, resource.updated_at)
                const underAutoApprove =
                  resource.aity_status === 'suggestions_made' && resource.under_auto_approve === true

                const cfg = stale
                  ? aityStaleStyle()
                  : underAutoApprove
                    ? AITY_UNDER_AUTO_APPROVE
                    : baseCfg

                return (
                  <Tooltip title={cfg.tooltip} arrow>
                    <Box sx={{ display: 'inline-flex', alignItems: 'center' }}>
                      <TydalIsotype size={20} {...aityIsotypeProps(theme, cfg)} />
                    </Box>
                  </Tooltip>
                )
              })()}
              <Chip
                label={getTypeLabel()}
                size="small"
                sx={{
                  height: 18,
                  fontSize: '0.75rem',
                  fontWeight: 500,
                  letterSpacing: 0.5,
                  bgcolor: isActive() ? 'primary.subtle' : 'grey.100',
                  color: isActive() ? 'primary.main' : 'text.secondary',
                  borderRadius: '4px',
                }}
              />
              {((resource.workspaces ?? resource.workspace) ?? []).map((ws: { id: number; name: string }) => (
                <Chip
                  key={ws.id}
                  label={ws.name}
                  size="small"
                  sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'transparent', color: 'secondary.main', border: '1px solid', borderColor: 'secondary.main' }}
                />
              ))}
            </Stack>
            <Stack direction="row" spacing={0.5} className="card-actions" sx={{ opacity: 0, transition: 'opacity 0.3s ease' }}>
              {onEdit && (
                <IconButton
                  size="small"
                  onClick={(e) => {
                    e.stopPropagation()
                    onEdit(e)
                  }}
                  sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'primary.main' } }}
                >
                  <Edit sx={{ fontSize: '1rem' }} />
                </IconButton>
              )}
              {onDelete && (
                <IconButton
                  size="small"
                  onClick={(e) => {
                    e.stopPropagation()
                    onDelete(e)
                  }}
                  sx={{ p: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}
                >
                  <Delete sx={{ fontSize: '1rem' }} />
                </IconButton>
              )}
            </Stack>
          </Stack>

          {/* Preview — a recess in the card, not a panel on it. The inset
              shadow is what tells the eye the neutral surface is a viewing area
              rather than an unthemed patch, and it gives the card a second
              depth step without adding any fill. */}
          <AssetPreview src={previewUrl} alt={getName()}>
            {/* Basket tick — hidden until hover, and pinned visible once
                anything is in the basket, so a selection under way is never
                something you have to hunt for. Clicking it must not open the
                detail modal, hence the stopPropagation on the wrapper. */}
            {onSelectToggle && (
              <Box
                className="card-select"
                onClick={(e) => e.stopPropagation()}
                sx={{
                  position: 'absolute',
                  top: 4,
                  left: 4,
                  zIndex: 2,
                  borderRadius: '4px',
                  bgcolor: 'rgba(255, 255, 255, 0.85)',
                  opacity: selected || selectionActive ? 1 : 0,
                  transition: 'opacity 0.2s ease',
                }}
              >
                <Checkbox
                  size="small"
                  checked={selected}
                  onChange={onSelectToggle}
                  inputProps={{ 'aria-label': `Put ${getName()} in the basket` }}
                  sx={{ p: 0.25 }}
                />
              </Box>
            )}
          </AssetPreview>

          {/* Name — under the preview, two-line clamp for predictable card heights.
              Full title surfaces via hover tooltip so nothing is hidden permanently. */}
          <Tooltip title={getName()} arrow placement="top" enterDelay={400}>
            <Typography
              variant="body2"
              sx={{
                fontWeight: 400,
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                display: '-webkit-box',
                WebkitLineClamp: 2,
                WebkitBoxOrient: 'vertical',
                lineHeight: 1.3,
                color: 'text.primary',
                minHeight: 'calc(1.3em * 2)',
              }}
            >
              {getName()}
            </Typography>
          </Tooltip>

          {/* Status row — LOM badges only. File count and Active moved to the footer. */}
          {(hasLOM() || hasLOMES()) && (
            <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap alignItems="center">
              {hasLOM() && <StatusChip variant="lom" label="LOM" />}
              {hasLOMES() && <StatusChip variant="lomes" label="LOM-ES" />}
            </Stack>
          )}

          {/* Tags — single line for stable card height. +N tooltip lists the rest. */}
          {(() => {
            const tags: any[] = resource.semanticTags ?? (resource as any).semantic_tags ?? []
            if (tags.length === 0) return null
            const VISIBLE = 4
            const overflow = Math.max(0, tags.length - VISIBLE)
            return (
              <Stack direction="row" spacing={0.4} alignItems="center" sx={{ minHeight: 16, overflow: 'hidden', flexWrap: 'nowrap' }}>
                {tags.slice(0, VISIBLE).map((tag: any) => {
                  const entityKey = tag.entity_type && tag.entity_type in ENTITY_TYPES ? tag.entity_type as EntityTypeKey : null
                  const et = entityKey ? ENTITY_TYPES[entityKey] : null
                  const color = et?.color ?? null
                  const EntityIcon = et?.Icon ?? null
                  return (
                    <Tooltip
                      key={tag.id}
                      title={
                        <Typography variant="caption" sx={{ textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                          Generated by {(tag.vocabulary ?? 'organization')}, reviewed by {(tag.reviewer ?? 'user')}
                        </Typography>
                      }
                      placement="top"
                      arrow
                    >
                      <Box
                        sx={{
                          display: 'inline-flex',
                          alignItems: 'stretch',
                          border: '1px solid',
                          borderColor: color ?? 'divider',
                          borderRadius: 0.75,
                          overflow: 'hidden',
                          height: 16,
                          maxWidth: '100%',
                        }}
                      >
                        {EntityIcon && (
                          <Box sx={{ bgcolor: color, display: 'flex', alignItems: 'center', px: 0.375 }}>
                            <EntityIcon sx={{ fontSize: '0.75rem', color: 'white' }} />
                          </Box>
                        )}
                        <Typography
                          sx={{
                            fontSize: '0.75rem',
                            fontWeight: 500,
                            px: 0.5,
                            lineHeight: '16px',
                            color: color ?? 'text.secondary',
                            whiteSpace: 'nowrap',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                            maxWidth: 90,
                          }}
                        >
                          {tag.label}
                        </Typography>
                      </Box>
                    </Tooltip>
                  )
                })}
                {overflow > 0 && (
                  <Tooltip
                    title={
                      <Typography variant="caption" sx={{ fontSize: '0.75rem' }}>
                        {tags.slice(VISIBLE).map((t: any) => t.label).join(', ')}
                      </Typography>
                    }
                    placement="top"
                    arrow
                  >
                    <Typography sx={{ fontSize: '0.75rem', color: 'text.disabled', lineHeight: '16px', cursor: 'help' }}>
                      +{overflow}
                    </Typography>
                  </Tooltip>
                )}
              </Stack>
            )
          })()}

          {/* Footer — three-column row: active chip (left) | modified date (centre) | short id (right).
              Short id uses the trailing entropy of UUID v7 since the leading bytes are time-based and
              shared across batches. */}
          <Box
            sx={{
              display: 'grid',
              gridTemplateColumns: '1fr auto 1fr',
              alignItems: 'center',
              gap: 1,
              pt: 0.25,
            }}
          >
            <Stack direction="row" spacing={0.5} alignItems="center" sx={{ justifySelf: 'start' }}>
              <Tooltip
                title={`${getFileCount()} ${getFileCount() === 1 ? 'file' : 'files'}`}
                arrow
                placement="top"
              >
                <Stack direction="row" spacing={0.25} alignItems="center" sx={{ color: 'text.secondary' }}>
                  <LayersIcon sx={{ fontSize: '0.85rem' }} />
                  <Typography variant="caption" sx={{ fontSize: '0.7rem', lineHeight: 1.2, fontWeight: 500 }}>
                    {getFileCount()}
                  </Typography>
                </Stack>
              </Tooltip>
              {isActive() ? (
                <StatusChip variant="active" label="Active" />
              ) : (
                <StatusChip variant="inactive" label="Inactive" />
              )}
            </Stack>
            {(() => {
              const label = getModifiedLabel()
              if (!label) return <span />
              return (
                <Tooltip title={resource.updated_at ?? resource.created_at ?? ''} arrow placement="top">
                  <Typography
                    variant="caption"
                    sx={{ fontSize: '0.7rem', color: 'text.disabled', lineHeight: 1.2, justifySelf: 'center' }}
                  >
                    mod. {label}
                  </Typography>
                </Tooltip>
              )
            })()}
            <Typography
              variant="caption"
              sx={{ color: 'text.disabled', fontFamily: 'monospace', fontSize: '0.7rem', lineHeight: 1.2, justifySelf: 'end' }}
              title={String(resource.id)}
            >
              {getShortId()}
            </Typography>
          </Box>
        </Stack>
      </CardContent>
    </Card>
  )
}

export default ResourceCard
