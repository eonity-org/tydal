import { MenuItem, Select, Stack, Switch, TextField, Typography } from '@mui/material'

import type { SchemeField } from '../../api/collectionService'

interface Props {
  field: SchemeField
  value: any
  onChange: (value: any) => void
}

/**
 * One collection-scheme field as a form control: a switch for booleans, a
 * select for `in`-validated selects, otherwise a text field typed by the
 * field (number, date, multiline text). Shared by the wizard's step-1
 * "Required by this collection" panel and the Review step's Collection fields.
 */
export function SchemeFieldInput({ field, value, onChange }: Props) {
  const val = value ?? ''

  if (field.type === 'boolean') {
    return (
      <Stack direction="row" alignItems="center" spacing={1}>
        <Typography variant="caption" color="text.secondary" sx={{ flex: 1 }}>
          {field.display_name}
        </Typography>
        <Switch
          size="small"
          checked={!!val}
          onChange={(e) => onChange(e.target.checked)}
          inputProps={{ 'aria-label': field.display_name }}
        />
      </Stack>
    )
  }

  if (field.type === 'select' && field.validators?.in) {
    return (
      <Select
        size="small"
        fullWidth
        displayEmpty
        value={val}
        onChange={(e) => onChange(e.target.value)}
        renderValue={(v) => v || <Typography color="text.disabled">{field.display_name}</Typography>}
        inputProps={{ 'aria-label': field.display_name }}
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
      size="small"
      fullWidth
      label={field.display_name}
      required={field.required}
      type={field.type === 'integer' ? 'number' : field.type === 'date' ? 'date' : 'text'}
      multiline={field.type === 'text'}
      rows={field.type === 'text' ? 2 : undefined}
      value={val}
      onChange={(e) => onChange(e.target.value)}
      InputLabelProps={field.type === 'date' ? { shrink: true } : undefined}
    />
  )
}
