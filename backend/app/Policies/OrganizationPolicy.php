<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class OrganizationPolicy
{
    use HandlesAuthorization;

    /**
     * Perform pre-authorization checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('organizations.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->can('organizations.view')
            && $user->organizations->contains('id', $organization->id);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('organizations.create');
    }

    /**
     * Update the organization — and, because the membership endpoints
     * authorize on this ability, manage who belongs to it and at what role.
     *
     * This used to additionally require the pivot role `org-admin`. That value
     * could never be written (its only two writers also passed an `id` column
     * the pivot does not have, so they threw), which made this permanently
     * false for everyone: no organization owner could invite or promote
     * anybody, and membership was a superadmin-only act. Membership plus the
     * config permission is the rule that was intended.
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->can('organizations.update')
            && $user->organizations->contains('id', $organization->id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Organization $organization): bool
    {
        return $user->can('organizations.delete')
            && $user->organizations->contains('id', $organization->id);
    }
}
