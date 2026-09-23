import { Divider, type DividerProps } from '@mui/material'

/**
 * NarrowDivider
 *
 * A centered divider at 85% width, used where a softer visual
 * separation is preferred over a full-width rule (e.g. sidebar sections).
 */
function NarrowDivider(props: DividerProps) {
  return <Divider sx={{ width: 'calc(100% - 32px)', mx: 'auto', borderColor: 'rgba(228, 228, 231, 0.5)', ...props.sx }} {...props} />
}

export default NarrowDivider
