import { Box, Stack, Typography } from '@mui/material'
import CheckIcon from '@mui/icons-material/Check'

interface Props {
  currentStep: 1 | 2 | 3 | 4
  labels?: [string, string, string, string]
}

const DEFAULT_LABELS: [string, string, string, string] = ['Mode & Files', 'Upload', 'Aity', 'Review']
const STEPS = [1, 2, 3, 4] as const

// All sizing in rem (1.75rem ≈ 28px at 16px base)
const CIRCLE_REM    = '1.75rem'
const CONNECTOR_REM = '1.75rem'

/**
 * Compact 4-step indicator for the ResourceWizard header.
 *
 * Two-row layout — connector line lives exclusively in the circles row,
 * so it always aligns perfectly with circle centres regardless of label height.
 *
 *   Row 1: ●────●────●────●
 *   Row 2:  lbl  lbl  lbl  lbl   (each label centred under its circle via matching spacers)
 */
export function WizardStepper({ currentStep, labels = DEFAULT_LABELS }: Props) {
  return (
    <Box sx={{ userSelect: 'none' }}>

      {/* Row 1 — circles + connectors */}
      <Stack direction="row" alignItems="center">
        {STEPS.map((n, idx) => {
          const completed = n < currentStep
          const active    = n === currentStep

          return (
            <Stack key={n} direction="row" alignItems="center">
              {idx > 0 && (
                <Box
                  sx={{
                    width: CONNECTOR_REM,
                    height: '0.125rem',
                    flexShrink: 0,
                    bgcolor: completed || active ? 'primary.main' : 'primary.200',
                    transition: 'background-color 0.2s',
                  }}
                />
              )}

              <Box
                sx={{
                  width: CIRCLE_REM,
                  height: CIRCLE_REM,
                  borderRadius: '50%',
                  flexShrink: 0,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  bgcolor: completed || active ? 'primary.main' : 'transparent',
                  border: '0.125rem solid',
                  borderColor: completed || active ? 'primary.main' : 'primary.200',
                  transition: 'all 0.2s',
                }}
              >
                {completed ? (
                  <CheckIcon sx={{ fontSize: '0.9rem', color: 'white' }} />
                ) : (
                  <Typography sx={{ fontWeight: 700, fontSize: '0.7rem', lineHeight: 1, color: active ? 'white' : 'text.disabled' }}>
                    {n}
                  </Typography>
                )}
              </Box>
            </Stack>
          )
        })}
      </Stack>

      {/* Row 2 — labels, mirroring the circle row structure for exact alignment */}
      <Stack direction="row" alignItems="center" sx={{ mt: '0.375rem' }}>
        {STEPS.map((n, idx) => {
          const active = n === currentStep
          const future = n > currentStep

          return (
            <Stack key={n} direction="row" alignItems="center">
              {/* Spacer matching the connector width */}
              {idx > 0 && <Box sx={{ width: CONNECTOR_REM, flexShrink: 0 }} />}

              {/* Label centred under its circle */}
              <Box sx={{ width: CIRCLE_REM, display: 'flex', justifyContent: 'center' }}>
                <Typography
                  sx={{
                    fontSize: '0.625rem',
                    fontWeight: active ? 700 : 400,
                    color: future ? 'text.disabled' : active ? 'primary.main' : 'text.secondary',
                    whiteSpace: 'nowrap',
                  }}
                >
                  {labels[idx]}
                </Typography>
              </Box>
            </Stack>
          )
        })}
      </Stack>

    </Box>
  )
}
