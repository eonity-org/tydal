import { useState } from 'react'
import {
  Alert, Box, Button, Checkbox, Chip, CircularProgress, Dialog, DialogActions, DialogContent,
  DialogTitle, IconButton, MenuItem, Select, Stack, Table, TableBody, TableCell, TableHead,
  TableRow, TextField, Tooltip, Typography,
} from '@mui/material'
import { Add, DataObject, Delete, Storage } from '@mui/icons-material'
import type { AdminCollectionScheme } from '../../api/adminService'
import type { SchemeField } from '../../api/collectionService'

/**
 * Editing a scheme — its identity, and its fields when that is still safe.
 *
 * Laid out as the same table the schemes reference panel uses, because it is
 * the same information: a locked scheme renders exactly like the viewer, and an
 * editable one swaps the cells for inline controls. Nothing moves between the
 * two states, so the shape of a field stays recognisable whichever mode you
 * are in.
 *
 * A field definition drives five things at once — the dynamic form, the
 * Elasticsearch mapping, the facet list, validation, and where the value is
 * stored. Changing one under collections that already hold resources leaves the
 * mapping disagreeing with the documents, which only a recreate-and-reindex
 * puts right. So the cells are live only while nothing uses the scheme;
 * otherwise the answer is Clone.
 *
 * Identity is separate: `display_name` is a label and always editable, while
 * `name` is the machine identifier the platform looks system schemes up by, so
 * it is fixed on those and free on everything else.
 */

// Mirrors App\Support\SchemaContract — the server validates the same lists.
const FIELD_TYPES = ['string', 'text', 'select', 'integer', 'boolean', 'array', 'email', 'url', 'date'] as const
const ES_TYPES = ['text', 'keyword', 'integer', 'long', 'float', 'double', 'boolean', 'date', 'object'] as const
const STORAGES = ['column', 'metadata', 'index_only'] as const
const FACETABLE = ['keyword', 'integer', 'long', 'float', 'double', 'boolean', 'date']
// The only field names storage "column" may target — the resources columns
// Resource::$fillable exposes and the wizard has a dedicated input for.
// Anything else has no column to write to and no form input to render it.
const COLUMN_FIELDS = ['name', 'description', 'type']

const CELL_SX = { '& td, & th': { py: 0.5, px: 1, fontSize: '0.875rem' } }
const HEAD_SX = { fontWeight: 600, color: 'text.secondary' }

/** Compact inline control, sized to sit inside a dense table cell. */
const inputSx = { '& .MuiInputBase-input': { fontSize: '0.8125rem', py: 0.25 } }

export interface SchemeEditorProps {
  scheme: AdminCollectionScheme
  saving: boolean
  onClose: () => void
  onSave: (payload: { name?: string; display_name: string; description?: string; fields?: SchemeField[] }) => void
}

function blankField(order: number): SchemeField {
  return {
    name: '', display_name: '', type: 'string', required: false, storage: 'metadata',
    is_facet: false, display_in_form: true, order, es_type: 'keyword',
  } as SchemeField
}

function StorageGlyph({ storage }: { storage: string }) {
  const Icon = storage === 'column' ? Storage : DataObject
  return <Icon sx={{ fontSize: '0.875rem', color: 'text.disabled' }} />
}

export function SchemeEditor({ scheme, saving, onClose, onSave }: SchemeEditorProps) {
  const locked = (scheme.collections_count ?? 0) > 0

  const [name, setName] = useState(scheme.name)
  const [displayName, setDisplayName] = useState(scheme.display_name)
  const [description, setDescription] = useState(scheme.description ?? '')
  const [fields, setFields] = useState<SchemeField[]>(() => [...(scheme.fields ?? [])])

  const patch = (index: number, change: Partial<SchemeField>) =>
    setFields((current) => current.map((f, i) => (i === index ? { ...f, ...change } : f)))

  // A facet is an aggregation, and analysed text cannot be aggregated — worth
  // catching here rather than as a 422 after the round trip.
  const facetProblem = fields.find((f) => f.is_facet && f.es_type && !FACETABLE.includes(f.es_type))
  const columnProblem = fields.find((f) => f.storage === 'column' && !COLUMN_FIELDS.includes(f.name))
  const nameProblem = fields.some((f) => !/^[a-z][a-z0-9_]*$/.test(f.name))
  const identifierProblem = !scheme.is_system && !/^[a-z][a-z0-9_]*$/.test(name)

  return (
    <Dialog open onClose={() => !saving && onClose()} maxWidth="lg" fullWidth>
      <DialogTitle sx={{ pb: 1 }}>
        <Stack direction="row" spacing={1.5} alignItems="center">
          <Typography variant="body1" sx={{ fontWeight: 500 }}>{scheme.display_name}</Typography>
          {scheme.is_system && (
            <Chip label="system" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'grey.100', color: 'text.secondary' }} />
          )}
          <Chip
            label={locked ? `in use by ${scheme.collections_count}` : 'unused'}
            size="small"
            sx={{ height: 18, fontSize: '0.75rem', bgcolor: locked ? 'grey.100' : 'primary.subtle', color: locked ? 'text.secondary' : 'primary.main' }}
          />
        </Stack>
      </DialogTitle>

      <DialogContent>
        <Stack spacing={2}>
          <Stack direction="row" spacing={2}>
            <TextField
              size="small" variant="standard" label="Display name" value={displayName} fullWidth
              onChange={(e) => setDisplayName(e.target.value)}
            />
            <Tooltip title={scheme.is_system
              ? 'A system scheme keeps its identifier — the platform looks it up by this name.'
              : 'Lowercase letters, digits and underscores.'}>
              <TextField
                size="small" variant="standard" label="Identifier" value={name} fullWidth
                disabled={scheme.is_system}
                error={identifierProblem}
                onChange={(e) => setName(e.target.value)}
                InputProps={{ sx: { fontFamily: 'monospace', fontSize: '0.8125rem' } }}
              />
            </Tooltip>
          </Stack>

          <TextField
            size="small" variant="standard" label="Description" value={description} fullWidth
            onChange={(e) => setDescription(e.target.value)}
          />

          {scheme.accepted_mimetypes && scheme.accepted_mimetypes.length > 0 && (
            <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap alignItems="center">
              <Typography variant="caption" color="text.secondary" sx={{ mr: 0.5 }}>Accepts:</Typography>
              {scheme.accepted_mimetypes.map((t) => (
                <Chip key={t} label={t} size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'grey.100', color: 'text.secondary' }} />
              ))}
            </Stack>
          )}

          {locked && (
            <Alert severity="info" sx={{ py: 0.5 }}>
              <Typography variant="caption">
                {scheme.collections_count} collection{scheme.collections_count === 1 ? '' : 's'} already
                use this scheme, so its fields are fixed — changing them would leave the search mapping
                disagreeing with resources that already exist. Clone it to make a variant you can edit.
              </Typography>
            </Alert>
          )}

          {facetProblem && (
            <Alert severity="warning" sx={{ py: 0.5 }}>
              <Typography variant="caption">
                “{facetProblem.name}” is a facet but its search type is <strong>{facetProblem.es_type}</strong>.
                Facets are aggregations and analysed text cannot be aggregated — use <strong>keyword</strong>.
              </Typography>
            </Alert>
          )}

          {columnProblem && (
            <Alert severity="warning" sx={{ py: 0.5 }}>
              <Typography variant="caption">
                “{columnProblem.name}” uses storage <strong>column</strong>, but only{' '}
                {COLUMN_FIELDS.join(', ')} map onto a real resources column — anything else has nowhere
                to be written and won't appear in the form. Use <strong>metadata</strong> instead.
              </Typography>
            </Alert>
          )}

          <Box>
            <Stack direction="row" justifyContent="space-between" alignItems="center" sx={{ mb: 0.5 }}>
              <Typography variant="body2" fontWeight={600} color="text.secondary"
                sx={{ textTransform: 'uppercase', letterSpacing: '0.06em', fontSize: '0.75rem' }}>
                Fields
              </Typography>
              {!locked && (
                <Button size="small" startIcon={<Add sx={{ fontSize: '1rem' }} />}
                  onClick={() => setFields((f) => [...f, blankField(f.length + 1)])}>
                  Add field
                </Button>
              )}
            </Stack>

            <Table size="small" sx={CELL_SX}>
              <TableHead>
                <TableRow sx={{ bgcolor: 'grey.100' }}>
                  <TableCell sx={{ ...HEAD_SX, width: 150 }}>Field</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 160 }}>Display name</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 130 }}>Storage</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 100 }}>Type</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 110 }}>Search</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 70 }}>Facet</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 80 }}>Required</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 70 }}>In form</TableCell>
                  <TableCell sx={{ ...HEAD_SX, width: 60 }}>Order</TableCell>
                  {!locked && <TableCell sx={{ ...HEAD_SX, width: 40 }} />}
                </TableRow>
              </TableHead>
              <TableBody>
                {fields.map((f, index) => (
                  <TableRow key={index} hover>
                    <TableCell>
                      {locked ? (
                        <Typography variant="caption" sx={{ fontFamily: 'monospace' }}>{f.name}</Typography>
                      ) : (
                        <TextField
                          variant="standard" value={f.name} fullWidth
                          onChange={(e) => patch(index, { name: e.target.value })}
                          error={f.name !== '' && !/^[a-z][a-z0-9_]*$/.test(f.name)}
                          InputProps={{ sx: { fontFamily: 'monospace', fontSize: '0.8125rem' } }}
                        />
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? f.display_name : (
                        <TextField
                          variant="standard" value={f.display_name ?? ''} fullWidth sx={inputSx}
                          onChange={(e) => patch(index, { display_name: e.target.value })}
                        />
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        <Stack direction="row" spacing={0.5} alignItems="center">
                          <StorageGlyph storage={f.storage} />
                          <Typography variant="caption" color="text.secondary">{f.storage}</Typography>
                        </Stack>
                      ) : (
                        <Select
                          variant="standard" value={f.storage} fullWidth sx={inputSx}
                          onChange={(e) => patch(index, { storage: e.target.value as SchemeField['storage'] })}
                          renderValue={(v) => (
                            <Stack direction="row" spacing={0.5} alignItems="center">
                              <StorageGlyph storage={v as string} />
                              <Typography variant="caption" color="text.secondary">{v as string}</Typography>
                            </Stack>
                          )}
                        >
                          {STORAGES.map((t) => {
                            const columnNotAllowed = t === 'column' && !COLUMN_FIELDS.includes(f.name)
                            return (
                              <MenuItem
                                key={t} value={t} sx={{ fontSize: '0.8125rem' }}
                                disabled={columnNotAllowed}
                                title={columnNotAllowed
                                  ? `"column" only fits ${COLUMN_FIELDS.join(', ')} — use "metadata" for a new field.`
                                  : undefined}
                              >
                                <Stack direction="row" spacing={0.75} alignItems="center">
                                  <StorageGlyph storage={t} />
                                  <span>{t}</span>
                                </Stack>
                              </MenuItem>
                            )
                          })}
                        </Select>
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        <Typography variant="caption" color="text.secondary">{f.type}</Typography>
                      ) : (
                        <Select
                          variant="standard" value={f.type} fullWidth sx={inputSx}
                          onChange={(e) => patch(index, { type: e.target.value as SchemeField['type'] })}
                        >
                          {FIELD_TYPES.map((t) => <MenuItem key={t} value={t} sx={{ fontSize: '0.8125rem' }}>{t}</MenuItem>)}
                        </Select>
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        <Typography variant="caption" color="text.secondary">{f.es_type ?? '—'}</Typography>
                      ) : (
                        <Select
                          variant="standard" value={f.es_type ?? 'keyword'} fullWidth sx={inputSx}
                          error={Boolean(f.is_facet && f.es_type && !FACETABLE.includes(f.es_type))}
                          onChange={(e) => patch(index, { es_type: e.target.value } as Partial<SchemeField>)}
                        >
                          {ES_TYPES.map((t) => <MenuItem key={t} value={t} sx={{ fontSize: '0.8125rem' }}>{t}</MenuItem>)}
                        </Select>
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        f.is_facet
                          ? <Chip label="facet" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'info.light', color: 'info.main' }} />
                          : <Typography variant="caption" color="text.disabled">—</Typography>
                      ) : (
                        <Checkbox size="small" sx={{ p: 0.25 }} checked={Boolean(f.is_facet)}
                          onChange={(e) => patch(index, { is_facet: e.target.checked })} />
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        f.required
                          ? <Chip label="required" size="small" sx={{ height: 18, fontSize: '0.75rem', bgcolor: 'primary.subtle', color: 'primary.main' }} />
                          : <Typography variant="caption" color="text.disabled">optional</Typography>
                      ) : (
                        <Checkbox size="small" sx={{ p: 0.25 }} checked={Boolean(f.required)}
                          onChange={(e) => patch(index, { required: e.target.checked })} />
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        <Typography variant="caption" color="text.disabled">{f.display_in_form ? 'yes' : '—'}</Typography>
                      ) : (
                        <Checkbox size="small" sx={{ p: 0.25 }} checked={Boolean(f.display_in_form)}
                          onChange={(e) => patch(index, { display_in_form: e.target.checked })} />
                      )}
                    </TableCell>

                    <TableCell>
                      {locked ? (
                        <Typography variant="caption" color="text.secondary">{f.order ?? 0}</Typography>
                      ) : (
                        <TextField
                          variant="standard" type="number" value={f.order ?? 0} fullWidth sx={inputSx}
                          onChange={(e) => patch(index, { order: Number(e.target.value) })}
                        />
                      )}
                    </TableCell>

                    {!locked && (
                      <TableCell>
                        {fields.length > 1 && (
                          <Tooltip title="Remove field">
                            <IconButton size="small" sx={{ p: 0.25 }}
                              onClick={() => setFields((f) => f.filter((_, i) => i !== index))}>
                              <Delete sx={{ fontSize: '0.9rem' }} />
                            </IconButton>
                          </Tooltip>
                        )}
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        </Stack>
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={saving}>Cancel</Button>
        <Button
          variant="contained"
          onClick={() => onSave({
            name: scheme.is_system ? undefined : name,
            display_name: displayName,
            description: description || undefined,
            fields: locked ? undefined : fields,
          })}
          disabled={saving || !displayName.trim() || identifierProblem || (!locked && (nameProblem || Boolean(facetProblem) || Boolean(columnProblem)))}
          startIcon={saving ? <CircularProgress size={14} color="inherit" /> : undefined}
        >
          Save
        </Button>
      </DialogActions>
    </Dialog>
  )
}

export default SchemeEditor
