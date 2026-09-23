import { Typography, Divider } from '@mui/material'
import type { ReactNode } from 'react'

/**
 * Section heading inside an admin subform: a light divider above + a slightly
 * larger label, so long forms read as identifiable blocks.
 *
 * Lives in its own module because both the vault dialog and the capability
 * grid it renders need it — importing it from VaultsTab would be circular.
 */
function SectionTitle({ children, divider = true }: { children: ReactNode; divider?: boolean }) {
  return (
    <>
      {divider && <Divider sx={{ mt: 0.5 }} />}
      <Typography variant="subtitle2" sx={{ fontWeight: 700, color: 'text.secondary' }}>
        {children}
      </Typography>
    </>
  )
}

export default SectionTitle
