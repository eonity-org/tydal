import { useState, useEffect } from 'react'
import { Stack, Typography, TextField, MenuItem, Chip, Button, Box, Tooltip } from '@mui/material'
import type {
  VaultCapabilityDef, VaultCapabilityGroup, VaultCapabilityKey, VaultExposurePolicy,
} from '../../api/vaultService'
import SectionTitle from './SectionTitle'

/**
 * The vault capability matrix (VAULT_SYSTEM.md §6.3).
 *
 * Purpose is a *preset* over these knobs, and `exposure_policy` overrides any
 * of them — but until now the overrides had no UI at all, so picking a purpose
 * meant committing to defaults you could not see and could not adjust without
 * writing JSON by hand. This renders the preset and lets each row be overridden
 * or reset, with the source of every effective value labelled.
 *
 * The presets are fetched from the backend rather than restated here: a table
 * duplicated in TypeScript is a table free to drift from the enum that governs.
 */

const GROUP_LABELS: Record<VaultCapabilityGroup, string> = {
  read_tiers: 'Read tiers — what the boundary answers',
  projection: 'Projection — which file roles reach a tier',
  write: 'Write boundary',
  retrieval: 'Retrieval tuning',
}

const GROUP_ORDER: VaultCapabilityGroup[] = ['read_tiers', 'projection', 'write', 'retrieval']

/**
 * Capabilities gated by an access level rather than a plain value. The legacy
 * boolean form still arrives from older policies — `true` is `inherit`,
 * `false` is `denied` — so it is normalised for display.
 */
const GATED_KEYS: VaultCapabilityKey[] = ['allow_chunks', 'allow_binary', 'allow_ask']
const LIST_KEYS: VaultCapabilityKey[] = ['address_roles', 'chunk_roles', 'write_methods']

type AccessLevel = 'inherit' | 'key' | 'denied'

const LEVEL_HINTS: Record<AccessLevel, string> = {
  inherit: 'Open when the vault is public, key-gated when it is private',
  key: 'Requires a vault key even while the vault is public',
  denied: 'Never answers, whatever credential is presented',
}

function toLevel(v: unknown): AccessLevel {
  if (v === true) return 'inherit'
  if (v === false) return 'denied'
  return (v === 'key' || v === 'denied' || v === 'inherit') ? v : 'inherit'
}

/** A workspace or collection the ingest target can point at, by name. */
export interface IngestTargetOption {
  id: number
  name: string
  /** Shown after the name, e.g. "default · all resources". */
  hint?: string
}

interface Props {
  capabilities: VaultCapabilityDef[]
  /** The active purpose's preset values, keyed by capability. */
  preset: Record<VaultCapabilityKey, unknown>
  /** This vault's overrides; null/absent keys fall back to the preset. */
  value: VaultExposurePolicy | null
  onChange: (policy: VaultExposurePolicy | null) => void
  disabled?: boolean
  /**
   * The vault's organization's workspaces and collections. When given, the
   * ingest target is picked by name; without them it falls back to raw ids.
   */
  ingestOptions?: { workspaces: IngestTargetOption[]; collections: IngestTargetOption[] }
}

function parseList(text: string): string[] {
  return text.split(',').map(s => s.trim()).filter(Boolean)
}

/**
 * A comma-separated list override. The raw text is held locally rather than
 * re-derived from the parsed array, because round-tripping through
 * `parts.join(', ')` eats the separator the moment it is typed — you could
 * never enter a second item. External changes (a reset) are still adopted:
 * they are the ones whose parsed form differs from what is on screen.
 */
function ListOverrideField({ value, placeholder, disabled, onCommit }: {
  value: string[] | undefined
  placeholder: string
  disabled?: boolean
  onCommit: (next: string[] | undefined) => void
}) {
  const [text, setText] = useState(value ? value.join(', ') : '')

  useEffect(() => {
    const incoming = value ? value.join(', ') : ''
    setText(current => (parseList(current).join(', ') === incoming ? current : incoming))
  }, [value])

  return (
    <TextField
      size="small" fullWidth disabled={disabled}
      placeholder={placeholder}
      value={text}
      onChange={(e) => {
        setText(e.target.value)
        const parts = parseList(e.target.value)
        onCommit(parts.length ? parts : undefined)
      }}
      helperText="Comma separated — empty uses the preset"
    />
  )
}

function describe(v: unknown): string {
  if (v === null || v === undefined) return '—'
  if (typeof v === 'boolean') return v ? 'follow vault state' : 'denied'
  if (v === 'inherit') return 'follow vault state'
  if (v === 'key') return 'key required'
  if (Array.isArray(v)) return v.length ? v.join(', ') : 'none'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

function VaultCapabilityGrid({ capabilities, preset, value, onChange, disabled, ingestOptions }: Props) {
  const overrides = value ?? {}

  const isOverridden = (key: VaultCapabilityKey) =>
    Object.prototype.hasOwnProperty.call(overrides, key) && overrides[key] !== null

  const effective = (key: VaultCapabilityKey) =>
    isOverridden(key) ? overrides[key] : preset[key]

  const set = (key: VaultCapabilityKey, next: unknown) => {
    const draft: VaultExposurePolicy = { ...overrides }
    if (next === undefined) delete draft[key]
    else draft[key] = next
    onChange(Object.keys(draft).length ? draft : null)
  }

  const control = (cap: VaultCapabilityDef) => {
    const current = effective(cap.key)
    const overridden = isOverridden(cap.key)

    if (GATED_KEYS.includes(cap.key)) {
      return (
        <TextField
          select size="small" fullWidth disabled={disabled}
          value={overridden ? toLevel(current) : ''}
          onChange={(e) => set(cap.key, e.target.value === '' ? undefined : e.target.value)}
          helperText={LEVEL_HINTS[overridden ? toLevel(current) : toLevel(preset[cap.key])]}
          // Without displayEmpty the "" (preset) option renders as a blank box,
          // so a row on its preset looks unset rather than inherited.
          slotProps={{ select: { displayEmpty: true } }}
        >
          <MenuItem value="">Preset ({describe(preset[cap.key])})</MenuItem>
          <MenuItem value="inherit">Follow vault state</MenuItem>
          <MenuItem value="key">Key required</MenuItem>
          <MenuItem value="denied">Denied</MenuItem>
        </TextField>
      )
    }

    if (LIST_KEYS.includes(cap.key)) {
      return (
        <ListOverrideField
          value={overridden && Array.isArray(current) ? current as string[] : undefined}
          placeholder={describe(preset[cap.key])}
          disabled={disabled}
          onCommit={(next) => set(cap.key, next)}
        />
      )
    }

    if (cap.key === 'rag_min_score') {
      return (
        <TextField
          size="small" fullWidth type="number" disabled={disabled}
          placeholder="instance default"
          value={overridden ? String(current) : ''}
          onChange={(e) => set(cap.key, e.target.value === '' ? undefined : Number(e.target.value))}
          slotProps={{ htmlInput: { min: 0, max: 1, step: 0.05 } }}
        />
      )
    }

    // `ingest` — where every purpose's `ingest` op lands new resources. The consumer
    // declares what; the vault decides where, so both ids are set together.
    const target = (overridden && typeof current === 'object' && current !== null
      ? current as { workspace_id?: number; collection_id?: number }
      : {})

    const setTarget = (patch: { workspace_id?: number; collection_id?: number }) => {
      const next = { ...target, ...patch }
      const complete = next.workspace_id != null && next.collection_id != null
      set(cap.key, complete || next.workspace_id != null || next.collection_id != null ? next : undefined)
    }

    if (ingestOptions) {
      // A saved id that no longer resolves (deleted, another org) stays visible.
      const pick = (label: string, options: IngestTargetOption[], id: number | undefined,
        onPick: (id: number | undefined) => void) => (
        <TextField
          select size="small" fullWidth label={label} disabled={disabled}
          value={id ?? ''}
          onChange={(e) => onPick(e.target.value === '' ? undefined : Number(e.target.value))}
        >
          <MenuItem value=""><em>None</em></MenuItem>
          {id != null && !options.some(o => o.id === id) && (
            <MenuItem value={id}>#{id} (not found in this organization)</MenuItem>
          )}
          {options.map(o => (
            <MenuItem key={o.id} value={o.id}>
              {o.name}
              {o.hint && (
                <Typography component="span" variant="caption" color="text.disabled" sx={{ ml: 1 }}>
                  {o.hint}
                </Typography>
              )}
            </MenuItem>
          ))}
        </TextField>
      )

      return (
        <Stack direction="row" spacing={1}>
          {pick('Workspace', ingestOptions.workspaces, target.workspace_id, (workspace_id) => setTarget({ workspace_id }))}
          {pick('Collection', ingestOptions.collections, target.collection_id, (collection_id) => setTarget({ collection_id }))}
        </Stack>
      )
    }

    return (
      <Stack direction="row" spacing={1}>
        <TextField
          size="small" fullWidth type="number" label="Workspace id" disabled={disabled}
          value={target.workspace_id ?? ''}
          onChange={(e) => setTarget({ workspace_id: e.target.value === '' ? undefined : Number(e.target.value) })}
        />
        <TextField
          size="small" fullWidth type="number" label="Collection id" disabled={disabled}
          value={target.collection_id ?? ''}
          onChange={(e) => setTarget({ collection_id: e.target.value === '' ? undefined : Number(e.target.value) })}
        />
      </Stack>
    )
  }

  const anyOverride = Object.keys(overrides).length > 0

  return (
    <Stack spacing={1.5}>
      <Stack direction="row" alignItems="center" justifyContent="space-between">
        <Typography variant="caption" color="text.disabled">
          The preset comes from the purpose; anything you set here overrides it for this vault only.
        </Typography>
        {anyOverride && (
          <Button
            size="small" onClick={() => onChange(null)} disabled={disabled}
            sx={{ textTransform: 'none', flexShrink: 0 }}
          >
            Reset all to preset
          </Button>
        )}
      </Stack>

      {GROUP_ORDER.map((group) => {
        const rows = capabilities.filter(c => c.group === group)
        if (!rows.length) return null

        return (
          <Stack key={group} spacing={1}>
            {/* Same heading as the dialog's other subforms — divider + subtitle2
                — so the matrix reads as sections rather than one long list. */}
            <SectionTitle>{GROUP_LABELS[group]}</SectionTitle>

            {rows.map((cap) => (
              <Stack
                key={cap.key} direction={{ xs: 'column', sm: 'row' }}
                spacing={1} alignItems={{ xs: 'stretch', sm: 'flex-start' }}
              >
                <Box sx={{ flex: { sm: '0 0 15rem' }, pt: { sm: 1 } }}>
                  <Typography variant="body2">{cap.label}</Typography>
                  <Typography variant="caption" color="text.disabled">{cap.key}</Typography>
                </Box>

                {/* minWidth 0 lets the control shrink inside the flex row
                    instead of being held open by its own intrinsic width. */}
                <Box sx={{ flex: 1, minWidth: 0 }}>{control(cap)}</Box>

                {/* Fixed width: the Reset button only exists on overridden rows,
                    so letting this column size to content would make a row's
                    input shrink the moment you set it. */}
                <Stack
                  direction="row" spacing={0.5} alignItems="center"
                  sx={{ pt: { sm: 0.75 }, width: { sm: 132 }, flexShrink: 0 }}
                >
                  <Tooltip title={`Effective: ${describe(effective(cap.key))}`}>
                    <Chip
                      size="small"
                      label={isOverridden(cap.key) ? 'override' : 'preset'}
                      color={isOverridden(cap.key) ? 'primary' : 'default'}
                      variant={isOverridden(cap.key) ? 'filled' : 'outlined'}
                    />
                  </Tooltip>
                  {isOverridden(cap.key) && (
                    <Button
                      size="small" disabled={disabled}
                      onClick={() => set(cap.key, undefined)}
                      sx={{ textTransform: 'none', minWidth: 0 }}
                    >
                      Reset
                    </Button>
                  )}
                </Stack>
              </Stack>
            ))}
          </Stack>
        )
      })}
    </Stack>
  )
}

export default VaultCapabilityGrid
