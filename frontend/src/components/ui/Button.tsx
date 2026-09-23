import { Button as MuiButton, ButtonProps as MuiButtonProps } from '@mui/material'

export interface ButtonProps extends Omit<MuiButtonProps, 'startIcon' | 'endIcon'> {
  /**
   * Icon element to display before the button text
   */
  startIcon?: React.ReactNode
  /**
   * Icon element to display after the button text
   */
  endIcon?: React.ReactNode
}

/**
 * TYDAL Button Component
 *
 * A styled button component with variants for different visual styles.
 *
 * @example
 * ```tsx
 * <Button variant="contained" startIcon={<AddIcon />}>New Resource</Button>
 * <Button variant="outlined">Cancel</Button>
 * <Button variant="text" href="/path">Link Button</Button>
 * ```
 */
function Button(props: ButtonProps) {
  const { children, ...muiProps } = props
  return <MuiButton {...muiProps}>{children}</MuiButton>
}

export default Button
