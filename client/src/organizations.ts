/**
 * `organizations` namespace.
 *  - `list` unwraps to `{ organizations: { data: [...] } }`.
 *  - `switch` is `raw` because callers read the whole `{ data: { organization,
 *    user } }` body.
 */
import type { Http } from './http.js'

export function organizationsNamespace(http: Http) {
  return {
    /** List organizations the user belongs to → unwraps `data`. */
    list<T = unknown>(opts?: { signal?: AbortSignal }): Promise<T> {
      return http.get<T>('/organizations', { signal: opts?.signal })
    },

    /** One organization, with its collection quota → unwraps `data`. */
    get<T = unknown>(organizationId: string | number, opts?: { signal?: AbortSignal }): Promise<T> {
      return http.get<T>(`/organizations/${organizationId}`, { signal: opts?.signal })
    },

    /** Switch the active organization (raw — caller wants the whole body). */
    switch<T = unknown>(organizationId: string | number): Promise<T> {
      return http.post<T>(`/organizations/${organizationId}/switch`, { raw: true })
    },

    /** Members of one organization → unwraps to `{ users }`. */
    members<T = unknown>(organizationId: string | number, opts?: { signal?: AbortSignal }): Promise<T> {
      return http.get<T>(`/organizations/${organizationId}/users`, { signal: opts?.signal })
    },

    /**
     * Add a member. Identify them by `userId` or, when you only know their
     * address, by `email` — an organization admin cannot look up user ids.
     */
    addMember<T = unknown>(
      organizationId: string | number,
      member: { userId?: string | number; email?: string; role: string },
    ): Promise<T> {
      return http.post<T>(`/organizations/${organizationId}/users`, {
        body: {
          ...(member.userId !== undefined ? { user_id: member.userId } : {}),
          ...(member.email !== undefined ? { email: member.email } : {}),
          role: member.role,
        },
      })
    },

    /** Change a member's role. */
    updateMemberRole<T = unknown>(
      organizationId: string | number,
      userId: string | number,
      role: string,
    ): Promise<T> {
      return http.put<T>(`/organizations/${organizationId}/users/${userId}`, { body: { role } })
    },

    /** Remove a member from the organization. */
    removeMember<T = unknown>(organizationId: string | number, userId: string | number): Promise<T> {
      return http.delete<T>(`/organizations/${organizationId}/users/${userId}`)
    },
  }
}

export type OrganizationsNamespace = ReturnType<typeof organizationsNamespace>
