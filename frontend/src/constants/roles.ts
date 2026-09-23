import type { User } from '../api/authService'

/**
 * The two authorization axes, in one place.
 *
 * They are not the same thing and the code used to mix them:
 *   - Platform administration is `user.is_superadmin`, a capability over the
 *     whole installation. It is never an organization role.
 *   - An organization role lives on the `organization_user` pivot and says what
 *     someone may do inside one tenant.
 *
 * This file exists because the role lists had drifted into four different
 * vocabularies — Header.tsx checked for an `org-admin` that could never exist
 * in the database, while the admin UI offered a list that did not include it.
 * Mirrors config/permissions.php and App\Enums\OrganizationRole on the backend.
 */

export const ORG_ROLES = [
  { value: 'owner', label: 'Owner', level: 100, description: 'Full control, including deleting the organization.' },
  { value: 'admin', label: 'Administrator', level: 75, description: 'Manages people and content.' },
  { value: 'editor', label: 'Editor', level: 50, description: 'Creates and edits content.' },
  { value: 'viewer', label: 'Viewer', level: 25, description: 'Read-only.' },
] as const

export type OrgRole = (typeof ORG_ROLES)[number]['value']

export const ORG_ROLE_VALUES = ORG_ROLES.map((r) => r.value) as readonly OrgRole[]

export function roleLabel(role?: string | null): string {
  return ORG_ROLES.find((r) => r.value === role)?.label ?? 'No role'
}

function levelOf(role?: string | null): number {
  return ORG_ROLES.find((r) => r.value === role)?.level ?? 0
}

/** Holds the platform capability — may reach every organization. */
export function isPlatformAdmin(user?: User | null): boolean {
  return user?.is_superadmin === true
}

/**
 * May the user do this, according to the permission list the API sent?
 *
 * Mirrors App\Support\Permissions::allows() — `resources.*` grants
 * `resources.update`. Advisory: it decides whether a control is worth showing,
 * never whether an action is allowed. The server authorizes every request, and
 * a stale client (someone's role changed a minute ago) simply meets the 403 it
 * would have met anyway.
 */
export function can(user: User | null | undefined, ability: string): boolean {
  const permissions = user?.permissions
  if (!permissions) return false

  return permissions.some((permission) => {
    if (permission === ability) return true
    if (permission.endsWith('.*')) {
      const prefix = permission.slice(0, -2)
      return ability.startsWith(`${prefix}.`) || ability === prefix
    }
    return false
  })
}

/** Acts at or above `minimum` in the organization currently in context. */
export function hasOrgRoleAtLeast(user: User | null | undefined, minimum: OrgRole): boolean {
  if (isPlatformAdmin(user)) return true
  return levelOf(user?.current_organization_role) >= levelOf(minimum)
}

/**
 * May administer the current organization — its members, its workspaces.
 * The single predicate that replaces the two hand-written copies in Header.
 */
export function canManageOrg(user?: User | null): boolean {
  return hasOrgRoleAtLeast(user, 'admin')
}

/**
 * Which roles this actor may hand out, strongest first.
 *
 * Mirrors `role_hierarchy` in config/permissions.php: nobody assigns above
 * their own rank, except that an owner may appoint another owner — without
 * that, ownership could never be transferred.
 */
export function assignableRoles(user?: User | null): readonly OrgRole[] {
  if (isPlatformAdmin(user)) return ORG_ROLE_VALUES

  switch (user?.current_organization_role) {
    case 'owner':
      return ['owner', 'admin', 'editor', 'viewer']
    case 'admin':
      return ['editor', 'viewer']
    default:
      return []
  }
}
