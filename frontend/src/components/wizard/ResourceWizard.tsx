import { useState, useCallback, useEffect, useMemo, useRef } from 'react'
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
import workspaceService, { Workspace } from '../../api/workspaceService'
import semanticTagService from '../../api/semanticTagService'
import { dispatchAutoApproveWorkspace } from '../../api/aityService'
import { getApiError } from '../../utils/apiError'

import { useAityPolling } from '../../hooks/useAityPolling'
import type { FileSuggestion } from '../../hooks/useAiSuggestionsPoller'

import { WizardStepper } from './WizardStepper'
import { AbandonDialog, AbandonFailure } from './AbandonDialog'
import { ModeUploadStep, WizardMode, WizardFileEntry } from './steps/ModeUploadStep'
import { AiTyStep } from './steps/AiTyStep'
import { CarouselStep } from './steps/CarouselStep'
import { hasFieldValue, requiredFieldsMetadata, requiredMetadataFields } from './schemeFields'

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

/** The server's message plus any field errors it names (e.g. which required field is empty). */
function describeError(err: unknown): string {
  const { message, fieldErrors } = getApiError(err)
  const details = fieldErrors ? Object.values(fieldErrors).filter((m) => m !== message) : []
  return details.length > 0 ? `${message} ${details.join(' ')}` : message
}

/**
 * A resource the wizard created and still stands behind. A failed row may
 * still carry the id of an empty draft that could not be removed: that one is
 * never published, only deleted.
 */
function isCreated(rec: WizardResourceRecord): rec is WizardResourceRecord & { resourceId: string } {
  return !!rec.resourceId && rec.uploadStatus !== 'error'
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
  const [autoError, setAutoError]                 = useState<string | null>(null)
  // The batch opened by a previous, partly failed Auto attempt — reused on
  // retry so a second click doesn't leave an empty batch behind.
  const autoBatchRef = useRef<Workspace | null>(null)

  // The step-1 selection (mode, files, required values) the current records
  // were uploaded for: Back → Next with the same selection reuses them (#21).
  const uploadedSelectionRef = useRef<string | null>(null)
  const [isDiscarding, setIsDiscarding] = useState(false)
  const [discardError, setDiscardError] = useState<string | null>(null)

  // Step 1: batch-wide values for the scheme's required fields
  const [requiredValues, setRequiredValues] = useState<Record<string, any>>({})

  // Abandon dialog
  const [abandonOpen, setAbandonOpen]     = useState(false)
  const [isDeletingAll, setIsDeletingAll] = useState(false)
  const [isPublishing, setIsPublishing]   = useState(false)
  const [abandonFailure, setAbandonFailure] = useState<AbandonFailure | null>(null)
  // Keep / Delete all wait for the upload in flight before acting on it
  const [isFinishingUploads, setIsFinishingUploads] = useState(false)

  // Finish saving (step 4)
  const [isSaving, setIsSaving]   = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)

  // Cancellation flag for the background upload loop: the wizard is being
  // discarded, so what it creates is deleted.
  const cancelledRef = useRef(false)
  // Stop flag: the loop finishes the resource in flight but starts no new one
  // (Keep while uploading).
  const stopRef = useRef(false)
  // The running (or last) upload loop. It resolves with every record patch it
  // applied, so Keep / Delete all can act on what exists once it settles
  // without waiting for React to re-render.
  const uploadRunRef = useRef<Promise<Map<string, Partial<WizardResourceRecord>>> | null>(null)

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
      setRequiredValues({})
      setAutoError(null)
      autoBatchRef.current = null
      uploadedSelectionRef.current = null
      setIsDiscarding(false)
      setDiscardError(null)
      setExitPath(null)
      setAutoApplyNameDesc(true)
      setAutoApplyTags(true)
      setAutoSmartClustering(true)
      setAbandonOpen(false)
      setAbandonFailure(null)
      setIsDeletingAll(false)
      setIsFinishingUploads(false)
      setIsSaving(false)
      setSaveError(null)
      cancelledRef.current = false
      stopRef.current = false
      uploadRunRef.current = null
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
        // Non-fatal: this only picks which file previews the resource. The
        // resource already has a preview from its first file, and the snapshot
        // can be changed later in the resource editor; nothing is lost.
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
    } catch {
      // Non-fatal: the restarted poller reads the file's real stage, so a
      // retry the server refused shows up as "failed" again on the card.
    }
  }, [records, restartPolling])

  const schemeFields: SchemeField[] = useMemo(() => collection?.scheme?.fields ?? [], [collection])
  const acceptedMimeTypes: string[] = collection?.scheme?.accepted_mimetypes ?? []
  const requiredFields = useMemo(() => requiredMetadataFields(schemeFields), [schemeFields])
  const missingRequired = requiredFields.filter((f) => !hasFieldValue(f, requiredValues[f.name]))

  const handleRequiredValueChange = useCallback((name: string, value: any) => {
    setRequiredValues((prev) => ({ ...prev, [name]: value }))
  }, [])

  // ── step 2: create each resource and upload its files ─────────────────────────
  // A resource is all or nothing: when a file fails after its resource was
  // created, the resource is deleted again so no empty draft is left behind
  // (#21). The row shows the error; Back → Next retries just the failed rows.
  const runUploads = useCallback(async (toUpload: WizardResourceRecord[], uploadMode: WizardMode) => {
    const applied = new Map<string, Partial<WizardResourceRecord>>()
    const patchRecord = (localId: string, patch: Partial<WizardResourceRecord>) => {
      applied.set(localId, { ...applied.get(localId), ...patch })
      updateRecord(localId, patch)
    }
    for (const rec of toUpload) {
      if (cancelledRef.current || stopRef.current) break

      patchRecord(rec.localId, { uploadStatus: 'creating' })

      let resource: ResourceData | null = null
      try {
        resource = await resourceService.createResource({
          name: rec.placeholderName,
          type: inferResourceType(rec.file.type),
          collection_id: collectionId,
          state: 'draft',
          ...(Object.keys(rec.metadata).length > 0 ? { metadata: rec.metadata } : {}),
        })
      } catch (err) {
        patchRecord(rec.localId, {
          uploadStatus: 'error',
          errorMessage: describeError(err),
        })
        continue
      }

      if (!resource) break
      if (cancelledRef.current) {
        // Created while the wizard was being discarded (Delete all): remove it
        // too. Nobody is left to tell if this fails, and the hourly draft
        // purge clears it anyway.
        resourceService.deleteResource(resource.id).catch(() => {})
        break
      }

      patchRecord(rec.localId, { resourceId: resource.id, uploadStatus: 'uploading' })

      // Batch: one canonical file. Components: every file a component.
      // Canonical: the first file canonical, the rest supporting.
      const roles = rec.allFiles.map((_, i) =>
        uploadMode === 'components' ? 'component' : i === 0 ? 'canonical' : 'supporting')
      const fileStatuses = rec.allFiles.map(() => 'pending' as 'pending' | 'uploading' | 'done' | 'error')
      const trackFiles = rec.fileUploadStatuses !== undefined
      const uploadedFileIds: string[] = []
      try {
        for (let fi = 0; fi < rec.allFiles.length; fi++) {
          if (cancelledRef.current) break
          fileStatuses[fi] = 'uploading'
          if (trackFiles) patchRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
          try {
            const uploaded = await resourceService.uploadFile(resource.id, rec.allFiles[fi], roles[fi])
            fileStatuses[fi] = uploaded?.id ? 'done' : 'error'
            if (uploaded?.id) uploadedFileIds.push(uploaded.id)
          } catch (err) {
            fileStatuses[fi] = 'error'
            throw err
          } finally {
            if (trackFiles) patchRecord(rec.localId, { fileUploadStatuses: [...fileStatuses] })
          }
        }
      } catch (err) {
        let errorMessage = describeError(err)
        let keptId: string | undefined
        try {
          await resourceService.deleteResource(resource.id)
        } catch (deleteErr) {
          keptId = resource.id
          errorMessage += ` The empty resource could not be removed either (${describeError(deleteErr)}); it stays a hidden draft and is cleared automatically.`
        }
        patchRecord(rec.localId, {
          resourceId: keptId,
          primaryFileId: undefined,
          uploadedFileIds: [],
          uploadStatus: 'error',
          errorMessage,
        })
        continue
      }

      if (cancelledRef.current) break
      patchRecord(rec.localId, {
        primaryFileId: uploadedFileIds[0],
        uploadedFileIds,
        uploadStatus: 'done',
      })
      if (uploadedFileIds.length > 0) startPolling(resource.id, uploadedFileIds)
    }
    return applied
  }, [collectionId, updateRecord, startPolling])

  const uploadRecords = useCallback((toUpload: WizardResourceRecord[], uploadMode: WizardMode) => {
    uploadRunRef.current = runUploads(toUpload, uploadMode)
  }, [runUploads])

  // ── step 1 → step 2 transition ───────────────────────────────────────────────
  // Back → Next with the same files, mode and required values goes back to the
  // upload already made (retrying only the rows that failed). Any change starts
  // over: the drafts of the earlier attempt are deleted first, so they don't
  // linger as orphans (#21).
  const handleProceedToStep2 = useCallback(async () => {
    cancelledRef.current = false
    stopRef.current = false
    if (!mode) return

    // The step-1 required values: every resource is created with them, and
    // Review starts from them (still editable per resource).
    const initialMetadata = requiredFieldsMetadata(requiredFields, requiredValues)
    const selection = JSON.stringify({
      mode,
      files: files.map((f) => [f.localId, f.role]),
      metadata: initialMetadata,
    })

    if (selection === uploadedSelectionRef.current && records.length > 0) {
      const failed = records
        .filter((r) => r.uploadStatus === 'error')
        .map((r) => ({
          ...r,
          uploadStatus: 'pending' as const,
          errorMessage: undefined,
          fileUploadStatuses: r.fileUploadStatuses?.map(() => 'pending' as const),
        }))
      setStep(2)
      if (failed.length === 0) return
      const retry = failed.map((r) => ({ ...r, resourceId: undefined }))
      setRecords((prev) => prev.map((r) => retry.find((f) => f.localId === r.localId) ?? r))
      // A failed row whose empty draft could not be removed: try once more
      // (it was already reported, and the draft purge clears it otherwise),
      // then create the row afresh.
      await Promise.allSettled(failed.filter((r) => r.resourceId).map((r) => resourceService.deleteResource(r.resourceId!)))
      uploadRecords(retry, mode)
      return
    }

    const earlier = records.filter((r) => r.resourceId)
    if (earlier.length > 0) {
      setIsDiscarding(true)
      stopAll()
      const results = await Promise.allSettled(earlier.map((r) => resourceService.deleteResource(r.resourceId!)))
      setIsDiscarding(false)
      const reasons = results.flatMap((res) => res.status === 'rejected' ? [describeError(res.reason)] : [])
      setDiscardError(reasons.length > 0
        ? `${reasons.length} resource${reasons.length !== 1 ? 's' : ''} from the previous upload could not be deleted: ${[...new Set(reasons)].join(' ')} They stay hidden drafts and are cleared automatically.`
        : null)
    } else {
      setDiscardError(null)
    }

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
        metadata: { ...initialMetadata },
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
        metadata: { ...initialMetadata },
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
        metadata: { ...initialMetadata },
        fileUploadStatuses: [primaryFe, ...supporting].map(() => 'pending' as const),
      }]
    }

    uploadedSelectionRef.current = selection
    setRecords(initialRecords)
    setExitPath(null)
    setStep(2)
    uploadRecords(initialRecords, mode)
  }, [mode, files, records, requiredFields, requiredValues, stopAll, uploadRecords])

  // ── Shared: persist accepted suggestions + promote draft → live ──────────────
  // Returns the names of the resources it could not save: a resource left a
  // draft is purged by the scheduler, so the caller must not carry on silently.
  const persistSuggestions = useCallback(async (): Promise<string[]> => {
    const failed: string[] = []
    for (const rec of records) {
      if (!isCreated(rec)) continue
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
            } catch {
              // Non-fatal: one tag the server refuses must not cost the
              // resource its other tags; it can be added later in the editor.
            }
          }
          if (tagIds.length > 0) await semanticTagService.syncResource(rec.resourceId, tagIds)
        }
      } catch {
        failed.push(rec.name || rec.placeholderName)
      }
    }
    return failed
  }, [records])

  // ── "Auto mode": create aity_review workspace + dispatch job with chosen options ─
  // Any failure keeps the wizard open with the reason: closing as if it had
  // worked leaves the resources live under their file names with no AI (#10).
  const handleAutoMode = useCallback(async () => {
    setIsSavingWorkspace(true)
    setAutoError(null)
    let stage = 'save the resources'
    try {
      const failed = await persistSuggestions()
      if (failed.length > 0) throw new Error(`Could not save ${failed.join(', ')}.`)

      stage = 'create the AiTy Review batch'
      const workspace = autoBatchRef.current
        ?? await workspaceService.createAityReviewBatch(workspaceName.trim() || defaultWorkspaceName())
      autoBatchRef.current = workspace

      stage = 'add the resources to the batch'
      for (const rec of records) {
        if (!isCreated(rec)) continue
        const addError = await workspaceService.addResource(workspace.id, rec.resourceId)
        if (addError) throw new Error(addError)
      }

      stage = 'start AiTy on the batch'
      await dispatchAutoApproveWorkspace(workspace.id, {
        apply_name:        autoApplyNameDesc,
        apply_description: autoApplyNameDesc,
        apply_tags:        autoApplyTags,
        dedup:             autoApplyTags && autoSmartClustering,
      })
    } catch (err) {
      setAutoError(`Could not ${stage}: ${describeError(err)}`)
      setIsSavingWorkspace(false)
      return
    }
    setIsSavingWorkspace(false)
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
  // Nothing to continue with: every upload failed
  const allUploadsFailed = uploadsComplete && doneCount === 0

  // ── Step 4: finish ────────────────────────────────────────────────────────────
  const handleFinish = useCallback(async () => {
    setIsSaving(true)
    setSaveError(null)
    let firstSaved: ResourceData | null = null

    try {
      for (const rec of records) {
        if (!isCreated(rec)) continue

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
            } catch {
              // Non-fatal: one tag the server refuses must not cost the
              // resource its other tags; it can be added later in the editor.
            }
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

  // Keep and Delete all report what they could not do, and the dialog stays
  // open with Retry: a resource that silently stays a draft is purged later,
  // files and all (#20). Retry acts only on the ones that failed.
  const runAbandonAction = useCallback(async (action: 'keep' | 'delete', targets: WizardResourceRecord[]) => {
    const results = await Promise.allSettled(targets.map((r) => action === 'keep'
      ? resourceService.updateResource(r.resourceId!, { state: 'live' })
      : resourceService.deleteResource(r.resourceId!)))
    const failed = targets.flatMap((r, i) => {
      const res = results[i]
      return res.status === 'rejected'
        ? [{ localId: r.localId, name: r.name || r.placeholderName, message: describeError(res.reason) }]
        : []
    })
    if (action === 'delete') {
      // Forget the deleted ones, so a retry or a later Keep can't reach them.
      const deleted = new Set(targets.filter((r) => !failed.some((f) => f.localId === r.localId)).map((r) => r.localId))
      setRecords((prev) => prev.map((r) => deleted.has(r.localId) ? { ...r, resourceId: undefined } : r))
    }
    if (failed.length > 0) {
      setAbandonFailure({ action, failed })
      return
    }
    setAbandonFailure(null)
    setAbandonOpen(false)
    stopAll()
    onClose(true)
  }, [stopAll, onClose])

  // Keep and Delete all first let the upload in flight settle ("Finishing
  // uploads…", buttons disabled): a resource whose creation is still on its
  // way when the button is pressed must be published or deleted with the
  // others, not left a draft for the purge. No new resource is started.
  const settleUploads = useCallback(async (): Promise<WizardResourceRecord[]> => {
    const run = uploadRunRef.current
    if (!run) return records
    setIsFinishingUploads(true)
    try {
      const applied = await run
      return records.map((r) => applied.has(r.localId) ? { ...r, ...applied.get(r.localId) } : r)
    } finally {
      setIsFinishingUploads(false)
    }
  }, [records])

  const handleKeep = useCallback(async () => {
    stopRef.current = true
    setIsPublishing(true)
    try {
      const settled = await settleUploads()
      await runAbandonAction('keep', settled.filter(isCreated))
    } finally {
      setIsPublishing(false)
    }
  }, [settleUploads, runAbandonAction])

  const handleDeleteAll = useCallback(async () => {
    // A resource created after this point is deleted by the upload loop
    // itself (best effort); one already created is deleted here, reported.
    cancelledRef.current = true
    setIsDeletingAll(true)
    try {
      const settled = await settleUploads()
      await runAbandonAction('delete', settled.filter((r) => r.resourceId))
    } finally {
      setIsDeletingAll(false)
    }
  }, [settleUploads, runAbandonAction])

  const handleAbandonRetry = useCallback(async () => {
    if (!abandonFailure) return
    const { action, failed } = abandonFailure
    const targets = records.filter((r) => r.resourceId && failed.some((f) => f.localId === r.localId))
    const setBusy = action === 'keep' ? setIsPublishing : setIsDeletingAll
    setBusy(true)
    try {
      await runAbandonAction(action, targets)
    } finally {
      setBusy(false)
    }
  }, [abandonFailure, records, runAbandonAction])

  // Leave with the failures unresolved: what is left are drafts, which the
  // dialog has warned are cleared automatically.
  const handleAbandonLeave = useCallback(() => {
    cancelledRef.current = true
    setAbandonFailure(null)
    setAbandonOpen(false)
    stopAll()
    onClose(true)
  }, [stopAll, onClose])

  // ── navigation guards ────────────────────────────────────────────────────────
  const isStep1Valid =
    mode !== null &&
    files.length >= 1 &&
    (mode !== 'canonical' || files.some((f) => f.role === 'canonical')) &&
    missingRequired.length === 0
  const step1Hint =
    mode === null || files.length === 0 || (mode === 'canonical' && !files.some((f) => f.role === 'canonical'))
      ? 'Select a mode and at least one file'
      : missingRequired.length > 0
        ? `Fill in ${missingRequired.map((f) => f.display_name).join(', ')}`
        : ''

  // ── step 2 subtitle ──────────────────────────────────────────────────────────
  const step2Subtitle = (() => {
    if (uploadingCount > 0) return `Uploading… (${doneCount + errorCount}/${records.length} done)`
    if (allUploadsFailed) return 'Nothing was uploaded'
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
              requiredFields={requiredFields}
              requiredValues={requiredValues}
              onRequiredValueChange={handleRequiredValueChange}
            />
          )}

          {step === 2 && discardError && (
            <Alert severity="warning" sx={{ m: 2, mb: 0 }}>
              {discardError}
            </Alert>
          )}

          {step === 2 && (
            <Step2UploadChoice
              records={records}
              uploadsComplete={uploadsComplete}
              allUploadsFailed={allUploadsFailed}
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
              <Tooltip title={step1Hint} disableHoverListener={isStep1Valid}>
                <span>
                  <Button
                    variant="contained"
                    onClick={() => void handleProceedToStep2()}
                    disabled={!isStep1Valid || isDiscarding}
                    startIcon={isDiscarding ? <CircularProgress size={16} color="inherit" /> : undefined}
                  >
                    {isDiscarding ? 'Clearing previous upload…' : 'Next →'}
                  </Button>
                </span>
              </Tooltip>
            </>
          )}

          {step === 2 && (
            <>
              {/* Back waits for the uploads: the selection may change on step 1,
                  and the drafts of this attempt must be complete to be cleared. */}
              <Tooltip title={uploadingCount > 0 ? 'Wait for the uploads to finish' : ''}>
                <span>
                  <Button onClick={() => setStep(1)} variant="outlined" disabled={isSavingWorkspace || uploadingCount > 0}>
                    ← Back
                  </Button>
                </span>
              </Tooltip>
              {exitPath === 'auto' && !allUploadsFailed ? (
                <Stack direction="row" spacing={1} alignItems="center">
                  {autoError && (
                    <Typography variant="caption" color="error" role="alert">
                      {autoError}
                    </Typography>
                  )}
                  <Button
                    variant="contained"
                    onClick={handleAutoMode}
                    disabled={!uploadsComplete || isSavingWorkspace}
                    startIcon={isSavingWorkspace ? <CircularProgress size={16} color="inherit" /> : <AutoAwesomeIcon />}
                  >
                    {isSavingWorkspace ? 'Saving…' : 'Save & exit'}
                  </Button>
                </Stack>
              ) : (
                <Button
                  variant="contained"
                  onClick={() => setStep(3)}
                  disabled={exitPath !== 'interactive' || !uploadsComplete || allUploadsFailed}
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
        resourceCount={records.filter((r) => isCreated(r) || r.uploadStatus === 'creating' || r.uploadStatus === 'uploading').length}
        isDeleting={isDeletingAll}
        isPublishing={isPublishing}
        isFinishingUploads={isFinishingUploads}
        failure={abandonFailure}
        onKeep={handleKeep}
        onDeleteAll={handleDeleteAll}
        onRetry={handleAbandonRetry}
        onLeave={handleAbandonLeave}
        onCancel={() => setAbandonOpen(false)}
      />
    </>
  )
}

// ─── Step 2: upload progress + 2-mode choice ──────────────────────────────────

interface Step2Props {
  records: WizardResourceRecord[]
  uploadsComplete: boolean
  allUploadsFailed: boolean
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
  allUploadsFailed,
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

      {/* Every upload failed: say why, and offer no way forward but Back */}
      {allUploadsFailed ? (
        <Alert severity="error">
          <Typography variant="body2" sx={{ fontWeight: 600, mb: 0.5 }}>
            Nothing was uploaded, so there is nothing to review.
          </Typography>
          {[...new Set(records.map((r) => r.errorMessage).filter(Boolean))].map((msg) => (
            <Typography key={msg} variant="body2">{msg}</Typography>
          ))}
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
            Go back to fix it and try again.
          </Typography>
        </Alert>
      ) : (
        <>
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
        </>
      )}
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
