/**
 * Organization Service for Tydal Frontend
 *
 * Handles fetching organizations and switching between them
 */

import { tydal } from './tydalClient'
import type { OrgRole } from '../constants/roles'

/** A user as seen from inside an organization — the pivot carries their role. */
export interface OrganizationMember {
  id: string | number
  name: string
  email: string
  is_superadmin?: boolean
  pivot?: { role?: OrgRole }
}

export interface Organization {
  id: string | number
  name: string
  slug?: string
  org_resource_count?: number
  resource_count?: number
  type?: string
  collections?: Collection[]
}

export interface Collection {
  id: string | number
  name: string
  slug?: string
  coll_resource_count?: number
  resource_count?: number
  resource_type?: string | null
  max_num_file?: number
  scheme_id?: string
  index_id?: string
  organization_id?: string | number
}

export interface OrganizationsResponse {
  data: {
    organizations: {
      data: Organization[]
    }
  }
}

export interface SwitchOrganizationResponse {
  data: {
    organization: Organization
    user: {
      id: string | number
      name: string
      email: string
    }
  }
}

class OrganizationService {
  /**
   * Get all organizations for the current user
   */
  async getOrganizations(): Promise<Organization[] | null> {
    try {
      // SDK unwraps the `{ success, data }` envelope → `{ organizations }`.
      // `organizations` may be a flat array or a paginated `{ data: [...] }`.
      const body = await tydal.organizations.list<{
        organizations?: Organization[] | { data?: Organization[] }
      }>()
      const orgs = body?.organizations
      if (!orgs) return null
      return Array.isArray(orgs) ? orgs : (orgs.data ?? null)
    } catch (error) {
      console.error('Get organizations error:', error)
      return null
    }
  }

  /**
   * Switch to a different organization
   */
  async switchOrganization(organizationId: string | number): Promise<SwitchOrganizationResponse | null> {
    try {
      // raw → caller reads the whole `{ data: { organization, user } }` body.
      return await tydal.organizations.switch<SwitchOrganizationResponse>(organizationId)
    } catch (error) {
      console.error('Switch organization error:', error)
      return null
    }
  }

  /** One organization plus how much of its collection quota is used. */
  async getOrganization(organizationId: string | number): Promise<{
    organization: Organization
    collections: { used: number; quota: number }
  }> {
    return tydal.organizations.get(organizationId)
  }

  /**
   * Members of one organization, with their role.
   *
   * These four calls back the organization settings page. They throw
   * TydalApiError on failure rather than returning null, because the page has
   * to show *why* something was refused — "you cannot assign that role", "this
   * is the only owner" — and a null would lose it.
   */
  async getMembers(organizationId: string | number): Promise<OrganizationMember[]> {
    const body = await tydal.organizations.members<{ users?: OrganizationMember[] }>(organizationId)
    return body?.users ?? []
  }

  async addMember(
    organizationId: string | number,
    member: { userId?: string | number; email?: string; role: OrgRole },
  ): Promise<void> {
    await tydal.organizations.addMember(organizationId, member)
  }

  async updateMemberRole(
    organizationId: string | number,
    userId: string | number,
    role: OrgRole,
  ): Promise<void> {
    await tydal.organizations.updateMemberRole(organizationId, userId, role)
  }

  async removeMember(organizationId: string | number, userId: string | number): Promise<void> {
    await tydal.organizations.removeMember(organizationId, userId)
  }
}

export default new OrganizationService()
