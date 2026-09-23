import {
  Radio as MuiRadio,
  RadioProps as MuiRadioProps,
  FormControlLabel,
  FormControlLabelProps,
} from '@mui/material'

export interface RadioProps extends Omit<MuiRadioProps, 'color'> {
  /**
   * Label text to display next to the radio button
   */
  label?: string
  /**
   * Placement of the label relative to the radio button
   */
  labelPlacement?: FormControlLabelProps['labelPlacement']
  /**
   * Color variant
   */
  color?: 'primary' | 'secondary' | 'error' | 'info' | 'success' | 'warning'
}

/**
 * TYDAL Radio Component
 *
 * A radio button component with optional label. Used for single-select facet filters.
 *
 * @example
 * ```tsx
 * <Radio checked={checked} onChange={handleChange} value="option1" />
 * <Radio label="Grid View" checked={selected} value="grid" />
 * <Radio label="List View" checked={!selected} value="list" />
 * ```
 */
function Radio(props: RadioProps) {
  const { label, labelPlacement = 'end', color = 'primary', ...radioProps } = props

  const radio = (
    <MuiRadio
      color={color}
      {...radioProps}
    />
  )

  if (label) {
    return (
      <FormControlLabel
        control={radio}
        label={label}
        labelPlacement={labelPlacement}
        sx={{ alignItems: 'center', margin: 0 }}
      />
    )
  }

  return radio
}

export default Radio
