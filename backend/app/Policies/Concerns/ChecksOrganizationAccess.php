<?php

namespace App\Policies\Concerns;

use App\Enums\OrganizationRole;
use App\Models\User;

/**
 * The contextual half of every policy.
 *
 * Permissions themselves ("may this role update resources?") come from
 * config/permissions.php through `$user->can('resources.update')`. What a
 * policy still has to decide is context: is this thing in the organization the
 * caller is acting in, are they in that organization at all, and — where
 * authorship matters — did they create it. Those checks were copy-pasted with
 * small differences into all five policies; this is the one copy.
 */
trait ChecksOrganizationAccess
{
    /** Holds `users.is_superadmin` — a capability over the whole installation. */
    protected function isPlatformAdmin(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    /**
     * May this user act in the organization currently in context?
     *
     * A platform admin may, without being a member — that is the point of the
     * role. Everyone else needs a row in `organization_user`.
     */
    protected function belongsToCurrentOrganization(User $user): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        $organization = currentOrganization();

        return $organization && $organization->users()->where('users.id', $user->id)->exists();
    }

    /** Is this record part of the organization currently in context? */
    protected function inCurrentOrganization(?string $organizationId): bool
    {
        $current = currentOrganizationId();

        return $current !== null && $organizationId === $current;
    }

    /**
     * Is the caller acting at or above the given organization role?
     *
     * A platform admin always is. Used for the "administrators may act on
     * things they did not create" rule.
     */
    protected function actsAtLeast(User $user, OrganizationRole $minimum): bool
    {
        if ($this->isPlatformAdmin($user)) {
            return true;
        }

        $role = OrganizationRole::tryFromValue(currentOrganizationRole());

        return $role !== null && $role->atLeast($minimum);
    }

    /**
     * May the caller act on something owned by $ownerId?
     *
     * Authorship protects a record from peers, not from the people who run the
     * organization: an owner or admin can edit anything in theirs. Without
     * this, an organization owner could not fix a typo in a colleague's
     * resource — which is how the policies used to behave.
     */
    protected function ownsOrAdministers(User $user, ?string $ownerId): bool
    {
        return (string) $ownerId === (string) $user->id
            || $this->actsAtLeast($user, OrganizationRole::ADMIN);
    }
}
