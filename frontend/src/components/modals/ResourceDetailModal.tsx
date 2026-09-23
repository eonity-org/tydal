import { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  DialogContentText,
  IconButton,
  Box,
  Stack,
  Typography,
  Tab,
  Tabs,
  Paper,
  Chip,
  Divider,
  CircularProgress,
  Alert,
  Button,
  Snackbar,
  Tooltip,
  useTheme,
  TextField,
  InputAdornment,
  Collapse,
  Popover,
} from '@mui/material'
import { Close, Edit, Save, Cancel, Star, Search, Hub, HubOutlined, ExpandMore, ExpandLess, Refresh, Visibility, VisibilityOff } from '@mui/icons-material'
import resourceService, { type ResourceData, type ResourceFile, type SemanticTag, type ActivityEvent } from '../../api/resourceService'
import authService from '../../api/authService'
import collectionService, { type Collection, getSchemeFields } from '../../api/collectionService'
import workspaceService, { type Workspace } from '../../api/workspaceService'
import semanticTagService from '../../api/semanticTagService'
import { ENTITY_TYPES, type EntityTypeKey } from '../../constants/entityTypes'
import MediaViewer from '../ui/MediaViewer'
import EditableBasicInfo from './editable/EditableBasicInfo'
import EditableFiles, { type FileConfig } from './editable/EditableFiles'
import EditableLomData from './editable/EditableLomData'
import VaultLinksPanel from './VaultLinksPanel'
import { validateResourceData, formatValidationErrors, hasResourceChanges } from '../../utils/validation'
import { SuggestionChip } from '../suggestions/SuggestionChip'
import { FileAityCard } from '../suggestions/FileAityCard'
import { AityHub } from '../suggestions/AityHub'
import { AityStatusAccordion } from '../suggestions/AityStatusAccordion'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import CheckCircleIcon from '@mui/icons-material/CheckCircle'
import { useAiSuggestionsPoller, type AiSuggestions } from '../../hooks/useAiSuggestionsPoller'
import { useAityPolling, type AityPollEntry } from '../../hooks/useAityPolling'
import { useAityStatusSync } from '../../hooks/useAityStatusSync'

export interface ResourceDetailModalProps {
  /**
   * Resource ID to fetch and display (null for create mode)
   */
  resourceId: number | string | null
  /**
   * Whether the modal is open
   */
  open: boolean
  /**
   * Callback when modal is closed
   */
  onClose: () => void
  /**
   * Initial mode for the modal
   */
  mode?: 'view' | 'edit' | 'create'
  /**
   * Collection ID (required for create mode)
   */
  collectionId?: number | null
  /**
   * Callback when resource is created/updated successfully
   */
  onResourceSaved?: (resource?: ResourceData) => void
}

interface TabPanelProps {
  children?: React.ReactNode
  index: number
  value: number
}

function TabPanel(props: TabPanelProps) {
  const { children, value, index, ...other } = props
  // Keep-mounted pattern: render every tab's children unconditionally and only
  // hide the inactive ones via CSS. Mounting/unmounting on every tab switch was
  // throwing away per-tab local state — most painfully, EditableFiles's queued
  // newFiles / filesToRemove arrays disappeared the moment the user clicked
  // Basic Info. With display:none the components keep their state and reappear
  // intact when the user returns to the tab.
  const active = value === index
  return (
    <div
      role="tabpanel"
      hidden={!active}
      id={`resource-tabpanel-${index}`}
      aria-labelledby={`resource-tab-${index}`}
      {...other}
    >
      <Box sx={{ py: 3, display: active ? undefined : 'none' }}>{children}</Box>
    </div>
  )
}

/**
 * TYDAL ResourceDetailModal Component
 *
 * Full-screen modal to display detailed resource information including
 * preview, metadata, files, and LOM data.
 *
 * @example
 * ```tsx
 * <ResourceDetailModal
 *   resourceId={resource.id}
 *   open={isModalOpen}
 *   onClose={() => setIsModalOpen(false)}
 * />
 * ```
 */

// ─── FileDebugCard ──────────────────────────────────────────────────────────
// Collapsible per-file row used in the Debug tab AI processing section.
// Contributing files (canonical + has tika_metadata) start expanded; others
// start collapsed and show just the disk path for quick navigation.
interface FileDebugCardProps {
  file: ResourceFile & { [key: string]: any }
  index: number
  isContributing: boolean
  onReextract?: () => Promise<void>
}

function FileDebugCard({ file, index, isContributing, onReextract }: FileDebugCardProps) {
  const [expanded, setExpanded] = useState(isContributing)
  const [extracting, setExtracting] = useState(false)

  const handleReextract = async (e: React.MouseEvent) => {
    e.stopPropagation()
    if (!onReextract || extracting) return
    setExtracting(true)
    try { await onReextract() } finally { setExtracting(false) }
  }

  const tika = file.latest_tika_system_file
  const sugTags = file.latest_ai_suggested_tags_system_file
  const sugName = file.latest_ai_suggested_name_system_file
  const sugDesc = file.latest_ai_suggested_description_system_file
  const tikaMeta = tika?.metadata?.tika_metadata
  const tikaKeyCount = tikaMeta ? Object.keys(tikaMeta).length : 0

  const chunkCount   = tika?.metadata?.chunk_count
  const extractedAt  = tika?.metadata?.extracted_at
  const charCount    = tika?.metadata?.char_count
  const embErr       = tika?.metadata?.embedding_error
  const embFailedAt  = tika?.metadata?.embedding_failed_at
  // Infer embedding status — no success marker exists in DB; ES is authoritative
  const embeddingStatus = (() => {
    if (!tika) return '⬜ not yet extracted'
    if (chunkCount === undefined) return '🖼  metadata-only (no text chunks — contributes via synthetic metadata chunk)'
    if (embErr)    return '❌ embedding failed: ' + embErr
    if (chunkCount === 0) return '⚠  0 chunks extracted — nothing to embed'
    return `✅ likely embedded — ${chunkCount} chunk(s), ~${charCount ?? '?'} chars (no error recorded; ES is authoritative)`
  })()

  const lines: string[] = [
    `mime_type:    ${file.mime_type}`,
    `disk:         ${file.disk || '(none)'}`,
    `path:         ${file.path || '(none)'}`,
    '',
    `Tika SystemFile: ${tika ? 'id=' + tika.id : '(none)'}`,
  ]
  if (tika) {
    const previewKeys = tikaKeyCount > 0 ? Object.keys(tikaMeta!).slice(0, 6).join(', ') + (tikaKeyCount > 6 ? '…' : '') : ''
    lines.push(`  tika_metadata:  ${tikaKeyCount > 0 ? tikaKeyCount + ' keys — ' + previewKeys : '(empty)'}`)
    if (extractedAt)  lines.push(`  extracted_at:   ${extractedAt}`)
    if (chunkCount !== undefined) lines.push(`  chunk_count:    ${chunkCount}`)
    if (charCount  !== undefined) lines.push(`  char_count:     ${charCount}`)
    if (embFailedAt) lines.push(`  emb_failed_at:  ${embFailedAt}`)
  }
  lines.push('')
  lines.push(`Embedding: ${embeddingStatus}`)
  lines.push('')
  lines.push('AI Suggestions:')
  const tagCount = sugTags?.metadata?.value?.length ?? 0
  lines.push(`  tags:        ${sugTags ? tagCount + ' suggestion(s) — id=' + sugTags.id : '(none)'}`)
  if (sugTags?.metadata?.value && tagCount > 0) {
    sugTags.metadata.value.forEach((t) => {
      lines.push(`    • ${t.label}${t.type ? ' [' + t.type + ']' : ''}${t.description ? ' — ' + t.description : ''}`)
    })
  }
  lines.push(`  name:        ${sugName ? '"' + (sugName.metadata?.value ?? '') + '" — id=' + sugName.id : '(none)'}`)
  const descVal = sugDesc?.metadata?.value ?? ''
  const descPreview = descVal.length > 100 ? descVal.slice(0, 100) + '…' : descVal
  lines.push(`  description: ${sugDesc ? '"' + descPreview + '" — id=' + sugDesc.id : '(none)'}`)

  return (
    <Box sx={{ mb: 1, border: '1px solid', borderColor: isContributing ? 'success.light' : 'divider', borderRadius: 1, overflow: 'hidden' }}>
      {/* Header row — click to expand/collapse */}
      <Box
        sx={{
          px: 1.5, py: 0.75,
          bgcolor: isContributing ? 'rgba(46, 125, 50, 0.06)' : 'grey.50',
          display: 'flex', alignItems: 'center', gap: 1, cursor: 'pointer',
          '&:hover': { bgcolor: isContributing ? 'rgba(46, 125, 50, 0.10)' : 'grey.100' },
        }}
        onClick={() => setExpanded(prev => !prev)}
      >
        <Typography variant="caption" sx={{ fontWeight: 600, flex: 1, fontFamily: 'monospace', fontSize: '0.75rem' }}>
          [{index + 1}] {file.filename || file.original_name || '(unnamed)'}
        </Typography>
        {file.role && (
          <Chip size="small" label={file.role} variant="outlined" sx={{ fontSize: '0.65rem', height: 18 }} />
        )}
        {isContributing && (
          <Chip size="small" label="contributing" color="success" sx={{ fontSize: '0.65rem', height: 18 }} />
        )}
        {tika?.metadata?.embedding_error && (
          <Chip size="small" label="emb. error" color="error" sx={{ fontSize: '0.65rem', height: 18 }} />
        )}
        {onReextract && (
          <Tooltip title="Re-extract this file (queues extraction job)" placement="top">
            <IconButton size="small" onClick={handleReextract} disabled={extracting} sx={{ p: 0.25 }}>
              {extracting
                ? <CircularProgress size={14} />
                : <Refresh sx={{ fontSize: 14, color: 'text.secondary' }} />}
            </IconButton>
          </Tooltip>
        )}
        {expanded
          ? <ExpandLess fontSize="small" sx={{ color: 'text.secondary' }} />
          : <ExpandMore fontSize="small" sx={{ color: 'text.secondary' }} />}
      </Box>

      {/* Collapsed state: show disk path only for quick debugging */}
      {!expanded && (
        <Box component="pre" sx={{ px: 1.5, py: 0.5, fontSize: '0.7rem', m: 0, fontFamily: 'monospace', color: 'text.secondary' }}>
          {`disk: ${file.disk || '(none)'}  |  path: ${file.path || '(none)'}`}
        </Box>
      )}

      {/* Expanded detail */}
      <Collapse in={expanded}>
        <Box component="pre" sx={{ px: 1.5, py: 1, fontSize: '0.7rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
          {lines.join('\n')}
        </Box>
      </Collapse>
    </Box>
  )
}

// ─────────────────────────────────────────────────────────────────────────────

function ResourceDetailModal(props: ResourceDetailModalProps) {
  const { resourceId, open, onClose, mode: initialMode = 'view', collectionId, onResourceSaved } = props
  const theme = useTheme()

  // Use initialMode prop to set edit mode on first render
  // Create mode should also start in edit mode
  const [isEditMode, setIsEditMode] = useState(initialMode === 'edit' || initialMode === 'create')

  const [resource, setResource] = useState<ResourceData | null>(null)
  const {
    status: aiPollStatus,
    startPolling: startAiSuggestionsPolling,
  } = useAiSuggestionsPoller(resource?.id ? String(resource.id) : null, { manual: true })
  const {
    statuses: aityStatuses,
    initStatuses: initAityStatuses,
    startPolling: startAityPolling,
    restartPolling: restartAityPolling,
    stopAll: stopAityPolling,
  } = useAityPolling()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [tabValue, setTabValue] = useState(0)
  const [selectedFileIndex, setSelectedFileIndex] = useState<number>(0)
  const [expandedFiles, setExpandedFiles] = useState<Set<number>>(new Set())

  // Edit mode states
  const [editedResource, setEditedResource] = useState<ResourceData | null>(null)
  const [isSaving, setIsSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [extractingAll, setExtractingAll] = useState(false)
  const [activityLog, setActivityLog] = useState<ActivityEvent[]>([])
  const [activityLogLoading, setActivityLogLoading] = useState(false)
  // True when the background AITY poller has detected a change the user hasn't
  // pulled in yet. We never auto-replace the resource while the modal is open;
  // the user dismisses the banner (or hits its Refresh) when they're ready.
  const [backgroundRefreshPending, setBackgroundRefreshPending] = useState(false)
  // Bumped after every successful save so the EditableFiles child component
  // remounts and drops its internal newFiles / filesToRemove state. Without
  // this, "Save & refresh" leaves the just-uploaded File objects visible in
  // the child's "new files" list while the refetched resource also shows them
  // as current — they appear duplicated until the modal closes.
  const [editorRemountKey, setEditorRemountKey] = useState(0)

  // Current user ID — needed to scope the "from previous session" detection and the
  // discard-on-close cleanup so we only touch files this user actually uploaded.
  const [currentUserId, setCurrentUserId] = useState<string | null>(null)
  // Session uploads completed in this modal instance. Cleared on Save (commit) or Discard.
  const [sessionUploadedFileIds, setSessionUploadedFileIds] = useState<Set<string>>(new Set())
  // File IDs whose AITY-terminal refetch already ran — prevents loops on subsequent status ticks.
  const sessionRefetchedRef = useRef<Set<string>>(new Set())
  const sessionRefetchInFlightRef = useRef(false)
  // Live count of uploads still in the XHR-uploading phase. Reported by EditableFiles.
  const [uploadingCount, setUploadingCount] = useState(0)
  // Imperative handle EditableFiles installs so we can abort every in-flight XHR
  // synchronously from the close-while-uploading flow.
  const abortAllUploadsRef = useRef<(() => void) | null>(null)
  // Imperative handle EditableFiles installs to accept files from the MediaViewer
  // dropzone. Null while EditableFiles is unmounted (view mode) — handleViewerDrop
  // buffers in pendingDroppedFiles and a flush effect retries once it installs.
  const addFilesRef = useRef<((files: File[]) => void) | null>(null)
  const [pendingDroppedFiles, setPendingDroppedFiles] = useState<File[] | null>(null)
  // Imperative handle EditableFiles installs so the MediaViewer canonical pin can
  // toggle a file's canonical role through the same role machinery as the Files tab.
  const canonicalToggleRef = useRef<((viewerFileId: string) => void) | null>(null)
  // Create mode adopts the wizard's model: the real `draft` resource is created on
  // the first file interaction (not modal open). createdDraftId tracks it so an
  // unsaved exit can hard-delete it; draftSavedRef flips true once Save commits the
  // draft so the exit cleanup doesn't delete a now-real resource. ensureDraftInFlight
  // coalesces concurrent first-file drops into a single create.
  const [createdDraftId, setCreatedDraftId] = useState<string | null>(null)
  const draftSavedRef = useRef(false)
  const ensureDraftInFlight = useRef<Promise<string | null> | null>(null)
  // Recovery dialog: shown on open when there are uncommitted files this user
  // left behind in a previous session. Forces explicit Accept (commit) or Discard.
  const [recoveryDialogOpen, setRecoveryDialogOpen] = useState(false)
  const [recoveryBusy, setRecoveryBusy] = useState<null | 'accept' | 'discard'>(null)
  // Exit dialog state, shared by Cancel and X (close). The target controls what
  // happens after the user picks Keep / Remove:
  //   'cancel' → drop edit mode, stay in the modal (back to view mode)
  //   'close'  → drop edit mode AND close the modal (back to dashboard)
  type ExitStage  = 'idle' | 'asking' | 'removing'
  type ExitTarget = 'cancel' | 'close'
  const [exitStage,  setExitStage]  = useState<ExitStage>('idle')
  const [exitTarget, setExitTarget] = useState<ExitTarget>('cancel')

  const [snackbar, setSnackbar] = useState<{ open: boolean; message: string; severity: 'success' | 'error' | 'warning' | 'info' }>({
    open: false,
    message: '',
    severity: 'success'
  })
  const [newFiles, setNewFiles] = useState<File[]>([])
  // Real server file id for each finished session upload, keyed by newFiles index.
  // Lets the snapshot star target a real id on a file still shown as a "new-{index}" blob.
  const [sessionUploadedIdByIndex, setSessionUploadedIdByIndex] = useState<Record<number, string>>({})

  // Keep selectedFileIndex within bounds of the viewer's deduped file list, otherwise
  // MediaViewer / file-row clicks try to read past the end on the next render
  // (happens during close, save commit, or after a session-upload refetch). The count
  // must match allFiles: session-uploaded files appear in BOTH resource.files and
  // newFiles, so exclude them from the resource.files side or the total over-counts
  // (the "File 5 of 4" symptom).
  useEffect(() => {
    const committed = (resource?.files ?? []).filter((f: any) => !sessionUploadedFileIds.has(f.id)).length
    const total = committed + newFiles.length
    if (total === 0) {
      if (selectedFileIndex !== 0) setSelectedFileIndex(0)
    } else if (selectedFileIndex >= total) {
      setSelectedFileIndex(total - 1)
    }
  }, [resource?.files, sessionUploadedFileIds, newFiles.length, selectedFileIndex])

  const [filesToRemove, setFilesToRemove] = useState<string[]>([])
  const [configMap, setConfigMap] = useState<Record<number, FileConfig>>({})
  const [existingFileConfigs, setExistingFileConfigs] = useState<Record<string, FileConfig>>({})
  const [newPreview, setNewPreview] = useState<File | null>(null)
  const [pendingCanonicalChange, setPendingCanonicalChange] = useState<string | null | undefined>(undefined)
  const [snapshotFileId, setSnapshotFileId] = useState<string | null | undefined>(undefined)
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})
  const [hasRejectedFiles, setHasRejectedFiles] = useState(false)
  const [tempFileUrls, setTempFileUrls] = useState<Record<number, string>>({})
  const [activeCollection, setActiveCollection] = useState<Collection | null>(null)
  const [availableWorkspaces, setAvailableWorkspaces] = useState<Workspace[]>([])
  const [resourceWorkspaceIds, setResourceWorkspaceIds] = useState<Set<string>>(new Set())
  const [originalWorkspaceIds, setOriginalWorkspaceIds] = useState<Set<string>>(new Set())
  const [selectedTagsData, setSelectedTagsData] = useState<SemanticTag[]>([])
  const [resourceTagIds, setResourceTagIds] = useState<Set<number>>(new Set())
  const [originalTagIds, setOriginalTagIds] = useState<Set<number>>(new Set())
  const [tagSearchQuery, setTagSearchQuery] = useState('')
  const [tagSearchResults, setTagSearchResults] = useState<SemanticTag[]>([])
  const [tagSearchLoading, setTagSearchLoading] = useState(false)
  const [tagDropdownOpen, setTagDropdownOpen] = useState(false)
  const [tagCreateError, setTagCreateError] = useState<string | null>(null)
  const [creatingTag, setCreatingTag] = useState(false)
  const [tagSuggestions, setTagSuggestions] = useState<SemanticTag[]>([])
  const [tagSuggestionsLoaded, setTagSuggestionsLoaded] = useState(false)
  const tagPickerRef = useRef<HTMLDivElement>(null)

  // AI suggestions state
  const [, setFilesWithAiSuggestions] = useState<Set<string>>(new Set())
  const [aiSuggestionsStatus, setAiSuggestionsStatus] = useState<'none' | 'found' | 'used'>('none')
  // Local UI toggle only — no backend call on hide/show
  const [aiSuggestionsHidden, setAiSuggestionsHidden] = useState(false)
  const [pendingSuggestedTags, setPendingSuggestedTags] = useState<Array<{ label: string; description?: string | null; type?: string }>>([])
  const [pendingTagEditIndex, setPendingTagEditIndex] = useState<number | null>(null)
  const [pendingTagEditAnchor, setPendingTagEditAnchor] = useState<HTMLElement | null>(null)

  // Computed suggestion data — lifted here so it can be referenced by both EditableBasicInfo and the Tags section.
  // Sources from the *_for_display relationships so the user can always re-pick a suggestion, even after it was
  // already accepted/auto-accepted (and even after editing the field over the suggestion). The only filter is
  // the user's Hide/Show toggle — the data is never silently removed from the user.
  const panelSuggestions = useMemo((): AiSuggestions | null => {
    // Gate on a real resource id rather than mode: in create mode the draft gets an
    // id once the first file lands, after which AITY suggestions flow normally.
    if (!resource?.id || aiSuggestionsStatus === 'none' || aiSuggestionsHidden) return null
    const readName = (f: any) => f.latest_ai_suggested_name_system_file_for_display?.metadata?.value
      ?? f.latest_ai_suggested_name_system_file?.metadata?.value
      ?? null
    const readDesc = (f: any) => f.latest_ai_suggested_description_system_file_for_display?.metadata?.value
      ?? f.latest_ai_suggested_description_system_file?.metadata?.value
      ?? null
    const readTags = (f: any): any[] => f.latest_ai_suggested_tags_system_file_for_display?.metadata?.value
      ?? f.latest_ai_suggested_tags_system_file?.metadata?.value
      ?? []

    const filesWithSugg = (resource.files ?? []).filter((f: any) =>
      readTags(f).length > 0 || readName(f) || readDesc(f)
    )

    // Synthetic "source file" used for resource-level AITY-generated rows so the
    // existing per-source rendering machinery (sourceLabel, sourceFile.id key) just
    // works. The id is non-real but unique; the filename surfaces in tooltips/ALT.
    const generatedSource = { id: '__aity_generated__', filename: 'aity generated' } as any

    const genName = resource.latest_ai_generated_name_system_file_for_display?.metadata?.value
      ?? resource.latest_ai_generated_name_system_file?.metadata?.value
      ?? null
    const genDesc = resource.latest_ai_generated_description_system_file_for_display?.metadata?.value
      ?? resource.latest_ai_generated_description_system_file?.metadata?.value
      ?? null
    const genTags = resource.latest_ai_generated_tags_system_file_for_display?.metadata?.value
      ?? resource.latest_ai_generated_tags_system_file?.metadata?.value
      ?? []

    if (filesWithSugg.length === 0 && !genName && !genDesc && (genTags as any[]).length === 0) return null

    // Tag groups — generated first, per-file second, with a divider rendered between
    // them. Dedup across groups: a label is shown in only one (generated wins so the
    // canonicalised form takes precedence over the raw per-file label).
    const seenLabels = new Set<string>()
    const suggestedTagsGenerated: AiSuggestions['suggestedTags'] = []
    for (const tag of (genTags as any[])) {
      if (!seenLabels.has(tag.label)) { seenLabels.add(tag.label); suggestedTagsGenerated.push({ ...tag, sourceFile: generatedSource }) }
    }
    const suggestedTagsFromFiles: AiSuggestions['suggestedTags'] = []
    for (const f of filesWithSugg) {
      for (const tag of readTags(f)) {
        if (!seenLabels.has(tag.label)) { seenLabels.add(tag.label); suggestedTagsFromFiles.push({ ...tag, sourceFile: f }) }
      }
    }
    const allTags = [...suggestedTagsGenerated, ...suggestedTagsFromFiles]

    // Generated variants render first so the user sees the synthesised value above the
    // per-file alternatives in the name/description suggestion list.
    const suggestedNames = [
      ...(genName ? [{ value: genName as string, sourceFile: generatedSource }] : []),
      ...filesWithSugg
        .filter((f: any) => readName(f))
        .map((f: any) => ({ value: readName(f) as string, sourceFile: f })),
    ]
    const suggestedDescriptions = [
      ...(genDesc ? [{ value: genDesc as string, sourceFile: generatedSource }] : []),
      ...filesWithSugg
        .filter((f: any) => readDesc(f))
        .map((f: any) => ({ value: readDesc(f) as string, sourceFile: f })),
    ]
    const perFileSuggestions = filesWithSugg.map((f: any) => ({
      filename: f.filename,
      suggestedName: readName(f) ?? null,
      suggestedDescription: readDesc(f) ?? null,
      suggestedTags: readTags(f).map((t: any) => ({
        label: t.label,
        description: t.description,
        type: t.type,
        confidence: t.confidence,
      })),
    }))
    return {
      filesWithSuggestions: filesWithSugg,
      suggestedNames,
      suggestedDescriptions,
      suggestedTags: allTags,
      suggestedTagsGenerated,
      suggestedTagsFromFiles,
      suggestedName: suggestedNames[0]?.value ?? null,
      suggestedDescription: suggestedDescriptions[0]?.value ?? null,
      perFileSuggestions,
    }
  }, [resource?.id, resource?.files, resource?.latest_ai_generated_name_system_file_for_display, resource?.latest_ai_generated_description_system_file_for_display, resource?.latest_ai_generated_tags_system_file_for_display, aiSuggestionsStatus, aiSuggestionsHidden, initialMode])

  // Existing saved-tag entity_type edit state
  const [existingTagEditId, setExistingTagEditId] = useState<number | null>(null)
  const [existingTagEditAnchor, setExistingTagEditAnchor] = useState<HTMLElement | null>(null)
  /** Local entity_type overrides for saved tags — flushed to API on Save */
  const [tagTypeOverrides, setTagTypeOverrides] = useState<Record<number, EntityTypeKey>>({})


  const hasFiles = resource?.files && Array.isArray(resource.files) && resource.files.length > 0

  // Whether the current user may add/edit files. No client-side permission signal
  // exists yet (the Edit button at the title bar is ungated; the API enforces the
  // policy), so this is true in any editable context. Single switch for the viewer
  // dropzone — tighten here when a client-side role/permission check is introduced.
  const canEdit = true

  // Live AITY aggregate derived from the per-file poll map — drives the hub button
  // (pulsing icon + failed badge) and its Retry action.
  const aityFailedIds = useMemo(
    () => Object.entries(aityStatuses).filter(([, e]) => e.status === 'failed').map(([id]) => id),
    [aityStatuses]
  )
  const aityProcessingActive = useMemo(
    () => Object.values(aityStatuses).some((e) => e.status === 'waiting'),
    [aityStatuses]
  )

  // Human-readable resource structure for the title chip. Reflects the *effective*
  // roles including pending, uncommitted edits (new-file configs, existing-file role
  // overrides, and a pending canonical promotion/demotion) so the chip updates live as
  // the user drops files or toggles canonical — before any save.
  const resourceKind = useMemo<string | null>(() => {
    const committed = ((resource?.files ?? []) as any[]).filter((f) => !sessionUploadedFileIds.has(f.id))
    const total = committed.length + newFiles.length
    if (total === 0) return null

    // Only two structural modes exist: Multi-component, or Canonical + Supporting (a lone
    // canonical file is still the latter mode). Decide purely from the EFFECTIVE roles —
    // the role handlers always write the resulting role into existingFileConfigs (existing
    // files) or configMap (new/session files), so "any component" is the reliable signal.
    // (We deliberately do NOT branch on pendingCanonicalChange: it's null both when the
    // canonical is cleared AND when a *new* file is canonical, so it can't distinguish the
    // modes — that ambiguity was the bug where promoting a new file showed Multi-component.)
    const roles = [
      ...committed.map((f: any) => existingFileConfigs[f.id]?.role ?? f.role),
      ...Object.values(configMap).map((c) => c.role),
    ]
    return roles.includes('component') ? 'Multi-component' : 'Canonical + Supporting'
  }, [resource?.files, sessionUploadedFileIds, newFiles.length, configMap, existingFileConfigs])

  // Effective canonical state of a viewer file (existing or pending "new-{index}"),
  // accounting for uncommitted role edits — drives the MediaViewer canonical pin.
  const isViewerFileCanonical = useCallback((viewerId: string | undefined): boolean => {
    if (!viewerId) return false
    const newMatch = /^new-(\d+)$/.exec(viewerId)
    if (newMatch) return configMap[parseInt(newMatch[1], 10)]?.role === 'canonical'
    if (existingFileConfigs[viewerId]?.role) return existingFileConfigs[viewerId].role === 'canonical'
    if (pendingCanonicalChange === viewerId) return true
    if (pendingCanonicalChange !== undefined) return false   // canonical moved elsewhere or cleared
    return ((resource?.files ?? []) as any[]).find((f) => f.id === viewerId)?.role === 'canonical'
  }, [configMap, existingFileConfigs, pendingCanonicalChange, resource?.files])

  // Combine existing files with new files for viewer.
  // Session uploads appear in resource.files (after the refetch effect fires)
  // AND in newFiles (the local buffer with its blob URL). Filter them out of
  // resource.files so the carousel doesn't double-count — the newFiles entry
  // is the canonical preview while the upload is still uncommitted.
  const allFiles = [
    ...(resource?.files
      ?.filter((file: any) => !sessionUploadedFileIds.has(file.id))
      .map((file: any) => ({
        id: file.id,
        filename: file.filename || file.metadata?.original_filename || file.name || 'Unnamed file',
        original_name: file.filename || file.metadata?.original_filename || file.name || 'Unnamed file', // For backward compatibility
        mime_type: file.mime_type,
        dam_url: file.url || file.dam_url,
        url: file.url || file.dam_url,
        isTemporary: false,
        snapshotId: undefined as string | undefined, // committed files use their own id
      })) || []),
    ...newFiles.map((file, index) => ({
      id: `new-${index}`, // Temporary ID for new files
      filename: file.name,
      original_name: file.name, // For backward compatibility
      mime_type: file.type,
      dam_url: tempFileUrls[index] || '', // Temporary URL for preview using index
      url: tempFileUrls[index] || '', // Temporary URL for preview using index
      isTemporary: true, // Flag to indicate this is a new file
      // Real server id once the session upload finished — enables the snapshot star
      // (which needs a real id) on a file still rendered from its local blob.
      snapshotId: sessionUploadedIdByIndex[index],
    }))
  ]


  // Set of currently-accepted tag labels (lowercased) for fast comparison against
  // suggested-tag labels in FileAityCard. In view mode this is just the tags
  // attached to the resource; in edit mode it also includes pending suggestion
  // clicks so chips light up immediately before save.
  const acceptedTagLabels = useMemo(
    () => new Set(
      [
        ...selectedTagsData.map((t) => (t.label ?? '').toLowerCase()),
        ...pendingSuggestedTags.map((t) => (t.label ?? '').toLowerCase()),
      ].filter(Boolean)
    ),
    [selectedTagsData, pendingSuggestedTags]
  )

  // Resolve the current user once per modal open so the resume banner and the
  // discard-on-close cleanup can scope to files this user actually uploaded.
  useEffect(() => {
    if (!open) return
    let cancelled = false
    authService.getUser().then((res) => {
      if (cancelled) return
      const id = res?.data?.id
      setCurrentUserId(id ? String(id) : null)
    }).catch(() => { /* ignore — banner just won't show */ })
    return () => { cancelled = true }
  }, [open])

  // Uncommitted files on the resource that belong to the current user. This is the
  // union of:
  //   - files left over from a previous browser session (visible on initial load),
  //   - files just uploaded in this modal instance (tracked in sessionUploadedFileIds)
  // commit-files only acts on files where uncommitted_by = Auth::id(), so we mirror
  // that filter here for the banner / discard prompt.
  const pendingSessionFiles = useMemo<ResourceFile[]>(() => {
    if (!resource?.files || !currentUserId) return []
    return resource.files.filter((f: any) =>
      f.uncommitted_at && (f.uncommitted_by ? String(f.uncommitted_by) === currentUserId : true)
    )
  }, [resource?.files, currentUserId])

  // Previous-session uncommitted files = pending files NOT uploaded in this modal instance.
  const previousSessionFiles = useMemo<ResourceFile[]>(() =>
    pendingSessionFiles.filter((f: any) => !sessionUploadedFileIds.has(f.id))
  , [pendingSessionFiles, sessionUploadedFileIds])

  // Check if there are unsaved changes
  const hasUnsavedChanges = useCallback(() => {
    // Check for data changes
    const hasDataChanges = hasResourceChanges(resource, editedResource)

    // Check for file changes (new files, files to remove, or canonical/snapshot changes)
    const hasFileChanges = newFiles.length > 0 || filesToRemove.length > 0 || newPreview !== null || snapshotFileId !== undefined || pendingCanonicalChange !== undefined || Object.keys(existingFileConfigs).length > 0

    // Check for preview changes
    const hasPreviewChanges = newPreview !== null || snapshotFileId !== undefined

    // Check for workspace membership changes
    const hasWorkspaceChanges = resourceWorkspaceIds.size !== originalWorkspaceIds.size
      || [...resourceWorkspaceIds].some(id => !originalWorkspaceIds.has(id))

    // Check for tag membership changes (including pending AI-suggested tags)
    const hasTagChanges = resourceTagIds.size !== originalTagIds.size
      || [...resourceTagIds].some(id => !originalTagIds.has(id))
      || pendingSuggestedTags.length > 0

    // Uncommitted session files (this session OR resumed from a prior one) need
    // to be committed via Save — count them as a real change so the Save button
    // doesn't grey out when the only "edit" is accepting recovered files.
    const hasPendingSessionFiles = pendingSessionFiles.length > 0

    // A still-unpublished draft always needs a Save to publish it (flip state off
    // 'draft'), even when the form otherwise looks clean — e.g. after auto-approve
    // persisted the metadata and the edit state was re-synced to match. Without this the
    // Save button greys out and the resource can never leave the draft state.
    const isUnpublishedDraft = createdDraftId !== null || resource?.state === 'draft'

    return hasDataChanges || hasFileChanges || hasPreviewChanges || hasWorkspaceChanges || hasTagChanges || hasPendingSessionFiles || isUnpublishedDraft
  }, [resource, editedResource, newFiles, filesToRemove, newPreview, snapshotFileId, pendingCanonicalChange, existingFileConfigs, resourceWorkspaceIds, originalWorkspaceIds, resourceTagIds, originalTagIds, pendingSuggestedTags, pendingSessionFiles, createdDraftId])

  // Build the per-file AITY poll entries from a freshly-fetched resource and
  // resume polling for anything still in flight. Called by the initial fetch
  // AND by Save & refresh so newly-committed files surface their AITY state
  // instead of falling through to "Not processed".
  const seedAityStatusesFromResource = useCallback((data: ResourceData, rid: string) => {
    const next: Record<string, AityPollEntry> = {}
    for (const f of (data.files ?? []) as any[]) {
      const stage = f.processing_status?.stage ?? null

      // Compute suggestion presence FIRST. A file with suggestions has demonstrably
      // been processed, so it must surface as "ready" even when processing_status.stage
      // never reached 'done' in the DB (stale/missing stage) — otherwise it falls through
      // to "Not processed" while the inline panel (which reads these same fields) shows
      // the suggestions. The two surfaces must agree.
      const name = f.latest_ai_suggested_name_system_file_for_display?.metadata?.value
        ?? f.latest_ai_suggested_name_system_file?.metadata?.value
        ?? null
      const desc = f.latest_ai_suggested_description_system_file_for_display?.metadata?.value
        ?? f.latest_ai_suggested_description_system_file?.metadata?.value
        ?? null
      const tags = f.latest_ai_suggested_tags_system_file_for_display?.metadata?.value
        ?? f.latest_ai_suggested_tags_system_file?.metadata?.value
        ?? []
      // Scheme-field suggestions (ai_fill contract)
      const metadata = f.latest_ai_suggested_metadata_system_file_for_display?.metadata?.value ?? {}
      const hasSugg = !!(name || desc || (tags as any[]).length > 0 || Object.keys(metadata).length > 0)
      const applied = {
        name:        !!(data as any).name_source_file_id        && (data as any).name_source_file_id        === f.id,
        description: !!(data as any).description_source_file_id && (data as any).description_source_file_id === f.id,
        tags:        !!(data as any).tags_source_file_id        && (data as any).tags_source_file_id        === f.id,
      }

      const inProgress = stage === 'queued' || stage === 'extracting' || stage === 'ai_analyzing'

      if (inProgress) {
        // An active re-run wins over any stale suggestions from a prior pass.
        next[f.id] = { status: 'waiting', stage, suggestions: null }
      } else if (stage === 'done' || hasSugg) {
        next[f.id] = {
          status: 'ready',
          stage: stage ?? 'done',
          suggestions: hasSugg
            ? { suggestedName: name, suggestedDescription: desc, suggestedTags: tags, suggestedMetadata: metadata }
            : null,
          applied,
        }
      } else if (stage === 'not_applicable') {
        next[f.id] = { status: 'not_supported', stage, suggestions: null }
      } else if (stage === 'failed') {
        next[f.id] = { status: 'failed', stage, suggestions: null }
      }
      // else: no stage and no suggestions → genuinely not processed; leave no entry.
    }
    initAityStatuses(next)

    const inProgressIds = Object.entries(next)
      .filter(([, entry]) => entry.status === 'waiting')
      .map(([id]) => id)
    if (inProgressIds.length > 0) {
      startAityPolling(rid, inProgressIds)
    }
  }, [initAityStatuses, startAityPolling])

  // Fetch resource data when modal opens
  useEffect(() => {
    if (!open) {
      setActiveCollection(null)
      setAvailableWorkspaces([])
      setResourceWorkspaceIds(new Set())
      setOriginalWorkspaceIds(new Set())
      setSelectedTagsData([])
      setResourceTagIds(new Set())
      setOriginalTagIds(new Set())
      setTagSearchQuery('')
      setTagSearchResults([])
      setTagSuggestions([])
      setTagSuggestionsLoaded(false)
      setTagDropdownOpen(false)
      setTagCreateError(null)
      setFilesWithAiSuggestions(new Set())
      setAiSuggestionsStatus('none')
      setAiSuggestionsHidden(false)
      stopAityPolling()
      setExistingTagEditId(null)
      setExistingTagEditAnchor(null)
      setTagTypeOverrides({})
      setSessionUploadedFileIds(new Set())
      sessionRefetchedRef.current = new Set()
      sessionRefetchInFlightRef.current = false
      setCreatedDraftId(null)
      draftSavedRef.current = false
      ensureDraftInFlight.current = null
      setSessionUploadedIdByIndex({})
      return
    }

    // Fetch available workspaces (non-default) for the workspace picker
    workspaceService.getWorkspaces().then(ws => {
      setAvailableWorkspaces((ws ?? []).filter(w => !w.is_default))
    })

    // Create mode: initialize with empty template, fetch collection schema
    if (resourceId === null && initialMode === 'create') {
      setLoading(true)

      if (!collectionId) {
        setError('Collection ID is required for creating a new resource')
        setLoading(false)
        return
      }

      // Fetch collection to get accept types and schema
      ;(async () => {
      const fetchedCollection = await collectionService.getCollection(collectionId)
      setActiveCollection(fetchedCollection)

      const defaultType = 'multimedia'

      const emptyResource: ResourceData = {
        id: '',
        organization_id: '',
        collection_id: collectionId,
        user_owner_id: '',
        type: defaultType,
        name: '',
        slug: null,
        description: null,
        active: true,
        state: 'live',
        metadata: {},
        payload: {
          public: false,
          downloadable: true,
          featured: false,
        },
        published_at: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
        deleted_at: null,
        files: [],
        categories: [],
        semanticTags: [],
      } as ResourceData
      setResource(emptyResource)
      setEditedResource(emptyResource)
      setIsEditMode(true)
      setLoading(false)
      })()
      return
    }

    // Edit/View mode: fetch existing resource
    const fetchResource = async () => {
      if (!resourceId) return

      setLoading(true)
      setError(null)

      try {
        const data = await resourceService.getResource(resourceId)
        if (data) {
          setResource(data)
          setEditedResource(null)
          // Initialise workspace membership from the loaded resource relationship
          const wsArray: any[] = data.workspaces ?? data.workspace ?? []
          const wsIdSet = new Set(wsArray.map((w: any) => String(w.id)))
          setResourceWorkspaceIds(wsIdSet)
          setOriginalWorkspaceIds(wsIdSet)

          // Initialise tag membership from the loaded resource relationship
          // Note: Laravel serialises semanticTags() as "semantic_tags" in JSON; getResource normalises
          // this but we also handle the raw key here as a safety net.
          const existingTags: SemanticTag[] = (data.semanticTags ?? data.semantic_tags) ?? []
          const tagIdSet = new Set<number>(existingTags.map((t: any) => Number(t.id)))
          setResourceTagIds(tagIdSet)
          setOriginalTagIds(tagIdSet)
          setSelectedTagsData(existingTags)

          // Track which files have active AI suggestions, pending (not yet applied) or
          // already applied. We need both: pending drives the auto-open default; any
          // suggestion at all (pending OR applied) keeps the panel re-openable so a
          // user can re-pick a suggestion they already used and then edited over.
          const hasPending = (f: any) =>
            f.latest_ai_suggested_tags_system_file?.metadata?.value?.length > 0 ||
            f.latest_ai_suggested_name_system_file?.metadata?.value ||
            f.latest_ai_suggested_description_system_file?.metadata?.value
          const hasAny = (f: any) =>
            hasPending(f) ||
            f.latest_ai_suggested_tags_system_file_for_display?.metadata?.value?.length > 0 ||
            f.latest_ai_suggested_name_system_file_for_display?.metadata?.value ||
            f.latest_ai_suggested_description_system_file_for_display?.metadata?.value
          const pendingIds = new Set<string>((data.files ?? []).filter(hasPending).map((f: any) => f.id))
          const anyIds     = new Set<string>((data.files ?? []).filter(hasAny).map((f: any) => f.id))

          // Resource-level generated rows count as "any suggestion" too — they keep the
          // panel re-openable when no per-file suggestion is pending and add a pending
          // signal of their own when not yet applied.
          const hasPendingGenerated = !!(
            data.latest_ai_generated_name_system_file?.metadata?.value ||
            data.latest_ai_generated_description_system_file?.metadata?.value ||
            (data.latest_ai_generated_tags_system_file?.metadata?.value?.length ?? 0) > 0
          )
          const hasAnyGenerated = hasPendingGenerated || !!(
            data.latest_ai_generated_name_system_file_for_display?.metadata?.value ||
            data.latest_ai_generated_description_system_file_for_display?.metadata?.value ||
            (data.latest_ai_generated_tags_system_file_for_display?.metadata?.value?.length ?? 0) > 0
          )
          setFilesWithAiSuggestions(anyIds)

          // Pre-seed per-file AITY polling state from static resource data and
          // resume polling for anything still in flight.
          seedAityStatusesFromResource(data, String(resourceId))

          // Derive suggestions status from whether suggestion SystemFiles exist.
          // 'found'      → at least one suggestion (pending or already applied) is available
          // 'used'       → backend reports the resource was processed but no records remain
          // 'none'       → never had any
          // The panel default is open when there's something pending to decide, and collapsed
          // when everything was already accepted/auto-accepted — the user can still click
          // Show to re-pick a suggestion they used and then edited away from.
          if (anyIds.size > 0 || hasAnyGenerated) {
            setAiSuggestionsStatus('found')
            setAiSuggestionsHidden(pendingIds.size === 0 && !hasPendingGenerated)
          } else if (data.ai_suggestions_status === 'processed') {
            setAiSuggestionsStatus('used')
            setAiSuggestionsHidden(false)
          } else {
            setAiSuggestionsStatus('none')
            setAiSuggestionsHidden(false)
          }

          // Eagerly load the org tag pool when AI suggestions are present so the
          // usage counter (N×) is available as soon as the accordion renders,
          // without waiting for the user to focus the tag picker.
          if (anyIds.size > 0 && !tagSuggestionsLoaded) {
            semanticTagService.list().then(all => {
              setTagSuggestions(all)
              setTagSuggestionsLoaded(true)
            }).catch(() => { setTagSuggestionsLoaded(true) })
          }

          const snapshotIndex = data.snapshot_file?.id
            ? (data.files?.findIndex((f: any) => f.id === data.snapshot_file?.id) ?? 0)
            : 0
          setSelectedFileIndex(Math.max(0, snapshotIndex))

          // Fetch collection schema so required-field markers and validation work in edit mode
          const cid = data.collection_id ?? collectionId
          if (cid) {
            collectionService.getCollection(cid).then(setActiveCollection)
          }
        } else {
          setError('Failed to load resource details')
        }
      } catch (err) {
        setError('An error occurred while loading resource details')
      } finally {
        setLoading(false)
      }
    }

    fetchResource()
  }, [resourceId, open, initialMode])

  /**
   * Derive aiSuggestionsStatus + aiSuggestionsHidden + filesWithAiSuggestions
   * from a freshly-fetched resource. Mirrors the same rules used by the initial
   * fetchResource effect — exists as a helper so Save & refresh can re-apply
   * the rules without re-running the whole modal-open initialisation pass.
   */
  const recomputeSuggestionsState = useCallback((data: ResourceData) => {
    const hasPending = (f: any) =>
      f.latest_ai_suggested_tags_system_file?.metadata?.value?.length > 0 ||
      f.latest_ai_suggested_name_system_file?.metadata?.value ||
      f.latest_ai_suggested_description_system_file?.metadata?.value
    const hasAny = (f: any) =>
      hasPending(f) ||
      f.latest_ai_suggested_tags_system_file_for_display?.metadata?.value?.length > 0 ||
      f.latest_ai_suggested_name_system_file_for_display?.metadata?.value ||
      f.latest_ai_suggested_description_system_file_for_display?.metadata?.value
    const pendingIds = new Set<string>((data.files ?? []).filter(hasPending).map((f: any) => f.id))
    const anyIds     = new Set<string>((data.files ?? []).filter(hasAny).map((f: any) => f.id))

    const hasPendingGenerated = !!(
      data.latest_ai_generated_name_system_file?.metadata?.value ||
      data.latest_ai_generated_description_system_file?.metadata?.value ||
      ((data.latest_ai_generated_tags_system_file?.metadata?.value as any[] | undefined)?.length ?? 0) > 0
    )
    const hasAnyGenerated = hasPendingGenerated || !!(
      data.latest_ai_generated_name_system_file_for_display?.metadata?.value ||
      data.latest_ai_generated_description_system_file_for_display?.metadata?.value ||
      ((data.latest_ai_generated_tags_system_file_for_display?.metadata?.value as any[] | undefined)?.length ?? 0) > 0
    )

    setFilesWithAiSuggestions(anyIds)

    if (anyIds.size > 0 || hasAnyGenerated) {
      setAiSuggestionsStatus('found')
      setAiSuggestionsHidden(pendingIds.size === 0 && !hasPendingGenerated)
    } else if (data.ai_suggestions_status === 'processed') {
      setAiSuggestionsStatus('used')
      setAiSuggestionsHidden(false)
    } else {
      setAiSuggestionsStatus('none')
      setAiSuggestionsHidden(false)
    }
  }, [])

  const loadActivityLog = useCallback(async () => {
    if (!resource?.id) return
    setActivityLogLoading(true)
    try {
      const events = await resourceService.getResourceActivity(String(resource.id))
      setActivityLog(events)
    } finally {
      setActivityLogLoading(false)
    }
  }, [resource?.id])

  // Auto-load the activity log when the Activity tab becomes active. The endpoint
  // synthesises events on each call (no caching), so the only cost of a fresh fetch
  // is the request itself — fine to re-fetch on every tab open. We intentionally
  // do NOT auto-load when the tab is not active, to keep the modal lightweight.
  useEffect(() => {
    if (tabValue === 3 && resource?.id) {
      loadActivityLog()
    }
  }, [tabValue, resource?.id, loadActivityLog])

  // Background AITY notifier — while the modal is open, watch for status changes
  // on this single resource. We intentionally do NOT replace `resource` here:
  // if the user is mid-edit on the Basic Info tab, swapping the underlying state
  // would shuffle the suggestion panel mid-keystroke. Instead set a flag that
  // surfaces a "new info — refresh?" banner; the user decides when to pull it in.
  //
  // useAityStatusSync only polls resources in queued / aity_in_progress / under-
  // auto-approve states, so once the resource is terminal it stops by itself.
  const pollList = useMemo(
    () => (open && resource ? [resource] : []),
    [open, resource],
  )
  useAityStatusSync(pollList, (patches) => {
    if (patches.length === 0) return
    setBackgroundRefreshPending(true)
  })

  const handleBackgroundRefresh = useCallback(async () => {
    if (!resource?.id) return
    const fresh = await resourceService.getResource(String(resource.id))
    if (fresh) {
      setResource(fresh)
      recomputeSuggestionsState(fresh)
    }
    setBackgroundRefreshPending(false)
    if (tabValue === 3) loadActivityLog()
  }, [resource?.id, tabValue, loadActivityLog, recomputeSuggestionsState])

  // Update edit mode when modal is opened with a different mode
  useEffect(() => {
    if (open) {
      setIsEditMode(initialMode === 'edit' || initialMode === 'create')
    }
  }, [open, initialMode])

  // Initialize edited resource when entering edit mode
  useEffect(() => {
    if (isEditMode && resource && !editedResource) {
      // Deep clone the resource to avoid mutations
      const cloned = JSON.parse(JSON.stringify(resource))

      // Ensure the language field is properly set
      if (!cloned.data) {
        cloned.data = {}
      }
      if (!cloned.data.description) {
        cloned.data.description = {}
      }
      if (!cloned.data.description.lang && resource.data?.description?.lang) {
        cloned.data.description.lang = resource.data.description.lang
      }


      setEditedResource(cloned)
      setValidationErrors({})
    }
  }, [isEditMode, resource, editedResource])

  const handleTabChange = (_event: React.SyntheticEvent, newValue: number) => {
    setTabValue(newValue)
  }

  // Handle entering edit mode
  const handleEnterEditMode = useCallback(() => {
    if (resource) {
      setEditedResource(JSON.parse(JSON.stringify(resource)))
      setIsEditMode(true)
    }
  }, [resource])

  // Create mode: lazily create the real `draft` resource on the first file
  // interaction so the file has a server-side home and AITY can run during create.
  // Returns the resource id (existing or newly created), or null if it can't be
  // created (no collection). Concurrent calls share one in-flight create.
  const ensureDraft = useCallback(async (): Promise<string | null> => {
    if (resource?.id) return String(resource.id)
    if (ensureDraftInFlight.current) return ensureDraftInFlight.current
    const cid = collectionId ?? editedResource?.collection_id ?? resource?.collection_id
    if (!cid) return null
    const p = (async () => {
      const created = await resourceService.createResource({
        name: (editedResource?.name?.trim() || 'Untitled draft'),
        type: editedResource?.type || 'multimedia',
        collection_id: Number(cid),
        state: 'draft',
      })
      if (!created) return null
      // Baseline becomes the draft; keep the user's in-progress edits in
      // editedResource (just stamp the new id on it so saves target the draft).
      setResource(created)
      setEditedResource(prev => prev ? { ...prev, id: created.id } : prev)
      setCreatedDraftId(String(created.id))
      draftSavedRef.current = false
      return String(created.id)
    })()
    ensureDraftInFlight.current = p
    try { return await p } finally { ensureDraftInFlight.current = null }
  }, [resource?.id, resource?.collection_id, collectionId, editedResource])

  // Files dropped onto the MediaViewer (left pane). A drop is a deliberate "add
  // this" gesture, so we enter edit mode if needed and stage the file through
  // EditableFiles' validated path — never a silent commit. In view mode the
  // EditableFiles handle isn't installed yet, so buffer and let the flush effect
  // retry once it mounts.
  const handleViewerDrop = useCallback((files: File[]) => {
    if (files.length === 0) return
    if (!isEditMode) handleEnterEditMode()
    if (addFilesRef.current) {
      addFilesRef.current(files)
    } else {
      setPendingDroppedFiles(prev => (prev ? [...prev, ...files] : files))
    }
  }, [isEditMode, handleEnterEditMode])

  // Flush files buffered by handleViewerDrop once EditableFiles installs its
  // addFilesRef handle (one render after entering edit mode).
  useEffect(() => {
    if (pendingDroppedFiles && addFilesRef.current) {
      addFilesRef.current(pendingDroppedFiles)
      setPendingDroppedFiles(null)
    }
  }, [pendingDroppedFiles, isEditMode])

  // Handle cancel edit
  const handleCancelEdit = useCallback(() => {
    setEditedResource(null)
    setIsEditMode(false)
    setSaveError(null)
    setNewFiles([])
    setFilesToRemove([])
    setConfigMap({})
    setExistingFileConfigs({})
    setNewPreview(null)
    setSnapshotFileId(undefined)
    setPendingCanonicalChange(undefined)
    setResourceWorkspaceIds(new Set(originalWorkspaceIds))
    setResourceTagIds(new Set(originalTagIds))
    setSelectedTagsData(prev => prev.filter(t => originalTagIds.has(t.id)))
    setPendingSuggestedTags([])
    setPendingTagEditIndex(null)
    setPendingTagEditAnchor(null)
    setTagSearchQuery('')
    setTagSearchResults([])
    setTagDropdownOpen(false)
    setTagCreateError(null)
    // If the modal was opened directly in edit mode (pencil on card),
    // there's no view state to return to — close the modal instead.
    if (initialMode === 'edit' || initialMode === 'create') {
      onClose()
    }
  }, [initialMode, onClose, originalWorkspaceIds, originalTagIds])

  // Toggle resource workspace membership locally — applied to the API only on Save
  const handleWorkspaceToggle = useCallback((workspaceId: string) => {
    const wsId = String(workspaceId)
    setResourceWorkspaceIds(prev => {
      const next = new Set(prev)
      next.has(wsId) ? next.delete(wsId) : next.add(wsId)
      return next
    })
  }, [])

  // Debounced server-side tag search
  useEffect(() => {
    if (!tagSearchQuery.trim()) { setTagSearchResults([]); setTagSearchLoading(false); return }
    setTagSearchLoading(true)
    const t = setTimeout(async () => {
      try {
        const results = await semanticTagService.list(tagSearchQuery)
        setTagSearchResults(results)
      } catch {
        setTagSearchResults([])
      } finally {
        setTagSearchLoading(false)
      }
    }, 300)
    return () => clearTimeout(t)
  }, [tagSearchQuery])

  // Close dropdown on outside click
  useEffect(() => {
    const handler = (e: MouseEvent) => {
      if (tagPickerRef.current && !tagPickerRef.current.contains(e.target as Node)) {
        setTagDropdownOpen(false)
      }
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [])

  // Lazily load the suggestions pool on first focus (cached for the session)
  const handleTagFocus = useCallback(async () => {
    setTagDropdownOpen(true)
    if (tagSuggestionsLoaded) return
    try {
      const all = await semanticTagService.list()
      setTagSuggestions(all)
      setTagSuggestionsLoaded(true)
    } catch {
      setTagSuggestionsLoaded(true) // don't retry on error
    }
  }, [tagSuggestionsLoaded])

  // Add a tag to the resource selection
  const handleTagAdd = useCallback((tag: SemanticTag) => {
    setResourceTagIds(prev => new Set([...prev, tag.id]))
    setSelectedTagsData(prev => prev.some(t => t.id === tag.id) ? prev : [...prev, tag])
    setTagSearchQuery('')
    setTagSearchResults([])
    setTagDropdownOpen(false)
    setTagCreateError(null)
  }, [])

  // Remove a tag from the resource selection
  const handleTagRemove = useCallback((tagId: number) => {
    setResourceTagIds(prev => { const n = new Set(prev); n.delete(tagId); return n })
    setSelectedTagsData(prev => prev.filter(t => t.id !== tagId))
  }, [])

  // Create a new tag inline (user ad-hoc) and auto-select it
  const handleTagCreate = useCallback(async (entityType: EntityTypeKey = 'tag') => {
    const label = tagSearchQuery.trim()
    if (!label) return
    setCreatingTag(true)
    setTagCreateError(null)
    try {
      const created = await semanticTagService.create({
        label,
        vocabulary: 'user',
        reviewer:   'user',
        entity_type: entityType,
      })
      handleTagAdd(created)
    } catch (err: any) {
      setTagCreateError(err?.message ?? 'Failed to create tag')
    } finally {
      setCreatingTag(false)
    }
  }, [tagSearchQuery, handleTagAdd])

  // Accept an AI-suggested name — overwrites the resource name in the edit form
  const handleAcceptSuggestedName = useCallback((value: string) => {
    setEditedResource(prev => prev ? { ...prev, name: value } : prev)
  }, [])

  // Accept an AI-suggested description — overwrites the description in the edit form
  const handleAcceptSuggestedDescription = useCallback((value: string) => {
    setEditedResource(prev => prev ? { ...prev, description: value } : prev)
  }, [])

  // Accept an AI-suggested tag — adds to pending list, created on Save (not immediately)
  const handleAcceptSuggestedTag = useCallback((tag: { label: string; description?: string | null; type?: string }) => {
    setPendingSuggestedTags(prev =>
      prev.some(t => t.label.toLowerCase() === tag.label.toLowerCase()) ? prev : [...prev, tag]
    )
  }, [])

  // Hide suggestions locally — the panel data stays intact in the DB
  const handleHideAiSuggestions = useCallback(() => {
    setAiSuggestionsHidden(true)
  }, [])

  // Show suggestions locally within this session (no backend call — use "Show again" for DB restore)
  const handleShowAiSuggestions = useCallback(() => {
    setAiSuggestionsHidden(false)
  }, [])

  // Retry AITY enrichment for a single file in the Files tab.
  // storeSuggestion atomically replaces each suggestion type; empty results preserve the previous value.
  const handleRetryFileAity = useCallback(async (fileId: string) => {
    if (!resource?.id) return
    const rid = String(resource.id)
    await resourceService.aityEnrichFile(rid, fileId)
    restartAityPolling(rid, fileId)
  }, [resource?.id, restartAityPolling])

  useEffect(() => {
    if (!resource?.id) return

    if (aiPollStatus === 'ready') {
      ;(async () => {
        const updated = await resourceService.getResource(String(resource.id))
        if (!updated) return

        setResource(updated)
        const withSugg = new Set<string>(
          (updated.files ?? [])
            .filter((f: any) =>
              f.latest_ai_suggested_tags_system_file?.metadata?.value?.length > 0 ||
              f.latest_ai_suggested_name_system_file?.metadata?.value ||
              f.latest_ai_suggested_description_system_file?.metadata?.value
            )
            .map((f: any) => f.id)
        )
        setFilesWithAiSuggestions(withSugg)
        setAiSuggestionsStatus(withSugg.size > 0 ? 'found' : updated.ai_suggestions_status === 'processed' ? 'used' : 'none')
        setAiSuggestionsHidden(false)

        if (withSugg.size > 0 && !tagSuggestionsLoaded) {
          semanticTagService.list().then(all => {
            setTagSuggestions(all)
            setTagSuggestionsLoaded(true)
          }).catch(() => { setTagSuggestionsLoaded(true) })
        }

        setSnackbar({ open: true, message: 'AI suggestions are ready.', severity: 'success' })
      })()
    } else if (aiPollStatus === 'timeout') {
      setSnackbar({ open: true, message: 'AI suggestions are still not available. Try refreshing in a few seconds.', severity: 'warning' })
    }
  }, [aiPollStatus, resource?.id, tagSuggestionsLoaded])

  // When a session-uploaded file's AITY pipeline reaches a terminal state, pull a
  // fresh resource so the per-file latest_ai_*_system_file fields surface in the
  // suggestions panel without the user having to Save & refresh manually.
  useEffect(() => {
    if (!resource?.id || sessionUploadedFileIds.size === 0) return

    const justFinished: string[] = []
    sessionUploadedFileIds.forEach((fid) => {
      const entry = aityStatuses[fid]
      if (!entry) return
      if (sessionRefetchedRef.current.has(fid)) return
      if (entry.status === 'ready' || entry.status === 'not_supported') {
        justFinished.push(fid)
      }
    })

    if (justFinished.length === 0 || sessionRefetchInFlightRef.current) return

    sessionRefetchInFlightRef.current = true
    ;(async () => {
      try {
        const updated = await resourceService.getResource(String(resource.id))
        if (updated) {
          setResource(updated)
          recomputeSuggestionsState(updated)
          // Seed the per-file AITY map for any new files in the refetched data
          // so their FileAityCards stop reporting "Not processed".
          seedAityStatusesFromResource(updated, String(resource.id))
          // Reload tag suggestions list so any AITY-coined tags are pickable.
          if (!tagSuggestionsLoaded) {
            semanticTagService.list().then((all) => {
              setTagSuggestions(all)
              setTagSuggestionsLoaded(true)
            }).catch(() => { setTagSuggestionsLoaded(true) })
          }
        }
        justFinished.forEach((fid) => sessionRefetchedRef.current.add(fid))
      } finally {
        sessionRefetchInFlightRef.current = false
      }
    })()
  }, [aityStatuses, sessionUploadedFileIds, resource?.id, recomputeSuggestionsState, tagSuggestionsLoaded, seedAityStatusesFromResource])

  // Restore processed AI suggestions from backend, then transition back to 'found'
  const handleRestoreAiSuggestions = useCallback(async () => {
    if (!resource?.id) return
    try {
      await resourceService.restoreAiSuggestions(resource.id)
      const updated = await resourceService.getResource(String(resource.id))
      if (updated) {
        setResource(updated)
        const withSugg = new Set<string>(
          (updated.files ?? [])
            .filter((f: any) =>
              f.latest_ai_suggested_tags_system_file?.metadata?.value?.length > 0 ||
              f.latest_ai_suggested_name_system_file?.metadata?.value ||
              f.latest_ai_suggested_description_system_file?.metadata?.value
            )
            .map((f: any) => f.id)
        )
        setFilesWithAiSuggestions(withSugg)
        setAiSuggestionsStatus('found')
        setAiSuggestionsHidden(false)
      }
    } catch {
      // silently ignore — the user can try again
    }
  }, [resource?.id])

  // Hub action: re-run resource-level AITY enrichment (regenerates the deduped
  // generated name/description/tag set) and resume suggestion polling.
  const handleReRunAity = useCallback(async () => {
    if (!resource?.id) return
    try {
      await resourceService.aityEnrichResource(String(resource.id))
      startAiSuggestionsPolling()
      setSnackbar({ open: true, message: 'Aity enrichment re-queued.', severity: 'info' })
    } catch {
      setSnackbar({ open: true, message: 'Could not start Aity enrichment.', severity: 'error' })
    }
  }, [resource?.id, startAiSuggestionsPolling])

  // Hub action: run the single-resource synthesis. apply=false generates the unified
  // name/description/tags as suggestions to review; apply=true writes them onto the
  // resource. Runs synchronously server-side, so refetch on success to surface the
  // result immediately (new suggestions, or the applied values).
  const runAityApprove = useCallback(async (apply: boolean) => {
    if (!resource?.id) return
    const rid = String(resource.id)
    try {
      await resourceService.aityApproveResource(rid, apply)
      const fresh = await resourceService.getResource(rid)
      if (fresh) {
        setResource(fresh)
        recomputeSuggestionsState(fresh)
        seedAityStatusesFromResource(fresh, rid)
        if (apply) {
          // Auto-approve wrote ONLY name/description/tags onto the resource. In edit
          // mode those fields bind to editedResource and the chips to the tag state —
          // setResource touches neither — so patch just those fields in. We must NOT
          // deep-clone `fresh` over editedResource: in the create flow `fresh` is still
          // a draft (state: 'draft'), and clobbering would drag that draft
          // state (and any other in-progress edits) back into the form, so the
          // subsequent Save would persist state='draft' and the resource would
          // never leave the draft state / show on the dashboard.
          setEditedResource(prev => prev ? {
            ...prev,
            name: fresh.name,
            description: fresh.description,
            name_origin: (fresh as any).name_origin,
            name_source_file_id: (fresh as any).name_source_file_id,
            description_origin: (fresh as any).description_origin,
            description_source_file_id: (fresh as any).description_source_file_id,
          } : prev)
          const freshTags: SemanticTag[] = ((fresh as any).semanticTags ?? (fresh as any).semantic_tags) ?? []
          const tagIdSet = new Set<number>(freshTags.map((t: any) => Number(t.id)))
          setResourceTagIds(tagIdSet)
          setOriginalTagIds(tagIdSet)
          setSelectedTagsData(freshTags)
        }
      }
      setSnackbar({
        open: true,
        message: apply ? 'Aity metadata generated and applied.' : 'Aity metadata generated — review the suggestions.',
        severity: 'success',
      })
    } catch (err: any) {
      setSnackbar({ open: true, message: err?.message ?? 'Aity metadata generation failed.', severity: 'error' })
    }
  }, [resource?.id, recomputeSuggestionsState, seedAityStatusesFromResource])

  const handleGenerateMetadata = useCallback(() => runAityApprove(false), [runAityApprove])
  const handleAutoApprove      = useCallback(() => runAityApprove(true),  [runAityApprove])

  // Hub action: retry every file whose AITY processing failed.
  const handleRetryFailedAity = useCallback(async () => {
    if (!resource?.id || aityFailedIds.length === 0) return
    await Promise.all(aityFailedIds.map((fid) => handleRetryFileAity(fid)))
  }, [resource?.id, aityFailedIds, handleRetryFileAity])

  // Handle save
  /**
   * Save the edited resource.
   *  - closeAfter: true  → "Save & quit" — preserves the existing behaviour: close
   *    the modal once the save (and any file work) finishes successfully.
   *  - closeAfter: false → "Save & refresh" — stay in the modal, drop edit mode,
   *    re-load the resource and the Activity tab so any background AITY work that
   *    landed during the save is visible. We never overwrite editedResource here
   *    because the user has already committed and the modal returns to view mode.
   */
  const handleSave = useCallback(async ({ closeAfter = true }: { closeAfter?: boolean } = {}) => {
    if (!editedResource) return

    // Validate before saving — include required fields from the collection's scheme
    const schemaFields = getSchemeFields(activeCollection) ?? []

    // Metadata fields required by scheme
    const requiredSchemaFields = schemaFields
      .filter(f => f.required && f.storage === 'metadata')
      .map(f => f.name)

    // Root-level resource fields required by scheme (e.g. 'description')
    const requiredRootFields = schemaFields
      .filter(f => f.required && f.storage === 'column' && f.name === 'description')
      .map(f => f.name)

    const validationErrorsList = validateResourceData(editedResource, requiredSchemaFields, requiredRootFields)
    if (validationErrorsList.length > 0) {
      // Convert to record for display in form
      const errorsRecord: Record<string, string> = {}
      validationErrorsList.forEach(err => {
        errorsRecord[err.field] = err.message
      })
      setValidationErrors(errorsRecord)
      setSaveError(formatValidationErrors(validationErrorsList))
      setSnackbar({ open: true, message: formatValidationErrors(validationErrorsList), severity: 'error' })
      return
    }

    // ── File-role integrity check ─────────────────────────────────────────
    // Allowed configurations only:
    //   - exactly one canonical, rest supporting (Canonical + Supporting mode)
    //   - all files component (Multi-component mode)
    // Compute the EFFECTIVE role of every file that will exist after save —
    // existing files (with pending overrides + canonical promotion/demotion),
    // plus newly added files (with their per-row configMap), excluding
    // anything in filesToRemove.
    if (resource) {
      const effectiveRoles: string[] = []

      ;(resource.files ?? []).forEach((f: any) => {
        if (filesToRemove.includes(f.id)) return
        // Session uploads are represented in newFiles/configMap below.
        if (sessionUploadedFileIds.has(f.id)) return
        let role: string = existingFileConfigs[f.id]?.role ?? f.role
        if (pendingCanonicalChange === f.id) role = 'canonical'
        else if (
          f.role === 'canonical' &&
          pendingCanonicalChange !== undefined &&
          pendingCanonicalChange !== null &&
          pendingCanonicalChange !== f.id
        ) {
          // Old canonical demoted when a different file becomes canonical
          role = existingFileConfigs[f.id]?.role ?? 'supporting'
        }
        effectiveRoles.push(role)
      })

      newFiles.forEach((_file, i) => {
        const role = configMap[i]?.role ?? 'component'
        effectiveRoles.push(role)
      })

      if (effectiveRoles.length > 0) {
        const canonical  = effectiveRoles.filter(r => r === 'canonical').length
        const supporting = effectiveRoles.filter(r => r === 'supporting').length
        const component  = effectiveRoles.filter(r => r === 'component').length

        let roleError: string | null = null
        if (canonical > 1) {
          roleError = `Cannot save: ${canonical} files are marked canonical. Only one canonical is allowed.`
        } else if (canonical === 1 && component > 0) {
          roleError = 'Cannot save: a canonical file and component files cannot coexist. Either keep one canonical with supporting files, or set all files to component.'
        } else if (canonical === 0 && supporting > 0 && component > 0) {
          roleError = 'Cannot save: mixing supporting and component files is not allowed. Either promote one to canonical, or set all to component.'
        } else if (canonical === 0 && supporting > 0 && component === 0) {
          roleError = 'Cannot save: supporting files require a canonical. Promote one file to canonical or change all to component.'
        }

        if (roleError) {
          setSaveError(roleError)
          setSnackbar({ open: true, message: roleError, severity: 'error' })
          return
        }
      }
    }

    setIsSaving(true)
    setSaveError(null)
    setValidationErrors({})

    try {
      // Prepare data for API
      // Get collection_id from either the root level or from the collection array
      let collectionId = editedResource.collection_id
      if (!collectionId && editedResource.collection && editedResource.collection.length > 0) {
        collectionId = editedResource.collection[0].id
      }

      if (!collectionId) {
        console.error('No collection_id found in resource!')
        throw new Error('Collection ID is required but not found in resource data')
      }

      // Call the API - create or update based on mode.
      // Use resource.id (state) first: after a partial save (resource created but files failed)
      // resource.id exists even though resourceId prop is still null, so retry must UPDATE not CREATE.
      const effectiveId = resource?.id || resourceId?.toString() || null
      let result: ResourceData | null = null
      const fileErrors: { name: string; error: string }[] = []

      // Saving must always take the resource out of the 'draft' state — 'draft' is an
      // internal lifecycle value, never user-selectable. If it ever leaks into the form
      // (e.g. a refetch re-seeding from the still-draft resource), coerce it so the save
      // publishes the resource instead of leaving it a draft (invisible + reaper-eligible).
      const stateToSave = (editedResource as any).state && (editedResource as any).state !== 'draft'
        ? (editedResource as any).state
        : 'live'

      if (effectiveId === null) {
        // Create: POST JSON to /resources, then upload files separately
        result = await resourceService.createResource({
          name: editedResource.name,
          type: editedResource.type,
          collection_id: Number(collectionId),
          state: stateToSave,
          description: editedResource.description || undefined,
          language: editedResource.metadata?.language,
          metadata: editedResource.metadata,
          payload: editedResource.payload as any,
        })

        // Create-path: the resource didn't exist when the user dropped files, so the
        // edit-path's eager-upload was skipped. We still need to upload newFiles here,
        // using legacy uploadFile (no defer_commit — the resource is being committed in
        // this same save).
        const newPreviewIndexCreate = newPreview ? newFiles.indexOf(newPreview) : -1
        const newPreviewIsCanonicalCreate = newPreviewIndexCreate >= 0 && configMap[newPreviewIndexCreate]?.role === 'canonical'

        const hasCanonicalInBatch = Object.values(configMap).some(c => c.role === 'canonical')
        const snapshotRoleCreate = hasCanonicalInBatch ? 'supporting' : 'component'
        const snapshotRelationCreate = hasCanonicalInBatch ? 'rendition' : undefined
        if (result && newPreview && !newPreviewIsCanonicalCreate) {
          try {
            await resourceService.uploadFile(result.id, newPreview, snapshotRoleCreate, snapshotRelationCreate, ['snapshot'])
          } catch (err) {
            fileErrors.push({ name: newPreview.name, error: err instanceof Error ? err.message : 'Upload failed' })
          }
        }

        let pendingSnapshotIdCreate: string | null = null
        if (result && newFiles.length > 0) {
          for (let i = 0; i < newFiles.length; i++) {
            const file = newFiles[i]
            if (!newPreviewIsCanonicalCreate && file === newPreview) continue
            const cfg = configMap[i]
            try {
              const uploaded = await resourceService.uploadFile(result.id, file, cfg?.role ?? 'canonical', cfg?.relation, undefined)
              if (newPreviewIsCanonicalCreate && file === newPreview && uploaded?.id) {
                pendingSnapshotIdCreate = uploaded.id
              }
            } catch (err) {
              fileErrors.push({ name: file.name, error: err instanceof Error ? err.message : 'Upload failed' })
            }
          }
        }

        if (result && (newPreview || newFiles.length > 0)) {
          result = await resourceService.getResource(result.id) ?? result
        }

        if (result && pendingSnapshotIdCreate && result.files?.some((f: any) => f.id === pendingSnapshotIdCreate)) {
          await resourceService.setFileSnapshot(result.id, pendingSnapshotIdCreate)
          result = await resourceService.getResource(result.id) ?? result
        }
      } else {
        // Update: PUT /resources/{id} with flat JSON, then upload new files separately
        result = await resourceService.updateResource(effectiveId, {
          name: editedResource.name,
          type: editedResource.type,
          collection_id: Number(collectionId),
          description: editedResource.description,
          state: stateToSave,
          metadata: editedResource.metadata,
          payload: editedResource.payload as any,
          language: editedResource.metadata?.language,
        })

        if (result && filesToRemove.length > 0) {
          for (const fileId of filesToRemove) {
            await resourceService.deleteFile(result.id, fileId)
          }
        }

        // Session uploads are already on the server (defer_commit=true). Flip them to
        // committed in one shot — the only thing Save still has to do for files.
        const hadUncommittedSessionFiles = sessionUploadedFileIds.size > 0 || pendingSessionFiles.length > 0
        if (result) {
          try {
            await resourceService.commitFiles(result.id)
          } catch (err) {
            fileErrors.push({ name: 'session files', error: err instanceof Error ? err.message : 'Commit failed' })
          }
        }

        // Also refetch when we just committed session files, so the stale
        // uncommitted_at/uncommitted_by markers drop out of resource.files and
        // hasUnsavedChanges() stops seeing them as pending.
        if (result && (
          filesToRemove.length > 0 ||
          newFiles.length > 0 ||
          newPreview ||
          snapshotFileId !== undefined ||
          hadUncommittedSessionFiles
        )) {
          result = await resourceService.getResource(result.id) ?? result
        }
      }

      if (result) {
        // Apply canonical change — demotes the old canonical, promotes the new one
        if (pendingCanonicalChange) {
          await resourceService.setFileCanonical(result.id, pendingCanonicalChange)
          result = await resourceService.getResource(result.id) ?? result
        }

        // Apply snapshot change for an existing file (new-file snapshot is handled via upload or setFileSnapshot above)
        if (snapshotFileId && result.files?.some((f: any) => f.id === snapshotFileId)) {
          await resourceService.setFileSnapshot(result.id, snapshotFileId)
          result = await resourceService.getResource(result.id) ?? result
        }

        // Apply role/relation changes to existing files (skip files handled by setFileCanonical above)
        const fileConfigEntries = Object.entries(existingFileConfigs)
        if (fileConfigEntries.length > 0) {
          await Promise.all(
            fileConfigEntries
              .filter(([fileId]) => fileId !== pendingCanonicalChange)
              .map(([fileId, cfg]) =>
                resourceService.updateFile(result!.id, fileId, {
                  role: cfg.role,
                  relation: cfg.relation ?? null,
                })
              )
          )
          result = await resourceService.getResource(result.id) ?? result
        }

        // Apply workspace membership changes
        const toAdd = [...resourceWorkspaceIds].filter(id => !originalWorkspaceIds.has(id))
        const toRemove = [...originalWorkspaceIds].filter(id => !resourceWorkspaceIds.has(id))
        const wsErrors = (await Promise.all([
          ...toAdd.map(id => workspaceService.addResource(id, result!.id)),
          ...toRemove.map(id => workspaceService.removeResource(id, result!.id)),
        ])).filter(Boolean) as string[]
        if (wsErrors.length > 0) {
          throw new Error('Workspace update failed: ' + wsErrors.join('; '))
        }
        setOriginalWorkspaceIds(new Set(resourceWorkspaceIds))

        // Apply entity_type changes for existing saved tags (organization-level shared update)
        if (Object.keys(tagTypeOverrides).length > 0) {
          await Promise.all(
            Object.entries(tagTypeOverrides).map(([idStr, newType]) => {
              const tagId = Number(idStr)
              const tag = selectedTagsData.find(t => t.id === tagId)
              if (!tag) return Promise.resolve()
              return semanticTagService.update(tagId, { entity_type: newType, label: tag.label })
            })
          )
          setSelectedTagsData(prev =>
            prev.map(t => {
              const override = tagTypeOverrides[t.id as number]
              return override ? { ...t, entity_type: override } : t
            })
          )
          setTagTypeOverrides({})
        }

        // Find-or-create any accepted AI-suggested tags, then sync all associations.
        // The backend store endpoint is idempotent: same (label, org, entity_type) always
        // returns the canonical tag, creating or reactivating it as needed.
        let newTagIds: number[] = []
        if (pendingSuggestedTags.length > 0) {
          const created = await Promise.all(
            pendingSuggestedTags.map(t => {
              const entityType = (t.type && t.type in ENTITY_TYPES) ? t.type as EntityTypeKey : 'tag'
              return semanticTagService.create({
                label: t.label,
                description: t.description ?? undefined,
                entity_type: entityType,
                vocabulary: 'ai_generated',
                reviewer:   'user',
              })
            })
          )
          newTagIds = created.map(t => t.id)
          setPendingSuggestedTags([])
        }

        // Sync semantic tag membership — always run so removals are applied even
        // when there are no new pending tags.
        const allTagIds = [...resourceTagIds, ...newTagIds]
        const hasTagChanges = allTagIds.length !== originalTagIds.size
          || allTagIds.some(id => !originalTagIds.has(id))
          || [...originalTagIds].some(id => !allTagIds.includes(id))
        if (hasTagChanges) {
          await semanticTagService.syncResource(result.id, allTagIds)
          setResourceTagIds(new Set(allTagIds))
          setOriginalTagIds(new Set(allTagIds))
        }

        setResource(result)
        // Transient file/tag pickers always reset — they were committed by the save.
        setNewFiles([])
        setFilesToRemove([])
        setConfigMap({})
        setExistingFileConfigs({})
        setNewPreview(null)
        setSnapshotFileId(undefined)
        // Reset to `undefined` (matches the initial useState value) so the
        // dirty check `pendingCanonicalChange !== undefined` stops firing —
        // `null` would leave the resource looking dirty after every save.
        setPendingCanonicalChange(undefined)
        setValidationErrors({})
        setExistingTagEditId(null)
        setExistingTagEditAnchor(null)
        // commit-files cleared uncommitted_at on the server for our session uploads,
        // so drop the local tracking too.
        setSessionUploadedFileIds(new Set())
        sessionRefetchedRef.current = new Set()

        // For "Save & quit" we drop edit mode (the modal is about to close anyway).
        // For "Save & refresh" we keep edit mode so the user can continue editing
        // from the freshly-saved state — see the post-save branch below where we
        // re-seed editedResource from the refetched resource.
        if (closeAfter) {
          setEditedResource(null)
          setIsEditMode(false)
        }

        // The draft (if any) is now committed — stop the exit cleanup from deleting it.
        draftSavedRef.current = true
        setCreatedDraftId(null)

        // Notify parent — pass the resource on a true first-time create. With the
        // draft model the row already exists (effectiveId non-null), so also pass it
        // when we created the draft in this session so the list treats it as new.
        onResourceSaved?.((effectiveId === null || createdDraftId) ? result : undefined)

        if (fileErrors.length > 0) {
          const detail = fileErrors.map(f => {
            const err = f.error.replace(/\.$/, '') // strip trailing period to avoid double-period
            return `"${f.name}": ${err}`
          }).join(' · ')
          const msg = `Resource saved, but ${fileErrors.length === 1 ? 'a file' : `${fileErrors.length} files`} could not be uploaded — ${detail}. Open the resource to retry.`
          setSaveError(msg)
          setSnackbar({ open: true, message: msg, severity: 'warning' })
        } else if (closeAfter) {
          onClose()
        } else {
          // Save & refresh: re-pull the freshest resource (background AITY may
          // have applied changes during the save) and reload the activity log
          // so the new audit rows show up immediately on the Activity tab.
          // Stay in edit mode and re-seed editedResource from the fresh data so
          // the user can keep editing without re-clicking Edit.
          let fresh: ResourceData | null = result
          if (result?.id) {
            const refetched = await resourceService.getResource(String(result.id))
            if (refetched) {
              fresh = refetched
              setResource(refetched)
            }
          }
          setEditedResource(fresh ? JSON.parse(JSON.stringify(fresh)) : null)
          // Re-derive the AITY suggestions banner state so the Show/Hide control
          // reflects the freshly-saved resource (e.g. if all suggestions just got
          // applied, status should flip from 'found' to 'used').
          if (fresh) recomputeSuggestionsState(fresh)
          // Re-seed the per-file AITY polling map so freshly-committed files
          // surface their actual processing state instead of "Not processed".
          if (fresh?.id) seedAityStatusesFromResource(fresh, String(fresh.id))
          // Force EditableFiles to remount so its internal newFiles/filesToRemove
          // arrays reset — otherwise the just-uploaded files appear twice.
          setEditorRemountKey(k => k + 1)
          setBackgroundRefreshPending(false)
          loadActivityLog()
          setSnackbar({ open: true, message: 'Saved — keep editing.', severity: 'success' })
        }
      } else {
        throw new Error('Failed to save resource')
      }
    } catch (err) {
      let errorMessage = 'Failed to save resource'
      if (err instanceof Error) {
        errorMessage = err.message
      } else if (typeof err === 'string') {
        errorMessage = err
      } else {
        // If it's an object, try to stringify it
        try {
          errorMessage = JSON.stringify(err)
        } catch {
          errorMessage = 'An unknown error occurred'
        }
      }

      console.error('Save error:', err)
      setSaveError(errorMessage)
      setSnackbar({ open: true, message: errorMessage, severity: 'error' })
    } finally {
      setIsSaving(false)
    }
  }, [editedResource, resourceId, newFiles, filesToRemove, configMap, existingFileConfigs, newPreview, snapshotFileId, pendingCanonicalChange, resourceWorkspaceIds, originalWorkspaceIds, resourceTagIds, originalTagIds, pendingSuggestedTags])

  // ── Unified exit flow (used by both Cancel and X) ──────────────────────────
  // Cancel and X share the same dialog. The only difference is what happens
  // AFTER the user picks Keep / Remove:
  //   target='cancel' → drop edit mode, stay in the modal
  //   target='close'  → drop edit mode AND close the modal
  //
  // Flow on click:
  //   - If there are session uploads (in-flight or already on server with
  //     uncommitted_at set), show the dialog with Keep / Remove buttons.
  //   - Else if edit mode and unsaved metadata changes, show a confirm dialog
  //     (no Keep/Remove buttons, since there's nothing to keep/remove).
  //   - Else just proceed (no prompt needed).

  // Create mode: if a draft was created this session but never saved, hard-delete
  // it on exit (mirrors the wizard — deleteResource hard-deletes a `draft` outright).
  // Best-effort and fire-and-forget so closing the modal stays instant; the hourly
  // reaper is the backstop for anything this misses (crash / tab close).
  const discardCreateDraftIfAny = useCallback(() => {
    if (initialMode === 'create' && createdDraftId && !draftSavedRef.current) {
      const id = createdDraftId
      setCreatedDraftId(null)
      try { abortAllUploadsRef.current?.() } catch { /* ignore */ }
      resourceService.deleteResource(id).catch(() => { /* reaper backstops */ })
    }
  }, [initialMode, createdDraftId])

  const finishExit = useCallback(() => {
    discardCreateDraftIfAny()
    if (isEditMode) handleCancelEdit()
    if (exitTarget === 'close') onClose()
    try { Object.values(tempFileUrls).forEach(url => URL.revokeObjectURL(url)) } catch { /* ignore */ }
  }, [discardCreateDraftIfAny, isEditMode, handleCancelEdit, exitTarget, onClose, tempFileUrls])

  const startExit = useCallback((target: ExitTarget) => {
    const hasUploadActivity = uploadingCount > 0 || sessionUploadedFileIds.size > 0
    setExitTarget(target)
    if (hasUploadActivity) {
      setExitStage('asking')
      return
    }
    if (isEditMode && hasUnsavedChanges()) {
      setExitStage('asking')
      return
    }
    // Nothing dirty — close/cancel immediately. Compute the after-action inline
    // so we don't have to wait for the exitTarget setState round-trip.
    discardCreateDraftIfAny()
    if (isEditMode) handleCancelEdit()
    if (target === 'close') onClose()
    try { Object.values(tempFileUrls).forEach(url => URL.revokeObjectURL(url)) } catch { /* ignore */ }
  }, [uploadingCount, sessionUploadedFileIds, isEditMode, hasUnsavedChanges, handleCancelEdit, onClose, tempFileUrls, discardCreateDraftIfAny])

  const handleClose        = useCallback(() => startExit('close'),  [startExit])
  const handleCancelClick  = useCallback(() => startExit('cancel'), [startExit])

  const handleExitStay = useCallback(() => setExitStage('idle'), [])

  const handleExitKeep = useCallback(() => {
    // Abort anything still mid-flight (those XHRs never reach the server, so
    // nothing to clean up). Already-completed uploads stay on the server with
    // uncommitted_at set and surface in the recovery dialog next time.
    try { abortAllUploadsRef.current?.() } catch { /* ignore */ }
    setExitStage('idle')
    finishExit()
  }, [finishExit])

  const handleExitRemove = useCallback(async () => {
    setExitStage('removing')
    try { abortAllUploadsRef.current?.() } catch { /* ignore */ }
    const idsToDelete = Array.from(sessionUploadedFileIds)
    if (resource?.id) {
      for (const fid of idsToDelete) {
        try { await resourceService.deleteFile(resource.id, fid) } catch { /* ignore */ }
      }
      try {
        const fresh = await resourceService.getResource(resource.id)
        if (fresh) setResource(fresh)
      } catch { /* ignore */ }
    }
    setExitStage('idle')
    finishExit()
  }, [sessionUploadedFileIds, resource?.id, finishExit])

  // ── Previous-session recovery dialog ───────────────────────────────────────
  // Auto-open when the modal opens and there are unresolved previous-session
  // files for this user. The user MUST pick Accept or Discard before doing
  // anything else with the resource.
  useEffect(() => {
    if (!open) { setRecoveryDialogOpen(false); return }
    if (previousSessionFiles.length > 0 && !recoveryBusy) {
      setRecoveryDialogOpen(true)
    } else if (previousSessionFiles.length === 0) {
      setRecoveryDialogOpen(false)
    }
  }, [open, previousSessionFiles.length, recoveryBusy])

  const handleRecoveryAccept = useCallback(async () => {
    if (!resource?.id) return
    setRecoveryBusy('accept')
    try {
      await resourceService.commitFiles(resource.id)
      const fresh = await resourceService.getResource(resource.id)
      if (fresh) {
        setResource(fresh)
        recomputeSuggestionsState(fresh)
      }
      setRecoveryDialogOpen(false)
    } catch (e: any) {
      setSnackbar({ open: true, message: `Could not accept files: ${e?.message ?? 'unknown error'}`, severity: 'error' })
    } finally {
      setRecoveryBusy(null)
    }
  }, [resource?.id, recomputeSuggestionsState])

  const handleRecoveryDiscard = useCallback(async () => {
    if (!resource?.id) return
    setRecoveryBusy('discard')
    try {
      for (const f of previousSessionFiles) {
        try { await resourceService.deleteFile(resource.id, f.id) } catch { /* ignore */ }
      }
      const fresh = await resourceService.getResource(resource.id)
      if (fresh) {
        setResource(fresh)
        recomputeSuggestionsState(fresh)
      }
      setRecoveryDialogOpen(false)
    } finally {
      setRecoveryBusy(null)
    }
  }, [resource?.id, previousSessionFiles, recomputeSuggestionsState])

  // Get resource name (from root level)
  const getResourceName = (): string => {
    if (!resource) return ''

    // Try root level name
    if (resource.name) return resource.name

    // Try file name
    if (resource.files && Array.isArray(resource.files) && resource.files.length > 0) {
      const fileName = resource.files[0].file_name
      if (fileName) return fileName
    }

    return 'Untitled Resource'
  }

  // Get resource type label
  const getResourceType = (): string => {
    if (!resource) return ''
    return resource.type?.toUpperCase() || 'MULTIMEDIA'
  }

  // Render metadata section
  const renderBasicInfo = () => {
    if (!resource) return null

    // Description is now at root level
    const hasDescription = resource.description && resource.description.trim().length > 0

    return (
      <Stack spacing={0}>
        {/* Block 1: Name, Description, Type, Collection, Active Status */}
        <Box sx={{ py: 2, px: 2, bgcolor: 'grey.100' }}>
          {/* Top row: name+ID left, type+active right */}
          <Stack direction="row" alignItems="flex-start" justifyContent="space-between" spacing={2}>
            <Stack spacing={0.25} sx={{ flex: 1, minWidth: 0 }}>
              <Typography variant="h5" sx={{ fontWeight: 600, color: 'text.primary', lineHeight: 1.2 }}>
                {getResourceName()}
              </Typography>
              <Typography variant="caption" sx={{ fontFamily: 'monospace', color: 'text.disabled', fontSize: '0.75rem' }}>
                {resource.id}
              </Typography>
            </Stack>
            <Stack direction="row" spacing={0.75} alignItems="center" sx={{ flexShrink: 0, pt: 0.5 }}>
              <Chip
                label={getResourceType().toUpperCase()}
                size="small"
                sx={{ bgcolor: 'primary.subtle', color: 'primary.main', fontWeight: 600, letterSpacing: 0.5 }}
              />
              <Chip
                label={resource.active ? 'Active' : 'Inactive'}
                size="small"
                sx={resource.active
                  ? { bgcolor: 'success.light', color: 'success.main' }
                  : { bgcolor: 'grey.100', color: 'text.secondary' }
                }
              />
            </Stack>
          </Stack>
          {/* Description below, full width */}
          <Box sx={{ mt: 1.5 }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 0.5 }}>
              Description
            </Typography>
            {hasDescription ? (
              <Typography variant="body2" color="text.secondary" sx={{ whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>
                {resource.description}
              </Typography>
            ) : (
              <Typography variant="body2" color="text.disabled" sx={{ fontStyle: 'italic' }}>
                No description
              </Typography>
            )}
          </Box>
        </Box>

        {/* Block 2a: Workspaces */}
        {(() => {
          const wsArray: Array<{ id: number; name: string }> = resource.workspaces ?? resource.workspace ?? []
          if (wsArray.length === 0) return null
          return (
            <Box sx={{ py: 2, px: 2, bgcolor: 'grey.50' }}>
                <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1 }}>
                  Workspaces
                </Typography>
                <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
                  {wsArray.map((ws) => (
                    <Chip
                      key={ws.id}
                      label={ws.name}
                      size="small"
                      sx={{ bgcolor: 'secondary.main', color: 'white' }}
                    />
                  ))}
                </Stack>
            </Box>
          )
        })()}

        {/* Block 2b: Semantic Tags */}
        {resource.semanticTags && Array.isArray(resource.semanticTags) && resource.semanticTags.length > 0 && (
          <Box sx={{ py: 2, px: 2, bgcolor: 'grey.50' }}>
              <Typography variant="subtitle2" gutterBottom sx={{ fontWeight: 600 }}>
                Tags ({resource.semanticTags.length})
              </Typography>
              <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap>
                {resource.semanticTags.map((tag: any) => {
                  const entityKey = tag.entity_type && tag.entity_type in ENTITY_TYPES ? tag.entity_type as EntityTypeKey : null
                  const et = entityKey ? ENTITY_TYPES[entityKey] : null
                  const color = et?.color ?? null
                  const EntityIcon = et?.Icon ?? null
                  const vocab    = (tag.vocabulary ?? 'organization') as string
                  const reviewer = (tag.reviewer   ?? 'user')         as string
                  return (
                    <Tooltip
                      key={tag.id}
                      placement="top"
                      arrow
                      title={
                        <Typography variant="caption" sx={{ textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                          Generated by {vocab}, reviewed by {reviewer}
                        </Typography>
                      }
                    >
                      <Box
                        sx={{
                          display: 'inline-flex',
                          flexDirection: 'row',
                          alignItems: 'stretch',
                          border: '1px solid',
                          borderColor: color ?? 'divider',
                          borderRadius: 1.5,
                          overflow: 'hidden',
                          minWidth: 80,
                        }}
                      >
                        {EntityIcon && (
                          <Box sx={{ bgcolor: color, display: 'flex', alignItems: 'center', justifyContent: 'center', px: 0.875 }}>
                            <EntityIcon sx={{ fontSize: '0.875rem', color: 'white' }} />
                          </Box>
                        )}
                        <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', bgcolor: color ? `${color}12` : 'grey.50' }}>
                          <Typography sx={{ fontSize: '0.75rem', fontWeight: 600, lineHeight: 1.4, color: 'text.primary', px: 1, pt: 0.4, pb: 0.2 }}>
                            {tag.label}
                          </Typography>
                          <Box sx={{ height: '1px', bgcolor: color ?? 'divider', opacity: 0.25, mx: 1 }} />
                          <Typography sx={{ fontSize: '0.65rem', fontWeight: 500, lineHeight: 1.35, color: color ?? 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.4, px: 1, pt: 0.15, pb: 0.4, opacity: 0.9 }}>
                            {vocab}
                          </Typography>
                        </Box>
                      </Box>
                    </Tooltip>
                  )
                })}
              </Stack>
          </Box>
        )}

        {/* Block 2c: Schema-driven metadata fields (view mode) */}
        {(() => {
          const metadataFields = (getSchemeFields(activeCollection) ?? []).filter(f => f.storage === 'metadata')
          if (metadataFields.length === 0) return null
          return (
            <Box sx={{ py: 2, px: 2, bgcolor: 'grey.100' }}>
                <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1 }}>
                  Collection Metadata
                </Typography>
                <Stack spacing={1}>
                  {metadataFields.map((f) => {
                    const label = f.display_name || f.name
                    const value = resource.metadata?.[f.name]
                    return (
                      <Stack key={f.name} direction="row" spacing={2} alignItems="baseline">
                        <Typography variant="body2" sx={{ fontWeight: 500, color: 'text.secondary', minWidth: 140 }}>
                          {label}:
                        </Typography>
                        <Typography variant="body2" color={value ? 'text.primary' : 'text.disabled'} sx={{ fontStyle: value ? 'normal' : 'italic' }}>
                          {value ?? 'Not set'}
                        </Typography>
                      </Stack>
                    )
                  })}
                </Stack>
            </Box>
          )
        })()}

        {/* Block 3: Dates */}
        {(resource.created_at || resource.updated_at || resource.published_at) && (
          <Box sx={{ py: 2, px: 2, bgcolor: 'grey.50' }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1.5 }}>
              Dates
            </Typography>
            {(() => {
              const dateEntries = [
                { key: 'created',   label: 'Created',   value: resource.created_at },
                { key: 'updated',   label: 'Updated',   value: resource.updated_at },
                { key: 'published', label: 'Published', value: resource.published_at },
              ].filter(d => !!d.value)
              const fmt = (iso: string) => new Date(iso).toLocaleDateString('en-US', {
                year: 'numeric', month: 'short', day: 'numeric',
                hour: '2-digit', minute: '2-digit',
              })
              return (
                <Stack direction="row" spacing={3}>
                  {dateEntries.map(({ key, label, value }) => (
                    <Stack key={key} spacing={0.25}>
                      <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                        {label}
                      </Typography>
                      <Typography variant="body2" sx={{ color: 'text.primary' }}>
                        {fmt(value!)}
                      </Typography>
                    </Stack>
                  ))}
                </Stack>
              )
            })()}
          </Box>
        )}

        {/* Block 4: Visibility & Access */}
        {(() => {
          const visibilityChip: Record<string, { bgcolor: string; color: string }> = {
            private:      { bgcolor: 'grey.100',       color: 'text.secondary' },
            organization: { bgcolor: 'primary.subtle', color: 'primary.main' },
            workspace:    { bgcolor: 'secondary.light', color: 'secondary.main' },
            public:       { bgcolor: 'success.light',  color: 'success.main' },
          }
          const vis = resource.visibility ?? 'private'
          const visStyle = visibilityChip[vis] ?? visibilityChip.private
          const accessOptions = [
            { key: 'downloadable', label: 'Downloadable' },
            { key: 'public',       label: 'Public' },
            { key: 'featured',     label: 'Featured' },
          ]
          return (
            <Box sx={{ py: 2, px: 2, bgcolor: 'grey.100' }}>
              <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1 }}>
                Visibility & Access
              </Typography>
              <Stack spacing={1}>
                <Stack direction="row" spacing={2} alignItems="center">
                  <Typography variant="body2" sx={{ fontWeight: 500, color: 'text.secondary', minWidth: 120 }}>
                    Visibility:
                  </Typography>
                  <Chip
                    label={vis.charAt(0).toUpperCase() + vis.slice(1)}
                    size="small"
                    sx={{ bgcolor: visStyle.bgcolor, color: visStyle.color }}
                  />
                </Stack>
                <Stack direction="row" spacing={2} alignItems="center">
                  <Typography variant="body2" sx={{ fontWeight: 500, color: 'text.secondary', minWidth: 120 }}>
                    Access:
                  </Typography>
                  <Stack direction="row" spacing={0.75}>
                    {accessOptions.map(({ key, label }) => {
                      const on = resource.payload?.[key] ?? (key === 'downloadable')
                      return (
                        <Chip
                          key={key}
                          label={label}
                          size="small"
                          sx={on
                            ? { bgcolor: 'primary.subtle', color: 'primary.main' }
                            : { bgcolor: 'grey.100', color: 'text.disabled' }
                          }
                        />
                      )
                    })}
                  </Stack>
                </Stack>
              </Stack>
            </Box>
          )
        })()}

      </Stack>
    )
  }

  // Render Files section
  const renderFiles = () => {
    // In edit mode, show editable file management
    if (isEditMode && editedResource) {
      return (
        <EditableFiles
          key={editorRemountKey}
          resource={editedResource}
          acceptedMimeTypes={activeCollection?.scheme?.accepted_mimetypes ?? []}
          selectedFileIndex={selectedFileIndex}
          snapshotFileId={snapshotFileId !== undefined ? snapshotFileId : resource?.snapshot_file?.id ?? null}
          onSnapshotChange={(fileId) => setSnapshotFileId(fileId)}
          onFileClick={(index) => setSelectedFileIndex(index)}
          onFilesChange={(files, toRemove, configs) => {
            // Only recreate blob URLs / jump the viewer when files are actually added or removed.
            // Role/relation changes trigger onFilesChange too, but the File objects don't change —
            // revoking URLs on every dropdown change would flicker the gallery preview.
            const prevCount = newFiles.length
            const nextCount = files.length
            const filesAdded   = nextCount > prevCount
            const filesRemoved = nextCount < prevCount

            if (filesAdded || filesRemoved) {
              // Revoke old URLs and build fresh ones
              Object.values(tempFileUrls).forEach(url => URL.revokeObjectURL(url))
              const newUrls: Record<number, string> = {}
              files.forEach((file, index) => {
                newUrls[index] = URL.createObjectURL(file)
              })
              setTempFileUrls(newUrls)

              // Auto-select the newly added file (do NOT jump on removal or config change).
              // existingCount must mirror allFiles' deduped existing side: exclude both
              // removed files AND session-uploaded files (which also live in newFiles), or
              // the target index lands past the end ("File 5 of 4").
              if (filesAdded) {
                const existingCount = resource?.files?.filter((f: any) =>
                  !toRemove.includes(f.id) && !sessionUploadedFileIds.has(f.id)
                ).length || 0
                setSelectedFileIndex(existingCount + nextCount - 1)
              }
            }

            setNewFiles(files)
            setFilesToRemove(toRemove)
            setConfigMap(configs)
          }}
          onExistingConfigsChange={(configs) => setExistingFileConfigs(configs)}
          onPreviewChange={(preview) => setNewPreview(preview)}
          onCanonicalChange={(fileId) => setPendingCanonicalChange(fileId)}
          onRejectedFilesChange={(hasRejected) => setHasRejectedFiles(hasRejected)}
          aityStatuses={aityStatuses}
          onRetryAity={handleRetryFileAity}
          sessionUploadedFileIds={sessionUploadedFileIds}
          onUploadingCountChange={setUploadingCount}
          onUploadedIdsChange={setSessionUploadedIdByIndex}
          abortAllUploadsRef={abortAllUploadsRef}
          addFilesRef={addFilesRef}
          canonicalToggleRef={canonicalToggleRef}
          onEnsureDraft={ensureDraft}
          onSessionUploadComplete={(fileId, rid) => {
            // rid is passed explicitly — in create mode it's the freshly-created
            // draft id, which the stale `resource` closure wouldn't have yet.
            setSessionUploadedFileIds((prev) => {
              if (prev.has(fileId)) return prev
              const next = new Set(prev)
              next.add(fileId)
              return next
            })
            startAityPolling(rid, [fileId])
            // Pull the just-uploaded file into resource.files NOW so the Files tab
            // illuminates, the AITY accordion appears, and the hub flips out of
            // "No aity" — without waiting for AITY to finish or a manual refresh.
            ;(async () => {
              try {
                const fresh = await resourceService.getResource(rid)
                if (fresh) {
                  setResource(fresh)
                  recomputeSuggestionsState(fresh)
                  seedAityStatusesFromResource(fresh, rid)
                }
              } catch { /* polling + the terminal-refetch effect will catch up */ }
            })()
          }}
        />
      )
    }

    // View mode - show original read-only display
    if (!resource || !resource.files || !Array.isArray(resource.files) || resource.files.length === 0) {
      return (
        <Box sx={{ textAlign: 'center', py: 4 }}>
          <Typography variant="body1" color="text.secondary">
            No files available for this resource
          </Typography>
        </Box>
      )
    }

    return (
      <Stack spacing={3}>
        <Box>
          <Stack direction="row" alignItems="center" sx={{ mb: 1 }}>
            <Typography variant="h6" sx={{ fontWeight: 600, flex: 1 }}>
              Files ({resource.files.length})
            </Typography>
            {(() => {
              const hasCanonical = resource.files.some((f: any) => f.role === 'canonical')
              return (
                <Chip
                  size="small"
                  label={hasCanonical ? 'Canonical + Supporting' : 'Multi-component'}
                  color={hasCanonical ? 'primary' : 'default'}
                  variant={hasCanonical ? 'filled' : 'outlined'}
                  sx={{ fontSize: '0.7rem', height: 20 }}
                />
              )
            })()}
          </Stack>
          <Stack spacing={1}>
            {resource.files.map((file: any, index: number) => (
              <Paper
                key={index}
                variant="outlined"
                onClick={() => setSelectedFileIndex(index)}
                sx={{
                  p: 2,
                  cursor: 'pointer',
                  transition: 'all 0.2s',
                  bgcolor: selectedFileIndex === index ? 'primary.50' : 'white',
                  borderColor: selectedFileIndex === index ? 'primary.main' : 'divider',
                  boxShadow: selectedFileIndex === index ? 6 : 0,
                  '&:hover': {
                    boxShadow: 2,
                    borderColor: 'primary.main',
                    bgcolor: 'primary.50',
                  },
                }}
              >
                <Stack spacing={0.5}>
                  {/* ── Row 1: filename + preview badge ── */}
                  <Stack direction="row" alignItems="center" spacing={1}>
                    <Typography
                      variant="body2"
                      sx={{
                        flex: 1,
                        color: 'primary.main',
                        fontWeight: selectedFileIndex === index ? 700 : 500,
                      }}
                    >
                      {file.filename || file.original_name || 'Unnamed file'}
                    </Typography>
                    {Array.isArray(file.usage) && file.usage.includes('snapshot') && (
                      <Stack direction="row" alignItems="center" spacing={0.25}>
                        <Star sx={{ fontSize: '0.875rem', color: 'warning.main' }} />
                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500, fontSize: '0.75rem' }}>
                          Snapshot
                        </Typography>
                      </Stack>
                    )}
                  </Stack>

                  {/* ── Row 2: MIME / size / purpose · contributing · chevron ── */}
                  <Stack direction="row" alignItems="center" spacing={0.5}>
                    <Typography variant="caption" color="text.secondary" sx={{ flex: 1 }}>
                      MIME: {file.mime_type || 'unknown'}
                      {file.size && <> • Size: {(file.size / 1024 / 1024).toFixed(2)} MB</>}
                      {file.role && <> • Role: {file.role}</>}
                      {file.relation && <> • {file.relation}</>}
                    </Typography>
                    {file.role === 'canonical' && (
                      <Stack direction="row" alignItems="center" spacing={0.25}>
                        <Hub sx={{ fontSize: '0.75rem', color: 'primary.main' }} />
                        <Typography variant="caption" sx={{ color: 'text.secondary', fontWeight: 500, fontSize: '0.75rem' }}>
                          Canonical
                        </Typography>
                      </Stack>
                    )}
                    {(() => {
                      const isExpanded = expandedFiles.has(index)
                      return (
                        <Tooltip title={isExpanded ? 'Collapse' : 'Show Aity status & extracted metadata'} placement="left">
                          <IconButton
                            size="small"
                            onClick={(e) => {
                              e.stopPropagation()
                              setExpandedFiles(prev => {
                                const next = new Set(prev)
                                next.has(index) ? next.delete(index) : next.add(index)
                                return next
                              })
                            }}
                            sx={{ color: isExpanded ? 'primary.main' : 'action.disabled', p: 0.25 }}
                          >
                            {isExpanded ? <ExpandLess fontSize="small" /> : <ExpandMore fontSize="small" />}
                          </IconButton>
                        </Tooltip>
                      )
                    })()}
                  </Stack>

                  {file.dam_url && (
                    <Typography
                      variant="caption"
                      sx={{ fontFamily: 'monospace', fontSize: '0.75rem', wordBreak: 'break-all', color: 'text.secondary' }}
                    >
                      {file.dam_url}
                    </Typography>
                  )}

                  {/* ── Expanded: AiTy status + tika metadata ── */}
                  <Collapse in={expandedFiles.has(index)} unmountOnExit>
                    <Box
                      sx={{ mt: 1, pt: 1, borderTop: 1, borderColor: 'divider', maxHeight: 360, overflowY: 'auto' }}
                      onClick={e => e.stopPropagation()}
                    >
                      <Stack spacing={1.5}>
                        {/* Always-present AiTy status row */}
                        <FileAityCard
                          file={file}
                          pollEntry={aityStatuses[file.id] ?? null}
                          onRetry={() => handleRetryFileAity(String(file.id))}
                          canRetry={false}
                          acceptedTagLabels={acceptedTagLabels}
                        />

                        {/* Tika extracted metadata — only when available */}
                        {(() => {
                          const tika = file.latest_tika_system_file?.metadata?.tika_metadata
                          const hasTika = tika && Object.keys(tika).length > 0
                          if (!hasTika) return null
                          return (
                              <Box>
                                <Stack direction="row" alignItems="center" spacing={0.5} sx={{ mb: 0.75 }}>
                                  <HubOutlined sx={{ fontSize: '0.875rem', color: 'text.secondary' }} />
                                  <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', textTransform: 'uppercase', letterSpacing: 0.5 }}>
                                    Extracted Metadata
                                  </Typography>
                                </Stack>
                                <Stack spacing={0.25}>
                                  {Object.entries(tika).map(([key, val]) => (
                                    <Stack key={key} direction="row" spacing={1} alignItems="flex-start">
                                      <Typography
                                        variant="caption"
                                        sx={{
                                          minWidth: 160,
                                          maxWidth: 160,
                                          color: 'text.secondary',
                                          fontWeight: 500,
                                          fontFamily: 'monospace',
                                          fontSize: '0.75rem',
                                          wordBreak: 'break-word',
                                        }}
                                      >
                                        {key}
                                      </Typography>
                                      <Typography variant="caption" sx={{ flex: 1, wordBreak: 'break-word', fontSize: '0.75rem' }}>
                                        {Array.isArray(val) ? val.join(', ') : String(val)}
                                      </Typography>
                                    </Stack>
                                  ))}
                                </Stack>
                              </Box>
                          )
                        })()}
                      </Stack>
                    </Box>
                  </Collapse>
                </Stack>
              </Paper>
            ))}
          </Stack>
        </Box>
      </Stack>
    )
  }

  // Check if resource has LOM data
  const hasLomData = () => {
    const lom = resource?.data?.lom
    const lomes = resource?.data?.lomes
    const hasLom = lom && lom !== null && Object.keys(lom).length > 0
    const hasLomes = lomes && lomes !== null && Object.keys(lomes).length > 0
    return hasLom || hasLomes
  }

  // Render LOM data if available
  const renderLomData = () => {
    // In edit mode, show editable LOM editor
    if (isEditMode && editedResource) {
      return (
        <EditableLomData
          resource={editedResource}
          onChange={setEditedResource}
        />
      )
    }

    // View mode - show original read-only display
    const lom = resource?.data?.lom
    const lomes = resource?.data?.lomes

    // Check if we have any LOM data
    const hasLom = lom && lom !== null && Object.keys(lom).length > 0
    const hasLomes = lomes && lomes !== null && Object.keys(lomes).length > 0

    if (!hasLom && !hasLomes) {
      return (
        <Box sx={{ textAlign: 'center', py: 4 }}>
          <Typography variant="body1" color="text.secondary">
            No LOM metadata available for this resource
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>
            LOM and LOM-ES data can be added to provide educational context
          </Typography>
        </Box>
      )
    }

    // Helper to render LOM fields
    const renderLomFields = (data: any, title: string) => {
      if (!data || typeof data !== 'object') return null

      return (
        <Box>
          <Typography variant="h6" gutterBottom sx={{ fontWeight: 600 }}>
            {title}
          </Typography>
          <Stack spacing={2}>
            {Object.entries(data).map(([key, value]) => {
              // Skip null/undefined values
              if (value === null || value === undefined) return null

              return (
                <Box
                  key={key}
                  sx={{
                    p: 2,
                    bgcolor: 'grey.50',
                    borderRadius: 2,
                    border: '1px solid',
                    borderColor: 'divider',
                  }}
                >
                  <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: 'primary.main' }}>
                    {key}
                  </Typography>
                  {Array.isArray(value) ? (
                    <Stack spacing={1}>
                      {value.map((item: any, idx: number) => (
                        <Box
                          key={idx}
                          sx={{
                            p: 1,
                            bgcolor: 'white',
                            borderRadius: 1,
                            border: '1px solid',
                            borderColor: 'divider',
                          }}
                        >
                          {typeof item === 'object' ? (
                            <Typography variant="body2" component="pre" sx={{ fontSize: '0.75rem', whiteSpace: 'pre-wrap' }}>
                              {JSON.stringify(item, null, 2)}
                            </Typography>
                          ) : (
                            <Typography variant="body2" sx={{ wordBreak: 'break-word' }}>
                              {String(item)}
                            </Typography>
                          )}
                        </Box>
                      ))}
                    </Stack>
                  ) : typeof value === 'object' ? (
                    <Typography variant="body2" component="pre" sx={{ fontSize: '0.75rem', whiteSpace: 'pre-wrap', bgcolor: 'white', p: 1, borderRadius: 1 }}>
                      {JSON.stringify(value, null, 2)}
                    </Typography>
                  ) : (
                    <Typography variant="body2" sx={{ wordBreak: 'break-word', lineHeight: 1.6 }}>
                      {String(value)}
                    </Typography>
                  )}
                </Box>
              )
            })}
          </Stack>
        </Box>
      )
    }

    return (
      <Stack spacing={3}>
        {hasLom && renderLomFields(lom, 'LOM Metadata')}
        {hasLomes && renderLomFields(lomes, 'LOM-ES Extended Metadata')}
      </Stack>
    )
  }

  // Render debug information - all raw resource data
  const renderDebugInfo = () => {
    if (!resource) return null

    const effectiveSchema = getSchemeFields(activeCollection)

    return (
      <Stack spacing={2}>
        <Typography variant="h6" gutterBottom sx={{ color: theme.palette.primary.main, fontWeight: 600 }}>
          🔍 Debug Information
        </Typography>

        {/* Collection Schema */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            COLLECTION SCHEMA
          </Typography>
          {activeCollection ? (
            <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
              {`collection: ${activeCollection.name} (id=${activeCollection.id})\nscheme_id: ${activeCollection.scheme_id ?? 'null'}\neffective schema: ${effectiveSchema ? JSON.stringify(effectiveSchema, null, 2) : 'null'}`}
            </Box>
          ) : (
            <Typography variant="caption" color="text.secondary">Collection not loaded.</Typography>
          )}
        </Paper>

        {/* Core Fields */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            CORE FIELDS (Database fields)
          </Typography>
          <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
{`id:              ${resource.id}
name:            ${resource.name || '(empty)'}
type:            ${resource.type}
description:     ${resource.description || '(null)'}
collection_id:   ${resource.collection_id || '(null)'}
user_owner_id:   ${resource.user_owner_id || '(null)'}
active:          ${resource.active}
organization_id: ${resource.organization_id || '(null)'}
slug:            ${resource.slug || '(null)'}
published_at:    ${resource.published_at || '(null)'}
created_at:      ${resource.created_at || '(unknown)'}
updated_at:      ${resource.updated_at || '(unknown)'}
deleted_at:      ${resource.deleted_at || '(null)'}`}
          </Box>
        </Paper>

        {/* Metadata */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            METADATA
          </Typography>
          <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
{`Tags (${resource.metadata?.tags ? resource.metadata.tags.length : 0}):
${Array.isArray(resource.metadata?.tags) && resource.metadata.tags.length > 0
  ? resource.metadata.tags.map((tag: string) => `  - ${tag}`).join('\n')
  : '  (none)'}

Other metadata keys:
${resource.metadata && Object.keys(resource.metadata).filter(k => !['tags', 'lom', 'lomes', 'partials'].includes(k)).length > 0
  ? Object.keys(resource.metadata).filter(k => !['tags', 'lom', 'lomes', 'partials'].includes(k)).map(k => `  - ${k}: ${JSON.stringify((resource.metadata as any)[k])}`).join('\n')
  : '  (none)'}
  
LOM (${resource.metadata?.lom && Object.keys(resource.metadata.lom).length > 0 ? Object.keys(resource.metadata.lom).length : 0} fields):
${resource.metadata?.lom && Object.keys(resource.metadata.lom).length > 0
  ? `  ${JSON.stringify(resource.metadata.lom, null, 2).split('\n').join('\n  ')}`
  : '  (empty)'}

LOM-ES (${resource.metadata?.lomes && Object.keys(resource.metadata.lomes).length > 0 ? Object.keys(resource.metadata.lomes).length : 0} fields):
${resource.metadata?.lomes && Object.keys(resource.metadata.lomes).length > 0
  ? `  ${JSON.stringify(resource.metadata.lomes, null, 2).split('\n').join('\n  ')}`
  : '  (empty)'}

Partials:
${resource.metadata?.partials && Object.keys(resource.metadata.partials).length > 0
  ? `  ${JSON.stringify(resource.metadata.partials, null, 2).split('\n').join('\n  ')}`
  : '  (empty)'}      `}

          </Box>
        </Paper>

        {/* Payload */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            PAYLOAD
          </Typography>
          <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
{`public:       ${resource.payload?.public ?? '(null)'}
downloadable: ${resource.payload?.downloadable ?? '(null)'}

Other payload keys:
${resource.payload && Object.keys(resource.payload).filter(k => !['public', 'downloadable'].includes(k)).length > 0
  ? Object.keys(resource.payload).filter(k => !['public', 'downloadable'].includes(k)).map(k => `  - ${k}: ${JSON.stringify((resource.payload as any)[k])}`).join('\n')
  : '  (none)'}`}
          </Box>
        </Paper>

        {/* Relationships */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            RELATIONSHIPS
          </Typography>
          <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
{`Categories (${Array.isArray(resource.categories) ? resource.categories.length : 0}):
${Array.isArray(resource.categories) && resource.categories.length > 0
  ? resource.categories.map((cat: any) => `  - ${cat.name} (${cat.id})`).join('\n')
  : '  (none)'}

Semantic Tags (${resource.semanticTags?.length || 0}):
${Array.isArray(resource.semanticTags) && resource.semanticTags.length > 0
  ? resource.semanticTags.map((tag: any) => `  - ${tag.label} (${tag.solr_language})`).join('\n')
  : '  (none)'}`}
          </Box>
        </Paper>

        {/* Files */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            FILES ({resource.files?.length || 0})
          </Typography>
          {resource.files && Array.isArray(resource.files) && resource.files.length > 0 ? (
            <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
              {resource.files.map((file, idx) => (
                <div key={idx}>{`[${idx + 1}] ${file.filename || file.original_name || 'Unnamed'}
    id:          ${file.id}
    resource_id: ${file.resource_id}
    mime_type:   ${file.mime_type}
    size:        ${file.size || '(unknown)'}
    role:        ${(file as any).role || '(unknown)'}
    relation:    ${(file as any).relation || '(none)'}
    usage:       ${(file as any).usage ? JSON.stringify((file as any).usage) : '(none)'}
    media_id:    ${file.media_id || '(none)'}
    path:        ${file.path || '(none)'}`}</div>
              ))}
            </Box>
          ) : (
            <Typography variant="body2" color="text.secondary">
              No files
            </Typography>
          )}
        </Paper>

        {/* AI Processing, Embeddings & Suggestions */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1.5 }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 600, color: theme.palette.primary.main, flex: 1 }}>
              🤖 AI PROCESSING, EMBEDDINGS & SUGGESTIONS
            </Typography>
            <Tooltip title="Queue re-extraction for all files (text, metadata, embeddings, AI suggestions)">
              <span>
                <Button
                  size="small"
                  variant="outlined"
                  startIcon={extractingAll ? <CircularProgress size={12} /> : <Refresh fontSize="small" />}
                  disabled={extractingAll || !resource.files?.length}
                  onClick={async () => {
                    setExtractingAll(true)
                    try {
                      await resourceService.aityEnrichResource(String(resource.id))
                      startAiSuggestionsPolling()
                      setSnackbar({ open: true, message: 'Re-extraction queued. Watching for AI suggestions.', severity: 'info' })
                    } catch (e: any) {
                      setSnackbar({ open: true, message: `Re-extract failed: ${e.message}`, severity: 'error' })
                    } finally {
                      setExtractingAll(false)
                    }
                  }}
                  sx={{ fontSize: '0.7rem', height: 26, whiteSpace: 'nowrap' }}
                >
                  Re-extract all
                </Button>
              </span>
            </Tooltip>
          </Box>

          <Box sx={{ mb: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
            <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary' }}>
              ai_suggestions_status:
            </Typography>
            <Chip
              size="small"
              label={resource.ai_suggestions_status ?? 'not loaded'}
              color={
                resource.ai_suggestions_status === 'found' ? 'success' :
                resource.ai_suggestions_status === 'processed' ? 'info' :
                resource.ai_suggestions_status === 'none' ? 'warning' :
                'default'
              }
            />
          </Box>

          {!resource.files?.length ? (
            <Typography variant="caption" color="text.secondary">No files to inspect.</Typography>
          ) : (
            resource.files.map((file, idx) => {
              const isContributing = file.role === 'canonical' && !!(
                file.latest_tika_system_file?.metadata?.tika_metadata &&
                Object.keys(file.latest_tika_system_file.metadata.tika_metadata).length > 0
              )
              return (
                <FileDebugCard
                  key={file.id ?? idx}
                  file={file as any}
                  index={idx}
                  isContributing={isContributing}
                  onReextract={async () => {
                    await resourceService.aityEnrichFile(String(resource.id), String(file.id))
                    startAiSuggestionsPolling()
                    setSnackbar({ open: true, message: `Re-extraction queued for "${file.filename || file.id}". Watching for AI suggestions.`, severity: 'info' })
                  }}
                />
              )
            })
          )}
        </Paper>

        {/* Promoted File Metadata */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            📋 PROMOTED FILE METADATA
          </Typography>

          {(() => {
            const contributing = resource.files?.find(
              f => f.role === 'canonical' &&
                   f.latest_tika_system_file?.metadata?.tika_metadata &&
                   Object.keys(f.latest_tika_system_file.metadata.tika_metadata).length > 0
            )
            const isStale = !contributing && !!resource.promoted_file_metadata
            return (
              <Stack spacing={1}>
                <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, fontFamily: 'monospace' }}>
                  {`Contributing file: ${contributing
                    ? (contributing.filename || contributing.id) + ' (role=canonical, id=' + contributing.id + ')'
                    : '(none — no canonical file with tika_metadata found)'
                  }`}
                </Box>
                {isStale && (
                  <Alert severity="warning" sx={{ fontSize: '0.75rem', py: 0.5 }}>
                    <strong>Stale data</strong> — <code>promoted_file_metadata</code> has content but no canonical
                    file is currently contributing. This happens when a file's role was changed away from canonical
                    without triggering a recalculation. Re-save the resource or re-extract a file to clear it.
                  </Alert>
                )}
                {resource.promoted_file_metadata ? (
                  <Box
                    component="pre"
                    sx={{
                      fontSize: '0.7rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace',
                      bgcolor: isStale ? 'warning.50' : 'grey.100',
                      border: isStale ? '1px solid' : 'none',
                      borderColor: 'warning.light',
                      p: 1, borderRadius: 1, maxHeight: 250, overflow: 'auto',
                    }}
                  >
                    {JSON.stringify(resource.promoted_file_metadata, null, 2)}
                  </Box>
                ) : (
                  <Typography variant="caption" color="text.secondary">
                    promoted_file_metadata is null — canonical file has not been processed by Tika yet, or no canonical file exists.
                  </Typography>
                )}
              </Stack>
            )
          })()}
        </Paper>

        {/* Collection & Workspace */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            COLLECTION & WORKSPACE
          </Typography>
          <Box component="pre" sx={{ fontSize: '0.75rem', m: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
            {/* Collection from relationship array */}
            {resource.collection && Array.isArray(resource.collection) && resource.collection.length > 0 ? (
              resource.collection.map((col: any) => `Collection: ${col.name}
  id:               ${col.id}
  organization_id:   ${col.organization_id}
  slug:              ${col.slug}
  max_number_of_files: ${col.max_number_of_files || '(null)'}`).join('\n\n')
            ) : (
              // Fallback to collection_id if no collection object
              `Collection: (id: ${resource.collection_id || '(none)'}) `
            )}
            {'\n\n'}
            {/* Workspace from relationship array */}
            {resource.workspace && Array.isArray(resource.workspace) && resource.workspace.length > 0 ? (
              resource.workspace.map((ws: any) => `Workspace: ${ws.name}
  id:     ${ws.id}`).join('\n')
            ) : (
              'Workspace: (none)'
            )}
          </Box>
        </Paper>

        {/* Raw Backend Response */}
        {/* Full JSON dump */}
        <Paper sx={{ p: 2, border: '1px solid', borderColor: 'divider', boxShadow: 1 }}>
          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1, color: theme.palette.primary.main }}>
            🔍 RESOURCE DATA (from backend)
          </Typography>
          <Box
            component="pre"
            sx={{
              fontSize: '0.75rem',
              m: 0,
              whiteSpace: 'pre-wrap',
              fontFamily: 'monospace',
              bgcolor: 'grey.100',
              p: 1,
              borderRadius: 1,
              maxHeight: 300,
              overflow: 'auto',
            }}
          >
            {JSON.stringify(resource, null, 2)}
          </Box>
        </Paper>

      </Stack>
    )
  }

  // Render the full processing activity log — promoted from the Debug tab to its
  // own dedicated tab in the modal so power users (and AITY auditing) have a
  // first-class surface for "what happened to this resource". Auto-loads on tab
  // activation; the refresh button forces a re-fetch.
  const renderActivity = () => {
    if (!resource) return null
    return (
      <Stack spacing={2}>
        <Paper sx={{ border: '1px solid', borderColor: 'divider', boxShadow: 1, overflow: 'hidden' }}>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', p: 2 }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 600, color: theme.palette.primary.main }}>
              📋 PROCESSING ACTIVITY LOG
            </Typography>
            <Tooltip title="Refresh">
              <IconButton size="small" onClick={() => loadActivityLog()} disabled={activityLogLoading} sx={{ p: 0.25 }}>
                {activityLogLoading ? <CircularProgress size={14} /> : <Refresh sx={{ fontSize: 14 }} />}
              </IconButton>
            </Tooltip>
          </Box>
          <Divider />
          <Box sx={{ p: 2 }}>
              {activityLogLoading ? (
                <Box sx={{ display: 'flex', justifyContent: 'center', py: 2 }}><CircularProgress size={20} /></Box>
              ) : activityLog.length === 0 ? (
                <Typography variant="caption" color="text.secondary">No events recorded yet.</Typography>
              ) : (
                <Stack spacing={0.5}>
                  {activityLog.map((ev, i) => {
                    const eventLabels: Record<string, string> = {
                      file_uploaded:                  '⬆ File uploaded',
                      tika_metadata:                  '🔬 Tika metadata extracted',
                      extracted_text:                 '📄 Text extracted',
                      chunks_created:                 '✂ Chunks created',
                      transcription:                  '🎙 Transcription',
                      ai_suggested_tags:              '🏷 AI tags suggested',
                      ai_suggested_name:              '✏ AI name suggested',
                      ai_suggested_description:       '📝 AI description suggested',
                      preview_snapshot:               '🖼 Preview snapshot',
                      // User-action audit rows
                      name_accepted:                  '✅ Name accepted',
                      description_accepted:           '✅ Description accepted',
                      tags_accepted:                  '✅ Tags accepted',
                      name_dismissed:                 '✖ Name suggestion dismissed',
                      description_dismissed:          '✖ Description suggestion dismissed',
                      tags_dismissed:                 '✖ Tag suggestion dismissed',
                      name_auto_approved:             '🤖 Name auto-approved',
                      description_auto_approved:      '🤖 Description auto-approved',
                      tags_auto_approved:             '🤖 Tags auto-approved',
                      // Plain editor field changes (no AITY suggestion involved)
                      name_updated:                   '✏ Name updated',
                      description_updated:            '📝 Description updated',
                      tags_updated:                   '🏷 Tags updated',
                      visibility_changed:             '👁 Visibility changed',
                      resource_activated:             '⏵ Resource activated',
                      resource_deactivated:           '⏸ Resource deactivated',
                      // AITY background worker lifecycle
                      aity_tika_extract:              '🔬 Tika text extract',
                      aity_tika_extract_failed:       '❌ Tika text extract failed',
                      aity_tika_metadata:             '🔬 Tika metadata extract',
                      aity_tika_metadata_failed:      '❌ Tika metadata extract failed',
                      aity_metadata_promote:          '🪄 Metadata promoted',
                      aity_autotag_dispatched:        '📤 Auto-tag dispatched',
                      aity_autotag_job_started:       '▶ Auto-tag started',
                      aity_autotag:                   '✨ Auto-tag completed',
                      aity_autotag_failed:            '❌ Auto-tag failed',
                      aity_vision_dispatched:         '📤 Vision dispatched',
                      aity_vision_job_started:        '▶ Vision started',
                      aity_vision_analyze:            '👁 Vision completed',
                      aity_vision_analyze_failed:     '❌ Vision failed',
                      aity_vision_skipped:            '⤿ Vision skipped',
                      // Embedding / vector indexing failures
                      embedding_failed:               '⚠ Embedding failed (check model / vector size)',
                    }
                    const label = eventLabels[ev.event] ?? ev.event
                    const statusColor = ev.status === 'completed' ? 'success.main' : ev.status === 'failed' ? 'error.main' : 'text.disabled'
                    const ts = ev.created_at ? new Date(ev.created_at).toLocaleString() : '—'

                    const detailParts: string[] = []
                    if (ev.details?.chunk_count !== undefined) detailParts.push(`${ev.details.chunk_count} chunks`)
                    if (ev.details?.char_count !== undefined) detailParts.push(`${ev.details.char_count} chars`)
                    if (ev.details?.key_count !== undefined) detailParts.push(`${ev.details.key_count} keys`)
                    if (ev.details?.count !== undefined) detailParts.push(`${ev.details.count} tags`)
                    if (ev.details?.labels?.length) detailParts.push(ev.details.labels.join(', '))
                    if (ev.details?.value && typeof ev.details.value === 'string') detailParts.push(`"${ev.details.value.substring(0, 60)}${ev.details.value.length > 60 ? '…' : ''}"`)
                    if (ev.details?.mime_type) detailParts.push(ev.details.mime_type)
                    if (ev.details?.role) detailParts.push(`role: ${ev.details.role}`)
                    if (ev.details?.error) detailParts.push(`error: ${ev.details.error}`)
                    if (ev.details?.embed_error) detailParts.push(`embed error: ${ev.details.embed_error}`)
                    // resource_events payload keys
                    if (ev.details?.matched_labels?.length) detailParts.push(`kept: ${ev.details.matched_labels.join(', ')}`)
                    if (ev.details?.dismissed_labels?.length) detailParts.push(`dropped: ${ev.details.dismissed_labels.join(', ')}`)
                    if (ev.details?.suggested_value && typeof ev.details.suggested_value === 'string') detailParts.push(`AI: "${ev.details.suggested_value.substring(0, 60)}${ev.details.suggested_value.length > 60 ? '…' : ''}"`)
                    if (ev.details?.committed_value && typeof ev.details.committed_value === 'string') detailParts.push(`user: "${ev.details.committed_value.substring(0, 60)}${ev.details.committed_value.length > 60 ? '…' : ''}"`)
                    if (ev.details?.via === 'save_flush') detailParts.push('(resolved on save)')
                    if (ev.details?.duration_ms !== undefined) detailParts.push(`${ev.details.duration_ms} ms`)
                    if (ev.details?.reason) detailParts.push(`reason: ${ev.details.reason}`)
                    if (ev.details?.suggested_name) detailParts.push(`name: "${String(ev.details.suggested_name).substring(0, 60)}"`)
                    if (ev.details?.suggested_desc) detailParts.push(`desc: "${String(ev.details.suggested_desc).substring(0, 60)}"`)
                    if (ev.details?.tags_count !== undefined) detailParts.push(`${ev.details.tags_count} tags`)
                    // Plain editor field-change payload keys
                    if (ev.details?.old_value !== undefined && ev.details?.new_value !== undefined) {
                      const ov = typeof ev.details.old_value === 'string' ? ev.details.old_value : JSON.stringify(ev.details.old_value)
                      const nv = typeof ev.details.new_value === 'string' ? ev.details.new_value : JSON.stringify(ev.details.new_value)
                      detailParts.push(`"${(ov ?? '').substring(0, 40)}" → "${(nv ?? '').substring(0, 40)}"`)
                    }
                    if (ev.details?.added?.length) detailParts.push(`+ ${ev.details.added.join(', ')}`)
                    if (ev.details?.removed?.length) detailParts.push(`− ${ev.details.removed.join(', ')}`)
                    if (ev.details?.origin && ev.event.endsWith('_updated')) detailParts.push(`origin: ${ev.details.origin}`)

                    return (
                      <Box key={i} sx={{ display: 'flex', gap: 1.5, alignItems: 'flex-start', py: 0.5, borderBottom: i < activityLog.length - 1 ? '1px solid' : 'none', borderColor: 'divider' }}>
                        <Box sx={{ width: 150, flexShrink: 0 }}>
                          <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace', fontSize: '0.65rem' }}>
                            {ts}
                          </Typography>
                        </Box>
                        <Box sx={{ flex: 1, minWidth: 0 }}>
                          <Box sx={{ display: 'flex', gap: 0.5, alignItems: 'center', flexWrap: 'wrap' }}>
                            <Typography variant="caption" sx={{ fontWeight: 600, fontSize: '0.7rem' }}>{label}</Typography>
                            <Chip size="small" label={ev.status} sx={{ height: 14, fontSize: '0.6rem', bgcolor: ev.status === 'completed' ? 'success.light' : ev.status === 'failed' ? 'error.light' : 'grey.200', color: statusColor }} />
                            {ev.file_name && <Chip size="small" label={ev.file_name} variant="outlined" sx={{ height: 14, fontSize: '0.6rem', maxWidth: 180, '& .MuiChip-label': { overflow: 'hidden', textOverflow: 'ellipsis' } }} />}
                          </Box>
                          {detailParts.length > 0 && (
                            <Typography variant="caption" color="text.secondary" sx={{ fontSize: '0.65rem', display: 'block', mt: 0.25 }}>
                              {detailParts.join(' · ')}
                            </Typography>
                          )}
                        </Box>
                      </Box>
                    )
                  })}
                </Stack>
              )}
            </Box>
        </Paper>
      </Stack>
    )
  }

  return (
    <Dialog
      open={open}
      onClose={(_e, reason) => {
        // Backdrop click is too easy to trigger accidentally with an upload in
        // flight — ignore. Escape and the explicit Close button both route
        // through handleClose so the discard-uncommitted prompt always runs.
        if (reason === 'backdropClick') return
        handleClose()
      }}
      maxWidth="xl"
      fullWidth
      BackdropProps={{
        sx: {
          backdropFilter: 'blur(8px)',
          bgcolor: 'rgba(0, 0, 0, 0.4)',
        },
      }}
      PaperProps={{
        sx: {
          height: '90vh',
          maxHeight: '90vh',
          borderRadius: 2,
          boxShadow: 24,
          overflow: 'hidden',
        },
      }}
    >
      {/* Header */}
      <DialogTitle sx={{ pb: 2, bgcolor: 'primary.50', borderBottom: '1px solid', borderColor: 'primary.200' }}>
        <Stack direction="row" alignItems="center" justifyContent="space-between">
          <Stack spacing={0.5}>
            <Stack direction="row" alignItems="center" spacing={1}>
              <Typography variant="h6" component="div" sx={{ fontWeight: 600, color: 'primary.dark' }}>
                {resourceId === null || initialMode === 'create' ? 'New Resource' : 'Resource Details'}
              </Typography>
              {resourceKind && (
                <Chip
                  size="small"
                  label={resourceKind}
                  color={resourceKind === 'Multi-component' ? 'secondary' : 'primary'}
                  sx={{ height: 20, fontSize: '0.7rem' }}
                />
              )}
            </Stack>
            <Typography variant="caption" color="text.secondary">
              {resourceId === null || initialMode === 'create'
                ? 'Create a new resource'
                : isEditMode
                ? 'Edit resource information'
                : 'View and manage resource information'}
            </Typography>
          </Stack>
          <Stack direction="row" spacing={1} alignItems="center">
            {/* AITY hub — exposes AITY actions, which only apply in edit/creation mode
                (the modal's other use, view, is read-only). isEditMode is true for both
                edit and create; resource?.id gates create until the first file makes the
                draft, so the hub surfaces once there's something for AITY to act on. */}
            {isEditMode && !loading && resource?.id && (
              <>
                {/* Dedicated show/hide suggestions toggle — sits to the left of the hub
                    for quick, discoverable access (only when there are active suggestions). */}
                {aiSuggestionsStatus === 'found' && (
                  <Tooltip title={aiSuggestionsHidden ? 'Show Aity suggestions' : 'Hide Aity suggestions'}>
                    <Button
                      size="small"
                      variant="outlined"
                      onClick={aiSuggestionsHidden ? handleShowAiSuggestions : handleHideAiSuggestions}
                      sx={{
                        // Outlined small icon-only button so its height matches the hub + Edit/Save/Cancel row.
                        minWidth: 0, px: 1, color: 'primary.main', borderColor: 'primary.200',
                        bgcolor: 'white', '&:hover': { bgcolor: 'grey.50', borderColor: 'primary.300' },
                      }}
                    >
                      {aiSuggestionsHidden ? <Visibility fontSize="small" /> : <VisibilityOff fontSize="small" />}
                    </Button>
                  </Tooltip>
                )}
                <AityHub
                  status={aiSuggestionsStatus}
                  processingActive={aityProcessingActive}
                  failedCount={aityFailedIds.length}
                  isEditMode={isEditMode}
                  backgroundRefreshPending={backgroundRefreshPending}
                  onRestore={handleRestoreAiSuggestions}
                  onReRun={handleReRunAity}
                  onGenerateMetadata={handleGenerateMetadata}
                  onAutoApprove={handleAutoApprove}
                  multiFile={(resource?.files?.length ?? 0) > 1}
                  onRefresh={handleBackgroundRefresh}
                  onRetryFailed={handleRetryFailedAity}
                />
                <Divider orientation="vertical" flexItem />
              </>
            )}
            {/* Edit/Save/Cancel buttons */}
            {!isEditMode ? (
              <Button
                variant="contained"
                startIcon={<Edit />}
                onClick={handleEnterEditMode}
                size="small"
                sx={{ textTransform: 'none' }}
              >
                Edit
              </Button>
            ) : (
              <>
                <Button
                  variant="outlined"
                  startIcon={<Cancel />}
                  onClick={handleCancelClick}
                  size="small"
                  sx={{ textTransform: 'none' }}
                  disabled={isSaving}
                >
                  Cancel
                </Button>
                <Tooltip title={hasRejectedFiles ? 'Remove oversized files before saving' : 'Save and stay in this modal'}>
                  <span style={{ display: 'inline-flex' }}>
                    <Button
                      variant="outlined"
                      startIcon={<Refresh />}
                      onClick={() => handleSave({ closeAfter: false })}
                      size="small"
                      sx={{ textTransform: 'none' }}
                      disabled={isSaving || !hasUnsavedChanges() || hasRejectedFiles}
                    >
                      {isSaving ? 'Saving...' : 'Save & refresh'}
                    </Button>
                  </span>
                </Tooltip>
                <Tooltip title={hasRejectedFiles ? 'Remove oversized files before saving' : 'Save and close the modal'}>
                  <span style={{ display: 'inline-flex' }}>
                    <Button
                      variant="contained"
                      startIcon={<Save />}
                      onClick={() => handleSave({ closeAfter: true })}
                      size="small"
                      sx={{ textTransform: 'none' }}
                      disabled={isSaving || !hasUnsavedChanges() || hasRejectedFiles}
                    >
                      {isSaving ? 'Saving...' : 'Save & quit'}
                    </Button>
                  </span>
                </Tooltip>
              </>
            )}
            <IconButton
              onClick={handleClose}
              size="small"
              sx={{
                color: 'text.secondary',
                '&:hover': { bgcolor: 'action.hover' },
              }}
            >
              <Close />
            </IconButton>
          </Stack>
        </Stack>
        {/* Save error display */}
        {saveError && (
          <Alert severity="error" sx={{ mt: 2 }} onClose={() => setSaveError(null)}>
            {saveError}
          </Alert>
        )}
        {/* The "new AITY info" signal now lives on the AITY hub (the "· new" label
            + "Load new Aity info" menu action) instead of a separate banner. */}
      </DialogTitle>

      <DialogContent sx={{ p: 0 }}>
        {loading ? (
          <Box
            sx={{
              display: 'flex',
              flexDirection: 'column',
              justifyContent: 'center',
              alignItems: 'center',
              height: '60vh',
              gap: 2,
            }}
          >
            <CircularProgress size={60} thickness={4} sx={{ color: theme.palette.primary.main }} />
            <Typography variant="body1" color="text.secondary">
              Loading resource details...
            </Typography>
          </Box>
        ) : error ? (
          <Box sx={{ p: 3 }}>
            <Alert severity="error" sx={{ borderRadius: 2 }}>
              {error}
            </Alert>
          </Box>
        ) : !resource ? (
          <Box sx={{ p: 3 }}>
            <Alert severity="info" sx={{ borderRadius: 2 }}>
              No resource data available
            </Alert>
          </Box>
        ) : (
          <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column' }}>
            {/* Two-column layout */}
            <Box sx={{ flex: 1, display: 'flex', overflow: 'hidden' }}>
              {/* Left column: Media Preview */}
              <Box
                sx={{
                  width: '50%',
                  bgcolor: 'grey.800',
                  height: '100%',
                }}
              >
                <MediaViewer
                  files={allFiles}
                  defaultIndex={selectedFileIndex}
                  showControls={true}
                  height="100%"
                  bgcolor="grey.800"
                  onIndexChange={(newIndex) => setSelectedFileIndex(newIndex)}
                  editMode={isEditMode}
                  isPreview={(() => {
                    const cf = allFiles[selectedFileIndex]
                    // Session uploads compare via their real id (snapshotId), not "new-{index}".
                    const effId = cf?.snapshotId ?? cf?.id
                    if (effId === undefined) return false
                    return snapshotFileId !== undefined
                      ? effId === snapshotFileId
                      : effId === resource?.snapshot_file?.id
                  })()}
                  onSetPreview={(fileId) => setSnapshotFileId(fileId)}
                  // Drop-to-add: gated by canEdit (today this mirrors "the Edit
                  // button is shown"; when a client-side permission check lands,
                  // both should read the same switch). Dropping enters edit mode
                  // and stages files via EditableFiles' validated path.
                  onDropFiles={canEdit ? handleViewerDrop : undefined}
                  acceptHint={activeCollection?.scheme?.accepted_mimetypes ?? []}
                  // Canonical pin — edit mode only. Toggling routes through EditableFiles'
                  // role machinery so the Files tab stays in sync; the title chip reacts
                  // via resourceKind (which reads the same pending role state).
                  isCanonical={isViewerFileCanonical(allFiles[selectedFileIndex]?.id)}
                  onToggleCanonical={isEditMode
                    ? (fileId) => canonicalToggleRef.current?.(fileId)
                    : undefined}
                />
              </Box>

              {/* Right column: Metadata */}
              <Box
                sx={{
                  width: '50%',
                  display: 'flex',
                  flexDirection: 'column',
                  overflow: 'hidden',
                }}
              >
                {/* Tabs */}
                <Box sx={{ borderBottom: 1, borderColor: 'divider', bgcolor: 'grey.50' }}>
                  <Tabs value={tabValue} onChange={handleTabChange} sx={{ minHeight: 48 }}>
                    <Tab label="Basic Info" sx={{ minHeight: 48, textTransform: 'none', fontWeight: 600, fontSize: '1rem' }} />
                    <Tab
                      label="Files"
                      sx={{
                        minHeight: 48,
                        textTransform: 'none',
                        fontWeight: 600,
                        fontSize: '1rem',
                        opacity: hasFiles ? 1 : 0.5,
                      }}
                    />
                    <Tab
                      label="LOM Data"
                      sx={{
                        minHeight: 48,
                        textTransform: 'none',
                        fontWeight: 600,
                        fontSize: '1rem',
                        opacity: hasLomData() ? 1 : 0.5,
                      }}
                    />
                    <Tab label="Activity" sx={{ minHeight: 48, textTransform: 'none', fontWeight: 600, fontSize: '1rem' }} />
                    <Tab label="Vault Links" sx={{ minHeight: 48, textTransform: 'none', fontWeight: 600, fontSize: '1rem' }} />
                    <Tab label="Debug" sx={{ minHeight: 48, textTransform: 'none', fontWeight: 600, fontSize: '1rem' }} />
                  </Tabs>
                </Box>

                {/* Tab Content */}
                <Box sx={{ flex: 1, overflow: 'auto', px: 3 }}>
                  <TabPanel value={tabValue} index={0}>
                    {isEditMode && editedResource ? (
                      <>
                        <EditableBasicInfo
                          resource={editedResource}
                          onChange={setEditedResource}
                          errors={validationErrors}
                          collection={activeCollection}
                          section="identity"
                          suggestedNames={panelSuggestions?.suggestedNames}
                          suggestedDescriptions={panelSuggestions?.suggestedDescriptions}
                          onAcceptName={handleAcceptSuggestedName}
                          onAcceptDescription={handleAcceptSuggestedDescription}
                        />
                      </>
                    ) : (
                      renderBasicInfo()
                    )}

                    {/* Workspace picker — edit mode only, not shown for new resources */}
                    {isEditMode && resource?.id && initialMode !== 'create' && availableWorkspaces.length > 0 && (
                      <>
                        <Divider sx={{ my: 2 }} />
                        <Box sx={{ py: 1 }}>
                          <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 1.5 }}>
                            Workspaces
                          </Typography>
                          <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                            {availableWorkspaces.map((ws) => {
                              const wsId = String(ws.id)
                              const isIn = resourceWorkspaceIds.has(wsId)
                              return (
                                <Chip
                                  key={wsId}
                                  label={ws.name}
                                  size="small"
                                  onClick={() => handleWorkspaceToggle(wsId)}
                                  variant={isIn ? 'filled' : 'outlined'}
                                  sx={{
                                    cursor: 'pointer',
                                    fontWeight: isIn ? 600 : 400,
                                    bgcolor: isIn ? 'secondary.main' : undefined,
                                    color: isIn ? 'white' : 'text.secondary',
                                    borderColor: isIn ? 'secondary.main' : 'divider',
                                    '&:hover': {
                                      bgcolor: isIn ? 'secondary.dark' : 'action.hover',
                                    },
                                  }}
                                />
                              )
                            })}
                          </Stack>
                        </Box>
                      </>
                    )}

                    {/* Tag picker — edit mode only */}
                    {isEditMode && (
                      <>
                        <Divider sx={{ my: 2 }} />
                        <Box sx={{ py: 1 }}>
                          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: 1.5 }}>
                            <Box sx={{ display: 'flex', alignItems: 'center' }}>
                              <Typography variant="subtitle2" sx={{ fontWeight: 600 }}>Tags</Typography>
                              {resource?.tags_origin && (() => {
                                const tagsSourceFile = (resource.files ?? []).find((f: any) => f.id === resource.tags_source_file_id)
                                let color = 'info.main'
                                let tip = 'Set by user'
                                if (resource.tags_origin === 'aity_suggestion') {
                                  color = 'success.main'
                                  tip = tagsSourceFile?.filename
                                    ? `Accepted AITY tag set from ${tagsSourceFile.filename}`
                                    : 'Accepted AITY tag set'
                                } else if (resource.tags_origin === 'aity_generated') {
                                  color = 'warning.main'
                                  tip = 'Generated by AITY across components'
                                }
                                return (
                                  <Tooltip title={tip} arrow>
                                    <CheckCircleIcon sx={{ fontSize: '1rem', ml: 0.75, color }} />
                                  </Tooltip>
                                )
                              })()}
                            </Box>
                          </Box>

                          {/* Zone 0 — AiTy tag suggestions (click to accept, before saving) */}
                          {panelSuggestions && panelSuggestions.suggestedTags.length > 0 && (
                            <Box
                              sx={{
                                mb: 1.5,
                                pl: 1,
                                py: 0.4,
                                borderLeft: '2px solid',
                                borderColor: 'primary.light',
                                bgcolor: 'primary.50',
                                borderRadius: '0 4px 4px 0',
                              }}
                            >
                              {/* Row 1 — legend + dismiss */}
                              <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
                                <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.4 }}>
                                  <Typography variant="caption" sx={{ color: 'primary.main', fontWeight: 700, lineHeight: 1.5 }}>
                                    Aity
                                  </Typography>
                                  <AutoAwesomeIcon sx={{ fontSize: 11, color: 'primary.main' }} />
                                </Box>
                              </Box>
                              {/* Row 2 — tag chips. Generated tags first, then a subtle divider,
                                  then per-file suggested tags. Source label is always shown for
                                  generated (reads "aity generated"); for file-sourced chips it
                                  appears only when more than one file produced a suggestion. */}
                              {(() => {
                                const genTags = panelSuggestions.suggestedTagsGenerated ?? []
                                const fileTags = panelSuggestions.suggestedTagsFromFiles ?? panelSuggestions.suggestedTags
                                // Always show the source filename — even with a single contributing file
                                // it's useful to confirm which file produced the suggestion.
                                const renderChip = (tag: any, _forceSourceLabel: boolean) => {
                                  const isAccepted = selectedTagsData.some((t: any) => t.label === tag.label) || pendingSuggestedTags.some((t) => t.label === tag.label)
                                  return (
                                    <SuggestionChip
                                      key={tag.label}
                                      label={tag.label}
                                      type={tag.type}
                                      description={tag.description}
                                      confidence={tag.confidence}
                                      accepted={isAccepted}
                                      onAccept={() => handleAcceptSuggestedTag(tag)}
                                      sourceLabel={tag.sourceFile?.filename}
                                    />
                                  )
                                }
                                return (
                                  <>
                                    {genTags.length > 0 && (
                                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.75, mt: 0.5 }}>
                                        {genTags.map((tag: any) => renderChip(tag, true))}
                                      </Box>
                                    )}
                                    {genTags.length > 0 && fileTags.length > 0 && (
                                      <Divider sx={{ my: 0.75, opacity: 0.4 }} />
                                    )}
                                    {fileTags.length > 0 && (
                                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.75, mt: genTags.length > 0 ? 0 : 0.5 }}>
                                        {fileTags.map((tag: any) => renderChip(tag, false))}
                                      </Box>
                                    )}
                                  </>
                                )
                              })()}
                            </Box>
                          )}

                          {/* Zone 1 — Selected tags as removable chips */}
                          {selectedTagsData.length > 0 && (
                            <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap sx={{ mb: 1.5, maxHeight: 130, overflowY: 'auto' }}>
                              {selectedTagsData.map((tag) => {
                                const effectiveType = tagTypeOverrides[tag.id as number] ?? (tag.entity_type && tag.entity_type in ENTITY_TYPES ? tag.entity_type as EntityTypeKey : null)
                                const et = effectiveType ? ENTITY_TYPES[effectiveType] : null
                                const color = et?.color ?? null
                                const EntityIcon = et?.Icon ?? null
                                return (
                                  <Box
                                    key={tag.id}
                                    sx={{
                                      display: 'inline-flex',
                                      flexDirection: 'row',
                                      alignItems: 'stretch',
                                      border: '2px solid',
                                      borderColor: color ?? 'divider',
                                      borderRadius: 1.5,
                                      overflow: 'hidden',
                                    }}
                                  >
                                    {EntityIcon && color && (
                                      <Box sx={{ bgcolor: color, display: 'flex', alignItems: 'center', justifyContent: 'center', px: 0.875 }}>
                                        <EntityIcon sx={{ fontSize: '0.875rem', color: 'white' }} />
                                      </Box>
                                    )}
                                    <Tooltip
                                      placement="top"
                                      arrow
                                      title={
                                        <Typography variant="caption" sx={{ textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                                          Generated by {(tag.vocabulary ?? 'organization')}, reviewed by {(tag.reviewer ?? 'user')}
                                        </Typography>
                                      }
                                    >
                                      <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', bgcolor: color ? `${color}12` : 'transparent' }}>
                                        <Typography sx={{ fontSize: '0.75rem', fontWeight: 600, lineHeight: 1.4, color: 'text.primary', px: 1, pt: 0.4, pb: 0.2 }}>
                                          {tag.label}
                                        </Typography>
                                        <Box sx={{ height: '1px', bgcolor: color ?? 'divider', opacity: 0.25, mx: 1 }} />
                                        <Typography sx={{ fontSize: '0.65rem', fontWeight: 500, lineHeight: 1.35, color: color ?? 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.4, px: 1, pt: 0.15, pb: 0.4, opacity: 0.9 }}>
                                          {(tag.vocabulary ?? 'organization')}
                                        </Typography>
                                      </Box>
                                    </Tooltip>
                                    {/* Pencil — opens entity_type edit popover */}
                                    <Box
                                      onClick={(e) => { e.stopPropagation(); setExistingTagEditId(tag.id as number); setExistingTagEditAnchor(e.currentTarget as HTMLElement) }}
                                      sx={{ display: 'flex', alignItems: 'center', px: 0.75, cursor: 'pointer', bgcolor: 'transparent', borderLeft: '1px solid', borderColor: color ?? 'divider', '&:hover': { bgcolor: 'grey.100' } }}
                                    >
                                      <Edit sx={{ fontSize: '0.75rem', color: 'text.disabled' }} />
                                    </Box>
                                    <Box
                                      onClick={() => handleTagRemove(tag.id)}
                                      sx={{ display: 'flex', alignItems: 'center', px: 0.75, cursor: 'pointer', bgcolor: 'transparent', '&:hover': { bgcolor: 'grey.100' } }}
                                    >
                                      <Typography sx={{ fontSize: '0.75rem', color: 'text.disabled', lineHeight: 1 }}>✕</Typography>
                                    </Box>
                                  </Box>
                                )
                              })}
                            </Stack>
                          )}

                          {/* Zone 1b — Pending AI-suggested tags (accepted, created on Save) */}
                          {pendingSuggestedTags.length > 0 && (
                            <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap sx={{ mb: 1.5 }}>
                              {pendingSuggestedTags.map((tag, idx) => {
                                const iconKey = (tag.type && tag.type in ENTITY_TYPES) ? tag.type as EntityTypeKey : 'tag'
                                const et = ENTITY_TYPES[iconKey]
                                const EntityIcon = et.Icon
                                return (
                                  <Box
                                    key={idx}
                                    sx={{
                                      display: 'inline-flex',
                                      flexDirection: 'row',
                                      alignItems: 'stretch',
                                      border: '2px dashed',
                                      borderColor: et.color,
                                      borderRadius: 1.5,
                                      overflow: 'hidden',
                                    }}
                                  >
                                    <Box sx={{ bgcolor: et.color, display: 'flex', alignItems: 'center', justifyContent: 'center', px: 0.875 }}>
                                      <EntityIcon sx={{ fontSize: '0.875rem', color: 'white' }} />
                                    </Box>
                                    <Tooltip
                                      placement="top"
                                      arrow
                                      title={
                                        <Typography variant="caption" sx={{ textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                                          Generated by Aity — pending save
                                        </Typography>
                                      }
                                    >
                                      <Box sx={{ flex: 1, display: 'flex', flexDirection: 'column', bgcolor: `${et.color}12` }}>
                                        <Typography sx={{ fontSize: '0.75rem', fontWeight: 600, lineHeight: 1.4, color: 'text.primary', px: 1, pt: 0.4, pb: 0.2 }}>
                                          {tag.label}
                                        </Typography>
                                        <Box sx={{ height: '1px', bgcolor: et.color, opacity: 0.25, mx: 1 }} />
                                        <Typography sx={{ fontSize: '0.65rem', fontWeight: 500, lineHeight: 1.35, color: et.color, textTransform: 'uppercase', letterSpacing: 0.4, px: 1, pt: 0.15, pb: 0.4, opacity: 0.9 }}>
                                          ai generated · new
                                        </Typography>
                                      </Box>
                                    </Tooltip>
                                    {/* Pencil — opens edit popover */}
                                    <Box
                                      onClick={(e) => { e.stopPropagation(); setPendingTagEditIndex(idx); setPendingTagEditAnchor(e.currentTarget as HTMLElement) }}
                                      sx={{ display: 'flex', alignItems: 'center', px: 0.75, cursor: 'pointer', bgcolor: 'transparent', borderLeft: '1px dashed', borderColor: et.color, '&:hover': { bgcolor: 'grey.100' } }}
                                    >
                                      <Edit sx={{ fontSize: '0.75rem', color: 'text.disabled' }} />
                                    </Box>
                                    <Box
                                      onClick={() => setPendingSuggestedTags(prev => prev.filter((_, i) => i !== idx))}
                                      sx={{ display: 'flex', alignItems: 'center', px: 0.75, cursor: 'pointer', bgcolor: 'transparent', '&:hover': { bgcolor: 'grey.100' } }}
                                    >
                                      <Typography sx={{ fontSize: '0.75rem', color: 'text.disabled', lineHeight: 1 }}>✕</Typography>
                                    </Box>
                                  </Box>
                                )
                              })}
                            </Stack>
                          )}

                          {/* Popover — inline editor for a pending suggested tag */}
                          <Popover
                            open={pendingTagEditIndex !== null && Boolean(pendingTagEditAnchor)}
                            anchorEl={pendingTagEditAnchor}
                            onClose={() => { setPendingTagEditIndex(null); setPendingTagEditAnchor(null) }}
                            anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
                            transformOrigin={{ vertical: 'top', horizontal: 'left' }}
                            slotProps={{ paper: { sx: { mt: 0.5, p: 1.5, width: 248, boxShadow: 3 } } }}
                          >
                            {pendingTagEditIndex !== null && (() => {
                              const tag = pendingSuggestedTags[pendingTagEditIndex]
                              if (!tag) return null
                              const currentIconKey = (tag.type && tag.type in ENTITY_TYPES) ? tag.type as EntityTypeKey : 'tag'
                              return (
                                <Box>
                                  <TextField
                                    size="small"
                                    fullWidth
                                    label="Label"
                                    value={tag.label}
                                    onChange={(e) => setPendingSuggestedTags(prev =>
                                      prev.map((t, i) => i === pendingTagEditIndex ? { ...t, label: e.target.value } : t)
                                    )}
                                    sx={{ mb: 1.25 }}
                                  />
                                  <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', mb: 0.75 }}>
                                    Entity type
                                  </Typography>
                                  <Stack direction="row" flexWrap="wrap" spacing={0.5} useFlexGap>
                                    {(Object.entries(ENTITY_TYPES) as [EntityTypeKey, typeof ENTITY_TYPES[EntityTypeKey]][]).map(([key, et]) => {
                                      const selected = currentIconKey === key
                                      return (
                                        <Box
                                          key={key}
                                          onClick={() => setPendingSuggestedTags(prev =>
                                            prev.map((t, i) => i === pendingTagEditIndex ? { ...t, type: key } : t)
                                          )}
                                          sx={{
                                            display: 'flex', alignItems: 'center', gap: 0.5, px: 1, py: 0.4,
                                            border: '1px solid', borderColor: selected ? et.color : 'divider',
                                            borderRadius: 1, cursor: 'pointer',
                                            bgcolor: selected ? `${et.color}18` : 'transparent',
                                            '&:hover': { bgcolor: `${et.color}18` },
                                          }}
                                        >
                                          <Box sx={{ width: 10, height: 10, borderRadius: 0.5, bgcolor: et.color, flexShrink: 0 }} />
                                          <Typography sx={{ fontSize: '0.72rem', fontWeight: selected ? 700 : 400 }}>{et.label}</Typography>
                                        </Box>
                                      )
                                    })}
                                  </Stack>
                                </Box>
                              )
                            })()}
                          </Popover>

                          {/* Popover — entity_type editor for an existing saved tag */}
                          <Popover
                            open={existingTagEditId !== null && Boolean(existingTagEditAnchor)}
                            anchorEl={existingTagEditAnchor}
                            onClose={() => { setExistingTagEditId(null); setExistingTagEditAnchor(null) }}
                            anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}
                            transformOrigin={{ vertical: 'top', horizontal: 'left' }}
                            slotProps={{ paper: { sx: { mt: 0.5, p: 1.5, width: 248, boxShadow: 3 } } }}
                          >
                            {existingTagEditId !== null && (() => {
                              const tag = selectedTagsData.find(t => t.id === existingTagEditId)
                              if (!tag) return null
                              const currentKey = tagTypeOverrides[existingTagEditId] ?? (tag.entity_type && tag.entity_type in ENTITY_TYPES ? tag.entity_type as EntityTypeKey : 'tag')
                              return (
                                <Box>
                                  <Typography variant="caption" sx={{ color: 'text.secondary', display: 'block', mb: 0.75 }}>
                                    Entity type — <strong>{tag.label}</strong>
                                  </Typography>
                                  <Stack direction="row" flexWrap="wrap" spacing={0.5} useFlexGap>
                                    {(Object.entries(ENTITY_TYPES) as [EntityTypeKey, typeof ENTITY_TYPES[EntityTypeKey]][]).map(([key, et]) => {
                                      const selected = currentKey === key
                                      return (
                                        <Box
                                          key={key}
                                          onClick={() => {
                                            setTagTypeOverrides(prev => ({ ...prev, [existingTagEditId]: key }))
                                            setExistingTagEditId(null)
                                            setExistingTagEditAnchor(null)
                                          }}
                                          sx={{
                                            display: 'flex', alignItems: 'center', gap: 0.5, px: 1, py: 0.4,
                                            border: '1px solid', borderColor: selected ? et.color : 'divider',
                                            borderRadius: 1, cursor: 'pointer',
                                            bgcolor: selected ? `${et.color}18` : 'transparent',
                                            '&:hover': { bgcolor: `${et.color}18` },
                                          }}
                                        >
                                          <Box sx={{ width: 10, height: 10, borderRadius: 0.5, bgcolor: et.color, flexShrink: 0 }} />
                                          <Typography sx={{ fontSize: '0.72rem', fontWeight: selected ? 700 : 400 }}>{et.label}</Typography>
                                        </Box>
                                      )
                                    })}
                                  </Stack>
                                </Box>
                              )
                            })()}
                          </Popover>

                          {/* Zone 2 — Search input + dropdown
                              NOTE: a second, deliberately similar-looking tag
                              picker lives in `components/ui/SemanticTagPicker`
                              (used by the basket's bulk tag action). It is a
                              separate implementation — see the rationale in
                              its docblock — so a change to the row or chip
                              appearance here needs the same change there. */}
                          <Box ref={tagPickerRef} sx={{ position: 'relative' }}>
                            <TextField
                              size="small"
                              fullWidth
                              placeholder="Search tags…"
                              value={tagSearchQuery}
                              onChange={(e) => { setTagSearchQuery(e.target.value); setTagDropdownOpen(true); setTagCreateError(null) }}
                              onFocus={handleTagFocus}
                              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); handleTagCreate() } }}
                              InputProps={{
                                startAdornment: (
                                  <InputAdornment position="start">
                                    {tagSearchLoading
                                      ? <CircularProgress size={14} />
                                      : <Search sx={{ fontSize: '1rem', color: 'text.disabled' }} />
                                    }
                                  </InputAdornment>
                                ),
                              }}
                            />

                            {/* Dropdown */}
                            {tagDropdownOpen && (() => {
                              const isSearching = tagSearchQuery.trim().length > 0
                              const pool = isSearching ? tagSearchResults : tagSuggestions
                              const visiblePool = pool.filter(t => !resourceTagIds.has(t.id))
                              const showCreate = isSearching && !tagSearchLoading
                                && !tagSearchResults.some(t => t.label.toLowerCase() === tagSearchQuery.trim().toLowerCase())
                              const showDropdown = visiblePool.length > 0 || showCreate || tagSearchLoading
                              if (!showDropdown && !isSearching) return null
                              return (
                              <Paper elevation={4} sx={{ position: 'absolute', top: '100%', left: 0, right: 0, mt: 0.5, zIndex: 10, maxHeight: 220, overflowY: 'auto', border: '1px solid', borderColor: 'divider' }}>
                                {!isSearching && (
                                  <Box sx={{ px: 1.5, pt: 1, pb: 0.25 }}>
                                    <Typography variant="caption" sx={{ color: 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>All tags</Typography>
                                  </Box>
                                )}
                                {/* Matching results (excluding already-selected) */}
                                {visiblePool
                                  .map((tag) => {
                                    const entityKey = tag.entity_type && tag.entity_type in ENTITY_TYPES ? tag.entity_type as EntityTypeKey : null
                                    const et = entityKey ? ENTITY_TYPES[entityKey] : null
                                    const color = et?.color ?? null
                                    const EntityIcon = et?.Icon ?? null
                                    return (
                                      <Box
                                        key={tag.id}
                                        onMouseDown={(e) => { e.preventDefault(); handleTagAdd(tag) }}
                                        sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1.5, py: 0.875, cursor: 'pointer', '&:hover': { bgcolor: 'grey.50' } }}
                                      >
                                        {EntityIcon
                                          ? <Box sx={{ width: 22, height: 22, borderRadius: 0.75, bgcolor: color, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                                              <EntityIcon sx={{ fontSize: '0.75rem', color: 'white' }} />
                                            </Box>
                                          : <Box sx={{ width: 22, height: 22, borderRadius: 0.75, bgcolor: 'grey.200', flexShrink: 0 }} />
                                        }
                                        <Typography variant="body2" sx={{ flex: 1 }}>{tag.label}</Typography>
                                        <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, flexShrink: 0 }}>
                                          {et && (
                                            <Typography variant="caption" sx={{ color: color ?? 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.4, fontSize: '0.68rem', fontWeight: 600 }}>
                                              {et.label}
                                            </Typography>
                                          )}
                                          {tag.resources_count != null && tag.resources_count > 0 && (
                                            <Typography variant="caption" sx={{ color: 'text.disabled', fontSize: '0.68rem' }}>
                                              · {tag.resources_count}×
                                            </Typography>
                                          )}
                                        </Box>
                                      </Box>
                                    )
                                  })
                                }

                                {/* Create options — one per entity type, only in search mode when no exact match */}
                                {showCreate && (
                                  <>
                                    <Box sx={{ px: 1.5, pt: 0.75, pb: 0.25, borderTop: visiblePool.length > 0 ? '1px solid' : 'none', borderColor: 'divider' }}>
                                      <Typography variant="caption" sx={{ color: 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                                        Create as…
                                      </Typography>
                                    </Box>
                                    {(Object.entries(ENTITY_TYPES) as [EntityTypeKey, typeof ENTITY_TYPES[EntityTypeKey]][]).map(([key, et]) => (
                                      <Box
                                        key={key}
                                        onMouseDown={(e) => { e.preventDefault(); handleTagCreate(key) }}
                                        sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1.5, py: 0.75, cursor: creatingTag ? 'default' : 'pointer', '&:hover': { bgcolor: 'grey.50' } }}
                                      >
                                        <Box sx={{ width: 20, height: 20, borderRadius: 0.75, bgcolor: et.color, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                                          <et.Icon sx={{ fontSize: '0.75rem', color: 'white' }} />
                                        </Box>
                                        <Typography variant="body2">
                                          <Typography component="span" variant="body2" sx={{ color: 'text.disabled' }}>Create </Typography>
                                          <Typography component="span" variant="body2" sx={{ fontWeight: 600 }}>"{tagSearchQuery.trim()}"</Typography>
                                          <Typography component="span" variant="body2" sx={{ color: 'text.disabled' }}> as {et.label}</Typography>
                                        </Typography>
                                        {creatingTag && <CircularProgress size={12} sx={{ ml: 'auto' }} />}
                                      </Box>
                                    ))}
                                  </>
                                )}

                                {/* Already-added notice */}
                                {isSearching && !tagSearchLoading && visiblePool.length === 0 && !showCreate && (
                                  <Box sx={{ px: 1.5, py: 1 }}>
                                    <Typography variant="caption" color="text.disabled">Already added</Typography>
                                  </Box>
                                )}
                              </Paper>
                              )
                            })()}
                          </Box>

                          {tagCreateError && (
                            <Typography variant="caption" color="error" sx={{ mt: 0.5, display: 'block' }}>{tagCreateError}</Typography>
                          )}
                          <Typography variant="caption" sx={{ color: 'text.disabled', mt: 0.5, display: 'block' }}>
                            New tags can be assigned an entity type and edited after saving. Org-wide tags are managed in Platform Administration → Tags.
                          </Typography>
                        </Box>
                      </>
                    )}

                    {/* Classification section — edit mode only */}
                    {isEditMode && editedResource && (
                      <EditableBasicInfo
                        resource={editedResource}
                        onChange={setEditedResource}
                        errors={validationErrors}
                        collection={activeCollection}
                        section="classification"
                      />
                    )}
                  </TabPanel>
                  <TabPanel value={tabValue} index={1}>
                    {/* Aggregate AITY status — one glance instead of expanding each
                        file row. Status + retry only; accepting suggestions stays
                        inline at the fields. */}
                    {resource?.files && resource.files.length > 0 && (
                      <Box sx={{ mb: 2 }}>
                        <AityStatusAccordion
                          files={resource.files as any}
                          statuses={aityStatuses}
                          onRetryFile={handleRetryFileAity}
                          onRetryAllFailed={handleRetryFailedAity}
                          backgroundRefreshPending={backgroundRefreshPending}
                          onRefresh={handleBackgroundRefresh}
                          acceptedTagLabels={acceptedTagLabels}
                        />
                      </Box>
                    )}
                    {renderFiles()}
                  </TabPanel>
                  <TabPanel value={tabValue} index={2}>
                    {renderLomData()}
                  </TabPanel>
                  <TabPanel value={tabValue} index={3}>
                    {renderActivity()}
                  </TabPanel>
                  <TabPanel value={tabValue} index={4}>
                    {resource?.id && isEditMode && (
                      <Box sx={{ py: 4, textAlign: 'center' }}>
                        <Typography variant="body2" color="text.secondary">
                          Vault links are only available in view mode.
                        </Typography>
                        <Typography variant="caption" color="text.disabled">
                          Save or cancel your changes to see the generated links.
                        </Typography>
                      </Box>
                    )}
                    {resource?.id && !isEditMode && (
                      <VaultLinksPanel
                        resourceId={String(resource.id)}
                        open={tabValue === 4}
                      />
                    )}
                  </TabPanel>
                  <TabPanel value={tabValue} index={5}>
                    {renderDebugInfo()}
                  </TabPanel>
                </Box>
              </Box>
            </Box>
          </Box>
        )}
      </DialogContent>

      {/* ── Unified exit dialog (Cancel and X share this) ─────────────────── */}
      <Dialog
        open={exitStage === 'asking' || exitStage === 'removing'}
        onClose={(_e, reason) => {
          // Force an explicit decision — no dismissal via backdrop / Escape.
          if (reason === 'backdropClick' || reason === 'escapeKeyDown') return
        }}
        maxWidth="xs"
        fullWidth
      >
        {(() => {
          const totalUploads     = sessionUploadedFileIds.size + uploadingCount
          const hasUploadActivity = totalUploads > 0
          const verb              = exitTarget === 'close' ? 'Close' : 'Cancel edits'
          const stayLabel         = exitTarget === 'close' ? 'Stay open' : 'Stay editing'
          return (
            <>
              <DialogTitle sx={{ fontWeight: 600 }}>
                {exitStage === 'removing' ? 'Removing uploads…' : verb}
              </DialogTitle>
              <DialogContent>
                {exitStage === 'removing' ? (
                  <Stack spacing={2} alignItems="center" sx={{ py: 1 }}>
                    <CircularProgress size={24} />
                    <DialogContentText sx={{ textAlign: 'center' }}>
                      Deleting uploaded files…
                    </DialogContentText>
                  </Stack>
                ) : hasUploadActivity ? (
                  <DialogContentText>
                    You have {totalUploads} uploaded file(s) on this resource that haven't been saved. Keep them to resume editing later, or remove them now.
                  </DialogContentText>
                ) : (
                  <DialogContentText>
                    You have unsaved changes. Discard them?
                  </DialogContentText>
                )}
              </DialogContent>
              {exitStage === 'asking' && (
                <DialogActions sx={{ px: 3, pb: 2 }}>
                  <Button onClick={handleExitStay} variant="outlined" sx={{ textTransform: 'none' }}>
                    {stayLabel}
                  </Button>
                  {hasUploadActivity ? (
                    <>
                      <Button onClick={handleExitRemove} variant="outlined" color="error" sx={{ textTransform: 'none' }}>
                        Remove uploads
                      </Button>
                      <Button onClick={handleExitKeep} variant="contained" sx={{ textTransform: 'none' }}>
                        Keep for later
                      </Button>
                    </>
                  ) : (
                    <Button onClick={handleExitKeep} variant="contained" color="error" sx={{ textTransform: 'none' }}>
                      Discard
                    </Button>
                  )}
                </DialogActions>
              )}
            </>
          )
        })()}
      </Dialog>

      {/* ── Previous-session recovery dialog ──────────────────────────────── */}
      <Dialog
        open={recoveryDialogOpen}
        onClose={(_e, reason) => {
          // Force an explicit decision — no dismissal via backdrop / Escape.
          if (reason === 'backdropClick' || reason === 'escapeKeyDown') return
        }}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle sx={{ fontWeight: 600 }}>Files from a previous session</DialogTitle>
        <DialogContent>
          <DialogContentText sx={{ mb: 2 }}>
            You have {previousSessionFiles.length} uploaded file(s) on this resource that were never saved. Accept them now (they become permanent and you can keep editing) or discard them (they're deleted).
          </DialogContentText>
          <Stack spacing={0.5} sx={{ maxHeight: 200, overflowY: 'auto', bgcolor: 'grey.50', p: 1, borderRadius: 1 }}>
            {previousSessionFiles.map((f: any) => (
              <Typography key={f.id} variant="body2" sx={{ fontFamily: 'monospace', fontSize: '0.8rem' }}>
                {f.filename || f.id}
              </Typography>
            ))}
          </Stack>
        </DialogContent>
        <DialogActions sx={{ px: 3, pb: 2 }}>
          <Button onClick={handleRecoveryDiscard} variant="outlined" color="error" disabled={recoveryBusy !== null} sx={{ textTransform: 'none' }}>
            {recoveryBusy === 'discard' ? 'Discarding…' : 'Discard'}
          </Button>
          <Button onClick={handleRecoveryAccept} variant="contained" disabled={recoveryBusy !== null} sx={{ textTransform: 'none' }}>
            {recoveryBusy === 'accept' ? 'Accepting…' : 'Accept'}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Snackbar for success/error feedback */}
      <Snackbar
        open={snackbar.open}
        autoHideDuration={6000}
        onClose={() => setSnackbar({ ...snackbar, open: false })}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert
          onClose={() => setSnackbar({ ...snackbar, open: false })}
          severity={snackbar.severity}
          sx={{ width: '100%' }}
        >
          {snackbar.message}
        </Alert>
      </Snackbar>
    </Dialog>
  )
}

export default ResourceDetailModal
