import { useEffect, useMemo, useState } from 'react'
import {
  Box,
  CircularProgress,
  ClickAwayListener,
  InputAdornment,
  Paper,
  Popper,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { Close, Search } from '@mui/icons-material'
import semanticTagService from '../../api/semanticTagService'
import type { SemanticTag } from '../../api/resourceService'
import { ENTITY_TYPES, type EntityTypeKey } from '../../constants/entityTypes'

/**
 * A semantic tag picker for acting on a SET of resources.
 *
 * A TYDAL tag is not a free-text label: it carries an `entity_type` (person /
 * organization / place / thing / tag), which is what makes the vocabulary
 * semantic. A plain text autocomplete hides exactly the dimension that
 * matters, so every row shows the entity's colour-coded icon, its type, and
 * how many resources already carry it — the last one being what tells you
 * whether you are reaching for the established term or a near-duplicate.
 *
 * ── Known duplication, deliberately kept ──────────────────────────────────
 * This is NOT the only tag picker. The resource editor has its own, inline in
 * `components/modals/ResourceDetailModal.tsx` (state around :381-:497, markup
 * around :3700-:3960). The two look alike on purpose but are separate
 * implementations, because the editor's does five things this one does not:
 *
 *   1. server-side debounced search, for vocabularies too large to hold
 *      client-side (this one fetches once on focus and filters in memory);
 *   2. two pools — AITY-suggested tags when idle, search results when typing;
 *   3. provenance on the selected chips (AI_GENERATED), wired to the
 *      suggestion accept/dismiss machinery;
 *   4. entity-type override editing, re-typing a saved tag on save;
 *   5. Enter-to-create from the search field.
 *
 * Folding those into one component means refactoring the most entangled part
 * of a 4118-line modal, and the suggestion interplay has no test coverage to
 * catch a regression — so the duplication was judged the cheaper risk.
 *
 * If you change the row or chip appearance here, change it there too, or the
 * two will drift.
 */

/** Entity swatch — the colour-coded square that identifies a tag's kind. */
export function EntityGlyph({ entityType, size = 22 }: { entityType?: string | null; size?: number }) {
  const key = entityType && entityType in ENTITY_TYPES ? (entityType as EntityTypeKey) : null
  const et = key ? ENTITY_TYPES[key] : null

  if (!et) {
    return <Box sx={{ width: size, height: size, borderRadius: 0.75, bgcolor: 'grey.200', flexShrink: 0 }} />
  }

  return (
    <Box
      sx={{
        width: size, height: size, borderRadius: 0.75, bgcolor: et.color,
        display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0,
      }}
    >
      <et.Icon sx={{ fontSize: size * 0.55, color: 'white' }} />
    </Box>
  )
}

export interface SemanticTagPickerProps {
  value: SemanticTag[]
  onChange: (tags: SemanticTag[]) => void
  /**
   * Offer "Create as…" rows when the query matches nothing. Off for removal,
   * where inventing a tag in order to take it away makes no sense.
   */
  allowCreate?: boolean
  placeholder?: string
  /** Shown under the field. */
  helperText?: string
  disabled?: boolean
}

export function SemanticTagPicker({
  value,
  onChange,
  allowCreate = false,
  placeholder = 'Search tags…',
  helperText,
  disabled = false,
}: SemanticTagPickerProps) {
  const [pool, setPool] = useState<SemanticTag[]>([])
  const [loading, setLoading] = useState(false)
  const [loaded, setLoaded] = useState(false)
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [creating, setCreating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // The dropdown is portalled out of the DOM tree (see the Popper below), so
  // the anchor has to be state rather than a ref — Popper needs to re-render
  // once the element exists.
  const [anchorEl, setAnchorEl] = useState<HTMLDivElement | null>(null)

  // The whole org vocabulary is fetched once, on first focus, and filtered
  // here — a dialog that re-queries on every keystroke feels laggier than one
  // that pays a single round-trip up front.
  useEffect(() => {
    if (!open || loaded) return
    setLoading(true)
    semanticTagService.list()
      .then((tags) => { setPool(tags); setLoaded(true) })
      .catch(() => setPool([]))
      .finally(() => setLoading(false))
  }, [open, loaded])

  const chosenIds = useMemo(() => new Set(value.map((t) => t.id)), [value])

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return pool
      .filter((tag) => !chosenIds.has(tag.id))
      .filter((tag) => (needle ? tag.label.toLowerCase().includes(needle) : true))
  }, [pool, chosenIds, query])

  const exactMatch = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return needle.length > 0 && pool.some((tag) => tag.label.toLowerCase() === needle)
  }, [pool, query])

  const showCreate = allowCreate && query.trim().length > 0 && !exactMatch && !loading

  const add = (tag: SemanticTag) => {
    onChange([...value, tag])
    setQuery('')
    setError(null)
  }

  const remove = (tagId: number) => onChange(value.filter((t) => t.id !== tagId))

  const create = async (entityType: EntityTypeKey) => {
    const label = query.trim()
    if (!label || creating) return
    setCreating(true)
    setError(null)
    try {
      const tag = await semanticTagService.create({ label, entity_type: entityType })
      setPool((prev) => [...prev, tag])
      add(tag)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'The tag could not be created.')
    } finally {
      setCreating(false)
    }
  }

  return (
    <Box>
      {/* Selected — the same chip the resource panel shows, minus the
          provenance line, which is per-resource and meaningless for a set. */}
        {value.length > 0 && (
          <Stack direction="row" spacing={0.75} flexWrap="wrap" useFlexGap sx={{ mb: 1 }}>
            {value.map((tag) => (
              <Stack
                key={tag.id}
                direction="row"
                alignItems="center"
                spacing={0.75}
                sx={{
                  pl: 0.5, pr: 0.75, py: 0.25,
                  border: '1px solid', borderColor: 'divider', borderRadius: 1,
                  bgcolor: 'grey.50',
                }}
              >
                <EntityGlyph entityType={tag.entity_type} size={20} />
                <Typography variant="body2" sx={{ fontWeight: 500 }}>{tag.label}</Typography>
                <Close
                  onClick={() => !disabled && remove(tag.id)}
                  sx={{ fontSize: '0.875rem', color: 'text.disabled', cursor: 'pointer', '&:hover': { color: 'error.main' } }}
                />
              </Stack>
            ))}
          </Stack>
        )}

        <Box ref={setAnchorEl}>
          <TextField
            size="small"
            fullWidth
            disabled={disabled}
            placeholder={placeholder}
            value={query}
            onChange={(e) => { setQuery(e.target.value); setOpen(true); setError(null) }}
            onFocus={() => setOpen(true)}
            helperText={error ?? helperText}
            error={Boolean(error)}
            InputProps={{
              startAdornment: (
                <InputAdornment position="start">
                  {loading
                    ? <CircularProgress size={14} />
                    : <Search sx={{ fontSize: '1rem', color: 'text.disabled' }} />}
                </InputAdornment>
              ),
            }}
          />

          {/* Portalled, not absolutely positioned inside the field: this
              picker lives in a Dialog whose content scrolls, and an in-flow
              dropdown gets clipped by that scroll container the moment the
              list is taller than the space left below the input. */}
          <Popper
            open={open && Boolean(anchorEl)}
            anchorEl={anchorEl}
            placement="bottom-start"
            style={{ width: anchorEl?.clientWidth }}
            sx={{ zIndex: (theme) => theme.zIndex.modal + 1 }}
            modifiers={[{ name: 'offset', options: { offset: [0, 4] } }]}
          >
            <ClickAwayListener onClickAway={() => setOpen(false)}>
            <Paper
              elevation={4}
              sx={{
                maxHeight: 240, overflowY: 'auto', border: '1px solid', borderColor: 'divider',
              }}
            >
              {query.trim().length === 0 && visible.length > 0 && (
                <Box sx={{ px: 1.5, pt: 1, pb: 0.25 }}>
                  <Typography variant="caption" sx={{ color: 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                    All tags
                  </Typography>
                </Box>
              )}

              {visible.map((tag) => {
                const key = tag.entity_type && tag.entity_type in ENTITY_TYPES ? (tag.entity_type as EntityTypeKey) : null
                const et = key ? ENTITY_TYPES[key] : null
                return (
                  <Box
                    key={tag.id}
                    onMouseDown={(e) => { e.preventDefault(); add(tag) }}
                    sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1.5, py: 0.875, cursor: 'pointer', '&:hover': { bgcolor: 'grey.50' } }}
                  >
                    <EntityGlyph entityType={tag.entity_type} />
                    <Typography variant="body2" sx={{ flex: 1 }}>{tag.label}</Typography>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, flexShrink: 0 }}>
                      {et && (
                        <Typography
                          variant="caption"
                          sx={{ color: et.color, textTransform: 'uppercase', letterSpacing: 0.4, fontSize: '0.68rem', fontWeight: 600 }}
                        >
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
              })}

              {showCreate && (
                <>
                  <Box sx={{ px: 1.5, pt: 0.75, pb: 0.25, borderTop: visible.length > 0 ? '1px solid' : 'none', borderColor: 'divider' }}>
                    <Typography variant="caption" sx={{ color: 'text.disabled', textTransform: 'uppercase', letterSpacing: 0.5, fontSize: '0.75rem' }}>
                      Create as…
                    </Typography>
                  </Box>
                  {(Object.entries(ENTITY_TYPES) as [EntityTypeKey, typeof ENTITY_TYPES[EntityTypeKey]][]).map(([key, et]) => (
                    <Box
                      key={key}
                      onMouseDown={(e) => { e.preventDefault(); void create(key) }}
                      sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 1.5, py: 0.75, cursor: creating ? 'default' : 'pointer', '&:hover': { bgcolor: 'grey.50' } }}
                    >
                      <EntityGlyph entityType={key} size={20} />
                      <Typography variant="body2">
                        <Typography component="span" variant="body2" sx={{ color: 'text.disabled' }}>Create </Typography>
                        <Typography component="span" variant="body2" sx={{ fontWeight: 600 }}>&quot;{query.trim()}&quot;</Typography>
                        <Typography component="span" variant="body2" sx={{ color: 'text.disabled' }}> as {et.label}</Typography>
                      </Typography>
                      {creating && <CircularProgress size={12} sx={{ ml: 'auto' }} />}
                    </Box>
                  ))}
                </>
              )}

              {!loading && visible.length === 0 && !showCreate && (
                <Box sx={{ px: 1.5, py: 1 }}>
                  <Typography variant="caption" color="text.disabled">
                    {query.trim().length > 0 ? 'No tag matches — and it is already chosen, or does not exist.' : 'No tags in this organization yet.'}
                  </Typography>
                </Box>
              )}
            </Paper>
            </ClickAwayListener>
          </Popper>
        </Box>
      </Box>
  )
}

export default SemanticTagPicker
