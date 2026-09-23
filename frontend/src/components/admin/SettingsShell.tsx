import { useState, type ReactNode } from 'react'
import { Box, Stack, Tab, Tabs } from '@mui/material'
import type { SvgIconComponent } from '@mui/icons-material'
import Header from '../layout/Header'
import { useSurfaces } from '../../contexts/ThemeContext'

/**
 * The chrome shared by the two administration surfaces: page frame, the
 * second-row tab bar in the header, and panel switching.
 *
 * Deliberately the *shell* only, not the tabs. The platform panel and the
 * organization settings page look identical and behave identically to navigate,
 * but their tab bodies are not the same thing: `/platform/users` and
 * `/organizations/{id}/users` are different endpoints with different columns
 * (one has an organization to pick) and different powers (one can grant
 * platform administration). Threading a `scope` prop through every tab would
 * put an `if (platform)` in each of them, and every one of those is somewhere a
 * tenant-scoping mistake can hide.
 *
 * A tab body IS shared when its API is already organization-scoped — Tags, for
 * one, whose endpoints are plain `auth:sanctum` and filter by the current
 * organization. Those need no branching at all.
 */

export interface SettingsTab {
  id: string
  label: string
  Icon: SvgIconComponent
  render: () => ReactNode
}

export interface SettingsShellProps {
  title: string
  tabs: SettingsTab[]
  /** Rendered above the active panel — scope reminders, quota lines. */
  intro?: ReactNode
}

export function SettingsShell({ title, tabs, intro }: SettingsShellProps) {
  const surfaces = useSurfaces()
  const [activeTab, setActiveTab] = useState<string>(tabs[0]?.id ?? '')

  const active = tabs.find((t) => t.id === activeTab) ?? tabs[0]

  return (
    <Box sx={{ minHeight: '100vh', bgcolor: surfaces.canvas }}>
      <Header
        hideSearch
        hideCollections
        title={title}
        secondRow={
          <Tabs
            value={active?.id ?? false}
            onChange={(_, val) => setActiveTab(val)}
            variant="scrollable"
            scrollButtons="auto"
            sx={{
              minHeight: 44,
              '& .MuiTab-root': { textTransform: 'none', fontWeight: 500, fontSize: '1rem', minHeight: 44, px: 2 },
              '& .MuiTabs-indicator': { height: 2 },
            }}
          >
            {tabs.map(({ id, label, Icon }) => (
              <Tab
                key={id}
                value={id}
                label={
                  <Stack direction="row" spacing={0.75} alignItems="center">
                    <Icon sx={{ fontSize: '0.875rem' }} />
                    <span>{label}</span>
                  </Stack>
                }
              />
            ))}
          </Tabs>
        }
      />

      <Box sx={{ px: 4, py: 3, maxWidth: 1200, mx: 'auto' }}>
        {intro}
        {active?.render()}
      </Box>
    </Box>
  )
}

export default SettingsShell
