import { useState, useEffect, useCallback, useRef } from 'react'
import resourceService, { ResourceData, ResourceFile } from '../api/resourceService'

// ─── types ────────────────────────────────────────────────────────────────────

export interface FileSuggestion {
  filename: string
  suggestedName: string | null
  suggestedDescription: string | null
  suggestedTags: Array<{ label: string; description?: string | null; type?: string; confidence?: number }>
}

export interface AiSuggestions {
  /** Files that have at least one active AI suggestion. */
  filesWithSuggestions: ResourceFile[]
  /** One entry per source file that produced a name suggestion. */
  suggestedNames: Array<{ value: string; sourceFile: ResourceFile }>
  /** One entry per source file that produced a description suggestion. */
  suggestedDescriptions: Array<{ value: string; sourceFile: ResourceFile }>
  /** All suggested tags across all files, deduplicated by label, with originating file. */
  suggestedTags: Array<{ label: string; description?: string | null; type?: string; confidence?: number; sourceFile: ResourceFile }>
  /** AITY-generated tags (resource-level, no source file). Same shape as suggestedTags entries
   *  but sourceFile is a synthetic placeholder whose filename reads "aity generated". */
  suggestedTagsGenerated?: Array<{ label: string; description?: string | null; type?: string; confidence?: number; sourceFile: ResourceFile }>
  /** Per-file suggested tags (excludes anything already in suggestedTagsGenerated). */
  suggestedTagsFromFiles?: Array<{ label: string; description?: string | null; type?: string; confidence?: number; sourceFile: ResourceFile }>
  /** Convenience: first available name. */
  suggestedName: string | null
  /** Convenience: first available description. */
  suggestedDescription: string | null
  /** Per-file breakdown — populated for multi-file resources (components / canonical modes). */
  perFileSuggestions: FileSuggestion[]
}

export type SuggestionsStatus = 'idle' | 'waiting' | 'ready' | 'timeout'

interface Options {
  pollInterval?: number
  stopWhen?: (resource: ResourceData) => boolean
  timeoutMs?: number
  manual?: boolean
}

// How long to keep polling after 'not_applicable', waiting for Tika / vision-AI.
const TIKA_WAIT_MS = 30_000

// ─── extraction helpers ───────────────────────────────────────────────────────

function hasSuggestions(resource: ResourceData): boolean {
  return (resource.files ?? []).some(
    (f) =>
      f.latest_ai_suggested_tags_system_file?.metadata?.value?.length ||
      f.latest_ai_suggested_name_system_file?.metadata?.value ||
      f.latest_ai_suggested_description_system_file?.metadata?.value
  )
}

export function extractSuggestions(resource: ResourceData): AiSuggestions {
  const files = resource.files ?? []
  const filesWithSuggestions = files.filter(
    (f) =>
      f.latest_ai_suggested_tags_system_file?.metadata?.value?.length ||
      f.latest_ai_suggested_name_system_file?.metadata?.value ||
      f.latest_ai_suggested_description_system_file?.metadata?.value
  )

  const suggestedNames = filesWithSuggestions
    .filter((f) => f.latest_ai_suggested_name_system_file?.metadata?.value)
    .map((f) => ({ value: f.latest_ai_suggested_name_system_file!.metadata!.value as string, sourceFile: f }))

  const suggestedDescriptions = filesWithSuggestions
    .filter((f) => f.latest_ai_suggested_description_system_file?.metadata?.value)
    .map((f) => ({ value: f.latest_ai_suggested_description_system_file!.metadata!.value as string, sourceFile: f }))

  const seenLabels = new Set<string>()
  const suggestedTags: AiSuggestions['suggestedTags'] = []
  for (const f of filesWithSuggestions) {
    for (const tag of f.latest_ai_suggested_tags_system_file?.metadata?.value ?? []) {
      if (!seenLabels.has(tag.label)) {
        seenLabels.add(tag.label)
        suggestedTags.push({ ...tag, sourceFile: f })
      }
    }
  }

  const perFileSuggestions: FileSuggestion[] = filesWithSuggestions.map((f) => ({
    filename: f.filename,
    suggestedName: f.latest_ai_suggested_name_system_file?.metadata?.value ?? null,
    suggestedDescription: f.latest_ai_suggested_description_system_file?.metadata?.value ?? null,
    suggestedTags: (f.latest_ai_suggested_tags_system_file?.metadata?.value ?? []).map((t) => ({
      label: t.label,
      description: t.description,
      type: t.type,
      confidence: t.confidence,
    })),
  }))

  return {
    filesWithSuggestions,
    suggestedNames,
    suggestedDescriptions,
    suggestedTags,
    suggestedName: suggestedNames[0]?.value ?? null,
    suggestedDescription: suggestedDescriptions[0]?.value ?? null,
    perFileSuggestions,
  }
}

// ─── Tika / EXIF extraction ───────────────────────────────────────────────────

function tikaStr(tika: Record<string, string | string[]>, ...keys: string[]): string | null {
  for (const key of keys) {
    const val = tika[key]
    if (!val) continue
    const str = (Array.isArray(val) ? val[0] : val)?.trim()
    if (str) return str
  }
  return null
}

function tikaArr(tika: Record<string, string | string[]>, ...keys: string[]): string[] {
  for (const key of keys) {
    const val = tika[key]
    if (!val) continue
    const arr = (Array.isArray(val) ? val : [val]).map((s) => s.trim()).filter(Boolean)
    if (arr.length > 0) return [...new Set(arr)]
  }
  return []
}

/**
 * Derives suggestions from Apache Tika EXIF/IPTC/XMP metadata.
 * Returns null when there is not enough data to be useful.
 * Used as a fallback when ai_suggestions_status === 'not_applicable' (images/video).
 */
export function extractTikaSuggestions(resource: ResourceData): AiSuggestions | null {
  const files = resource.files ?? []
  const filesWithSugg: ResourceFile[] = []
  const suggestedNames: AiSuggestions['suggestedNames'] = []
  const suggestedDescriptions: AiSuggestions['suggestedDescriptions'] = []
  const suggestedTags: AiSuggestions['suggestedTags'] = []
  const perFileSuggestions: FileSuggestion[] = []
  const seenLabels = new Set<string>()

  for (const file of files) {
    const tika = file.latest_tika_system_file?.metadata?.tika_metadata
    if (!tika || Object.keys(tika).length === 0) continue

    // ── name ────────────────────────────────────────────────────────────────
    const title   = tikaStr(tika, 'dc:title', 'title', 'Exif IFD0:Image Description', 'IPTC:Headline')
    const make    = tikaStr(tika, 'tiff:Make', 'Make', 'Exif IFD0:Make')
    const model   = tikaStr(tika, 'tiff:Model', 'Model', 'Exif IFD0:Model')
    const dateRaw = tikaStr(tika, 'Creation-Date', 'meta:creation-date', 'date:created',
      'Exif SubIFD:Date/Time Original', 'Date/Time Original')

    let suggestedName: string | null = title
    if (!suggestedName && model) {
      const cleanModel = make && model.toLowerCase().startsWith(make.toLowerCase())
        ? model.slice(make.length).trim()
        : model
      if (dateRaw) {
        try {
          const d = new Date(dateRaw)
          if (!isNaN(d.getTime())) {
            const ds = d.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
            suggestedName = `${cleanModel} — ${ds}`
          }
        } catch { /* ignore */ }
      }
      suggestedName = suggestedName ?? cleanModel
    }

    // ── description ─────────────────────────────────────────────────────────
    const explicitDesc = tikaStr(tika,
      'dc:description', 'description', 'IPTC:Caption/Abstract', 'Exif IFD0:User Comment')
    let suggestedDescription: string | null = explicitDesc
    if (!suggestedDescription) {
      const parts: string[] = []
      if (make && model) parts.push(`Taken with ${make} ${model}`)
      else if (model)    parts.push(`Taken with ${model}`)
      if (dateRaw) {
        try {
          const d = new Date(dateRaw)
          if (!isNaN(d.getTime())) {
            const ds = d.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
            parts.push(`on ${ds}`)
          }
        } catch { /* ignore */ }
      }
      const w = tikaStr(tika, 'tiff:ImageWidth', 'Image Width', 'Exif IFD0:Image Width')
      const h = tikaStr(tika, 'tiff:ImageLength', 'Image Height', 'Exif IFD0:Image Height')
      if (w && h) parts.push(`${w}×${h} px`)
      suggestedDescription = parts.length > 0 ? parts.join(', ') : null
    }

    // ── tags ────────────────────────────────────────────────────────────────
    const rawTags = tikaArr(tika, 'dc:subject', 'Keywords', 'IPTC:Keywords', 'xmp:subject')
    const fileTags = rawTags.map((label) => ({ label }))

    if (suggestedName || suggestedDescription || fileTags.length > 0) {
      filesWithSugg.push(file)
      if (suggestedName) suggestedNames.push({ value: suggestedName, sourceFile: file })
      if (suggestedDescription) suggestedDescriptions.push({ value: suggestedDescription, sourceFile: file })
      for (const tag of fileTags) {
        if (!seenLabels.has(tag.label)) {
          seenLabels.add(tag.label)
          suggestedTags.push({ ...tag, sourceFile: file })
        }
      }
      perFileSuggestions.push({ filename: file.filename, suggestedName, suggestedDescription, suggestedTags: fileTags })
    }
  }

  if (filesWithSugg.length === 0) return null

  return {
    filesWithSuggestions: filesWithSugg,
    suggestedNames,
    suggestedDescriptions,
    suggestedTags,
    suggestedName: suggestedNames[0]?.value ?? null,
    suggestedDescription: suggestedDescriptions[0]?.value ?? null,
    perFileSuggestions,
  }
}

// ─── hook ─────────────────────────────────────────────────────────────────────

export function useAiSuggestionsPoller(
  resourceId: string | null,
  options: Options = {}
) {
  const {
    pollInterval = 3000,
    stopWhen = hasSuggestions,
    timeoutMs = 300_000,
    manual = false,
  } = options

  const [status, setStatus] = useState<SuggestionsStatus>(manual ? 'idle' : 'waiting')
  const [suggestions, setSuggestions] = useState<AiSuggestions | null>(null)

  const intervalRef   = useRef<ReturnType<typeof setInterval> | null>(null)
  const timeoutRef    = useRef<ReturnType<typeof setTimeout> | null>(null)
  const activeRef     = useRef(false)
  const tikaWaitingRef = useRef(false)

  const stop = useCallback(() => {
    if (intervalRef.current)  clearInterval(intervalRef.current)
    if (timeoutRef.current)   clearTimeout(timeoutRef.current)
    activeRef.current    = false
    tikaWaitingRef.current = false
  }, [])

  const poll = useCallback(async () => {
    if (!resourceId || !activeRef.current) return

    const resource = await resourceService.getResource(resourceId)
    if (!resource || !activeRef.current) return

    if (resource.ai_suggestions_status === 'not_applicable') {
      // Try Tika/EXIF fallback for images and video
      const tikaSugg = extractTikaSuggestions(resource)
      if (tikaSugg && !tikaWaitingRef.current) {
        // First not_applicable with Tika data: show immediately, shorten timeout for vision-AI
        tikaWaitingRef.current = true
        setSuggestions(tikaSugg)
        setStatus('ready')
        if (timeoutRef.current) clearTimeout(timeoutRef.current)
        timeoutRef.current = setTimeout(() => {
          if (activeRef.current) stop()
        }, TIKA_WAIT_MS)
      } else if (tikaSugg && tikaWaitingRef.current) {
        // Richer Tika scan may have completed — update in place
        setSuggestions(tikaSugg)
      } else if (!tikaWaitingRef.current) {
        // No Tika data yet — start 30 s window for vision-AI
        tikaWaitingRef.current = true
        if (timeoutRef.current) clearTimeout(timeoutRef.current)
        timeoutRef.current = setTimeout(() => {
          if (activeRef.current) { stop(); setStatus('timeout') }
        }, TIKA_WAIT_MS)
      }
      return // keep polling so vision-AI result can arrive
    }

    if (stopWhen(resource)) {
      stop()
      setSuggestions(extractSuggestions(resource))
      setStatus('ready')
    }
  }, [resourceId, stopWhen, stop])

  const startPolling = useCallback(() => {
    if (!resourceId) return
    stop()
    activeRef.current     = true
    tikaWaitingRef.current = false
    setStatus('waiting')
    setSuggestions(null)

    poll()
    intervalRef.current = setInterval(poll, pollInterval)

    timeoutRef.current = setTimeout(() => {
      if (activeRef.current) { stop(); setStatus('timeout') }
    }, timeoutMs)
  }, [resourceId, poll, pollInterval, timeoutMs, stop])

  useEffect(() => {
    if (!manual && resourceId) startPolling()
    return stop
  }, [manual, resourceId, startPolling, stop])

  return { status, suggestions, startPolling, stop }
}
