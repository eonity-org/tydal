import { useState, useCallback, useEffect, useRef } from 'react'
import {
  Alert, Box, Button, Card, CardActionArea, CardContent, Checkbox,
  Chip, CircularProgress, Dialog,
  DialogActions, DialogContent, DialogTitle,
  Divider, FormControlLabel, IconButton, Stack, TextField, Tooltip, Typography,
} from '@mui/material'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'
import CheckCircleIcon from '@mui/icons-material/CheckCircle'
import CloseIcon from '@mui/icons-material/Close'
import ErrorOutlineIcon from '@mui/icons-material/ErrorOutline'
import VisibilityIcon from '@mui/icons-material/Visibility'

import collectionService, { Collection, SchemeField } from '../../api/collectionService'
import resourceService, { ResourceData } from '../../api/resourceService'
import workspaceService from '../../api/workspaceService'
import semanticTagService from '../../api/semanticTagService'
import { dispatchAutoApproveWorkspace } from '../../api/aityService'

import { useAityPolling } from '../../hooks/useAityPolling'
import type { FileSuggestion } from '../../hooks/useAiSuggestionsPoller'

import { WizardStepper } from './WizardStepper'
import { AbandonDialog } from './AbandonDialog'
import { ModeUploadStep, WizardMode, WizardFileEntry } from './steps/ModeUploadStep'
import { AiTyStep } from './steps/AiTyStep'
import { CarouselStep } from './steps/CarouselStep'

// ─── wizard types ─────────────────────────────────────────────────────────────

export interface WizardResourceRecord {
  localId: string
  resourceId?: string
  primaryFileId?: string
  uploadedFileIds: string[]  // one per file in allFiles, same order
  placeholderName: string
  uploadStatus: 'pending' | 'creating' | 'uploading' | 'done' | 'error'
  errorMessage?: string
  file: File               // primary file for this record
  allFiles: File[]         // all files (canonical + supporting) for this record
  previewUrl?: string      // for image types — object URL of the primary file
  // Editable (step 4)
  name: string
  description: string
  acceptedTagLabels: string[]
  pendingTags: Array<{ label: string; description?: string | null; type?: string }>
  metadata: Record<string, any>
  // Suggestion dismissal — true once user applies or explicitly dismisses
  nameSuggestionDismissed?: boolean
  descSuggestionDismissed?: boolean
  // True once the user accepts/picks AI suggestions in step 3
  suggestionsAccepted?: boolean
  // Components/canonical: filename of the file whose suggestion is driving name/description
  pickedSuggestionFilename?: string
  // Per-file upload status for multi-file modes (parallel to allFiles); undefined in batch mode
  fileUploadStatuses?: Array<'pending' | 'uploading' | 'done' | 'error'>
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function inferResourceType(mimeType: string): string {
  if (mimeType.startsWith('image/')) return 'image'
  if (mimeType.startsWith('audio/')) return 'audio'
  if (mimeType.startsWith('video/')) return 'video'
  return 'document'
}

function filenameWithoutExtension(filename: string): string {
  return filename.replace(/\.[^.]+$/, '')
}

function defaultWorkspaceName(): string {
  const d = new Date()
  const dd   = String(d.getDate()).padStart(2, '0')
  const mm   = String(d.getMonth() + 1).padStart(2, '0')
  const yyyy = d.getFullYear()
  const hh   = String(d.getHours()).padStart(2, '0')
  const min  = String(d.getMinutes()).padStart(2, '0')
  const ss   = String(d.getSeconds()).padStart(2, '0')
  return `New Upload — ${dd}/${mm}/${yyyy} ${hh}:${min}:${ss}`
}

// ─── props ────────────────────────────────────────────────────────────────────

interface Props {
  open: boolean
  collectionId: number
  onClose: (refreshNeeded?: boolean) => void
  onSaved: (resource: ResourceData) => void
}

// ─── component ────────────────────────────────────────────────────────────────

const STEP_SUBTITLES: Record<number, string> = {
  1: 'Choose upload mode and select files',
  4: 'Review and edit your resources',
}

const MODE_LABELS: Record<WizardMode, string> = {
  batch:      'Batch',
  components: 'Multi-component',
  canonical:  'Canonical',
}

/**
 * Multi-mode resource creation wizard.
 *
 * Step 1 — Choose mode (Batch / Components / Canonical) + select files
 * Step 2 — Upload progress + choose exit path (Supervise / Defer Review / AiTy Review)
 * Step 3 — AiTy suggestions supervision
 * Step 4 — Carousel to inline-edit all created resources
 */
export function ResourceWizard({ open, collectionId, onClose, onSaved }: Props) {
  // ── wizard state ─────────────────────────────────────────────────────────────
  const [step, setStep]           = useState<1 | 2 | 3 | 4>(1)
  const [mode, setMode]           = useState<WizardMode | null>(null)
  const [files, setFiles]         = useState<WizardFileEntry[]>([])
  const [records, setRecords]     = useState<WizardResourceRecord[]>([])
  const [carouselIdx, setCarouselIdx] = useState(0)
  const [collection, setCollection]   = useState<Collection | null>(null)
  const [collectionError, setCollectionError] = useState(false)

  // Step 2 mode: null = not yet chosen
  const [exitPath, setExitPath] = useState<'interactive' | 'auto' | null>(null)
  // Auto mode options
  const [autoApplyNameDesc,    setAutoApplyNameDesc]    = useState(true)
  const [autoApplyTags,        setAutoApplyTags]        = useState(true)
  const [autoSmartClustering,  setAutoSmartClustering]  = useState(true)

  // Workspace name for Auto mode
  const [workspaceName, setWorkspaceName]         = useState(defaultWorkspaceName)
  const [isSavingWorkspace, setIsSavingWorkspace] = useState(false)

  // Abandon dialog
  const [abandonOpen, setAbandonOpen]     = useState(false)
  const [isDeletingAll, setIsDeletingAll] = useState(false)
  const [isPublishing, setIsPublishing]   = useState(false)

  // Finish saving (step 4)
  const [isSaving, setIsSaving]   = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  // Cancellation flag for the background upload loop
  const cancelledRef = useRef(false)

  // ── AI polling ───────────────────────────────────────────────────────────────
  const { statuses: aityStatuses, startPolling, restartPolling, stopAll } = useAityPolling()

  // ── collection fetch ─────────────────────────────────────────────────────────
  useEffect(() => {
    if (!open || !collectionId) return
    collectionService.getCollection(collectionId).then((c) => {
      if (c) { setCollection(c); setCollectionError(false) }
      else setCollectionError(true)
    })
  }, [open, collectionId])

  // Reset state when wizard opens fresh
  useEffect(() => {
    if (open) {
      setStep(1)
      setMode(null)
      setFiles([])
      setRecords([])
      setCarouselIdx(0)
      setWorkspaceName(defaultWorkspaceName())
      setExitPath(null)
      setAutoApplyNameDesc(true)
      setAutoApplyTags(true)
      setAutoSmartClustering(true)
      setAbandonOpen(false)
      setIsDeletingAll(false)
      setIsSaving(false)
      setSaveError(null)
      cancelledRef.current = false
    }
  }, [open])

  // ── helpers ───────────────────────────────────────────────────────────────────
  const updateRecord = useCallback((localId: string, patch: Partial<WizardResourceRecord>) => {
    setRecords((prev) => prev.map((r) => r.localId === localId ? { ...r, ...patch } : r))
  }, [])

  const handleAcceptSuggestions = useCallback((localId: string, selectedFileSuggestion?: FileSuggestion) => {
    const rec = records.find((r) => r.localId === localId)
    if (!rec || !rec.resourceId) return

    const firstFileId = rec.uploadedFileIds[0]
    const pollSugg = firstFileId ? aityStatuses[firstFileId]?.suggestions : undefined
    const source: FileSuggestion | undefined = selectedFileSuggestion ?? (pollSugg
      ? { filename: rec.file.name, suggestedName: pollSugg.suggestedName, suggestedDescription: pollSugg.suggestedDescription, suggestedTags: pollSugg.suggestedTags }
      : undefined)
    if (!source) return

    if (mode !== 'batch') {
      const pickedIdx = rec.allFiles.findIndex((f) => f.name === source.filename)
      const pickedFileId = pickedIdx !== -1 ? rec.uploadedFileIds[pickedIdx] : undefined
      if (pickedFileId) {
        resourceService.setFileSnapshot(rec.resourceId, pickedFileId).catch(() => {})
      }
    }

    setRecords((prev) => prev.map((r) => {
      if (r.localId !== localId || !r.resourceId) return r
      return {
        ...r,
        name: source.suggestedName ?? r.name,
        description: source.suggestedDescription ?? r.description,
        pendingTags: source.suggestedTags,
        acceptedTagLabels: source.suggestedTags.map((t) => t.label),
        suggestionsAccepted: true,
        nameSuggestionDismissed: true,
        descSuggestionDismissed: true,
        pickedSuggestionFilename: source.filename,
      }
    }))
  }, [aityStatuses, mode, records])

  const handleRejectSuggestions = useCallback((localId: string) => {
    setRecords((prev) => prev.map((rec) => {
      if (rec.localId !== localId) return rec
      return {
        ...rec,
        name: rec.placeholderName,
        description: '',
        pendingTags: [],
        acceptedTagLabels: [],
        suggestionsAccepted: false,
        nameSuggestionDismissed: false,
        descSuggestionDismissed: false,
        pickedSuggestionFilename: undefined,
      }
    }))
  }, [])

  const handleAcceptAll = useCallback(() => {
    setRecords((prev) => prev.map((rec) => {
      const fileId = rec.uploadedFileIds[0]
      if (!fileId) return rec
      const poll = aityStatuses[fileId]
      if (poll?.status !== 'ready' || !poll.suggestions) return rec
      const sugg = poll.suggestions
      const source: FileSuggestion = {
        filename: rec.file.name,
        suggestedName: sugg.suggestedName,
        suggestedDescription: sugg.suggestedDescription,
        suggestedTags: sugg.suggestedTags,
      }
      return {
        ...rec,
        name: source.suggestedName ?? rec.name,
        description: source.suggestedDescription ?? rec.description,
        pendingTags: source.suggestedTags,
        acceptedTagLabels: source.suggestedTags.map((t) => t.label),
        suggestionsAccepted: true,
        nameSuggestionDismissed: true,
        descSuggestionDismissed: true,
        pickedSuggestionFilename: source.filename,
      }
    }))
  }, [aityStatuses])

  const handleRejectAll = useCallback(() => {
    setRecords((prev) => prev.map((rec) => ({
      ...rec,
      name: rec.placeholderName,
      description: '',
      pendingTags: [],
      acceptedTagLabels: [],
      suggestionsAccepted: false,
      nameSuggestionDismissed: false,
      descSuggestionDismissed: false,
      pickedSuggestionFilename: undefined,
    })))
  }, [])

  const handleRetryFile = useCallback(async (localId: string, fileId: string) => {
    const rec = records.find((r) => r.localId === localId)
    if (!rec?.resourceId) return
    restartPolling(rec.resourceId, fileId)
    try {
      await resourceService.aityEnrichFile(rec.resourceId, fileId)
    } catch { /* non-fatal */ }
  }, [records, restartPolling])

  const schemeFields: SchemeField[] = collection?.scheme?.fields ?? []
  const acceptedMimeTypes: string[] = collection?.scheme?.accepted_mimetypes ?? []

  // ── step 1 → step 2 transition ───────────────────────────────────────────────
  const handleProceedToStep2 = useCallback(() => {
    cancelledRef.current = false

    let initialRecords: WizardResourceRecord[] = []

    if (mode === 'batch') {
      initialRecords = files.map((fe) => ({
        localId: fe.localId,
        placeholderName: filenameWithoutExtension(fe.file.name),
        uploadStatus: 'pending' as const,
        file: fe.file,
        allFiles: [fe.file],
        uploadedFileIds: [],
        previewUrl: fe.file.type.startsWith('image/') ? URL.createObjectURL(fe.file) : undefined,
        name: filenameWithoutExtension(fe.file.name),
        description: '',
        acceptedTagLabels: [],
        pendingTags: [],
        metadata: {},
      }))
    } else if (mode === 'components') {
      const primary = files[0]
      initialRecords = [{
        localId: primary.localId,
        placeholderName: filenameWithoutExtension(primary.file.name),
        uploadStatus: 'pending' as const,
        file: primary.file,
        allFiles: files.map((f) => f.file),
        uploadedFileIds: [],
        previewUrl: primary.file.type.startsWith('image/') ? URL.createObjectURL(primary.file) : undefined,
        name: filenameWithoutExtension(primary.file.name),
        description: '',
        acceptedTagLabels: [],
        pendingTags: [],
        metadata: {},
        fileUploadStatuses: files.map(() => 'pending' as const),
      }]
    } else {
      const primaryFe  = files.find((f) => f.role === 'canonical')!
      const supporting = files.filter((f) => f.role !== 'canonical')
      initialRecords = [{
        localId: primaryFe.localId,
        placeholderName: filenameWithoutExtension(primaryFe.file.name),
        uploadStatus: 'pending' as const,
        file: primaryFe.file,
        allFiles: [primaryFe.file, ...supporting.map((f) => f.file)],
        uploadedFileIds: [],
        previewUrl: primaryFe.file.type.startsWith('image/') ? URL.createObjectURL(primaryFe.file) : undefined,
        name: filenameWithoutExtension(primaryFe.file.name),
        description: '',
        acceptedTagLabels: [],
        pendingTags: [],
        metadata: {},
        fileUploadStatuses: [primaryFe, ...supporting].map(() => 'pending' as const),
      }]
    }

    setRecords(initialRecords)
    setExitPath(null)
    setStep(2)

    // ── background upload loop ────────────────────────────────────────────────
    ;(async () => {
      for (const rec of initialRecords) {
        if (cancelledRef.current) break

        updateRecord(rec.localId, { uploadStatus: 'creating' })

        let resource: ResourceData | null = null
        try {
          resource = await resourceService.createResource({
            name: rec.placeholderName,
            type: inferResourceType(rec.file.type),
            collection_id: collectionId,
            state: 'draft',
          })
        } catch (err) {
          updateRecord(rec.localId, {
            uploadStatus: 'error',
            errorMessage: err instanceof Error ? err.message : 'Create failed',
          })
          continue
        }

        if (!resource || cancelledRef.current) break

        updateRecord(rec.localId, { resourceId: resource.id, uploadStatus: 'uploading' })

        let uploadedFileIds: string[] = []
        try {
          if (mode === 'batch') {
            const uploaded = await resourceService.uploadFile(resource.id, rec.file, 'canonical')
            if (cancelledRef.current) break
            uploadedFileIds = uploaded?.id ? [uploaded.id] : []
            updateRecord(rec.localId, {
              primaryFileId: uploaded?.id,
              uploadedFileIds,
              uploadStatus: 'done',
            })
          } else if (mode === 'components') {
            let primaryFileId: string | undefined
            const fileStatuses: Array<'pending' | 'uploading' | 'done' | 'error'> = files.map(() => 'pending' as const)
            for (let fi = 0; fi < files.length; fi++) {
              if (cancelledRef.current) break
              fileStatuses[fi] = 'uploading'
              updateRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
              const uploaded = await resourceService.uploadFile(resource.id, files[fi].file, 'component')
              fileStatuses[fi] = uploaded?.id ? 'done' : 'error'
              if (uploaded?.id) {
                uploadedFileIds.push(uploaded.id)
                if (!primaryFileId) primaryFileId = uploaded.id
              }
              updateRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
            }
            if (cancelledRef.current) break
            updateRecord(rec.localId, { primaryFileId, uploadedFileIds, uploadStatus: 'done' })
          } else {
            const primaryFe  = files.find((f) => f.role === 'canonical')!
            const supporting = files.filter((f) => f.role !== 'canonical')
            const fileStatuses: Array<'pending' | 'uploading' | 'done' | 'error'> = [primaryFe, ...supporting].map(() => 'pending' as const)

            fileStatuses[0] = 'uploading'
            updateRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
            const uploaded = await resourceService.uploadFile(resource.id, primaryFe.file, 'canonical')
            fileStatuses[0] = uploaded?.id ? 'done' : 'error'
            if (uploaded?.id) uploadedFileIds.push(uploaded.id)
            updateRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })

            if (cancelledRef.current) break
            for (let si = 0; si < supporting.length; si++) {
              if (cancelledRef.current) break
              fileStatuses[si + 1] = 'uploading'
              updateRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
              const sfUploaded = await resourceService.uploadFile(resource.id, supporting[si].file, 'supporting')
              fileStatuses[si + 1] = sfUploaded?.id ? 'done' : 'error'
              if (sfUploaded?.id) uploadedFileIds.push(sfUploaded.id)
              updateRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
            }
            if (cancelledRef.current) break
            updateRecord(rec.localId, {
              primaryFileId: uploadedFileIds[0],
              uploadedFileIds,
              uploadStatus: 'done',
            })
          }
        } catch (err) {
          updateRecord(rec.localId, {
            uploadStatus: 'error',
            errorMessage: err instanceof Error ? err.message : 'Upload failed',
          })
          continue
        }

        if (cancelledRef.current) break
        if (uploadedFileIds.length > 0) startPolling(resource.id, uploadedFileIds)
      }
    })()
  }, [mode, files, collectionId, updateRecord, startPolling])

  // ── Shared: persist accepted suggestions + promote draft → live ──────────────
  const persistSuggestions = useCallback(async () => {
    for (const rec of records) {
      if (!rec.resourceId) continue
      try {
        await resourceService.updateResource(rec.resourceId, {
          ...(rec.suggestionsAccepted ? {
            name: rec.name || rec.placeholderName,
            description: rec.description || null,
            metadata: rec.metadata,
          } : {}),
          state: 'live',
        })
        if (rec.pendingTags.length > 0) {
          const tagIds: number[] = []
          for (const tag of rec.pendingTags) {
            try {
              const created = await semanticTagService.create({
                label: tag.label,
                description: tag.description ?? '',
                entity_type: tag.type ?? 'tag',
                vocabulary: 'ai_generated',
                reviewer:   'user',
              })
              if (created?.id) tagIds.push(created.id)
            } catch { /* non-fatal */ }
          }
          if (tagIds.length > 0) await semanticTagService.syncResource(rec.resourceId, tagIds)
        }
      } catch { /* record save failure is non-fatal */ }
    }
  }, [records])

  // ── "Auto mode": create aity_review workspace + dispatch job with chosen options ─
  const handleAutoMode = useCallback(async () => {
    setIsSavingWorkspace(true)
    try {
      await persistSuggestions()
      const workspace = await workspaceService.createWorkspace({
        name: workspaceName.trim(),
        purpose: 'aity_review',
      })
      if (workspace) {
        for (const rec of records) {
          if (rec.resourceId) await workspaceService.addResource(workspace.id, rec.resourceId)
        }
        await dispatchAutoApproveWorkspace(workspace.id, {
          apply_name:        autoApplyNameDesc,
          apply_description: autoApplyNameDesc,
          apply_tags:        autoApplyTags,
          dedup:             autoApplyTags && autoSmartClustering,
        })
      }
    } catch { /* non-fatal */ }
    finally { setIsSavingWorkspace(false) }
    stopAll()
    onClose(true)
  }, [workspaceName, autoApplyNameDesc, autoApplyTags, autoSmartClustering, records, persistSuggestions, stopAll, onClose])

  // Derived upload readiness
  const uploadsComplete = records.length > 0 &&
    records.every((r) => r.uploadStatus === 'done' || r.uploadStatus === 'error')
  const uploadingCount = records.filter((r) =>
    r.uploadStatus === 'pending' || r.uploadStatus === 'creating' || r.uploadStatus === 'uploading'
  ).length
  const doneCount  = records.filter((r) => r.uploadStatus === 'done').length
  const errorCount = records.filter((r) => r.uploadStatus === 'error').length

  // ── Step 4: finish ────────────────────────────────────────────────────────────
  const handleFinish = useCallback(async () => {
    setIsSaving(true)
    setSaveError(null)
    let firstSaved: ResourceData | null = null

    try {
      for (const rec of records) {
        if (!rec.resourceId) continue

        const updated = await resourceService.updateResource(rec.resourceId, {
          name: rec.name || rec.placeholderName,
          description: rec.description || null,
          metadata: rec.metadata,
          state: 'live',
        })
        if (!firstSaved) firstSaved = updated

        if (rec.pendingTags.length > 0) {
          const tagIds: number[] = []
          for (const tag of rec.pendingTags) {
            try {
              const created = await semanticTagService.create({
                label: tag.label,
                description: tag.description ?? '',
                entity_type: tag.type ?? 'tag',
                vocabulary: 'ai_generated',
                reviewer:   'user',
              })
              if (created?.id) tagIds.push(created.id)
            } catch { /* non-fatal */ }
          }
          if (tagIds.length > 0) await semanticTagService.syncResource(rec.resourceId, tagIds)
        }
      }

      stopAll()
      if (firstSaved) onSaved(firstSaved)
      else onClose()
    } catch (err) {
      setSaveError(err instanceof Error ? err.message : 'Save failed')
    } finally {
      setIsSaving(false)
    }
  }, [records, stopAll, onSaved, onClose])

  // ── abandon / close handling ──────────────────────────────────────────────────
  const handleClose = useCallback(() => {
    const hasCreatedResources = records.some((r) => r.resourceId)
    const hasActiveUploads    = records.some((r) =>
      r.uploadStatus === 'pending' || r.uploadStatus === 'creating' || r.uploadStatus === 'uploading'
    )
    if (!hasCreatedResources && !hasActiveUploads) {
      cancelledRef.current = true
      stopAll()
      onClose()
    } else {
      setAbandonOpen(true)
    }
  }, [records, stopAll, onClose])

  const handleKeep = useCallback(async () => {
    setIsPublishing(true)
    try {
      await Promise.all(
        records
          .filter((r) => r.resourceId)
          .map((r) => resourceService.updateResource(r.resourceId!, { state: 'live' }).catch(() => {}))
      )
    } finally {
      setIsPublishing(false)
    }
    setAbandonOpen(false)
    stopAll()
    onClose(true)
  }, [records, stopAll, onClose])

  const handleDeleteAll = useCallback(async () => {
    cancelledRef.current = true
    setIsDeletingAll(true)
    try {
      await Promise.all(
        records
          .filter((r) => r.resourceId)
          .map((r) => resourceService.deleteResource(r.resourceId!).catch(() => {}))
      )
    } finally {
      setIsDeletingAll(false)
    }
    setAbandonOpen(false)
    stopAll()
    onClose(true)
  }, [records, stopAll, onClose])

  // ── navigation guards ────────────────────────────────────────────────────────
  const isStep1Valid =
    mode !== null &&
    files.length >= 1 &&
    (mode !== 'canonical' || files.some((f) => f.role === 'canonical'))

  // ── step 2 subtitle ──────────────────────────────────────────────────────────
  const step2Subtitle = (() => {
    if (uploadingCount > 0) return `Uploading… (${doneCount + errorCount}/${records.length} done)`
    if (uploadsComplete && errorCount > 0) return `Upload complete — ${errorCount} error(s)`
    if (uploadsComplete) return 'Upload complete — choose how to proceed'
    return 'Processing…'
  })()

  // ── render ────────────────────────────────────────────────────────────────────
  return (
    <>
      <Dialog
        open={open}
        onClose={handleClose}
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
            display: 'flex',
            flexDirection: 'column',
          },
        }}
      >
        {/* ── Header ──────────────────────────────────────────────────────────── */}
        <DialogTitle
          sx={{
            pb: 2,
            bgcolor: 'primary.50',
            borderBottom: '1px solid',
            borderColor: 'primary.200',
            flexShrink: 0,
          }}
        >
          <Stack
            direction="row"
            alignItems="center"
            sx={{ display: 'grid', gridTemplateColumns: '1fr auto 1fr' }}
          >
            {/* Left — title + subtitle */}
            <Stack spacing={0.5}>
              <Typography variant="h6" component="div" sx={{ fontWeight: 600, color: 'primary.dark' }}>
                New Resources
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {step === 2
                  ? step2Subtitle
                  : step === 3
                    ? (() => {
                        const anyPolling = records.some((r) =>
                          r.uploadedFileIds.some((fid) => aityStatuses[fid]?.status === 'waiting')
                        )
                        return anyPolling ? 'Aity is analyzing…' : 'Review AI suggestions'
                      })()
                    : STEP_SUBTITLES[step]}
                {collection ? ` — ${collection.name}` : ''}
              </Typography>
            </Stack>

            {/* Center — mode chip */}
            <Stack alignItems="center" justifyContent="center">
              {mode && (
                <Chip
                  label={MODE_LABELS[mode]}
                  size="small"
                  sx={{
                    height: '1.75rem',
                    fontSize: '0.75rem',
                    fontWeight: 600,
                    bgcolor: 'primary.100',
                    color: 'primary.dark',
                    border: '1px solid',
                    borderColor: 'primary.200',
                    px: 0.5,
                  }}
                />
              )}
            </Stack>

            {/* Right — stepper + close */}
            <Stack direction="row" spacing={1} alignItems="center" justifyContent="flex-end">
              <WizardStepper currentStep={step} />
              <Divider orientation="vertical" flexItem sx={{ mx: 0.5 }} />
              <IconButton
                onClick={handleClose}
                size="small"
                sx={{ color: 'text.secondary', '&:hover': { bgcolor: 'action.hover' } }}
              >
                <CloseIcon />
              </IconButton>
            </Stack>
          </Stack>
        </DialogTitle>

        {/* ── Content ─────────────────────────────────────────────────────────── */}
        <DialogContent
          sx={{
            p: 0,
            flex: 1,
            overflow: 'hidden',
            display: 'flex',
            flexDirection: 'column',
          }}
        >
          {collectionError && (
            <Alert severity="error" sx={{ m: 2 }}>
              Could not load collection. File type filtering is disabled.
            </Alert>
          )}

          {step === 1 && (
            <ModeUploadStep
              mode={mode}
              files={files}
              collectionMimeTypes={acceptedMimeTypes}
              onModeChange={setMode}
              onFilesChange={setFiles}
            />
          )}

          {step === 2 && (
            <Step2UploadChoice
              records={records}
              uploadsComplete={uploadsComplete}
              exitPath={exitPath}
              autoApplyNameDesc={autoApplyNameDesc}
              autoApplyTags={autoApplyTags}
              autoSmartClustering={autoSmartClustering}
              workspaceName={workspaceName}
              isSavingWorkspace={isSavingWorkspace}
              onSelectExitPath={setExitPath}
              onAutoApplyNameDescChange={setAutoApplyNameDesc}
              onAutoApplyTagsChange={setAutoApplyTags}
              onAutoSmartClusteringChange={setAutoSmartClustering}
              onWorkspaceNameChange={setWorkspaceName}
            />
          )}

          {step === 3 && mode && (
            <AiTyStep
              mode={mode}
              records={records}
              aityStatuses={aityStatuses}
              onAcceptSuggestions={handleAcceptSuggestions}
              onRejectSuggestions={handleRejectSuggestions}
              onAcceptAll={handleAcceptAll}
              onRejectAll={handleRejectAll}
              onRetryFile={handleRetryFile}
            />
          )}

          {step === 4 && (
            <CarouselStep
              records={records}
              aityStatuses={aityStatuses}
              schemeFields={schemeFields}
              onRecordChange={updateRecord}
              currentIndex={carouselIdx}
              onNavigate={setCarouselIdx}
            />
          )}
        </DialogContent>

        {/* ── Footer actions ───────────────────────────────────────────────────── */}
        <DialogActions
          sx={{
            borderTop: '1px solid',
            borderColor: 'divider',
            px: 3,
            py: 2,
            flexShrink: 0,
            justifyContent: 'space-between',
          }}
        >
          {step === 1 && (
            <>
              <Button onClick={handleClose} variant="outlined">
                Cancel
              </Button>
              <Tooltip title={!isStep1Valid ? 'Select a mode and at least one file' : ''} disableHoverListener={isStep1Valid}>
                <span>
                  <Button
                    variant="contained"
                    onClick={handleProceedToStep2}
                    disabled={!isStep1Valid}
                  >
                    Next →
                  </Button>
                </span>
              </Tooltip>
            </>
          )}

          {step === 2 && (
            <>
              <Button onClick={() => setStep(1)} variant="outlined" disabled={isSavingWorkspace}>
                ← Back
              </Button>
              {exitPath === 'auto' ? (
                <Button
                  variant="contained"
                  onClick={handleAutoMode}
                  disabled={!uploadsComplete || isSavingWorkspace}
                  startIcon={isSavingWorkspace ? <CircularProgress size={16} color="inherit" /> : <AutoAwesomeIcon />}
                >
                  {isSavingWorkspace ? 'Saving…' : 'Save & exit'}
                </Button>
              ) : (
                <Button
                  variant="contained"
                  onClick={() => setStep(3)}
                  disabled={exitPath !== 'interactive' || !uploadsComplete}
                >
                  Interactive →
                </Button>
              )}
            </>
          )}

          {step === 3 && (
            <>
              <Button onClick={() => setStep(2)} variant="outlined">
                ← Back
              </Button>
              <Button
                variant="contained"
                onClick={() => setStep(4)}
              >
                Proceed to Review →
              </Button>
            </>
          )}

          {step === 4 && (
            <>
              <Button onClick={() => setStep(3)} variant="outlined" disabled={isSaving}>
                ← Back
              </Button>
              <Stack direction="row" spacing={1} alignItems="center">
                {saveError && (
                  <Typography variant="caption" color="error">
                    {saveError}
                  </Typography>
                )}
                <Button
                  variant="contained"
                  onClick={handleFinish}
                  disabled={isSaving}
                  startIcon={isSaving ? <CircularProgress size={16} color="inherit" /> : undefined}
                >
                  {isSaving ? 'Saving…' : 'Finish'}
                </Button>
              </Stack>
            </>
          )}
        </DialogActions>
      </Dialog>

      {/* ── Abandon dialog ──────────────────────────────────────────────────── */}
      <AbandonDialog
        open={abandonOpen}
        resourceCount={records.filter((r) => r.resourceId || r.uploadStatus === 'creating' || r.uploadStatus === 'uploading').length}
        isDeleting={isDeletingAll}
        isPublishing={isPublishing}
        onKeep={handleKeep}
        onDeleteAll={handleDeleteAll}
        onCancel={() => setAbandonOpen(false)}
      />
    </>
  )
}

// ─── Step 2: upload progress + 2-mode choice ──────────────────────────────────

interface Step2Props {
  records: WizardResourceRecord[]
  uploadsComplete: boolean
  exitPath: 'interactive' | 'auto' | null
  autoApplyNameDesc: boolean
  autoApplyTags: boolean
  autoSmartClustering: boolean
  workspaceName: string
  isSavingWorkspace: boolean
  onSelectExitPath: (p: 'interactive' | 'auto') => void
  onAutoApplyNameDescChange: (v: boolean) => void
  onAutoApplyTagsChange: (v: boolean) => void
  onAutoSmartClusteringChange: (v: boolean) => void
  onWorkspaceNameChange: (v: string) => void
}

function Step2UploadChoice({
  records,
  uploadsComplete,
  exitPath,
  autoApplyNameDesc,
  autoApplyTags,
  autoSmartClustering,
  workspaceName,
  isSavingWorkspace,
  onSelectExitPath,
  onAutoApplyNameDescChange,
  onAutoApplyTagsChange,
  onAutoSmartClusteringChange,
  onWorkspaceNameChange,
}: Step2Props) {
  const doneCount  = records.filter((r) => r.uploadStatus === 'done').length
  const errorCount = records.filter((r) => r.uploadStatus === 'error').length
  const total      = records.length

  return (
    <Box sx={{ flex: 1, overflow: 'auto', p: 3 }}>
      {/* Upload progress list */}
      <Stack spacing={0.75} sx={{ mb: 3 }}>
        {records.map((rec) => {
          const showSubFiles = rec.fileUploadStatuses !== undefined && rec.allFiles.length > 1
          return (
            <Box key={rec.localId} sx={{ bgcolor: 'grey.50', borderRadius: 1, border: '1px solid', borderColor: 'divider', overflow: 'hidden' }}>
              {/* Resource / summary row */}
              <Stack direction="row" spacing={1.5} alignItems="center" sx={{ px: 1.5, py: 0.75 }}>
                {rec.uploadStatus === 'done' && (
                  <CheckCircleIcon sx={{ fontSize: 16, color: 'success.main', flexShrink: 0 }} />
                )}
                {rec.uploadStatus === 'error' && (
                  <ErrorOutlineIcon sx={{ fontSize: 16, color: 'error.main', flexShrink: 0 }} />
                )}
                {(rec.uploadStatus === 'pending' || rec.uploadStatus === 'creating' || rec.uploadStatus === 'uploading') && (
                  <CircularProgress size={14} sx={{ flexShrink: 0 }} />
                )}
                <Typography variant="body2" sx={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                  {rec.placeholderName}
                </Typography>
                <Typography variant="caption" color="text.secondary" sx={{ flexShrink: 0 }}>
                  {rec.uploadStatus === 'done'        ? 'uploaded'
                   : rec.uploadStatus === 'error'     ? rec.errorMessage ?? 'failed'
                   : rec.uploadStatus === 'uploading' ? 'uploading…'
                   : rec.uploadStatus === 'creating'  ? 'creating…'
                   : 'pending'}
                </Typography>
              </Stack>

              {/* Per-file rows for multi-file modes */}
              {showSubFiles && (
                <Stack sx={{ borderTop: '1px solid', borderColor: 'divider' }}>
                  {rec.allFiles.map((f, i) => {
                    const fst = rec.fileUploadStatuses![i] ?? 'pending'
                    return (
                      <Stack key={i} direction="row" spacing={1.5} alignItems="center"
                        sx={{ px: 2.5, py: 0.5, bgcolor: 'background.paper', '&:not(:last-child)': { borderBottom: '1px solid', borderColor: 'divider' } }}
                      >
                        {fst === 'done'  && <CheckCircleIcon sx={{ fontSize: 12, color: 'success.main', flexShrink: 0 }} />}
                        {fst === 'error' && <ErrorOutlineIcon sx={{ fontSize: 12, color: 'error.main', flexShrink: 0 }} />}
                        {(fst === 'uploading' || fst === 'pending') && <CircularProgress size={10} sx={{ flexShrink: 0 }} />}
                        <Typography variant="caption" sx={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                          {f.name}
                        </Typography>
                        <Typography variant="caption" color="text.disabled" sx={{ flexShrink: 0 }}>
                          {fst === 'done'      ? 'uploaded'
                           : fst === 'error'   ? 'failed'
                           : fst === 'uploading' ? 'uploading…'
                           : 'pending'}
                        </Typography>
                      </Stack>
                    )
                  })}
                </Stack>
              )}
            </Box>
          )
        })}
      </Stack>

      {/* Summary badge */}
      {total > 0 && (
        <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 3 }}>
          {uploadsComplete
            ? <Chip size="small" color={errorCount > 0 ? 'warning' : 'success'}
                label={errorCount > 0 ? `${doneCount} uploaded, ${errorCount} failed` : `${doneCount} resource${doneCount !== 1 ? 's' : ''} uploaded`} />
            : <Chip size="small" icon={<CircularProgress size={10} color="inherit" />}
                label={`Uploading… ${doneCount + errorCount} / ${total}`} />
          }
        </Stack>
      )}

      <Divider sx={{ mb: 3 }} />

      {/* Mode cards */}
      <Typography variant="subtitle2" color="text.secondary" sx={{ mb: 2 }}>
        Choose how to proceed:
      </Typography>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>

        {/* Card 1: Interactive */}
        <ChoiceCard
          selected={exitPath === 'interactive'}
          disabled={!uploadsComplete}
          icon={<VisibilityIcon />}
          title="Interactive"
          description="Stay in the wizard to review AI suggestions step by step, then edit and save each resource."
          onClick={() => onSelectExitPath('interactive')}
        />

        {/* Card 2: Auto */}
        <ChoiceCard
          selected={exitPath === 'auto'}
          disabled={!uploadsComplete}
          icon={<AutoAwesomeIcon />}
          title="Auto"
          description="Let AiTy process the batch in the background and apply the selected suggestions automatically."
          accentColor="secondary"
          onClick={() => onSelectExitPath('auto')}
        >
          {exitPath === 'auto' && (
            <Stack spacing={1} sx={{ mt: 1.5 }}>
              {/* What to auto-apply */}
              <FormControlLabel
                control={
                  <Checkbox size="small" checked={autoApplyNameDesc}
                    onChange={(e) => onAutoApplyNameDescChange(e.target.checked)}
                    disabled={isSavingWorkspace} />
                }
                label={<Typography variant="caption">Accept name &amp; description</Typography>}
                sx={{ m: 0 }}
              />
              <FormControlLabel
                control={
                  <Checkbox size="small" checked={autoApplyTags}
                    onChange={(e) => onAutoApplyTagsChange(e.target.checked)}
                    disabled={isSavingWorkspace} />
                }
                label={<Typography variant="caption">Accept tags per resource</Typography>}
                sx={{ m: 0 }}
              />
              <FormControlLabel
                control={
                  <Checkbox size="small" checked={autoApplyTags && autoSmartClustering}
                    onChange={(e) => onAutoSmartClusteringChange(e.target.checked)}
                    disabled={isSavingWorkspace || !autoApplyTags} />
                }
                label={<Typography variant="caption" color={autoApplyTags ? 'text.primary' : 'text.disabled'}>Smart tag clustering</Typography>}
                sx={{ m: 0, pl: 2 }}
              />

              <Divider sx={{ my: 0.5 }} />

              {/* Optional workspace name */}
              <TextField
                size="small"
                label="Workspace name (optional)"
                value={workspaceName}
                onChange={(e) => onWorkspaceNameChange(e.target.value)}
                fullWidth
                disabled={isSavingWorkspace}
              />
            </Stack>
          )}
        </ChoiceCard>

      </Stack>
    </Box>
  )
}

// ─── Reusable choice card ─────────────────────────────────────────────────────

interface ChoiceCardProps {
  selected: boolean
  disabled: boolean
  icon: React.ReactNode
  title: string
  description: string
  accentColor?: 'primary' | 'secondary'
  onClick: () => void
  children?: React.ReactNode
}

function ChoiceCard({
  selected, disabled, icon, title, description, accentColor = 'primary', onClick, children,
}: ChoiceCardProps) {
  return (
    <Card
      variant="outlined"
      sx={{
        flex: 1,
        opacity: disabled ? 0.45 : 1,
        transition: 'all 0.15s',
        borderColor: selected ? `${accentColor}.main` : 'divider',
        borderWidth: selected ? 2 : 1,
        bgcolor: selected ? `${accentColor}.50` : 'background.paper',
        boxShadow: selected ? 2 : 0,
        display: 'flex',
        flexDirection: 'column',
      }}
    >
      <CardActionArea onClick={onClick} disabled={disabled} sx={{ flex: 'none' }}>
        <CardContent>
          <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.5 }}>
            <Box sx={{ color: selected ? `${accentColor}.main` : 'text.secondary', display: 'flex' }}>
              {icon}
            </Box>
            <Typography variant="subtitle2" sx={{ fontWeight: 600, color: selected ? `${accentColor}.dark` : 'text.primary' }}>
              {title}
            </Typography>
            {selected && (
              <CheckCircleIcon sx={{ fontSize: 16, color: `${accentColor}.main`, ml: 'auto' }} />
            )}
          </Stack>
          <Typography variant="caption" color="text.secondary">
            {description}
          </Typography>
        </CardContent>
      </CardActionArea>
      {/* Expanded controls rendered below the clickable area so they don't re-trigger selection */}
      {children && (
        <Box sx={{ px: 2, pb: 2, pt: 0 }}>
          {children}
        </Box>
      )}
    </Card>
  )
}
