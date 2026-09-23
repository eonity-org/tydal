import { useState, useCallback, useMemo, useEffect, useRef } from 'react'
import {
  Box,
  Stack,
  Typography,
  Paper,
  IconButton,
  Button,
  LinearProgress,
  Divider,
  Tooltip,
  Alert,
  Chip,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  Collapse,
  CircularProgress,
} from '@mui/material'
import {
  Delete,
  DeleteOutline,
  CloudUpload,
  InsertDriveFile,
  Star,
  StarBorder,
  Upload,
  Hub,
  HubOutlined,
  InfoOutlined,
  ExpandLess,
  ExpandMore,
} from '@mui/icons-material'
import TydalIsotype from '../../ui/TydalIsotype'
import resourceService, { type ResourceData } from '../../../api/resourceService'
import { FileAityCard } from '../../suggestions/FileAityCard'
import type { AityPollEntry } from '../../../hooks/useAityPolling'

const MAX_UPLOAD_MB = 300
const MAX_UPLOAD_BYTES = MAX_UPLOAD_MB * 1024 * 1024

/** Supports exact matches and wildcards like `image/*`. */
function mimeMatches(mime: string, accepted: string[]): boolean {
  if (accepted.length === 0) return true
  return accepted.some((a) => {
    if (a === mime) return true
    if (a.endsWith('/*') && mime.startsWith(a.slice(0, -1))) return true
    return false
  })
}

export interface FileConfig {
  role: string
  relation?: string
}

export interface EditableFilesProps {
  resource: ResourceData
  acceptedMimeTypes?: string[]
  onFilesChange: (files: File[], filesToRemove: string[], configs: Record<number, FileConfig>) => void
  onExistingConfigsChange?: (configs: Record<string, FileConfig>) => void
  onPreviewChange: (preview: File | null) => void
  onSnapshotChange?: (fileId: string | null) => void
  onRejectedFilesChange?: (hasRejected: boolean) => void
  onCanonicalChange?: (fileId: string | null) => void
  snapshotFileId?: string | null
  onFileClick?: (fileIndex: number) => void
  selectedFileIndex?: number
  aityStatuses?: Record<string, AityPollEntry>
  onRetryAity?: (fileId: string) => Promise<void>
  /** Called when a session upload finishes; parent should start AITY polling for the
   *  file. The resourceId is passed explicitly (not read from props) because in create
   *  mode the upload targets a freshly-created draft whose id isn't in this closure's
   *  stale `resource` prop yet. */
  onSessionUploadComplete?: (fileId: string, resourceId: string) => void
  /**
   * File IDs uploaded in this modal session. The parent may refetch the resource
   * after AITY completes, at which point these files appear in `resource.files`
   * too — so we hide them from "Current Files" to avoid double-counting (they're
   * already shown in the "Uploaded this session" group).
   */
  sessionUploadedFileIds?: Set<string>
  /** Reports the count of uploads still in the 'uploading' phase. */
  onUploadingCountChange?: (count: number) => void
  /**
   * Reports the real server file id for each finished session upload, keyed by its
   * newFiles index. Lets the parent target the snapshot (which needs a real id) on a
   * freshly-uploaded file that's still shown as a temporary "new-{index}" blob.
   */
  onUploadedIdsChange?: (idsByIndex: Record<number, string>) => void
  /**
   * Parent ref that EditableFiles populates with an "abort all in-flight uploads"
   * function. The parent calls it from the close-while-uploading flow.
   */
  abortAllUploadsRef?: { current: (() => void) | null }
  /**
   * Parent ref that EditableFiles populates with an "add these files" function —
   * the same entry point the dropzone and file-input use. Lets the MediaViewer
   * (left pane) route dropped files through this component's single validated
   * upload path instead of duplicating MIME/role/upload logic.
   */
  addFilesRef?: { current: ((files: File[]) => void) | null }
  /**
   * Create mode only: lazily create the real `draft` resource on the first file
   * so uploads have a parent id and AITY can run during create. Resolves to the
   * resource id (or null if it couldn't be created). When omitted, files with no
   * resource id stay buffered for the parent's Save flow (legacy behavior).
   */
  onEnsureDraft?: () => Promise<string | null>
  /**
   * Parent ref EditableFiles populates with a "toggle canonical" function the
   * MediaViewer canonical icon calls. Accepts a viewer file id ("new-{index}" for
   * a pending upload, otherwise the real file id). Toggling the current canonical
   * off makes the file a component (→ multi-component); toggling a non-canonical
   * file on promotes it and demotes the previous canonical. Reuses the same role
   * handlers as the Files-tab dropdowns so both surfaces stay in sync.
   */
  canonicalToggleRef?: { current: ((viewerFileId: string) => void) | null }
}

/**
 * Lifecycle of a per-row session upload:
 *
 *   uploading ── XHR onProgress ──► aity ── poll(ready) ──► ready
 *      │                              │
 *      └──── XHR error / abort ───────┴──► error
 */
type UploadPhase = 'uploading' | 'aity' | 'ready' | 'error'

interface UploadProgress {
  file: File
  progress: number      // 0–100, only meaningful while phase === 'uploading'
  phase: UploadPhase
  uploadedFileId?: string
  abort?: AbortController
  errorMessage?: string
}

const RELATION_OPTIONS = [
  { value: 'translation', label: 'Translation' },
  { value: 'variant',     label: 'Variant' },
  { value: 'transcript',  label: 'Transcript' },
  { value: 'rendition',   label: 'Rendition' },
  { value: 'derived',     label: 'Derived' },
  { value: 'extracted',   label: 'Extracted' },
]

function EditableFiles(props: EditableFilesProps) {
  const {
    resource,
    acceptedMimeTypes = [],
    onFilesChange,
    onExistingConfigsChange,
    onPreviewChange,
    onSnapshotChange,
    onRejectedFilesChange,
    onCanonicalChange,
    snapshotFileId,
    onFileClick,
    selectedFileIndex,
    aityStatuses,
    onRetryAity,
    onSessionUploadComplete,
    sessionUploadedFileIds,
    onUploadingCountChange,
    onUploadedIdsChange,
    abortAllUploadsRef,
    addFilesRef,
    onEnsureDraft,
    canonicalToggleRef,
  } = props

  const [newFiles, setNewFiles] = useState<File[]>([])
  const [filesToRemove, setFilesToRemove] = useState<string[]>([])
  const [configMap, setConfigMap] = useState<Record<number, FileConfig>>({})
  const [previewNewFileIndex, setPreviewNewFileIndex] = useState<number | null>(null)
  const [uploadProgress, setUploadProgress] = useState<UploadProgress[]>([])
  const [dragActive, setDragActive] = useState(false)
  const [rejectedFiles, setRejectedFiles] = useState<{ name: string; size: number }[]>([])
  const [rejectedMimeFiles, setRejectedMimeFiles] = useState<string[]>([])
  const [helpExpanded, setHelpExpanded] = useState(false)
  const [expandedFileIds, setExpandedFileIds] = useState<Set<string>>(new Set())

  // Pending role/relation overrides for existing files (keyed by file ID)
  const [existingConfigs, setExistingConfigs] = useState<Record<string, FileConfig>>({})

  // The canonical file ID — only one per resource
  const [canonicalFileId, setCanonicalFileId] = useState<string | null>(() => {
    return (resource.files || []).find((f: any) => f.role === 'canonical')?.id ?? null
  })

  // Hide files uploaded in this session — they're already represented in the
  // "Uploaded this session" group via the newFiles buffer + uploadProgress UI.
  const currentFiles = useMemo(
    () => (resource.files || []).filter((f: any) =>
      !sessionUploadedFileIds || !sessionUploadedFileIds.has(f.id)
    ),
    [resource.files, sessionUploadedFileIds]
  )

  // Track mount so the upload XHR's .then/.catch don't setState after unmount,
  // and abort all in-flight uploads on unmount so half-finished POSTs don't
  // resolve into a torn-down React tree.
  const mountedRef = useRef(true)
  useEffect(() => {
    mountedRef.current = true
    return () => {
      mountedRef.current = false
      setUploadProgress((prev) => {
        prev.forEach((p) => { if (p.phase === 'uploading') p.abort?.abort() })
        return prev
      })
    }
  }, [])

  // Always keep the latest uploadProgress reachable through the parent ref so
  // the close-while-uploading flow can abort everything without waiting for a
  // re-render cycle to refresh a closure.
  const uploadProgressRef = useRef<UploadProgress[]>([])
  useEffect(() => { uploadProgressRef.current = uploadProgress }, [uploadProgress])

  useEffect(() => {
    if (!abortAllUploadsRef) return
    abortAllUploadsRef.current = () => {
      uploadProgressRef.current.forEach((p) => {
        if (p.phase === 'uploading') p.abort?.abort()
      })
    }
    return () => { abortAllUploadsRef.current = null }
  }, [abortAllUploadsRef])

  // Report the number of uploads still in the 'uploading' phase to the parent.
  useEffect(() => {
    if (!onUploadingCountChange) return
    const n = uploadProgress.filter((p) => p.phase === 'uploading').length
    onUploadingCountChange(n)
  }, [uploadProgress, onUploadingCountChange])

  // Report each finished session upload's real server id by newFiles index, so the
  // parent can target the snapshot on a still-"new-{index}" file.
  useEffect(() => {
    if (!onUploadedIdsChange) return
    const map: Record<number, string> = {}
    uploadProgress.forEach((p, i) => { if (p.uploadedFileId) map[i] = p.uploadedFileId })
    onUploadedIdsChange(map)
  }, [uploadProgress, onUploadedIdsChange])

  // Derive which role mode the resource is currently in
  const hasCanonicalFile = canonicalFileId !== null ||
    Object.values(configMap).some((c) => c.role === 'canonical')
  const hasComponentFiles = useMemo(() =>
    currentFiles.some((f: any) => f.role === 'component') ||
    Object.values(configMap).some((c) => c.role === 'component'),
    [currentFiles, configMap]
  )

  // Available roles depend on the current mode:
  //   all-components mode  → only component allowed (canonical would conflict)
  //   canonical mode       → canonical and supporting only (component would conflict)
  //   fresh resource       → all three options open
  const availableRoles = useMemo((): Array<'canonical' | 'component' | 'supporting'> => {
    if (hasComponentFiles) return ['component']
    if (hasCanonicalFile)  return ['canonical', 'supporting']
    return ['canonical', 'component', 'supporting']
  }, [hasCanonicalFile, hasComponentFiles])

  const canonicalFileName = useMemo(() =>
    currentFiles.find((f: any) => f.id === canonicalFileId)?.filename ?? 'a file',
    [currentFiles, canonicalFileId]
  )

  // Default role for the next new file
  const defaultNewRole = useMemo((): 'canonical' | 'component' | 'supporting' => {
    if (hasCanonicalFile)  return 'supporting'
    if (hasComponentFiles) return 'component'
    return 'canonical'  // fresh resource — first file becomes canonical
  }, [hasCanonicalFile, hasComponentFiles])

  // Snapshot file ID (prop overrides resource snapshot_file)
  const activeSnapshotFileId = snapshotFileId !== undefined
    ? snapshotFileId
    : (resource.snapshot_file as any)?.id ?? null

  const addFiles = useCallback((incoming: File[]) => {
    // MIME type gate — must run before size check so type errors are reported separately
    const mimeRejected: string[] = []
    if (acceptedMimeTypes.length > 0) {
      const byMime = incoming.filter((f) => !mimeMatches(f.type, acceptedMimeTypes))
      byMime.forEach((f) => mimeRejected.push(f.name))
      incoming = incoming.filter((f) => mimeMatches(f.type, acceptedMimeTypes))
    }
    setRejectedMimeFiles(mimeRejected)

    const accepted: File[] = []
    const rejected: { name: string; size: number }[] = []

    for (const file of incoming) {
      if (file.size > MAX_UPLOAD_BYTES) {
        rejected.push({ name: file.name, size: file.size })
      } else {
        accepted.push(file)
      }
    }

    setRejectedFiles(rejected)
    onRejectedFilesChange?.(rejected.length > 0 || mimeRejected.length > 0)

    if (accepted.length === 0) return

    const updatedFiles = [...newFiles, ...accepted]

    // Role default for fresh resources (no existing canonical/component/files):
    //   - a single dropped file is the canonical asset;
    //   - several files dropped together are peer components → multi-component, so the
    //     auto-approve synthesis unifies name/description/tags across all of them.
    // Once the resource already has a canonical or component file, defaultNewRole takes
    // over (supporting / component respectively). This is only the *default* — the user
    // can still change any file's role in the list below.
    const isFreshResource = !hasCanonicalFile && !hasComponentFiles && currentFiles.length === 0
    const freshRole: 'canonical' | 'component' = updatedFiles.length > 1 ? 'component' : 'canonical'

    const updatedConfigMap = { ...configMap }
    for (let i = newFiles.length; i < updatedFiles.length; i++) {
      const role = isFreshResource ? freshRole : defaultNewRole
      updatedConfigMap[i] = { role }
    }

    setConfigMap(updatedConfigMap)
    setNewFiles(updatedFiles)
    onFilesChange(updatedFiles, filesToRemove, updatedConfigMap)

    // Auto-star the first uploaded file when no snapshot exists yet.
    // The first file is always the canonical, and any file (image or not) can be the snapshot.
    // onPreviewChange is NOT called for canonical files — the backend auto-sets usage=['snapshot']
    // on canonical upload; for non-canonicals we queue a dedicated supporting/rendition/snapshot upload.
    if (activeSnapshotFileId === null && previewNewFileIndex === null && accepted.length > 0) {
      const globalIdx = newFiles.length  // first accepted file in global new-file space
      setPreviewNewFileIndex(globalIdx)
      const willBeCanonical = globalIdx === 0 && defaultNewRole === 'canonical'
      if (!willBeCanonical) {
        onPreviewChange(accepted[0])
      }
      onFileClick?.(currentFiles.length + globalIdx)
    }

    // Eager upload with real XHR progress (replaces the legacy cosmetic setInterval).
    // Files are sent with defer_commit=true so the server marks them session-uncommitted;
    // ResourceDetailModal's Save flow calls commitFiles to flip the flag. In create mode
    // the resource doesn't exist yet — onEnsureDraft() creates the real `draft` resource
    // on this first file so the upload has a parent and AITY can run during create. If no
    // draft can be created (no collection / create failed), files stay buffered as plain
    // newFiles until Save uploads them (legacy fallback).
    ;(async () => {
      let uploadTargetId = resource.id
      if (!uploadTargetId && onEnsureDraft) {
        uploadTargetId = (await onEnsureDraft()) ?? ''
      }
      if (!uploadTargetId) return

      const initialProgress: UploadProgress[] = accepted.map((file) => ({
        file,
        progress: 0,
        phase: 'uploading' as const,
        abort: new AbortController(),
      }))
      const baseIdx = newFiles.length  // index in newFiles where these entries start
      setUploadProgress((prev) => [...prev, ...initialProgress])

      initialProgress.forEach((entry, offset) => {
        const globalIdx = baseIdx + offset
        const role = updatedConfigMap[globalIdx]?.role ?? defaultNewRole
        const relation = updatedConfigMap[globalIdx]?.relation

        resourceService
          .uploadFileWithProgress(uploadTargetId, entry.file, {
            role,
            relation,
            deferCommit: true,
            signal: entry.abort?.signal,
            onProgress: (pct) => {
              if (!mountedRef.current) return
              setUploadProgress((prev) =>
                prev.map((p, i) => (i === globalIdx ? { ...p, progress: pct } : p))
              )
            },
          })
          .then((uploaded) => {
            if (!mountedRef.current) return
            setUploadProgress((prev) =>
              prev.map((p, i) =>
                i === globalIdx
                  ? { ...p, phase: 'aity' as const, progress: 100, uploadedFileId: uploaded.id }
                  : p
              )
            )
            onSessionUploadComplete?.(uploaded.id, uploadTargetId)
          })
          .catch((err: any) => {
            if (err?.name === 'AbortError') return
            if (!mountedRef.current) return
            setUploadProgress((prev) =>
              prev.map((p, i) =>
                i === globalIdx ? { ...p, phase: 'error' as const, errorMessage: err?.message ?? 'Upload failed' } : p
              )
            )
          })
      })
    })()
  }, [newFiles.length, filesToRemove, onFilesChange, configMap, defaultNewRole,
      activeSnapshotFileId, previewNewFileIndex, onPreviewChange, onFileClick, currentFiles.length,
      acceptedMimeTypes, resource.id, onSessionUploadComplete, onEnsureDraft])

  // Advance phase 'aity' → 'ready' / 'error' based on the AITY polling result.
  // The parent owns the polling and feeds it back via aityStatuses.
  useEffect(() => {
    if (!aityStatuses) return
    setUploadProgress((prev) => {
      let changed = false
      const next = prev.map((p) => {
        if (p.phase !== 'aity' || !p.uploadedFileId) return p
        const entry = aityStatuses[p.uploadedFileId]
        const status = entry?.status
        if (status === 'ready' || status === 'not_supported') {
          changed = true
          return { ...p, phase: 'ready' as const }
        }
        if (status === 'failed') {
          changed = true
          return { ...p, phase: 'error' as const, errorMessage: 'AITY analysis failed' }
        }
        return p
      })
      return changed ? next : prev
    })
  }, [aityStatuses])

  const handleFileSelect = useCallback((event: React.ChangeEvent<HTMLInputElement>) => {
    addFiles(Array.from(event.target.files || []))
  }, [addFiles])

  // Expose addFiles to the parent so the MediaViewer dropzone can feed files
  // through this component's single validated path (mirrors abortAllUploadsRef).
  useEffect(() => {
    if (!addFilesRef) return
    addFilesRef.current = (files: File[]) => addFiles(files)
    return () => { addFilesRef.current = null }
  }, [addFilesRef, addFiles])

  const handleDrag = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    if (e.type === 'dragenter' || e.type === 'dragover') {
      setDragActive(true)
    } else if (e.type === 'dragleave') {
      setDragActive(false)
    }
  }, [])

  const handleDrop = useCallback((e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setDragActive(false)
    addFiles(Array.from(e.dataTransfer.files))
  }, [addFiles])

  const handleRemoveNewFile = async (index: number) => {
    const progress = uploadProgress[index]
    // Mid-upload removal: per the "wait, then delete" policy, abort the XHR and stop here.
    // The server never created a File row for an aborted upload, so nothing to clean up.
    if (progress?.phase === 'uploading') {
      progress.abort?.abort()
    } else if (progress?.uploadedFileId) {
      // Upload already landed → the row is real on the server; delete it now.
      try {
        await resourceService.deleteFile(resource.id, progress.uploadedFileId)
      } catch (err) {
        console.warn('deleteFile failed for session upload', err)
      }
    }

    const updatedFiles = newFiles.filter((_, i) => i !== index)

    const updatedConfigMap: Record<number, FileConfig> = {}
    Object.entries(configMap).forEach(([k, v]) => {
      const ki = parseInt(k)
      if (ki < index) updatedConfigMap[ki] = v
      else if (ki > index) updatedConfigMap[ki - 1] = v
    })

    setNewFiles(updatedFiles)
    setConfigMap(updatedConfigMap)
    setUploadProgress((prev) => prev.filter((_, i) => i !== index))
    onFilesChange(updatedFiles, filesToRemove, updatedConfigMap)

    if (previewNewFileIndex === index) {
      setPreviewNewFileIndex(null)
      onPreviewChange(null)
    } else if (previewNewFileIndex !== null && previewNewFileIndex > index) {
      setPreviewNewFileIndex(previewNewFileIndex - 1)
    }
  }

  const handleToggleFileRemoval = (fileId: string) => {
    const updated = filesToRemove.includes(fileId)
      ? filesToRemove.filter((id) => id !== fileId)
      : [...filesToRemove, fileId]
    setFilesToRemove(updated)
    onFilesChange(newFiles, updated, configMap)
  }

  const handleRoleChange = (index: number, role: string) => {
    const updated: Record<number, FileConfig> = { ...configMap }
    const oldRole = updated[index]?.role ?? defaultNewRole
    // Forward transition: convert all other new files (canonical or component) → supporting
    if (role === 'canonical') {
      Object.keys(updated).forEach((k) => {
        const ki = parseInt(k)
        if (ki !== index && (updated[ki].role === 'canonical' || updated[ki].role === 'component')) {
          updated[ki] = { ...updated[ki], role: 'supporting' }
        }
      })
      // Convert existing canonical and component files → supporting
      const updatedExisting = { ...existingConfigs }
      let existingChanged = false
      currentFiles.forEach((f: any) => {
        const currentRole = updatedExisting[f.id]?.role ?? f.role
        if (currentRole === 'canonical' || currentRole === 'component') {
          updatedExisting[f.id] = { ...updatedExisting[f.id], role: 'supporting' as const }
          existingChanged = true
        }
      })
      if (existingChanged) {
        setExistingConfigs(updatedExisting)
        onExistingConfigsChange?.(updatedExisting)
      }
      setCanonicalFileId(null)
      onCanonicalChange?.(null)
    }
    // Reverse transition: when demoting a new canonical and no other canonical remains,
    // convert other supporting files (new + existing) back to component.
    if (oldRole === 'canonical' && role !== 'canonical') {
      const otherNewCanonical = Object.entries(updated).some(([k, c]) => parseInt(k) !== index && c.role === 'canonical')
      if (!otherNewCanonical && !canonicalFileId) {
        Object.keys(updated).forEach((k) => {
          const ki = parseInt(k)
          if (ki !== index && updated[ki].role === 'supporting') {
            updated[ki] = { ...updated[ki], role: 'component' }
          }
        })
        setExistingConfigs((prev) => {
          const updatedEx = { ...prev }
          let changed = false
          currentFiles.forEach((f: any) => {
            const currentRole = prev[f.id]?.role ?? f.role
            if (currentRole === 'supporting') {
              updatedEx[f.id] = { ...prev[f.id], role: 'component' }
              changed = true
            }
          })
          if (changed) onExistingConfigsChange?.(updatedEx)
          return changed ? updatedEx : prev
        })
      }
    }
    updated[index] = { ...updated[index], role }
    // Canonical files cannot have a relation
    if (role === 'canonical') updated[index].relation = undefined
    setConfigMap(updated)
    onFilesChange(newFiles, filesToRemove, updated)

    // NOTE: snapshot (the star) is intentionally independent of canonical. Changing a
    // file's role no longer touches the snapshot — the old "canonical is its own
    // snapshot" coupling is legacy and no longer applies.

    // Session uploads are already on the server (defer_commit=true). Sync the role change
    // back so Save only has to flip uncommitted_at.
    const uploadedId = uploadProgress[index]?.uploadedFileId
    if (uploadedId) {
      const relationForPatch = role === 'canonical' ? null : (updated[index].relation ?? null)
      resourceService.updateFile(resource.id, uploadedId, { role, relation: relationForPatch })
        .catch((err) => console.warn('updateFile (role) failed for session upload', err))
    }
  }

  const handleRelationChange = (index: number, relation: string) => {
    const updated = { ...configMap, [index]: { ...configMap[index], relation } }
    setConfigMap(updated)
    onFilesChange(newFiles, filesToRemove, updated)

    const uploadedId = uploadProgress[index]?.uploadedFileId
    if (uploadedId) {
      resourceService.updateFile(resource.id, uploadedId, { relation: relation || null })
        .catch((err) => console.warn('updateFile (relation) failed for session upload', err))
    }
  }

  const handleExistingRoleChange = (fileId: string, role: string) => {
    const updated = { ...existingConfigs, [fileId]: { ...existingConfigs[fileId], role } }
    if (role === 'canonical') {
      updated[fileId].relation = undefined
      // Convert all OTHER existing canonical/component files → supporting (forward mode
      // transition). Demoting the prior canonical too keeps a single canonical — without
      // it, promoting a second existing file would leave two canonicals (save then errors).
      currentFiles.forEach((f: any) => {
        if (f.id !== fileId) {
          const currentRole = updated[f.id]?.role ?? f.role
          if (currentRole === 'canonical' || currentRole === 'component') {
            updated[f.id] = { ...updated[f.id], role: 'supporting' }
          }
        }
      })
      // Demote any NEW file that's currently canonical/component → supporting as well.
      setConfigMap((prev) => {
        const cfg = { ...prev }
        let changed = false
        Object.keys(cfg).forEach((k) => {
          const ki = parseInt(k)
          if (cfg[ki].role === 'canonical' || cfg[ki].role === 'component') {
            cfg[ki] = { ...cfg[ki], role: 'supporting' }
            changed = true
          }
        })
        if (changed) onFilesChange(newFiles, filesToRemove, cfg)
        return changed ? cfg : prev
      })
    }
    // Reverse transition: when demoting from canonical, convert other supporting files → component
    if (canonicalFileId === fileId && role !== 'canonical') {
      currentFiles.forEach((f: any) => {
        if (f.id !== fileId) {
          const currentRole = updated[f.id]?.role ?? f.role
          if (currentRole === 'supporting') {
            updated[f.id] = { ...updated[f.id], role: 'component' }
          }
        }
      })
    }
    setExistingConfigs(updated)
    onExistingConfigsChange?.(updated)
    if (role === 'canonical') {
      setCanonicalFileId(fileId)
      onCanonicalChange?.(fileId)
    } else if (canonicalFileId === fileId) {
      setCanonicalFileId(null)
      onCanonicalChange?.(null)
      // Also reverse new supporting files → component
      setConfigMap((prev) => {
        const updatedCfg = { ...prev }
        let changed = false
        Object.keys(updatedCfg).forEach((k) => {
          if (updatedCfg[parseInt(k)].role === 'supporting') {
            updatedCfg[parseInt(k)] = { ...updatedCfg[parseInt(k)], role: 'component' }
            changed = true
          }
        })
        if (changed) onFilesChange(newFiles, filesToRemove, updatedCfg)
        return changed ? updatedCfg : prev
      })
    }
  }

  const handleExistingRelationChange = (fileId: string, relation: string) => {
    const updated = { ...existingConfigs, [fileId]: { ...existingConfigs[fileId], relation: relation || undefined } }
    setExistingConfigs(updated)
    onExistingConfigsChange?.(updated)
  }

  // Toggle canonical from the MediaViewer icon. Maps a viewer file id to the right
  // role handler (new vs existing) and flips canonical ⇆ component. Delegates to the
  // same handlers the dropdowns use, so the Files tab stays consistent.
  const toggleCanonicalForViewer = (viewerFileId: string) => {
    const newMatch = /^new-(\d+)$/.exec(viewerFileId)
    if (newMatch) {
      const idx = parseInt(newMatch[1], 10)
      const isCanon = (configMap[idx]?.role ?? defaultNewRole) === 'canonical'
      handleRoleChange(idx, isCanon ? 'component' : 'canonical')
    } else {
      const f = currentFiles.find((x: any) => x.id === viewerFileId)
      const isCanon = (existingConfigs[viewerFileId]?.role ?? f?.role) === 'canonical'
      handleExistingRoleChange(viewerFileId, isCanon ? 'component' : 'canonical')
    }
  }

  useEffect(() => {
    if (!canonicalToggleRef) return
    canonicalToggleRef.current = toggleCanonicalForViewer
    return () => { canonicalToggleRef.current = null }
  })

  const handleStarExistingFile = (fileId: string) => {
    const next = activeSnapshotFileId === fileId ? null : fileId
    onSnapshotChange?.(next)
    if (previewNewFileIndex !== null) {
      setPreviewNewFileIndex(null)
      onPreviewChange(null)
    }
    // Navigate the viewer to the starred file so the preview updates immediately
    if (next !== null) {
      const idx = currentFiles.findIndex((f: any) => f.id === fileId)
      if (idx !== -1) onFileClick?.(idx)
    }
  }

  const handleToggleCanonical = useCallback((fileId: string) => {
    const next = canonicalFileId === fileId ? null : fileId
    setCanonicalFileId(next)
    onCanonicalChange?.(next)
    if (next !== null) {
      // FORWARD: convert component new-files → supporting, demote any existing canonical new file
      setConfigMap((prev) => {
        const updated = { ...prev }
        let changed = false
        Object.keys(updated).forEach((k) => {
          const ki = parseInt(k)
          if (updated[ki].role === 'canonical' || updated[ki].role === 'component') {
            updated[ki] = { ...updated[ki], role: 'supporting' }
            changed = true
          }
        })
        if (changed) onFilesChange(newFiles, filesToRemove, updated)
        return changed ? updated : prev
      })
      // Convert existing component files to supporting; also clear the promoted file's
      // existingConfigs entry so effectiveRole falls through to the canonical check.
      setExistingConfigs((prev) => {
        const updated = { ...prev }
        let changed = false
        if (prev[fileId] !== undefined) {
          delete updated[fileId]
          changed = true
        }
        currentFiles.forEach((f: any) => {
          if (f.id !== fileId) {
            const currentRole = prev[f.id]?.role ?? f.role
            if (currentRole === 'component') {
              updated[f.id] = { ...prev[f.id], role: 'supporting' }
              changed = true
            }
          }
        })
        if (changed) onExistingConfigsChange?.(updated)
        return changed ? updated : prev
      })
    } else {
      // REVERSE: canonical removed → convert all supporting files (+ former canonical) → component
      setConfigMap((prev) => {
        const updated = { ...prev }
        let changed = false
        Object.keys(updated).forEach((k) => {
          if (updated[parseInt(k)].role === 'supporting') {
            updated[parseInt(k)] = { ...updated[parseInt(k)], role: 'component' }
            changed = true
          }
        })
        if (changed) onFilesChange(newFiles, filesToRemove, updated)
        return changed ? updated : prev
      })
      setExistingConfigs((prev) => {
        const updated = { ...prev }
        // Former canonical → component
        updated[fileId] = { ...prev[fileId], role: 'component' }
        // All other supporting → component
        currentFiles.forEach((f: any) => {
          if (f.id !== fileId) {
            const role = prev[f.id]?.role ?? f.role
            if (role === 'supporting') {
              updated[f.id] = { ...prev[f.id], role: 'component' }
            }
          }
        })
        onExistingConfigsChange?.(updated)
        return updated
      })
    }
  }, [canonicalFileId, onCanonicalChange, onFilesChange, onExistingConfigsChange, newFiles, filesToRemove, currentFiles])

  const handleStarNewFile = (index: number, file: File) => {
    // Once the eager XHR has finished, the new file has a real server ID. Route
    // the snapshot decision through the existing-file path (snapshotFileId)
    // so Save calls setFileSnapshot on it. Otherwise the only signal we leave
    // is `newPreview: File`, which the edit-mode save flow ignores for files
    // that already exist server-side — so the previous snapshot would survive.
    const serverId = uploadProgress[index]?.uploadedFileId

    if (previewNewFileIndex === index) {
      setPreviewNewFileIndex(null)
      onPreviewChange(null)
      if (serverId) onSnapshotChange?.(null)
    } else {
      setPreviewNewFileIndex(index)
      if (serverId) {
        // Eager-uploaded already — persist via setFileSnapshot at save time.
        onSnapshotChange?.(serverId)
        onPreviewChange(null)
      } else {
        // Still buffering locally (create mode, or upload mid-flight) —
        // fall back to the File-based path; save uploads it with usage=snapshot.
        onPreviewChange(file)
        onSnapshotChange?.(null)
      }
      // Navigate the viewer to this new file (new files appear after existing ones in allFiles)
      const existingCount = currentFiles.length
      onFileClick?.(existingCount + index)
    }
  }

  const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 Bytes'
    const k = 1024
    const sizes = ['Bytes', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
  }

  return (
    <Stack spacing={3}>
      {/* Upload Section */}
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 600, mb: 2 }}>
          Upload New Files
        </Typography>

        <Paper
          variant="outlined"
          sx={{
            p: 4,
            textAlign: 'center',
            border: dragActive ? '2px dashed' : '2px solid',
            borderColor: dragActive ? 'primary.main' : 'divider',
            bgcolor: dragActive ? 'primary.50' : 'background.paper',
            cursor: 'pointer',
            transition: 'all 0.2s',
          }}
          onDragEnter={handleDrag}
          onDragLeave={handleDrag}
          onDragOver={handleDrag}
          onDrop={handleDrop}
          onClick={() => document.getElementById('file-upload-input')?.click()}
        >
          <Stack spacing={2} alignItems="center">
            <CloudUpload sx={{ fontSize: '3rem', color: 'primary.main' }} />
            <Box>
              <Typography variant="body1" sx={{ fontWeight: 500 }}>
                {dragActive ? 'Drop files here' : 'Drag & drop files here'}
              </Typography>
              <Typography variant="body2" color="text.secondary">
                or click to browse · max {MAX_UPLOAD_MB} MB per file
              </Typography>
              {acceptedMimeTypes.length > 0 && (
                <Typography variant="caption" color="text.disabled" sx={{ textAlign: 'center' }}>
                  {acceptedMimeTypes.join(', ')}
                </Typography>
              )}
            </Box>
            <Button
              variant="outlined"
              startIcon={<Upload />}
              size="small"
              onClick={(e) => {
                e.stopPropagation()
                document.getElementById('file-upload-input')?.click()
              }}
            >
              Select Files
            </Button>
            <Box
              id="file-upload-input"
              component="input"
              type="file"
              multiple
              accept={acceptedMimeTypes.length > 0 ? acceptedMimeTypes.join(',') : undefined}
              sx={{ display: 'none' }}
              onChange={handleFileSelect as any}
            />
          </Stack>
        </Paper>
      </Box>

      {/* Rejection alerts — shown between dropzone and file list so they're visible in context */}
      {rejectedMimeFiles.length > 0 && (
        <Alert
          severity="error"
          onClose={() => { setRejectedMimeFiles([]); onRejectedFilesChange?.(rejectedFiles.length > 0) }}
        >
          <Typography variant="caption" sx={{ display: 'block', fontWeight: 600, mb: 0.25 }}>
            File type not allowed in this collection:
          </Typography>
          {rejectedMimeFiles.map((name) => (
            <Typography key={name} variant="caption" sx={{ display: 'block', ml: 1 }}>· {name}</Typography>
          ))}
          {acceptedMimeTypes.length > 0 && (
            <Typography variant="caption" sx={{ display: 'block', mt: 0.5, opacity: 0.8 }}>
              Accepted: {acceptedMimeTypes.join(', ')}
            </Typography>
          )}
        </Alert>
      )}
      {rejectedFiles.length > 0 && (
        <Alert severity="error" onClose={() => { setRejectedFiles([]); onRejectedFilesChange?.(rejectedMimeFiles.length > 0) }}>
          <strong>Too large — max {MAX_UPLOAD_MB} MB per file:</strong>
          {rejectedFiles.map((f) => (
            <Typography key={f.name} variant="caption" display="block">
              {f.name} ({formatFileSize(f.size)})
            </Typography>
          ))}
        </Alert>
      )}

      {/* Uploaded this session — files dropped during the current edit session.
          They were uploaded eagerly with defer_commit=true; ResourceDetailModal commits
          them on Save (or deletes them on Discard). */}
      {newFiles.length > 0 && (
        <Box>
          <Typography variant="h6" sx={{ fontWeight: 600, mb: 1 }}>
            Uploaded this session ({newFiles.length})
          </Typography>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
            <Chip
              size="small"
              label={hasCanonicalFile ? 'Canonical + Supporting' : 'Multi-component'}
              color="primary"
              variant="filled"
              sx={{ fontSize: '0.7rem', height: 20 }}
            />
            <Typography variant="caption" color="text.secondary">
              {hasCanonicalFile
                ? `${canonicalFileName} is the primary source. Click ⊕ to change or remove it.`
                : 'All files are equal peers. Click ⊕ on any file to define a canonical.'}
            </Typography>
          </Box>
          <Stack spacing={1}>
            {newFiles.map((file, index) => {
              const progress = uploadProgress[index]
              const fileIndex = currentFiles.length + index
              const isSelected = selectedFileIndex === fileIndex
              const isPreview = previewNewFileIndex === index
              const config = configMap[index] ?? { role: defaultNewRole }
              const isNewCanonical = config.role === 'canonical'
              return (
                <Paper
                  key={index}
                  variant="outlined"
                  onClick={() => onFileClick?.(fileIndex)}
                  sx={{
                    p: 2,
                    bgcolor: isSelected ? 'primary.subtle' : 'background.paper',
                    borderColor: isSelected ? 'primary.main' : 'divider',
                    cursor: 'pointer',
                    transition: 'all 0.2s',
                    '&:hover': { borderColor: 'primary.light', bgcolor: 'action.hover' },
                  }}
                >
                  <Stack spacing={1}>
                    <Stack direction="row" spacing={1} alignItems="center">
                      <InsertDriveFile color="primary" />
                      <Box sx={{ flex: 1, minWidth: 0 }}>
                        <Typography variant="body2" sx={{ fontWeight: 500 }} noWrap>
                          {file.name}
                        </Typography>
                        <Typography variant="caption" color="text.disabled" sx={{ display: 'block' }}>
                          {formatFileSize(file.size)}
                        </Typography>
                      </Box>
                      <Tooltip
                        title={
                          isNewCanonical
                            ? 'Remove canonical — all files revert to Multi-component mode'
                            : hasCanonicalFile
                              ? `Set as canonical (replaces ${canonicalFileName})`
                              : 'Set as canonical — switches to Canonical + Supporting mode'
                        }
                        placement="left"
                      >
                        <IconButton
                          size="small"
                          onClick={(e) => { e.stopPropagation(); onFileClick?.(fileIndex); handleRoleChange(index, isNewCanonical ? 'component' : 'canonical') }}
                          sx={{
                            color: isNewCanonical ? 'primary.main' : 'action.disabled',
                            '&:hover': { color: 'primary.main' },
                          }}
                        >
                          {isNewCanonical ? <Hub fontSize="small" /> : <HubOutlined fontSize="small" />}
                        </IconButton>
                      </Tooltip>
                      <Tooltip title={isPreview ? 'Remove as snapshot' : 'Set as snapshot'} placement="left">
                        <IconButton
                          size="small"
                          onClick={(e) => { e.stopPropagation(); onFileClick?.(fileIndex); handleStarNewFile(index, file) }}
                          sx={{
                            color: isPreview ? 'warning.main' : 'action.disabled',
                            '&:hover': { color: 'warning.main' },
                          }}
                        >
                          {isPreview ? <Star fontSize="small" /> : <StarBorder fontSize="small" />}
                        </IconButton>
                      </Tooltip>
                      {(() => {
                        const blockReason = isNewCanonical && isPreview
                          ? 'This file is canonical AND the snapshot — clear both before removing.'
                          : isNewCanonical
                            ? 'This file is canonical — clear the canonical flag before removing.'
                            : isPreview
                              ? 'This file is the snapshot — clear the snapshot star before removing.'
                              : null
                        return (
                          <Tooltip title={blockReason ?? 'Remove'} placement="left">
                            {/* span wrapper so the Tooltip still works when the button is disabled */}
                            <span>
                              <IconButton
                                size="small"
                                onClick={(e) => { e.stopPropagation(); onFileClick?.(fileIndex); handleRemoveNewFile(index) }}
                                disabled={blockReason !== null}
                                color="error"
                              >
                                <Delete fontSize="small" />
                              </IconButton>
                            </span>
                          </Tooltip>
                        )
                      })()}
                    </Stack>
                    <Stack direction="row" spacing={1} alignItems="center" onClick={(e) => e.stopPropagation()}>
                      <FormControl size="small" sx={{ minWidth: 120 }}>
                        <InputLabel sx={{ fontSize: '0.7rem' }}>Role</InputLabel>
                        <Select
                          label="Role"
                          value={config.role}
                          onChange={(e) => handleRoleChange(index, e.target.value)}
                          sx={{ fontSize: '0.75rem', height: 26 }}
                        >
                          {(['canonical', 'component', 'supporting'] as const).map((r) => (
                            <MenuItem key={r} value={r} disabled={!availableRoles.includes(r)}>
                              {r}
                              {!availableRoles.includes(r) && (
                                <Typography variant="caption" color="text.disabled" sx={{ ml: 0.5 }}>
                                  {r === 'component' ? '(canonical exists)' : r === 'canonical' ? '(components exist)' : ''}
                                </Typography>
                              )}
                            </MenuItem>
                          ))}
                        </Select>
                      </FormControl>
                      {config.role !== 'canonical' && (
                        <FormControl size="small" sx={{ minWidth: 130 }}>
                          <InputLabel sx={{ fontSize: '0.7rem' }}>Relation</InputLabel>
                          <Select
                            label="Relation"
                            value={config.relation ?? ''}
                            onChange={(e) => handleRelationChange(index, e.target.value)}
                            sx={{ fontSize: '0.75rem', height: 26 }}
                          >
                            <MenuItem value=""><em>none</em></MenuItem>
                            {RELATION_OPTIONS.map((opt) => (
                              <MenuItem key={opt.value} value={opt.value}>{opt.label}</MenuItem>
                            ))}
                          </Select>
                        </FormControl>
                      )}
                    </Stack>
                    {/* Lifecycle bar: real upload progress while uploading, then a thin row showing
                        AITY analysis state once the bytes are on the server. */}
                    {progress && progress.phase === 'uploading' && (
                      <LinearProgress
                        variant="determinate"
                        value={progress.progress}
                        sx={{ height: 6, borderRadius: 3, overflow: 'hidden' }}
                      />
                    )}
                    {progress && progress.phase === 'aity' && (
                      <Stack direction="row" spacing={1} alignItems="center" sx={{ pl: 0.25 }}>
                        <TydalIsotype size={16} variant="brand-active" />
                        <Typography variant="caption" color="primary.main" sx={{ fontWeight: 500 }}>
                          Aity working…
                        </Typography>
                      </Stack>
                    )}
                    {progress && progress.phase === 'ready' && (
                      <Stack direction="row" spacing={1} alignItems="center" sx={{ pl: 0.25 }}>
                        <TydalIsotype size={16} variant="brand" />
                        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 500 }}>
                          Aity ready
                        </Typography>
                      </Stack>
                    )}
                    {progress && progress.phase === 'error' && (
                      <Alert severity="error" sx={{ py: 0, fontSize: '0.75rem' }}>
                        {progress.errorMessage ?? 'Upload failed'}
                      </Alert>
                    )}
                  </Stack>
                </Paper>
              )
            })}
          </Stack>

          {/* Role & Relation guide */}
          <Box sx={{ mt: 1.5 }}>
            <Stack
              direction="row"
              alignItems="flex-start"
              spacing={0.75}
              sx={{ cursor: 'pointer', userSelect: 'none' }}
              onClick={() => setHelpExpanded((v) => !v)}
            >
              <InfoOutlined sx={{ fontSize: 18, color: 'info.main', flexShrink: 0, mt: '1px' }} />
              <Box sx={{ flex: 1 }}>
                <Typography variant="caption" sx={{ fontWeight: 600, color: 'text.secondary', display: 'block' }}>
                  Role &amp; Relation guide
                </Typography>
                {!helpExpanded && (
                  <Typography variant="caption" color="text.disabled" sx={{ display: 'block' }}>
                    Files use one of two models: all-component peers, or a canonical with optional supporting files.
                  </Typography>
                )}
              </Box>
              {helpExpanded
                ? <ExpandLess sx={{ fontSize: '1rem', color: 'text.disabled', flexShrink: 0 }} />
                : <ExpandMore sx={{ fontSize: '1rem', color: 'text.disabled', flexShrink: 0 }} />
              }
            </Stack>

            <Collapse in={helpExpanded}>
              <Stack spacing={1.5} sx={{ pl: 3, pt: 1 }}>
                <Box>
                  <Typography variant="caption" sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'text.disabled', display: 'block', mb: 0.5 }}>
                    Roles
                  </Typography>
                  <Stack spacing={0.5}>
                    {[
                      { label: 'canonical', desc: 'Primary source file. Drives text extraction, search and AI. One per resource.' },
                      { label: 'supporting', desc: 'Auxiliary file tied to a canonical — a translation, rendition, transcript, etc.' },
                    ].map(({ label, desc }) => (
                      <Stack key={label} direction="row" spacing={1} alignItems="flex-start">
                        <Chip label={label} size="small" variant={label === 'supporting' ? 'outlined' : 'filled'} color="primary" sx={{ height: 18, fontSize: '0.65rem', mt: 0.2, flexShrink: 0 }} />
                        <Typography variant="caption" color="text.secondary">{desc}</Typography>
                      </Stack>
                    ))}
                    <Divider sx={{ my: 0.25 }} />
                    <Stack direction="row" spacing={1} alignItems="flex-start">
                      <Chip label="component" size="small" variant="filled" color="primary" sx={{ height: 18, fontSize: '0.65rem', mt: 0.2, flexShrink: 0 }} />
                      <Typography variant="caption" color="text.secondary">Equal-weight peer in a multi-file set (e.g. a photo series). No canonical alongside.</Typography>
                    </Stack>
                  </Stack>
                </Box>

                <Box>
                  <Typography variant="caption" sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'text.disabled', display: 'block', mb: 0.5 }}>
                    Relations <Box component="span" sx={{ fontWeight: 400, textTransform: 'none', letterSpacing: 0 }}>(optional, supporting only)</Box>
                  </Typography>
                  <Stack spacing={0.5}>
                    {[
                      { label: 'derived',     desc: 'Transformed from the canonical — conversion, crop, edit.' },
                      { label: 'rendition',   desc: 'Delivery-optimised copy — thumbnail, web preview, lower resolution.' },
                      { label: 'variant',     desc: 'Meaningful variation — different layout, format, or presentation intent.' },
                      { label: 'translation', desc: 'The canonical in another language.' },
                      { label: 'transcript',  desc: 'Text representation of audiovisual content — subtitles, captions, speech-to-text.' },
                      { label: 'extracted',   desc: 'Auto-generated from the canonical — OCR output, keyframes, metadata enrichment.' },
                    ].map(({ label, desc }) => (
                      <Stack key={label} direction="row" spacing={1} alignItems="flex-start">
                        <Chip label={label} size="small" variant="outlined" sx={{ height: 18, fontSize: '0.65rem', mt: 0.2, flexShrink: 0 }} />
                        <Typography variant="caption" color="text.secondary">{desc}</Typography>
                      </Stack>
                    ))}
                  </Stack>
                </Box>
              </Stack>
            </Collapse>
          </Box>
        </Box>
      )}


      {/* Current Files */}
      {currentFiles.length > 0 && (
        <Box>
          <Typography variant="h6" sx={{ fontWeight: 600, mb: 2 }}>
            Current Files ({currentFiles.length})
          </Typography>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1.5 }}>
            <Chip
              size="small"
              label={hasCanonicalFile ? 'Canonical + Supporting' : 'Multi-component'}
              color="primary"
              variant="filled"
              sx={{ fontSize: '0.7rem', height: 20 }}
            />
            <Typography variant="caption" color="text.secondary">
              {hasCanonicalFile
                ? `${canonicalFileName} is the primary source. Click ⊕ to change or remove it.`
                : 'All files are equal peers. Click ⊕ on any file to define a canonical.'}
            </Typography>
          </Box>
          <Stack spacing={1}>
            {currentFiles.map((file: any, index: number) => {
              const isMarkedForRemoval = filesToRemove.includes(file.id)
              const isSelected = selectedFileIndex === index
              const isSnapshot = activeSnapshotFileId === file.id
              const isCanonical = canonicalFileId === file.id
              const existingCfg = existingConfigs[file.id]
              // Effective role: pending override > canonical state > DB value (with demotion logic)
              const effectiveRole = existingCfg?.role ?? (
                isCanonical
                  ? 'canonical'
                  : file.role === 'canonical' && canonicalFileId !== null && canonicalFileId !== file.id
                    ? 'supporting'
                    : file.role
              )
              const effectiveRelation = existingCfg?.relation !== undefined ? existingCfg.relation : (file.relation ?? '')
              return (
                <Paper
                  key={index}
                  variant="outlined"
                  onClick={() => onFileClick?.(index)}
                  sx={{
                    p: 2,
                    opacity: isMarkedForRemoval ? 0.5 : 1,
                    bgcolor: isSelected ? 'primary.subtle' : isMarkedForRemoval ? 'error.light' : 'background.paper',
                    borderColor: isSelected ? 'primary.main' : isMarkedForRemoval ? 'error.main' : 'divider',
                    transition: 'all 0.2s',
                    cursor: 'pointer',
                    '&:hover': { borderColor: 'primary.light', bgcolor: 'action.hover' },
                  }}
                >
                  <Stack spacing={1}>
                    <Stack direction="row" spacing={1} alignItems="center">
                      <InsertDriveFile sx={{ color: isMarkedForRemoval ? 'error.main' : 'primary.main' }} />
                      <Box sx={{ flex: 1, minWidth: 0 }}>
                        <Typography
                          variant="body2"
                          sx={{ fontWeight: 500 }}
                          color={isMarkedForRemoval ? 'error.main' : 'text.primary'}
                          noWrap
                        >
                          {file.filename || 'Unnamed file'}
                        </Typography>
                        <Stack direction="row" spacing={0.5} alignItems="center">
                          {file.mime_type && (
                            <Typography variant="caption" color="text.disabled">
                              {file.mime_type}
                            </Typography>
                          )}
                          {file.uncommitted_at && (
                            <Chip
                              size="small"
                              label="from previous session"
                              variant="outlined"
                              color="warning"
                              sx={{ height: 16, fontSize: '0.65rem', '& .MuiChip-label': { px: 0.75 } }}
                            />
                          )}
                        </Stack>
                      </Box>
                      <Tooltip
                        title={
                          isCanonical
                            ? 'Remove canonical — all files revert to Multi-component mode'
                            : hasCanonicalFile
                              ? `Set as canonical (replaces ${canonicalFileName})`
                              : 'Set as canonical — switches to Canonical + Supporting mode'
                        }
                        placement="left"
                      >
                        <IconButton
                          size="small"
                          onClick={(e) => { e.stopPropagation(); onFileClick?.(index); handleToggleCanonical(file.id) }}
                          sx={{
                            color: isCanonical ? 'primary.main' : 'action.disabled',
                            '&:hover': { color: 'primary.main' },
                          }}
                        >
                          {isCanonical ? <Hub fontSize="small" /> : <HubOutlined fontSize="small" />}
                        </IconButton>
                      </Tooltip>
                      <Tooltip title={isSnapshot ? 'Remove as snapshot' : 'Set as snapshot'} placement="left">
                        <IconButton
                          size="small"
                          onClick={(e) => { e.stopPropagation(); onFileClick?.(index); handleStarExistingFile(file.id) }}
                          sx={{
                            color: isSnapshot ? 'warning.main' : 'action.disabled',
                            '&:hover': { color: 'warning.main' },
                          }}
                        >
                          {isSnapshot ? <Star fontSize="small" /> : <StarBorder fontSize="small" />}
                        </IconButton>
                      </Tooltip>
                      {(() => {
                        const blockReason = isCanonical && isSnapshot
                          ? 'This file is canonical AND the snapshot — clear both before removing.'
                          : isCanonical
                            ? 'This file is canonical — clear the canonical flag before removing.'
                            : isSnapshot
                              ? 'This file is the snapshot — clear the snapshot star before removing.'
                              : null
                        const tooltipText = blockReason ?? (isMarkedForRemoval ? 'Undo removal' : 'Mark for removal')
                        return (
                          <Tooltip title={tooltipText} placement="left">
                            {/* span wrapper so the Tooltip still works when the button is disabled */}
                            <span>
                              <IconButton
                                size="small"
                                onClick={(e) => { e.stopPropagation(); onFileClick?.(index); handleToggleFileRemoval(file.id) }}
                                disabled={blockReason !== null}
                                sx={{
                                  color: isMarkedForRemoval ? 'error.main' : 'action.disabled',
                                  '&:hover': { color: isMarkedForRemoval ? 'error.dark' : 'error.main' },
                                }}
                              >
                                {isMarkedForRemoval ? <Delete fontSize="small" /> : <DeleteOutline fontSize="small" />}
                              </IconButton>
                            </span>
                          </Tooltip>
                        )
                      })()}
                    </Stack>
                    <Stack direction="row" spacing={1} alignItems="center" sx={{ width: '100%' }} onClick={(e) => e.stopPropagation()}>
                      <FormControl size="small" sx={{ minWidth: 120 }}>
                        <InputLabel sx={{ fontSize: '0.7rem' }}>Role</InputLabel>
                        <Select
                          label="Role"
                          value={effectiveRole ?? ''}
                          onChange={(e) => handleExistingRoleChange(file.id, e.target.value)}
                          sx={{ fontSize: '0.75rem', height: 26 }}
                        >
                          {(['canonical', 'component', 'supporting'] as const).map((r) => {
                            // A file can always keep its current role; constraints apply to switching
                            const isCurrent = effectiveRole === r
                            const isAllowed = isCurrent || availableRoles.includes(r) ||
                              // Switching this file away from component/canonical opens the other option
                              (r === 'canonical' && effectiveRole === 'component' && !hasCanonicalFile) ||
                              (r === 'component' && effectiveRole === 'canonical' && !hasComponentFiles)
                            return (
                              <MenuItem key={r} value={r} disabled={!isAllowed}>
                                {r}
                                {!isAllowed && (
                                  <Typography variant="caption" color="text.disabled" sx={{ ml: 0.5 }}>
                                    {r === 'component' ? '(canonical exists)' : r === 'canonical' ? '(components exist)' : ''}
                                  </Typography>
                                )}
                              </MenuItem>
                            )
                          })}
                        </Select>
                      </FormControl>
                      {effectiveRole !== 'canonical' && (
                        <FormControl size="small" sx={{ minWidth: 130 }}>
                          <InputLabel sx={{ fontSize: '0.7rem' }}>Relation</InputLabel>
                          <Select
                            label="Relation"
                            value={effectiveRelation}
                            onChange={(e) => handleExistingRelationChange(file.id, e.target.value)}
                            sx={{ fontSize: '0.75rem', height: 26 }}
                          >
                            <MenuItem value=""><em>none</em></MenuItem>
                            {RELATION_OPTIONS.map((opt) => (
                              <MenuItem key={opt.value} value={opt.value}>{opt.label}</MenuItem>
                            ))}
                          </Select>
                        </FormControl>
                      )}
                      {aityStatuses !== undefined && (() => {
                        const pollStatus = aityStatuses[file.id]?.status
                        const isExpanded = expandedFileIds.has(file.id)

                        const labelColor = pollStatus === 'ready'    ? 'secondary.main'
                                         : pollStatus === 'failed'   ? 'error.main'
                                         : pollStatus === 'waiting'  ? 'primary.main'
                                         : 'text.disabled'

                        return (
                          <>
                            <Box sx={{ flex: 1 }} />
                            <Tooltip title={isExpanded ? 'Collapse Aity' : 'Show Aity status & extracted metadata'} placement="left">
                              <Stack
                                direction="row"
                                alignItems="center"
                                spacing={0.25}
                                onClick={(e) => {
                                  e.stopPropagation()
                                  setExpandedFileIds((prev) => {
                                    const next = new Set(prev)
                                    next.has(file.id) ? next.delete(file.id) : next.add(file.id)
                                    return next
                                  })
                                }}
                                sx={{
                                  cursor: 'pointer',
                                  px: 0.75,
                                  py: 0.25,
                                  borderRadius: 1,
                                  '&:hover': { bgcolor: 'action.hover' },
                                }}
                              >
                                {pollStatus === 'waiting'
                                  ? <CircularProgress size={11} color="primary" />
                                  : <TydalIsotype size={16} variant="brand" />
                                }
                                <Typography variant="caption" sx={{ fontWeight: 600, textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem', color: labelColor }}>
                                  Aity
                                </Typography>
                                {isExpanded ? <ExpandLess sx={{ fontSize: '1rem', color: 'text.secondary' }} /> : <ExpandMore sx={{ fontSize: '1rem', color: 'text.secondary' }} />}
                              </Stack>
                            </Tooltip>
                          </>
                        )
                      })()}
                    </Stack>

                    {/* ── Expanded: AiTy status + tika metadata ── */}
                    {aityStatuses !== undefined && (
                      <Collapse in={expandedFileIds.has(file.id)} unmountOnExit>
                        <Box
                          sx={{ mt: 1, pt: 1, borderTop: 1, borderColor: 'divider', maxHeight: 360, overflowY: 'auto' }}
                          onClick={(e) => e.stopPropagation()}
                        >
                          <Stack spacing={1.5}>
                            <FileAityCard
                              file={file}
                              pollEntry={aityStatuses[file.id] ?? null}
                              onRetry={() => onRetryAity?.(String(file.id)) ?? Promise.resolve()}
                            />
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
                    )}
                  </Stack>
                </Paper>
              )
            })}
          </Stack>
        </Box>
      )}
    </Stack>
  )
}

export default EditableFiles
