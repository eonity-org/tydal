import { FolderOpen, Groups, LocalOffer } from '@mui/icons-material'
import SettingsShell, { type SettingsTab } from '../components/admin/SettingsShell'
import OrgMembersTab from '../components/admin/OrgMembersTab'
import OrgCollectionsTab from '../components/admin/OrgCollectionsTab'
import SemanticTagsTab from '../components/admin/SemanticTagsTab'

/**
 * Organization settings — one tenant: its people, its collections, its
 * vocabulary.
 *
 * Shares SettingsShell with the platform panel so the two navigate identically
 * and gain tabs the same way. What it does not share is the tab bodies that
 * reach across organizations: Members and Collections here are strictly the
 * organization in context, with narrower choices and a quota, whereas the
 * platform equivalents pick an owning organization and can grant platform
 * administration.
 *
 * Tags is literally the same component as the platform panel's, because its
 * endpoints were already organization-scoped — no branching needed, and no
 * reason for two copies.
 */
const TABS: SettingsTab[] = [
  { id: 'members', label: 'Members', Icon: Groups, render: () => <OrgMembersTab /> },
  { id: 'collections', label: 'Collections', Icon: FolderOpen, render: () => <OrgCollectionsTab /> },
  { id: 'tags', label: 'Tags', Icon: LocalOffer, render: () => <SemanticTagsTab /> },
]

function OrganizationPage() {
  return <SettingsShell title="Organization settings" tabs={TABS} />
}

export default OrganizationPage
