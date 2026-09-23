import {
  TextField as MuiTextField,
  TextFieldProps as MuiTextFieldProps,
  InputAdornment,
  InputProps as MuiInputProps,
} from '@mui/material'
import { icons } from './iconMap'

export interface TextFieldProps extends Omit<MuiTextFieldProps, 'InputProps'> {
  /**
   * Icon to display inside the input field (e.g., search icon)
   */
  startIcon?: keyof typeof icons
  /**
   * Icon to display at the end of the input field
   */
  endIcon?: keyof typeof icons
  /**
   * Input props (e.g., for custom adornments)
   */
  InputProps?: MuiInputProps
}

/**
 * TYDAL Text Field Component
 *
 * A text input component with optional icon adornments.
 *
 * @example
 * ```tsx
 * <TextField placeholder="Search resources..." />
 * <TextField placeholder="Search..." startIcon="search" />
 * <TextField placeholder="Filter" endIcon="filter" />
 * <TextField label="Name" fullWidth />
 * ```
 */
function TextField(props: TextFieldProps) {
  const { startIcon, endIcon, InputProps = {}, ...muiProps } = props

  const StartIcon = startIcon ? icons[startIcon] : null
  const EndIcon = endIcon ? icons[endIcon] : null

  const inputProps: MuiInputProps = {
    ...InputProps,
    startAdornment: StartIcon ? (
      <InputAdornment position="start">
        <StartIcon />
      </InputAdornment>
    ) : InputProps?.startAdornment,
    endAdornment: EndIcon ? (
      <InputAdornment position="end">
        <EndIcon />
      </InputAdornment>
    ) : InputProps?.endAdornment,
  }

  return <MuiTextField {...muiProps} InputProps={inputProps} />
}

export default TextField


