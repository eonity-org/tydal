import React, { useState, useEffect, useMemo } from 'react'
import {
  Box, Button, Chip, Divider, IconButton, MenuItem, Select,
  Stack, Switch, Tab, Tabs, TextField, Typography,
} from '@mui/material'
import AudiotrackIcon from '@mui/icons-material/Audiotrack'
import ArticleIcon from '@mui/icons-material/Article'
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft'
import ChevronRightIcon from '@mui/icons-material/ChevronRight'
import ImageIcon from '@mui/icons-material/Image'
import OndemandVideoIcon from '@mui/icons-material/OndemandVideo'
import AutoAwesomeIcon from '@mui/icons-material/AutoAwesome'

import { InlineSuggestion } from '../suggestions/InlineSuggestion'
import { SuggestionChip } from '../suggestions/SuggestionChip'
import type { SchemeField } from '../../api/collectionService'
import type { WizardResourceRecord } from './ResourceWizard'
import type { AityPollEntry } from '../../hooks/useAityPolling'
import { useMediaSurface } from '../../contexts/ThemeContext'

export interface FilePollEntry {
  filename: string
  pollEntry: AityPollEntry | null
}

// ─── helpers ──────────────────────────────────────────────────────────────────

function fileTypeInfo(mime: string): { icon: React.ReactNode; label: string } {
  const sx = { fontSize: '5rem', color: 'grey.500' }
  if (mime.startsWith('image/'))       return { icon: <ImageIcon sx={sx} />, label: 'Image' }
  if (mime.startsWith('audio/'))       return { icon: <AudiotrackIcon sx={sx} />, label: 'Audio' }
  if (mime.startsWith('video/'))       return { icon: <OndemandVideoIcon sx={sx} />, label: 'Video' }
  if (mime === 'application/pdf')      return { icon: <ArticleIcon sx={sx} />, label: 'PDF' }
  return { icon: <ArticleIcon sx={sx} />, label: 'Document' }
}

// ─── component ────────────────────────────────────────────────────────────────

interface Props {
  record: WizardResourceRecord
  filePollEntries: FilePollEntry[]
  defaultFilename?: string
  schemeFields: SchemeField[]
  onChange: (localId: string, patch: Partial<WizardResourceRecord>) => void
}

/**
 * Two-column inline editor for a single resource in the review carousel.
 *
 * Left  — preview image / file-type icon + file list
 * Right — name, description, pending AI tags, collection metadata fields
 *
 * For component/canonical records (multiple files), a file switcher tab bar
 * lets the user browse each file's AI suggestions independently without
 * affecting which tags are already accepted.
 */
export function CarouselResourceEditor({ record, filePollEntries, defaultFilename, schemeFields, onChange }: Props) {
  const mediaSurfaceSx = useMediaSurface()
  const [activeFilename, setActiveFilename] = useState(
    defaultFilename ?? filePollEntries[0]?.filename
  )
  const [activeFileIdx, setActiveFileIdx] = useState(0)

  // Reset both selectors when navigating to a different resource card
  useEffect(() => {
    setActiveFilename(defaultFilename ?? filePollEntries[0]?.filename)
    setActiveFileIdx(0)
  }, [record.localId, defaultFilename])

  const activePollEntry = filePollEntries.find((e) => e.filename === activeFilename)?.pollEntry ?? null
  const suggestions = activePollEntry?.suggestions ?? null

  // ── file preview ─────────────────────────────────────────────────────────────
  const activeFile = record.allFiles[activeFileIdx] ?? record.file
  const activeMime = activeFile?.type ?? ''
  const { icon: fileIcon, label: fileLabel } = fileTypeInfo(activeMime)

  // Object URL for the active file — recreated only when the record or file index changes
  const activeObjectUrl = useMemo(
    () => (activeFile ? URL.createObjectURL(activeFile) : null),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [record.localId, activeFileIdx]
  )
  useEffect(() => () => { if (activeObjectUrl) URL.revokeObjectURL(activeObjectUrl) }, [activeObjectUrl])

  // ── tag helpers ─────────────────────────────────────────────────────────────

  const acceptTag = (tag: { label: string; description?: string | null; type?: string }) => {
    if (record.acceptedTagLabels.includes(tag.label)) return
    onChange(record.localId, {
      acceptedTagLabels: [...record.acceptedTagLabels, tag.label],
      pendingTags:        [...record.pendingTags, tag],
    })
  }

  const removeAcceptedTag = (label: string) => {
    onChange(record.localId, {
      acceptedTagLabels: record.acceptedTagLabels.filter((l) => l !== label),
      pendingTags: record.pendingTags.filter((t) => t.label !== label),
    })
  }

  // ── all suggested tags (accepted ones shown with checkmark, unaccepted with +) ─
  const allSuggestedTags = suggestions?.suggestedTags ?? []

  // ── show inline suggestion below field ──────────────────────────────────────
  // Suggestion stays visible until explicitly applied or dismissed — not tied to field value
  const showApplyName =
    !!suggestions?.suggestedName && !record.nameSuggestionDismissed
  const showApplyDesc =
    !!suggestions?.suggestedDescription && !record.descSuggestionDismissed

  // ── form field renderer ──────────────────────────────────────────────────────
  const renderSchemeField = (field: SchemeField) => {
    const val = record.metadata[field.name] ?? ''
    const setVal = (v: any) =>
      onChange(record.localId, { metadata: { ...record.metadata, [field.name]: v } })

    if (field.type === 'boolean') {
      return (
        <Stack direction="row" alignItems="center" spacing={1} key={field.name}>
          <Typography variant="caption" color="text.secondary" sx={{ flex: 1 }}>
            {field.display_name}
          </Typography>
          <Switch
            size="small"
            checked={!!val}
            onChange={(e) => setVal(e.target.checked)}
          />
        </Stack>
      )
    }

    if (field.type === 'select' && field.validators?.in) {
      return (
        <Select
          key={field.name}
          size="small"
          fullWidth
          displayEmpty
          value={val}
          onChange={(e) => setVal(e.target.value)}
          renderValue={(v) => v || <Typography color="text.disabled">{field.display_name}</Typography>}
        >
          {!field.required && <MenuItem value=""><em>— none —</em></MenuItem>}
          {field.validators.in.map((opt) => (
            <MenuItem key={opt} value={opt}>{opt}</MenuItem>
          ))}
        </Select>
      )
    }

    return (
      <TextField
        key={field.name}
        size="small"
        fullWidth
        label={field.display_name}
        required={field.required}
        type={field.type === 'integer' ? 'number' : field.type === 'date' ? 'date' : 'text'}
        multiline={field.type === 'text'}
        rows={field.type === 'text' ? 2 : undefined}
        value={val}
        onChange={(e) => setVal(e.target.value)}
        InputLabelProps={field.type === 'date' ? { shrink: true } : undefined}
      />
    )
  }

  const editableFields = schemeFields.filter((f) => f.display_in_form && f.storage === 'metadata')

  return (
    <Box sx={{ display: 'flex', height: '100%', p: 1.5, gap: 1.5, boxSizing: 'border-box' }}>
      {/* ── Left: preview ────────────────────────────────────────────────────── */}
      <Box
        sx={{
          flexBasis: '40%',
          flexShrink: 0,
          display: 'flex',
          flexDirection: 'column',
          bgcolor: 'grey.900',
          borderRadius: 2,
          overflow: 'hidden',
        }}
      >
        {/* Preview */}
        <Box
          sx={{
            flex: 1,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            minHeight: 160,
            overflow: 'hidden',
          }}
        >
          {activeMime.startsWith('image/') && activeObjectUrl ? (
            <Box
              component="img"
              src={activeObjectUrl}
              alt="preview"
              sx={{ maxWidth: '100%', maxHeight: '100%', objectFit: 'contain', ...mediaSurfaceSx.image }}
            />
          ) : activeMime.startsWith('video/') && activeObjectUrl ? (
            <Box
              component="video"
              src={activeObjectUrl}
              controls
              sx={{ maxWidth: '100%', maxHeight: '100%', display: 'block' }}
            />
          ) : activeMime.startsWith('audio/') && activeObjectUrl ? (
            <Stack alignItems="center" spacing={2} sx={{ p: 2, width: '100%' }}>
              {fileIcon}
              <Box
                component="audio"
                src={activeObjectUrl}
                controls
                sx={{ width: '100%', maxWidth: 240 }}
              />
            </Stack>
          ) : activeMime === 'application/pdf' && activeObjectUrl ? (
            <Box
              component="iframe"
              src={activeObjectUrl}
              title="PDF preview"
              sx={{ width: '100%', height: '100%', border: 'none', display: 'block' }}
            />
          ) : (
            <Stack alignItems="center" spacing={1}>
              {fileIcon}
              <Typography variant="caption" sx={{ color: 'grey.500', letterSpacing: 1, textTransform: 'uppercase', fontSize: '0.65rem' }}>
                {fileLabel}
              </Typography>
            </Stack>
          )}
        </Box>

        {/* File navigator */}
        <Stack
          direction="row"
          alignItems="center"
          sx={{ borderTop: '1px solid', borderColor: 'grey.700', px: 0.5, py: 0.5 }}
        >
          {record.allFiles.length > 1 && (
            <IconButton
              size="small"
              onClick={() => setActiveFileIdx((i) => Math.max(0, i - 1))}
              disabled={activeFileIdx === 0}
              sx={{ color: 'grey.400', flexShrink: 0, '&.Mui-disabled': { color: 'grey.700' }, '&:hover': { color: 'grey.100' } }}
            >
              <ChevronLeftIcon sx={{ fontSize: 18 }} />
            </IconButton>
          )}
          <Box sx={{ flex: 1, minWidth: 0, textAlign: record.allFiles.length > 1 ? 'center' : 'left', px: 0.5 }}>
            <Typography
              variant="caption"
              noWrap
              title={activeFile?.name}
              sx={{ color: 'grey.300', display: 'block', lineHeight: 1.3 }}
            >
              {activeFile?.name}
            </Typography>
            {record.allFiles.length > 1 && (
              <Typography variant="caption" sx={{ color: 'grey.400', lineHeight: 1 }}>
                {activeFileIdx + 1} / {record.allFiles.length}
              </Typography>
            )}
          </Box>
          {record.allFiles.length > 1 && (
            <IconButton
              size="small"
              onClick={() => setActiveFileIdx((i) => Math.min(record.allFiles.length - 1, i + 1))}
              disabled={activeFileIdx === record.allFiles.length - 1}
              sx={{ color: 'grey.400', flexShrink: 0, '&.Mui-disabled': { color: 'grey.700' }, '&:hover': { color: 'grey.100' } }}
            >
              <ChevronRightIcon sx={{ fontSize: 18 }} />
            </IconButton>
          )}
        </Stack>
      </Box>

      {/* ── Right: editor ────────────────────────────────────────────────────── */}
      <Box
        sx={{ flex: 1, overflowY: 'auto', p: 2, display: 'flex', flexDirection: 'column', gap: 2, bgcolor: 'grey.50', borderRadius: 2 }}
      >
        {/* Name */}
        <Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>Name *</Typography>
          <TextField
            size="small"
            fullWidth
            value={record.name}
            placeholder={record.placeholderName}
            onChange={(e) => onChange(record.localId, { name: e.target.value })}
          />
          {showApplyName && (
            <InlineSuggestion
              value={suggestions!.suggestedName!}
              onApply={() => onChange(record.localId, { name: suggestions!.suggestedName! })}
              onDismiss={() => onChange(record.localId, { nameSuggestionDismissed: true })}
            />
          )}
        </Box>

        {/* Description */}
        <Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5 }}>Description</Typography>
          <TextField
            size="small"
            fullWidth
            multiline
            rows={2}
            value={record.description}
            placeholder="Brief description"
            onChange={(e) => onChange(record.localId, { description: e.target.value })}
          />
          {showApplyDesc && (
            <InlineSuggestion
              value={suggestions!.suggestedDescription!}
              multiline
              onApply={() => onChange(record.localId, { description: suggestions!.suggestedDescription! })}
              onDismiss={() => onChange(record.localId, { descSuggestionDismissed: true })}
            />
          )}
        </Box>

        {/* AI tag suggestions */}
        {(allSuggestedTags.length > 0 || filePollEntries.length > 1) && (
          <Box>
            <Stack direction="row" alignItems="center" sx={{ mb: 0.75 }}>
              <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600, flex: 1 }}>
                Tags
              </Typography>
              {record.acceptedTagLabels.length > 0 && (
                <Button
                  size="small"
                  variant="text"
                  onClick={() => onChange(record.localId, { acceptedTagLabels: [], pendingTags: [] })}
                  sx={{ fontSize: '0.7rem', py: 0, minHeight: 0, color: 'text.secondary' }}
                >
                  Deselect all tags
                </Button>
              )}
            </Stack>

            {/* File switcher — only shown for component/canonical records with multiple files */}
            {filePollEntries.length > 1 && (
              <Tabs
                value={activeFilename}
                onChange={(_, v) => setActiveFilename(v)}
                variant="scrollable"
                scrollButtons="auto"
                sx={{
                  mb: 1,
                  minHeight: 28,
                  '& .MuiTab-root': { minHeight: 28, py: 0.5, px: 1.25, fontSize: '0.68rem' },
                  '& .MuiTabs-indicator': { height: 2 },
                }}
              >
                {filePollEntries.map((entry) => (
                  <Tab key={entry.filename} label={entry.filename} value={entry.filename} />
                ))}
              </Tabs>
            )}

            {/* Suggested tags — AiTy bordered box */}
            {allSuggestedTags.length > 0 && (
              <Box
                sx={{
                  mb: 1,
                  pl: 1,
                  py: 0.4,
                  borderLeft: '2px solid',
                  borderColor: 'primary.light',
                  bgcolor: 'primary.50',
                  borderRadius: '0 4px 4px 0',
                }}
              >
                <Stack direction="row" alignItems="center" sx={{ mb: 0.5, gap: 0.4 }}>
                  <Typography variant="caption" sx={{ color: 'primary.main', fontWeight: 700, lineHeight: 1.5 }}>
                    AiTy
                  </Typography>
                  <AutoAwesomeIcon sx={{ fontSize: 11, color: 'primary.main' }} />
                </Stack>
                <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.75 }}>
                  {allSuggestedTags.map((tag) => (
                    <SuggestionChip
                      key={tag.label}
                      label={tag.label}
                      type={tag.type}
                      description={tag.description}
                      confidence={tag.confidence}
                      accepted={record.acceptedTagLabels.includes(tag.label)}
                      onAccept={() => acceptTag(tag)}
                      onReject={() => removeAcceptedTag(tag.label)}
                    />
                  ))}
                </Box>
              </Box>
            )}
          </Box>
        )}

        {/* Accepted tags:
            - Single-file: only shown as fallback when there are no AI suggestions
            - Multi-file: always shown below the tab switcher so accepted tags from
              any file remain visible regardless of which tab is active */}
        {record.acceptedTagLabels.length > 0 && (allSuggestedTags.length === 0 || filePollEntries.length > 1) && (
          <Box>
            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 0.5, fontWeight: 600 }}>
              {filePollEntries.length > 1 ? 'Accepted tags' : 'Tags'}
            </Typography>
            <Stack direction="row" flexWrap="wrap" gap={0.5}>
              {record.acceptedTagLabels.map((label) => (
                <Chip
                  key={label}
                  label={label}
                  size="small"
                  color="primary"
                  onDelete={() => removeAcceptedTag(label)}
                />
              ))}
            </Stack>
          </Box>
        )}

        {/* Collection metadata fields */}
        {editableFields.length > 0 && (
          <>
            <Divider />
            <Stack spacing={1.5}>
              <Typography variant="caption" color="text.secondary">Collection fields</Typography>
              {editableFields.map(renderSchemeField)}
            </Stack>
          </>
        )}

      </Box>
    </Box>
  )
}
