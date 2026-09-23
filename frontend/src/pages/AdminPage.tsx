import { People, Business, FolderOpen, Schema, Share, LocalOffer, Memory } from '@mui/icons-material'
import SettingsShell, { type SettingsTab } from '../components/admin/SettingsShell'
import UsersTab from '../components/admin/UsersTab'
import OrgsTab from '../components/admin/OrgsTab'
import CollectionsTab from '../components/admin/CollectionsTab'
import SchemesTab from '../components/admin/SchemesTab'
import VaultsTab from '../components/admin/VaultsTab'
import SemanticTagsTab from '../components/admin/SemanticTagsTab'
import AiServicesTab from '../components/admin/AiServicesTab'

/**
 * Platform administration — the whole installation: every organization, the
 * users across them, the shared schemes and indexes, and the AI configuration.
 *
 * Shares its chrome with the organization settings page via SettingsShell; the
 * tab bodies are its own, because these ones reach across tenants.
 *
 * One exception worth knowing: the Tags tab is NOT platform-scoped. Semantic
 * tags belong to an organization and their endpoints filter by the current one,
 * so editing tags here edits whichever organization you are standing in. It is
 * the same component the organization settings page uses.
 */
const TABS: SettingsTab[] = [
  { id: 'users', label: 'Users', Icon: People, render: () => <UsersTab /> },
  { id: 'organizations', label: 'Organizations', Icon: Business, render: () => <OrgsTab /> },
  { id: 'collections', label: 'Collections', Icon: FolderOpen, render: () => <CollectionsTab /> },
  { id: 'schemes', label: 'Schemes & Indexes', Icon: Schema, render: () => <SchemesTab /> },
  { id: 'vaults', label: 'Vault Sharing', Icon: Share, render: () => <VaultsTab /> },
  { id: 'tags', label: 'Tags', Icon: LocalOffer, render: () => <SemanticTagsTab scopeNote /> },
  { id: 'ai', label: 'AI Services', Icon: Memory, render: () => <AiServicesTab /> },
]

function AdminPage() {
  return <SettingsShell title="Platform Administration" tabs={TABS} />
}

export default AdminPage
