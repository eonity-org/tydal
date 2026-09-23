import {
  Checkbox as MuiCheckbox,
  CheckboxProps as MuiCheckboxProps,
  FormControlLabel,
  FormControlLabelProps,
} from '@mui/material'

export interface CheckboxProps extends Omit<MuiCheckboxProps, 'color'> {
  /**
   * Label text to display next to the checkbox
   */
  label?: string
  /**
   * Placement of the label relative to the checkbox
   */
  labelPlacement?: FormControlLabelProps['labelPlacement']
  /**
   * Color variant
   */
  color?: 'primary' | 'secondary' | 'error' | 'info' | 'success' | 'warning'
}

/**
 * TYDAL Checkbox Component
 *
 * A checkbox component with optional label. Used for facet filters and multi-select options.
 *
 * @example
 * ```tsx
 * <Checkbox checked={checked} onChange={handleChange} />
 * <Checkbox label="Images" checked={checked} onChange={handleChange} />
 * <Checkbox label="Videos" checked={false} color="primary" />
 * ```
 */
function Checkbox(props: CheckboxProps) {
  const { label, labelPlacement = 'end', color = 'primary', ...checkboxProps } = props

  const checkbox = (
    <MuiCheckbox
      color={color}
      {...checkboxProps}
    />
  )

  if (label) {
    return (
      <FormControlLabel
        control={checkbox}
        label={label}
        labelPlacement={labelPlacement}
        sx={{ alignItems: 'center', margin: 0 }}
      />
    )
  }

  return checkbox
}

export default Checkbox
